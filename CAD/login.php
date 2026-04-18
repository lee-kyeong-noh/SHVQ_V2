<?php
if (!function_exists('cadIsHttpsRequest')) {
    function cadIsHttpsRequest(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if (intval($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
        $xfp = strtolower(trim((string)(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '')));
        if ($xfp === 'https') return true;
        $xfs = strtolower(trim((string)(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))[0] ?? '')));
        return $xfs === 'on';
    }
}
if (PHP_SAPI !== 'cli' && !cadIsHttpsRequest()) {
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'shvq.kr');
    if (strpos($host, ',') !== false) $host = trim(explode(',', $host)[0]);
    $host = trim(preg_replace('/[\r\n]+/', '', $host));
    if ($host === '') $host = 'shvq.kr';
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '') $uri = '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    $secure = cadIsHttpsRequest();
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_secure', $secure ? '1' : '0');
    if (!headers_sent()) {
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
        }
    }
    session_start();
}
require_once __DIR__ . '/config.php';

// ERP 직책(part_position) → CAD 레벨 매핑
$POSITION_TO_LEVEL = [
    0 => 1,  // 직책 없음 → 뷰어
    1 => 2,  // 조원 → 작성자
    2 => 3,  // 조장 → 검수자
    3 => 4,  // 팀장 → 관리자
    4 => 5,  // 그룹장 → 시스템관리자
];

// 어드민 계정 (무조건 레벨 5)
$ADMIN_LIST = ['admin', '15051801', '21062401', '26031601'];

// DB 연결 — V2 DbConnection 싱글턴 사용
function getDB(){
    try {
        return cadGetDB();
    } catch(Exception $e){
        return null;
    }
}

// ERP에서 토큰으로 접속한 경우 자동 로그인
if(isset($_GET['token']) && !isset($_SESSION['cad_user'])){
    $_GET['action'] = 'erp_auth';
    $_POST['token'] = $_GET['token'];
    ob_start();
    include __DIR__ . '/dist/login.php';
    ob_end_clean();
    if(isset($_SESSION['cad_user'])){
        header('Location: cad.php');
        exit;
    }
}

// 이미 로그인된 경우 바로 CAD로 이동
if(isset($_SESSION['cad_user'])){
    header('Location: cad.php');
    exit;
}

$error = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    // CSRF 검증
    if(!cadCsrfService()->validateFromRequest()){
        $error = '보안 토큰이 만료되었습니다. 페이지를 새로고침 하세요.';
    }
    $id = trim($_POST['id'] ?? '');
    $pw = trim($_POST['pw'] ?? '');

    if(!$error && (!$id || !$pw)){
        $error = '아이디와 비밀번호를 입력하세요';
    }
    // Rate Limit 체크
    if(!$error){
        $rlId = cadRateLimitId($id);
        $rlResult = cadRateLimiter()->check($rlId);
        if(!$rlResult['allowed']){
            $error = '로그인 시도 횟수 초과. '.$rlResult['retry_after'].'초 후 다시 시도하세요';
        }
    }
    if(!$error){
        $pdo = getDB();
        if(!$pdo){
            $error = 'DB 연결 실패';
        } else {
            $stmt = $pdo->prepare("SELECT u.*, e.name as emp_name, e.part_position, e.emp_num, e.member_photo FROM Tb_Users u LEFT JOIN Tb_Employee e ON u.employee_idx=e.idx WHERE u.id=?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$user || !cadPasswordService()->verifyAndMigrate($user, $pw)){
                if(isset($rlId)) cadRateLimiter()->fail($rlId);
                $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            } else {
                if(isset($rlId)) cadRateLimiter()->success($rlId);
                // CAD 레벨 결정
                $level = in_array($id, $ADMIN_LIST) ? 5 : ($POSITION_TO_LEVEL[intval($user['part_position']??0)] ?? 1);

                $_SESSION['cad_user'] = [
                    'id'=>$user['id'],
                    'name'=>$user['emp_name']??$user['name'],
                    'level'=>$level,
                    'authority_idx'=>$user['authority_idx'],
                    'employee_idx'=>$user['employee_idx'],
                    'user_type'=>$user['user_type'],
                    'login_time'=>date('Y-m-d H:i:s'),
                    'photo'=>$user['member_photo'] ? 'https://img.shv.kr/employee/'.$user['member_photo'] : '',
                    'method'=>'direct'
                ];

                // 로그인 시간 업데이트
                $pdo->prepare("UPDATE Tb_Users SET login_date=GETDATE() WHERE idx=?")->execute([$user['idx']]);

                header('Location: cad.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SHVCAD - 로그인</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
    background:#0b1120;
    display:flex;align-items:center;justify-content:center;
    height:100vh;font-family:'Noto Sans KR',sans-serif;
}
.login-box{
    background:#0d1828;border:1px solid #1b3354;border-radius:12px;
    padding:40px 36px;width:380px;max-width:95vw;
    box-shadow:0 8px 40px rgba(0,0,0,0.7);
}
.login-logo{
    text-align:center;margin-bottom:28px;
}
.login-logo h1{
    font-size:28px;font-weight:700;color:#00aaff;letter-spacing:2px;
}
.login-logo p{
    color:#3a5a7a;font-size:11px;margin-top:6px;
}
.login-field{margin-bottom:16px}
.login-field label{
    display:block;color:#8ab4d4;font-size:12px;margin-bottom:5px;
}
.login-field input{
    width:100%;padding:10px 14px;
    background:#111e30;border:1px solid #1b3354;border-radius:6px;
    color:#d0e8ff;font-size:14px;outline:none;
    transition:border-color 0.2s;
}
.login-field input:focus{border-color:#00aaff}
.login-field input::placeholder{color:#3a5a7a}
.login-btn{
    width:100%;padding:12px;margin-top:8px;
    background:#0077cc;border:none;border-radius:6px;
    color:#fff;font-size:14px;font-weight:600;cursor:pointer;
    transition:background 0.2s;letter-spacing:1px;
}
.login-btn:hover{background:#00aaff}
.login-error{
    background:rgba(255,68,102,0.1);border:1px solid #ff4466;
    color:#ff4466;padding:8px 12px;border-radius:5px;
    font-size:12px;margin-bottom:14px;text-align:center;
}
.login-footer{
    text-align:center;margin-top:24px;
    color:#3a5a7a;font-size:10px;
}
.login-accounts{
    margin-top:20px;padding-top:16px;border-top:1px solid #1b3354;
}
.login-accounts p{color:#3a5a7a;font-size:10px;margin-bottom:6px;text-align:center}
.login-accounts table{width:100%;font-size:10px;color:#5a7a9a;border-collapse:collapse}
.login-accounts td{padding:2px 6px}
.login-accounts td:first-child{color:#00aaff;font-weight:600}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>
<body>

<div class="login-box">
    <div class="login-logo">
        <h1>SHV SmartCAD</h1>
        <p>Cloud-based Smart CAD System</p>
    </div>

    <?php if($error): ?>
    <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(cadCsrfService()->issueToken()); ?>">
        <div class="login-field">
            <label>아이디</label>
            <input type="text" name="id" placeholder="아이디 입력" autofocus required>
        </div>
        <div class="login-field" style="position:relative">
            <label>비밀번호</label>
            <input type="password" name="pw" id="pwInput" placeholder="비밀번호 입력" required style="padding-right:40px">
            <span id="togglePw" style="position:absolute;right:12px;top:38px;cursor:pointer;color:#5a7a9a;font-size:15px;user-select:none" title="비밀번호 보기"><i class="fa fa-eye"></i></span>
        </div>
        <div id="capsWarn" style="display:none;color:#ffaa00;font-size:11px;margin-bottom:8px;text-align:center">⚠ Caps Lock이 켜져 있습니다</div>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px">
            <input type="checkbox" id="saveId" onchange="if(this.checked)localStorage.setItem('cad_save_id',document.querySelector('[name=id]').value);else localStorage.removeItem('cad_save_id');">
            <label for="saveId" style="color:#5a7a9a;font-size:11px;cursor:pointer">ID 저장</label>
        </div>
        <button type="submit" class="login-btn">로그인</button>
    </form>
    <script>
    (function(){
        var saved=localStorage.getItem('cad_save_id');
        if(saved){
            document.querySelector('[name=id]').value=saved;
            document.getElementById('saveId').checked=true;
            document.querySelector('[name=pw]').focus();
        }
        document.querySelector('form').addEventListener('submit',function(){
            if(document.getElementById('saveId').checked)
                localStorage.setItem('cad_save_id',document.querySelector('[name=id]').value);
        });

        // 한글 입력 차단 (영어만 허용)
        var inputs=document.querySelectorAll('[name=id],[name=pw]');
        inputs.forEach(function(inp){
            inp.addEventListener('compositionstart',function(){this._composing=true;});
            inp.addEventListener('compositionend',function(){
                this._composing=false;
                this.value=this.value.replace(/[ㄱ-ㅎㅏ-ㅣ가-힣]/g,'');
            });
            inp.addEventListener('input',function(){
                if(!this._composing) this.value=this.value.replace(/[ㄱ-ㅎㅏ-ㅣ가-힣]/g,'');
            });
        });

        // 비밀번호 보기 토글
        document.getElementById('togglePw').addEventListener('click',function(){
            var pw=document.getElementById('pwInput');
            if(pw.type==='password'){
                pw.type='text';
                this.innerHTML='<i class="fa fa-eye-slash"></i>';
                this.title='비밀번호 숨기기';
            } else {
                pw.type='password';
                this.innerHTML='<i class="fa fa-eye"></i>';
                this.title='비밀번호 보기';
            }
        });

        // Caps Lock 감지
        document.addEventListener('keydown',function(e){
            var warn=document.getElementById('capsWarn');
            if(e.getModifierState && e.getModifierState('CapsLock')){
                warn.style.display='block';
            } else {
                warn.style.display='none';
            }
        });
    })();
    </script>

    <div class="login-accounts">
        <p>ERP 계정으로 로그인</p>
        <table>
            <tr><td colspan="3" style="color:#5a7a9a;font-size:10px">사원번호와 ERP 비밀번호를 입력하세요</td></tr>
        </table>
    </div>

    <div class="login-footer">
        &copy; 2026 SH Vision. All rights reserved.
    </div>
</div>

</body>
</html>
