<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Http;

use Illuminate\Contracts\Config\Repository as Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Oki\People\Events\IdentityEvent;
use Oki\People\Laravel\Identity\ProjectsIdentities;

/**
 * Takes a delivery from People and applies it.
 *
 * Point a route at this and set `people.webhook.secret` to what
 * `webhook:register` printed:
 *
 * ```php
 * Route::post('webhooks/identity', IdentityWebhookController::class);
 * ```
 *
 * No token: a delivery is one system calling another and nobody is signed
 * in. What authenticates it is the signature, so a request without a good
 * one is refused before anything is read out of the body.
 *
 * **Answer quickly.** People gives a receiver seconds, and applying an event
 * is one small transaction; anything an application wants to do about the
 * change belongs after the answer, not inside it.
 *
 * A delivery that arrives twice is applied once — `event_id` is what that
 * turns on — and either way it is answered `204`, because a receiver that
 * refuses a repeat makes People try it again.
 */
class IdentityWebhookController
{
    public function __invoke(Request $request, Configuration $config, ProjectsIdentities $identities): JsonResponse
    {
        $secret = $config->get('people.webhook.secret');

        if (! is_string($secret) || $secret === '') {
            abort(503, 'This application has no webhook secret, so it cannot tell a delivery from a forgery.');
        }

        $signature = $request->header(VerifiesIdentityWebhook::Header);

        if (! is_string($signature) || ! VerifiesIdentityWebhook::holds($signature, $request->getContent(), $secret)) {
            abort(401, 'That delivery is not signed by People.');
        }

        /** @var array<string, mixed> $body */
        $body = $request->json()->all();
        $event = IdentityEvent::from($body);

        if ($event === null) {
            abort(422, 'That is not an identity event.');
        }

        $identities->apply($event);

        return response()->json(status: 204);
    }
}
