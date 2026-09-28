<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A small, stateless, expiring share token for the family-report public
 * link — no database table, nothing to revoke or clean up. The token is
 * `base64url(incidentId|expiresAtTimestamp|hmac)`; the HMAC (keyed on the
 * app's own encryption key) makes it infeasible to forge a token for a
 * different incident or a later expiry than a coordinator actually issued,
 * and it self-expires without any server-side state.
 *
 * This is deliberately not Laravel's built-in signed-route helper: that
 * signs one exact route+query combination, but one shareable link needs to
 * authorize several different public endpoints (fetch context, submit the
 * report, stage an upload) — so the token is verified the same way against
 * whichever endpoint receives it, independent of the URL it arrives on.
 */
class ShareToken
{
    public static function make(string $incidentId, Carbon $expiresAt): string
    {
        $payload = $incidentId.'|'.$expiresAt->getTimestamp();
        $signature = self::sign($payload);

        return self::encode($payload.'|'.$signature);
    }

    public static function verify(?string $token, string $incidentId): bool
    {
        if (blank($token)) {
            return false;
        }

        $decoded = self::decode($token);
        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return false;
        }

        [$tokenIncidentId, $expiresAtTimestamp, $signature] = $parts;

        if (! hash_equals(self::sign($tokenIncidentId.'|'.$expiresAtTimestamp), $signature)) {
            return false;
        }

        if ($tokenIncidentId !== $incidentId) {
            return false;
        }

        return (int) $expiresAtTimestamp >= now()->getTimestamp();
    }

    protected static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + (4 - strlen($value) % 4), '=');

        return (string) base64_decode($padded, true);
    }
}
