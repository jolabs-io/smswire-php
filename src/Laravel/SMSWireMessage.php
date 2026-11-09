<?php

declare(strict_types=1);

namespace SMSWire\Laravel;

use DateTimeInterface;

/** What a notification's toSmswire() returns: the text and how to send it. */
final class SMSWireMessage
{
    public ?string $to = null;
    public ?string $template = null;
    /** @var array<string, scalar> */
    public array $variables = [];
    public ?string $type = null;
    /** @var list<string> */
    public array $tags = [];
    /** @var array<string, mixed> */
    public array $metadata = [];
    public ?int $sim = null;
    public ?DateTimeInterface $sendAt = null;
    public ?string $idempotencyKey = null;

    public function __construct(public ?string $body = null)
    {
    }

    public static function create(?string $body = null): self
    {
        return new self($body);
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /** Overrides the notifiable's routeNotificationForSmswire() number. */
    public function to(string $to): self
    {
        $this->to = $to;
        return $this;
    }

    /**
     * A saved template (id or name) instead of a body.
     *
     * @param array<string, scalar> $variables
     */
    public function template(string $template, array $variables = []): self
    {
        $this->template = $template;
        $this->variables = $variables;
        return $this;
    }

    /** @param array<string, scalar> $variables */
    public function variables(array $variables): self
    {
        $this->variables = $variables;
        return $this;
    }

    /** otp, transactional, notification or marketing. */
    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /** @param list<string> $tags */
    public function tags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * Echoed back in webhooks.
     *
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function sim(int $slot): self
    {
        $this->sim = $slot;
        return $this;
    }

    public function sendAt(DateTimeInterface $at): self
    {
        $this->sendAt = $at;
        return $this;
    }

    /** Makes a queued notification's retries safe: the same key never sends twice. */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;
        return $this;
    }

    /** @return array<string, mixed> the send() parameters, without 'to' */
    public function toArray(): array
    {
        return array_filter([
            'body' => $this->body,
            'template' => $this->template,
            'variables' => $this->variables ?: null,
            'type' => $this->type,
            'tags' => $this->tags ?: null,
            'metadata' => $this->metadata ?: null,
            'sim' => $this->sim,
            'send_at' => $this->sendAt,
        ], static fn ($v) => $v !== null);
    }
}
