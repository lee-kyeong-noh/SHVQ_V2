/* ========================================
   SHVQ V2 — Login Page JS
   AJAX 로그인 + CSRF 자동 주입
   ======================================== */
'use strict';

(function () {
    var AUTH_URL = 'dist_process/saas/Auth.php';
    /* 서버사이드에서 hidden field에 미리 주입된 CSRF 토큰 읽기 */
    var csrfToken = (document.getElementById('csrfToken') || {}).value || '';

    var form     = document.getElementById('loginForm');
    var loginId  = document.getElementById('loginId');
    var loginPw  = document.getElementById('loginPw');
    var errorBox = document.getElementById('loginError');
    var loginBtn = document.getElementById('loginBtn');
    var togglePw = document.getElementById('togglePw');
    var capsWarn = document.getElementById('capsWarn');
    var remember = document.getElementById('rememberMe');

    /* ── CSRF 토큰 fetch ── */
    function fetchCsrf() {
        return fetch(AUTH_URL + '?todo=csrf', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok && d.csrf_token) {
                csrfToken = d.csrf_token;
                document.getElementById('csrfToken').value = csrfToken;
            }
        })
        .catch(function () { /* 무시 — 로그인 시 재시도 */ });
    }

    /* ── 에러 표시 ── */
    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
        /* 재-애니메이션 트리거 (런타임 좌표 예외) */
        errorBox.style.animation = 'none';
        void errorBox.offsetWidth;
        errorBox.style.animation = '';
    }

    function showForceLoginPrompt(ip, loginAt) {
        var time = loginAt ? loginAt.replace(/^\d{4}-\d{2}-\d{2}\s/, '') : '';
        var ipText = ip || '알 수 없음';
        errorBox.innerHTML = '';
        var msgEl = document.createElement('div');
        msgEl.textContent = '다른 기기(IP: ' + ipText + ')에서 ' + time + '에 접속 중입니다.';
        errorBox.appendChild(msgEl);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'force-login-btn';
        btn.textContent = '로그아웃하고 여기서 로그인';
        btn.addEventListener('click', function () { submitLogin(true); });
        errorBox.appendChild(btn);
        errorBox.style.display = 'block';
        errorBox.style.animation = 'none';
        void errorBox.offsetWidth;
        errorBox.style.animation = '';
    }

    function hideError() {
        errorBox.style.display = 'none';
    }

    /* ── 버튼 로딩 상태 ── */
    function setLoading(on) {
        loginBtn.disabled = on;
        loginBtn.querySelector('.btn-text').style.display    = on ? 'none' : '';
        loginBtn.querySelector('.btn-spinner').style.display = on ? 'inline-flex' : 'none';
    }

    /* ── 로그인 요청 ── */
    function submitLogin(force) {
        var id = loginId.value.trim();
        var pw = loginPw.value;

        hideError();
        setLoading(true);

        var body = new FormData();
        body.append('todo',       'login');
        body.append('login_id',   id);
        body.append('password',   pw);
        body.append('remember',   remember.checked ? '1' : '0');
        body.append('csrf_token', csrfToken);
        if (force) body.append('force', '1');

        fetch(AUTH_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            setLoading(false);

            if (data.ok) {
                if (data.csrf_token) csrfToken = data.csrf_token;

                if (typeof webkit !== 'undefined' &&
                    webkit.messageHandlers &&
                    webkit.messageHandlers.callbackHandler &&
                    data.app_token) {
                    webkit.messageHandlers.callbackHandler.postMessage({
                        save_id:    id,
                        save_token: data.app_token
                    });
                }

                window.location.href = data.redirect || 'index.php';
                return;
            }

            var err = data.error || '';
            if (err === 'ALREADY_LOGGED_IN') {
                var sess = data.active_session || {};
                showForceLoginPrompt(sess.client_ip, sess.login_at);
            } else if (err === 'LOGIN_RATE_LIMITED') {
                var sec = data.retry_after || 300;
                showError('로그인 시도가 너무 많습니다. ' + sec + '초 후 재시도해주세요.');
            } else if (err === 'CSRF_TOKEN_INVALID') {
                showError('보안 토큰이 만료되었습니다. 다시 시도해주세요.');
                fetchCsrf();
            } else if (err === 'INVALID_INPUT' || err === 'INVALID_INPUT_LENGTH') {
                showError('아이디 또는 비밀번호를 확인해주세요.');
            } else if (err === 'LOGIN_FAILED') {
                showError('아이디 또는 비밀번호가 올바르지 않습니다.');
            } else {
                showError(data.message || '로그인에 실패했습니다.');
            }
        })
        .catch(function () {
            setLoading(false);
            showError('네트워크 연결을 확인해주세요.');
        });
    }

    /* ── 폼 제출 ── */
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var id = loginId.value.trim();
        var pw = loginPw.value;

        if (!id) { showError('아이디를 입력하세요.'); loginId.focus(); return; }
        if (!pw) { showError('비밀번호를 입력하세요.'); loginPw.focus(); return; }

        try {
            if (remember.checked) {
                localStorage.setItem('shv_v2_login_id', id);
            } else {
                localStorage.removeItem('shv_v2_login_id');
            }
        } catch (ex) {}

        submitLogin(false);
    });

    /* ── 비밀번호 토글 ── */
    if (togglePw) {
        togglePw.addEventListener('click', function () {
            var isText = loginPw.type === 'text';
            loginPw.type = isText ? 'password' : 'text';
            togglePw.querySelector('i').className = isText ? 'fa fa-eye' : 'fa fa-eye-slash';
        });
    }

    /* ── Caps Lock 감지 ── */
    document.addEventListener('keydown', function (e) {
        if (!capsWarn) return;
        capsWarn.style.display = e.getModifierState && e.getModifierState('CapsLock') ? 'block' : 'none';
    });

    /* ── remember_session (자동 로그인 쿠키 복구) ── */
    function tryRememberSession() {
        if (!document.cookie.match(/SHVQ_REMEMBER=/)) return;

        fetch(AUTH_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'todo=remember_session'
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) window.location.href = d.redirect || 'index.php';
        })
        .catch(function () {});
    }

    /* ── 초기화 ── */
    fetchCsrf();
    tryRememberSession();

    /* 저장된 아이디 복원 */
    try {
        var savedId = localStorage.getItem('shv_v2_login_id');
        if (savedId) {
            loginId.value   = savedId;
            remember.checked = true;
            loginPw.focus();
        }
    } catch (ex) {}

})();
