<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

final class HttpSession
{
    private string $baseUrl;
    private int $timeout;
    private bool $insecure;
    private array $cookies = [];

    public function __construct(string $baseUrl, int $timeout = 20, bool $insecure = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(5, $timeout);
        $this->insecure = $insecure;
    }

    public function request(string $path, string $method = 'GET', array $params = []): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($method === 'GET' && $params !== []) {
            $query = http_build_query($params);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status' => 0,
                'url' => $url,
                'body' => '',
                'json' => null,
                'error' => 'curl_init failed',
            ];
        }

        $headers = ['Accept: application/json'];
        $cookieHeader = $this->buildCookieHeader();
        if ($cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        $rawHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SHVQ-V2-AuthRegressionTest/1.0',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$rawHeaders): int {
                $rawHeaders[] = rtrim($line, "\r\n");
                $this->captureSetCookie($line);
                return strlen($line);
            },
        ]);

        if ($this->insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno > 0 ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $body = is_string($body) ? $body : '';
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $json = null;
        }

        return [
            'status' => $status,
            'url' => $url,
            'body' => $body,
            'json' => $json,
            'error' => $error,
            'headers' => $rawHeaders,
        ];
    }

    public function getCookie(string $name): ?string
    {
        return array_key_exists($name, $this->cookies) ? $this->cookies[$name] : null;
    }

    public function setCookie(string $name, string $value): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $this->cookies[$name] = $value;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    public function allCookies(): array
    {
        return $this->cookies;
    }

    private function buildCookieHeader(): string
    {
        if ($this->cookies === []) {
            return '';
        }

        $parts = [];
        foreach ($this->cookies as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode('; ', $parts);
    }

    private function captureSetCookie(string $headerLine): void
    {
        if (stripos($headerLine, 'Set-Cookie:') !== 0) {
            return;
        }

        $cookieRaw = trim(substr($headerLine, strlen('Set-Cookie:')));
        if ($cookieRaw === '') {
            return;
        }

        $segments = explode(';', $cookieRaw);
        $nameValue = trim((string)array_shift($segments));
        if ($nameValue === '' || strpos($nameValue, '=') === false) {
            return;
        }

        [$name, $value] = array_pad(explode('=', $nameValue, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            return;
        }

        if ($value === '' || strcasecmp($value, 'deleted') === 0) {
            unset($this->cookies[$name]);
            return;
        }

        $this->cookies[$name] = $value;
    }
}

final class TestRunner
{
    private array $config;
    private array $results = [];
    private ?int $primaryRoleLevel = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function run(): int
    {
        $this->printHeader();

        $this->runCase('TC01 로그인 성공', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $resp = $this->login($http, (string)$this->config['login_id'], (string)$this->config['password'], $csrf, false);
            if (!$this->isSuccess($resp)) {
                return [false, '로그인 실패: ' . $this->respCode($resp) . ' (HTTP ' . $resp['status'] . ')'];
            }

            $role = (int)$this->jpath($resp, 'data.user.role_level', -1);
            $this->primaryRoleLevel = $role;

            return [true, 'role_level=' . $role];
        });

        $this->runCase('TC02 로그인 실패 (비밀번호 오류)', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $wrong = (string)$this->config['password'] . '__wrong__';
            $resp = $this->login($http, (string)$this->config['login_id'], $wrong, $csrf, false);
            $code = $this->respCode($resp);
            if ($code !== 'LOGIN_FAILED') {
                return [false, '예상 LOGIN_FAILED, 실제 ' . $code . ' (HTTP ' . $resp['status'] . ')'];
            }

            return [true, 'LOGIN_FAILED 확인'];
        });

        $this->runCase('TC03 잠금(rate limit 5회) 검증', function (): array {
            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $loginId = 'rl_' . date('YmdHis') . '_' . random_int(1000, 9999);
            $codes = [];
            $statuses = [];
            for ($i = 1; $i <= 6; $i++) {
                $resp = $this->login($http, $loginId, 'wrong_pw_' . $i, $csrf, false);
                $codes[] = $this->respCode($resp);
                $statuses[] = (int)$resp['status'];
            }

            $firstFiveOk = true;
            for ($i = 0; $i < 5; $i++) {
                if (($codes[$i] ?? '') !== 'LOGIN_FAILED') {
                    $firstFiveOk = false;
                    break;
                }
            }
            $sixthCode = $codes[5] ?? '';
            $sixthStatus = $statuses[5] ?? 0;
            $lockOk = ($sixthCode === 'LOGIN_RATE_LIMITED') && ($sixthStatus === 429);

            if (!$firstFiveOk || !$lockOk) {
                return [
                    false,
                    '예상 불일치 codes=' . implode(',', $codes) . ' statuses=' . implode(',', array_map('strval', $statuses)),
                ];
            }

            return [true, '1~5회 LOGIN_FAILED, 6회 LOGIN_RATE_LIMITED(429) 확인'];
        });

        $this->runCase('TC04-1 CSRF 유효 토큰', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $resp = $this->login($http, (string)$this->config['login_id'], (string)$this->config['password'], $csrf, false);
            if (!$this->isSuccess($resp)) {
                return [false, '유효 토큰 로그인 실패: ' . $this->respCode($resp) . ' (HTTP ' . $resp['status'] . ')'];
            }

            return [true, '로그인 성공'];
        });

        $this->runCase('TC04-2 CSRF 무효 토큰', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $resp = $this->login($http, (string)$this->config['login_id'], (string)$this->config['password'], 'invalid_csrf_token', false);
            $code = $this->respCode($resp);
            if ($code !== 'CSRF_TOKEN_INVALID') {
                return [false, '예상 CSRF_TOKEN_INVALID, 실제 ' . $code . ' (HTTP ' . $resp['status'] . ')'];
            }

            return [true, 'CSRF_TOKEN_INVALID 확인'];
        });

        $this->runCase('TC04-3 CSRF 누락 토큰', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $resp = $this->login($http, (string)$this->config['login_id'], (string)$this->config['password'], null, false);
            $code = $this->respCode($resp);
            if ($code !== 'CSRF_TOKEN_INVALID') {
                return [false, '예상 CSRF_TOKEN_INVALID, 실제 ' . $code . ' (HTTP ' . $resp['status'] . ')'];
            }

            return [true, 'CSRF_TOKEN_INVALID 확인'];
        });

        $this->runCase('TC05 remember token 세션 복원', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $rememberCookieName = (string)$this->config['remember_cookie_name'];

            $sessLogin = $this->newSession();
            $csrf = $this->issueCsrfToken($sessLogin);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $loginResp = $this->login($sessLogin, (string)$this->config['login_id'], (string)$this->config['password'], $csrf, true);
            if (!$this->isSuccess($loginResp)) {
                return [false, 'remember 로그인 실패: ' . $this->respCode($loginResp) . ' (HTTP ' . $loginResp['status'] . ')'];
            }

            $rememberValue = $sessLogin->getCookie($rememberCookieName);
            if (!is_string($rememberValue) || trim($rememberValue) === '') {
                return [false, $rememberCookieName . ' 쿠키가 발급되지 않음'];
            }

            $sessRestore = $this->newSession();
            $sessRestore->setCookie($rememberCookieName, $rememberValue);

            $restoreResp = $sessRestore->request('dist_process/saas/Auth.php', 'POST', [
                'todo' => 'remember_session',
            ]);

            if (!$this->isSuccess($restoreResp)) {
                return [false, 'remember_session 실패: ' . $this->respCode($restoreResp) . ' (HTTP ' . $restoreResp['status'] . ')'];
            }

            $restored = (bool)$this->jpath($restoreResp, 'data.restored', false);
            if (!$restored) {
                return [false, 'data.restored=false (복원되지 않음)'];
            }

            return [true, 'remember_session 복원 성공'];
        });

        $this->runCase('TC06 role_level 권한 차단(403)', function (): array {
            $targetLogin = '';
            $targetPassword = '';

            $lowLogin = trim((string)$this->config['low_login_id']);
            $lowPassword = (string)$this->config['low_password'];

            if ($lowLogin !== '' && $lowPassword !== '') {
                $targetLogin = $lowLogin;
                $targetPassword = $lowPassword;
            } elseif ($this->hasPrimaryCredential() && is_int($this->primaryRoleLevel) && $this->primaryRoleLevel < 5) {
                $targetLogin = (string)$this->config['login_id'];
                $targetPassword = (string)$this->config['password'];
            }

            if ($targetLogin === '' || $targetPassword === '') {
                return [false, 'low-role 계정 필요 (--low-login-id/--low-password)'];
            }

            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $loginResp = $this->login($http, $targetLogin, $targetPassword, $csrf, false);
            if (!$this->isSuccess($loginResp)) {
                return [false, '권한계정 로그인 실패: ' . $this->respCode($loginResp) . ' (HTTP ' . $loginResp['status'] . ')'];
            }

            $role = (int)$this->jpath($loginResp, 'data.user.role_level', -1);
            $resp = $http->request('dist_process/saas/Tenant.php', 'GET', ['todo' => 'list_tenants']);
            $code = $this->respCode($resp);

            if ((int)$resp['status'] !== 403 || $code !== 'FORBIDDEN') {
                return [false, '예상 403/FORBIDDEN, 실제 HTTP ' . $resp['status'] . ' code=' . $code . ' role=' . $role];
            }

            return [true, 'HTTP 403/FORBIDDEN 확인 (role_level=' . $role . ')'];
        });

        $this->runCase('TC07 로그아웃 후 세션 무효화', function (): array {
            if (!$this->hasPrimaryCredential()) {
                return [false, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 또는 --login-id/--password 필요'];
            }

            $http = $this->newSession();
            $csrf = $this->issueCsrfToken($http);
            if ($csrf === null) {
                return [false, 'CSRF 발급 실패'];
            }

            $loginResp = $this->login($http, (string)$this->config['login_id'], (string)$this->config['password'], $csrf, false);
            if (!$this->isSuccess($loginResp)) {
                return [false, '로그인 실패: ' . $this->respCode($loginResp) . ' (HTTP ' . $loginResp['status'] . ')'];
            }

            $logoutResp = $http->request('dist_process/saas/Auth.php', 'POST', ['todo' => 'logout']);
            if (!$this->isSuccess($logoutResp)) {
                return [false, 'logout 실패: ' . $this->respCode($logoutResp) . ' (HTTP ' . $logoutResp['status'] . ')'];
            }

            $protectedResp = $http->request('dist_process/saas/Dashboard.php', 'GET', ['todo' => 'summary']);
            $protectedCode = $this->respCode($protectedResp);
            if ((int)$protectedResp['status'] !== 401 || $protectedCode !== 'AUTH_REQUIRED') {
                return [false, 'logout 이후 보호자원 응답 이상: HTTP ' . $protectedResp['status'] . ' code=' . $protectedCode];
            }

            $rememberResp = $http->request('dist_process/saas/Auth.php', 'POST', ['todo' => 'remember_session']);
            if ($this->isSuccess($rememberResp)) {
                return [false, 'logout 이후 remember_session 이 성공함'];
            }

            return [true, '세션 무효화 및 재복원 차단 확인'];
        });

        return $this->printSummary();
    }

    private function runCase(string $name, callable $fn): void
    {
        $started = microtime(true);
        try {
            $result = $fn();
            $ok = (bool)($result[0] ?? false);
            $message = (string)($result[1] ?? '');
        } catch (Throwable $e) {
            $ok = false;
            $message = '예외: ' . $e->getMessage();
        }
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);

        $this->results[] = [
            'name' => $name,
            'ok' => $ok,
            'message' => $message,
            'elapsed_ms' => $elapsedMs,
        ];

        $label = $ok ? 'PASS' : 'FAIL';
        $line = sprintf('[%s] %s (%dms)', $label, $name, $elapsedMs);
        if ($message !== '') {
            $line .= ' - ' . $message;
        }
        echo $line . PHP_EOL;
    }

    private function printHeader(): void
    {
        echo '=== SHVQ_V2 Auth Regression Test ===' . PHP_EOL;
        echo 'Base URL : ' . $this->config['base_url'] . PHP_EOL;
        echo 'Start At : ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
    }

    private function printSummary(): int
    {
        $pass = 0;
        $fail = 0;
        $totalMs = 0;

        foreach ($this->results as $r) {
            $totalMs += (int)$r['elapsed_ms'];
            if (!empty($r['ok'])) {
                $pass++;
            } else {
                $fail++;
            }
        }

        echo str_repeat('-', 72) . PHP_EOL;
        echo 'SUMMARY: total=' . count($this->results)
            . ', pass=' . $pass
            . ', fail=' . $fail
            . ', elapsed=' . $totalMs . 'ms' . PHP_EOL;

        return $fail > 0 ? 1 : 0;
    }

    private function newSession(): HttpSession
    {
        return new HttpSession((string)$this->config['base_url'], (int)$this->config['timeout'], (bool)$this->config['insecure']);
    }

    private function hasPrimaryCredential(): bool
    {
        return trim((string)$this->config['login_id']) !== '' && (string)$this->config['password'] !== '';
    }

    private function issueCsrfToken(HttpSession $http): ?string
    {
        $resp = $http->request('dist_process/saas/Auth.php', 'POST', ['todo' => 'csrf']);
        if (!$this->isSuccess($resp)) {
            return null;
        }

        $token = $this->jpath($resp, 'data.csrf_token', null);
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function login(HttpSession $http, string $loginId, string $password, ?string $csrfToken, bool $remember): array
    {
        $params = [
            'todo' => 'login',
            'login_id' => $loginId,
            'password' => $password,
            'remember' => $remember ? '1' : '0',
        ];

        if (is_string($csrfToken)) {
            $params['csrf_token'] = $csrfToken;
        }

        return $http->request('dist_process/saas/Auth.php', 'POST', $params);
    }

    private function isSuccess(array $resp): bool
    {
        return (bool)($resp['json']['success'] ?? false);
    }

    private function respCode(array $resp): string
    {
        $json = $resp['json'] ?? null;
        if (!is_array($json)) {
            return $resp['error'] !== '' ? 'CURL_ERROR' : 'NON_JSON_RESPONSE';
        }
        return (string)($json['code'] ?? $json['error'] ?? 'UNKNOWN');
    }

    private function jpath(array $resp, string $path, mixed $default = null): mixed
    {
        $json = $resp['json'] ?? null;
        if (!is_array($json)) {
            return $default;
        }

        $current = $json;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) {
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = '1';
            continue;
        }

        if (strncmp($arg, '--', 2) !== 0) {
            continue;
        }

        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $key = substr($arg, 2);
            $out[$key] = '1';
            continue;
        }

        $key = substr($arg, 2, $eqPos - 2);
        $value = substr($arg, $eqPos + 1);
        $out[$key] = $value;
    }
    return $out;
}

function envOrDefault(string $name, string $default = ''): string
{
    $v = getenv($name);
    return is_string($v) && $v !== '' ? $v : $default;
}

function printUsage(): void
{
    $usage = <<<TXT
Usage:
  php SHVQ_V2/tests/AuthRegressionTest.php [options]

Options:
  --base-url=URL                default: http://211.116.112.67/SHVQ_V2
  --login-id=ID                 primary login id (또는 SHVQ_LOGIN_ID)
  --password=PW                 primary password (또는 SHVQ_PASSWORD)
  --low-login-id=ID             low-role login id (또는 SHVQ_LOW_LOGIN_ID)
  --low-password=PW             low-role password (또는 SHVQ_LOW_PASSWORD)
  --remember-cookie-name=NAME   default: SHVQ_REMEMBER
  --timeout=SECONDS             default: 20
  --insecure=1                  HTTPS 인증서 검증 비활성화
  --help                        show this help
TXT;
    echo $usage . PHP_EOL;
}

$args = parseArgs($argv);
if (($args['help'] ?? '') === '1') {
    printUsage();
    exit(0);
}

$baseUrl = trim((string)($args['base-url'] ?? envOrDefault('SHVQ_BASE_URL', 'http://211.116.112.67/SHVQ_V2')));
$loginId = (string)($args['login-id'] ?? envOrDefault('SHVQ_LOGIN_ID'));
$password = (string)($args['password'] ?? envOrDefault('SHVQ_PASSWORD'));
$lowLoginId = (string)($args['low-login-id'] ?? envOrDefault('SHVQ_LOW_LOGIN_ID'));
$lowPassword = (string)($args['low-password'] ?? envOrDefault('SHVQ_LOW_PASSWORD'));
$rememberCookieName = trim((string)($args['remember-cookie-name'] ?? envOrDefault('SHVQ_REMEMBER_COOKIE_NAME', 'SHVQ_REMEMBER')));
$timeout = (int)($args['timeout'] ?? envOrDefault('SHVQ_TIMEOUT', '20'));
$insecure = in_array((string)($args['insecure'] ?? envOrDefault('SHVQ_INSECURE', '0')), ['1', 'true', 'yes', 'on'], true);

$config = [
    'base_url' => $baseUrl,
    'login_id' => $loginId,
    'password' => $password,
    'low_login_id' => $lowLoginId,
    'low_password' => $lowPassword,
    'remember_cookie_name' => $rememberCookieName !== '' ? $rememberCookieName : 'SHVQ_REMEMBER',
    'timeout' => $timeout > 0 ? $timeout : 20,
    'insecure' => $insecure,
];

$runner = new TestRunner($config);
$exitCode = $runner->run();
exit($exitCode);
