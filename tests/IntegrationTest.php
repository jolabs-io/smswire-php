<?php

declare(strict_types=1);

namespace SMSWire\Tests;

use PHPUnit\Framework\TestCase;
use SMSWire\Client;
use SMSWire\Exception\ApiException;
use SMSWire\Exception\SignatureException;
use SMSWire\Webhook;

/**
 * Against the real API (apps/api's sdk-server: in-memory database, a phone that delivers at once).
 * Skipped outside the monorepo, or when the API's dependencies aren't installed.
 */
final class IntegrationTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    /** @var array<string, string> */
    private static array $server = [];
    private static Client $sms;

    public static function setUpBeforeClass(): void
    {
        $api = dirname(Support::sdks()) . '/apps/api';
        $tsx = $api . '/node_modules/.bin/tsx';
        if (!is_file($tsx)) {
            return;
        }
        self::$process = proc_open([$tsx, 'test/sdkServer.ts'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $api);
        $line = fgets($pipes[1]);
        self::$server = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
        self::$sms = new Client(self::$server['apiKey'], self::$server['project'], [
            'base_url' => self::$server['baseUrl'],
            'webhook_secret' => self::$server['webhookSecret'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    protected function setUp(): void
    {
        if (!self::$server) {
            $this->markTestSkipped('needs apps/api with its dependencies (pnpm install)');
        }
    }

    private static function control(string $path, ?array $body = null): mixed
    {
        $context = $body === null ? null : stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]);
        return json_decode((string) file_get_contents(self::$server['control'] . $path, false, $context), true);
    }

    private static function eventually(callable $read, callable $ok): mixed
    {
        for ($i = 0; $i < 100; $i++) {
            $value = $read();
            if ($ok($value)) {
                return $value;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('timed out');
    }

    public function testSendsAMessageThatThePhoneDelivers(): void
    {
        $sent = self::$sms->messages->send(['to' => '+251911234567', 'body' => 'Your order shipped', 'tags' => ['orders'], 'metadata' => ['order' => 7]]);
        $this->assertSame(['queued', ['orders'], ['order' => 7]], [$sent['status'], $sent['tags'], $sent['metadata']]);
        $delivered = self::eventually(fn () => self::$sms->messages->get($sent['id']), fn ($m) => $m['status'] === 'delivered');
        $this->assertNotEmpty($delivered['delivered_at']);
        $this->assertContains($sent['id'], array_column(self::$sms->messages->list(['tag' => 'orders'])['data'], 'id'));
    }

    public function testReplaysASendWithTheSameIdempotencyKey(): void
    {
        $a = self::$sms->messages->send(['to' => '+251911234567', 'body' => 'Once'], 'php-order-42');
        $b = self::$sms->messages->send(['to' => '+251911234567', 'body' => 'Once'], 'php-order-42');
        $this->assertSame($a['id'], $b['id']);
    }

    public function testCancelsAScheduledMessage(): void
    {
        $m = self::$sms->messages->send(['to' => '+251911234567', 'body' => 'Later', 'send_at' => new \DateTimeImmutable('+1 hour')]);
        $this->assertSame('cancelled', self::$sms->messages->cancel($m['id'])['status']);
    }

    public function testSendsABatchAndRejectsBadNumbers(): void
    {
        $result = self::$sms->messages->sendBulk([
            'name' => 'SDK test',
            'body' => 'Hi {{name}}',
            'messages' => [['to' => '+251911234567', 'variables' => ['name' => 'Abebe']], ['to' => 'not a number', 'variables' => ['name' => 'Nobody']]],
        ]);
        $this->assertSame(1, $result['accepted']);
        $this->assertSame([1, 'not a number'], [$result['rejected'][0]['index'], $result['rejected'][0]['to']]);
    }

    public function testSendsAndVerifiesAOneTimeCode(): void
    {
        $otp = self::$sms->otp->send(['to' => '+251944567890', 'length' => 6]);
        $texts = self::eventually(fn () => self::control('/sms'), fn ($all) => in_array($otp['message_id'], array_column($all, 'messageId'), true));
        $body = array_values(array_filter($texts, fn ($s) => $s['messageId'] === $otp['message_id']))[0]['body'];
        preg_match('/\d{6}/', $body, $m);
        $code = $m[0];
        $this->assertSame(['valid' => false, 'reason' => 'invalid'], self::$sms->otp->verify($otp['id'], $code === '000000' ? '111111' : '000000'));
        $this->assertTrue(self::$sms->otp->verify($otp['id'], $code)['valid']);
        $this->assertSame('already_used', self::$sms->otp->verify($otp['id'], $code)['reason']);
    }

    public function testManagesARecurringSend(): void
    {
        $created = self::$sms->schedules->create(['name' => 'Weekly', 'cron' => '0 9 * * 1', 'timezone' => 'Africa/Addis_Ababa', 'to' => '+251911234567', 'body' => 'Weekly report']);
        $this->assertSame(['Weekly', true, 1], [$created['name'], $created['enabled'], $created['recipients']['numbers']]);
        $this->assertFalse(self::$sms->schedules->update($created['id'], ['enabled' => false])['enabled']);
        $this->assertContains($created['id'], array_column(self::$sms->schedules->list()['data'], 'id'));
        $this->assertSame($created['id'], self::$sms->schedules->get($created['id'])['id']);
        self::$sms->schedules->delete($created['id']);
        try {
            self::$sms->schedules->get($created['id']);
            $this->fail('expected a 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testVerifiesTheWebhooksTheApiReallySends(): void
    {
        self::control('/reply', ['from' => '+251911234567', 'body' => 'ሰላም, thanks!']);
        $hooks = self::eventually(fn () => self::control('/webhooks'), fn ($all) => in_array('message.received', array_map(fn ($h) => $h['headers']['X-SMSWire-Event'], $all), true));
        foreach ($hooks as $hook) {
            $event = self::$sms->verifyWebhook($hook['body'], $hook['headers']['X-SMSWire-Signature']);
            $this->assertSame($hook['headers']['X-SMSWire-Event'], $event['event']);
        }
        $this->expectException(SignatureException::class);
        Webhook::verify(str_replace('+251', '+252', $hooks[0]['body']), $hooks[0]['headers']['X-SMSWire-Signature'], self::$server['webhookSecret']);
    }

    public function testTurnsApiErrorsIntoApiException(): void
    {
        try {
            (new Client('smswire_live_wrong', self::$server['project'], ['base_url' => self::$server['baseUrl']]))->messages->list();
            $this->fail('expected a 401');
        } catch (ApiException $e) {
            $this->assertSame([401, 'invalid_api_key'], [$e->status, $e->errorCode]);
        }
        try {
            self::$sms->messages->send(['to' => '+251911234567']);
            $this->fail('expected a 400');
        } catch (ApiException $e) {
            $this->assertSame([400, 'invalid_request'], [$e->status, $e->errorCode]);
        }
    }
}
