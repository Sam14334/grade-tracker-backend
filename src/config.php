<?php

// Hosting environment variables take precedence over the local .env file.
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) || getenv($key) !== false || array_key_exists($key, $_ENV)) continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function configValue(string $key): ?string {
    $value = getenv($key);
    if ($value === false) $value = $_ENV[$key] ?? null;
    return $value === null ? null : (string)$value;
}

function requiredConfig(string $key): string {
    $value = configValue($key);
    if ($value === null || trim($value) === '') {
        throw new RuntimeException("$key is not configured in the environment or .env");
    }
    return $value;
}

function appAllowedOrigin(): string {
    return trim((string)configValue('APP_ALLOWED_ORIGIN'));
}
