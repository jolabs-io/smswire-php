<?php

declare(strict_types=1);

namespace SMSWire\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use SMSWire\Client;

final class Support
{
    public static function sdks(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::sdks() . '/fixtures/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A client on a scripted Guzzle; $history collects the requests it made.
     *
     * @param list<mixed> $answers Responses or exceptions, in order
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $history
     * @param array<string, mixed> $options
     */
    public static function client(array $answers, array &$history, array $options = [], string $key = 'k', string $project = 'p'): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($history));
        return new Client($key, $project, $options + ['http_client' => new Guzzle(['handler' => $stack]), 'sleep' => static fn () => null]);
    }
}
