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
            return ['status' => 0, 'url' => $url, 'body' => '', 'json' => null, 'error' => 'curl_init_failed'];
        }

        $headers = ['Accept: application/json'];
        $cookie = $this->buildCookieHeader();
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SHVQ-V2-FmsRegressionTest/1.0',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
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
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) > 0 ? curl_error($ch) : '';

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
        ];
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

final class FmsRegressionTest
{
    private array $cfg;
    private array $results = [];

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function run(): int
    {
        $this->printHeader();

        $this->runCase('TC01 비로그인 차단 (Site list)', function (): array {
            $http = $this->newSession();
            $resp = $http->request('dist_process/saas/Site.php', 'GET', ['todo' => 'list', 'limit' => 1]);
            $code = $this->respCode($resp);
            $ok = $resp['status'] === 401 && $code === 'AUTH_REQUIRED';
            if (!$ok) {
                return [false, 'expected 401/AUTH_REQUIRED, got HTTP ' . $resp['status'] . ' code=' . $code];
            }
            return [true, 'AUTH_REQUIRED 확인'];
        });

        if ($this->hasCredential()) {
            $http = $this->newSession();

            $this->runCase('TC02 로그인 성공', function () use ($http): array {
                $csrfResp = $http->request('dist_process/saas/Auth.php', 'GET', ['todo' => 'csrf']);
                $csrf = (string)$this->jpath($csrfResp, 'data.csrf_token', '');
                if ($csrf === '') {
                    return [false, 'csrf token 발급 실패'];
                }

                $loginResp = $http->request('dist_process/saas/Auth.php', 'POST', [
                    'todo' => 'login',
                    'login_id' => (string)$this->cfg['login_id'],
                    'password' => (string)$this->cfg['password'],
                    'csrf_token' => $csrf,
                ]);

                if (!$this->isOk($loginResp)) {
                    return [false, 'login failed HTTP ' . $loginResp['status'] . ' code=' . $this->respCode($loginResp)];
                }
                return [true, '로그인 세션 획득'];
            });

            $this->runCase('TC03 Site list 조회', function () use ($http): array {
                $resp = $http->request('dist_process/saas/Site.php', 'GET', ['todo' => 'list', 'limit' => 1]);
                if (!$this->isOk($resp)) {
                    return [false, 'HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'OK'];
            });

            $this->runCase('TC04 Member list 조회', function () use ($http): array {
                $resp = $http->request('dist_process/saas/Member.php', 'GET', ['todo' => 'list', 'limit' => 1]);
                if (!$this->isOk($resp)) {
                    return [false, 'HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'OK'];
            });

            $this->runCase('TC05 HeadOffice list 조회', function () use ($http): array {
                $resp = $http->request('dist_process/saas/HeadOffice.php', 'GET', ['todo' => 'list', 'limit' => 1]);
                if (!$this->isOk($resp)) {
                    return [false, 'HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'OK'];
            });

            $this->runCase('TC06 Site write CSRF 차단', function () use ($http): array {
                $resp = $http->request('dist_process/saas/Site.php', 'POST', ['todo' => 'insert']);
                $ok = $resp['status'] === 403 && $this->respCode($resp) === 'CSRF_TOKEN_INVALID';
                if (!$ok) {
                    return [false, 'expected 403/CSRF_TOKEN_INVALID, got HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'CSRF 방어 확인'];
            });

            $this->runCase('TC07 Member write CSRF 차단', function () use ($http): array {
                $resp = $http->request('dist_process/saas/Member.php', 'POST', ['todo' => 'insert']);
                $ok = $resp['status'] === 403 && $this->respCode($resp) === 'CSRF_TOKEN_INVALID';
                if (!$ok) {
                    return [false, 'expected 403/CSRF_TOKEN_INVALID, got HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'CSRF 방어 확인'];
            });

            $this->runCase('TC08 HeadOffice write CSRF 차단', function () use ($http): array {
                $resp = $http->request('dist_process/saas/HeadOffice.php', 'POST', ['todo' => 'insert']);
                $ok = $resp['status'] === 403 && $this->respCode($resp) === 'CSRF_TOKEN_INVALID';
                if (!$ok) {
                    return [false, 'expected 403/CSRF_TOKEN_INVALID, got HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'CSRF 방어 확인'];
            });

            $this->runCase('TC09 Site est_list 파라미터 검증', function () use ($http): array {
                $resp = $http->request('dist_process/saas/Site.php', 'GET', ['todo' => 'est_list']);
                $ok = $resp['status'] === 422 && $this->respCode($resp) === 'INVALID_PARAM';
                if (!$ok) {
                    return [false, 'expected 422/INVALID_PARAM, got HTTP ' . $resp['status'] . ' code=' . $this->respCode($resp)];
                }
                return [true, 'site_idx required 검증 확인'];
            });
        } else {
            $this->runCase('TC02~TC09 건너뜀', function (): array {
                return [true, 'SHVQ_LOGIN_ID/SHVQ_PASSWORD 미설정: 인증 케이스 스킵'];
            });
        }

        return $this->printSummary();
    }

    private function newSession(): HttpSession
    {
        return new HttpSession((string)$this->cfg['base_url'], (int)$this->cfg['timeout'], (bool)$this->cfg['insecure']);
    }

    private function hasCredential(): bool
    {
        return trim((string)$this->cfg['login_id']) !== '' && trim((string)$this->cfg['password']) !== '';
    }

    private function isOk(array $resp): bool
    {
        return (bool)$this->jpath($resp, 'json.ok', false);
    }

    private function respCode(array $resp): string
    {
        return (string)$this->jpath($resp, 'json.code', '');
    }

    private function jpath(array $arr, string $path, $default = null)
    {
        $parts = explode('.', $path);
        $cur = $arr;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    private function runCase(string $name, callable $fn): void
    {
        try {
            [$ok, $msg] = array_pad((array)$fn(), 2, '');
            $ok = (bool)$ok;
            $msg = (string)$msg;
        } catch (Throwable $e) {
            $ok = false;
            $msg = $e->getMessage();
        }

        $this->results[] = ['name' => $name, 'ok' => $ok, 'message' => $msg];
        $mark = $ok ? 'PASS' : 'FAIL';
        echo sprintf("[%s] %s - %s\n", $mark, $name, $msg);
    }

    private function printHeader(): void
    {
        echo "=== SHVQ_V2 FMS Regression Test ===\n";
        echo 'base_url: ' . $this->cfg['base_url'] . "\n";
        echo 'timeout : ' . $this->cfg['timeout'] . "s\n";
        echo 'insecure: ' . ($this->cfg['insecure'] ? 'true' : 'false') . "\n";
        echo "-----------------------------------\n";
    }

    private function printSummary(): int
    {
        $total = count($this->results);
        $passed = 0;
        foreach ($this->results as $r) {
            if ((bool)($r['ok'] ?? false)) {
                $passed++;
            }
        }
        $failed = $total - $passed;

        echo "-----------------------------------\n";
        echo sprintf("TOTAL %d / PASS %d / FAIL %d\n", $total, $passed, $failed);

        return $failed > 0 ? 1 : 0;
    }
}

$opts = [
    'base_url' => getenv('SHVQ_BASE_URL') !== false ? (string)getenv('SHVQ_BASE_URL') : 'https://shvq.kr/SHVQ_V2',
    'login_id' => getenv('SHVQ_LOGIN_ID') !== false ? (string)getenv('SHVQ_LOGIN_ID') : '',
    'password' => getenv('SHVQ_PASSWORD') !== false ? (string)getenv('SHVQ_PASSWORD') : '',
    'timeout' => getenv('SHVQ_TIMEOUT') !== false ? (int)getenv('SHVQ_TIMEOUT') : 20,
    'insecure' => (bool)(getenv('SHVQ_INSECURE') !== false ? (int)getenv('SHVQ_INSECURE') : 0),
];

foreach ($argv as $arg) {
    if (strpos($arg, '--base-url=') === 0) {
        $opts['base_url'] = (string)substr($arg, strlen('--base-url='));
    } elseif (strpos($arg, '--login-id=') === 0) {
        $opts['login_id'] = (string)substr($arg, strlen('--login-id='));
    } elseif (strpos($arg, '--password=') === 0) {
        $opts['password'] = (string)substr($arg, strlen('--password='));
    } elseif (strpos($arg, '--timeout=') === 0) {
        $opts['timeout'] = (int)substr($arg, strlen('--timeout='));
    } elseif ($arg === '--insecure') {
        $opts['insecure'] = true;
    }
}

$runner = new FmsRegressionTest($opts);
exit($runner->run());
