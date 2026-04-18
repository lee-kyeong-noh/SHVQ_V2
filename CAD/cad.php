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

// 로그인 체크: ERP 세션 or 토큰 or CAD 세션
// 1. ERP 세션이 있으면 자동 로그인 (데모 세션 덮어쓰기 포함)
$isCurrentDemo = isset($_SESSION['cad_user']) && ($_SESSION['cad_user']['user_type'] ?? '') === 'demo';
if((!isset($_SESSION['cad_user']) || $isCurrentDemo) && isset($_SESSION['shv_user'])){
    $eu = $_SESSION['shv_user'];
    $POSITION_TO_LEVEL = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5];
    $ADMIN_LIST = ['admin', '15051801', '21062401', '26031601'];
    $sessionLoginId = (string)($eu['id'] ?? $eu['login_id'] ?? '');
    if ($sessionLoginId === '') {
        $sessionLoginId = 'user_' . (int)($eu['idx'] ?? 0);
    }
    $sessionName = (string)($eu['name'] ?? '');
    if ($sessionName === '') {
        $sessionName = $sessionLoginId;
    }
    $legacyLevel = (int)($eu['user_level'] ?? 0);
    $mappedLevel = $legacyLevel > 0
        ? max(1, min(5, $legacyLevel))
        : ($POSITION_TO_LEVEL[intval($eu['part_position']??0)] ?? 1);

    $_SESSION['cad_user'] = [
        'id' => $sessionLoginId,
        'name' => $sessionName,
        'level' => in_array($sessionLoginId, $ADMIN_LIST, true) ? 5 : $mappedLevel,
        'authority_idx' => $eu['authority_idx'] ?? $legacyLevel,
        'employee_idx' => $eu['employee_idx'] ?? $eu['idx'] ?? 0,
        'user_type' => $eu['user_type'] ?? '',
        'photo' => $eu['photo'] ?? '',
        'login_time' => date('Y-m-d H:i:s'),
        'method' => 'erp_session',
    ];
}
// 2. 데모 토큰 로그인 (폼 제출 후 세션 생성)
if(isset($_GET['demo']) && !isset($_SESSION['cad_user'])){
    $demoKey = $_GET['demo'];
    $tokenFile = __DIR__ . '/cad_saves/demo_tokens.json';
    $demoTokens = file_exists($tokenFile) ? json_decode(file_get_contents($tokenFile), true) : [];
    if(is_array($demoTokens) && isset($demoTokens[$demoKey])){
        $dt = $demoTokens[$demoKey];
        $expired = !empty($dt['expires']) && date('Y-m-d') > $dt['expires'];
        if(!$expired){
            // POST로 로그인 폼 제출된 경우 → 세션 생성
            if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['demo_login']??'')==='1'){
                $_SESSION['cad_user'] = [
                    'id' => 'demo_'.substr(md5($demoKey),0,6),
                    'name' => $dt['name'] ?? 'Demo User',
                    'level' => intval($dt['level'] ?? 2),
                    'authority_idx' => 0,
                    'employee_idx' => 0,
                    'user_type' => 'demo',
                    'photo' => '',
                    'login_time' => date('Y-m-d H:i:s'),
                    'method' => 'demo_token',
                ];
                header('Location: cad.php?demo='.$demoKey);
                exit;
            }
            // GET → 데모 로그인 폼 표시
            ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SHV SmartCAD - Demo Login</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0b1120;display:flex;align-items:center;justify-content:center;height:100vh;font-family:'Noto Sans KR',sans-serif;}
.login-box{background:#0d1828;border:1px solid #1b3354;border-radius:12px;padding:40px 36px;width:380px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,0.7);}
.login-logo{text-align:center;margin-bottom:28px;}
.login-logo h1{font-size:28px;font-weight:700;color:#00aaff;letter-spacing:2px;}
.login-logo p{color:#3a5a7a;font-size:11px;margin-top:6px;}
.login-field{margin-bottom:16px}
.login-field label{display:block;color:#8ab4d4;font-size:12px;margin-bottom:5px;}
.login-field input{width:100%;padding:10px 14px;background:#111e30;border:1px solid #1b3354;border-radius:6px;color:#d0e8ff;font-size:14px;outline:none;transition:border-color 0.2s;}
.login-field input:focus{border-color:#00aaff}
.login-btn{width:100%;padding:12px;margin-top:8px;background:#0077cc;border:none;border-radius:6px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.2s;letter-spacing:1px;}
.login-btn:hover{background:#00aaff}
.login-footer{text-align:center;margin-top:24px;color:#3a5a7a;font-size:10px;}
.demo-badge{display:inline-block;background:#00aaff;color:#fff;padding:2px 10px;border-radius:10px;font-size:10px;font-weight:600;margin-top:8px;}
</style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <h1>SHV SmartCAD</h1>
        <p>Cloud-based Smart CAD System</p>
        <p class="login-contact">사용문의 : (주)에스에이치비젼 No1@shv.kr</p>
    </div>
    <form method="POST" action="cad.php?demo=<?php echo htmlspecialchars($demoKey); ?>">
        <input type="hidden" name="demo_login" value="1">
        <div class="login-field">
            <label>아이디</label>
            <input type="text" name="id" value="guest" readonly class="demo-readonly">
        </div>
        <div class="login-field">
            <label>비밀번호</label>
            <input type="text" name="pw" value="12345678" readonly class="demo-readonly">
        </div>
        <button type="submit" class="login-btn">로그인</button>
    </form>
    <div class="login-footer">
        &copy; 2026 SH Vision. All rights reserved.
    </div>
</div>
</body>
</html>
            <?php
            exit;
        }
    }
}
// 2-1. ERP 토큰 자동 로그인
if(isset($_GET['token']) && !isset($_SESSION['cad_user'])){
    $_GET['action'] = 'erp_auth';
    $_POST['token'] = $_GET['token'];
    ob_start();
    include __DIR__ . '/dist/login.php';
    ob_end_clean();
}
// 3. 세션 없으면 로그인 페이지
if(!isset($_SESSION['cad_user'])){
    header('Location: login.php');
    exit;
}
$CAD_CURRENT_USER = $_SESSION['cad_user'];
$isDemo = ($CAD_CURRENT_USER['user_type'] ?? '') === 'demo';

function cadJsonExit($payload, $statusCode = 200){
    ApiResponse::fromLegacy($payload, $statusCode, $statusCode);
    exit;
}

function cadUserLevel(){
    global $CAD_CURRENT_USER;
    return intval($CAD_CURRENT_USER['level'] ?? 0);
}

function cadRequireLevel($minLevel, $msg = '권한이 없습니다'){
    if(cadUserLevel() < $minLevel){
        cadJsonExit(['ok'=>false,'msg'=>$msg], 403);
    }
}

function cadSanitizeSiteNo($value){
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', strval($value ?? ''));
}

function cadSanitizeId($value){
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', strval($value ?? ''));
}

function cadEnsureDir($dir){
    if(!is_dir($dir) && !mkdir($dir, 0755, true)){
        cadJsonExit(['ok'=>false,'msg'=>'저장 폴더 생성 실패'], 500);
    }
}

function cadReadJsonBody($maxBytes = 20971520){
    $raw = file_get_contents('php://input');
    if($raw === false){
        cadJsonExit(['ok'=>false,'msg'=>'요청 읽기 실패'], 400);
    }
    if(strlen($raw) > $maxBytes){
        cadJsonExit(['ok'=>false,'msg'=>'요청 크기 초과'], 413);
    }
    $data = json_decode($raw, true);
    if(!is_array($data)){
        cadJsonExit(['ok'=>false,'msg'=>'잘못된 요청 형식'], 400);
    }
    return $data;
}

define('SAVE_DIR', __DIR__ . '/cad_saves/');
if(!is_dir(SAVE_DIR)) mkdir(SAVE_DIR, 0755, true);

if(isset($_GET['api'])){
    header('Content-Type: application/json; charset=utf-8');
    $api=$_GET['api'];
    // CSRF 검증 — 상태 변경 API에만 적용
    $csrfRequired = ['save','delete','export','demo_token_save','demo_token_delete','xlsx'];
    if(in_array($api, $csrfRequired, true)){
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
        if(!$csrfToken || !cadCsrfService()->validateFromRequest($csrfToken)){
            cadJsonExit(['ok'=>false,'msg'=>'CSRF 토큰이 유효하지 않습니다'], 403);
        }
    }
    // 현장번호별 폴더 결정 (site_no가 있으면 cad_saves/현장번호/, 없으면 cad_saves/)
    $siteNo = cadSanitizeSiteNo($_GET['site_no'] ?? '');
    $siteDir = $siteNo ? SAVE_DIR.$siteNo.'/' : SAVE_DIR;
    if($siteNo) cadEnsureDir($siteDir);

    switch($api){
        case 'list':
            $f=glob($siteDir.'*.json')?:[];$l=[];
            foreach($f as $x){$d=json_decode(file_get_contents($x),true);$l[]=['id'=>$d['id']??basename($x,'.json'),'title'=>$d['title']??'untitled','updated_at'=>date('Y-m-d H:i',filemtime($x)),'thumbnail'=>$d['thumbnail']??'','site_no'=>$siteNo];}
            usort($l,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
            echo json_encode(['ok'=>true,'data'=>$l,'site_no'=>$siteNo]);break;
        case 'save':
            cadRequireLevel(2, '저장 권한이 없습니다');
            $b = cadReadJsonBody();
            $reqSite = cadSanitizeSiteNo($b['site_no'] ?? $siteNo);
            $saveDir = $reqSite ? SAVE_DIR.$reqSite.'/' : SAVE_DIR;
            if($reqSite) cadEnsureDir($saveDir);
            $id = cadSanitizeId($b['id'] ?? '') ?: ('dwg_'.uniqid('', true));
            $title = trim(strval($b['title'] ?? 'untitled'));
            if($title === '') $title = 'untitled';
            if(function_exists('mb_substr')) $title = mb_substr($title, 0, 120, 'UTF-8');
            else $title = substr($title, 0, 120);
            $drawing = is_array($b['drawing'] ?? null) ? $b['drawing'] : [];
            $thumbnail = strval($b['thumbnail'] ?? '');
            if(strlen($thumbnail) > 8 * 1024 * 1024){
                cadJsonExit(['ok'=>false,'msg'=>'썸네일 크기 초과'], 413);
            }
            $payload = json_encode([
                'id'=>$id,
                'title'=>$title,
                'drawing'=>$drawing,
                'thumbnail'=>$thumbnail,
                'site_no'=>$reqSite
            ], JSON_UNESCAPED_UNICODE);
            if($payload === false){
                cadJsonExit(['ok'=>false,'msg'=>'저장 데이터 인코딩 실패'], 400);
            }
            if(file_put_contents($saveDir.$id.'.json', $payload) === false){
                cadJsonExit(['ok'=>false,'msg'=>'도면 저장 실패'], 500);
            }
            echo json_encode(['ok'=>true,'id'=>$id,'site_no'=>$reqSite]);break;
        case 'export':
            cadRequireLevel(2, '내보내기 권한이 없습니다');
            $b = cadReadJsonBody();
            $id = cadSanitizeId($b['id'] ?? '');
            $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', strval($b['ext'] ?? '')));
            $content = $b['content'] ?? '';
            $reqSite = cadSanitizeSiteNo($b['site_no'] ?? $siteNo);
            $allowedExt = ['dxf','svg','png','json'];
            if(!in_array($ext, $allowedExt, true)){
                cadJsonExit(['ok'=>false,'msg'=>'허용되지 않는 저장 형식'], 400);
            }
            if(!$id||!$ext||!$content){ echo json_encode(['ok'=>false,'msg'=>'missing params']); break; }
            $dir = $reqSite ? SAVE_DIR.$reqSite.'/'.$ext.'/' : SAVE_DIR.$ext.'/';
            cadEnsureDir($dir);
            if(strpos($content,'base64,')!==false){
                $data = base64_decode(explode('base64,',$content)[1], true);
                if($data === false){
                    cadJsonExit(['ok'=>false,'msg'=>'base64 디코딩 실패'], 400);
                }
            } else {
                $data = strval($content);
            }
            if(strlen($data) > 20 * 1024 * 1024){
                cadJsonExit(['ok'=>false,'msg'=>'내보내기 데이터 크기 초과'], 413);
            }
            if(file_put_contents($dir.$id.'.'.$ext, $data) === false){
                cadJsonExit(['ok'=>false,'msg'=>'파일 저장 실패'], 500);
            }
            echo json_encode([
                'ok'=>true,
                'id'=>$id,
                'ext'=>$ext,
                'file'=>$id.'.'.$ext,
                'site_no'=>$reqSite
            ]);
            break;
        case 'load':
            $id = cadSanitizeId($_GET['id'] ?? '');
            $f=$siteDir.$id.'.json';
            // 현장 폴더에 없으면 루트에서도 찾기
            if(!file_exists($f) && $siteNo) $f=SAVE_DIR.$id.'.json';
            echo($id&&file_exists($f))?file_get_contents($f):json_encode(['ok'=>false]);break;
        case 'delete':
            cadRequireLevel(2, '삭제 권한이 없습니다');
            $id = cadSanitizeId($_GET['id'] ?? '');
            if(!$id){
                cadJsonExit(['ok'=>false,'msg'=>'id 필요'], 400);
            }
            if($id && file_exists($siteDir.$id.'.json') && !unlink($siteDir.$id.'.json')){
                cadJsonExit(['ok'=>false,'msg'=>'도면 삭제 실패'], 500);
            }
            echo json_encode(['ok'=>true]);break;

        // ── 데모토큰 관리 API ──
        case 'demo_tokens':
            $tokenFile = __DIR__ . '/cad_saves/demo_tokens.json';
            $tokens = file_exists($tokenFile) ? json_decode(file_get_contents($tokenFile), true) : [];
            if(!is_array($tokens)) $tokens = [];
            echo json_encode(['ok'=>true, 'data'=>array_values($tokens)]);
            break;
        case 'demo_token_save':
            if($CAD_CURRENT_USER['level'] < 4){ echo json_encode(['ok'=>false,'msg'=>'관리자 권한 필요']); break; }
            $b = json_decode(file_get_contents('php://input'), true);
            $tokenFile = __DIR__ . '/cad_saves/demo_tokens.json';
            $tokens = file_exists($tokenFile) ? json_decode(file_get_contents($tokenFile), true) : [];
            if(!is_array($tokens)) $tokens = [];
            $token = trim($b['token'] ?? '');
            $name = trim($b['name'] ?? 'Demo User');
            $level = intval($b['level'] ?? 2);
            $expires = trim($b['expires'] ?? '');
            if(!$token){ echo json_encode(['ok'=>false,'msg'=>'토큰값 필수']); break; }
            // 토큰 자동생성
            if($token === '__AUTO__') $token = 'DEMO-' . strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $tokens[$token] = ['token'=>$token, 'name'=>$name, 'level'=>$level, 'expires'=>$expires, 'created'=>date('Y-m-d H:i:s'), 'created_by'=>$CAD_CURRENT_USER['name']];
            file_put_contents($tokenFile, json_encode($tokens, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            $url = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/cad.php?demo='.$token;
            echo json_encode(['ok'=>true, 'token'=>$token, 'url'=>$url]);
            break;
        case 'demo_token_delete':
            if($CAD_CURRENT_USER['level'] < 4){ echo json_encode(['ok'=>false,'msg'=>'관리자 권한 필요']); break; }
            $b = json_decode(file_get_contents('php://input'), true);
            $tokenFile = __DIR__ . '/cad_saves/demo_tokens.json';
            $tokens = file_exists($tokenFile) ? json_decode(file_get_contents($tokenFile), true) : [];
            $delToken = trim($b['token'] ?? '');
            if($delToken && isset($tokens[$delToken])) unset($tokens[$delToken]);
            file_put_contents($tokenFile, json_encode($tokens, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            echo json_encode(['ok'=>true]);
            break;

        case 'xlsx':
            cadRequireLevel(2, '엑셀 업로드 권한이 없습니다');
            if(empty($_FILES['file'])) { echo json_encode(['ok'=>false,'msg'=>'no file']); exit; }
            // 파일 크기 제한 (5MB)
            if($_FILES['file']['size'] > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'파일 크기 초과 (최대 5MB)']); exit; }
            $tmp = $_FILES['file']['tmp_name'];
            $name = strtolower($_FILES['file']['name']);
            // 허용 확장자 검증
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            if(!in_array($ext, ['xlsx','xls','csv','tsv'])) { echo json_encode(['ok'=>false,'msg'=>'허용되지 않는 파일 형식 (xlsx/xls/csv/tsv만 가능)']); exit; }
            try {
                if(substr($name,-4)==='.csv'||substr($name,-4)==='.tsv'){
                    $sep = substr($name,-4)==='.tsv' ? "\t" : ',';
                    $text = file_get_contents($tmp);
                    $rows = [];
                    foreach(explode("\n", trim($text)) as $line){
                        if(trim($line)==='') continue;
                        $rows[] = array_map('trim', str_getcsv($line, $sep));
                    }
                    echo json_encode(['ok'=>true,'rows'=>$rows]);
                } else {
                    if(!class_exists('ZipArchive') && !class_exists('COM')){
                        echo json_encode(['ok'=>false,'msg'=>'ZipArchive/COM 없음 - CSV로 저장 후 사용하세요']); exit;
                    }
                    require_once __DIR__.'/dist/CAD_xlsx_parser.php';
                    $xlsx = SimpleXLSX::parse($tmp);
                    if(!$xlsx){
                        $info = class_exists('ZipArchive') ? 'ZipArchive:OK' : 'ZipArchive:없음';
                        $info .= class_exists('COM') ? ', COM:OK' : ', COM:없음';
                        echo json_encode(['ok'=>false,'msg'=>'파싱실패('.$info.')']); exit;
                    }
                    $rows = $xlsx->rows();
                    echo json_encode(['ok'=>true,'rows'=>$rows]);
                }
            } catch(Exception $e){
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            break;
    }
    exit;
}

$f=glob(SAVE_DIR.'*.json')?:[];$l=[];
foreach($f as $x){$d=json_decode(file_get_contents($x),true);$l[]=['id'=>$d['id']??basename($x,'.json'),'title'=>$d['title']??'untitled','updated_at'=>date('Y-m-d H:i',filemtime($x)),'thumbnail'=>$d['thumbnail']??''];}
usort($l,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
$jl=json_encode($l,JSON_UNESCAPED_UNICODE);
$ps=htmlspecialchars(basename($_SERVER["PHP_SELF"]));
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>SHV WebCAD</title>
    <!-- CSS -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
</head>
<body>
<script>window.CAD_IS_DEMO = <?php echo $isDemo ? 'true' : 'false'; ?>;</script>

<div id="ppOverlay"><div id="ppBox"><div class="ppHead"><h2>SHV WebCAD</h2><div class="ppBtns"><button class="ppBtn" id="btnPPNew">+ New</button><button class="ppBtnSec" id="btnPPClose">Close</button></div></div><div id="ppList"><div id="ppEmpty">No saved drawings.</div></div></div></div>
<div id="sdOverlay"><div id="sdBox"><h3>Save Drawing</h3><input type="text" id="sdTitleInp" placeholder="Name..." value="untitled"><div class="sdBtns"><button class="sdCancel" id="btnSdCancel">Cancel</button><button class="sdOk" id="btnSdOk">Save</button></div></div></div>


<!-- TOP BAR (단독 접속 시만) -->
<?php $fromPortal = ($_GET['from'] ?? '') === 'portal'; ?>
<?php if(!$fromPortal): ?>
<div id="topbar">
    <div class="topMenu">
        <span class="logo" style="cursor:default"><span class="logo-shv">SHV</span> <span class="logo-smart">Smart</span><span class="logo-cad">CAD</span></span>
    </div>
    <div class="spacer"></div>
    <div id="siteInfo">
        <?php if($isDemo): ?>
        <span>현장번호 : <b id="siteNo" class="siteNo-conn">TEST-001</b></span>
        <span>현장명 : <b id="siteName">테스트 현장</b></span>
        <?php else: ?>
        <span>현장번호 : <b id="siteNo" class="siteNo-none">미연결</b></span>
        <span>현장명 : <b id="siteName">-</b></span>
        <button data-fn="openModal" data-arg="siteConnModal" class="cad-btn-site">현장연결</button>
        <?php endif; ?>
    </div>
    <div class="spacer"></div>
    <button class="topBtn" id="btnDarkMode" data-fn="toggleDarkMode" title="다크/라이트 모드">🌙</button>
    <button class="topBtn" data-fn="undo" title="실행취소">◀</button>
    <button class="topBtn" data-fn="redo" title="다시실행">▶</button>
    <select class="scaleSelect" id="scaleSelect" data-change="setScale" title="축척">
        <option value="1" selected>1:1</option><option value="50">1:50</option><option value="100">1:100</option><option value="200">1:200</option><option value="500">1:500</option>
    </select>
    <select class="unitSelect" id="unitSelect" data-change="setUnit" title="단위">
        <option value="mm">mm</option><option value="cm">cm</option><option value="m">m</option>
    </select>
    <?php
    $photoUrl = !empty($CAD_CURRENT_USER['photo']) ? $CAD_CURRENT_USER['photo'] : "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='8' r='4' fill='%235588aa'/%3E%3Cpath d='M4 20c0-4 3.6-7 8-7s8 3 8 7' fill='%235588aa'/%3E%3C/svg%3E";
    ?>
    <img id="userPhoto" src="<?php echo htmlspecialchars($photoUrl); ?>" class="cad-user-photo" title="<?php echo htmlspecialchars($CAD_CURRENT_USER['name']); ?>">
    <span class="cad-user-name"><?php echo htmlspecialchars($CAD_CURRENT_USER['name']); ?></span>
    <button onclick="shvConfirm('로그아웃','로그아웃 하시겠습니까?',function(){location.href='dist/login.php?action=logout'})" class="cad-btn-logout">로그아웃</button>
</div>
<?php else: ?>
<!-- 포탈 모드: topbar 숨김, 현장연결용 요소 + 리본 위 현장연결 바 -->
<div id="portalSiteBar">
    <?php if($isDemo): ?>
    <span>현장번호 : <b id="siteNo" class="siteNo-conn">TEST-001</b></span>
    <span>현장명 : <b id="siteName">테스트 현장</b></span>
    <?php else: ?>
    <span>현장번호 : <b id="siteNo" class="siteNo-none">미연결</b></span>
    <span>현장명 : <b id="siteName">-</b></span>
    <button data-fn="openModal" data-arg="siteConnModal" class="cad-btn-site">현장연결</button>
    <?php endif; ?>
    <div class="cad-fill"></div>
    <select class="scaleSelect portal-select" id="scaleSelect" data-change="setScale" title="축척">
        <option value="1" selected>1:1</option><option value="50">1:50</option><option value="100">1:100</option><option value="200">1:200</option><option value="500">1:500</option>
    </select>
    <select class="unitSelect portal-select" id="unitSelect" data-change="setUnit" title="단위">
        <option value="mm">mm</option><option value="cm">cm</option><option value="m">m</option>
    </select>
</div>
<?php endif; ?>

<!-- RIBBON TABS -->
<div id="ribbonTabs">
    <button class="ribTabBtn active" data-ribtab="ribHome">홈</button>
    <button class="ribTabBtn" data-ribtab="ribFile">파일</button>
    <button class="ribTabBtn" data-ribtab="ribEdit">편집</button>
    <button class="ribTabBtn" data-ribtab="ribInsert">삽입</button>
    <button class="ribTabBtn" data-ribtab="ribAnnot">주석</button>
    <button class="ribTabBtn" data-ribtab="ribView">보기</button>
    <button class="ribTabBtn" data-ribtab="ribPrint">출력</button>
    <button class="ribTabBtn" data-ribtab="ribTool">도구</button>
    <button class="ribTabBtn" data-ribtab="ribSetting">설정</button>
    <button class="ribTabBtn" data-ribtab="ribHelp">도움말</button>
    <button class="ribTabBtn" data-ribtab="ribDrawing">도면관리</button>
</div>

<!-- TOOL RIBBON -->
<div id="toolRibbon">
    <!-- 홈 탭 -->
    <div class="ribTabContent active" id="ribHome">
        <div class="ribGroup">
            <div class="ribLabel">선택/편집</div>
            <div class="ribRow">
                <button class="ribBtn active" id="rb_select" data-fn="setTool" data-arg="select" title="선택 [S]"><span class="ribIco">↖</span><span class="ribTxt">선택</span></button>
                <button class="ribBtn" id="rb_move" data-fn="setTool" data-arg="move" title="이동 [M]"><span class="ribIco">✥</span><span class="ribTxt">이동</span></button>
                <button class="ribBtn" id="rb_copyMove" data-fn="setTool" data-arg="copyMove" title="복사이동 [CO]"><span class="ribIco">⧉</span><span class="ribTxt">복사이동</span></button>
                <button class="ribBtn" data-fn="groupToBlock" title="그룹 (블록) [Ctrl+G]"><span class="ribIco">⊞</span><span class="ribTxt">그룹</span></button>
                <button class="ribBtn" data-fn="explodeBlock" title="그룹 해제 [Ctrl+Shift+G]"><span class="ribIco">⊟</span><span class="ribTxt">해제</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">그리기</div>
            <div class="ribRow">
                <button class="ribBtn" id="rb_line" data-fn="setTool" data-arg="line" title="선 [L]"><span class="ribIco">╱</span><span class="ribTxt">선</span></button>
                <button class="ribBtn" id="rb_wall" data-fn="setTool" data-arg="wall" title="벽체 [W]"><span class="ribIco">▐</span><span class="ribTxt">벽체</span></button>
                <button class="ribBtn" id="rb_rect" data-fn="setTool" data-arg="rect" title="사각형 [R]"><span class="ribIco">▭</span><span class="ribTxt">사각형</span></button>
                <button class="ribBtn" id="rb_circle" data-fn="setTool" data-arg="circle" title="원 [C]"><span class="ribIco">○</span><span class="ribTxt">원</span></button>
                <button class="ribBtn" id="rb_polyline" data-fn="setTool" data-arg="polyline" title="폴리선 [P]"><span class="ribIco">⌒</span><span class="ribTxt">폴리선</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">수정</div>
            <div class="ribRow">
                <button class="ribBtn" id="rb_offset" data-fn="setTool" data-arg="offset" title="오프셋 [O]"><span class="ribIco">⊞</span><span class="ribTxt">오프셋</span></button>
                <button class="ribBtn" id="rb_trim" data-fn="setTool" data-arg="trim" title="트림 [TR]"><span class="ribIco">✂</span><span class="ribTxt">트림</span></button>
                <button class="ribBtn" id="rb_extend" data-fn="setTool" data-arg="extend" title="연장 [EX]"><span class="ribIco">↗</span><span class="ribTxt">연장</span></button>
                <button class="ribBtn" id="rb_dim" data-fn="setTool" data-arg="dim" title="치수 [D]"><span class="ribIco">↔</span><span class="ribTxt">치수</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">주석</div>
            <div class="ribRow">
                <button class="ribBtn" id="rb_text" data-fn="setTool" data-arg="text" title="텍스트 [T]"><span class="ribIco">T</span><span class="ribTxt">텍스트</span></button>
                <button class="ribBtn" id="rb_annot" data-fn="setTool" data-arg="annot" title="주석"><span class="ribIco">💬</span><span class="ribTxt">주석</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">편집</div>
            <div class="ribRow">
                <button class="ribBtn2" data-fn="undo" title="실행취소 [Ctrl+Z]"><span class="ribIco">↩</span><span class="ribTxt">취소</span></button>
                <button class="ribBtn2" data-fn="redo" title="다시실행 [Ctrl+Y]"><span class="ribIco">↪</span><span class="ribTxt">다시실행</span></button>
                <button class="ribBtn2" data-fn="copySelected" title="복사 [Ctrl+C]"><span class="ribIco">⎘</span><span class="ribTxt">복사</span></button>
                <button class="ribBtn2" data-fn="deleteSelected" title="삭제 [Del]"><span class="ribIco" style="color:var(--danger)">🗑</span><span class="ribTxt">삭제</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">화면</div>
            <div class="ribRow">
                <button class="ribBtn2" data-fn="refreshView" title="리프레시 [RE]"><span class="ribIco">🔄</span><span class="ribTxt">RE</span></button>
            </div>
        </div>
    </div>

    <!-- 삽입 탭 -->
    <div class="ribTabContent" id="ribInsert">
        <div class="ribGroup">
            <div class="ribLabel">이미지</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="openImportBg" title="배경 이미지/PDF"><span class="ribIco">🖼</span><span class="ribTxt">배경</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">데이터</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="xlsxClick" title="Excel 표 삽입"><span class="ribIco">📊</span><span class="ribTxt">Excel 표</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">주석</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="setTool" data-arg="text" title="텍스트 [T]"><span class="ribIco">T</span><span class="ribTxt">텍스트</span></button>
                <button class="ribBtn" data-fn="setTool" data-arg="annot" title="주석"><span class="ribIco">💬</span><span class="ribTxt">주석</span></button>
                <button class="ribBtn" data-fn="setTool" data-arg="dim" title="치수 [D]"><span class="ribIco">↔</span><span class="ribTxt">치수</span></button>
            </div>
        </div>
    </div>

    <!-- 주석 탭 -->
    <div class="ribTabContent" id="ribAnnot">
        <div class="ribGroup">
            <div class="ribLabel">텍스트</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="setTool" data-arg="text" title="텍스트 [T]"><span class="ribIco">T</span><span class="ribTxt">텍스트</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">말풍선</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="setTool" data-arg="annot" title="주석"><span class="ribIco">💬</span><span class="ribTxt">주석</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">치수</div>
            <div class="ribRow">
                <button class="ribBtn" data-fn="setTool" data-arg="dim" title="치수 [D]"><span class="ribIco">↔</span><span class="ribTxt">치수</span></button>
            </div>
        </div>
    </div>

    <!-- 보기 탭 -->
    <div class="ribTabContent" id="ribView">
        <div class="ribGroup">
            <div class="ribLabel">줌</div>
            <div class="ribRow">
                <button class="ribBtn2" data-fn="fitAll" title="전체 맞춤"><span class="ribIco">⊡</span><span class="ribTxt">전체</span></button>
                <button class="ribBtn2" data-fn="zoomIn" title="줌 인 [+]"><span class="ribIco">+</span><span class="ribTxt">확대</span></button>
                <button class="ribBtn2" data-fn="zoomOut" title="줌 아웃 [-]"><span class="ribIco">−</span><span class="ribTxt">축소</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">표시</div>
            <div class="ribRow">
                <button class="ribBtn2" data-fn="toggleGrid" title="격자 [G]"><span class="ribIco">⊞</span><span class="ribTxt">격자</span></button>
                <button class="ribBtn2" data-fn="toggleSnap" title="스냅 [F3]"><span class="ribIco">⊹</span><span class="ribTxt">스냅</span></button>
                <button class="ribBtn2" data-fn="toggleOrtho" title="직교 [F8]"><span class="ribIco">⊢</span><span class="ribTxt">직교</span></button>
            </div>
        </div>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">설정</div>
            <div class="ribRow">
                <button class="ribBtn2" data-fn="openModal" data-arg="settingsModal" title="설정"><span class="ribIco">⚙</span><span class="ribTxt">설정</span></button>
                <button class="ribBtn2" data-fn="openModal" data-arg="pointerModal" title="포인터"><span class="ribIco">🖱</span><span class="ribTxt">포인터</span></button>
            </div>
        </div>
    </div>
    <!-- 파일 -->
    <div class="ribTabContent" id="ribFile">
        <div class="ribGroup"><div class="ribLabel">새로 만들기</div><div class="ribRow">
            <button class="ribBtn" data-fn="newFile" title="새 도면 [Ctrl+N]"><span class="ribIco">📄</span><span class="ribTxt">새 도면</span></button>
        </div></div><div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">열기</div><div class="ribRow">
            <button class="ribBtn2" onclick="var i=document.getElementById('dwgFileInput');i.value='';i.click();" title="DWG/DXF 열기 [Ctrl+O]"><span class="ribIco">📂</span><span class="ribTxt">열기(DWG)</span></button>
            <button class="ribBtn2" data-fn="openFile" title="서버 도면 열기"><span class="ribIco">☁</span><span class="ribTxt">서버도면</span></button>
            <button class="ribBtn2" data-fn="openImportBg" title="배경 이미지"><span class="ribIco">🖼</span><span class="ribTxt">배경이미지</span></button>
            <button class="ribBtn2" data-fn="xlsxClick" title="Excel 표"><span class="ribIco">📊</span><span class="ribTxt">Excel표</span></button>
        </div></div><div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">저장/내보내기</div><div class="ribRow">
            <button class="ribBtn2" data-fn="saveJSON" title="서버 저장 [Ctrl+S]"><span class="ribIco">💾</span><span class="ribTxt">저장</span></button>
            <button class="ribBtn2" onclick="if(typeof saveDWG==='function')saveDWG();" title="DWG 내보내기"><span class="ribIco">📦</span><span class="ribTxt">DWG</span></button>
            <button class="ribBtn2" data-fn="saveDXF" title="DXF 내보내기"><span class="ribIco">📐</span><span class="ribTxt">DXF</span></button>
            <button class="ribBtn2" data-fn="saveSVG" title="SVG 내보내기"><span class="ribIco">🎨</span><span class="ribTxt">SVG</span></button>
            <button class="ribBtn2" data-fn="savePNG" title="PNG 내보내기"><span class="ribIco">📷</span><span class="ribTxt">PNG</span></button>
        </div></div>
    </div>
    <!-- 편집 -->
    <div class="ribTabContent" id="ribEdit">
        <div class="ribGroup"><div class="ribLabel">실행</div><div class="ribRow">
            <button class="ribBtn2" data-fn="undo" title="실행취소 [Ctrl+Z]"><span class="ribIco">↩</span><span class="ribTxt">취소</span></button>
            <button class="ribBtn2" data-fn="redo" title="다시실행 [Ctrl+Y]"><span class="ribIco">↪</span><span class="ribTxt">다시</span></button>
        </div></div><div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">클립보드</div><div class="ribRow">
            <button class="ribBtn2" data-fn="copySelected" title="복사 [Ctrl+C]"><span class="ribIco">📋</span><span class="ribTxt">복사</span></button>
            <button class="ribBtn2" data-fn="pasteObjects" title="붙여넣기 [Ctrl+V]"><span class="ribIco">📌</span><span class="ribTxt">붙여넣기</span></button>
            <button class="ribBtn2" data-fn="deleteSelected" title="삭제 [Del]"><span class="ribIco">🗑</span><span class="ribTxt">삭제</span></button>
        </div></div><div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">순서</div><div class="ribRow">
            <button class="ribBtn2" data-fn="bringToFront" title="맨 앞으로"><span class="ribIco">⬆</span><span class="ribTxt">맨앞</span></button>
            <button class="ribBtn2" data-fn="sendToBack" title="맨 뒤로"><span class="ribIco">⬇</span><span class="ribTxt">맨뒤</span></button>
        </div></div><div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">선택</div><div class="ribRow">
            <button class="ribBtn2" data-fn="selectAll" title="전체 선택 [Ctrl+A]"><span class="ribIco">☑</span><span class="ribTxt">전체선택</span></button>
        </div></div>
    </div>
    <!-- 출력 -->
    <div class="ribTabContent" id="ribPrint">
        <div class="ribGroup"><div class="ribLabel">인쇄</div><div class="ribRow">
            <button class="ribBtn" data-fn="openPrintModal" data-arg="view" title="인쇄 [Ctrl+P]"><span class="ribIco">🖨</span><span class="ribTxt">인쇄</span></button>
        </div></div>
        <div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">PDF</div><div class="ribRow">
            <button class="ribBtn" data-fn="openPrintModal" data-arg="all" title="전체 도면 PDF"><span class="ribIco">📄</span><span class="ribTxt">전체</span></button>
            <button class="ribBtn" data-fn="openPrintModal" data-arg="view" title="현재 화면 PDF"><span class="ribIco">🖥</span><span class="ribTxt">화면</span></button>
            <button class="ribBtn" data-fn="openPrintModal" data-arg="select" title="영역 선택 PDF"><span class="ribIco">✂</span><span class="ribTxt">선택</span></button>
        </div></div>
        <div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">DWG</div><div class="ribRow">
            <button class="ribBtn" data-fn="saveDWG" title="DWG 파일 저장"><span class="ribIco">💾</span><span class="ribTxt">DWG 저장</span></button>
        </div></div>
    </div>
    <!-- 도구 -->
    <div class="ribTabContent" id="ribTool">
        <div class="ribGroup"><div class="ribLabel">도구</div><div class="ribRow">
            <button class="ribBtn" data-fn="openModal" data-arg="pointerModal" title="포인터 설정"><span class="ribIco">🖱</span><span class="ribTxt">포인터 설정</span></button>
        </div></div>
    </div>
    <!-- 설정 -->
    <div class="ribTabContent" id="ribSetting">
        <div class="ribGroup"><div class="ribLabel">설정</div><div class="ribRow">
            <button class="ribBtn" data-fn="openModal" data-arg="settingsModal" title="CAD 설정"><span class="ribIco">⚙</span><span class="ribTxt">CAD 설정</span></button>
            <button class="ribBtn" data-fn="openModal" data-arg="pointerModal" title="포인터 설정"><span class="ribIco">🖱</span><span class="ribTxt">포인터</span></button>
        </div></div>
        <?php if($CAD_CURRENT_USER['level'] >= 4): ?>
        <div class="ribSep"></div>
        <div class="ribGroup"><div class="ribLabel">관리자</div><div class="ribRow">
            <button class="ribBtn" data-fn="openModal" data-arg="demoTokenModal" title="데모 링크 관리"><span class="ribIco">🔗</span><span class="ribTxt">데모링크</span></button>
        </div></div>
        <?php endif; ?>
    </div>
    <!-- 도움말 -->
    <div class="ribTabContent" id="ribHelp">
        <div class="ribGroup"><div class="ribLabel">도움말</div><div class="ribRow">
            <button class="ribBtn" data-fn="openModal" data-arg="shortcutModal" title="단축키 [F1]"><span class="ribIco">⌨</span><span class="ribTxt">단축키</span></button>
            <button class="ribBtn" data-fn="openModal" data-arg="aboutModal" title="정보"><span class="ribIco">ℹ</span><span class="ribTxt">SHVCAD 정보</span></button>
        </div></div>
    </div>
    <div class="ribTabContent" id="ribDrawing">
        <div class="ribGroup">
            <div class="ribLabel">도면관리</div>
            <div class="ribRow">
                <?php if($fromPortal): ?>
                <button class="ribBtn2" onclick="goPortal('smartcad')" title="도면작업"><span class="ribIco">🖊</span><span class="ribTxt">도면작업</span></button>
                <button class="ribBtn2" onclick="window.open('/SHVQ_V2/CAD/cad.php?from=portal','shv_cad')" title="새창에서 열기"><span class="ribIco">🔗</span><span class="ribTxt">새창열기</span></button>
                <?php else: ?>
                <button class="ribBtn2" onclick="location.reload()" title="새로고침"><span class="ribIco">🔄</span><span class="ribTxt">새로고침</span></button>
                <button class="ribBtn2" onclick="window.open(location.href,'shv_cad2')" title="새창에서 열기"><span class="ribIco">🔗</span><span class="ribTxt">새창열기</span></button>
                <?php endif; ?>
            </div>
        </div>
        <?php if(!$isDemo): ?>
        <div class="ribSep"></div>
        <div class="ribGroup">
            <div class="ribLabel">이동</div>
            <div class="ribRow">
                <?php if($fromPortal): ?>
                <button class="ribBtn2" onclick="goPortal('site_new')" title="현장조회"><span class="ribIco">🏢</span><span class="ribTxt">현장조회</span></button>
                <button class="ribBtn2" onclick="goPortal('member_new')" title="고객조회"><span class="ribIco">📋</span><span class="ribTxt">고객조회</span></button>
                <?php else: ?>
                <button class="ribBtn2" onclick="goPortal('site_new')" title="현장조회"><span class="ribIco">🏢</span><span class="ribTxt">현장조회</span></button>
                <button class="ribBtn2" onclick="goPortal('member_new')" title="고객조회"><span class="ribIco">📋</span><span class="ribTxt">고객조회</span></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if(!$fromPortal): ?>
<!-- CAD SIDEBAR (기본 숨김, 토글 가능) -->
<style>
#cadSidebar { width:180px; background:var(--panel); border-right:1px solid var(--border); display:none; flex-direction:column; flex-shrink:0; overflow:hidden; }
#cadSidebar.open { display:flex; }
#cadSidebar .cs-tabs { display:flex; flex-wrap:wrap; gap:2px; padding:6px; border-bottom:1px solid var(--border); }
#cadSidebar .cs-tab { padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:10px; font-weight:600; cursor:pointer; background:var(--panel); color:var(--textD); white-space:nowrap; }
#cadSidebar .cs-tab.active { background:var(--accent2,#1a4a7a); color:#fff; border-color:var(--accent2,#1a4a7a); }
#cadSidebar .cs-tab:hover { background:var(--accent2,#1a4a7a); color:#fff; }
#cadSidebar .cs-body { flex:1; overflow-y:auto; }
#cadSidebar .cs-item { display:block; padding:8px 14px; font-size:11px; color:var(--textB); cursor:pointer; border-bottom:1px solid rgba(255,255,255,.05); text-decoration:none; }
#cadSidebar .cs-item:hover { background:rgba(255,255,255,.05); color:#fff; }
#cadSidebar .cs-bottom { border-top:1px solid var(--border); padding:6px; }
#cadSidebar .cs-bottom a { display:block; padding:6px 14px; font-size:10px; color:var(--textD); text-decoration:none; }
#cadSidebar .cs-bottom a:hover { color:#fff; }
</style>
<div id="cadSidebar">
    <div class="cs-tabs">
        <?php
        $cadMenus = [
            'file'=>['label'=>'파일','items'=>['새 도면'=>'newFile','열기'=>['도면 (.json)'=>'openFile','배경 이미지 (.png/.jpg)'=>'openImportBg','Excel 표 (.xlsx/.csv)'=>'xlsxClick'],'저장 (.json)'=>'saveJSON','내보내기 DXF'=>'saveDXF','내보내기 SVG'=>'saveSVG','내보내기 PNG'=>'savePNG']],
            'edit'=>['label'=>'편집','items'=>['실행취소'=>'undo','다시실행'=>'redo','복사'=>'copySelected','붙여넣기'=>'pasteObjects','삭제'=>'deleteSelected','맨 앞으로'=>'bringToFront','맨 뒤로'=>'sendToBack','전체 선택'=>'selectAll']],
            'view'=>['label'=>'보기','items'=>['전체 맞춤'=>'fitAll','줌 인'=>'zoomIn','줌 아웃'=>'zoomOut','격자'=>'toggleGrid','스냅'=>'toggleSnap','직교'=>'toggleOrtho']],
            'print'=>['label'=>'출력','items'=>['PDF 전체'=>"openPrintModal','all",'PDF 화면'=>"openPrintModal','view",'PDF 선택'=>"openPrintModal','select"]],
            'insert'=>['label'=>'삽입','items'=>['배경 이미지'=>'openImportBg','Excel 표'=>'xlsxClick']],
            'tool'=>['label'=>'도구','items'=>['포인터'=>"openModal','pointerModal"]],
            'setting'=>['label'=>'설정','items'=>['CAD 설정'=>"openModal','settingsModal",'포인터'=>"openModal','pointerModal"]],
            'help'=>['label'=>'도움말','items'=>['단축키'=>"openModal','shortcutModal",'정보'=>"openModal','aboutModal"]],
        ];
        $first = true;
        foreach($cadMenus as $mk=>$mv):
        ?>
        <button class="cs-tab<?php echo $first?' active':''; ?>" onclick="cadSideSwitch('<?php echo $mk; ?>',this)"><?php echo $mv['label']; ?></button>
        <?php $first=false; endforeach; ?>
    </div>
    <div class="cs-body">
        <?php foreach($cadMenus as $mk=>$mv): ?>
        <div class="cs-section" id="cs-<?php echo $mk; ?>"<?php if($mk!=='file') echo ' style="display:none"'; ?>>
            <?php foreach($mv['items'] as $il=>$ifn):
                if(is_array($ifn)): ?>
            <div class="cs-sub-label"><?php echo $il; ?></div>
                <?php foreach($ifn as $subLabel=>$subFn): ?>
            <a class="cs-item cs-sub-item" onclick="<?php
                if(strpos($subFn,"','")!==false){ $p=explode("','",$subFn); echo $p[0]."('".$p[1]."')"; }
                else echo $subFn.'()';
            ?>"><?php echo $subLabel; ?></a>
                <?php endforeach; ?>
            <?php else: ?>
            <a class="cs-item" onclick="<?php
                if(strpos($ifn,"','")!==false){ $p=explode("','",$ifn); echo $p[0]."('".$p[1]."')"; }
                else echo $ifn.'()';
            ?>"><?php echo $il; ?></a>
            <?php endif; endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="cs-bottom">
        <a href="/SHVQ_V2/?r=my_settings" target="_top"><i class="fa fa-sliders cs-bottom-icon"></i>개인설정</a>
        <a href="/SHVQ_V2/?r=settings" target="_top"><i class="fa fa-cog cs-bottom-icon"></i>관리자설정</a>
    </div>
</div>
<script>
function cadSideSwitch(key, btn){
    document.querySelectorAll('.cs-tab').forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.cs-section').forEach(function(s){ s.style.display='none'; });
    var sec = document.getElementById('cs-'+key);
    if(sec) sec.style.display='';
}
</script>
<?php endif; ?>

<!-- MAIN -->
<div id="main">

    <!-- LEFT TOOLBAR -->
    <div id="toolbar">
        <div class="toolGroup">
            <button class="toolBtn active" id="tb_select" data-fn="setTool" data-arg="select" title="">
                ↖<span class="tip">선택 [S/Esc]</span>
            </button>
        </div>
        <div class="toolGroup">
            <button class="toolBtn" id="tb_line" data-fn="setTool" data-arg="line">
                ╱<span class="tip">선 [L]</span>
            </button>
            <button class="toolBtn" id="tb_wall" data-fn="setTool" data-arg="wall">
                ▐<span class="tip">벽체 [W]</span>
            </button>
            <button class="toolBtn" id="tb_rect" data-fn="setTool" data-arg="rect">
                ▭<span class="tip">사각형 [R]</span>
            </button>
            <button class="toolBtn" id="tb_circle" data-fn="setTool" data-arg="circle">
                ○<span class="tip">원 [C]</span>
            </button>
            <button class="toolBtn" id="tb_polyline" data-fn="setTool" data-arg="polyline">
                ⌒<span class="tip">폴리선 [P]</span>
            </button>
        </div>
        <div class="toolGroup">
            <button class="toolBtn" id="tb_move" data-fn="setTool" data-arg="move">
                ✥<span class="tip">이동 [M]</span>
            </button>
            <button class="toolBtn" id="tb_copyMove" data-fn="setTool" data-arg="copyMove">
                ⧉<span class="tip">복사이동 [CO]</span>
            </button>
            <button class="toolBtn" id="tb_offset" data-fn="setTool" data-arg="offset">
                ⊞<span class="tip">오프셋 [O]</span>
            </button>
            <button class="toolBtn" id="tb_trim" data-fn="setTool" data-arg="trim">
                ✂<span class="tip">트림 [TR]</span>
            </button>
            <button class="toolBtn" id="tb_dim" data-fn="setTool" data-arg="dim">
                ↔<span class="tip">치수 [D]</span>
            </button>
        </div>
        <div class="toolGroup">
            <button class="toolBtn" id="tb_text" data-fn="setTool" data-arg="text">
                T<span class="tip">텍스트 [T]</span>
            </button>
            <button class="toolBtn" id="tb_annot" data-fn="setTool" data-arg="annot">
                💬<span class="tip">주석</span>
            </button>
        </div>
        <div class="toolGroup">
            <button class="toolBtn" data-fn="zoomIn">+<span class="tip">줌 인</span></button>
            <button class="toolBtn" data-fn="zoomOut">−<span class="tip">줌 아웃</span></button>
            <button class="toolBtn" data-fn="fitAll">⊡<span class="tip">전체 맞춤</span></button>
        </div>
    </div>

    <!-- CANVAS -->
    <div id="canvasWrap" tabindex="0">
        <canvas id="mainCanvas"></canvas>
        <canvas id="overlayCanvas"></canvas>
        <div id="printOverlay">
            <div id="printRect"></div>
            <div id="printLabel">영역 드래그 선택</div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div id="rightPanelResize" title="드래그로 너비 조절"></div>
    <div id="rightPanel">
        <div class="rTab">
            <button class="rTabBtn active" data-fn="switchRTab" data-arg="layers">레이어</button>
            <button class="rTabBtn" data-fn="switchRTab" data-arg="props">속성</button>
            <button class="rTabBtn" data-fn="switchRTab" data-arg="saves">저장</button>
        </div>

        <!-- LAYERS TAB -->
        <div class="rTabContent active" id="tab_layers">
            <div id="layerList"></div>
            <button class="addLayerBtn" data-fn="addLayer">+ 레이어 추가</button>
        </div>

        <!-- PROPERTIES TAB -->
        <div class="rTabContent" id="tab_props">
            <!-- 선택 객체 정보 -->
            <div class="propGroup" id="propInfoGroup">
                <div class="propLabel">선택 객체</div>
                <div id="propInfo" class="cad-hint" style="padding:4px 0">없음</div>
                <div class="propRow" id="propLenRow" style="display:none">
                    <label>길이</label>
                    <input type="number" class="propInput" id="prop_len" step="1" data-change="applyLength" title="길이 직접 수정">
                    <span class="cad-hint-sm" id="prop_lenUnit">mm</span>
                </div>
                <div class="propRow" id="propAreaRow" style="display:none">
                    <label>면적</label>
                    <span id="prop_area" class="cad-val"></span>
                </div>
            </div>
            <!-- 객체 정보 -->
            <div class="propGroup" id="propObjGroup" style="display:none">
                <div class="propLabel">객체 정보</div>
                <div class="propRow">
                    <label>타입</label>
                    <span id="prop_type" class="cad-accent">-</span>
                </div>
                <div class="propRow">
                    <label>레이어</label>
                    <select class="propSelect cad-fill" id="prop_layer" data-change="applyObjProps"></select>
                </div>
                <div class="propRow" id="propRow_x" style="display:none">
                    <label id="propLabel_x">X</label>
                    <input type="number" class="propInput" id="prop_x" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_y" style="display:none">
                    <label id="propLabel_y">Y</label>
                    <input type="number" class="propInput" id="prop_y" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_x2" style="display:none">
                    <label>끝점 X</label>
                    <input type="number" class="propInput" id="prop_x2" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_y2" style="display:none">
                    <label>끝점 Y</label>
                    <input type="number" class="propInput" id="prop_y2" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_w" style="display:none">
                    <label>폭</label>
                    <input type="number" class="propInput" id="prop_w" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_h" style="display:none">
                    <label>높이</label>
                    <input type="number" class="propInput" id="prop_h" step="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_r" style="display:none">
                    <label>반지름</label>
                    <input type="number" class="propInput" id="prop_r" step="1" min="1" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_fs" style="display:none">
                    <label>글자크기</label>
                    <input type="number" class="propInput" id="prop_fs" step="1" min="4" max="200" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_text" style="display:none">
                    <label>텍스트</label>
                    <textarea class="propInput" id="prop_text" rows="2" style="resize:vertical;min-height:28px" data-change="applyObjProps"></textarea>
                </div>
                <div class="propRow" id="propRow_blockInfo" style="display:none">
                    <label>블록명</label>
                    <input type="text" class="propInput" id="prop_blockName" data-change="applyObjProps">
                </div>
                <div class="propRow" id="propRow_blockCnt" style="display:none">
                    <label>하위객체</label>
                    <span id="prop_blockCnt" class="cad-val">0개</span>
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel">선 스타일</div>
                <div class="propRow">
                    <label>색상</label>
                    <input type="color" class="colorPicker" id="prop_color" value="#ffffff" data-change="applyProps">
                </div>
                <div class="propRow">
                    <label>두께</label>
                    <input type="number" class="propInput" id="prop_lw" value="1" min="0.1" max="20" step="0.5" data-change="applyProps">
                </div>
                <div class="propRow">
                    <label>선 종류</label>
                    <select class="propSelect" id="prop_ls" data-change="applyProps">
                        <option value="solid">실선</option>
                        <option value="dashed">파선</option>
                        <option value="dotted">점선</option>
                        <option value="dashdot">일점쇄선</option>
                        <option value="dashdotdot">이점쇄선</option>
                    </select>
                </div>
                <div class="propRow">
                    <label>끝 모양</label>
                    <select class="propSelect" id="prop_cap" data-change="applyProps">
                        <option value="butt">평평</option>
                        <option value="round">둥글게</option>
                        <option value="square">사각</option>
                    </select>
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel">채우기</div>
                <div class="propRow">
                    <label>채우기색</label>
                    <input type="color" class="colorPicker" id="prop_fill" value="#000000" data-change="applyProps">
                    <input type="checkbox" id="prop_fillOn" data-change="applyProps" title="채우기 켜기">
                </div>
                <div class="propRow">
                    <label>투명도</label>
                    <input type="range" id="prop_alpha" min="0" max="1" step="0.05" value="1" class="prop-range" data-change="applyProps">
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel">벽 두께</div>
                <div class="propRow">
                    <label>두께(mm)</label>
                    <input type="number" class="propInput" id="prop_wallW" value="200" min="50" step="50" data-change="applyProps">
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel">순서</div>
                <div class="cad-flex cad-gap-6" style="flex-wrap:wrap">
                    <button class="propBtn" data-fn="bringToFront">맨 앞</button>
                    <button class="propBtn" data-fn="sendToBack">맨 뒤</button>
                    <button class="propBtn" data-fn="bringForward">앞으로</button>
                    <button class="propBtn" data-fn="sendBackward">뒤로</button>
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel">잠금</div>
                <div class="propRow">
                    <button class="propBtn propBtnFull" data-fn="toggleLock">🔒 잠금/해제</button>
                </div>
            </div>
            <!-- 주석(말풍선) 속성 -->
            <div class="propGroup" id="propAnnotGroup" style="display:none">
                <div class="propLabel">말풍선</div>
                <div class="propRow">
                    <label>꼬리 모양</label>
                    <select class="propSelect" id="prop_tailStyle" data-change="applyAnnotProps">
                        <option value="arrow">화살표</option>
                        <option value="triangle">삼각형</option>
                        <option value="line">선</option>
                    </select>
                </div>
                <div class="propRow">
                    <label>꼬리 두께</label>
                    <input type="number" class="propInput" id="prop_tailWidth" value="2" min="0.5" max="10" step="0.5" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>풍선 모양</label>
                    <select class="propSelect" id="prop_bubbleShape" data-change="applyAnnotProps">
                        <option value="rounded">둥근 사각형</option>
                        <option value="rect">사각형</option>
                        <option value="ellipse">타원</option>
                    </select>
                </div>
                <div class="propRow">
                    <label>배경색</label>
                    <input type="color" class="colorPicker" id="prop_bubbleBg" value="#1a2a40" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>테두리색</label>
                    <input type="color" class="colorPicker" id="prop_bubbleBorder" value="#ffff88" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>테두리 두께</label>
                    <input type="number" class="propInput" id="prop_bubbleBorderW" value="1" min="0.5" max="8" step="0.5" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>투명도</label>
                    <input type="range" id="prop_bubbleAlpha" min="0" max="1" step="0.05" value="0.9" class="prop-range" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>글씨체</label>
                    <select class="propSelect" id="prop_annotFont" data-change="applyAnnotProps">
                        <option value="Noto Sans KR">Noto Sans KR</option>
                        <option value="맑은 고딕">맑은 고딕</option>
                        <option value="굴림">굴림</option>
                        <option value="돋움">돋움</option>
                        <option value="바탕">바탕</option>
                        <option value="Arial">Arial</option>
                        <option value="monospace">고정폭</option>
                    </select>
                </div>
                <div class="propRow">
                    <label>글자 크기</label>
                    <input type="number" class="propInput" id="prop_annotFontSize" value="12" min="8" max="72" step="1" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>글자색</label>
                    <input type="color" class="colorPicker" id="prop_annotColor" value="#ffff88" data-change="applyAnnotProps">
                </div>
                <div class="propRow">
                    <label>굵기</label>
                    <select class="propSelect" id="prop_annotWeight" data-change="applyAnnotProps">
                        <option value="normal">일반</option>
                        <option value="bold">볼드</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- SAVES TAB -->
        <div class="rTabContent" id="tab_saves">
            <input type="number" id="autoSaveMin" value="5" style="display:none">
            <input type="checkbox" id="autoSaveOn" checked style="display:none">
            <div class="propGroup">
                <div class="propLabel cad-flex-between">코멘트 <button onclick="popoutPanel('comment')" class="cad-popout-btn" title="별도 창으로">⧉</button></div>
                <div id="commentContent">
                    <textarea id="versionComment" placeholder="코멘트 입력..." class="prop-textarea"></textarea>
                    <button class="propBtn propBtnFull" style="margin-top:4px" data-fn="addVersionComment">💬 코멘트 저장</button>
                    <div id="commentList" class="cad-scroll-sm" style="margin-top:6px"></div>
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel cad-flex-between">버전 히스토리 <button onclick="popoutPanel('version')" class="cad-popout-btn" title="별도 창으로">⧉</button></div>
                <div id="versionContent">
                    <div id="versionList" class="cad-scroll-md"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 플로팅 코멘트 창 -->
<div id="floatComment" class="floatWin" style="display:none">
    <div class="floatWinHead" id="floatCommentHead">💬 코멘트 <span class="floatWinBtns"><button onclick="dockPanel('comment')" title="되돌리기">⮽</button><button onclick="document.getElementById('floatComment').style.display='none'" title="닫기">✕</button></span></div>
    <div class="floatWinBody" id="floatCommentBody"></div>
</div>

<!-- 플로팅 버전 창 -->
<div id="floatVersion" class="floatWin" style="display:none">
    <div class="floatWinHead" id="floatVersionHead">📋 버전 히스토리 <span class="floatWinBtns"><button onclick="dockPanel('version')" title="되돌리기">⮽</button><button onclick="document.getElementById('floatVersion').style.display='none'" title="닫기">✕</button></span></div>
    <div class="floatWinBody" id="floatVersionBody"></div>
</div>

<!-- STATUS BAR -->
<div id="statusbar">
    X: <b id="sX">0</b> &nbsp; Y: <b id="sY">0</b> &nbsp;|&nbsp;
    도구: <b id="sTool">선택</b> &nbsp;|&nbsp;
    레이어: <b id="sLayer">기본</b> &nbsp;|&nbsp;
    객체: <b id="sCount">0</b>개
    <span class="cad-fill"></span>
    <button class="stateBtn" id="btnGrid" data-fn="toggleGrid" title="모눈 [G]">
        <span class="sbIcon">⊞</span> 모눈 <span class="sbState" id="sGrid">ON</span>
    </button>
    <button class="stateBtn" id="btnSnap" data-fn="toggleSnap" title="스냅 [F3]">
        <span class="sbIcon">⊹</span> 스냅 <span class="sbState" id="sSnap">ON</span>
    </button>
    <button class="stateBtn off" id="btnOrtho" data-fn="toggleOrtho" title="직교 [F8]">
        <span class="sbIcon">⊢</span> 직교 <span class="sbState" id="sOrtho">OFF</span>
    </button>
</div>

<!-- 명령 히스토리 패널 (5줄 고정, 스크롤) -->
<div id="cmdHistPanel" style="display:none">
</div>

<!-- 명령창 -->
<div id="cmdBar">
    <span id="cmdPrompt">명령:</span>
    <div id="cmdInputWrap">
        <input id="cmdInput" type="text" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="명령어 입력...">
        <div id="cmdDropdown"></div>
    </div>
    <div id="cmdHistory"></div>
    <button id="cmdHistBtn" title="명령 히스토리">▲ 기록</button>
</div>

<!-- 현장연결 Modal -->
<div class="modalOverlay" id="siteConnModal">
    <div class="modal modal-lg modal-flex">
        <div class="modalTitle modal-header">현장 연결</div>
        <!-- 검색 단계 -->
        <!-- 1단계: 검색 -->
        <div id="siteStep1" class="modal-step">
            <div class="modalRow">
                <label>검색</label>
                <input type="text" class="modalInput cad-fill" id="siteConnSearch" placeholder="현장번호 또는 현장명 입력 후 Enter">
                <button data-fn="filterSiteList" class="cad-btn-search" title="검색">🔍</button>
            </div>
            <div id="siteConnList" class="cad-fill cad-scroll-xl" style="margin:8px 0;border:1px solid var(--border);border-radius:5px"></div>
        </div>
        <!-- 1.5단계: 도면 목록 -->
        <div id="siteStepDrawings" class="modal-step" style="display:none">
            <div class="cad-flex cad-gap-10" style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border)">
                <button id="siteDrawBackBtn" class="cad-btn-back">◀ 다시 검색</button>
                <span class="cad-accent-lg" id="siteDrawInfo"></span>
            </div>
            <div class="cad-hint" style="margin-bottom:8px">이 현장에 저장된 도면이 있습니다. 열기 또는 새로 작성하세요.</div>
            <div id="siteDrawListPanel" class="cad-fill cad-scroll-lg" style="border:1px solid var(--border);border-radius:5px;margin-bottom:10px"></div>
            <div class="cad-flex cad-gap-8" style="justify-content:flex-end">
                <button id="siteDrawNewBtn" class="cad-btn-new">+ 새로 작성</button>
            </div>
        </div>
        <!-- 세팅 단계 -->
        <div id="siteStep2" class="modal-step" style="display:none;overflow-y:auto">
            <div class="cad-flex cad-gap-10" style="margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)">
                <button id="siteBackBtn" class="cad-btn-back">◀ 다시 검색</button>
                <span class="cad-accent-lg" id="siteSelInfo"></span>
            </div>
            <!-- 현장 정보 (읽기 전용) -->
            <div class="cad-card">
                <div class="cad-section-title">현장 정보</div>
                <div class="cad-grid-2">
                    <div class="modalRow"><label>현장번호</label><input type="text" class="modalInput" id="siteSetNo" readonly class="cad-readonly cad-accent"></div>
                    <div class="modalRow"><label>현장명</label><input type="text" class="modalInput" id="siteSetName" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>주소</label><input type="text" class="modalInput" id="siteSetAddr" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>담당자</label><input type="text" class="modalInput" id="siteSetManager" value="-" readonly class="cad-readonly"></div>
                </div>
                <div class="cad-grid-3" style="margin-top:6px">
                    <div class="modalRow"><label>발주담당</label><input type="text" class="modalInput" id="siteSetOrder" value="-" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>고객명</label><input type="text" class="modalInput" id="siteSetClient" value="-" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>현장유형</label><input type="text" class="modalInput" id="siteSetType" value="-" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>총수량</label><input type="text" class="modalInput" id="siteSetQty" value="-" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>착공일</label><input type="text" class="modalInput" id="siteSetStart" value="-" readonly class="cad-readonly"></div>
                    <div class="modalRow"><label>준공일</label><input type="text" class="modalInput" id="siteSetEnd" value="-" readonly class="cad-readonly"></div>
                </div>
            </div>
            <!-- 도면 설정 (수정 가능) -->
            <div class="cad-card-accent">
                <div class="cad-section-title-accent">도면 설정</div>
                <div class="cad-grid-2">
                    <div class="modalRow"><label>기본 축척</label>
                        <select class="modalSelect" id="siteSetScale">
                            <option value="1" selected>1:1</option>
                            <option value="50">1:50</option>
                            <option value="100">1:100</option>
                            <option value="200">1:200</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>기본 단위</label>
                        <select class="modalSelect" id="siteSetUnit">
                            <option value="mm" selected>mm</option>
                            <option value="cm">cm</option>
                            <option value="m">m</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>저장 옵션1</label>
                        <select class="modalSelect cad-fill" id="siteSetSaveType1">
                            <option value="dwg" selected>📐 DWG</option>
                            <option value="">없음</option>
                            <option value="dxf">📐 DXF</option>
                            <option value="json">📋 JSON</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>저장 옵션2</label>
                        <select class="modalSelect cad-fill" id="siteSetSaveType2">
                            <option value="" selected>없음</option>
                            <option value="dwg">📐 DWG</option>
                            <option value="dxf">📐 DXF</option>
                            <option value="json">📋 JSON</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>저장 옵션3</label>
                        <select class="modalSelect cad-fill" id="siteSetSaveType3">
                            <option value="" selected>없음</option>
                            <option value="dwg">📐 DWG</option>
                            <option value="dxf">📐 DXF</option>
                            <option value="json">📋 JSON</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>작성파일명</label>
                        <input type="text" class="modalInput site-filename" id="siteSetFileName" readonly value="">
                    </div>
                </div>
                <!-- 작업범위 -->
                <div class="scope-section">
                    <div class="cad-section-title-accent">작업범위</div>
                    <div class="cad-section-title" style="margin-bottom:4px">배선</div>
                    <div class="wire-wrap">
                        <table class="wire-table">
                            <thead>
                                <tr style="background:var(--panel2)">
                                    <th style="width:35px">No</th>
                                    <th>품목명</th>
                                    <th class="tc" style="width:55px">단위</th>
                                    <th class="tr" style="width:65px">수량</th>
                                    <th class="tc" style="width:50px">산출</th>
                                    <th class="tc" style="width:35px"></th>
                                </tr>
                            </thead>
                            <tbody id="wireTableBody">
                                <tr>
                                    <td class="hint">1</td>
                                    <td><input type="text" class="modalInput wire-input" value="HIV 2.5mm" data-wire-name></td>
                                    <td><select class="modalSelect wire-select" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td><input type="number" class="modalInput wire-qty" value="150" data-wire-qty></td>
                                    <td class="tc"><input type="radio" name="wireCalc" value="0" checked title="산출연동"></td>
                                    <td class="tc"><span class="wire-del" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                                <tr>
                                    <td class="hint">2</td>
                                    <td><input type="text" class="modalInput wire-input" value="CV 4mm" data-wire-name></td>
                                    <td><select class="modalSelect wire-select" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td><input type="number" class="modalInput wire-qty" value="80" data-wire-qty></td>
                                    <td class="tc"><input type="radio" name="wireCalc" value="1" title="산출연동"></td>
                                    <td class="tc"><span class="wire-del" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                                <tr>
                                    <td class="hint">3</td>
                                    <td><input type="text" class="modalInput wire-input" value="FR-CV 6mm" data-wire-name></td>
                                    <td><select class="modalSelect wire-select" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td><input type="number" class="modalInput wire-qty" value="200" data-wire-qty></td>
                                    <td class="tc"><input type="radio" name="wireCalc" value="2" title="산출연동"></td>
                                    <td class="tc"><span class="wire-del" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button onclick="addWireRow()" class="wire-add">+ 품목 추가</button>
                </div>
            </div>
            <!-- 저장된 도면 -->
            <div class="cad-card">
                <div class="cad-section-title">저장된 도면</div>
                <div id="siteDrawingList" class="cad-scroll-sm" style="border:1px solid var(--border);border-radius:5px">
                    <div class="cad-empty">저장된 도면 없음</div>
                </div>
            </div>
        </div>
        <div class="modal-footer-bar">
            <button class="btnSecondary" data-fn="closeModal" data-arg="siteConnModal">닫기</button>
            <div class="cad-fill"></div>
            <button id="siteNewDrawBtn" class="btnPrimary" style="display:none;background:#6366f1;" onclick="startNewDrawing()">+ 새 도면 작성</button>
            <button class="btnPrimary" id="siteApplyBtn" data-fn="applySiteSettings" style="display:none">적용</button>
        </div>
    </div>
</div>

<!-- About Modal -->
<div class="modalOverlay" id="aboutModal">
    <div class="modal modal-sm" style="text-align:center">
        <div class="modalTitle">SHVCAD</div>
        <div class="about-body">
            <div class="about-title">SHVCAD</div>
            <div>SHV ERP System v1.0</div>
            <div class="cad-hint" style="margin-top:8px">© 2026 SH Vision. All rights reserved.</div>
            <div class="cad-hint" style="margin-top:4px">No1@shv.kr</div>
        </div>
        <div class="modalBtns">
            <button class="btnPrimary" data-fn="closeModal" data-arg="aboutModal">닫기</button>
        </div>
    </div>
</div>

<!-- FOOTER -->
<div id="footer">
  © 2026 SH Vision. All rights reserved. &nbsp;|&nbsp; SHV ERP System v1.0
</div>

<!-- ── MODALS ── -->

<!-- Print Modal -->
<div class="modalOverlay" id="printModal">
    <div class="modal" style="max-width:700px;width:95%;">
        <div class="modalTitle">🖨️ 인쇄 / PDF 출력</div>
        <div class="print-layout">
            <!-- 왼쪽: 설정 -->
            <div class="print-left">
                <div class="modalRow">
                    <label>인쇄 영역</label>
                    <select class="modalSelect" id="pArea" onchange="window._onPrintAreaChange&&window._onPrintAreaChange()">
                        <option value="view">현재 화면</option>
                        <option value="all">전체 도면</option>
                        <option value="select">영역 지정</option>
                    </select>
                </div>
                <div id="pAreaInfo" class="print-area-info" style="display:none"></div>
                <div id="pAreaSelectBtn" style="display:none;margin:-4px 0 10px;">
                    <button type="button" onclick="closeModal('printModal');setTimeout(function(){if(typeof startPrintSelect==='function')startPrintSelect();else if(typeof setTool==='function')setTool('printSelect');},200);" class="print-area-btn">✂ 영역 지정하기</button>
                </div>
                <div class="modalRow">
                    <label>용지 크기</label>
                    <select class="modalSelect" id="pPaper" onchange="cadFn('updatePrintPreview')">
                        <option value="A4">A4</option>
                        <option value="A3" selected>A3</option>
                        <option value="A2">A2</option>
                        <option value="A1">A1</option>
                    </select>
                </div>
                <div class="modalRow">
                    <label>방향</label>
                    <select class="modalSelect" id="pOrient" onchange="cadFn('updatePrintPreview')">
                        <option value="landscape">가로</option>
                        <option value="portrait">세로</option>
                    </select>
                </div>
                <div class="modalRow">
                    <label>축척</label>
                    <select class="modalSelect" id="pScale">
                        <option value="fit">화면 맞춤</option>
                        <option value="1:50">1:50</option>
                        <option value="1:100">1:100</option>
                        <option value="1:200">1:200</option>
                    </select>
                </div>
                <div class="modalRow">
                    <label>정렬</label>
                    <select class="modalSelect" id="pAlign">
                        <option value="center">가운데</option>
                        <option value="topleft">왼쪽 위</option>
                    </select>
                </div>
                <div class="modalRow">
                    <label>해상도</label>
                    <select class="modalSelect" id="pDpi" onchange="window._onPrintAreaChange&&window._onPrintAreaChange()">
                        <option value="150">150 DPI</option>
                        <option value="300">300 DPI</option>
                        <option value="600" selected>600 DPI</option>
                        <option value="1200">1200 DPI</option>
                    </select>
                </div>
                <div class="modalRow">
                    <label>주석 포함</label>
                    <input type="checkbox" id="pAnnot" checked>
                </div>
                <div class="modalRow">
                    <label>워터마크</label>
                    <input type="checkbox" id="pWatermark" checked>
                </div>
                <div class="modalRow">
                    <label>출력일자</label>
                    <input type="checkbox" id="pPrintDate" checked>
                </div>
            </div>
            <!-- 오른쪽: 미리보기 -->
            <div class="print-right">
                <div class="print-preview-label">미리보기</div>
                <div id="printPreviewWrap" class="print-preview-wrap">
                    <canvas id="printPreviewCanvas"></canvas>
                </div>
            </div>
        </div>
        <div class="modalBtns" style="margin-top:12px;">
            <button class="btnSecondary" data-fn="closeModal" data-arg="printModal">취소</button>
            <button class="btnPrimary btn-print" data-fn="doPrintDirect">🖨️ 인쇄</button>
            <button class="btnPrimary" data-fn="doPrint">📄 PDF</button>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modalOverlay" id="settingsModal">
    <div class="modal modal-md modal-flex">
        <div class="modalTitle modal-header">⚙ 설정</div>
        <div class="cad-flex cad-fill" style="overflow:hidden">
            <!-- 사이드메뉴 -->
            <div id="setSide" class="set-side">
                <div class="setSideItem active" data-set-tab="setGeneral">일반</div>
                <div class="setSideItem" data-set-tab="setDim">치수</div>
                <div class="setSideItem" data-set-tab="setPointer">포인터</div>
                <div class="setSideItem" data-set-tab="setDraft">제도</div>
                <div class="setSideItem" data-set-tab="setDisplay">표시</div>
                <div class="setSideItem" data-set-tab="setShortcut">단축키</div>
                <div class="setSideItem" data-set-tab="setSystem">시스템</div>
                <div class="setSideItem" data-set-tab="setAbout">정보</div>
            </div>
            <!-- 콘텐츠 -->
            <div class="set-body">
                <!-- 일반 -->
                <div class="setTabContent active" id="setGeneral">
                    <div class="set-grid">
                        <div class="modalRow"><label>격자 간격(px)</label><input type="number" class="modalInput" id="setGrid" value="20" min="5" max="100"></div>
                        <div class="modalRow"><label>스냅 감도(px)</label><input type="number" class="modalInput" id="setSnap" value="10" min="2" max="30"></div>
                        <div class="modalRow"><label>기본 선 두께</label><input type="number" class="modalInput" id="setDefLW" value="1" min="0.5" max="10" step="0.5"></div>
                        <div class="modalRow"><label>기본 선 색상</label><input type="color" class="colorPicker" id="setDefColor" value="#ffffff"></div>
                    </div>
                </div>
                <!-- 치수 -->
                <div class="setTabContent" id="setDim">
                    <div class="set-grid">
                        <div class="modalRow"><label>치수 폰트 크기</label><input type="number" class="modalInput" id="setDimFont" value="12" min="8" max="24"></div>
                        <div class="modalRow"><label>화살표 크기</label><input type="number" class="modalInput" id="setArrow" value="10" min="4" max="24"></div>
                        <div class="modalRow"><label>치수선 색상</label><input type="color" class="colorPicker" id="setDimColor" value="#88ff88"></div>
                    </div>
                </div>
                <!-- 포인터 -->
                <div class="setTabContent" id="setPointer">
                    <div class="set-grid">
                        <div class="modalRow"><label>십자선 크기</label>
                            <select class="modalSelect" id="setCursorSize">
                                <option value="small">소 (20%)</option>
                                <option value="medium" selected>중 (50%)</option>
                                <option value="large">대 (80%)</option>
                                <option value="full">전체</option>
                            </select>
                        </div>
                        <div class="modalRow"><label>선 굵기</label><input type="number" class="modalInput" id="setCursorLW" value="1" min="0.5" max="3" step="0.5"></div>
                        <div class="modalRow"><label>색상</label><input type="color" class="colorPicker" id="setCursorColor" value="#ffffff"></div>
                        <div class="modalRow"><label>중심 사각형</label><input type="checkbox" id="setCursorSquare" checked></div>
                        <div class="modalRow"><label>중심 공백</label><input type="number" class="modalInput" id="setCursorGap" value="8" min="0" max="30"></div>
                    </div>
                </div>
                <!-- 제도 -->
                <div class="setTabContent" id="setDraft">
                    <div class="set-grid">
                        <div class="modalRow"><label>모눈(격자) <span class="key-hint">[G]</span></label>
                            <select class="modalSelect" id="setGridOn" onchange="if(typeof toggleGrid==='function'){if((this.value==='1')!==showGrid)toggleGrid();}">
                                <option value="1" selected>ON</option>
                                <option value="0">OFF</option>
                            </select>
                        </div>
                        <div class="modalRow"><label>직교 모드 <span class="key-hint">[F8]</span></label><select class="modalSelect" id="setOrtho"><option value="0">OFF</option><option value="1">ON</option></select></div>
                        <div class="modalRow"><label>객체스냅 <span class="key-hint">[F3]</span></label><select class="modalSelect" id="setSnapMode"><option value="0">OFF</option><option value="1">ON</option></select></div>
                    </div>
                    <div class="set-sep">스냅 종류 <span style="font-size:9px">(F3으로 전체 ON/OFF)</span></div>
                    <div class="set-grid">
                        <div class="modalRow"><label>끝점</label><input type="checkbox" id="setSnapEnd" checked></div>
                        <div class="modalRow"><label>중점</label><input type="checkbox" id="setSnapMid" checked></div>
                        <div class="modalRow"><label>교차점</label><input type="checkbox" id="setSnapInter" checked></div>
                        <div class="modalRow"><label>중심점</label><input type="checkbox" id="setSnapCenter" checked></div>
                        <div class="modalRow"><label>수직점</label><input type="checkbox" id="setSnapPerp"></div>
                        <div class="modalRow"><label>중심</label><input type="checkbox" id="setSnapCen" checked></div>
                        <div class="modalRow"><label>직교</label><input type="checkbox" id="setSnapOrtho"></div>
                        <div class="modalRow"><label>근처점</label><input type="checkbox" id="setSnapNearest" checked></div>
                        <div class="modalRow"><label>사분점</label><input type="checkbox" id="setSnapQuad" checked></div>
                        <div class="modalRow"><label>접점</label><input type="checkbox" id="setSnapTangent"></div>
                    </div>
                    <div class="set-sep">기타</div>
                    <div class="set-grid">
                        <div class="modalRow"><label>각도 제한</label>
                            <select class="modalSelect" id="setAngleSnap">
                                <option value="0">없음</option>
                                <option value="15">15°</option>
                                <option value="30">30°</option>
                                <option value="45" selected>45°</option>
                                <option value="90">90°</option>
                            </select>
                        </div>
                        <div class="modalRow"><label>벽체 두께(mm)</label><input type="number" class="modalInput" id="setWallW" value="200" min="50" max="1000" step="50"></div>
                        <div class="modalRow"><label>기본 단위</label>
                            <select class="modalSelect" id="setDefUnit">
                                <option value="mm" selected>mm</option>
                                <option value="cm">cm</option>
                                <option value="m">m</option>
                            </select>
                        </div>
                    </div>
                </div>
                <!-- 표시 -->
                <div class="setTabContent" id="setDisplay">
                    <div class="set-grid">
                        <div class="modalRow"><label>배경색</label><input type="color" class="colorPicker" id="setBgColor" value="#000000"></div>
                        <div class="modalRow"><label>격자색</label><input type="color" class="colorPicker" id="setGridColor" value="#1a2a40"></div>
                        <div class="modalRow"><label>테마</label>
                            <select class="modalSelect" id="setTheme">
                                <option value="dark" selected>다크</option>
                                <option value="blue">블루</option>
                            </select>
                        </div>
                    </div>
                </div>
                <!-- 단축키 -->
                <div class="setTabContent" id="setShortcut">
                    <div class="scGrid">
                        <div class="scRow"><span class="scKey">S / Esc</span><span class="scDesc">선택</span></div>
                        <div class="scRow"><span class="scKey">L</span><span class="scDesc">선</span></div>
                        <div class="scRow"><span class="scKey">W</span><span class="scDesc">벽체</span></div>
                        <div class="scRow"><span class="scKey">R</span><span class="scDesc">사각형</span></div>
                        <div class="scRow"><span class="scKey">C</span><span class="scDesc">원</span></div>
                        <div class="scRow"><span class="scKey">P</span><span class="scDesc">폴리선</span></div>
                        <div class="scRow"><span class="scKey">O</span><span class="scDesc">오프셋</span></div>
                        <div class="scRow"><span class="scKey">D</span><span class="scDesc">치수</span></div>
                        <div class="scRow"><span class="scKey">T</span><span class="scDesc">텍스트</span></div>
                        <div class="scRow"><span class="scKey">M</span><span class="scDesc">이동</span></div>
                        <div class="scRow"><span class="scKey">CO</span><span class="scDesc">복사이동</span></div>
                        <div class="scRow"><span class="scKey">RE</span><span class="scDesc">리프레시</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+Z</span><span class="scDesc">실행취소</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+Y</span><span class="scDesc">다시실행</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+C</span><span class="scDesc">복사</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+V</span><span class="scDesc">붙여넣기</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+S</span><span class="scDesc">저장</span></div>
                        <div class="scRow"><span class="scKey">Ctrl+P</span><span class="scDesc">PDF 출력</span></div>
                        <div class="scRow"><span class="scKey">G</span><span class="scDesc">격자 토글</span></div>
                        <div class="scRow"><span class="scKey">F3</span><span class="scDesc">스냅 토글</span></div>
                        <div class="scRow"><span class="scKey">F8</span><span class="scDesc">직교 모드</span></div>
                        <div class="scRow"><span class="scKey">↑ / ↓</span><span class="scDesc">명령 히스토리</span></div>
                    </div>
                </div>
                <!-- 시스템 -->
                <div class="setTabContent" id="setSystem">
                    <div class="cad-grid-2">
                        <div class="modalRow">
                            <label>자동저장</label>
                            <select class="modalSelect" id="autoSaveOn2" onchange="document.getElementById('autoSaveOn').checked=(this.value==='1');setAutoSave();">
                                <option value="1" selected>ON</option>
                                <option value="0">OFF</option>
                            </select>
                        </div>
                        <div class="modalRow">
                            <label>저장 간격(분)</label>
                            <input type="number" class="modalInput" id="autoSaveMin2" value="5" min="1" max="60" onchange="document.getElementById('autoSaveMin').value=this.value;setAutoSave();">
                        </div>
                        <div class="modalRow">
                            <label>미저장 경고</label>
                            <select class="modalSelect" id="setUnsavedWarn">
                                <option value="1" selected>ON</option>
                                <option value="0">OFF</option>
                            </select>
                        </div>
                        <div class="modalRow">
                            <label>백업 복구</label>
                            <select class="modalSelect" id="setBackupRestore">
                                <option value="1" selected>ON</option>
                                <option value="0">OFF</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin:14px 0 6px;color:var(--textD);font-size:10px;letter-spacing:0.5px">캐시 관리</div>
                    <div class="cad-grid-2">
                        <div class="modalRow">
                            <button class="btnSecondary" style="width:100%;font-size:11px" onclick="localStorage.removeItem('cad_unsaved');notify('백업 데이터 삭제됨');">백업 데이터 삭제</button>
                        </div>
                        <div class="modalRow">
                            <button class="btnSecondary" style="width:100%;font-size:11px" onclick="localStorage.clear();notify('전체 캐시 삭제됨');">전체 캐시 삭제</button>
                        </div>
                    </div>
                </div>
                <!-- 정보 -->
                <div class="setTabContent" id="setAbout">
                    <div style="text-align:center;padding:20px 0">
                        <div style="font-size:28px;font-weight:700;color:var(--accent);margin-bottom:12px">SHVCAD</div>
                        <div style="color:var(--textB);font-size:13px">SHV ERP System v1.0</div>
                        <div style="color:var(--textD);font-size:11px;margin-top:12px">© 2026 SH Vision. All rights reserved.</div>
                        <div style="color:var(--textD);font-size:11px;margin-top:4px">No1@shv.kr</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modalBtns" style="padding:12px 20px;border-top:1px solid var(--border)">
            <button class="btnSecondary" data-fn="closeModal" data-arg="settingsModal">취소</button>
            <button class="btnPrimary" data-fn="applySettings">적용</button>
        </div>
    </div>
</div>

<!-- Shortcut Modal -->
<div class="modalOverlay" id="shortcutModal">
    <div class="modal modal-sm">
        <div class="modalTitle">⌨ 단축키 목록</div>
        <div class="scGrid">
            <div class="scRow"><span class="scKey">Ctrl+Shift+1</span><span class="scDesc">속성 패널</span></div>
            <div class="scRow"><span class="scKey">M</span><span class="scDesc">이동</span></div>
            <div class="scRow"><span class="scKey">CO</span><span class="scDesc">복사이동</span></div>
            <div class="scRow"><span class="scKey">SC</span><span class="scDesc">스케일 조정</span></div>
            <div class="scRow"><span class="scKey">Space</span><span class="scDesc">마지막 명령 반복</span></div>
            <div class="scRow"><span class="scKey">L</span><span class="scDesc">선</span></div>
            <div class="scRow"><span class="scKey">W</span><span class="scDesc">벽체</span></div>
            <div class="scRow"><span class="scKey">R</span><span class="scDesc">사각형</span></div>
            <div class="scRow"><span class="scKey">C</span><span class="scDesc">원</span></div>
            <div class="scRow"><span class="scKey">P</span><span class="scDesc">폴리선</span></div>
            <div class="scRow"><span class="scKey">O</span><span class="scDesc">오프셋</span></div>
            <div class="scRow"><span class="scKey">D</span><span class="scDesc">치수</span></div>
            <div class="scRow"><span class="scKey">T</span><span class="scDesc">텍스트</span></div>
            <div class="scRow"><span class="scKey">S / Esc</span><span class="scDesc">선택</span></div>
            <div class="scRow"><span class="scKey">Delete</span><span class="scDesc">삭제</span></div>
            <div class="scRow"><span class="scKey">Ctrl+Z</span><span class="scDesc">실행취소</span></div>
            <div class="scRow"><span class="scKey">Ctrl+Y</span><span class="scDesc">다시실행</span></div>
            <div class="scRow"><span class="scKey">Ctrl+C</span><span class="scDesc">복사</span></div>
            <div class="scRow"><span class="scKey">Ctrl+V</span><span class="scDesc">붙여넣기</span></div>
            <div class="scRow"><span class="scKey">Ctrl+A</span><span class="scDesc">전체선택</span></div>
            <div class="scRow"><span class="scKey">Ctrl+S</span><span class="scDesc">저장</span></div>
            <div class="scRow"><span class="scKey">Ctrl+]</span><span class="scDesc">맨 앞으로</span></div>
            <div class="scRow"><span class="scKey">Ctrl+[</span><span class="scDesc">맨 뒤로</span></div>
            <div class="scRow"><span class="scKey">Ctrl+P</span><span class="scDesc">PDF 출력</span></div>
            <div class="scRow"><span class="scKey">Ctrl+Shift+F</span><span class="scDesc">전체 맞춤</span></div>
            <div class="scRow"><span class="scKey">G</span><span class="scDesc">격자 토글</span></div>
            <div class="scRow"><span class="scKey">F3</span><span class="scDesc">스냅 토글</span></div>
            <div class="scRow"><span class="scKey">F8</span><span class="scDesc">직교 모드</span></div>
            <div class="scRow"><span class="scKey">F1</span><span class="scDesc">이 도움말</span></div>
            <div class="scRow"><span class="scKey">+/-</span><span class="scDesc">줌 인/아웃</span></div>
        </div>
        <div class="modalBtns">
            <button class="btnPrimary" data-fn="closeModal" data-arg="shortcutModal">닫기</button>
        </div>
    </div>
</div>

<!-- Background Import Modal -->
<!-- Pointer Settings Modal -->
<div class="modalOverlay" id="pointerModal">
    <div class="modal" style="min-width:300px">
        <div class="modalTitle">🖱 포인터 설정</div>
        <div class="modalRow">
            <label>십자선 크기</label>
            <select class="modalSelect" id="cursorSizePreset">
                <option value="small">소 (화면 20%)</option>
                <option value="medium" selected>중 (화면 50%)</option>
                <option value="large">대 (화면 80%)</option>
                <option value="full">전체 화면</option>
                <option value="custom">직접 입력</option>
            </select>
        </div>
        <div class="modalRow" id="cursorCustomRow" style="display:none">
            <label>직접 입력 (%)</label>
            <input type="number" class="modalInput" id="cursorSizePct" value="50" min="5" max="100" step="5">
        </div>
        <div class="modalRow">
            <label>선 굵기</label>
            <input type="number" class="modalInput" id="cursorLineWidth" value="1" min="0.5" max="3" step="0.5">
        </div>
        <div class="modalRow">
            <label>색상</label>
            <input type="color" class="colorPicker" id="cursorColor" value="#ffffff" style="width:60px;height:30px">
        </div>
        <div class="modalRow">
            <label>중심 사각형</label>
            <input type="checkbox" id="cursorSquare" checked>
        </div>
        <div class="modalRow">
            <label>중심 공백</label>
            <input type="number" class="modalInput" id="cursorGap" value="8" min="0" max="30" step="1">
        </div>
        <!-- Preview -->
        <div style="margin:12px 0 4px;color:var(--textD);font-size:11px;text-transform:uppercase;letter-spacing:1px">미리보기</div>
        <canvas id="cursorPreview" width="260" height="100" style="background:#000;border:1px solid var(--border);border-radius:4px;width:100%"></canvas>
        <div class="modalBtns">
            <button class="btnSecondary" data-fn="closeModal" data-arg="pointerModal">취소</button>
            <button class="btnPrimary" data-fn="saveCursorSettings">적용</button>
        </div>
    </div>
</div>
<div class="modalOverlay" id="bgModal">
    <div class="modal">
        <div class="modalTitle">🖼 배경 이미지/PDF 불러오기</div>
        <div class="modalRow">
            <label>파일 선택</label>
            <input type="file" id="bgFileInput" class="modalInput" accept=".png,.jpg,.jpeg,.gif,.bmp,.webp,.pdf" data-change="loadBgFile">
        </div>
        <div class="modalRow">
            <label>투명도</label>
            <input type="range" id="bgAlpha" min="0.1" max="1" step="0.05" value="0.5" style="flex:1" data-change="updateBgAlpha">
            <span id="bgAlphaVal" style="color:var(--textB);font-size:11px;width:30px">0.5</span>
        </div>
        <div class="modalRow">
            <label>스케일 기준</label>
            <input type="number" class="modalInput" id="bgScale" value="1" min="0.1" step="0.1" placeholder="배율">
        </div>
        <div class="modalBtns">
            <button class="btnDanger" data-fn="removeBg">배경 제거</button>
            <button class="btnSecondary" data-fn="closeModal" data-arg="bgModal">닫기</button>
            <button class="btnPrimary" data-fn="applyBg">적용</button>
        </div>
    </div>
</div>

<!-- PDF 페이지 선택 모달 -->
<div class="modalOverlay" id="pdfPageModal">
    <div class="modal" style="min-width:360px">
        <div class="modalTitle">📄 PDF 페이지 선택</div>
        <div class="modalRow">
            <label>전체 페이지</label>
            <span id="pdfTotalPages" style="color:var(--textB);font-weight:500">-</span>
        </div>
        <div class="modalRow">
            <label>삽입 페이지</label>
            <input type="number" class="modalInput" id="pdfPageNum" value="1" min="1" step="1" style="width:80px">
        </div>
        <!-- 페이지 썸네일 미리보기 -->
        <div style="margin:10px 0;text-align:center">
            <canvas id="pdfThumbCanvas" style="max-width:100%;border:1px solid var(--border);border-radius:4px;background:#fff"></canvas>
        </div>
        <div class="modalBtns">
            <button class="btnSecondary" id="btnPdfCancel">취소</button>
            <button class="btnSecondary" id="btnPdfPrev">◀ 이전</button>
            <button class="btnSecondary" id="btnPdfNext">다음 ▶</button>
            <button class="btnPrimary" id="btnPdfInsert">삽입</button>
        </div>
    </div>
</div>

<!-- Context Menu -->
<div id="ctxMenu">
    <div class="ctxItem" data-fn="copySelected">복사 <span class="ctxKey">Ctrl+C</span></div>
    <div class="ctxItem" data-fn="pasteObjects">붙여넣기 <span class="ctxKey">Ctrl+V</span></div>
    <div class="ctxSep"></div>
    <div class="ctxItem" data-fn="bringToFront">맨 앞으로 <span class="ctxKey">Ctrl+]</span></div>
    <div class="ctxItem" data-fn="sendToBack">맨 뒤로 <span class="ctxKey">Ctrl+[</span></div>
    <div class="ctxSep"></div>
    <div class="ctxItem" data-fn="toggleLock">잠금/해제</div>
    <div class="ctxSep"></div>
    <div class="ctxItem" data-fn="deleteSelected" style="color:var(--danger)">삭제 <span class="ctxKey">Del</span></div>
</div>

<!-- Dynamic Cursor Tooltip -->
<div id="dynTip" style="
  display:none;position:fixed;pointer-events:none;z-index:999;
  background:rgba(10,20,40,0.92);border:1px solid #00aaff;
  color:#d0e8ff;font-family:monospace;font-size:11px;
  padding:4px 10px;border-radius:4px;white-space:nowrap;
  box-shadow:0 2px 8px rgba(0,0,0,0.6);
"></div>

<!-- Dynamic Length Input (선/벽체 그릴 때 숫자 입력) -->
<div id="dynLenWrap" style="display:none;position:fixed;z-index:1100;background:var(--panel);border:1px solid var(--accent);border-radius:6px;padding:5px 8px;align-items:center;gap:6px;box-shadow:0 4px 16px rgba(0,0,0,0.7);">
    <input id="dynLenInput" type="text" inputmode="numeric"
           style="width:90px;background:var(--panel2);border:1px solid var(--border);color:#fff;font-family:monospace;font-size:14px;padding:4px 8px;border-radius:4px;outline:none;text-align:right;"
           placeholder="길이"
    >
    <span id="dynLenUnit" style="color:var(--textD);font-size:11px;min-width:18px">mm</span>
</div>

<!-- Notification -->
<div id="notify"></div>

<!-- Hidden inputs -->
<input type="file" id="xlsxFileInput" accept=".xlsx,.xls,.csv,.tsv" style="display:none" data-change="importXLSX">
<input type="file" id="jsonFileInput" accept=".json" style="display:none" data-change="loadJSON">
<input type="file" id="dwgFileInput" accept=".dwg,.dxf" style="display:none">






<script>
    /* PHP -> JS 데이터 주입 (config.js 보다 먼저 로드) */
    window._CAD_API = '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?api=';
    window._CAD_INIT = <?php echo $jl; ?>;
    window._CAD_STATUS = <?php echo json_encode($CAD_STATUS); ?>;
    window._CAD_LEVELS = <?php echo json_encode($CAD_LEVELS); ?>;
    window._CAD_PERMISSIONS = <?php echo json_encode($CAD_PERMISSIONS); ?>;
    window._CAD_STATUS_EDIT_LEVEL = <?php echo json_encode($CAD_STATUS_EDIT_LEVEL); ?>;
    window._CAD_USER = <?php echo json_encode($CAD_CURRENT_USER); ?>;
    window._CAD_COMPANY = '(주)에스에이치비전';
    window._CAD_AUTHOR = <?php echo json_encode($CAD_CURRENT_USER['name']??''); ?>;
</script>
<?php $jsVer = time(); ?>
<script src="js/CAD_config.js?v=<?php echo $jsVer; ?>"></script>
<script src="js/CAD_engine.js?v=<?php echo $jsVer; ?>"></script>
<script src="js/CAD_ui.js?v=<?php echo $jsVer; ?>"></script>
<script>
// ── 접속 방식별 현장 자동 연결 ──
(function(){
    var params = new URLSearchParams(window.location.search);
    var siteIdx = params.get('site_idx');
    var siteName = params.get('site_name');
    var drawingIdx = params.get('drawing_idx');

    if(siteIdx){
        // 현장관리에서 접속 → 자동 연결
        var decodedName = siteName ? decodeURIComponent(siteName) : '';

        setTimeout(function(){
            fetch('dist/site_api.php?action=detail&idx='+siteIdx)
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if(d.ok) window._siteDetail = d.data;
                    var no = d.ok && d.data.no ? d.data.no : '';
                    var name = d.ok && d.data.name ? d.data.name : decodedName;
                    var addr = d.ok && d.data.addr ? d.data.addr : '';
                    var member = d.ok && d.data.order ? d.data.order : '';

                    // 상단 현장정보 업데이트
                    document.getElementById('siteNo').textContent = no;
                    document.getElementById('siteNo').style.color = '#00ffcc';
                    document.getElementById('siteName').textContent = name;

                    // 연결 성공 팝업
                    if(typeof showSiteConnectedPopup==='function') showSiteConnectedPopup(no, name, addr, member);

                    // 도면 목록 처리
                    if(typeof showSiteDrawings==='function') showSiteDrawings(no, name, addr);

                    if(drawingIdx){
                        setTimeout(function(){
                            fetch(window._CAD_API+'load&id=dwg_site_'+siteIdx+'_'+drawingIdx)
                                .then(function(r){ return r.json(); })
                                .then(function(dd){ if(dd.drawing) loadDrawingData(dd); });
                        }, 500);
                    }
                })
                .catch(function(){
                    document.getElementById('siteNo').textContent = '연결실패';
                    document.getElementById('siteName').textContent = decodedName || '-';
                });
        }, 800);
    } else {
        // 직접접속 또는 홈메뉴 접속 → 현장연결 모달 자동 표시 (데모 모드 제외)
        if(!window.CAD_IS_DEMO){
            setTimeout(function(){
                var siteNoEl = document.getElementById('siteNo');
                if(siteNoEl && (siteNoEl.textContent === '미연결' || !siteNoEl.textContent.trim() || siteNoEl.textContent === '-')){
                    openModal('siteConnModal');
                }
            }, 1000);
        }
    }
})();

// 포탈 이동 (겸용 라우터 대응)
function goPortal(route){
    route = (route || 'dashboard').replace(/^#/, '');
    if(window.parent !== window){
        // iframe: parent.navigateTo 우선, 없으면 hash fallback
        if(typeof window.parent.navigateTo === 'function'){
            window.parent.navigateTo(route);
        } else {
            try {
                var pUrl = new URL(window.parent.location.href);
                pUrl.searchParams.set('r', route);
                if(!pUrl.searchParams.get('system')) pUrl.searchParams.set('system', 'cad');
                pUrl.hash = '';
                window.parent.location.href = pUrl.pathname + '?' + pUrl.searchParams.toString();
            } catch(e){
                var pBase = window.parent.location.pathname.replace(/\/CAD\/cad\.php.*/, '/index.php');
                window.parent.location.href = pBase + '?system=cad&r=' + encodeURIComponent(route);
            }
        }
    } else {
        // 단독창: ?r= 방식으로 이동
        var base = window.location.pathname.replace(/\/CAD\/cad\.php.*/, '/index.php');
        window.open(base + '?system=cad&r=' + encodeURIComponent(route), '_blank');
    }
}

// 현장 연결 성공 팝업
function _escHtml(s){ var d=document.createElement('div'); d.textContent=s||'-'; return d.innerHTML; }
function showSiteConnectedPopup(no, name, addr, member){
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;';
    overlay.onclick = function(e){ if(e.target===this){ this.remove(); } };

    overlay.innerHTML =
        '<div style="background:#1a2233;border:1px solid #2a3a55;border-radius:14px;width:380px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.5);animation:fadeIn .25s ease;overflow:hidden;">' +
            '<div style="padding:20px 24px;text-align:center;">' +
                '<div style="width:56px;height:56px;border-radius:50%;background:rgba(0,255,204,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;"><span style="font-size:28px;color:#00ffcc;">✓</span></div>' +
                '<h3 style="font-size:16px;font-weight:800;color:#fff;margin-bottom:4px;">현장 연결 완료</h3>' +
                '<p style="font-size:12px;color:#8899bb;margin-bottom:16px;">현장 정보가 자동으로 연결되었습니다</p>' +
            '</div>' +
            '<div style="padding:0 24px 20px;">' +
                '<table style="width:100%;border-collapse:collapse;">' +
                    '<tr><td style="padding:6px 0;font-size:11px;color:#6688aa;width:70px;">현장번호</td><td style="padding:6px 0;font-size:13px;color:#00aaff;font-weight:700;">' + _escHtml(no) + '</td></tr>' +
                    '<tr><td style="padding:6px 0;font-size:11px;color:#6688aa;">현장명</td><td style="padding:6px 0;font-size:13px;color:#fff;font-weight:600;">' + _escHtml(name) + '</td></tr>' +
                    '<tr><td style="padding:6px 0;font-size:11px;color:#6688aa;">발주처</td><td style="padding:6px 0;font-size:12px;color:#aabbcc;">' + _escHtml(member) + '</td></tr>' +
                    '<tr><td style="padding:6px 0;font-size:11px;color:#6688aa;">주소</td><td style="padding:6px 0;font-size:11px;color:#8899bb;">' + _escHtml(addr) + '</td></tr>' +
                '</table>' +
            '</div>' +
            '<div style="padding:12px 24px;border-top:1px solid #2a3a55;text-align:center;">' +
                '<button onclick="this.closest(\'div[style]\').parentElement.remove()" style="padding:8px 32px;background:#00aaff;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">확인</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    // 3초 후 자동 닫기
    setTimeout(function(){ if(overlay.parentElement) overlay.remove(); }, 4000);
}

// ── 공통: 토스트 알림 ──
(function(){
    var tc = document.createElement('div');
    tc.id = 'cadToastContainer';
    tc.style.cssText = 'position:fixed;top:60px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
    document.body.appendChild(tc);
})();
function showToast(msg, type){
    type = type || 'info';
    var colors = {info:'#3b82f6',success:'#10b981',danger:'#ef4444',warn:'#f59e0b'};
    var tc = document.getElementById('cadToastContainer');
    var t = document.createElement('div');
    t.style.cssText = 'padding:10px 18px;border-radius:8px;font-size:12px;font-weight:600;color:#fff;background:'+( colors[type]||colors.info)+';box-shadow:0 4px 16px rgba(0,0,0,.2);opacity:0;transform:translateX(30px);transition:all .3s;max-width:320px;';
    t.textContent = msg;
    tc.appendChild(t);
    setTimeout(function(){ t.style.opacity='1'; t.style.transform='translateX(0)'; }, 10);
    setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateX(30px)'; setTimeout(function(){ t.remove(); }, 300); }, 3000);
}

// ── 공통: confirm 대체 ──
function shvConfirm(title, msg, callback){
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;animation:cadFadeIn .15s;';
    ov.innerHTML = '<div style="background:#fff;border-radius:12px;padding:20px 24px;width:360px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.25);">'
        +'<div style="font-size:14px;font-weight:700;color:#1a2a40;margin-bottom:8px;">'+title+'</div>'
        +'<p style="font-size:13px;color:#5a6a80;margin-bottom:14px;">'+msg+'</p>'
        +'<div style="display:flex;justify-content:flex-end;gap:8px;">'
        +'<button style="padding:8px 18px;background:#f4f6f8;color:#5a6a80;border:1px solid #e0e4ea;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;" onclick="this.closest(\'div[style*=fixed]\').remove()">취소</button>'
        +'<button style="padding:8px 18px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;" id="cadConfirmOk">확인</button>'
        +'</div></div>';
    document.body.appendChild(ov);
    ov.querySelector('#cadConfirmOk').addEventListener('click', function(){ ov.remove(); if(callback) callback(); });
}

<?php if($isDemo): ?>
// ── 데모 모드: 현장연결 차단 + 민감정보 가림 ──
(function(){
    // openModal 오버라이드: 현장연결 모달 차단
    var _origOpenModal = window.openModal;
    window.openModal = function(id){
        if(id === 'siteConnModal') return; // 데모 모드에서 현장연결 차단
        if(_origOpenModal) _origOpenModal(id);
    };
    // 자동 현장연결 팝업도 차단
    window.showSiteConnectedPopup = function(){};
    // 데모 도면 자동 로드
    setTimeout(function(){
        var apiUrl = (window._CAD_API||'cad.php?api=') + 'load&id=demo_drawing&site_no=TEST-001';
        fetch(apiUrl).then(function(r){return r.json();}).then(function(r){
            var d = r.drawing || (r.data && r.data.drawing) || r;
            if(d && d.objects){ objects=d.objects; }
            if(d && d.layers){ layers=d.layers; }
            if(d && d.scale){ scale=d.scale; }
            if(d && d.unit){ unit=d.unit; }
            // layer 이름 → layerId 매핑 (DXF 가져오기 파일 호환)
            var nameMap = {};
            layers.forEach(function(l){ nameMap[l.name] = l.id; });
            objects.forEach(function(o){
                if(o.layerId === undefined && o.layer !== undefined){
                    if(nameMap[o.layer] !== undefined){
                        o.layerId = nameMap[o.layer];
                    } else {
                        // 레이어 없으면 첫번째 레이어에 배정
                        o.layerId = layers.length ? layers[0].id : 0;
                    }
                }
            });
            // 유의미한 line 객체 기준으로 중심 계산 후 전체 이동
            var xs=[],ys=[];
            objects.forEach(function(o){
                if(o.type==='line'){
                    var len=Math.sqrt(Math.pow(o.x2-o.x1,2)+Math.pow(o.y2-o.y1,2));
                    if(len>5){xs.push(o.x1);xs.push(o.x2);ys.push(o.y1);ys.push(o.y2);}
                }
            });
            if(xs.length){
                var cx=(Math.min.apply(null,xs)+Math.max.apply(null,xs))/2;
                var cy=(Math.min.apply(null,ys)+Math.max.apply(null,ys))/2;
                objects.forEach(function(o){
                    if(o.x!==undefined){o.x-=cx;o.y-=cy;}
                    if(o.x1!==undefined){o.x1-=cx;o.x2-=cx;o.y1-=cy;o.y2-=cy;}
                });
            }
            if(typeof renderLayerList==='function') renderLayerList();
            // 유의미한 line 기준 뷰포트 직접 계산 (fitAll은 빈텍스트 때문에 줌이 작아짐)
            var lxs=[],lys=[];
            objects.forEach(function(o){
                if(o.type==='line'){
                    var len=Math.sqrt(Math.pow(o.x2-o.x1,2)+Math.pow(o.y2-o.y1,2));
                    if(len>5){lxs.push(o.x1);lxs.push(o.x2);lys.push(o.y1);lys.push(o.y2);}
                }
            });
            if(lxs.length){
                var lx1=Math.min.apply(null,lxs),lx2=Math.max.apply(null,lxs);
                var ly1=Math.min.apply(null,lys),ly2=Math.max.apply(null,lys);
                var dw=lx2-lx1||1, dh=ly2-ly1||1;
                var cw=canvas.width, ch=canvas.height;
                var pad=80;
                viewZoom=Math.min((cw-pad*2)/dw,(ch-pad*2)/dh);
                var ccx=(lx1+lx2)/2, ccy=(ly1+ly2)/2;
                viewX=cw/2-ccx*viewZoom;
                viewY=ch/2-ccy*viewZoom;
                if(typeof render==='function') render();
            } else {
                if(typeof fitAll==='function') fitAll();
            }
            if(typeof updateStatus==='function') updateStatus();
            // 도움말 모달 자동 표시
            setTimeout(function(){
                // 데모 안내 문구 삽입
                var aboutBody = document.querySelector('#aboutModal .modal');
                if(aboutBody){
                    var existing = document.getElementById('demoGuideMsg');
                    if(!existing){
                        var msg = document.createElement('div');
                        msg.id = 'demoGuideMsg';
                        msg.style.cssText = 'margin-top:12px;padding:10px 14px;background:rgba(0,170,255,0.08);border:1px solid rgba(0,170,255,0.2);border-radius:6px;';
                        msg.innerHTML = '<div style="font-size:12px;color:#00aaff;font-weight:600;margin-bottom:4px;">Demo Guide</div><div style="font-size:11px;color:#8ab4d4;line-height:1.6;">데모 도면이 생성되어 있습니다.<br>도면을 지우고 자유롭게 사용해 보세요.</div>';
                        var btns = aboutBody.querySelector('.modalBtns');
                        if(btns) aboutBody.insertBefore(msg, btns);
                    }
                }
                if(typeof openModal==='function') openModal('aboutModal');
            }, 300);
        }).catch(function(){});
    }, 500);
})();
<?php endif; ?>
</script>
<?php /* cadSidebar flex wrapper 제거됨 */ ?>

<!-- ── 데모토큰 관리 모달 ── -->
<?php if($CAD_CURRENT_USER['level'] >= 4): ?>
<div id="demoTokenModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
<style>#demoTokenModal.open{display:flex !important;}</style>
<div style="background:#0d1828;border:1px solid #1b3354;border-radius:10px;width:620px;max-width:95vw;max-height:85vh;overflow:auto;box-shadow:0 8px 40px rgba(0,0,0,0.7);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #1b3354;">
        <h3 style="margin:0;color:#d0e8ff;font-size:15px;">🔗 데모 링크 관리</h3>
        <button onclick="document.getElementById('demoTokenModal').classList.remove('open')" style="background:none;border:none;color:#5a7a9a;font-size:18px;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:16px 18px;">
        <!-- 토큰 생성 폼 -->
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;">
            <div style="flex:1;min-width:120px;">
                <label style="display:block;color:#8ab4d4;font-size:11px;margin-bottom:3px;">사용자명</label>
                <input id="dtName" type="text" value="Demo User" style="width:100%;padding:6px 10px;background:#111e30;border:1px solid #1b3354;border-radius:5px;color:#d0e8ff;font-size:12px;outline:none;">
            </div>
            <div style="min-width:90px;">
                <label style="display:block;color:#8ab4d4;font-size:11px;margin-bottom:3px;">권한</label>
                <select id="dtLevel" style="width:100%;padding:6px 8px;background:#111e30;border:1px solid #1b3354;border-radius:5px;color:#d0e8ff;font-size:12px;outline:none;">
                    <option value="0">게스트(보기만)</option>
                    <option value="1">뷰어(+내보내기)</option>
                    <option value="2" selected>작성자(+편집)</option>
                    <option value="3">검수자</option>
                    <option value="4">관리자</option>
                </select>
            </div>
            <div style="min-width:130px;">
                <label style="display:block;color:#8ab4d4;font-size:11px;margin-bottom:3px;">만료일 (선택)</label>
                <input id="dtExpires" type="date" style="width:100%;padding:6px 10px;background:#111e30;border:1px solid #1b3354;border-radius:5px;color:#d0e8ff;font-size:12px;outline:none;">
            </div>
            <button id="btnCreateToken" style="padding:6px 16px;background:#00aaff;border:none;border-radius:5px;color:#fff;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">+ 토큰 생성</button>
        </div>
        <!-- 토큰 목록 -->
        <div id="dtList" style="font-size:12px;color:#8ab4d4;"></div>
    </div>
</div>
</div>
<script>
(function(){
    var PS='<?php echo htmlspecialchars(basename($_SERVER["PHP_SELF"])); ?>';
    var lvLabels={0:'게스트',1:'뷰어',2:'작성자',3:'검수자',4:'관리자',5:'시스템관리자'};
    var lvColors={0:'#888',1:'#5a7a9a',2:'#00aaff',3:'#ffaa00',4:'#ff4466',5:'#aa66ff'};

    function loadTokens(){
        fetch(PS+'?api=demo_tokens').then(r=>r.json()).then(d=>{
            var el=document.getElementById('dtList');
            if(!d.ok||!d.data||!d.data.length){el.innerHTML='<div style="text-align:center;color:#3a5a7a;padding:20px;">생성된 데모 토큰이 없습니다</div>';return;}
            var h='<table style="width:100%;border-collapse:collapse;">';
            h+='<tr style="border-bottom:1px solid #1b3354;color:#5a7a9a;font-size:11px;"><th style="text-align:left;padding:6px;">사용자명</th><th>권한</th><th>만료</th><th>생성일</th><th>생성자</th><th style="text-align:center;">링크</th><th></th></tr>';
            d.data.forEach(function(t){
                var expired=t.expires&&t.expires<new Date().toISOString().slice(0,10);
                var url=location.origin+location.pathname.replace(/[^\/]*$/,'')+'cad.php?demo='+encodeURIComponent(t.token);
                h+='<tr style="border-bottom:1px solid #111e30;'+(expired?'opacity:0.4':'')+'">';
                h+='<td style="padding:6px;">'+esc(t.name)+'</td>';
                h+='<td style="text-align:center;"><span style="background:'+(lvColors[t.level]||'#888')+';color:#fff;padding:1px 8px;border-radius:3px;font-size:10px;">'+(lvLabels[t.level]||'Lv.'+t.level)+'</span></td>';
                h+='<td style="text-align:center;font-size:11px;">'+(t.expires||'<span style="color:#3a5a7a">무제한</span>')+(expired?' <span style="color:#ff4466;font-size:10px;">만료</span>':'')+'</td>';
                h+='<td style="text-align:center;font-size:11px;color:#5a7a9a;">'+(t.created||'').slice(0,10)+'</td>';
                h+='<td style="text-align:center;font-size:11px;color:#5a7a9a;">'+esc(t.created_by||'')+'</td>';
                h+='<td style="text-align:center;"><button onclick="copyDemoUrl(\''+esc(url)+'\')" style="background:#0077cc;border:none;color:#fff;padding:2px 10px;border-radius:3px;cursor:pointer;font-size:10px;" title="'+esc(url)+'">복사</button></td>';
                h+='<td><button onclick="delDemoToken(\''+esc(t.token)+'\')" style="background:#ff4466;border:none;color:#fff;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:10px;">삭제</button></td>';
                h+='</tr>';
            });
            h+='</table>';
            el.innerHTML=h;
        });
    }
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

    document.getElementById('btnCreateToken').addEventListener('click',function(){
        var name=document.getElementById('dtName').value.trim();
        var level=document.getElementById('dtLevel').value;
        var expires=document.getElementById('dtExpires').value;
        if(!name){if(typeof shvToast==='function')shvToast('사용자명을 입력하세요','warn');return;}
        fetch(PS+'?api=demo_token_save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:'__AUTO__',name:name,level:parseInt(level),expires:expires})})
        .then(r=>r.json()).then(d=>{
            if(d.ok){
                loadTokens();
                if(typeof shvToast==='function')shvToast('데모 토큰 생성 완료','ok');
                // 자동 복사
                if(d.url) copyDemoUrl(d.url);
            } else {
                if(typeof shvToast==='function')shvToast(d.msg||'생성 실패','err');
            }
        });
    });

    window.copyDemoUrl=function(url){
        var ta=document.createElement('textarea');
        ta.value=url;ta.style.cssText='position:fixed;left:-9999px';
        document.body.appendChild(ta);ta.select();
        try{document.execCommand('copy');if(typeof shvToast==='function')shvToast('링크가 클립보드에 복사되었습니다','ok');}
        catch(e){if(typeof showToast==='function')showToast('복사 실패 - 링크: '+url,'warn');}
        document.body.removeChild(ta);
    };
    window.delDemoToken=function(token){
        shvConfirm('데모 토큰 삭제','이 데모 토큰을 삭제하시겠습니까?',function(){
            fetch(PS+'?api=demo_token_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:token})})
            .then(r=>r.json()).then(function(){loadTokens();});
        });
    };

    // 모달 열릴 때 목록 로드
    var mo=new MutationObserver(function(){
        if(document.getElementById('demoTokenModal').classList.contains('open')) loadTokens();
    });
    mo.observe(document.getElementById('demoTokenModal'),{attributes:true,attributeFilter:['class']});
})();
</script>
<?php endif; ?>

</body>
</html>
