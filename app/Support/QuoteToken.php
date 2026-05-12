<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class QuoteToken
{
    /**
     * Sign a quote payload with HMAC-SHA256 keyed by APP_KEY.
     * Returns "<base64(payload)>.<hex(signature)>".
     *
     * @param  array<string, mixed>  $payload  (must already include the absolute "expires_at" unix ts)
     *
     * @throws \JsonException if the payload cannot be serialised
     */
    public static function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, self::secret());

        return $b64.'.'.$sig;
    }

    /**
     * Verify the signature and TTL of a quote token.
     *
     * Throws InvalidArgumentException on malformed / tampered tokens.
     * On success returns ['payload' => ..., 'expired' => bool] — caller must
     * inspect 'expired' and decide whether to reject as stale.
     *
     * @return array{payload: array<string, mixed>, expired: bool}
     *
     * @throws InvalidArgumentException on any malformed/tampered token
     */
    public static function verify(string $token): array
    {
        if (! str_contains($token, '.')) {
            throw new InvalidArgumentException('malformed_token');
        }
        [$b64, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $b64, self::secret());
        if (strlen($sig) !== 64 || ! hash_equals($expected, $sig)) {
            throw new InvalidArgumentException('bad_signature');
        }

        $padded = $b64.str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($json === false) {
            throw new InvalidArgumentException('bad_base64');
        }
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (! is_array($payload) || ! isset($payload['expires_at'])) {
            throw new InvalidArgumentException('bad_payload');
        }

        return [
            'payload' => $payload,
            'expired' => (int) $payload['expires_at'] < time(),
        ];
    }

    private static function secret(): string
    {
        // Use APP_KEY but namespaced so accidental leaks of this HMAC don't compromise other signed-URL paths.
        $key = (string) Config::get('app.key');

        return hash('sha256', 'quote_token|'.$key, true);
    }
}
