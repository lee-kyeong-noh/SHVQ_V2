<?php
require_once __DIR__ . '/config/env.php';

/* Auth.php와 동일한 세션 설정 — SHVQSESSID 세션에서 인증 컨텍스트 읽기 */
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

/* ── CSRF 토큰 보장 (세션과 동일 토큰 → HTML 메타 태그로 전달) ── */
$_csrfKey = shvEnv('CSRF_TOKEN_KEY', '_csrf_token');
if (!isset($_SESSION[$_csrfKey]) || !is_string($_SESSION[$_csrfKey])) {
    $_SESSION[$_csrfKey] = bin2hex(random_bytes(32));
}
$_csrfToken = $_SESSION[$_csrfKey];

if (empty($_SESSION['auth']['user_pk'])) {
    header('Location: login.php');
    exit;
}
$userPk    = (int)$_SESSION['auth']['user_pk'];
$loginId   = (string)($_SESSION['auth']['login_id'] ?? '');
$roleLevel = (int)($_SESSION['auth']['role_level']  ?? 0);

/* 사용자 이름 + 프로필 사진 조회 */
$userName  = $loginId; // fallback
$userPhoto = '';       // 사진 없으면 빈 문자열 → 기본 아이콘 표시
try {
    $dbCfg = require __DIR__ . '/config/database.php';
    $dsn   = sprintf('sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=1;Encrypt=0',
        $dbCfg['host'], (int)($dbCfg['port'] ?? 1433), $dbCfg['database']);
    $db    = new PDO($dsn, $dbCfg['username'], $dbCfg['password']);
    $stmt  = $db->prepare('SELECT name FROM Tb_Users WHERE idx = :pk');
    $stmt->execute([':pk' => $userPk]);
    $row   = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['name'])) $userName = (string)$row['name'];
} catch (Throwable $e) { /* fallback */ }

/* 프로필 사진 조회 (Tb_Employee.member_photo, V1 DB 읽기 전용) */
try {
    $dbCfgV1 = require __DIR__ . '/config/database.php';
    $dsnV1   = sprintf('sqlsrv:Server=%s,%d;Database=CSM_C004732;TrustServerCertificate=1;Encrypt=0',
        $dbCfgV1['host'], (int)($dbCfgV1['port'] ?? 1433));
    $dbV1    = new PDO($dsnV1, $dbCfgV1['username'], $dbCfgV1['password']);
    $stmtP   = $dbV1->prepare(
        "SELECT TOP 1 e.member_photo
         FROM Tb_Users u
         INNER JOIN Tb_Employee e ON e.idx = u.employee_idx
         WHERE u.login_id = :login_id AND ISNULL(e.member_photo,'') <> ''"
    );
    $stmtP->execute([':login_id' => $loginId]);
    $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!empty($rowP['member_photo'])) {
        $userPhoto = 'https://img.shv.kr/employee/' . htmlspecialchars($rowP['member_photo'], ENT_QUOTES);
    }
} catch (Throwable $e) { /* 사진 없음 fallback */ }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($_csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>SH Vision Portal</title>
<link rel="icon" href="favicon.ico" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<?php
$_cssFiles = ['tokens','reset','glass','layout','components','utilities','responsive'];
foreach ($_cssFiles as $_f):
    $_v = @filemtime(__DIR__."/css/v2/{$_f}.css") ?: '1';
?>
<link rel="stylesheet" href="css/v2/<?= $_f ?>.css?v=<?= $_v ?>">
<?php endforeach; ?>
</head>
<body>

<!-- ══════════════════════════════════════
     TOPBAR
     ══════════════════════════════════════ -->
<header id="topbar" class="glass-strong">

    <!-- 햄버거 (태블릿/모바일) -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="메뉴 열기">
        <i class="fa fa-bars"></i>
    </button>

    <!-- 로고 -->
    <a href="index.php" class="top-logo">
        <span class="logo-sh">SH</span>
        <span class="logo-vision"> Vision</span>
        <span class="logo-portal"> Portal</span>
    </a>

    <!-- L0 네비게이션 -->
    <nav class="top-nav" id="topNav">
        <button class="top-menu-btn active" data-l0="fms">FMS</button>
        <button class="top-menu-btn" data-l0="pms">PMS</button>
        <button class="top-menu-btn" data-l0="bms">BMS</button>
        <button class="top-menu-btn" data-l0="ctm">도급</button>
        <button class="top-menu-btn" data-l0="grp">GRP</button>
        <button class="top-menu-btn" data-l0="mail">MAIL</button>
        <button class="top-menu-btn" data-l0="mat">MAT</button>
        <button class="top-menu-btn" data-l0="cad">CAD</button>
        <button class="top-menu-btn" data-l0="facility">시설</button>
        <button class="top-menu-btn" data-l0="api">도구</button>
        <button class="top-menu-btn" data-l0="manage">관리</button>
        <button class="top-menu-btn" data-l0="estimate">예정</button>
    </nav>

    <div class="top-spacer"></div>

    <!-- 우측 액션 -->
    <div class="top-right">
        <button class="top-icon-btn notif-bell" id="notifBell" title="알림">
            <i class="fa fa-bell-o"></i>
            <span class="notif-badge notif-badge-hidden" id="notifBellBadge">0</span>
        </button>

        <!-- 다크/라이트 모드 스위치 -->
        <label class="theme-switch" title="다크/라이트 모드">
            <input type="checkbox" id="themeToggle">
            <span class="switch-track">
                <span class="switch-thumb">
                    <i class="fa fa-sun-o icon-sun"></i>
                    <i class="fa fa-moon-o icon-moon"></i>
                </span>
            </span>
        </label>

        <div class="top-user">
            <?php if ($userPhoto): ?>
                <img class="user-photo" src="<?= $userPhoto ?>" alt="<?= htmlspecialchars($userName) ?>">
            <?php else: ?>
                <div class="user-photo-default"><i class="fa fa-user"></i></div>
            <?php endif; ?>
            <span class="user-name"><?= htmlspecialchars($userName) ?></span>
            <button class="top-icon-btn" id="btnLogout" title="로그아웃"><i class="fa fa-sign-out"></i></button>
        </div>
    </div>

</header>

<!-- ══════════════════════════════════════
     APP BODY
     ══════════════════════════════════════ -->
<div id="app-body">

    <!-- 사이드바 백드롭 (태블릿/모바일) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ── SIDEBAR ── -->
    <aside id="sidebar" class="glass-panel">

        <!-- FMS -->
        <nav class="side-section" id="sideFms">
            <div class="side-title"><i class="fa fa-address-book"></i> 고객관리</div>
            <a class="side-item" href="?r=member_head"><i class="fa fa-building-o"></i> 본사관리</a>
            <a class="side-item" href="?r=member_branch"><i class="fa fa-map-marker"></i> 사업장관리</a>
            <a class="side-item" href="?r=member_branch&employee=me"><i class="fa fa-user"></i> 내담당사업장</a>
            <a class="side-item" href="?r=member_planned"><i class="fa fa-calendar-o"></i> 예정사업장</a>
            <a class="side-item" href="?r=site_new"><i class="fa fa-search"></i> 현장조회</a>
            <a class="side-item" href="?r=site_new&employee=me"><i class="fa fa-user-circle-o"></i> 내담당현장</a>
            <a class="side-item" href="?r=member_settings"><i class="fa fa-cog"></i> 고객관리설정</a>

            <div class="side-title"><i class="fa fa-th-list"></i> PJT</div>
            <a class="side-item" href="?r=project_dashboard"><i class="fa fa-tachometer"></i> PJT 현황</a>
            <a class="side-item" href="?r=pjt_todo"><i class="fa fa-check-square-o"></i> 해야할 PJT</a>
            <a class="side-item" href="?r=pjt_calendar_v2"><i class="fa fa-calendar"></i> PJT 캘린더</a>
            <a class="side-item" href="?r=project_main"><i class="fa fa-folder-open-o"></i> 전체 프로젝트</a>
            <a class="side-item" href="?r=project_schedule"><i class="fa fa-clock-o"></i> PJT 일정</a>

            <div class="side-title"><i class="fa fa-tasks"></i> 업무활동</div>
            <a class="side-item" href="?r=calendar_end"><i class="fa fa-flag-o"></i> 해야할업무</a>
            <a class="side-item" href="?r=calendar_log"><i class="fa fa-history"></i> 업무이력</a>
            <a class="side-item" href="?r=walkdownList"><i class="fa fa-street-view"></i> 실사현황</a>

            <div class="side-title"><i class="fa fa-file-text-o"></i> 업무보고</div>
            <a class="side-item" href="?r=task_report_my"><i class="fa fa-user-o"></i> 내업무보고</a>
            <a class="side-item" href="?r=task_report_recv"><i class="fa fa-inbox"></i> 받은업무보고</a>
            <a class="side-item" href="?r=task_report_all"><i class="fa fa-list-alt"></i> 업무보고현황</a>
            <a class="side-item" href="?r=task_activity_stats"><i class="fa fa-bar-chart"></i> 업무활동현황</a>

            <div class="side-title"><i class="fa fa-compass"></i> SRM</div>
            <a class="side-item" href="?r=site_srm"><i class="fa fa-building"></i> 현장정보</a>
            <a class="side-item" href="?r=srm_order"><i class="fa fa-file-text-o"></i> 도급지시서</a>
            <a class="side-item" href="?r=employee"><i class="fa fa-users"></i> 당사주소록</a>

            <div class="side-title"><i class="fa fa-wrench"></i> 기술지원</div>
            <a class="side-item" href="?r=technicalSupportList"><i class="fa fa-list"></i> 현황리스트</a>
            <a class="side-item" href="?r=technicalSupportBest"><i class="fa fa-star-o"></i> 채택된답변</a>
            <a class="side-item" href="?r=technicalEducation"><i class="fa fa-graduation-cap"></i> 기술교육</a>

            <div class="side-title"><i class="fa fa-shield"></i> 안전관리</div>
            <a class="side-item" href="?r=safetycostList"><i class="fa fa-krw"></i> 안전관리비내역</a>
            <a class="side-item" href="?r=agreeBoardList"><i class="fa fa-bullhorn"></i> 안전게시판</a>
            <a class="side-item" href="?r=accessSafetyList"><i class="fa fa-lock"></i> 출입안전관리</a>

        </nav>

        <!-- 예정 -->
        <nav class="side-section hidden" id="sideEstimate">
            <div class="side-title"><i class="fa fa-plug"></i> 배관배선</div>
            <a class="side-item" href="?r=wire_check"><i class="fa fa-calculator"></i> 배관배선 검토</a>
            <a class="side-item" href="?r=wire_stats"><i class="fa fa-bar-chart"></i> 통계</a>
        </nav>

        <!-- PMS -->
        <nav class="side-section hidden" id="sidePms">
            <div class="side-title"><i class="fa fa-calculator"></i> 견적관리</div>
            <a class="side-item" href="?r=budgetlist"><i class="fa fa-bar-chart"></i> 예산현황</a>
            <a class="side-item" href="?r=quotationStatus_quote"><i class="fa fa-file-o"></i> 견적현황</a>
            <a class="side-item" href="?r=quotationStatus_order"><i class="fa fa-check-circle-o"></i> 수주현황</a>
            <a class="side-item" href="?r=quotationStatus_fail"><i class="fa fa-times-circle-o"></i> 실패현황</a>
            <a class="side-item" href="?r=calcList"><i class="fa fa-calculator"></i> 산출내역서</a>

            <div class="side-title"><i class="fa fa-calendar"></i> 고정일정</div>
            <a class="side-item" href="?r=schedule"><i class="fa fa-calendar-o"></i> 일정</a>

            <div class="side-title"><i class="fa fa-comments"></i> 회의게시판</div>
            <a class="side-item" href="?r=meetingList"><i class="fa fa-list"></i> 전체</a>
            <a class="side-item" href="?r=meetingList_partner"><i class="fa fa-handshake-o"></i> 협력업체</a>
            <a class="side-item" href="?r=meetingList_me"><i class="fa fa-user-o"></i> 내팀</a>
        </nav>

        <!-- BMS -->
        <nav class="side-section hidden" id="sideBms">
            <div class="side-title"><i class="fa fa-shopping-cart"></i> 구매관리</div>
            <a class="side-item" href="?r=company"><i class="fa fa-users"></i> 업체조회</a>
            <a class="side-item" href="?r=material_purchase_new"><i class="fa fa-cubes"></i> 자재구매내역</a>
            <a class="side-item" href="?r=material_contract_new"><i class="fa fa-file-text-o"></i> 도급내역</a>
            <a class="side-item" href="?r=material_sales_new"><i class="fa fa-store"></i> 대리점내역</a>

            <div class="side-title"><i class="fa fa-file-text-o"></i> 수주관리</div>
            <a class="side-item" href="?r=order_status"><i class="fa fa-list-alt"></i> 수주현황</a>
            <a class="side-item" href="?r=order_balance"><i class="fa fa-balance-scale"></i> 수주잔고</a>
            <a class="side-item" href="?r=order_register"><i class="fa fa-plus"></i> 수주등록</a>

            <div class="side-title"><i class="fa fa-line-chart"></i> 매출관리</div>
            <a class="side-item" href="?r=sales_status"><i class="fa fa-bar-chart"></i> 매출현황</a>
            <a class="side-item" href="?r=sales_register"><i class="fa fa-plus"></i> 매출등록</a>
            <a class="side-item" href="?r=sales_tax"><i class="fa fa-file-o"></i> 세금계산서</a>
            <a class="side-item" href="?r=sales_unmatched"><i class="fa fa-exclamation-circle"></i> 매출마감</a>

            <div class="side-title"><i class="fa fa-krw"></i> 수금관리</div>
            <a class="side-item" href="?r=collect_status"><i class="fa fa-inbox"></i> 수금현황</a>
            <a class="side-item" href="?r=collect_unpaid"><i class="fa fa-warning"></i> 미수관리</a>
            <a class="side-item" href="?r=collect_unclaimed"><i class="fa fa-question-circle-o"></i> 미청구관리</a>
            <a class="side-item" href="?r=collect_register"><i class="fa fa-plus"></i> 입금등록</a>

            <div class="side-title"><i class="fa fa-money"></i> 급여관리</div>
            <a class="side-item" href="?r=work_employee_pay_new"><i class="fa fa-credit-card"></i> 급여관리</a>

            <div class="side-title"><i class="fa fa-credit-card"></i> 비용관리</div>
            <a class="side-item" href="?r=expense_my_new"><i class="fa fa-user-o"></i> 나의경비</a>
            <a class="side-item" href="?r=expense_manage_new"><i class="fa fa-cogs"></i> 경비관리</a>
            <a class="side-item" href="?r=expense_company_new"><i class="fa fa-building-o"></i> 회사경비</a>
            <a class="side-item" href="?r=expense_all_new"><i class="fa fa-list"></i> 전체경비</a>

            <div class="side-title"><i class="fa fa-bank"></i> 자금관리</div>
            <a class="side-item" href="?r=accountList"><i class="fa fa-credit-card"></i> 계좌조회</a>
            <a class="side-item" href="?r=account_request"><i class="fa fa-exchange"></i> 이체요청</a>
            <a class="side-item" href="?r=account_balance"><i class="fa fa-bar-chart"></i> 잔액조회</a>
            <a class="side-item" href="?r=resolution"><i class="fa fa-file-text-o"></i> 지출결의서</a>

            <div class="side-title"><i class="fa fa-archive"></i> 자산관리</div>
            <a class="side-item" href="?r=assetList"><i class="fa fa-list"></i> 자산리스트</a>
            <a class="side-item" href="?r=carAccidentList"><i class="fa fa-car"></i> 사고리스트</a>
        </nav>

        <!-- CTM -->
        <nav class="side-section hidden" id="sideCtm">
            <div class="side-title"><i class="fa fa-file-text-o"></i> 계약관리</div>
            <a class="side-item" href="?r=ctm_main"><i class="fa fa-folder-open-o"></i> 계약현황</a>
        </nav>

        <!-- GRP -->
        <nav class="side-section hidden" id="sideGrp">
            <div class="side-title"><i class="fa fa-home"></i> Home</div>
            <a class="side-item" href="?r=emp"><i class="fa fa-tachometer"></i> 대시보드</a>
            <a class="side-item" href="?r=chat"><i class="fa fa-comments-o"></i> 채팅</a>

            <div class="side-title"><i class="fa fa-address-book"></i> 주소록</div>
            <a class="side-item" href="?r=org_chart"><i class="fa fa-sitemap"></i> 조직도</a>
            <a class="side-item" href="?r=org_chart_card"><i class="fa fa-address-card-o"></i> 주소록</a>

            <div class="side-title"><i class="fa fa-users"></i> H.R</div>
            <a class="side-item" href="?r=work_overtime"><i class="fa fa-clock-o"></i> 근무내역</a>
            <a class="side-item" href="?r=attitude"><i class="fa fa-calendar-check-o"></i> 근태리스트</a>
            <a class="side-item" href="?r=holiday"><i class="fa fa-umbrella"></i> 휴가관리</a>

            <div class="side-title"><i class="fa fa-file-text"></i> 전자결재</div>
            <a class="side-item" href="?r=approval_req"><i class="fa fa-inbox"></i> 결재하기</a>
            <a class="side-item" href="?r=approval_write"><i class="fa fa-edit"></i> 결재작성</a>
            <a class="side-item" href="?r=approval_done"><i class="fa fa-check"></i> 완결문서</a>

            <div class="side-title"><i class="fa fa-folder-open"></i> 문서함</div>
            <a class="side-item" href="?r=doc_all"><i class="fa fa-files-o"></i> 전체완결</a>
            <a class="side-item" href="?r=approval_official"><i class="fa fa-stamp"></i> 공문</a>
        </nav>

        <!-- MAIL -->
        <nav class="side-section hidden" id="sideMail">
            <div class="side-title"><i class="fa fa-envelope-o"></i> 웹메일</div>
            <a class="side-item" href="?r=mail_inbox"><i class="fa fa-inbox"></i> 받은편지함</a>
            <a class="side-item" href="?r=mail_sent"><i class="fa fa-paper-plane-o"></i> 보낸편지함</a>
            <a class="side-item" href="?r=mail_drafts"><i class="fa fa-pencil-square-o"></i> 임시보관함</a>
            <a class="side-item" href="?r=mail_compose"><i class="fa fa-edit"></i> 메일쓰기</a>
            <a class="side-item" href="?r=mail_spam"><i class="fa fa-ban"></i> 스팸메일함</a>
            <a class="side-item" href="?r=mail_archive"><i class="fa fa-archive"></i> 보관메일함</a>
            <a class="side-item" href="?r=mail_duplicate"><i class="fa fa-clone"></i> 중복메일함</a>
            <a class="side-item" href="?r=mail_trash"><i class="fa fa-trash-o"></i> 휴지통</a>

            <!-- 커스텀 폴더 (동적 렌더링) -->
            <div id="sideMailCustomFolders"></div>

            <div class="side-title"><i class="fa fa-cog"></i> MAIL설정</div>
            <a class="side-item" href="?r=mail_account_settings"><i class="fa fa-user-o"></i> 계정설정</a>
            <a class="side-item" href="?r=mail_admin_settings"><i class="fa fa-cogs"></i> Mail관리자설정</a>
        </nav>

        <!-- MAT -->
        <nav class="side-section hidden" id="sideMat">
            <div class="side-title"><i class="fa fa-cubes"></i> 품목관리</div>
            <a class="side-item" href="?r=material_list"><i class="fa fa-list"></i> 품목관리</a>
            <a class="side-item" href="?r=material_takelist"><i class="fa fa-truck"></i> 품목수령리스트</a>

            <div class="side-title"><i class="fa fa-archive"></i> 재고관리</div>
            <a class="side-item" href="?r=stock_status"><i class="fa fa-bar-chart"></i> 재고현황</a>
            <a class="side-item" href="?r=stock_in"><i class="fa fa-arrow-down"></i> 입고관리</a>
            <a class="side-item" href="?r=stock_out"><i class="fa fa-arrow-up"></i> 출고관리</a>
            <a class="side-item" href="?r=stock_transfer"><i class="fa fa-exchange"></i> 창고간이동</a>
            <a class="side-item" href="?r=stock_adjust"><i class="fa fa-sliders"></i> 재고조정</a>
            <a class="side-item" href="?r=stock_log"><i class="fa fa-history"></i> 재고이력</a>

            <div class="side-title"><i class="fa fa-cog"></i> 설정</div>
            <a class="side-item" href="?r=mat_settings"><i class="fa fa-sliders"></i> 품목관리설정</a>
        </nav>

        <!-- CAD -->
        <nav class="side-section hidden" id="sideCad">
            <div class="side-title"><i class="fa fa-pencil"></i> SmartCAD</div>
            <a class="side-item" href="?r=smartcad"><i class="fa fa-object-group"></i> SmartCAD 메인</a>
        </nav>

        <!-- 시설 (CCTV + IoT 통합) -->
        <nav class="side-section hidden" id="sideFacility">
            <div class="side-title"><i class="fa fa-video-camera"></i> CCTV</div>
            <a class="side-item" href="?r=cctv_viewer"><i class="fa fa-desktop"></i> CCTV Viewer</a>
            <a class="side-item" href="?r=onvif"><i class="fa fa-camera"></i> ONVIF 카메라</a>

            <div class="side-title"><i class="fa fa-plug"></i> IoT</div>
            <a class="side-item" href="?r=iot"><i class="fa fa-microchip"></i> IoT 관리</a>
        </nav>

        <!-- 도구/API -->
        <nav class="side-section hidden" id="sideApi">
            <div class="side-title"><i class="fa fa-puzzle-piece"></i> API 도구</div>
            <a class="side-item" href="?r=elevator_api"><i class="fa fa-arrows-v"></i> 승강기 설치정보</a>
            <a class="side-item" href="?r=naratender"><i class="fa fa-gavel"></i> 나라장터 입찰공고</a>
            <a class="side-item" href="?r=apt_bid"><i class="fa fa-trophy"></i> 공동주택 입찰결과</a>
            <a class="side-item" href="?r=qr_scanner"><i class="fa fa-qrcode"></i> S-스캐너</a>
            <a class="side-item" href="?r=doc_viewer"><i class="fa fa-file-o"></i> 문서뷰어</a>
            <a class="side-item" href="?r=short_url"><i class="fa fa-link"></i> 단축URL</a>

            <div class="side-title"><i class="fa fa-bar-chart"></i> 모니터링</div>
            <a class="side-item" href="?r=ws_monitor"><i class="fa fa-signal"></i> WS 접속자 현황</a>
        </nav>

        <!-- 관리 -->
        <nav class="side-section hidden" id="sideManage">
            <div class="side-title"><i class="fa fa-cog"></i> 설정</div>
            <a class="side-item" href="?r=my_settings"><i class="fa fa-user-o"></i> 개인설정</a>
            <?php if ($roleLevel >= 4): ?>
            <a class="side-item" href="?r=settings"><i class="fa fa-cogs"></i> 관리자설정</a>
            <a class="side-item" href="?r=auth_audit"><i class="fa fa-shield"></i> 인증감사로그</a>
            <?php endif; ?>
            <a class="side-item" href="?r=devlog"><i class="fa fa-code"></i> 개발일지</a>
            <a class="side-item" href="?r=manual"><i class="fa fa-book"></i> 매뉴얼</a>
            <a class="side-item" href="?r=trash"><i class="fa fa-trash-o"></i> 휴지통</a>
        </nav>

    </aside>

    <!-- ── CONTENT ── -->
    <main id="content">
        <!-- router.js 가 여기에 페이지 로드 -->
        <div class="empty-state">
            <div class="empty-icon"><i class="fa fa-home"></i></div>
            <div class="empty-message">메뉴를 선택하세요.</div>
        </div>
    </main>

</div><!-- /#app-body -->

<!-- ── 저작권 표시 (항상 고정) ── -->
<div id="shv-copyright">
    &copy; <?php echo date('Y'); ?> <strong>SH Vision</strong>. All rights reserved.
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
window.SHV = window.SHV || {};
SHV._user = <?= json_encode([
    'id'    => $loginId,
    'name'  => $userName,
    'pk'    => $userPk,
    'photo' => $userPhoto,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/core/dom.js"></script>
<script src="js/core/csrf.js?v=20260413c"></script>
<script src="js/core/api.js?v=20260413c"></script>
<script src="js/core/events.js"></script>
<script src="js/core/table.js?v=20260413h"></script>
<script src="js/ui/toast.js"></script>
<script src="js/ui/modal.js?v=20260413"></script>
<script src="js/ui/confirm.js"></script>
<script src="js/ui/prompt.js?v=20260415a"></script>
<script src="js/ui/table-sort.js"></script>
<script src="js/ui/table-select.js"></script>
<script src="js/ui/table-filter.js"></script>
<script src="js/ui/search-dropdown.js"></script>
<script src="js/ui/chat.js?v=20260416a"></script>
<script src="js/pages/pjt.js?v=20260416a"></script>
<script src="js/core/router.js?v=20260415f"></script>
<script src="js/pages/mail.js?v=20260415a"></script>
<script src="js/mail/indexeddb.js?v=20260415a"></script>
<script src="js/mail/cache.js?v=20260415a"></script>
<script src="js/mail/search.js?v=20260415a"></script>
<script src="js/mail/realtime.js?v=20260415a"></script>
<script src="js/mail/push.js?v=20260415a"></script>
<script src="js/core/notifications.js?v=20260415c"></script>
<script src="js/pages/mail_pages.js?v=20260416a"></script>
<script src="js/pages/manage_pages.js?v=20260413a"></script>
<script>
(function () {
    'use strict';

    /* ── 테마 초기화 (localStorage 우선) ── */
    var themeToggle = document.getElementById('themeToggle');
    var savedTheme  = localStorage.getItem('shv-theme') || 'light';
    if (savedTheme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        themeToggle.checked = true;
    }
    themeToggle.addEventListener('change', function () {
        if (this.checked) {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('shv-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('shv-theme', 'light');
        }
    });

    /* ── 라우터 초기화 (CSRF는 메타 태그에서 이미 로드됨) ── */
    SHV.router.init('#content');

    /* ── 글로벌 알림 초기화 ── */
    if (SHV.notifications) { SHV.notifications.init(); }

    /* ── L0 섹션 맵 ── */
    var l0Sections = {
        fms:      document.getElementById('sideFms'),
        estimate: document.getElementById('sideEstimate'),
        pms:      document.getElementById('sidePms'),
        bms:      document.getElementById('sideBms'),
        ctm:      document.getElementById('sideCtm'),
        grp:      document.getElementById('sideGrp'),
        mail:     document.getElementById('sideMail'),
        mat:      document.getElementById('sideMat'),
        cad:      document.getElementById('sideCad'),
        facility: document.getElementById('sideFacility'),
        api:      document.getElementById('sideApi'),
        manage:   document.getElementById('sideManage')
    };

    /* L0 활성화 (상단 탭 + 사이드 섹션) */
    function activateL0(l0) {
        document.querySelectorAll('.top-menu-btn[data-l0]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.l0 === l0);
        });
        Object.keys(l0Sections).forEach(function (key) {
            if (l0Sections[key]) l0Sections[key].classList.toggle('hidden', key !== l0);
        });
    }

    /* 사이드 아이템 active (route 기준) */
    function activateSideItem(route) {
        document.querySelectorAll('.side-item').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            a.classList.toggle('active', href === '?r=' + route);
        });
    }

    /* ── L0 → 기본 라우트 맵 ── */
    var l0DefaultRoute = {
        fms: 'fms_dashboard', pms: 'pms_dashboard', bms: 'bms_dashboard',
        ctm: 'ctm_main', grp: 'emp', mail: 'mail_inbox',
        mat: 'material_list', cad: 'smartcad', facility: 'cctv_viewer',
        api: 'api_dashboard', manage: 'manage_dashboard'
    };

    /* ── 상단 탭 클릭 → L0 전환 + 기본 라우트 이동 ── */
    var _currentL0 = '';
    document.querySelectorAll('.top-menu-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var l0 = this.dataset.l0;
            activateL0(l0);
            /* 같은 L0 내에서는 페이지 이동 안 함 (현재 페이지 유지) */
            if (l0 !== _currentL0) {
                _currentL0 = l0;
                var defaultRoute = l0DefaultRoute[l0];
                if (defaultRoute) {
                    SHV.router.navigate(defaultRoute);
                }
            }
        });
    });

    /* ── 사이드바 메뉴 클릭 → SPA 라우터로 처리 (전체 리로드 방지) ── */
    document.getElementById('sidebar').addEventListener('click', function (e) {
        var target = e.target.closest('.side-item[href]');
        if (!target) return;
        var href  = target.getAttribute('href') || '';
        var match = href.match(/^\?r=(.+)/);
        if (!match) return;
        e.preventDefault();
        var route = decodeURIComponent(match[1]);
        SHV.router.navigate(route);
        /* 모바일: 클릭 후 사이드바 닫기 */
        if (window.innerWidth <= 1024) closeSidebar();
    });

    /* ── 라우터 onLoad → L0·사이드 active 자동 동기화 ── */
    SHV.router.onLoad(function (info) {
        _currentL0 = info.l0;
        activateL0(info.l0);
        activateSideItem(info.route);
    });

    /* ── 사이드바 접기/펴기 ── */
    var appBody  = document.getElementById('app-body');
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        sidebar.classList.add('sidebar-open');
        backdrop.classList.add('active');
    }
    function closeSidebar() {
        sidebar.classList.remove('sidebar-open');
        backdrop.classList.remove('active');
        /* 데스크톱: 사이드바 접기 */
        appBody.classList.remove('sidebar-collapsed');
    }
    function toggleSidebar() {
        if (window.innerWidth <= 1024) {
            /* 태블릿/모바일: overlay 방식 */
            sidebar.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
        } else {
            /* 데스크톱: 너비 축소 방식 */
            appBody.classList.toggle('sidebar-collapsed');
        }
    }

    document.getElementById('sidebarToggle').addEventListener('click', toggleSidebar);

    /* ── backdrop 클릭 → 닫기 (태블릿/모바일) ── */
    backdrop.addEventListener('click', closeSidebar);

    /* ── 화면 넓어지면 overlay 상태 정리 ── */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1024) {
            sidebar.classList.remove('sidebar-open');
            backdrop.classList.remove('active');
        }
    });

    /* ── 로그아웃 ── */
    document.getElementById('btnLogout').addEventListener('click', function () {
        SHV.api.post('dist_process/saas/Auth.php', { todo: 'logout' })
            .then(function () { window.location.href = 'login.php'; })
            .catch(function () { window.location.href = 'login.php'; });
    });

})();
</script>

</body>
</html>
