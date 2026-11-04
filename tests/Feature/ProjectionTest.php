<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Oki\People\Events\IdentityEvent;
use Oki\People\Laravel\Identity\AppliedIdentityEvent;
use Oki\People\Laravel\Identity\IdentityProfile;
use Oki\People\Laravel\Identity\ProjectsIdentities;

uses(RefreshDatabase::class);

/** One of People's events, as the SDK hands it over. */
function an_identity_event(
    string $type = IdentityEvent::UPDATED,
    string $subject = 'user-1',
    array $payload = ['name' => 'Ada Lovelace', 'username' => 'ada'],
    int $sequence = 1,
    ?string $id = null,
    ?DateTimeImmutable $occurred = null,
): IdentityEvent {
    return new IdentityEvent(
        sequence: $sequence,
        eventId: $id ?? 'event-'.$sequence,
        type: $type,
        subject: $subject,
        payload: $payload,
        occurredAt: $occurred ?? new DateTimeImmutable,
    );
}

function projection(): ProjectsIdentities
{
    return app(ProjectsIdentities::class);
}

it('writes down what People says somebody is called', function (): void {
    projection()->apply(an_identity_event());

    expect(IdentityProfile::query()->sole())
        ->identity_id->toBe('user-1')
        ->display_name->toBe('Ada Lovelace')
        ->username->toBe('ada')
        ->status->toBe(IdentityProfile::Active);
});

it('applies an event once, however many times it arrives', function (): void {
    $event = an_identity_event();

    expect(projection()->apply($event))->toBeTrue()
        ->and(projection()->apply($event))->toBeFalse()
        ->and(AppliedIdentityEvent::query()->count())->toBe(1);
});

it('does not let an older event overwrite a newer one', function (): void {
    $now = new DateTimeImmutable;

    projection()->apply(an_identity_event(payload: ['name' => 'Ada Lovelace'], sequence: 2, occurred: $now));
    projection()->apply(an_identity_event(payload: ['name' => 'Ada Byron'], sequence: 1, id: 'event-0', occurred: $now->modify('-1 hour')));

    expect(IdentityProfile::query()->sole()->display_name)->toBe('Ada Lovelace');
});

it('marks an account suspended, and lets it back in', function (): void {
    projection()->apply(an_identity_event(type: IdentityEvent::SUSPENDED, sequence: 1));
    expect(IdentityProfile::query()->sole()->isActive())->toBeFalse();

    projection()->apply(an_identity_event(type: IdentityEvent::REINSTATED, sequence: 2));
    expect(IdentityProfile::query()->sole()->isActive())->toBeTrue();
});

it('does not let a suspension that arrives late take hold', function (): void {
    $now = new DateTimeImmutable;

    projection()->apply(an_identity_event(sequence: 2, occurred: $now));
    projection()->apply(an_identity_event(type: IdentityEvent::SUSPENDED, sequence: 1, id: 'event-0', occurred: $now->modify('-1 hour')));

    expect(IdentityProfile::query()->sole()->isActive())->toBeTrue();
});

it('erases what is held when an account is deleted', function (): void {
    projection()->apply(an_identity_event(sequence: 1));
    projection()->apply(an_identity_event(type: IdentityEvent::DELETED, sequence: 2));

    expect(IdentityProfile::query()->count())->toBe(0)
        // The record that it happened stays: it carries nothing but an id.
        ->and(AppliedIdentityEvent::query()->count())->toBe(2);
});

it('records that a credential changed and leaves the profile alone', function (): void {
    projection()->apply(an_identity_event(sequence: 1));
    projection()->apply(an_identity_event(type: IdentityEvent::CREDENTIAL_CHANGED, payload: ['sign_out_everywhere' => true], sequence: 2));

    expect(IdentityProfile::query()->sole()->display_name)->toBe('Ada Lovelace')
        ->and(AppliedIdentityEvent::query()->count())->toBe(2);
});

it('follows a person being merged behind an account', function (): void {
    projection()->apply(an_identity_event(type: IdentityEvent::PERSON_MERGED, payload: ['person_id' => 'person-9'], sequence: 1));

    expect(IdentityProfile::query()->sole()->person_id)->toBe('person-9');
});

it('resumes the feed from the highest sequence applied', function (): void {
    expect(projection()->cursor())->toBe(0);

    projection()->apply(an_identity_event(sequence: 7));
    projection()->advance(7);

    expect(projection()->cursor())->toBe(7);
});

it('hands a sync run the same answer as a webhook', function (): void {
    projection()->handle(an_identity_event());

    expect(IdentityProfile::query()->sole()->username)->toBe('ada');
});

it('projects a profile read on first sight, before any event', function (): void {
    projection()->fromProfile('user-2', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'person' => ['id' => 'person-2', 'avatar_url' => 'https://people.example.com/grace.png'],
    ]);

    expect(IdentityProfile::query()->sole())
        ->identity_id->toBe('user-2')
        ->person_id->toBe('person-2')
        ->display_name->toBe('Grace Hopper')
        ->email->toBe('grace@example.com')
        ->avatar_url->toBe('https://people.example.com/grace.png');
});

it('takes the person\'s own name where the account carries none', function (): void {
    projection()->fromProfile('user-3', ['person' => ['id' => 'person-3', 'name' => 'Ada', 'username' => 'ada']]);

    expect(IdentityProfile::query()->sole())->display_name->toBe('Ada')->username->toBe('ada');
});

it('finds a row by either id', function (): void {
    projection()->fromProfile('user-4', ['name' => 'Ada', 'person' => ['id' => 'person-4']]);

    expect(IdentityProfile::query()->for('user-4')->sole()->person_id)->toBe('person-4')
        ->and(IdentityProfile::query()->for(null, 'person-4')->sole()->identity_id)->toBe('user-4')
        ->and(IdentityProfile::query()->for('nobody', 'person-4')->count())->toBe(1);
});

it('knows when what is held has aged past what a caller will accept', function (): void {
    $profile = projection()->fromProfile('user-5', ['name' => 'Ada']);

    expect($profile->staleAfter(900))->toBeFalse()
        ->and((new IdentityProfile)->staleAfter(900))->toBeTrue();
});
