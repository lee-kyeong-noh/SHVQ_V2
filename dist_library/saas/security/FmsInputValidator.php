<?php
declare(strict_types=1);

final class FmsInputValidator
{
    public static function string(array $src, string $key, int $maxLen = 255, bool $required = false): string
    {
        $value = trim((string)($src[$key] ?? ''));

        if ($required && $value === '') {
            throw new InvalidArgumentException($key . ' is required');
        }

        if ($value !== '' && mb_strlen($value) > $maxLen) {
            throw new InvalidArgumentException($key . ' length exceeded (max ' . $maxLen . ')');
        }

        return $value;
    }

    public static function int(array $src, string $key, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        $raw = trim((string)($src[$key] ?? ''));
        if ($raw === '') {
            if ($required) {
                throw new InvalidArgumentException($key . ' is required');
            }
            return null;
        }

        if (!preg_match('/^-?\\d+$/', $raw)) {
            throw new InvalidArgumentException($key . ' must be integer');
        }

        $value = (int)$raw;
        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException($key . ' must be >= ' . $min);
        }
        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException($key . ' must be <= ' . $max);
        }

        return $value;
    }

    public static function bool(array $src, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $src)) {
            return $default;
        }

        $raw = strtolower(trim((string)$src[$key]));
        if ($raw === '') {
            return $default;
        }

        return in_array($raw, ['1', 'true', 'yes', 'on', 'y'], true);
    }

    public static function oneOf(string $value, string $key, array $allowed, bool $allowEmpty = true): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return $value;
        }

        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($key . ' value is invalid');
        }

        return $value;
    }

    public static function email(string $value, string $key, bool $allowEmpty = true): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return '';
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException($key . ' is invalid');
        }

        return $value;
    }

    public static function phone(string $value, string $key, bool $allowEmpty = true): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return '';
        }

        $digits = preg_replace('/\\D+/', '', $value) ?? '';
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 13) {
            throw new InvalidArgumentException($key . ' is invalid');
        }

        return $value;
    }

    public static function bizNumber(string $value, string $key, bool $allowEmpty = true): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return '';
        }

        $digits = preg_replace('/\\D+/', '', $value) ?? '';
        if (strlen($digits) !== 10) {
            throw new InvalidArgumentException($key . ' must be 10 digits');
        }

        return $value;
    }

    public static function decimal(string $value, string $key, bool $allowEmpty = true, ?float $min = null, ?float $max = null): ?float
    {
        $value = trim($value);
        if ($value === '') {
            if ($allowEmpty) {
                return null;
            }
            throw new InvalidArgumentException($key . ' is required');
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException($key . ' must be numeric');
        }

        $num = (float)$value;
        if ($min !== null && $num < $min) {
            throw new InvalidArgumentException($key . ' must be >= ' . $min);
        }
        if ($max !== null && $num > $max) {
            throw new InvalidArgumentException($key . ' must be <= ' . $max);
        }

        return $num;
    }

    public static function idxList(mixed $raw): array
    {
        $tokens = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                $token = trim((string)$item);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        } else {
            $parts = preg_split('/[\\s,]+/', trim((string)$raw)) ?: [];
            foreach ($parts as $part) {
                $token = trim((string)$part);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        $list = [];
        foreach ($tokens as $token) {
            if (!ctype_digit($token)) {
                continue;
            }
            $value = (int)$token;
            if ($value > 0) {
                $list[] = $value;
            }
        }

        return array_values(array_unique($list));
    }
}
