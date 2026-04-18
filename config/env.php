<?php
declare(strict_types=1);

if (!function_exists('shvLoadEnv')) {
    /**
     * Load key-value pairs from .env file.
     */
    function shvLoadEnv(?string $envPath = null): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $path = $envPath ?: __DIR__ . '/.env';
        if (!is_file($path)) {
            $loaded = true;
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $loaded = true;
            return;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || $line[0] === '#' || str_starts_with($line, '//')) {
                continue;
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));
            if ($key == '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        $loaded = true;
    }
}

if (!function_exists('shvEnv')) {
    function shvEnv(string $key, ?string $default = null): ?string
    {
        shvLoadEnv();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string)$value;
    }
}

if (!function_exists('shvEnvInt')) {
    function shvEnvInt(string $key, int $default = 0): int
    {
        $value = shvEnv($key);
        return is_numeric($value) ? (int)$value : $default;
    }
}

if (!function_exists('shvEnvBool')) {
    function shvEnvBool(string $key, bool $default = false): bool
    {
        $value = shvEnv($key);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

shvLoadEnv(__DIR__ . '/.env');
