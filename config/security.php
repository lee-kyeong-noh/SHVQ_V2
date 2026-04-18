<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

$trustedProxies = array_values(array_filter(
    array_map('trim', explode(',', (string)shvEnv('AUTH_TRUSTED_PROXIES', ''))),
    static fn (string $value): bool => $value !== ''
));

return [
    'app' => [
        'env' => shvEnv('APP_ENV', 'development'),
        'debug' => shvEnvBool('APP_DEBUG', false),
        'timezone' => shvEnv('APP_TIMEZONE', 'Asia/Seoul'),
        'url' => shvEnv('APP_URL', 'https://shvq.kr'),
    ],
    'session' => [
        'name' => shvEnv('SESSION_NAME', 'SHVQSESSID'),
        'lifetime' => shvEnvInt('SESSION_LIFETIME', 7200),
        'secure_cookie' => shvEnvBool('SESSION_SECURE_COOKIE', true),
        'http_only' => shvEnvBool('SESSION_HTTP_ONLY', true),
        'same_site' => shvEnv('SESSION_SAME_SITE', 'Lax'),
        'remember_days' => shvEnvInt('SESSION_REMEMBER_DAYS', 14),
    ],
    'csrf' => [
        'token_key' => shvEnv('CSRF_TOKEN_KEY', '_csrf_token'),
        'header_key' => shvEnv('CSRF_HEADER_KEY', 'X-CSRF-Token'),
        'require_login_csrf' => shvEnvBool('AUTH_REQUIRE_CSRF_LOGIN', true),
    ],
    'auth' => [
        'user_table' => shvEnv('AUTH_USER_TABLE', 'Tb_Users'),
        'user_pk_column' => shvEnv('AUTH_USER_PK_COLUMN', 'idx'),
        'user_login_column' => shvEnv('AUTH_USER_LOGIN_COLUMN', 'id'),
        'user_password_column' => shvEnv('AUTH_USER_PASSWORD_COLUMN', 'pw'),
        'user_level_column' => shvEnv('AUTH_USER_LEVEL_COLUMN', 'user_level'),
        'user_status_column' => shvEnv('AUTH_USER_STATUS_COLUMN', 'status'),
        'active_status_value' => shvEnv('AUTH_ACTIVE_STATUS_VALUE', 'active'),
        'allow_legacy_password' => shvEnvBool('AUTH_ALLOW_LEGACY_PASSWORD', false),
    ],
    'rate_limit' => [
        'max_attempts' => shvEnvInt('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'window_seconds' => shvEnvInt('AUTH_LOGIN_WINDOW_SECONDS', 300),
        'lock_seconds' => shvEnvInt('AUTH_LOCK_SECONDS', 300),
        'table' => shvEnv('AUTH_RATE_LIMIT_TABLE', 'Tb_AuthRateLimit'),
    ],
    'remember' => [
        'table' => shvEnv('AUTH_REMEMBER_TABLE', 'Tb_AuthRememberToken'),
        'cookie_name' => shvEnv('AUTH_REMEMBER_COOKIE_NAME', 'SHVQ_REMEMBER'),
    ],
    'cad' => [
        'table' => shvEnv('AUTH_CAD_TOKEN_TABLE', 'Tb_AuthCadToken'),
        'ttl_seconds' => shvEnvInt('AUTH_CAD_TOKEN_TTL_SECONDS', 300),
    ],
    'audit' => [
        'table' => shvEnv('AUTH_AUDIT_TABLE', 'Tb_AuthAuditLog'),
    ],
    'auth_audit' => [
        'min_role_level' => shvEnvInt('AUTH_AUDIT_MIN_ROLE_LEVEL', 4),
        'max_limit' => shvEnvInt('AUTH_AUDIT_MAX_LIMIT', 200),
    ],
    'network' => [
        'trust_proxy_headers' => shvEnvBool('AUTH_TRUST_PROXY_HEADERS', false),
        'trusted_proxies' => $trustedProxies,
    ],
    'shadow_write' => [
        'enabled' => shvEnvBool('SHADOW_WRITE_ENABLED', true),
        'queue_table' => shvEnv('SHADOW_QUEUE_TABLE', 'Tb_IntErrorQueue'),
        'provider_key' => shvEnv('SHADOW_QUEUE_PROVIDER', 'shadow'),
        'job_type' => shvEnv('SHADOW_QUEUE_JOB_TYPE', 'shadow_write'),
        'max_retry' => shvEnvInt('SHADOW_MAX_RETRY', 10),
        'retry_backoff_base_minutes' => shvEnvInt('SHADOW_RETRY_BACKOFF_BASE_MINUTES', 2),
        'retry_backoff_max_minutes' => shvEnvInt('SHADOW_RETRY_BACKOFF_MAX_MINUTES', 60),
        'monitor_stale_retrying_minutes' => shvEnvInt('SHADOW_MONITOR_STALE_RETRYING_MINUTES', 20),
        'monitor_backlog_threshold' => shvEnvInt('SHADOW_MONITOR_BACKLOG_THRESHOLD', 100),
        'monitor_backlog_older_minutes' => shvEnvInt('SHADOW_MONITOR_BACKLOG_OLDER_MINUTES', 10),
        'min_role_level' => shvEnvInt('SHADOW_MIN_ROLE_LEVEL', 4),
    ],
];
