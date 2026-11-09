<?php

declare(strict_types=1);

namespace SMSWire\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SMSWire\Client;
use SMSWire\Exception\ApiException;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['SMSWIRE_KEY', 'SMSWIRE_PROJECT', 'SMSWIRE_URL'] as $name) {
            putenv($name);
        }
    }

    public function testSendCarriesKeyPathBodyAndAFreshIdempotencyKey(): void
    {
        $history = [];
        $sms = Support::client([new Response(202, [], '{"id":"msg_1"}'), new Response(202, [], '{"id":"msg_2"}')], $history, ['base_url' => 'https://gw.test/'], 'smswire_live_x', 'prj_1');
        $sms->messages->send(['to' => '+251911234567', 'body' => 'Hi', 'tags' => ['login'], 'sim' => null, 'send_at' => new \DateTimeImmutable('2026-01-02T03:04:00Z')]);
        $sms->messages->send(['to' => '+251911234567', 'body' => 'Hi']);
        [$first, $second] = array_map(fn ($h) => $h['request'], $history);
        $this->assertSame('https://gw.test/v1/p/prj_1/messages', (string) $first->getUri());
        $this->assertSame('POST', $first->getMethod());
        $this->assertSame('Bearer smswire_live_x', $first->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('smswire-php/', $first->getHeaderLine('User-Agent'));
        $this->assertSame(
            ['to' => '+251911234567', 'body' => 'Hi', 'tags' => ['login'], 'send_at' => '2026-01-02T03:04:00+00:00'],
            json_decode((string) $first->getBody(), true),
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->getHeaderLine('Idempotency-Key'));
        $this->assertNotSame($first->getHeaderLine('Idempotency-Key'), $second->getHeaderLine('Idempotency-Key'));
    }

    public function testKeepsYourIdempotencyKeyAcrossRetries(): void
    {
        $history = [];
        $drop = new ConnectException('Connection refused', new Psr7Request('POST', 'https://gw.test'));
        $sms = Support::client([$drop, new Response(202, [], '{"id":"msg_1"}')], $history);
        $sms->messages->send(['to' => '+1', 'body' => 'x'], 'order-1234');
        $this->assertSame(['order-1234', 'order-1234'], array_map(fn ($h) => $h['request']->getHeaderLine('Idempotency-Key'), $history));
    }

    public function testDoesNotRetryABulkSendAfterADroppedConnection(): void
    {
        $history = [];
        $drop = new ConnectException('Connection refused', new Psr7Request('POST', 'https://gw.test'));
        $sms = Support::client([$drop, new Response(202, [], '{}')], $history);
        try {
            $sms->messages->sendBulk(['messages' => [['to' => '+1', 'body' => 'x']]]);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame([null, 'network_error'], [$e->status, $e->errorCode]);
        }
        $this->assertCount(1, $history);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $history = [];
        $boom = fn () => new Response(503, [], '{"error":"internal_error","message":"Boom"}');
        $sms = Support::client([$boom(), $boom(), $boom(), $boom()], $history, ['max_retries' => 3]);
        try {
            $sms->messages->get('msg_1');
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame('internal_error', $e->errorCode);
        }
        $this->assertCount(4, $history);
    }

    public function testListsWithFiltersAndWalksEveryPage(): void
    {
        $history = [];
        $sms = Support::client([
            new Response(200, [], '{"data":[{"id":"a"},{"id":"b"}],"next_cursor":"c1"}'),
            new Response(200, [], '{"data":[{"id":"c"}],"next_cursor":null}'),
        ], $history);
        $ids = array_map(fn ($m) => $m['id'], iterator_to_array($sms->messages->listAll(['status' => 'delivered', 'tag' => 'login', 'limit' => 2]), false));
        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertSame('status=delivered&tag=login&limit=2', $history[0]['request']->getUri()->getQuery());
        $this->assertSame('cursor=c1&status=delivered&tag=login&limit=2', $history[1]['request']->getUri()->getQuery());
    }

    public function testUpdateSendsOnlyWhatChangesAndNullClears(): void
    {
        $history = [];
        $sms = Support::client([new Response(200, [], '{"id":"sch_1"}')], $history);
        $sms->schedules->update('sch_1', ['enabled' => false, 'ends_at' => null]);
        $this->assertSame('PATCH', $history[0]['request']->getMethod());
        $this->assertSame(['enabled' => false, 'ends_at' => null], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testReadsSettingsFromTheEnvironment(): void
    {
        putenv('SMSWIRE_KEY=env_key');
        putenv('SMSWIRE_PROJECT=env_project');
        putenv('SMSWIRE_URL=https://env.test');
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new Response(200, [], '{"data":[]}')]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        (new Client(null, null, ['http_client' => new \GuzzleHttp\Client(['handler' => $stack])]))->schedules->list();
        $this->assertSame('https://env.test/v1/p/env_project/schedules', (string) $history[0]['request']->getUri());
        $this->assertSame('Bearer env_key', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testNeedsAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(null, 'p');
    }

    public function testDeleteAnswers204(): void
    {
        $history = [];
        $sms = Support::client([new Response(204)], $history);
        $sms->schedules->delete('sch_1');
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
    }
}
