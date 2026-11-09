<?php

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Searchable fingerprint of a secret value (IIN): HMAC-SHA256 with a key separate from the encryption key.
 * Key: config('prime.blind_index_key') (env BLIND_INDEX_KEY); falls back to a key derived from APP_KEY.
 */
final class BlindIndex
{
    public static function make(string $value, string $purpose = 'iin'): string
    {
        return hash_hmac('sha256', $purpose.'|'.$value, self::key());
    }

    private static function key(): string
    {
        $key = (string) config('prime.blind_index_key');
        if ($key === '' || $key === (string) config('app.key')) {
            // TZ 03 §17 p.1: the fingerprint key is separate from the encryption key. A derived key is tolerated
            // in local/testing only; in production a missing/equal key would tie both secrets to APP_KEY.
            if (app()->isProduction()) {
                throw new RuntimeException('BLIND_INDEX_KEY must be set and differ from APP_KEY in production.');
            }
            $key = hash_hmac('sha256', 'prime-blind-index', (string) config('app.key'));
        }

        return $key;
    }
}
