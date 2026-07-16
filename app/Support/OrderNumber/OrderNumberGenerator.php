<?php

declare(strict_types=1);

namespace App\Support\OrderNumber;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class OrderNumberGenerator
{
    private const PREFIX = 'ORD';

    private const MAX_ATTEMPTS = 5;

    /** Crockford Base32 — 0-9 A-Z minus I, L, O, U (the characters humans confuse). */
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** ISO 7064 alphanumeric alphabet: '0'..'9' => 0..9, 'A'..'Z' => 10..35. */
    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Generate a canonical, unique order number (existence-checked, bounded attempts). */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->build();
            // DB::table bypasses the Order SoftDeletes global scope — the UNIQUE index covers soft-deleted
            // rows, so the collision check must see them too.
            if (! DB::table('orders')->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Could not generate a unique order_number after '.self::MAX_ATTEMPTS.' attempts.',
        );
    }

    /** Build one candidate: ORD-BBBB-BBBB-C (no uniqueness check). */
    public function build(): string
    {
        $body = '';
        for ($i = 0; $i < 8; $i++) {
            $body .= self::CROCKFORD[random_int(0, strlen(self::CROCKFORD) - 1)];
        }

        return sprintf(
            '%s-%s-%s-%s',
            self::PREFIX,
            substr($body, 0, 4),
            substr($body, 4, 4),
            $this->checkCharacter($body),
        );
    }

    /**
     * ISO 7064 MOD 37,36 hybrid check character over the body only — the 'ORD' prefix and the
     * dashes are excluded from the computation. Seeded with 36 per the standard (`P₀ = M'`); the
     * doubling step is what makes character position — and therefore leading zeroes — significant.
     *
     * The check character is drawn from the full 0-9A-Z alphabet, NOT the Crockford subset, so it
     * may legitimately be I, L, O, or U — which is why isValid() compares it literally.
     */
    public function checkCharacter(string $body): string
    {
        $modulus = 37;
        $other = 36;
        $product = $other;

        foreach (str_split(strtoupper($body)) as $char) {
            $value = strpos(self::ALPHANUMERIC, $char);
            if ($value === false) {
                throw new InvalidArgumentException("Non-alphanumeric character '{$char}' in checksum input.");
            }
            $sum = ($value + $product) % $other;
            $product = (2 * ($sum === 0 ? $other : $sum)) % $modulus;
        }

        // $product is always 1..36 (37 is prime and 2*x never hits it), so 37 - $product is 1..36
        // and the % 36 folds the 36 case down to 0 — giving a 0..35 index into ALPHANUMERIC.
        $checkValue = ($modulus - $product) % $other;

        return self::ALPHANUMERIC[$checkValue];
    }

    /**
     * Validate an order number. The check character is compared literally; the BODY gets Crockford
     * input-aliasing (I/L => 1, O => 0), and U is rejected (not a Crockford symbol, not an alias).
     */
    public function isValid(string $orderNumber): bool
    {
        $value = strtoupper(trim($orderNumber));
        if (preg_match('/^ORD-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z])$/', $value, $m) !== 1) {
            return false;
        }
        $check = $m[3];        // literal — never Crockford-normalized (may itself be I/L/O/U)
        $body = $m[1].$m[2];
        if (str_contains($body, 'U')) {
            return false;      // U is not a Crockford body symbol and has no alias
        }
        // Crockford input-aliasing on the BODY only: I/L => 1, O => 0.
        $body = strtr($body, ['I' => '1', 'L' => '1', 'O' => '0']);

        return $this->checkCharacter($body) === $check;
    }

    /** Forgiving normalization for partial/fuzzy search: upper-case, strip non-alphanumerics. */
    public function normalizeSearchTerm(string $term): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $term));
    }
}
