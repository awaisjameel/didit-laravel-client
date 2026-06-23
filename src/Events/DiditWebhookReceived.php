<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a webhook request has been received and its signature
 * successfully verified by processWebhook().
 *
 * Register a listener to react to verification events without inline handling:
 *
 *     Event::listen(DiditWebhookReceived::class, function ($event) {
 *         match ($event->payload['status'] ?? null) {
 *             'Approved' => ...,
 *             'Declined' => ...,
 *             default => ...,
 *         };
 *     });
 */
class DiditWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  The verified webhook payload.
     */
    public function __construct(public readonly array $payload) {}
}
