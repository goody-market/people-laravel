<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Oki\People\Events\IdentityEvent;
use Oki\People\Laravel\Http\IdentityWebhookController;
use Oki\People\Laravel\Http\VerifiesIdentityWebhook;
use Oki\People\Laravel\Identity\IdentityProfile;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'a-shared-secret';

beforeEach(function (): void {
    config(['people.webhook.secret' => WEBHOOK_SECRET]);
    Route::post('webhooks/identity', IdentityWebhookController::class);
});

/** A delivery as People sends one. */
function delivered(array $event, ?string $secret = WEBHOOK_SECRET, ?int $at = null): Illuminate\Testing\TestResponse
{
    $body = (string) json_encode($event);
    $at ??= time();
    $signature = $secret === null ? 'v1=nonsense' : VerifiesIdentityWebhook::for($body, $secret, $at);

    return test()->call('POST', 'webhooks/identity', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PEOPLE_SIGNATURE' => $signature,
    ], $body);
}

/** What People puts in the body. */
function an_event_body(string $type = IdentityEvent::UPDATED, string $id = 'event-1'): array
{
    return [
        'sequence' => 1,
        'event_id' => $id,
        'type' => $type,
        'subject_id' => 'user-1',
        'payload' => ['name' => 'Ada Lovelace', 'username' => 'ada'],
        'occurred_at' => now()->toIso8601String(),
    ];
}

it('applies a signed delivery', function (): void {
    delivered(an_event_body())->assertNoContent();

    expect(IdentityProfile::query()->sole()->display_name)->toBe('Ada Lovelace');
});

it('answers a repeat the same way, and applies it once', function (): void {
    delivered(an_event_body())->assertNoContent();
    delivered(an_event_body())->assertNoContent();

    expect(IdentityProfile::query()->count())->toBe(1);
});

it('refuses a delivery nobody signed', function (): void {
    delivered(an_event_body(), secret: null)->assertUnauthorized();

    expect(IdentityProfile::query()->count())->toBe(0);
});

it('refuses a delivery signed with the wrong secret', function (): void {
    delivered(an_event_body(), secret: 'somebody-elses-secret')->assertUnauthorized();
});

it('refuses a delivery old enough to have been kept and replayed', function (): void {
    delivered(an_event_body(), at: time() - 600)->assertUnauthorized();
});

it('refuses a body that is not an identity event', function (): void {
    delivered(['hello' => 'world'])->assertStatus(422);
});

it('says so when this application has no secret to check against', function (): void {
    config(['people.webhook.secret' => null]);

    delivered(an_event_body())->assertStatus(503);
});

it('holds a signature only for the body it was made from', function (): void {
    $header = VerifiesIdentityWebhook::for('a-body', WEBHOOK_SECRET);

    expect(VerifiesIdentityWebhook::holds($header, 'a-body', WEBHOOK_SECRET))->toBeTrue()
        ->and(VerifiesIdentityWebhook::holds($header, 'another-body', WEBHOOK_SECRET))->toBeFalse()
        ->and(VerifiesIdentityWebhook::holds('nonsense', 'a-body', WEBHOOK_SECRET))->toBeFalse();
});
