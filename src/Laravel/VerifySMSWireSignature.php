<?php

declare(strict_types=1);

namespace SMSWire\Laravel;

use Closure;
use Illuminate\Http\Request;
use SMSWire\Client;
use SMSWire\Exception\SignatureException;
use SMSWire\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware ('smswire.signature') for your webhook endpoint: answers 400 to anything
 * not signed with SMSWIRE_WEBHOOK_SECRET, and puts the verified event in
 * $request->attributes->get('smswire_event').
 *
 *     Route::post('/hooks/smswire', SmswireWebhookController::class)->middleware('smswire.signature');
 */
final class VerifySMSWireSignature
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $event = $this->client->verifyWebhook($request->getContent(), $request->header(Webhook::SIGNATURE_HEADER));
        } catch (SignatureException $e) {
            abort(400, $e->getMessage());
        }
        $request->attributes->set('smswire_event', $event);
        return $next($request);
    }
}
