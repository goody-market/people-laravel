<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this application keeps of the people People knows.
 *
 * `identity_profiles` is the copy a screen is drawn from. `identity_events`
 * is what has been applied to it: the cursor to resume the feed from, and
 * the record that an event already landed, since delivery is at least once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable and unique, both of them: an application knows some
            // people by their account and others only as a person.
            $table->string('identity_id')->nullable()->unique('identity_profiles_identity_unique');
            $table->string('person_id')->nullable()->unique('identity_profiles_person_unique');

            $table->string('display_name')->nullable();
            $table->string('username')->nullable();

            // Never written by an event — People publishes no contact, and an
            // application reads one only where a scope released it.
            $table->string('email')->nullable();

            $table->string('avatar_url', 2048)->nullable();

            // A plain string rather than an enum column, so a status this
            // version has not heard of does not fail the write.
            $table->string('status')->default('active');

            // When this was last agreed with People. It settles out-of-order
            // delivery — an event older than this is not applied — and ages
            // a row the feed cannot refresh, since the feed carries accounts
            // and somebody with no account is never in it.
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('identity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // People's own sequence: monotonic and gapless, which is what
            // makes it the cursor. The highest here is where the next read
            // resumes from.
            $table->unsignedBigInteger('sequence')->index('identity_events_sequence_index');

            // What delivery is deduped on. At least once means the same
            // event arrives twice and the second time is a no-op.
            $table->string('event_id')->unique('identity_events_event_unique');

            $table->string('type');
            $table->string('subject_id');

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_events');
        Schema::dropIfExists('identity_profiles');
    }
};
