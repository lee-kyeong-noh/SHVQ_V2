<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * 스토리지 설정
 *
 * 웹서버 루트: D:/SHV_ERP  (shvq.kr 서빙)
 * 업로드 경로: D:/SHV_ERP/SHVQ_V2/uploads/
 * PHP 표준 파일 함수(copy/unlink/mkdir)로 직접 읽기/쓰기.
 *
 * 테넌트별 경로 구조:
 *   D:/SHV_ERP/SHVQ_V2/uploads/{tenant_id}/{category}/{filename}
 *
 * HTTP URL 구조:
 *   https://shvq.kr/SHVQ_V2/uploads/{tenant_id}/{category}/{filename}
 */
return [

    // ── 드라이버 설정 ────────────────────────────
    'driver' => [
        // FTP 마운트 드라이브 루트 경로 (Windows: E:/, Linux: /mnt/ftp/)
        'base_path' => shvEnv('STORAGE_BASE_PATH', 'D:/SHV_ERP/SHVQ_V2/uploads/'),
    ],

    // ── HTTP 공개 URL 베이스 ─────────────────────
    'base_url' => shvEnv('STORAGE_BASE_URL', 'https://shvq.kr/SHVQ_V2'),

    // ── 카테고리 경로 매핑 ────────────────────────
    // key: StorageService 카테고리 키
    // val: uploads/{tenant_id}/ 이하 실제 서브 경로
    'categories' => [
        // ── MAT 품목관리 ──
        'mat_banner'       => 'mat',
        'mat_detail'       => 'mat',
        'mat_attach'       => 'mat/attach',

        // ── 메일 ──
        'mail_attach'      => 'mail/attach',
        'mail_inline'      => 'mail/inline',

        // ── 인사/직원 ──
        'employee'         => 'employee',

        // ── 공통 ──
        'common'           => 'common',
        'temp'             => 'temp',

        // ── FMS 본사 ──
        'head_attach'      => 'head/attach',

        // ── FMS 사업장 ──
        'member_attach'    => 'member/attach',
        'ocr_scan'         => 'member/ocr',

        // ── FMS 현장 ──
        'site_attach'      => 'site/attach',
        'est_attach'       => 'site/est',
        'bill_attach'      => 'site/bill',
        'floor_plan'       => 'site/floor',
        'subcontract'      => 'site/subcontract',

        // ── FMS 연락처 ──
        'contact_photo'    => 'contact/photo',

        // ── 코멘트 (특기사항 공용) ──
        'comment_file'     => 'comment',

        // ── PJT ──
        'pjt_attach'       => 'pjt/attach',
        'pjt_photo'        => 'pjt/photo',
        'pjt_inspect'      => 'pjt/inspect',

        // ── CAD ──
        'cad_drawing'      => 'cad/drawing',
        'cad_export'       => 'cad/export',

        // ── CCTV / NVR ──
        'cctv_snapshot'    => 'cctv/snapshot',
        'cctv_timelapse'   => 'cctv/timelapse',
        'cctv_recording'   => 'cctv/recording',

        // ── IoT ──
        'iot_data'         => 'iot/data',

        // ── GRP 그룹웨어 ──
        'approval_attach'  => 'grp/approval',
        'board_attach'     => 'grp/board',
    ],

    // ── 업로드 제한 ──────────────────────────────
    'limits' => [
        // 단일 파일 최대 크기 (바이트)
        'max_size_bytes' => shvEnvInt('STORAGE_MAX_SIZE_MB', 20) * 1024 * 1024,

        // 허용 확장자 (소문자, 콤마 구분)
        'allowed_ext' => array_values(array_filter(
            array_map('trim', explode(',', (string)shvEnv(
                'STORAGE_ALLOWED_EXT',
                'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,txt,csv,hwp'
            )))
        )),
    ],

];
