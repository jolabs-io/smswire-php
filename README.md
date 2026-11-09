# SMSWire for PHP and Laravel

Send SMS and one-time codes from your app through your own Android phones with [SMSWire](https://smswire.app).

```sh
composer require smswire/smswire-php
```

PHP 8.2+, Guzzle 7, Laravel 11+. Includes a Laravel notification channel, facade and webhook middleware (auto-discovered).

## Set up

Each project on the dashboard has an API key and a slug (the part after `/p/` in its endpoint). Keep them in the environment (`.env` in Laravel):

```sh
SMSWIRE_KEY="smswire_live_…"
SMSWIRE_PROJECT="prj_…"
SMSWIRE_WEBHOOK_SECRET="whsec_…"   # only if you receive webhooks
# SMSWIRE_URL="https://api.smswire.app"   # your server, if you host SMSWire yourself
```

```php
$sms = new SMSWire\Client(); // or new SMSWire\Client($apiKey, $project, ['base_url' => …])
```

## Send

```php
$message = $sms->messages->send([
    'to' => '+251911234567',
    'body' => 'Your order {{order}} shipped',
    'variables' => ['order' => 'A-1042'],
    'type' => 'transactional',          // otp | transactional | notification | marketing
    'tags' => ['orders'],
    'metadata' => ['user_id' => 42],     // echoed back in webhooks
]);
// → ['id' => 'msg_…', 'status' => 'queued', 'segments' => 1, …]

// Your own idempotency key makes your retries safe too (the SDK adds a random one otherwise).
$sms->messages->send(['to' => $to, 'body' => $body], "order-{$order->id}");

$sms->messages->get($message['id']);
$sms->messages->cancel($message['id']);        // before it reaches a phone

$page = $sms->messages->list(['status' => 'failed', 'limit' => 50]);
foreach ($sms->messages->listAll(['tag' => 'orders']) as $m) {
    echo $m['id'], ' ', $m['status'], PHP_EOL;
}

$batch = $sms->messages->sendBulk([
    'name' => 'Weekend sale',
    'body' => 'Hi {{name}}, 20% off this weekend',
    'rate' => 30, // per minute
    'messages' => [
        ['to' => '+251911234567', 'variables' => ['name' => 'Abebe']],
        ['to' => '+251922345678', 'variables' => ['name' => 'Sara']],
    ],
]);
// → ['batch_id' => …, 'accepted' => 2, 'rejected' => [['index', 'to', 'reason'], …]]
```

## One-time codes

```php
$otp = $sms->otp->send(['to' => '+251911234567', 'length' => 6, 'ttl' => 300]);
// later, with what the user typed:
$result = $sms->otp->verify($otp['id'], $code);
if (!$result['valid']) {
    echo $result['reason']; // invalid | expired | too_many_attempts | already_used
}
```

## Recurring sends

```php
$schedule = $sms->schedules->create([
    'name' => 'Weekly report',
    'cron' => '0 9 * * 1',
    'timezone' => 'Africa/Addis_Ababa',
    'to' => '+251911234567',
    'body' => 'Your weekly report is ready',
]);
$sms->schedules->update($schedule['id'], ['enabled' => false]);
$sms->schedules->list();
$sms->schedules->delete($schedule['id']);
```

## Webhooks

Status updates (`message.status`) and replies (`message.received`) are POSTed to the project's webhook URL, signed with `X-SMSWire-Signature`. Verify the **raw** body:

```php
use SMSWire\Exception\SignatureException;
use SMSWire\Webhook;

try {
    $event = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_X_SMSWIRE_SIGNATURE'] ?? null, getenv('SMSWIRE_WEBHOOK_SECRET'));
} catch (SignatureException $e) {
    http_response_code(400);
    exit;
}
if ($event['event'] === 'message.status') { /* $event['id'], $event['status'], $event['metadata'] */ }
if ($event['event'] === 'message.received') { /* $event['from'], $event['body'] */ }
```

The same event can arrive twice; make the handler idempotent.

## Laravel

The package registers itself. Optionally publish the config: `php artisan vendor:publish --tag=smswire-config`.

**Notifications:**

```php
use SMSWire\Laravel\SMSWireMessage;

class OrderShipped extends Notification
{
    public function via($notifiable): array
    {
        return ['smswire'];
    }

    public function toSmswire($notifiable): SMSWireMessage
    {
        return SMSWireMessage::create("Your order {$this->order->number} shipped")
            ->tags(['orders'])
            ->metadata(['order_id' => $this->order->id])
            ->idempotencyKey("shipped-{$this->order->id}"); // safe queue retries
    }
}

// On the notifiable model:
public function routeNotificationForSmswire(): ?string
{
    return $this->phone;
}

// Or to any number:
Notification::route('smswire', '+251911234567')->notify(new OrderShipped($order));
```

**Facade:** `SMSWire::messages()->send([...])`, `SMSWire::otp()->verify($id, $code)`.

**Webhooks:** the `smswire.signature` middleware answers 400 to anything not signed with `SMSWIRE_WEBHOOK_SECRET` and hands you the event:

```php
Route::post('/hooks/smswire', function (Request $request) {
    $event = $request->attributes->get('smswire_event');
    // …
    return response()->noContent();
})->middleware('smswire.signature');
```

Exclude the route from CSRF protection (`VerifyCsrfToken::$except`, or `validateCsrfTokens(except: [...])` in Laravel 11+).

## Errors and retries

Failed calls throw `SMSWire\Exception\ApiException` with `$status` (null when no answer came), `$errorCode` (`invalid_request`, `invalid_api_key`, `rate_limited`, `daily_limit_reached`, `network_error`, `timeout`, …), `getMessage()` and `$retryAfter`.

The SDK retries (default `max_retries` 2, waiting `Retry-After` or backing off):

- reads, updates, deletes and `messages->send` (it carries an idempotency key): on 429, 5xx and network errors;
- `sendBulk`, `otp->send`, `otp->verify`, `schedules->create`: on 429 only, since a 5xx or a dropped connection may already have done the work.

A `Retry-After` longer than `max_retry_wait` (60 s, e.g. a daily limit) throws at once.
