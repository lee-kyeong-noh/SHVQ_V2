<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

try {
    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? '');
    $auth = new AuthService();

    if ($todo === 'csrf') {
        ApiResponse::fromLegacy($auth->csrfToken());
        exit;
    }

    if ($todo === 'heartbeat') {
        $ctx = $auth->currentContext();
        if ($ctx === []) {
            ApiResponse::fromLegacy(['ok' => false, 'error' => 'SESSION_EXPIRED']);
            exit;
        }
        if (!$auth->validateActiveSession()) {
            ApiResponse::fromLegacy(['ok' => false, 'error' => 'FORCE_LOGGED_OUT']);
            exit;
        }
        ApiResponse::fromLegacy(['ok' => true]);
        exit;
    }

    if ($todo === 'login') {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = (string)($_POST['remember'] ?? '0');
        $csrfToken = (string)($_POST['csrf_token'] ?? '');

        if ($loginId === '' || $password === '') {
            ApiResponse::error('INVALID_INPUT', 'login_id/password is required', 422);
            exit;
        }

        if (mb_strlen($loginId) > 120 || mb_strlen($password) > 255) {
            ApiResponse::error('INVALID_INPUT_LENGTH', 'input length exceeded', 422, [
                'max_login_id' => 120,
                'max_password' => 255,
            ]);
            exit;
        }

        $force = in_array((string)($_POST['force'] ?? '0'), ['1', 'true'], true);
        $result = $auth->login($loginId, $password, in_array($remember, ['1', 'true', 'on', 'yes'], true), $csrfToken, $force);

        if (!(bool)($result['ok'] ?? false) && (string)($result['error'] ?? '') === 'LOGIN_RATE_LIMITED') {
            ApiResponse::fromLegacy($result, 200, 429);
            exit;
        }

        ApiResponse::fromLegacy($result, 200, 401);
        exit;
    }

    if ($todo === 'remember_session') {
        $result = $auth->restoreFromRememberCookie();
        ApiResponse::fromLegacy($result, 200, 401);
        exit;
    }

    if ($todo === 'cad_token_issue') {
        $result = $auth->issueCadToken();
        ApiResponse::fromLegacy($result, 200, 401);
        exit;
    }

    if ($todo === 'cad_token_verify') {
        $token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
        if ($token === '') {
            ApiResponse::error('INVALID_INPUT', 'token is required', 422);
            exit;
        }

        $result = $auth->verifyCadToken($token);
        ApiResponse::fromLegacy($result, 200, 401);
        exit;
    }

    if ($todo === 'logout') {
        ApiResponse::fromLegacy($auth->logout());
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
