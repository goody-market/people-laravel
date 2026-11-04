<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Http;

/**
 * Whether a delivery from People is genuine.
 *
 * People signs `X-People-Signature: t={seconds},v1={hex}` — HMAC-SHA256 over
 * `{t}.{body}` under the secret it printed when the address was registered.
 * The timestamp is inside what is signed, so a delivery somebody kept cannot
 * be replayed later against the same signature.
 *
 * Written here rather than in the README of the service that sends them: a
 * receiver that infers the rule from prose gets it subtly wrong, and the two
 * halves of a signature have to agree exactly.
 */
final readonly class VerifiesIdentityWebhook
{
    public const Header = 'X-People-Signature';

    /** How old a delivery may be before it is refused. */
    public const Tolerance = 300;

    /**
     * The header People would send for this body.
     *
     * Here so an application can exercise its own endpoint without
     * reimplementing the scheme in its test suite — the one place a subtly
     * different implementation would go unnoticed.
     */
    public static function for(string $body, string $secret, ?int $at = null): string
    {
        $at ??= time();

        return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret);
    }

    public static function holds(string $header, string $body, string $secret, int $tolerance = self::Tolerance): bool
    {
        parse_str(str_replace(',', '&', $header), $parts);

        $at = is_string($parts['t'] ?? null) ? (int) $parts['t'] : 0;
        $signed = is_string($parts['v1'] ?? null) ? $parts['v1'] : '';

        if ($at === 0 || abs(time() - $at) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$at}.{$body}", $secret), $signed);
    }
}
