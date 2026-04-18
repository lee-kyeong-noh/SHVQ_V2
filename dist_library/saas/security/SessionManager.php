<?php
declare(strict_types=1);

final class SessionManager
{
    private array $security;

    public function __construct(array $security)
    {
        $this->security = $security;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = $this->security['session'];
        session_name((string)$cfg['name']);

        $cookieParams = [
            'lifetime' => 0,  /* 브라우저 닫으면 쿠키 삭제 */
            'path' => '/',
            'domain' => '',
            'secure' => (bool)$cfg['secure_cookie'],
            'httponly' => (bool)$cfg['http_only'],
            'samesite' => (string)$cfg['same_site'],
        ];

        ini_set('session.gc_maxlifetime', (string)(int)$cfg['lifetime']);
        session_set_cookie_params($cookieParams);
        session_start();
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function setAuthContext(array $context): void
    {
        $this->start();
        $now = date('Y-m-d H:i:s');
        if (!isset($context['login_at']) || !is_string($context['login_at'])) {
            $context['login_at'] = $now;
        }
        $context['last_activity_at'] = $now;

        $_SESSION['auth'] = $context;
        $_SESSION['shv_user'] = $this->toLegacyContext($context);
    }

    public function getAuthContext(): array
    {
        $this->start();

        $context = [];
        if (is_array($_SESSION['auth'] ?? null)) {
            $context = $_SESSION['auth'];
        } elseif (is_array($_SESSION['shv_user'] ?? null)) {
            $context = $this->fromLegacyContext($_SESSION['shv_user']);
        }

        if ($context === []) {
            return [];
        }

        if ($this->isIdleExpired($context)) {
            $this->clearAuthContext();
            return [];
        }

        $context['last_activity_at'] = date('Y-m-d H:i:s');
        $_SESSION['auth'] = $context;
        $_SESSION['shv_user'] = $this->toLegacyContext($context);

        return $context;
    }

    public function clearAuthContext(): void
    {
        $this->start();
        unset($_SESSION['auth']);
        unset($_SESSION['shv_user']);
    }

    private function isIdleExpired(array $context): bool
    {
        $lifetime = (int)($this->security['session']['lifetime'] ?? 0);
        if ($lifetime <= 0) {
            return false;
        }

        $reference = $context['last_activity_at'] ?? $context['login_at'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            return false;
        }

        $lastTimestamp = strtotime($reference);
        if ($lastTimestamp === false) {
            return false;
        }

        return (time() - $lastTimestamp) > $lifetime;
    }

    private function toLegacyContext(array $context): array
    {
        return [
            'idx' => (int)($context['user_pk'] ?? 0),
            'id' => (string)($context['login_id'] ?? ''),
            'user_level' => (int)($context['role_level'] ?? 0),
            'service_code' => (string)($context['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($context['tenant_id'] ?? 0),
            'login_at' => (string)($context['login_at'] ?? ''),
            'last_activity_at' => (string)($context['last_activity_at'] ?? ''),
        ];
    }

    private function fromLegacyContext(array $legacy): array
    {
        return [
            'user_pk' => (int)($legacy['idx'] ?? 0),
            'login_id' => (string)($legacy['id'] ?? ''),
            'role_level' => (int)($legacy['user_level'] ?? 0),
            'service_code' => (string)($legacy['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($legacy['tenant_id'] ?? 0),
            'login_at' => (string)($legacy['login_at'] ?? ''),
            'last_activity_at' => (string)($legacy['last_activity_at'] ?? ''),
        ];
    }
}
