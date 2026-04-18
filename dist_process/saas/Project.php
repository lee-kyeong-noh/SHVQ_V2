<?php
declare(strict_types=1);
/**
 * SHVQ V2 — Project API Bridge
 *
 * 목적:
 * - SaaS 인증/CSRF 게이트를 통과한 뒤
 * - 기존 V1 PJT API(Project.php, ProjectV2.php)로 요청을 위임한다.
 *
 * 참고:
 * - 1차 위임 대상: /SHVQ/dist_process/Project.php
 * - 폴백 대상:      /SHVQ/dist_process/ProjectV2.php
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? ''));
    if ($todoRaw === '') {
        ApiResponse::error('INVALID_PARAM', 'todo is required', 422);
        exit;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'POST') {
        ApiResponse::error('METHOD_NOT_ALLOWED', 'GET or POST required', 405);
        exit;
    }

    if ($method === 'POST' && !$auth->validateCsrf()) {
        ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    if ($roleLevel <= 0) {
        ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['current' => $roleLevel]);
        exit;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'shvq.kr'));
    if ($host === '') {
        $host = 'shvq.kr';
    }

    $legacyUrls = [
        $scheme . '://' . $host . '/SHVQ/dist_process/Project.php',
        $scheme . '://' . $host . '/SHVQ/dist_process/ProjectV2.php',
    ];

    $requestQuery = $_GET;
    $requestPost = $_POST;
    if (!array_key_exists('todo', $requestQuery) && !array_key_exists('todo', $requestPost)) {
        if ($method === 'GET') {
            $requestQuery['todo'] = $todoRaw;
        } else {
            $requestPost['todo'] = $todoRaw;
        }
    }

    $cookieHeader = trim((string)($_SERVER['HTTP_COOKIE'] ?? ''));
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'SHVQ_V2_ProjectBridge/1.0'));
    if ($userAgent === '') {
        $userAgent = 'SHVQ_V2_ProjectBridge/1.0';
    }

    $buildMultipartFields = static function (array $post, array $files): array {
        $fields = $post;
        foreach ($files as $name => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $tmp = $meta['tmp_name'] ?? null;
            $err = $meta['error'] ?? null;
            $filename = $meta['name'] ?? '';
            $mime = $meta['type'] ?? 'application/octet-stream';

            if (is_array($tmp)) {
                foreach ($tmp as $i => $tmpPath) {
                    $fileErr = is_array($err) ? (int)($err[$i] ?? UPLOAD_ERR_NO_FILE) : (int)$err;
                    $fileName = is_array($filename) ? (string)($filename[$i] ?? ('upload_' . $i)) : (string)$filename;
                    $fileType = is_array($mime) ? (string)($mime[$i] ?? 'application/octet-stream') : (string)$mime;
                    if ($fileErr !== UPLOAD_ERR_OK || !is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath)) {
                        continue;
                    }
                    $fields[$name . '[' . $i . ']'] = new CURLFile($tmpPath, $fileType !== '' ? $fileType : 'application/octet-stream', $fileName !== '' ? $fileName : basename($tmpPath));
                }
                continue;
            }

            if ((int)$err !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !is_file($tmp)) {
                continue;
            }
            $fields[$name] = new CURLFile($tmp, is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream', is_string($filename) && $filename !== '' ? $filename : basename($tmp));
        }
        return $fields;
    };

    $isUnsupportedTodo = static function (string $body): bool {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return true;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return false;
        }

        $msg = strtolower(trim((string)($decoded['msg'] ?? $decoded['message'] ?? '')));
        $err = strtolower(trim((string)($decoded['error'] ?? $decoded['code'] ?? '')));
        if ($msg !== '' && (str_contains($msg, '지원하지') || str_contains($msg, 'unsupported') || str_contains($msg, 'todo'))) {
            return true;
        }
        if ($err !== '' && (str_contains($err, 'unsupported') || str_contains($err, 'todo'))) {
            return true;
        }

        return false;
    };

    $forwardOnce = static function (
        string $url,
        string $requestMethod,
        array $query,
        array $post,
        array $files,
        string $cookie,
        string $ua,
        callable $buildMultipart
    ): array {
        $targetUrl = $url;
        if ($requestMethod === 'GET' && $query !== []) {
            $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'content_type' => 'application/json; charset=utf-8', 'body' => '', 'error' => 'curl init failed'];
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($cookie !== '') {
            $opts[CURLOPT_COOKIE] = $cookie;
        }

        if ($requestMethod === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($files !== []) {
                $opts[CURLOPT_POSTFIELDS] = $buildMultipart($post, $files);
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
            }
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json; charset=utf-8');
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) {
            $body = '';
        }

        return [
            'ok' => $err === '',
            'status' => $status,
            'content_type' => $ctype,
            'body' => $body,
            'error' => $err,
            'url' => $targetUrl,
        ];
    };

    $best = null;
    foreach ($legacyUrls as $legacyUrl) {
        $res = $forwardOnce($legacyUrl, $method, $requestQuery, $requestPost, $_FILES, $cookieHeader, $userAgent, $buildMultipartFields);
        if ($best === null) {
            $best = $res;
        }

        $status = (int)($res['status'] ?? 0);
        $body = (string)($res['body'] ?? '');
        if (($res['ok'] ?? false) !== true) {
            continue;
        }
        if ($status === 0 || $status >= 500) {
            continue;
        }
        if ($status === 404 || $isUnsupportedTodo($body)) {
            $best = $res;
            continue;
        }

        http_response_code($status > 0 ? $status : 200);
        header('Content-Type: ' . (string)($res['content_type'] ?? 'application/json; charset=utf-8'));
        echo $body;
        exit;
    }

    if (is_array($best)) {
        $status = (int)($best['status'] ?? 0);
        $body = (string)($best['body'] ?? '');
        if ($body !== '') {
            http_response_code($status > 0 ? $status : 502);
            header('Content-Type: ' . (string)($best['content_type'] ?? 'application/json; charset=utf-8'));
            echo $body;
            exit;
        }
    }

    ApiResponse::error('LEGACY_PROXY_FAILED', 'legacy project api proxy failed', 502, [
        'todo' => $todoRaw,
        'legacy_urls' => $legacyUrls,
    ]);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
