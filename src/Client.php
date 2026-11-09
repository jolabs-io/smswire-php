<?php

declare(strict_types=1);

namespace SMSWire;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use SMSWire\Http\Transport;
use SMSWire\Resources\Messages;
use SMSWire\Resources\Otp;
use SMSWire\Resources\Schedules;

/**
 * Client for one SMSWire project.
 *
 *     $sms = new SMSWire\Client();   // SMSWIRE_KEY, SMSWIRE_PROJECT, SMSWIRE_URL from the environment
 *     $sms->messages->send(['to' => '+251911234567', 'body' => 'Hello']);
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.smswire.app';

    public readonly Messages $messages;
    public readonly Otp $otp;
    public readonly Schedules $schedules;
    private readonly ?string $webhookSecret;

    /**
     * @param array{
     *     base_url?: string,
     *     webhook_secret?: string,
     *     max_retries?: int,
     *     max_retry_wait?: float,
     *     timeout?: float,
     *     http_client?: ClientInterface,
     *     sleep?: callable(float): void,
     * } $options
     */
    public function __construct(?string $apiKey = null, ?string $project = null, array $options = [])
    {
        $apiKey = $apiKey ?: self::env('SMSWIRE_KEY');
        $project = $project ?: self::env('SMSWIRE_PROJECT');
        if (!$apiKey) {
            throw new InvalidArgumentException('SMSWire: pass an API key or set SMSWIRE_KEY');
        }
        if (!$project) {
            throw new InvalidArgumentException('SMSWire: pass a project or set SMSWIRE_PROJECT');
        }

        $transport = new Transport(
            $options['http_client'] ?? new Guzzle(),
            $apiKey,
            $options['base_url'] ?? self::env('SMSWIRE_URL') ?? self::DEFAULT_BASE_URL,
            $options['max_retries'] ?? 2,
            (float) ($options['max_retry_wait'] ?? 60),
            (float) ($options['timeout'] ?? 30),
            'smswire-php/' . self::VERSION,
            $options['sleep'] ?? null,
        );
        $p = '/v1/p/' . rawurlencode($project);
        $this->messages = new Messages($transport, $p . '/messages');
        $this->otp = new Otp($transport, $p . '/otp');
        $this->schedules = new Schedules($transport, $p . '/schedules');
        $this->webhookSecret = $options['webhook_secret'] ?? self::env('SMSWIRE_WEBHOOK_SECRET');
    }

    /**
     * Webhook::verify() with this client's signing secret.
     *
     * @return array<string, mixed>
     */
    public function verifyWebhook(string $rawBody, ?string $signature, int $tolerance = 300): array
    {
        if (!$this->webhookSecret) {
            throw new InvalidArgumentException('SMSWire: pass webhook_secret or set SMSWIRE_WEBHOOK_SECRET');
        }
        return Webhook::verify($rawBody, $signature, $this->webhookSecret, $tolerance);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
