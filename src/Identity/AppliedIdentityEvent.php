<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Identity;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An event from People that has already been applied here.
 *
 * Two jobs in one table: the highest `sequence` is where the next read of
 * the feed resumes from, and `event_id` is what a repeated delivery is
 * recognised by — delivery is at least once, so the same event arrives twice
 * and the second time has to be a no-op.
 *
 * @property string $id
 * @property int $sequence
 * @property string $event_id
 * @property string $type
 * @property string $subject_id
 * @property Carbon|null $occurred_at
 * @property Carbon|null $applied_at
 */
class AppliedIdentityEvent extends Model
{
    use HasUuids;

    protected $table = 'identity_events';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'sequence' => 'integer',
        'occurred_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /** Where the next read of the feed resumes from. */
    public static function cursor(): int
    {
        $highest = static::query()->max('sequence');

        return is_numeric($highest) ? (int) $highest : 0;
    }
}
