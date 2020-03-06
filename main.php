<?php

require __DIR__ . '/vendor/autoload.php';

use DI\ContainerBuilder;
use SimpleBus\Message\Bus\MessageBus;
use SimpleBus\Message\Bus\Middleware\MessageBusSupportingMiddleware;


use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SimpleBus\Message\Bus\Middleware\FinishesHandlingMessageBeforeHandlingNext;
use SimpleBus\Message\CallableResolver\CallableResolver;
use SimpleBus\Message\Name\ClassBasedNameResolver;
use SimpleBus\Message\Name\NamedMessage;
use SimpleBus\Message\Name\NamedMessageNameResolver;
use SimpleBus\Message\Subscriber\NotifiesMessageSubscribersMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;
use SimpleBus\Message\CallableResolver\CallableCollection;
use SimpleBus\Message\CallableResolver\ServiceLocatorAwareCallableResolver;
use SimpleBus\Message\Subscriber\Resolver\NameBasedMessageSubscriberResolver;


class ApplicationInitialisedEvent implements NamedMessage
{
    public static function messageName()
    {
        return ApplicationInitialisedEvent::class;
    }
}

interface ApplicationListener
{
    function handle(object $event);
}

class AppFactoryInitializer // implements ApplicationListener
{
    private App  $af;

    public function __construct(App $af)
    {
        $this->af = $af;
    }

    function handle()
    {
        error_log("running handle!");
        // $this->af->run();
    }
}

class PhpDiCallableResolver implements CallableResolver
{

    private $context;

    public function __construct(ContainerInterface $context)
    {
        $this->context = $context;
    }

    public function resolve($maybeCallable)
    {
        return $this->context->get(AppFactoryInitializer::class);
    }

}

function main()
{

    try {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([

            /*MessageBus::class => function (ContainerInterface $container) {
                $eventBus = new MessageBusSupportingMiddleware(
                    [new FinishesHandlingMessageBeforeHandlingNext()]);
                $eventSubscribersByEventName = [
                    ApplicationInitialisedEvent::class => [function ($event) {
                        echo("the new event is $event");
                    }]
                ];
                $eventNameResolver = new NamedMessageNameResolver();
                $eventSubscriberCollection = new CallableCollection();
                $eventSubscribersResolver = new NameBasedMessageSubscriberResolver(
                    $eventNameResolver,
                    $eventSubscriberCollection
                );
                return $eventBus;
            },*/
            // the event bus
            /*
            MessageBusSupportingMiddleware::class => function (ContainerInterface $container) {
                $eventBus = new MessageBusSupportingMiddleware();
                $eventBus->appendMiddleware(new FinishesHandlingMessageBeforeHandlingNext());
                $serviceLocator = function ($serviceId) use ($container) {
                    error_log("looking for the service called $serviceId");
                    return $container->get($serviceId);
                };
                $eventSubscribersByEventName = [
                    ApplicationInitialisedEvent::class => [ $container->get(AppFactoryInitializer::class) ]
                ];
                $eventSubscriberCollection = new CallableCollection(
                    $eventSubscribersByEventName,
                    new SimpleBus\Message\CallableResolver\ServiceLocatorAwareCallableResolver($serviceLocator)
                );
                $eventNameResolver = new NamedMessageNameResolver();
                $eventSubscribersResolver = new NameBasedMessageSubscriberResolver($eventNameResolver, $eventSubscriberCollection);
                $eventBus->appendMiddleware(
                    new NotifiesMessageSubscribersMiddleware($eventSubscribersResolver));
                return $eventBus;
            },
            */

            // web tier
            App::class => function (UserService $userService) {
                $app = AppFactory::create();
                $app->addErrorMiddleware(true, true, true);
                $app->get('/hello/{name}', function (RequestInterface $request, ResponseInterface $res, $args) {
                    $name = $args['name'];
                    $res->getBody()->write("<P>Hello, $name!</P>");
                    return $res;
                });
                $app->get('/users', function (RequestInterface $request, ResponseInterface $response) use ($userService) {
                    $users = $userService->getAllUsers();
                    $response->getBody()->write(json_encode($users));
                    return $response;
                });
                return $app;
            },

            AppFactoryInitializer::class => function (App $af) {
                new AppFactoryInitializer($af);
            },

            // DB access
            PDO::class => function () {
                $pw = 'orders';
                $user = 'orders';
                $db = 'orders';
                $uri = "pgsql:host=localhost;port=5432;dbname=$db;user=$user;password=$pw";
                return new PDO($uri, $user, $pw);
            },
            PdoTemplate::class => function (PDO $pdo) {
                return new PdoTemplate($pdo);
            },

            // Services
            UserService::class => function (PdoTemplate $pdoTemplate) {
                return new UserService($pdoTemplate);
            }
        ]);
        $container = $builder->build();
        $container->get(App::class)->run();
//        $bus = $container->get(MessageBusSupportingMiddleware::class);
//        $bus->handle(new ApplicationInitialisedEvent());


    } catch (Exception $e) {
        echo("boom! $e");
    }
}


class PdoTemplate
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(string $sql, Closure $rowMapper)
    {
        $results = [];
        $stmt = $this->pdo->query($sql);
        if ($stmt) {
            while ($row = $stmt->fetch()) {
                $results[] = $rowMapper($row);
            }
        }
        return $results;
    }
}

class UserService
{

    public PdoTemplate $pdo;

    public function getAllUsers()
    {
        return $this->pdo->execute("select * from users", function ($row) {
            return new User($row['id'], $row['name']);
        });
    }

    public function __construct(PdoTemplate $pdoTemplate)
    {
        $this->pdo = $pdoTemplate;
    }
}

class User implements JsonSerializable
{
    private string $id;
    private string $name;

    public function __toString()
    {
        return "{ ID: $this->id " . "NAME: $this->name " . " } \n";
    }

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function jsonSerialize()
    {
        return ["id" => $this->id, "name" => $this->name];
    }
}

main();
?>