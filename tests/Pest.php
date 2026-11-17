<?php

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NickDeKruijk\ListMailing\Client;
use NickDeKruijk\ListMailing\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

pest()->extend(TestCase::class)->in('Laravel');

/**
 * A client whose requests are answered by the given responses, in order. The
 * requests it sent end up in $history.
 *
 * @param  list<Response|Throwable>  $responses
 * @param  array<int, array{request: RequestInterface, options: array<string, mixed>}>|null  $history
 */
function client(array $responses, ?array &$history = null, ?string $defaultList = 'list-1'): Client
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client('lm_test_token', 'https://listmailing.test/api/v1', $defaultList, 10.0, new GuzzleClient(['handler' => $stack]));
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function json(int $status, array $body, array $headers = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
}
