<?php
if(session_status()===PHP_SESSION_NONE) session_start();
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if(!isset($CAD_STATUS)) require_once __DIR__ . '/../config.php';
// env.php, init.php는 config.php에서 이미 로드됨

// ERP DB 연동 (login.php 페이지와 동일 방식)
$POSITION_TO_LEVEL = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5];
$ADMIN_LIST = ['admin', '15051801', '21062401', '26031601'];

function getLoginDB(){
    try {
        return cadGetDB();
    } catch(Exception $e){ return null; }
}

function verifyCadTokenV2(string $token): ?array
{
    $trimmed = trim($token);
    if ($trimmed === '') {
        return null;
    }

    try {
        $auth = new AuthService();
        $result = $auth->verifyCadToken($trimmed);
        if (!is_array($result) || !($result['ok'] ?? false)) {
            return null;
        }
        return is_array($result['user'] ?? null) ? $result['user'] : null;
    } catch (Throwable) {
        return null;
    }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch($action){

    // 일반 로그인 (ID/PW) — ERP DB 조회 + V2 보안(RateLimit, PasswordService)
    case 'login':
        $id = trim($_POST['id'] ?? '');
        $pw = trim($_POST['pw'] ?? '');
        if(!$id || !$pw){
            echo json_encode(['ok'=>false, 'msg'=>'아이디와 비밀번호를 입력하세요']);
            break;
        }
        // Rate Limit 체크
        $rlId = cadRateLimitId($id);
        $rlResult = cadRateLimiter()->check($rlId);
        if(!$rlResult['allowed']){
            echo json_encode(['ok'=>false, 'msg'=>'로그인 시도 횟수 초과. '.$rlResult['retry_after'].'초 후 다시 시도하세요']);
            break;
        }
        $pdo = getLoginDB();
        if(!$pdo){
            echo json_encode(['ok'=>false, 'msg'=>'DB 연결 실패']);
            break;
        }
        $stmt = $pdo->prepare("SELECT u.*, e.name as emp_name, e.part_position, e.member_photo FROM Tb_Users u LEFT JOIN Tb_Employee e ON u.employee_idx=e.idx WHERE u.id=?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$user || !cadPasswordService()->verifyAndMigrate($user, $pw)){
            cadRateLimiter()->fail($rlId);
            echo json_encode(['ok'=>false, 'msg'=>'아이디 또는 비밀번호가 올바르지 않습니다']);
            break;
        }
        cadRateLimiter()->success($rlId);
        $level = in_array($id, $ADMIN_LIST) ? 5 : ($POSITION_TO_LEVEL[intval($user['part_position']??0)] ?? 1);
        $_SESSION['cad_user'] = [
            'id'    => $user['id'],
            'name'  => $user['emp_name'] ?? $user['name'],
            'level' => $level,
            'authority_idx' => $user['authority_idx'] ?? 0,
            'employee_idx' => $user['employee_idx'] ?? 0,
            'photo' => !empty($user['member_photo']) ? 'https://img.shv.kr/employee/'.$user['member_photo'] : '',
            'login_time' => date('Y-m-d H:i:s'),
            'method' => 'direct',
        ];
        echo json_encode(['ok'=>true, 'user'=>$_SESSION['cad_user']]);
        break;

    // ERP 자동 로그인 (토큰 방식) — V2 CAD 원타임 토큰 검증
    case 'erp_auth':
        $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
        if(trim($token) === ''){
            echo json_encode(['ok'=>false, 'msg'=>'토큰 없음']);
            break;
        }

        $verifiedUser = verifyCadTokenV2($token);
        if(!is_array($verifiedUser)){
            echo json_encode(['ok'=>false, 'msg'=>'유효하지 않은 토큰']);
            break;
        }

        $userPk = (int)($verifiedUser['user_pk'] ?? 0);
        if($userPk <= 0){
            echo json_encode(['ok'=>false, 'msg'=>'유효하지 않은 사용자']);
            break;
        }

        $pdo = getLoginDB();
        if(!$pdo){
            echo json_encode(['ok'=>false, 'msg'=>'DB 연결 실패']);
            break;
        }

        $stmt = $pdo->prepare("SELECT u.*, e.name as emp_name, e.part_position, e.member_photo FROM Tb_Users u LEFT JOIN Tb_Employee e ON u.employee_idx=e.idx WHERE u.idx=?");
        $stmt->execute([$userPk]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$user){
            echo json_encode(['ok'=>false, 'msg'=>'사용자 조회 실패']);
            break;
        }

        $loginId = (string)($user['id'] ?? $user['login_id'] ?? '');
        if ($loginId === '') {
            $loginId = 'user_' . $userPk;
        }
        $level = in_array($loginId, $ADMIN_LIST, true) ? 5 : ($POSITION_TO_LEVEL[intval($user['part_position']??0)] ?? 1);

        $_SESSION['cad_user'] = [
            'id'    => $loginId,
            'name'  => $user['emp_name'] ?? $user['name'],
            'level' => $level,
            'authority_idx' => $user['authority_idx'] ?? 0,
            'employee_idx' => $user['employee_idx'] ?? 0,
            'photo' => !empty($user['member_photo']) ? 'https://img.shv.kr/employee/'.$user['member_photo'] : '',
            'login_time' => date('Y-m-d H:i:s'),
            'method' => 'erp_token_v2',
            'service_code' => (string)($verifiedUser['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($verifiedUser['tenant_id'] ?? 0),
        ];
        echo json_encode(['ok'=>true, 'user'=>$_SESSION['cad_user']]);
        break;

    // 세션 체크
    case 'check':
        if(isset($_SESSION['cad_user'])){
            echo json_encode(['ok'=>true, 'user'=>$_SESSION['cad_user']]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'로그인 필요']);
        }
        break;

    // 로그아웃
    case 'logout':
        unset($_SESSION['cad_user']);
        session_destroy();
        header('Location: ../login.php');
        exit;

    default:
        echo json_encode(['ok'=>false, 'msg'=>'잘못된 요청']);
}
