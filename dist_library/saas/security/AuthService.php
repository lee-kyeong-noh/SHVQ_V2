<?php
declare(strict_types=1);

final class AuthService
{
    private PDO $db;
    private array $security;
    private SessionManager $session;
    private CsrfService $csrf;
    private RateLimiter $rateLimiter;
    private PasswordService $passwordService;
    private RememberTokenService $remember;
    private CadTokenService $cadTokenService;
    private AuditLogger $audit;
    private ClientIpResolver $ipResolver;

    public function __construct()
    {
        $this->security = require __DIR__ . '/../../../config/security.php';
        $this->db = DbConnection::get();

        $this->session = new SessionManager($this->security);
        $this->csrf = new CsrfService($this->session, $this->security);
        $this->rateLimiter = new RateLimiter($this->db, $this->security);
        $this->passwordService = new PasswordService($this->db, $this->security);
        $this->remember = new RememberTokenService($this->db, $this->security);
        $this->cadTokenService = new CadTokenService($this->db, $this->security);
        $this->audit = new AuditLogger($this->db, $this->security);
        $this->ipResolver = new ClientIpResolver($this->security);

        date_default_timezone_set((string)$this->security['app']['timezone']);
    }

    public function csrfToken(): array
    {
        return [
            'ok' => true,
            'csrf_token' => $this->csrf->issueToken(),
        ];
    }

    public function currentContext(): array
    {
        return $this->session->getAuthContext();
    }

    public function validateCsrf(?string $token = null): bool
    {
        return $this->csrf->validateFromRequest($token);
    }

    public function login(string $loginId, string $password, bool $rememberMe, ?string $csrfToken = null, bool $force = false): array
    {
        $this->session->start();

        if ((bool)$this->security['csrf']['require_login_csrf'] && !$this->csrf->validateFromRequest($csrfToken)) {
            $this->audit->log('auth.login', 0, 'DENY_CSRF', 'Invalid CSRF token', ['login_id' => $loginId]);
            return ['ok' => false, 'error' => 'CSRF_TOKEN_INVALID'];
        }

        $identifier = $this->rateLimitIdentifier($loginId);
        $check = $this->rateLimiter->check($identifier);
        if (!($check['allowed'] ?? false)) {
            $this->audit->log('auth.login', 0, 'DENY_RATE_LIMIT', 'Too many attempts', ['login_id' => $loginId]);
            return [
                'ok' => false,
                'error' => 'LOGIN_RATE_LIMITED',
                'retry_after' => (int)($check['retry_after'] ?? 0),
            ];
        }

        $user = $this->findUserByLoginId($loginId);
        $userPk = is_array($user) ? (int)($user[$this->security['auth']['user_pk_column']] ?? 0) : 0;
        $isActiveUser = is_array($user) && $this->isActiveUser($user);

        $verified = false;
        if ($isActiveUser) {
            $verified = $this->passwordService->verifyAndMigrate($user, $password);
        } else {
            $this->verifyAgainstDummyHash($password);
        }

        if (!$verified) {
            $this->rateLimiter->fail($identifier);
            $this->audit->log('auth.login', $userPk, 'DENY_LOGIN', 'Invalid credentials', ['login_id' => $loginId]);
            return ['ok' => false, 'error' => 'LOGIN_FAILED'];
        }

        $tenantContext = $this->resolveTenantContext($userPk);
        if ($tenantContext === null) {
            $this->audit->log('auth.login', $userPk, 'DENY_TENANT', 'No active tenant mapping', ['login_id' => $loginId]);
            return ['ok' => false, 'error' => 'AUTH_CONTEXT_MISSING'];
        }

        /* ── 중복 로그인 체크 ── */
        $existing = $this->findActiveSession($userPk);
        if ($existing !== null && !$force) {
            return [
                'ok' => false,
                'error' => 'ALREADY_LOGGED_IN',
                'active_session' => [
                    'client_ip' => (string)$existing['client_ip'],
                    'login_at' => (string)$existing['login_at'],
                ],
            ];
        }

        $this->rateLimiter->success($identifier);
        $this->session->regenerate();

        $levelColumn = (string)$this->security['auth']['user_level_column'];
        $context = [
            'user_pk' => $userPk,
            'login_id' => $loginId,
            'role_level' => isset($user[$levelColumn]) ? (int)$user[$levelColumn] : 0,
            'service_code' => $tenantContext['service_code'],
            'tenant_id' => $tenantContext['tenant_id'],
            'login_at' => date('Y-m-d H:i:s'),
        ];

        $this->session->setAuthContext($context);
        $this->upsertActiveSession($userPk);

        if ($rememberMe) {
            $this->remember->issue($context['user_pk'], $context['service_code'], $context['tenant_id']);
        }

        $this->audit->log('auth.login', $context['user_pk'], 'OK', 'Login success', ['login_id' => $loginId]);

        return [
            'ok' => true,
            'user' => [
                'user_pk' => $context['user_pk'],
                'login_id' => $context['login_id'],
                'role_level' => $context['role_level'],
                'service_code' => $context['service_code'],
                'tenant_id' => $context['tenant_id'],
            ],
            'csrf_token' => $this->csrf->regenerateToken(),
        ];
    }

    public function restoreFromRememberCookie(): array
    {
        $this->session->start();

        $current = $this->session->getAuthContext();
        if ($current !== []) {
            return [
                'ok' => true,
                'restored' => false,
                'user' => [
                    'user_pk' => (int)$current['user_pk'],
                    'login_id' => (string)$current['login_id'],
                    'role_level' => (int)$current['role_level'],
                    'service_code' => (string)$current['service_code'],
                    'tenant_id' => (int)$current['tenant_id'],
                ],
                'csrf_token' => $this->csrf->issueToken(),
            ];
        }

        $cookieName = (string)$this->security['remember']['cookie_name'];
        $cookieValue = is_string($_COOKIE[$cookieName] ?? null) ? $_COOKIE[$cookieName] : null;
        $rememberContext = $this->remember->validateAndRotate($cookieValue);

        if (!is_array($rememberContext)) {
            return ['ok' => false, 'error' => 'REMEMBER_TOKEN_INVALID'];
        }

        $userPk = (int)$rememberContext['user_pk'];
        $user = $this->findUserByPk($userPk);
        if (!is_array($user) || !$this->isActiveUser($user)) {
            $this->remember->revokeFromCookie($cookieValue);
            $this->remember->clearCookie();
            return ['ok' => false, 'error' => 'REMEMBER_USER_INVALID'];
        }

        $tenantContext = $this->resolveTenantContext($userPk, (int)$rememberContext['tenant_id']);
        if ($tenantContext === null) {
            $this->remember->revokeFromCookie($cookieValue);
            $this->remember->clearCookie();
            return ['ok' => false, 'error' => 'AUTH_CONTEXT_MISSING'];
        }

        $this->session->regenerate();
        $levelColumn = (string)$this->security['auth']['user_level_column'];
        $loginColumn = (string)$this->security['auth']['user_login_column'];
        $context = [
            'user_pk' => $userPk,
            'login_id' => (string)($user[$loginColumn] ?? ''),
            'role_level' => isset($user[$levelColumn]) ? (int)$user[$levelColumn] : 0,
            'service_code' => $tenantContext['service_code'],
            'tenant_id' => $tenantContext['tenant_id'],
            'login_at' => date('Y-m-d H:i:s'),
        ];

        $this->session->setAuthContext($context);
        $this->audit->log('auth.remember_restore', $userPk, 'OK', 'Session restored from remember token');

        return [
            'ok' => true,
            'restored' => true,
            'user' => [
                'user_pk' => $context['user_pk'],
                'login_id' => $context['login_id'],
                'role_level' => $context['role_level'],
                'service_code' => $context['service_code'],
                'tenant_id' => $context['tenant_id'],
            ],
            'csrf_token' => $this->csrf->regenerateToken(),
        ];
    }

    public function issueCadToken(): array
    {
        $ctx = $this->session->getAuthContext();
        if ($ctx === []) {
            return ['ok' => false, 'error' => 'AUTH_REQUIRED'];
        }

        $roleLevel = (int)($ctx['role_level'] ?? 0);
        if ($roleLevel < 1) {
            return ['ok' => false, 'error' => 'FORBIDDEN'];
        }

        $issued = $this->cadTokenService->issue(
            (int)($ctx['user_pk'] ?? 0),
            (string)($ctx['service_code'] ?? 'shvq'),
            (int)($ctx['tenant_id'] ?? 0)
        );

        $this->audit->log('auth.cad_token_issue', (int)($ctx['user_pk'] ?? 0), 'OK', 'CAD token issued');

        return [
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'ttl_seconds' => $issued['ttl_seconds'],
        ];
    }

    public function verifyCadToken(string $token): array
    {
        $result = $this->cadTokenService->verify($token);
        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'CAD_TOKEN_INVALID'];
        }

        $this->audit->log('auth.cad_token_verify', (int)($result['user_pk'] ?? 0), 'OK', 'CAD token verified');

        return [
            'ok' => true,
            'user' => [
                'user_pk' => (int)$result['user_pk'],
                'service_code' => (string)$result['service_code'],
                'tenant_id' => (int)$result['tenant_id'],
            ],
            'expires_at' => (string)$result['expires_at'],
        ];
    }

    public function logout(): array
    {
        $this->session->start();
        $ctx = $this->session->getAuthContext();
        $userPk = (int)($ctx['user_pk'] ?? 0);

        $cookieName = (string)$this->security['remember']['cookie_name'];
        $cookieValue = is_string($_COOKIE[$cookieName] ?? null) ? $_COOKIE[$cookieName] : null;
        $this->remember->revokeFromCookie($cookieValue);
        $this->remember->clearCookie();

        $this->deleteActiveSession($userPk);
        $this->session->clearAuthContext();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->audit->log('auth.logout', $userPk, 'OK', 'Logout success');
        return ['ok' => true];
    }

    private function findUserByLoginId(string $loginId): ?array
    {
        $auth = $this->security['auth'];
        $table = (string)$auth['user_table'];
        $pk = (string)$auth['user_pk_column'];
        $loginCol = (string)$auth['user_login_column'];
        $passwordCol = (string)$auth['user_password_column'];
        $levelCol = (string)$auth['user_level_column'];
        $statusCol = (string)$auth['user_status_column'];

        $sql = sprintf(
            'SELECT TOP 1 %s, %s, %s, %s, %s
             FROM %s
             WHERE %s = :login_id',
            $this->qi($pk),
            $this->qi($loginCol),
            $this->qi($passwordCol),
            $this->qi($levelCol),
            $this->qi($statusCol),
            $this->qi($table),
            $this->qi($loginCol)
        );

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['login_id' => $loginId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            // Compatibility fallback: legacy Tb_Users may not have status column.
            $fallbackSql = sprintf(
                'SELECT TOP 1 %s, %s, %s, %s
                 FROM %s
                 WHERE %s = :login_id',
                $this->qi($pk),
                $this->qi($loginCol),
                $this->qi($passwordCol),
                $this->qi($levelCol),
                $this->qi($table),
                $this->qi($loginCol)
            );
            $stmt = $this->db->prepare($fallbackSql);
            $stmt->execute(['login_id' => $loginId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }
    }

    private function findUserByPk(int $userPk): ?array
    {
        if ($userPk <= 0) {
            return null;
        }

        $auth = $this->security['auth'];
        $table = (string)$auth['user_table'];
        $pk = (string)$auth['user_pk_column'];
        $loginCol = (string)$auth['user_login_column'];
        $levelCol = (string)$auth['user_level_column'];
        $statusCol = (string)$auth['user_status_column'];

        $sql = sprintf(
            'SELECT TOP 1 %s, %s, %s, %s
             FROM %s
             WHERE %s = :user_pk',
            $this->qi($pk),
            $this->qi($loginCol),
            $this->qi($levelCol),
            $this->qi($statusCol),
            $this->qi($table),
            $this->qi($pk)
        );

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_pk' => $userPk]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            // Compatibility fallback: legacy Tb_Users may not have status column.
            $fallbackSql = sprintf(
                'SELECT TOP 1 %s, %s, %s
                 FROM %s
                 WHERE %s = :user_pk',
                $this->qi($pk),
                $this->qi($loginCol),
                $this->qi($levelCol),
                $this->qi($table),
                $this->qi($pk)
            );
            $stmt = $this->db->prepare($fallbackSql);
            $stmt->execute(['user_pk' => $userPk]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }
    }

    private function resolveTenantContext(int $userPk, ?int $preferredTenantId = null): ?array
    {
        if ($userPk <= 0) {
            return null;
        }

        try {
            if ($preferredTenantId !== null && $preferredTenantId > 0) {
                $preferredSql =
                    "SELECT TOP 1 t.service_code, t.idx AS tenant_id
                     FROM dbo.Tb_SvcTenantUser tu
                     INNER JOIN dbo.Tb_SvcTenant t ON t.idx = tu.tenant_id
                     WHERE tu.user_idx = :user_pk
                       AND tu.tenant_id = :tenant_id
                       AND tu.status = 'ACTIVE'
                       AND t.status = 'ACTIVE'";

                $stmt = $this->db->prepare($preferredSql);
                $stmt->execute([
                    'user_pk' => $userPk,
                    'tenant_id' => $preferredTenantId,
                ]);
                $preferred = $stmt->fetch();
                if (is_array($preferred)) {
                    return [
                        'service_code' => (string)$preferred['service_code'],
                        'tenant_id' => (int)$preferred['tenant_id'],
                    ];
                }
            }

            $sql =
                "SELECT TOP 1 t.service_code, t.idx AS tenant_id
                 FROM dbo.Tb_SvcTenantUser tu
                 INNER JOIN dbo.Tb_SvcTenant t ON t.idx = tu.tenant_id
                 WHERE tu.user_idx = :user_pk
                   AND tu.status = 'ACTIVE'
                   AND t.status = 'ACTIVE'
                 ORDER BY CASE WHEN t.is_default = 1 THEN 0 ELSE 1 END, tu.idx ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_pk' => $userPk]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return $this->fallbackTenantContext();
            }

            return [
                'service_code' => (string)$row['service_code'],
                'tenant_id' => (int)$row['tenant_id'],
            ];
        } catch (Throwable) {
            // Degrade mode: allow login while SaaS tenant tables are not migrated yet.
            return $this->fallbackTenantContext();
        }
    }

    private function fallbackTenantContext(): array
    {
        return [
            'service_code' => 'shvq',
            'tenant_id' => 0,
        ];
    }

    private function isActiveUser(array $row): bool
    {
        $statusCol = (string)$this->security['auth']['user_status_column'];
        $active = strtolower((string)$this->security['auth']['active_status_value']);

        if (!array_key_exists($statusCol, $row)) {
            return true;
        }

        return strtolower((string)$row[$statusCol]) === $active;
    }

    private function verifyAgainstDummyHash(string $plainPassword): void
    {
        // Fixed bcrypt hash for timing-safe negative path.
        static $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        password_verify($plainPassword, $dummyHash);
    }

    private function rateLimitIdentifier(string $loginId): string
    {
        $ip = $this->ipResolver->resolve();
        return hash('sha256', strtolower(trim($loginId)) . '|' . $ip);
    }

    /* ── Active Session 관리 (중복 로그인 방지) ── */

    public function validateActiveSession(): bool
    {
        $ctx = $this->session->getAuthContext();
        $userPk = (int)($ctx['user_pk'] ?? 0);
        if ($userPk <= 0) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT session_id FROM dbo.Tb_UserActiveSession WHERE user_pk = :pk'
            );
            $stmt->execute(['pk' => $userPk]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return true;
            }
            return (string)$row['session_id'] === session_id();
        } catch (Throwable) {
            return true;
        }
    }

    private function findActiveSession(int $userPk): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT session_id, client_ip, login_at FROM dbo.Tb_UserActiveSession WHERE user_pk = :pk'
            );
            $stmt->execute(['pk' => $userPk]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function upsertActiveSession(int $userPk): void
    {
        try {
            $ip = $this->ipResolver->resolve();
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
            $sid = session_id();

            $sql = "MERGE dbo.Tb_UserActiveSession WITH (HOLDLOCK) AS t
                    USING (SELECT :pk AS user_pk) AS s ON t.user_pk = s.user_pk
                    WHEN MATCHED THEN
                        UPDATE SET session_id = :sid, client_ip = :ip, user_agent = :ua,
                                   login_at = GETDATE(), last_seen_at = GETDATE()
                    WHEN NOT MATCHED THEN
                        INSERT (user_pk, session_id, client_ip, user_agent, login_at, last_seen_at)
                        VALUES (:pk2, :sid2, :ip2, :ua2, GETDATE(), GETDATE());";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pk' => $userPk, 'sid' => $sid, 'ip' => $ip, 'ua' => $ua,
                'pk2' => $userPk, 'sid2' => $sid, 'ip2' => $ip, 'ua2' => $ua,
            ]);
        } catch (Throwable) {
            /* 테이블 미생성 시 무시 — 로그인 자체는 정상 진행 */
        }
    }

    private function deleteActiveSession(int $userPk): void
    {
        if ($userPk <= 0) {
            return;
        }
        try {
            $stmt = $this->db->prepare('DELETE FROM dbo.Tb_UserActiveSession WHERE user_pk = :pk');
            $stmt->execute(['pk' => $userPk]);
        } catch (Throwable) {
            /* 무시 */
        }
    }

    /** MSSQL 식별자 안전 인용 */
    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }
}
