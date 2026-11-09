<?php

declare(strict_types=1);

namespace SMSWire\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMSWire\Client;
use SMSWire\Exception\ApiException;
use SMSWire\Exception\SignatureException;
use SMSWire\Webhook;

/** The shared vectors in sdks/fixtures, which every SDK passes the same way. */
final class FixturesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function webhookCases(): iterable
    {
        foreach (Support::fixture('webhooks.json')['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('webhookCases')]
    public function testWebhookSignature(array $case): void
    {
        $f = Support::fixture('webhooks.json');
        if (!$case['valid']) {
            $this->expectException(SignatureException::class);
        }
        $event = Webhook::verify($case['body'], $case['header'], $f['secret'], $f['tolerance'], $case['now']);
        $this->assertSame($case['event'] ?? 'message.status', $event['event']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function errorCases(): iterable
    {
        foreach (Support::fixture('errors.json')['cases'] as $case) {
            yield 'safe request: ' . $case['name'] => [$case, 'retry'];
            yield 'POST without idempotency: ' . $case['name'] => [$case, 'retry_post'];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('errorCases')]
    public function testErrorsAndRetries(array $case, string $flag): void
    {
        $history = [];
        $sms = Support::client(
            [new Response($case['status'], $case['headers'], $case['body']), new Response(200, [], '{"id":"msg_1"}')],
            $history,
            ['max_retries' => 1, 'max_retry_wait' => Support::fixture('errors.json')['max_retry_wait']],
        );
        $call = fn (Client $s) => $flag === 'retry' ? $s->messages->get('msg_1') : $s->otp->send(['to' => '+1']);

        if ($case[$flag]) {
            $this->assertSame(['id' => 'msg_1'], $call($sms));
            $this->assertCount(2, $history);
            return;
        }
        try {
            $call($sms);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(
                [$case['status'], $case['code'], $case['message'], $case['retry_after']],
                [$e->status, $e->errorCode, $e->getMessage(), $e->retryAfter === null ? null : (int) $e->retryAfter],
            );
            $this->assertCount(1, $history);
        }
    }
}
