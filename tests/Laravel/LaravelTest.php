<?php

declare(strict_types=1);

namespace SMSWire\Tests\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use SMSWire\Client;
use SMSWire\Laravel\Facades\SMSWire;
use SMSWire\Laravel\SMSWireMessage;
use SMSWire\Laravel\SMSWireServiceProvider;
use SMSWire\Tests\Support;

final class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return ['smswire'];
    }

    public function toSmswire(object $notifiable): SMSWireMessage
    {
        return SMSWireMessage::create('Order {{order}} shipped')
            ->variables(['order' => 'A-1042'])
            ->type('transactional')
            ->tags(['orders'])
            ->metadata(['order_id' => 1042])
            ->idempotencyKey('shipped-1042');
    }
}

final class Customer
{
    use Notifiable;

    public string $phone = '+251911234567';

    public function routeNotificationForSmswire(): string
    {
        return $this->phone;
    }
}

final class LaravelTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getPackageProviders($app): array
    {
        return [SMSWireServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('smswire.key', 'smswire_live_x');
        $app['config']->set('smswire.project', 'prj_1');
        $app['config']->set('smswire.url', 'https://gw.test');
        $app['config']->set('smswire.webhook_secret', Support::fixture('webhooks.json')['secret']);
    }

    private function fakeApi(int $answers = 1): void
    {
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $answers, new Response(202, [], '{"id":"msg_1","status":"queued"}'))));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client('smswire_live_x', 'prj_1', ['base_url' => 'https://gw.test', 'http_client' => new Guzzle(['handler' => $stack])]));
    }

    public function testBindsTheClientFromConfig(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
        $this->assertInstanceOf(\SMSWire\Resources\Messages::class, SMSWire::messages());
    }

    public function testSendsANotificationThroughTheChannel(): void
    {
        $this->fakeApi();
        (new Customer())->notify(new OrderShipped());
        $request = $this->history[0]['request'];
        $this->assertSame('https://gw.test/v1/p/prj_1/messages', (string) $request->getUri());
        $this->assertSame('shipped-1042', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'to' => '+251911234567',
            'body' => 'Order {{order}} shipped',
            'variables' => ['order' => 'A-1042'],
            'type' => 'transactional',
            'tags' => ['orders'],
            'metadata' => ['order_id' => 1042],
        ], json_decode((string) $request->getBody(), true));
    }

    public function testSendsAnOnDemandNotification(): void
    {
        $this->fakeApi();
        Notifications::route('smswire', '+251922345678')->notify(new OrderShipped());
        $this->assertSame('+251922345678', json_decode((string) $this->history[0]['request']->getBody(), true)['to']);
    }

    public function testMiddlewareLetsSignedWebhooksThroughAndRejectsTheRest(): void
    {
        $f = Support::fixture('webhooks.json');
        $case = $f['cases'][0];
        Route::post('/hooks/smswire', fn (Request $r) => response()->json($r->attributes->get('smswire_event')))->middleware('smswire.signature');

        $signedNow = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $case['body'], $f['secret']);
        $this->call('POST', '/hooks/smswire', [], [], [], ['HTTP_X_SMSWIRE_SIGNATURE' => $signedNow, 'CONTENT_TYPE' => 'application/json'], $case['body'])
            ->assertOk()
            ->assertJsonPath('event', 'message.status');

        $this->call('POST', '/hooks/smswire', [], [], [], ['HTTP_X_SMSWIRE_SIGNATURE' => $case['header'], 'CONTENT_TYPE' => 'application/json'], $case['body'])
            ->assertStatus(400); // signed long ago
        $this->call('POST', '/hooks/smswire', [], [], [], ['CONTENT_TYPE' => 'application/json'], $case['body'])
            ->assertStatus(400);
    }
}
