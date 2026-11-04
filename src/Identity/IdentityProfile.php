<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Identity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What this application keeps of somebody People knows.
 *
 * **A read model, and never an authority.** It is written by the events
 * People publishes and by the one profile read that first sight does — never
 * by hand and never by a screen. People remains the source of truth for
 * every field on it, and anything that turns on whether somebody may still
 * act asks People rather than reading this.
 *
 * Either id may be absent and at least one is always there: an application
 * knows a caller by their account — the `sub` on their token — and often
 * knows somebody else only as a person. Both columns are nullable and
 * unique, which is a database counting two nulls as different rows.
 *
 * @property string $id
 * @property string|null $identity_id
 * @property string|null $person_id
 * @property string|null $display_name
 * @property string|null $username
 * @property string|null $email Only ever written by a profile read; People publishes no contact.
 * @property string|null $avatar_url
 * @property string $status
 * @property Carbon|null $synced_at
 */
class IdentityProfile extends Model
{
    use HasUuids;

    public const Active = 'active';

    public const Suspended = 'suspended';

    protected $table = 'identity_profiles';

    protected $guarded = [];

    /**
     * Active unless People has said otherwise.
     *
     * The column defaults to it too, but a default in the database is only
     * read back by a query: a profile written in code would otherwise answer
     * with no status at all until something reloaded it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::Active];

    /** @var array<string, string> */
    protected $casts = ['synced_at' => 'datetime'];

    /** Whether People last said this account may still be acted for. */
    public function isActive(): bool
    {
        return $this->status === self::Active;
    }

    /** Whether what is held has aged past the freshness a caller will accept. */
    public function staleAfter(int $seconds): bool
    {
        return $this->synced_at === null || $this->synced_at->addSeconds($seconds)->isPast();
    }

    /**
     * The row for whichever id is known.
     *
     * @param  Builder<IdentityProfile>  $query
     * @return Builder<IdentityProfile>
     */
    public function scopeFor(Builder $query, ?string $identity, ?string $person = null): Builder
    {
        return $query->where(function (Builder $either) use ($identity, $person): void {
            $identity === null || $either->orWhere('identity_id', $identity);
            $person === null || $either->orWhere('person_id', $person);
        });
    }
}
