<?php

declare(strict_types=1);

namespace SMSWire\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use SMSWire\Exception\ApiException;

/** @internal Sends Requests with the SDK's retry rules. */
final class Transport
{
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param callable(float): void|null $sleep
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $maxRetries,
        private readonly float $maxRetryWait,
        private readonly float $timeout,
        private readonly string $userAgent,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (float $seconds) => usleep((int) round($seconds * 1_000_000));
    }

    public function send(Request $req): mixed
    {
        $options = [
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => min(10.0, $this->timeout),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent,
            ] + $req->headers,
        ];
        $query = array_filter($req->query ?? [], static fn ($v) => $v !== null);
        if ($query) {
            $options['query'] = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $query);
        }
        if ($req->body !== null) {
            $options['body'] = json_encode($req->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        }
        $url = rtrim($this->baseUrl, '/') . $req->path;

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->http->request($req->method, $url, $options);
                if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                    $text = (string) $res->getBody();
                    return $text === '' ? null : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                }
                $err = self::errorFrom($res);
            } catch (ConnectException $e) {
                $timedOut = str_contains(strtolower($e->getMessage()), 'timed out');
                $err = new ApiException(
                    null,
                    $timedOut ? 'timeout' : 'network_error',
                    $timedOut ? "No answer within {$this->timeout} s" : 'Could not reach ' . $this->baseUrl . ': ' . $e->getMessage(),
                    null,
                    $e,
                );
            } catch (GuzzleException $e) {
                $err = new ApiException(null, 'network_error', 'Could not reach ' . $this->baseUrl . ': ' . $e->getMessage(), null, $e);
            }
            $wait = $this->retryDelay($err, $req, $attempt);
            if ($wait === null) {
                throw $err;
            }
            ($this->sleep)($wait);
        }
    }

    public static function errorFrom(ResponseInterface $res): ApiException
    {
        $code = 'http_error';
        $message = 'HTTP ' . $res->getStatusCode();
        $body = json_decode((string) $res->getBody(), true);
        if (is_array($body) && is_string($body['error'] ?? null) && is_string($body['message'] ?? null)) {
            $code = $body['error'];
            $message = $body['message'];
        }
        return new ApiException($res->getStatusCode(), $code, $message, self::retryAfter($res->getHeaderLine('Retry-After')));
    }

    private static function retryAfter(string $header): ?float
    {
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }
        $at = strtotime($header);
        return $at === false ? null : max(0.0, (float) ($at - time()));
    }

    /** Seconds to wait before trying again, or null to give up. */
    private function retryDelay(ApiException $err, Request $req, int $attempt): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        $retryable = $err->status === 429 || ($req->idempotent && ($err->status === null || $err->status >= 500));
        if (!$retryable) {
            return null;
        }
        if ($err->retryAfter !== null) {
            return $err->retryAfter <= $this->maxRetryWait ? $err->retryAfter : null;
        }
        return min(8.0, 0.5 * 2 ** $attempt) * (1 + mt_rand() / mt_getrandmax() * 0.25);
    }
}
