<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../dist_library/saas/security/init.php';

// ── DB 연결: V2 DbConnection 싱글턴 사용 ──
if (!function_exists('cadGetDB')) {
    function cadGetDB(): PDO
    {
        return DbConnection::get();
    }
}

// ── V2 보안 서비스 팩토리 (CAD 전용) ──
if (!function_exists('cadSecurityConfig')) {
    /** V2 security.php 로드 (한 번만) */
    function cadSecurityConfig(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../config/security.php';
            // CAD는 ERP 평문 비밀번호 호환 필요 — legacy 허용 + 컬럼명 오버라이드
            $cfg['auth']['allow_legacy_password'] = true;
            $cfg['auth']['user_password_column'] = 'passwd';
        }
        return $cfg;
    }

    /** V2 PasswordService (평문→bcrypt 자동 마이그레이션 포함) */
    function cadPasswordService(): PasswordService
    {
        static $svc = null;
        if ($svc === null) {
            $svc = new PasswordService(cadGetDB(), cadSecurityConfig());
        }
        return $svc;
    }

    /** V2 RateLimiter (로그인 무차별 대입 방지) */
    function cadRateLimiter(): RateLimiter
    {
        static $svc = null;
        if ($svc === null) {
            $svc = new RateLimiter(cadGetDB(), cadSecurityConfig());
        }
        return $svc;
    }

    /** V2 CsrfService */
    function cadCsrfService(): CsrfService
    {
        static $svc = null;
        if ($svc === null) {
            $cfg = cadSecurityConfig();
            $session = new SessionManager($cfg);
            $svc = new CsrfService($session, $cfg);
        }
        return $svc;
    }

    /** 클라이언트 IP (프록시 안전) */
    function cadClientIp(): string
    {
        static $ip = null;
        if ($ip === null) {
            $resolver = new ClientIpResolver(cadSecurityConfig());
            $ip = $resolver->resolve();
        }
        return $ip;
    }

    /** Rate Limit 식별자 생성 */
    function cadRateLimitId(string $loginId): string
    {
        return hash('sha256', $loginId . '|' . cadClientIp());
    }
}

// 도면 상태값 (코드는 고정, 라벨/색상은 변경 가능)
$CAD_STATUS = [
    0 => ['label' => '작성', 'color' => '#00aaff'],
    1 => ['label' => '산출', 'color' => '#ffaa00'],
    2 => ['label' => '완료', 'color' => '#00cc88'],
    3 => ['label' => '반려', 'color' => '#ff4466'],
    4 => ['label' => '보류', 'color' => '#888888'],
    5 => ['label' => '승인', 'color' => '#aa66ff'],
];

// 사용자 레벨 정의
$CAD_LEVELS = [
    0 => '게스트',
    1 => '뷰어',
    2 => '작성자',
    3 => '검수자',
    4 => '관리자',
    5 => '시스템관리자',
];

// 레벨별 권한 ON/OFF (1=허용, 0=차단)
// view: 보기, export: 내보내기, edit: 도면수정, status_change: 상태변경, approve: 수정승인, delete: 삭제, settings: 설정변경
$CAD_PERMISSIONS = [
    0 => ['view'=>1, 'export'=>0, 'edit'=>0, 'status_change'=>0, 'approve'=>0, 'delete'=>0, 'settings'=>0],
    1 => ['view'=>1, 'export'=>1, 'edit'=>0, 'status_change'=>0, 'approve'=>0, 'delete'=>0, 'settings'=>0],
    2 => ['view'=>1, 'export'=>1, 'edit'=>1, 'status_change'=>0, 'approve'=>0, 'delete'=>0, 'settings'=>0],
    3 => ['view'=>1, 'export'=>1, 'edit'=>1, 'status_change'=>1, 'approve'=>1, 'delete'=>0, 'settings'=>0],
    4 => ['view'=>1, 'export'=>1, 'edit'=>1, 'status_change'=>1, 'approve'=>1, 'delete'=>1, 'settings'=>0],
    5 => ['view'=>1, 'export'=>1, 'edit'=>1, 'status_change'=>1, 'approve'=>1, 'delete'=>1, 'settings'=>1],
];

// 상태별 수정 가능 최소 레벨 (이 레벨 미만이면 검수자 승인 필요)
$CAD_STATUS_EDIT_LEVEL = [
    0 => 2,  // 작성 상태: 레벨2(작성자) 이상 수정 가능
    1 => 2,  // 산출 상태: 레벨2(작성자) 이상 수정 가능
    2 => 3,  // 완료 상태: 레벨3(검수자) 이상만 수정 가능
    3 => 3,  // 반려 상태: 레벨3(검수자) 이상만 수정 가능
    4 => 4,  // 보류 상태: 레벨4(관리자) 이상만 수정 가능
    5 => 4,  // 승인 상태: 레벨4(관리자) 이상만 수정 가능
];

// 현재 로그인 사용자 (세션에서 설정됨, 기본값은 게스트)
if(!isset($CAD_CURRENT_USER)){
    $CAD_CURRENT_USER = [
        'id' => 'guest',
        'name' => '게스트',
        'level' => 0,
    ];
}
