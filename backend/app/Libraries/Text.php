<?php

namespace App\Libraries;

use Config\Florist;

class Text
{
    public static function slugify(string $text): string
    {
        $text = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)));

        return trim($text, '-') ?: 'item';
    }

    /** Normalises an Indian mobile number to 91XXXXXXXXXX, or returns null if invalid. */
    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? '91' . $digits : null;
    }

    /**
     * The part of a login rate-limit key that names the account: "id:<id>" for an account that exists, so every
     * spelling of the same login ("ADMIN", "+91 98765 43210", "09876543210", the email) shares one counter;
     * otherwise a canonical form of what was typed (lowercased, phone numbers as 91XXXXXXXXXX).
     */
    public static function loginKey(?array $account, string $login): string
    {
        if ($account !== null && isset($account['id'])) {
            return 'id:' . (int) $account['id'];
        }
        $login = trim($login);
        if (! str_contains($login, '@') && ($phone = self::normalizePhone($login)) !== null) {
            return 'phone:' . $phone;
        }

        return mb_strtolower($login, 'UTF-8');
    }

    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw);
    }

    public static function rupees($amount): string
    {
        $amount = (float) $amount;

        return '₹' . number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }

    /** Absolute public URL, e.g. for links inside emails. */
    public static function siteUrl(string $path = ''): string
    {
        return rtrim(config(Florist::class)->siteUrl, '/') . '/' . ltrim($path, '/');
    }

    /** "uploads/x.webp" → "/uploads/x.webp" (paths are stored relative to public/). */
    public static function publicPath(?string $path): ?string
    {
        return $path ? '/' . ltrim($path, '/') : null;
    }

    /** "uploads/x.webp" → the file on disk, under the web root (Config\Florist::$publicPath). */
    public static function publicFile(string $path): string
    {
        return rtrim(config(Florist::class)->publicPath, '/\\') . '/' . ltrim($path, '/');
    }

    /** A php.ini size ("64M", "1G", "512K") in bytes. */
    public static function iniBytes(string $value): int
    {
        $n = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g'     => $n * 1024 ** 3,
            'm'     => $n * 1024 ** 2,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    /** Random letters and digits (Laravel's Str::random). */
    public static function random(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }

        return $out;
    }

    /** "2026-11-17 18:30:00" (stored in the shop's time zone) → "2026-11-17T13:00:00.000000Z", as Laravel puts dates in JSON. */
    public static function jsonDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? $value : gmdate('Y-m-d\TH:i:s.000000\Z', $time);
    }

    /** "2026-11-17 18:30:00" → "2026-11-17T18:30:00+05:30" (Carbon's toIso8601String). */
    public static function isoDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? $value : date('Y-m-d\TH:i:sP', $time);
    }
}
