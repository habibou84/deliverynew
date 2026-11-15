<?php

namespace App\Support;

/**
 * Codes à usage unique basés sur le temps (TOTP, RFC 6238) : ceux de Google Authenticator, Microsoft Authenticator,
 * Authy, 1Password… Code à 6 chiffres renouvelé toutes les 30 secondes, HMAC-SHA1, clé en base 32.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouvelle clé secrète (160 bits, 32 caractères en base 32) */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Pas de 30 secondes d'un instant (secondes Unix) */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** Code d'un pas donné */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Pas auquel correspond le code (un pas d'avance ou de retard toléré pour l'horloge du téléphone), ou null.
     */
    public static function match(string $secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::step($time);
        for ($step = $now - $window; $step <= $now + $window; $step++) {
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Lien otpauth:// lu par les applications (QR code) */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
