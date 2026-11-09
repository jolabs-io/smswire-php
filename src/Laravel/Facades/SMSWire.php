<?php

declare(strict_types=1);

namespace SMSWire\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use SMSWire\Client;

/**
 *     SMSWire::messages()->send(['to' => '+251911234567', 'body' => 'Hello']);
 *
 * @method static array<string, mixed> verifyWebhook(string $rawBody, ?string $signature, int $tolerance = 300)
 *
 * @see Client
 */
final class SMSWire extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    public static function messages(): \SMSWire\Resources\Messages
    {
        return static::getFacadeRoot()->messages;
    }

    public static function otp(): \SMSWire\Resources\Otp
    {
        return static::getFacadeRoot()->otp;
    }

    public static function schedules(): \SMSWire\Resources\Schedules
    {
        return static::getFacadeRoot()->schedules;
    }
}
