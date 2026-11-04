<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Identity;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Oki\People\Events\IdentityEvent;
use Oki\People\Laravel\Contracts\HandlesIdentityEvents;

/**
 * Keeps the projection current from People's events.
 *
 * Bind it as `people.events.handler` and `people:sync` walks the feed into
 * it. The same `apply()` is what a webhook receiver calls, so an application
 * that is pushed to and an application that polls arrive at the same rows.
 *
 * **Safe to repeat, and safe to receive out of order.** Delivery is at least
 * once, so the same event arrives twice; and nothing promises the order it
 * arrives in, so what settles two events about one account is `occurred_at`,
 * never which landed first.
 */
class ProjectsIdentities implements HandlesIdentityEvents
{
    public function cursor(): int
    {
        return AppliedIdentityEvent::cursor();
    }

    public function handle(IdentityEvent $event): void
    {
        $this->apply($event);
    }

    /** The cursor is the highest sequence applied, so there is nothing to advance. */
    public function advance(int $sequence): void {}

    /**
     * Applies one event, and answers whether it was new.
     *
     * False is an ordinary outcome — an event already seen, or one older
     * than what is held — not a failure.
     */
    public function apply(IdentityEvent $event): bool
    {
        $occurred = Carbon::instance($event->occurredAt);

        return (bool) DB::transaction(function () use ($event, $occurred): bool {
            // Recorded first, and inside the transaction: the unique key on
            // `event_id` is what makes a repeated delivery a no-op, and a
            // record written after the change would let two arrivals of one
            // event both apply.
            if (! $this->record($event, $occurred)) {
                return false;
            }

            match ($event->type) {
                IdentityEvent::REGISTERED, IdentityEvent::UPDATED, IdentityEvent::PERSON_MERGED => $this->wrote($event, $occurred),
                IdentityEvent::SUSPENDED => $this->became($event, IdentityProfile::Suspended, $occurred),
                IdentityEvent::REINSTATED => $this->became($event, IdentityProfile::Active, $occurred),
                IdentityEvent::DELETED => $this->erased($event),
                // That a credential changed, never what it changed to. An
                // application holding a session of its own ends it by
                // listening for this; the projection has nothing to change.
                default => null,
            };

            return true;
        });
    }

    /**
     * Writes down what People just said about somebody.
     *
     * The cold-start path: somebody first sight has never heard of must not
     * wait for an event to be drawn, so the one profile read that met them
     * is projected as it goes. Unlike an event it carries no `occurred_at` —
     * People answered it just now.
     *
     * @param  array<string, mixed>  $profile
     */
    public function fromProfile(string $identity, array $profile): IdentityProfile
    {
        $person = is_array($profile['person'] ?? null) ? $profile['person'] : [];

        $held = IdentityProfile::query()->firstOrNew(['identity_id' => $identity]);

        $held->forceFill(array_filter([
            'identity_id' => $identity,
            'person_id' => $this->text($person, 'id'),
            'display_name' => $this->text($profile, 'name') ?? $this->text($person, 'name'),
            'username' => $this->text($profile, 'username') ?? $this->text($person, 'username'),
            // The one field no event ever carries: whether an application may
            // read somebody's email is decided by the scopes its token was
            // granted, so it arrives here or not at all.
            'email' => $this->text($profile, 'email'),
            'avatar_url' => $this->text($profile, 'avatar_url') ?? $this->text($person, 'avatar_url'),
        ], fn (?string $said): bool => $said !== null) + ['synced_at' => now()])->save();

        return $held;
    }

    /** Writes the event down, or answers false where it has been seen. */
    private function record(IdentityEvent $event, Carbon $occurred): bool
    {
        if (AppliedIdentityEvent::query()->where('event_id', $event->eventId)->exists()) {
            return false;
        }

        AppliedIdentityEvent::query()->create([
            'sequence' => $event->sequence,
            'event_id' => $event->eventId,
            'type' => $event->type,
            'subject_id' => $event->subject,
            'occurred_at' => $occurred,
            'applied_at' => now(),
        ]);

        return true;
    }

    /** What People now says somebody is called. */
    private function wrote(IdentityEvent $event, Carbon $occurred): void
    {
        $profile = $this->held($event->subject);

        if ($this->stale($profile, $occurred)) {
            return;
        }

        $profile->forceFill(array_filter([
            'person_id' => $this->text($event->payload, 'person_id'),
            'display_name' => $this->text($event->payload, 'name'),
            'username' => $this->text($event->payload, 'username'),
            'avatar_url' => $this->text($event->payload, 'avatar_url'),
        ], fn (?string $said): bool => $said !== null) + ['synced_at' => $occurred])->save();
    }

    /** The account was suspended or let back in. */
    private function became(IdentityEvent $event, string $status, Carbon $occurred): void
    {
        $profile = $this->held($event->subject);

        if ($this->stale($profile, $occurred)) {
            return;
        }

        $profile->forceFill(['status' => $status, 'synced_at' => $occurred])->save();
    }

    /**
     * The account is gone, so what is held about them goes.
     *
     * Erasure, not a status: a projection is a copy of somebody's personal
     * data, and a deletion request has to reach it. The applied event stays,
     * because it is the record that the erasure happened and carries nothing
     * about them but an id.
     */
    private function erased(IdentityEvent $event): void
    {
        IdentityProfile::query()->where('identity_id', $event->subject)->delete();
    }

    private function held(string $identity): IdentityProfile
    {
        return IdentityProfile::query()->firstOrNew(['identity_id' => $identity]);
    }

    /**
     * Whether what is held is already newer than this event.
     *
     * Arrival order is not event order, and a replay walks history again
     * from wherever the cursor was: without this, yesterday's name is
     * written over today's.
     */
    private function stale(IdentityProfile $profile, DateTimeInterface $occurred): bool
    {
        return $profile->synced_at !== null && $profile->synced_at->greaterThan($occurred);
    }

    /** @param  array<string, mixed>  $from */
    private function text(array $from, string $field): ?string
    {
        $value = $from[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
