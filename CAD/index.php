<?php
if(isset($_GET['api'])){
    // API는 최신 로직(cad.php)으로 위임해 중복 유지보수를 제거한다.
    require_once __DIR__ . '/cad.php';
    exit;
}

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

// 로그인 체크: ERP 토큰 자동 로그인 또는 세션 체크
if(isset($_GET['token']) && !isset($_SESSION['cad_user'])){
    $_GET['action'] = 'erp_auth';
    $_POST['token'] = $_GET['token'];
    ob_start();
    include __DIR__ . '/dist/login.php';
    ob_end_clean();
}
if(!isset($_SESSION['cad_user'])){
    header('Location: login.php');
    exit;
}
$CAD_CURRENT_USER = $_SESSION['cad_user'];

define('SAVE_DIR', __DIR__ . '/cad_saves/');
if(!is_dir(SAVE_DIR)) mkdir(SAVE_DIR, 0755, true);

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
    <!-- V2 Design Tokens + CAD Styles -->
    <link rel="stylesheet" href="../css/v2/tokens.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div id="ppOverlay"><div id="ppBox"><div class="ppHead"><h2>SHV WebCAD</h2><div class="ppBtns"><button class="ppBtn" id="btnPPDwg" style="background:#2a6;"><i class="fa fa-upload"></i> DWG/DXF 열기</button><button class="ppBtn" id="btnPPNew">+ New</button><button class="ppBtnSec" id="btnPPClose">Close</button></div></div><div id="ppList"><div id="ppEmpty">No saved drawings.</div></div></div></div>
<input type="file" id="dwgFileInput" accept=".dwg,.dxf" style="display:none">
<div id="sdOverlay"><div id="sdBox"><h3>Save Drawing</h3><input type="text" id="sdTitleInp" placeholder="Name..." value="untitled"><div style="margin:8px 0;display:flex;align-items:center;gap:8px;"><label style="color:#aaa;font-size:12px;">포맷:</label><select id="sdFormatSel" style="flex:1;padding:4px 8px;background:#1a2035;color:#e0e0e0;border:1px solid #2a3a5a;border-radius:4px;font-size:13px;"><option value="dwg" selected>DWG (AutoCAD)</option><option value="dxf">DXF</option><option value="json">JSON (서버 저장)</option></select></div><div class="sdBtns"><button class="sdCancel" id="btnSdCancel">Cancel</button><button class="sdOk" id="btnSdOk">Save</button></div></div></div>


<!-- TOP BAR -->
<div id="topbar">
    <div class="topMenu">
        <span class="logo" data-menu="menuLogo" style="cursor:pointer"><span style="color:#00aaff;font-weight:800">SHV</span> <span style="color:#d0e8ff;font-weight:400">Smart</span><span style="color:#00ffcc;font-weight:700">CAD</span></span>
        <div class="topDropdown" id="menuLogo">
            <div class="ddItem" data-fn="openModal" data-arg="settingsModal">⚙ 설정</div>
            <div class="ddItem" data-fn="openModal" data-arg="pointerModal">🖱 포인터 설정</div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="openModal" data-arg="shortcutModal">⌨ 단축키 목록</div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="openModal" data-arg="aboutModal">ℹ SHVCAD 정보</div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu0">파일</button>
        <div class="topDropdown" id="menu0">
            <div class="ddItem" data-fn="newFile">새 도면 <span class="key">Ctrl+N</span></div>
            <div class="ddItem" onclick="document.getElementById('dwgFileInput').value='';document.getElementById('dwgFileInput').click();">열기 (DWG/DXF) <span class="key">Ctrl+O</span></div>
            <div class="ddItem" data-fn="openFile">서버 도면 열기</div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="saveJSON">저장 <span class="key">Ctrl+S</span></div>
            <div class="ddSep"></div>
            <div class="ddItem" onclick="if(typeof saveDWG==='function')saveDWG();">내보내기 DWG</div>
            <div class="ddItem" data-fn="saveDXF">내보내기 DXF</div>
            <div class="ddItem" data-fn="saveSVG">내보내기 SVG</div>
            <div class="ddItem" data-fn="savePNG">내보내기 PNG</div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu1">편집</button>
        <div class="topDropdown" id="menu1">
            <div class="ddItem" data-fn="undo">실행취소 <span class="key">Ctrl+Z</span></div>
            <div class="ddItem" data-fn="redo">다시실행 <span class="key">Ctrl+Y</span></div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="copySelected">복사 <span class="key">Ctrl+C</span></div>
            <div class="ddItem" data-fn="pasteObjects">붙여넣기 <span class="key">Ctrl+V</span></div>
            <div class="ddItem" data-fn="deleteSelected">삭제 <span class="key">Del</span></div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="bringToFront">맨 앞으로 <span class="key">Ctrl+]</span></div>
            <div class="ddItem" data-fn="sendToBack">맨 뒤로 <span class="key">Ctrl+[</span></div>
            <div class="ddItem" data-fn="bringForward">한 단계 앞</div>
            <div class="ddItem" data-fn="sendBackward">한 단계 뒤</div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="selectAll">전체 선택 <span class="key">Ctrl+A</span></div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu2">보기</button>
        <div class="topDropdown" id="menu2">
            <div class="ddItem" data-fn="fitAll">전체 화면 맞춤 <span class="key">Ctrl+Shift+F</span></div>
            <div class="ddItem" data-fn="zoomIn">줌 인 <span class="key">+</span></div>
            <div class="ddItem" data-fn="zoomOut">줌 아웃 <span class="key">-</span></div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="toggleGrid">격자 표시/숨김 <span class="key">G</span></div>
            <div class="ddItem" data-fn="toggleSnap">스냅 <span class="key">F3</span></div>
            <div class="ddItem" data-fn="toggleOrtho">직교모드 <span class="key">F8</span></div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu3">출력</button>
        <div class="topDropdown" id="menu3">
            <div class="ddItem" data-fn="openPrintModal" data-arg="all">PDF 전체 도면</div>
            <div class="ddItem" data-fn="openPrintModal" data-arg="view">PDF 현재 화면</div>
            <div class="ddItem" data-fn="openPrintModal" data-arg="select">PDF 영역 선택 <span class="key">Ctrl+P</span></div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu_insert">삽입</button>
        <div class="topDropdown" id="menu_insert">
            <div class="ddItem" data-fn="openImportBg">배경 이미지 (PNG/JPG)</div>
            <div class="ddItem" data-fn="openImportBg">배경 PDF</div>
            <div class="ddSep"></div>
            <div class="ddItem" data-fn="xlsxClick">Excel 표 (CSV)</div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu5">도구</button>
        <div class="topDropdown" id="menu5">
            <div class="ddItem" data-fn="openModal" data-arg="pointerModal">포인터 설정</div>
        </div>
    </div>

    <div class="topMenu">
        <button class="topMenuBtn" data-menu="menu4">도움말</button>
        <div class="topDropdown" id="menu4">
            <div class="ddItem" data-fn="openModal" data-arg="shortcutModal">단축키 목록 <span class="key">F1</span></div>
        </div>
    </div>

    <div class="spacer"></div>

    <div id="siteInfo" style="display:flex;align-items:center;gap:14px;font-size:11px;color:var(--textB);white-space:nowrap;">
        <span>현장번호 : <b id="siteNo">N12345</b></span>
        <span>현장명 : <b id="siteName">대영건설테스트 아파트 조합</b></span>
        <button data-fn="openModal" data-arg="siteConnModal" style="font-size:10px;padding:3px 10px;margin-left:6px;background:#00aaff;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:700;letter-spacing:0.5px;box-shadow:0 0 8px rgba(0,170,255,0.5);">현장연결</button>
    </div>

    <div class="spacer"></div>

    <button class="topBtn" id="btnDarkMode" data-fn="toggleDarkMode" title="다크/라이트 모드" style="font-size:14px;padding:2px 8px;">🌙</button>

    <button class="topBtn" data-fn="undo" title="실행취소 (Ctrl+Z)" style="font-size:14px;padding:2px 6px;">◀</button>
    <button class="topBtn" data-fn="redo" title="다시실행 (Ctrl+Y)" style="font-size:14px;padding:2px 6px;">▶</button>

    <select class="scaleSelect" id="scaleSelect" data-change="setScale" title="축척">
        <option value="1" selected>1:1</option>
        <option value="50">1:50</option>
        <option value="100">1:100</option>
        <option value="200">1:200</option>
        <option value="500">1:500</option>
    </select>

    <select class="unitSelect" id="unitSelect" data-change="setUnit" title="단위">
        <option value="mm">mm</option>
        <option value="cm">cm</option>
        <option value="m">m</option>
    </select>

    <?php
    $photoUrl = !empty($CAD_CURRENT_USER['photo']) ? $CAD_CURRENT_USER['photo'] : "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='8' r='4' fill='%235588aa'/%3E%3Cpath d='M4 20c0-4 3.6-7 8-7s8 3 8 7' fill='%235588aa'/%3E%3C/svg%3E";
    ?>
    <img id="userPhoto" src="<?php echo htmlspecialchars($photoUrl); ?>" style="width:22px;height:22px;border-radius:50%;border:1px solid var(--border);margin-left:6px;object-fit:cover" title="<?php echo htmlspecialchars($CAD_CURRENT_USER['name']); ?>">
    <span style="color:var(--textB);font-size:10px;margin:0 4px"><?php echo htmlspecialchars($CAD_CURRENT_USER['name']); ?></span>
    <button onclick="location.href='dist/login.php?action=logout'" style="background:#ff4466;border:none;color:#fff;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:10px;font-weight:600;">로그아웃</button>
</div>

<!-- RIBBON TABS -->
<div id="ribbonTabs">
    <button class="ribTabBtn active" data-ribtab="ribHome">홈</button>
    <button class="ribTabBtn" data-ribtab="ribInsert">삽입</button>
    <button class="ribTabBtn" data-ribtab="ribAnnot">주석</button>
    <button class="ribTabBtn" data-ribtab="ribView">보기</button>
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
</div>

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
    <div id="rightPanelResize" style="width:4px;cursor:col-resize;background:transparent;flex-shrink:0;z-index:101" title="드래그로 너비 조절"></div>
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
                <div id="propInfo" style="color:var(--textD);font-size:11px;padding:4px 0">없음</div>
                <div class="propRow" id="propLenRow" style="display:none">
                    <label>길이</label>
                    <input type="number" class="propInput" id="prop_len" step="1" data-change="applyLength" title="길이 직접 수정">
                    <span style="color:var(--textD);font-size:10px" id="prop_lenUnit">mm</span>
                </div>
                <div class="propRow" id="propAreaRow" style="display:none">
                    <label>면적</label>
                    <span id="prop_area" style="color:var(--textB);font-size:11px"></span>
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
                    <input type="range" id="prop_alpha" min="0" max="1" step="0.05" value="1" style="flex:1" data-change="applyProps">
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
                <div style="display:flex;gap:6px;flex-wrap:wrap">
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
                    <input type="range" id="prop_bubbleAlpha" min="0" max="1" step="0.05" value="0.9" style="flex:1" data-change="applyAnnotProps">
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
                <div class="propLabel" style="display:flex;align-items:center;justify-content:space-between">코멘트 <button onclick="popoutPanel('comment')" style="background:none;border:none;color:var(--textD);cursor:pointer;font-size:11px" title="별도 창으로">⧉</button></div>
                <div id="commentContent">
                    <textarea id="versionComment" placeholder="코멘트 입력..." style="width:100%;height:50px;background:var(--panel2);border:1px solid var(--border);color:var(--textB);font-size:11px;padding:6px 8px;border-radius:4px;resize:vertical;font-family:sans-serif;outline:none"></textarea>
                    <button class="propBtn propBtnFull" style="margin-top:4px" data-fn="addVersionComment">💬 코멘트 저장</button>
                    <div id="commentList" style="max-height:120px;overflow-y:auto;margin-top:6px"></div>
                </div>
            </div>
            <div class="propGroup">
                <div class="propLabel" style="display:flex;align-items:center;justify-content:space-between">버전 히스토리 <button onclick="popoutPanel('version')" style="background:none;border:none;color:var(--textD);cursor:pointer;font-size:11px" title="별도 창으로">⧉</button></div>
                <div id="versionContent">
                    <div id="versionList" style="max-height:150px;overflow-y:auto"></div>
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
    <span style="flex:1"></span>
    <button class="stateBtn" id="btnSnap" data-fn="toggleSnap" title="스냅 [F3]">
        <span class="sbIcon">⊹</span> 스냅 <span class="sbState" id="sSnap">ON</span>
    </button>
    <button class="stateBtn off" id="btnOrtho" data-fn="toggleOrtho" title="직교 [F8]">
        <span class="sbIcon">⊢</span> 직교 <span class="sbState" id="sOrtho">OFF</span>
    </button>
</div>

<!-- 명령 히스토리 패널 (5줄 고정, 스크롤) -->
<div id="cmdHistPanel" style="display:none;height:95px;overflow-y:auto;background:#0a1020;border:1px solid #1a3050;font-family:monospace;font-size:11px;flex-shrink:0;">
</div>

<!-- 명령창 -->
<div id="cmdBar">
    <span id="cmdPrompt">명령:</span>
    <div id="cmdInputWrap">
        <input id="cmdInput" type="text" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="명령어 입력...">
        <div id="cmdDropdown"></div>
    </div>
    <div id="cmdHistory"></div>
    <button id="cmdHistBtn" style="background:none;border:1px solid #1a3050;color:#5588aa;font-size:10px;padding:2px 8px;cursor:pointer;border-radius:3px;white-space:nowrap;" title="명령 히스토리">▲ 기록</button>
</div>

<!-- 현장연결 Modal -->
<div class="modalOverlay" id="siteConnModal">
    <div class="modal modal-lg" style="display:flex;flex-direction:column;padding:0;overflow:hidden">
        <div class="modalTitle" style="padding:14px 20px;margin:0;border-bottom:1px solid var(--border)">현장 연결</div>
        <!-- 검색 단계 -->
        <!-- 1단계: 검색 -->
        <div id="siteStep1" style="flex:1;display:flex;flex-direction:column;padding:16px">
            <div class="modalRow">
                <label>검색</label>
                <input type="text" class="modalInput" id="siteConnSearch" placeholder="현장번호 또는 현장명 입력 후 Enter" style="flex:1">
                <button data-fn="filterSiteList" style="background:var(--accent);border:none;color:#fff;padding:6px 10px;border-radius:5px;cursor:pointer;font-size:14px" title="검색">🔍</button>
            </div>
            <div id="siteConnList" style="flex:1;max-height:280px;overflow-y:auto;margin:8px 0;border:1px solid var(--border);border-radius:5px"></div>
        </div>
        <!-- 1.5단계: 도면 목록 -->
        <div id="siteStepDrawings" style="flex:1;display:none;flex-direction:column;padding:16px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border)">
                <button id="siteDrawBackBtn" style="background:none;border:1px solid var(--border);color:var(--textB);padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px">◀ 다시 검색</button>
                <span style="font-size:13px;font-weight:700;color:var(--accent)" id="siteDrawInfo"></span>
            </div>
            <div style="font-size:11px;color:var(--textD);margin-bottom:8px">이 현장에 저장된 도면이 있습니다. 열기 또는 새로 작성하세요.</div>
            <div id="siteDrawListPanel" style="flex:1;max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:5px;margin-bottom:10px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button id="siteDrawNewBtn" style="background:var(--accent);border:none;color:#fff;padding:6px 16px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600">+ 새로 작성</button>
            </div>
        </div>
        <!-- 세팅 단계 -->
        <div id="siteStep2" style="flex:1;display:none;padding:16px;overflow-y:auto">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)">
                <button id="siteBackBtn" style="background:none;border:1px solid var(--border);color:var(--textB);padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px">◀ 다시 검색</button>
                <span style="font-size:13px;font-weight:700;color:var(--accent)" id="siteSelInfo"></span>
            </div>
            <!-- 현장 정보 (읽기 전용) -->
            <div style="background:var(--panel2);border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
                <div style="font-size:10px;color:var(--textD);letter-spacing:0.5px;margin-bottom:6px">현장 정보</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px">
                    <div class="modalRow"><label>현장번호</label><input type="text" class="modalInput" id="siteSetNo" readonly style="color:var(--accent);font-weight:700;background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>현장명</label><input type="text" class="modalInput" id="siteSetName" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>주소</label><input type="text" class="modalInput" id="siteSetAddr" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>담당자</label><input type="text" class="modalInput" id="siteSetManager" value="-" readonly style="background:transparent;border-color:transparent"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px 14px;margin-top:6px">
                    <div class="modalRow"><label>발주담당</label><input type="text" class="modalInput" id="siteSetOrder" value="-" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>고객명</label><input type="text" class="modalInput" id="siteSetClient" value="-" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>현장유형</label><input type="text" class="modalInput" id="siteSetType" value="-" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>총수량</label><input type="text" class="modalInput" id="siteSetQty" value="-" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>착공일</label><input type="text" class="modalInput" id="siteSetStart" value="-" readonly style="background:transparent;border-color:transparent"></div>
                    <div class="modalRow"><label>준공일</label><input type="text" class="modalInput" id="siteSetEnd" value="-" readonly style="background:transparent;border-color:transparent"></div>
                </div>
            </div>
            <!-- 도면 설정 (수정 가능) -->
            <div style="background:var(--bg);border:1px solid var(--accent2);border-radius:6px;padding:12px;margin-bottom:12px">
                <div style="font-size:10px;color:var(--accent);letter-spacing:0.5px;margin-bottom:6px">도면 설정</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px">
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
                        <select class="modalSelect" id="siteSetSaveType1" style="flex:1">
                            <option value="dxf" selected>📐 DXF</option>
                            <option value="">없음</option>
                            <option value="svg">🖼 SVG</option>
                            <option value="png">📸 PNG</option>
                            <option value="pdf">📄 PDF</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>저장 옵션2</label>
                        <select class="modalSelect" id="siteSetSaveType2" style="flex:1">
                            <option value="" selected>없음</option>
                            <option value="dxf">📐 DXF</option>
                            <option value="svg">🖼 SVG</option>
                            <option value="png">📸 PNG</option>
                            <option value="pdf">📄 PDF</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>저장 옵션3</label>
                        <select class="modalSelect" id="siteSetSaveType3" style="flex:1">
                            <option value="" selected>없음</option>
                            <option value="dxf">📐 DXF</option>
                            <option value="svg">🖼 SVG</option>
                            <option value="png">📸 PNG</option>
                            <option value="pdf">📄 PDF</option>
                        </select>
                    </div>
                    <div class="modalRow"><label>작성파일명</label>
                        <input type="text" class="modalInput" id="siteSetFileName" readonly style="color:var(--accent);font-weight:600;background:transparent;border-color:var(--border);flex:1" value="">
                    </div>
                </div>
                <!-- 작업범위 -->
                <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--border)">
                    <div style="font-size:10px;color:var(--accent);letter-spacing:0.5px;margin-bottom:6px">작업범위</div>
                    <!-- 배선 테이블 -->
                    <div style="font-size:10px;color:var(--textD);letter-spacing:0.5px;margin-bottom:4px">배선</div>
                    <div style="border:1px solid var(--border);border-radius:4px;overflow:hidden">
                        <table style="width:100%;border-collapse:collapse;font-size:11px">
                            <thead>
                                <tr style="background:var(--panel2)">
                                    <th style="padding:5px 8px;text-align:left;color:var(--textD);border-bottom:1px solid var(--border);width:35px">No</th>
                                    <th style="padding:5px 8px;text-align:left;color:var(--textD);border-bottom:1px solid var(--border)">품목명</th>
                                    <th style="padding:5px 8px;text-align:center;color:var(--textD);border-bottom:1px solid var(--border);width:55px">단위</th>
                                    <th style="padding:5px 8px;text-align:right;color:var(--textD);border-bottom:1px solid var(--border);width:65px">수량</th>
                                    <th style="padding:5px 8px;text-align:center;color:var(--textD);border-bottom:1px solid var(--border);width:50px">산출</th>
                                    <th style="padding:5px 8px;text-align:center;color:var(--textD);border-bottom:1px solid var(--border);width:35px"></th>
                                </tr>
                            </thead>
                            <tbody id="wireTableBody">
                                <tr>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);color:var(--textD)">1</td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="text" class="modalInput" style="padding:2px 6px;font-size:11px" value="HIV 2.5mm" data-wire-name></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><select class="modalSelect" style="padding:2px 4px;font-size:10px;width:48px" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="number" class="modalInput" style="padding:2px 6px;font-size:11px;text-align:right;width:55px" value="150" data-wire-qty></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><input type="radio" name="wireCalc" value="0" checked title="산출연동"></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><span style="color:var(--danger);cursor:pointer;font-size:13px" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);color:var(--textD)">2</td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="text" class="modalInput" style="padding:2px 6px;font-size:11px" value="CV 4mm" data-wire-name></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><select class="modalSelect" style="padding:2px 4px;font-size:10px;width:48px" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="number" class="modalInput" style="padding:2px 6px;font-size:11px;text-align:right;width:55px" value="80" data-wire-qty></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><input type="radio" name="wireCalc" value="1" title="산출연동"></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><span style="color:var(--danger);cursor:pointer;font-size:13px" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);color:var(--textD)">3</td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="text" class="modalInput" style="padding:2px 6px;font-size:11px" value="FR-CV 6mm" data-wire-name></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><select class="modalSelect" style="padding:2px 4px;font-size:10px;width:48px" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="number" class="modalInput" style="padding:2px 6px;font-size:11px;text-align:right;width:55px" value="200" data-wire-qty></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><input type="radio" name="wireCalc" value="2" title="산출연동"></td>
                                    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><span style="color:var(--danger);cursor:pointer;font-size:13px" onclick="removeWireRow(this)">✕</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button onclick="addWireRow()" style="margin-top:6px;background:none;border:1px dashed var(--border);color:var(--textD);cursor:pointer;padding:4px 12px;border-radius:4px;font-size:10px;width:100%">+ 품목 추가</button>
                </div>
            </div>
            <!-- 저장된 도면 -->
            <div style="background:var(--panel2);border:1px solid var(--border);border-radius:6px;padding:12px">
                <div style="font-size:10px;color:var(--textD);letter-spacing:0.5px;margin-bottom:6px">저장된 도면</div>
                <div id="siteDrawingList" style="max-height:120px;overflow-y:auto;border:1px solid var(--border);border-radius:5px">
                    <div style="padding:10px;color:var(--textD);text-align:center">저장된 도면 없음</div>
                </div>
            </div>
        </div>
        <div class="modalBtns" style="padding:12px 20px;border-top:1px solid var(--border)">
            <button class="btnSecondary" data-fn="closeModal" data-arg="siteConnModal">닫기</button>
            <button class="btnPrimary" id="siteApplyBtn" data-fn="applySiteSettings" style="display:none">적용</button>
        </div>
    </div>
</div>

<!-- About Modal -->
<div class="modalOverlay" id="aboutModal">
    <div class="modal modal-sm" style="text-align:center">
        <div class="modalTitle">SHVCAD</div>
        <div style="padding:16px 0;color:var(--textB);font-size:13px;line-height:1.8">
            <div style="font-size:24px;font-weight:700;color:var(--accent);margin-bottom:8px">SHVCAD</div>
            <div>SHV ERP System v1.0</div>
            <div style="color:var(--textD);font-size:11px;margin-top:8px">© 2026 SH Vision. All rights reserved.</div>
            <div style="color:var(--textD);font-size:11px;margin-top:4px">No1@shv.kr</div>
        </div>
        <div class="modalBtns">
            <button class="btnPrimary" data-fn="closeModal" data-arg="aboutModal">닫기</button>
        </div>
    </div>
</div>

<!-- FOOTER -->
<div id="footer" style="height:22px;background:#0a0f1a;border-top:1px solid #1a2a40;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:10px;color:#4a6080;letter-spacing:0.5px;">
  © 2026 SH Vision. All rights reserved. &nbsp;|&nbsp; SHV ERP System v1.0
</div>

<!-- ── MODALS ── -->

<!-- Print Modal -->
<div class="modalOverlay" id="printModal">
    <div class="modal">
        <div class="modalTitle">📄 PDF 출력 설정</div>
        <div class="modalRow">
            <label>용지 크기</label>
            <select class="modalSelect" id="pPaper">
                <option value="A4">A4</option>
                <option value="A3" selected>A3</option>
                <option value="A2">A2</option>
                <option value="A1">A1</option>
            </select>
        </div>
        <div class="modalRow">
            <label>방향</label>
            <select class="modalSelect" id="pOrient">
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
            <label>주석 포함</label>
            <input type="checkbox" id="pAnnot" checked>
        </div>
        <div class="modalBtns">
            <button class="btnSecondary" data-fn="closeModal" data-arg="printModal">취소</button>
            <button class="btnPrimary" data-fn="doPrint">출력</button>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modalOverlay" id="settingsModal">
    <div class="modal modal-md" style="display:flex;flex-direction:column;padding:0;overflow:hidden">
        <div class="modalTitle" style="padding:14px 20px;margin:0;border-bottom:1px solid var(--border)">⚙ 설정</div>
        <div style="display:flex;flex:1;overflow:hidden">
            <!-- 사이드메뉴 -->
            <div id="setSide" style="width:140px;background:var(--panel2);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:8px 0;flex-shrink:0">
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
            <div style="flex:1;overflow-y:auto;padding:16px">
                <!-- 일반 -->
                <div class="setTabContent active" id="setGeneral">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
                        <div class="modalRow"><label>격자 간격(px)</label><input type="number" class="modalInput" id="setGrid" value="20" min="5" max="100"></div>
                        <div class="modalRow"><label>스냅 감도(px)</label><input type="number" class="modalInput" id="setSnap" value="10" min="2" max="30"></div>
                        <div class="modalRow"><label>기본 선 두께</label><input type="number" class="modalInput" id="setDefLW" value="1" min="0.5" max="10" step="0.5"></div>
                        <div class="modalRow"><label>기본 선 색상</label><input type="color" class="colorPicker" id="setDefColor" value="#ffffff"></div>
                    </div>
                </div>
                <!-- 치수 -->
                <div class="setTabContent" id="setDim">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
                        <div class="modalRow"><label>치수 폰트 크기</label><input type="number" class="modalInput" id="setDimFont" value="12" min="8" max="24"></div>
                        <div class="modalRow"><label>화살표 크기</label><input type="number" class="modalInput" id="setArrow" value="10" min="4" max="24"></div>
                        <div class="modalRow"><label>치수선 색상</label><input type="color" class="colorPicker" id="setDimColor" value="#88ff88"></div>
                    </div>
                </div>
                <!-- 포인터 -->
                <div class="setTabContent" id="setPointer">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
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
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
                        <div class="modalRow"><label>직교 모드 <span style="color:var(--textD);font-size:9px">[F8]</span></label><select class="modalSelect" id="setOrtho"><option value="0">OFF</option><option value="1">ON</option></select></div>
                        <div class="modalRow"><label>객체스냅 <span style="color:var(--textD);font-size:9px">[F3]</span></label><select class="modalSelect" id="setSnapMode"><option value="0">OFF</option><option value="1">ON</option></select></div>
                    </div>
                    <div style="margin:10px 0 4px;color:var(--textD);font-size:10px;letter-spacing:0.5px">스냅 종류 <span style="font-size:9px">(F3으로 전체 ON/OFF)</span></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
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
                    <div style="margin:10px 0 4px;color:var(--textD);font-size:10px;letter-spacing:0.5px">기타</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
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
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px">
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
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px">
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
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px">
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






<script>
    /* PHP -> JS 데이터 주입 (config.js 보다 먼저 로드) */
    window._CAD_API = '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?api=';
    window._CAD_INIT = <?php echo $jl; ?>;
    window._CAD_STATUS = <?php echo json_encode($CAD_STATUS); ?>;
    window._CAD_LEVELS = <?php echo json_encode($CAD_LEVELS); ?>;
    window._CAD_PERMISSIONS = <?php echo json_encode($CAD_PERMISSIONS); ?>;
    window._CAD_STATUS_EDIT_LEVEL = <?php echo json_encode($CAD_STATUS_EDIT_LEVEL); ?>;
    window._CAD_USER = <?php echo json_encode($CAD_CURRENT_USER); ?>;
    window._CAD_CSRF = '<?php echo htmlspecialchars(cadCsrfService()->issueToken(), ENT_QUOTES, "UTF-8"); ?>';
</script>
<script src="js/CAD_config.js"></script>
<script src="js/CAD_engine.js"></script>
<script src="js/CAD_ui.js"></script>
</body>
</html>
