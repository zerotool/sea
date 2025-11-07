<?php

require __DIR__ . '/../vendor/autoload.php';

use Sea\Controller\ApiController;
use Sea\GridService;
use Sea\KafkaProducer;
use Sea\Repository\ShipRepository;
use Sea\Service\EventPublisher;
use Sea\Service\ShipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$request = Request::createFromGlobals();

$hexes = GridService::buildGrid();
$repository = new ShipRepository($hexes);
$publisher = new EventPublisher();
$shipService = new ShipService($repository, $publisher, $hexes);
$producer = new KafkaProducer();
$controller = new ApiController($shipService, $producer);

if ($request->getMethod() === 'OPTIONS') {
    sendResponse(new Response('', Response::HTTP_NO_CONTENT));
    return;
}

$routes = new RouteCollection();
$routes->add('health', new Route('/health', ['_controller' => [$controller, 'health']], [], [], '', [], ['GET']));
$routes->add('state', new Route('/api/state', ['_controller' => [$controller, 'state']], [], [], '', [], ['GET']));
$routes->add('move', new Route('/api/move', ['_controller' => [$controller, 'move']], [], [], '', [], ['POST']));

$context = new RequestContext();
$context->fromRequest($request);
$matcher = new UrlMatcher($routes, $context);

try {
    $parameters = $matcher->match($request->getPathInfo());
    $request->attributes->add($parameters);
    /** @var callable $handler */
    $handler = $parameters['_controller'];
    $response = $handler($request);
} catch (ResourceNotFoundException $notFound) {
    $response = new JsonResponse(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
} catch (Throwable $error) {
    $response = new JsonResponse(['message' => 'Server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
}

sendResponse($response ?? new JsonResponse(['message' => 'Server error'], Response::HTTP_INTERNAL_SERVER_ERROR));

function sendResponse(Response $response): void
{
    applyCors($response)->send();
}

function applyCors(Response $response): Response
{
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Player-ID');
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    return $response;
}
