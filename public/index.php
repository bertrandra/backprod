<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Immutable: a value already set in the real environment wins over .env, so a
// deployment's configuration cannot be overridden by a stray file.
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$containerFactory = require __DIR__ . '/../config/container.php';
assert(is_callable($containerFactory));

/** @var ContainerInterface $container */
$container = $containerFactory();

/** @var RequestHandlerInterface $app */
$app = $container->get(RequestHandlerInterface::class);

(new SapiEmitter())->emit(
    $app->handle(ServerRequestFactory::fromGlobals()),
);
