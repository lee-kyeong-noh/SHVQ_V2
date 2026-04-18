<?php
require_once __DIR__ . '/config/env.php';

/* ── Auth.php와 동일한 세션 설정으로 시작 (CSRF 세션 충돌 방지) ── */
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
ini_set('session.gc_maxlifetime', (string)shvEnvInt('SESSION_LIFETIME', 7200));
session_set_cookie_params([
    'lifetime' => 0,  /* 브라우저 닫으면 쿠키 삭제 */
    'path'     => '/',
    'domain'   => '',
    'secure'   => shvEnvBool('SESSION_SECURE_COOKIE', true),
    'httponly' => shvEnvBool('SESSION_HTTP_ONLY', true),
    'samesite' => shvEnv('SESSION_SAME_SITE', 'Lax'),
]);
session_start();

/* 이미 로그인 → 대시보드로 */
if (!empty($_SESSION['auth']['user_pk'])) {
    header('Location: index.php');
    exit;
}

/* ── CSRF 토큰 서버사이드 선발급 (fetchCsrf 전에 이미 세션에 존재) ── */
$csrfKey = shvEnv('CSRF_TOKEN_KEY', '_csrf_token');
if (!isset($_SESSION[$csrfKey]) || !is_string($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
$initCsrfToken = $_SESSION[$csrfKey];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SH Vision Portal</title>
<link rel="icon" href="favicon.ico" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="css/v2/login.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>html,body{margin:0;background:#0f1333;min-height:100%;}body.lp-pending .login-wrap{opacity:0;transform:translateY(10px);pointer-events:none;}body.lp-ready .login-wrap{opacity:1;transform:none;transition:opacity .18s ease,transform .18s ease;}</style>
<noscript><style>body.lp-pending .login-wrap{opacity:1;transform:none;pointer-events:auto;}</style></noscript>
<link rel="stylesheet" href="css/v2/login.css">
</head>
<body class="lp-pending">

<!-- 레이어 1: 배경 이미지 (blur 절대 없음) -->
<div class="lp-bg"></div>
<!-- 레이어 2: 어둠 오버레이 (blur 절대 없음) -->
<div class="lp-overlay"></div>

<div class="login-wrap">
    <div class="login-box">

        <div class="login-logo">
            <h1>SH Vision Portal</h1>
            <p>Integrated Smart Work Platform</p>
        </div>

        <div class="login-error" id="loginError" role="alert"></div>

        <form id="loginForm" autocomplete="on" novalidate>
            <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($initCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-icon-wrap">
                <i class="fa fa-user input-icon" aria-hidden="true"></i>
                <input type="text" name="login_id" id="loginId"
                    placeholder="아이디 입력" autocomplete="username"
                    maxlength="120" required autofocus>
            </div>

            <div class="input-icon-wrap input-icon-wrap--pw">
                <i class="fa fa-lock input-icon" aria-hidden="true"></i>
                <input type="password" name="password" id="loginPw"
                    placeholder="비밀번호 입력" autocomplete="current-password"
                    maxlength="255" required>
                <button type="button" id="togglePw" class="pw-toggle-btn" tabindex="-1" aria-label="비밀번호 표시">
                    <i class="fa fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <div id="capsWarn" class="caps-warn" role="status">
                <i class="fa fa-warning" aria-hidden="true"></i> Caps Lock이 켜져 있습니다
            </div>

            <div class="login-options">
                <label class="save-id">
                    <input type="checkbox" id="rememberMe" name="remember" value="1">
                    로그인 상태 유지
                </label>
            </div>

            <button type="submit" class="login-btn" id="loginBtn">
                <span class="btn-text">로그인</span>
                <span class="btn-spinner"><i class="fa fa-circle-o-notch fa-spin"></i></span>
            </button>
        </form>

        <div class="login-divider"></div>

        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> SH Vision. All rights reserved.
        </div>
    </div>
</div>

<script>
(function () {
    function reveal() {
        document.body.classList.remove('lp-pending');
        document.body.classList.add('lp-ready');
    }
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () { requestAnimationFrame(reveal); });
    }
    window.addEventListener('load', reveal, { once: true });
})();
</script>
<script src="js/login.js"></script>
</body>
</html>
