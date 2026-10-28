<?php

declare(strict_types=1);

use App\Config;
use App\Controller\EventController;
use App\Controller\PostbackController;
use App\Database;
use App\DedupService;
use App\EventRepository;
use App\Http;
use App\PartnerClient;
use App\PostbackService;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Predis\Client as Redis;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

$logger = new Logger('postback');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Info));

$repository = new EventRepository([Database::class, 'connect']);

$redis = new Redis([
    'scheme' => 'tcp',
    'host' => Config::get('REDIS_HOST', 'redis'),
    'port' => Config::int('REDIS_PORT', 6379),
    'timeout' => 2.0,
    'read_write_timeout' => 2.0,
]);

$partner = new PartnerClient(
    new GuzzleClient([
        'timeout' => (float) Config::get('PARTNER_TIMEOUT', '3'),
        'connect_timeout' => 2.0,
        'http_errors' => false,
    ]),
    Config::get('PARTNER_API_URL', 'http://partner-mock:8081/api/events'),
    $logger,
    min(3, max(1, Config::int('PARTNER_MAX_ATTEMPTS', 3))),
    Config::int('PARTNER_RETRY_DELAY_MS', 200),
);

$postbacks = new PostbackController(new PostbackService(
    $repository,
    new DedupService($redis, Config::int('DEDUP_TTL', 86400)),
    $partner,
    $logger,
));
$events = new EventController($repository);

$app = AppFactory::create();
$app->addRoutingMiddleware();

$errors = $app->addErrorMiddleware(false, true, true, $logger);
$errors->setDefaultErrorHandler(function (ServerRequestInterface $req, Throwable $e) use ($app) {
    $code = $e instanceof HttpException ? $e->getCode() : 500;

    return Http::json(
        $app->getResponseFactory()->createResponse(),
        $code,
        ['status' => 'error', 'message' => $code === 500 ? 'Internal server error' : $e->getMessage()],
    );
});

$app->get('/postback', $postbacks);
$app->get('/events', [$events, 'index']);
$app->get('/events/{id:[0-9]+}', [$events, 'show']);
$app->get('/health', fn ($req, $res) => Http::json($res, 200, ['status' => 'ok']));

return $app;
