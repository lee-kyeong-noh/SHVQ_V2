<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>';
    exit;
}
?>
<section data-page="manual" data-title="개발 메뉴얼">

<div class="manual-wrap">

    <!-- ── 좌측 목차 ── -->
    <nav class="manual-nav" id="manualNav">
        <div class="manual-nav-title"><i class="fa fa-book"></i> 목차</div>

        <div class="manual-nav-group">개요</div>
        <a class="manual-nav-item active" data-target="sec-overview">프로젝트 개요</a>
        <a class="manual-nav-item" data-target="sec-stack">기술 스택 &amp; 구조</a>
        <a class="manual-nav-item" data-target="sec-role">역할 분담</a>

        <div class="manual-nav-group">DB 설명</div>
        <a class="manual-nav-item" data-target="sec-multitenant">멀티테넌트 구조</a>
        <a class="manual-nav-item" data-target="sec-db-auth">인증·보안 테이블</a>
        <a class="manual-nav-item" data-target="sec-db-grp">GRP 그룹웨어 테이블</a>
        <a class="manual-nav-item" data-target="sec-db-appr">전자결재 테이블 (설계)</a>
        <a class="manual-nav-item" data-target="sec-db-v1">V1 DB 참조 규칙</a>

        <div class="manual-nav-group">API &amp; 파일</div>
        <a class="manual-nav-item" data-target="sec-api">API 엔드포인트</a>
        <a class="manual-nav-item" data-target="sec-storage">파일 스토리지</a>
        <a class="manual-nav-item" data-target="sec-security">보안 &amp; CSRF</a>

        <div class="manual-nav-group">설계서</div>
        <a class="manual-nav-item" data-target="sec-design-docs">설계서 문서 모음</a>

        <div class="manual-nav-group">개발 규칙</div>
        <a class="manual-nav-item" data-target="sec-rule-front">프론트엔드 규칙</a>
        <a class="manual-nav-item" data-target="sec-rule-ftp">FTP 배포</a>
        <a class="manual-nav-item" data-target="sec-devlog">개발일지 (모듈별)</a>
        <a class="manual-nav-item" data-target="sec-role-level">권한 레벨</a>
        <a class="manual-nav-item" data-target="sec-manual-update">📋 메뉴얼 업데이트 규칙</a>

        <div class="manual-nav-group">FMS 모듈 분석</div>
        <a class="manual-nav-item" data-target="sec-fms-hierarchy">3계층 구조 (본사/사업장/현장)</a>
        <a class="manual-nav-item" data-target="sec-fms-fieldmanager">현장소장 (FieldManager)</a>
        <a class="manual-nav-item" data-target="sec-fms-pjt">PJT (프로젝트)</a>
        <a class="manual-nav-item" data-target="sec-fms-estimate">견적 (Estimate)</a>
        <a class="manual-nav-item" data-target="sec-fms-config">V1 Config 옵션 전수</a>
        <a class="manual-nav-item" data-target="sec-fms-v2status">V2 구현 현황</a>
        <a class="manual-nav-item" data-target="sec-fms-route">V2 라우트 맵 (119개)</a>
        <a class="manual-nav-item" data-target="sec-fms-impl">FMS 구현 파일 목록</a>
        <a class="manual-nav-item" data-target="sec-fms-api-map">FMS API 연동 맵</a>
        <a class="manual-nav-item" data-target="sec-fms-v1gap">V1→V2 미구현 API 갭</a>
        <a class="manual-nav-item" data-target="sec-fms-head-todo">본사 상세 보강 작업 목록</a>

        <div class="manual-nav-group">CAD (SmartCAD)</div>
        <a class="manual-nav-item" data-target="sec-cad-overview">CAD 아키텍처</a>
        <a class="manual-nav-item" data-target="sec-cad-refactor">V2 리팩터링 완료</a>
        <a class="manual-nav-item" data-target="sec-cad-overhaul">제품화 개편안</a>

        <div class="manual-nav-group">시설 (Facility)</div>
        <a class="manual-nav-item" data-target="sec-facility-onvif">ONVIF 카메라 관리</a>
        <a class="manual-nav-item" data-target="sec-facility-cctv">CCTV Viewer</a>
        <a class="manual-nav-item" data-target="sec-facility-iot">IoT 통합 관리</a>
        <a class="manual-nav-item" data-target="sec-facility-api">시설 API 엔드포인트</a>

        <div class="manual-nav-group">변경 이력</div>
        <a class="manual-nav-item" data-target="sec-changelog-fms-head">FMS 본사관리</a>
        <a class="manual-nav-item" data-target="sec-changelog-appr">전자결재 시스템</a>
        <a class="manual-nav-item" data-target="sec-changelog-grp">GRP 그룹웨어</a>
        <a class="manual-nav-item" data-target="sec-changelog-mat">MAT 품목관리</a>
        <a class="manual-nav-item" data-target="sec-changelog-mail">📧 메일 시스템</a>
    </nav>

    <!-- ── 우측 콘텐츠 ── -->
    <div class="manual-content" id="manualContent">

        <!-- ══════════════════ 개요 ══════════════════ -->
        <section class="manual-sec" id="sec-overview">
            <h2 class="manual-sec-title"><i class="fa fa-flag"></i> 프로젝트 개요</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <tr><th>프로젝트명</th><td>SH Vision ERP Portal v2.0</td></tr>
                    <tr><th>형태</th><td>SaaS 멀티테넌트 ERP (재설계)</td></tr>
                    <tr><th>서비스 URL</th><td><a href="https://shvq.kr/SHVQ_V2/" target="_blank">https://shvq.kr/SHVQ_V2/</a></td></tr>
                    <tr><th>개발 DB</th><td>CSM_C004732_V2 (67번 개발 서버)</td></tr>
                    <tr><th>상용 DB</th><td>CSM_C004732 (66번 — <span class="text-danger">접근 금지</span>)</td></tr>
                    <tr><th>V1 URL</th><td><a href="https://shvq.kr/SHVQ/" target="_blank">https://shvq.kr/SHVQ/</a> (운영중)</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 기술 스택 ══════════════════ -->
        <section class="manual-sec" id="sec-stack">
            <h2 class="manual-sec-title"><i class="fa fa-code"></i> 기술 스택 &amp; 구조</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <tr><th>Frontend</th><td>Vanilla JS + 순수 CSS (프레임워크 없음, 부트스트랩 없음)</td></tr>
                    <tr><th>Backend</th><td>PHP 8.x + MSSQL (PDO sqlsrv)</td></tr>
                    <tr><th>CSS 토큰</th><td>css/v2/tokens.css — 디자인 토큰 전체 정의</td></tr>
                    <tr><th>SPA 라우터</th><td>js/core/router.js — <code>?r=route</code> 기반, fetch로 뷰 부분 로드</td></tr>
                    <tr><th>인증</th><td>PHP 세션 (SHVQSESSID) + CSRF 메타 태그 방식</td></tr>
                    <tr><th>IIS</th><td>Windows IIS (D:/SHV_ERP/ 루트)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">디렉토리 구조</div>
                <pre class="manual-code">SHVQ_V2/
├── index.php                    # SPA 진입점 (세션·CSRF·메뉴 렌더)
├── login.php                    # 로그인 (AJAX + CSRF)
├── config/                      # 환경설정
│   ├── env.php                  # .env 로더
│   ├── database.php             # DB 연결
│   ├── security.php             # 보안 설정
│   └── storage.php              # 스토리지 설정
├── dist_library/saas/
│   ├── security/                # 인증·보안 클래스 (AuthService 등)
│   ├── GroupwareService.php     # GRP 비즈니스 로직
│   └── storage/                 # 파일 스토리지 드라이버
├── dist_process/saas/           # API 엔드포인트 (PHP)
│   ├── Auth.php
│   ├── Employee.php
│   └── ...
├── views/saas/                  # 뷰 파일 (SPA 부분 로드)
│   ├── grp/                     # GRP 그룹웨어
│   ├── mail/                    # 웹메일
│   ├── mat/                     # 품목관리
│   └── manage/                  # 관리
├── css/v2/                      # V2 전용 CSS
│   ├── tokens.css
│   ├── components.css
│   ├── detail-view.css          # 상세뷰 공용 (본사/사업장/현장)
│   └── pages/                   # 페이지별 CSS
└── js/core/                     # JS 코어
    ├── router.js
    ├── api.js
    ├── csrf.js
    └── ...</pre>
            </div>
        </section>

        <!-- ══════════════════ 역할 분담 ══════════════════ -->
        <section class="manual-sec" id="sec-role">
            <h2 class="manual-sec-title"><i class="fa fa-users"></i> 역할 분담</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <thead><tr><th>담당</th><th>영역</th><th>금지</th></tr></thead>
                    <tbody>
                        <tr><td><strong>ChatGPT</strong></td><td>PHP 백엔드 (컨트롤러/모델/DB 쿼리/인증/권한)</td><td>CSS/HTML/JS 파일 직접 수정 금지</td></tr>
                        <tr><td><strong>Claude</strong></td><td>CSS/HTML/JS/뷰 템플릿 작성, FTP 배포, 코드 리뷰</td><td>V1 코드 참조 금지 (명시 요청 시 예외)</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 멀티테넌트 ══════════════════ -->
        <section class="manual-sec" id="sec-multitenant">
            <h2 class="manual-sec-title"><i class="fa fa-sitemap"></i> 멀티테넌트 구조</h2>
            <div class="manual-card">
                <p class="manual-desc">모든 V2 데이터 테이블은 <code>tenant_id</code> + <code>service_code</code> 두 컬럼으로 테넌트를 격리합니다.</p>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>tenant_id</code></td><td>INT</td><td>테넌트 식별자 (회사 단위)</td></tr>
                        <tr><td><code>service_code</code></td><td>VARCHAR(20)</td><td>서비스 코드 (GRP, MAT, FMS 등)</td></tr>
                    </tbody>
                </table>
                <div class="manual-sub-title mt-3">PHP 스코프 사용 패턴</div>
                <pre class="manual-code">$scope = $service->resolveScope($context, '', 0);
// $scope = ['tenant_id' => 1, 'service_code' => 'GRP']

// 모든 쿼리에 WHERE tenant_id = ? AND service_code = ? 자동 적용</pre>
            </div>
        </section>

        <!-- ══════════════════ 인증·보안 테이블 ══════════════════ -->
        <section class="manual-sec" id="sec-db-auth">
            <h2 class="manual-sec-title"><i class="fa fa-lock"></i> 인증·보안 테이블</h2>

            <div class="manual-card">
                <div class="manual-tbl-title">Tb_Users <span class="manual-badge">V1 공용</span></div>
                <p class="manual-desc">로그인 계정 테이블. V1과 동일한 DB를 읽기 전용으로 공유.</p>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK</td><td>사용자 기본키</td></tr>
                        <tr><td><code>id</code></td><td>VARCHAR</td><td>로그인 아이디</td></tr>
                        <tr><td><code>pw</code></td><td>VARCHAR</td><td>비밀번호 (bcrypt 또는 레거시 md5)</td></tr>
                        <tr><td><code>authority_idx</code></td><td>INT</td><td><strong>권한 레벨</strong> (1=최고관리자, 2=관리자, 3=부서장, 4=일반사원)</td></tr>
                        <tr><td><code>status</code></td><td>VARCHAR</td><td>계정 상태 (<code>active</code> 만 로그인 허용)</td></tr>
                        <tr><td><code>employee_idx</code></td><td>INT</td><td>연결된 직원 IDX</td></tr>
                    </tbody>
                </table>
                <div class="manual-warn"><i class="fa fa-exclamation-triangle"></i> <strong>authority_idx는 낮을수록 높은 권한</strong> — PHP 백엔드에서 권한 체크 시 <code>$roleLevel &gt; 2</code> (관리자 이상만 허용) 패턴 사용</div>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_AuthRateLimit <span class="manual-badge manual-badge--v2">V2 신규</span></div>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>id</code></td><td>INT PK</td><td></td></tr>
                        <tr><td><code>ip_address</code></td><td>VARCHAR(45)</td><td>클라이언트 IP</td></tr>
                        <tr><td><code>login_id</code></td><td>VARCHAR</td><td>시도한 로그인 ID</td></tr>
                        <tr><td><code>attempt_count</code></td><td>INT</td><td>시도 횟수</td></tr>
                        <tr><td><code>window_start</code></td><td>DATETIME</td><td>시도 윈도우 시작시간</td></tr>
                        <tr><td><code>locked_until</code></td><td>DATETIME NULL</td><td>잠금 해제 시각</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_AuthAuditLog <span class="manual-badge manual-badge--v2">V2 신규</span></div>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>id</code></td><td>BIGINT PK</td><td></td></tr>
                        <tr><td><code>event_type</code></td><td>VARCHAR(100)</td><td>이벤트 유형 (login.success, employee.delete 등)</td></tr>
                        <tr><td><code>actor_user_pk</code></td><td>INT</td><td>행위자 user PK</td></tr>
                        <tr><td><code>result</code></td><td>VARCHAR(20)</td><td>OK / FAIL</td></tr>
                        <tr><td><code>message</code></td><td>NVARCHAR(500)</td><td>상세 메시지</td></tr>
                        <tr><td><code>metadata</code></td><td>NVARCHAR(MAX)</td><td>JSON 부가정보</td></tr>
                        <tr><td><code>ip_address</code></td><td>VARCHAR(45)</td><td>클라이언트 IP</td></tr>
                        <tr><td><code>created_at</code></td><td>DATETIME</td><td>기록 시각</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ GRP 그룹웨어 ══════════════════ -->
        <section class="manual-sec" id="sec-db-grp">
            <h2 class="manual-sec-title"><i class="fa fa-building"></i> GRP 그룹웨어 테이블</h2>

            <div class="manual-card">
                <div class="manual-tbl-title">Tb_GwEmployee <span class="manual-badge manual-badge--v2">V2 신규</span></div>
                <p class="manual-desc">직원 정보. V1의 Tb_Employee와 별도 (멀티테넌트 지원).</p>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK</td><td></td></tr>
                        <tr><td><code>tenant_id</code></td><td>INT</td><td>테넌트</td></tr>
                        <tr><td><code>service_code</code></td><td>VARCHAR(20)</td><td></td></tr>
                        <tr><td><code>emp_no</code></td><td>VARCHAR(30)</td><td>사번</td></tr>
                        <tr><td><code>name</code></td><td>NVARCHAR(50)</td><td>이름</td></tr>
                        <tr><td><code>dept_idx</code></td><td>INT NULL</td><td>소속 부서 (Tb_GwDept.idx)</td></tr>
                        <tr><td><code>position</code></td><td>NVARCHAR(30)</td><td>직급</td></tr>
                        <tr><td><code>job_title</code></td><td>NVARCHAR(30)</td><td>직책</td></tr>
                        <tr><td><code>emp_type</code></td><td>NVARCHAR(30)</td><td>고용형태</td></tr>
                        <tr><td><code>status</code></td><td>VARCHAR(20)</td><td>재직상태 (ACTIVE / RESIGNED / LEAVE)</td></tr>
                        <tr><td><code>phone</code></td><td>VARCHAR(30)</td><td>휴대폰</td></tr>
                        <tr><td><code>email</code></td><td>VARCHAR(100)</td><td>회사메일</td></tr>
                        <tr><td><code>photo_url</code></td><td>VARCHAR(500)</td><td>프로필 사진 URL</td></tr>
                        <tr><td><code>is_hidden</code></td><td>BIT DEFAULT 0</td><td>숨김 여부 (조직도에서 제외)</td></tr>
                        <tr><td><code>is_deleted</code></td><td>BIT DEFAULT 0</td><td>소프트 삭제</td></tr>
                        <tr><td><code>hire_date</code></td><td>DATE NULL</td><td>입사일</td></tr>
                        <tr><td><code>leave_date</code></td><td>DATE NULL</td><td>퇴사일</td></tr>
                        <tr><td><code>salary_basic</code></td><td>DECIMAL(15,2)</td><td>기본급</td></tr>
                        <tr><td><code>created_at</code></td><td>DATETIME</td><td></td></tr>
                        <tr><td><code>updated_at</code></td><td>DATETIME</td><td></td></tr>
                        <tr><td><code>updated_by</code></td><td>INT NULL</td><td>마지막 수정자 user_pk</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_GwDept <span class="manual-badge manual-badge--v2">V2 신규</span></div>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK</td><td></td></tr>
                        <tr><td><code>tenant_id</code></td><td>INT</td><td></td></tr>
                        <tr><td><code>service_code</code></td><td>VARCHAR(20)</td><td></td></tr>
                        <tr><td><code>dept_name</code></td><td>NVARCHAR(100)</td><td>부서명</td></tr>
                        <tr><td><code>parent_idx</code></td><td>INT NULL</td><td>상위 부서 (NULL = 최상위)</td></tr>
                        <tr><td><code>sort_order</code></td><td>INT DEFAULT 0</td><td>정렬순서</td></tr>
                        <tr><td><code>is_deleted</code></td><td>BIT DEFAULT 0</td><td></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 전자결재 테이블 설계 ══════════════════ -->
        <section class="manual-sec" id="sec-db-appr">
            <h2 class="manual-sec-title"><i class="fa fa-file-text-o"></i> 전자결재 테이블 설계 <span class="manual-badge manual-badge--plan">설계중</span></h2>
            <div class="manual-warn"><i class="fa fa-info-circle"></i> V1의 <code>Tb_ElectronicApproval</code>을 정규화하여 V2에서 재설계. ChatGPT 백엔드 작업 전 이 설계안 기준으로 테이블 생성 요청.</div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_ApprDoc — 결재 문서 본문</div>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK IDENTITY</td><td></td></tr>
                        <tr><td><code>tenant_id</code></td><td>INT NOT NULL</td><td></td></tr>
                        <tr><td><code>service_code</code></td><td>VARCHAR(20)</td><td></td></tr>
                        <tr><td><code>doc_no</code></td><td>VARCHAR(30)</td><td>문서번호 (자동생성: YYYYMMDD-NNN)</td></tr>
                        <tr><td><code>title</code></td><td>NVARCHAR(300)</td><td>제목</td></tr>
                        <tr><td><code>content</code></td><td>NVARCHAR(MAX)</td><td>본문 HTML (TinyMCE)</td></tr>
                        <tr><td><code>form_type</code></td><td>VARCHAR(50)</td><td>양식 (basic / expense / vacation / transportationFee / statementReasons / requstPayment)</td></tr>
                        <tr><td><code>gubun</code></td><td>VARCHAR(20)</td><td>결재 구분 (지급 / 협조 / 품의 / 공문 / 내부 / 기타)</td></tr>
                        <tr><td><code>status</code></td><td>TINYINT DEFAULT 1</td><td>1=작성중, 2=결재대기, 3=완결, 4=회수, 5=반려</td></tr>
                        <tr><td><code>writer_emp_idx</code></td><td>INT</td><td>기안자 (Tb_GwEmployee.idx)</td></tr>
                        <tr><td><code>doc_date</code></td><td>DATE NULL</td><td>기안일</td></tr>
                        <tr><td><code>due_date</code></td><td>DATE NULL</td><td>마감일</td></tr>
                        <tr><td><code>done_at</code></td><td>DATETIME NULL</td><td>완결 일시</td></tr>
                        <tr><td><code>created_at</code></td><td>DATETIME DEFAULT GETDATE()</td><td></td></tr>
                        <tr><td><code>updated_at</code></td><td>DATETIME</td><td></td></tr>
                        <tr><td><code>is_deleted</code></td><td>BIT DEFAULT 0</td><td></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_ApprLine — 결재선 &amp; 참조자</div>
                <p class="manual-desc">V1의 owner_1~5 고정 컬럼 방식 → 정규화. 결재자 수 제한 없음.</p>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK IDENTITY</td><td></td></tr>
                        <tr><td><code>doc_idx</code></td><td>INT NOT NULL</td><td>Tb_ApprDoc.idx FK</td></tr>
                        <tr><td><code>seq</code></td><td>TINYINT</td><td>결재 순서 (1, 2, 3...)</td></tr>
                        <tr><td><code>line_type</code></td><td>VARCHAR(10)</td><td><code>approver</code> 결재자 / <code>ref</code> 참조자</td></tr>
                        <tr><td><code>emp_idx</code></td><td>INT NOT NULL</td><td>대상 직원 (Tb_GwEmployee.idx)</td></tr>
                        <tr><td><code>confirm_status</code></td><td>VARCHAR(10) DEFAULT 'pending'</td><td>pending / approved / rejected / skipped</td></tr>
                        <tr><td><code>confirm_at</code></td><td>DATETIME NULL</td><td>결재 처리 일시</td></tr>
                        <tr><td><code>comment</code></td><td>NVARCHAR(1000) NULL</td><td>결재 의견</td></tr>
                        <tr><td><code>sign_url</code></td><td>VARCHAR(500) NULL</td><td>서명 이미지 URL</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-tbl-title">Tb_ApprLinePreset — 결재선 프리셋</div>
                <p class="manual-desc">자주 쓰는 결재선 저장. 다음번 결재 시 불러오기.</p>
                <table class="manual-tbl">
                    <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx</code></td><td>INT PK</td><td></td></tr>
                        <tr><td><code>tenant_id</code></td><td>INT</td><td></td></tr>
                        <tr><td><code>preset_name</code></td><td>NVARCHAR(100)</td><td>프리셋 이름</td></tr>
                        <tr><td><code>owner_emp_idx</code></td><td>INT</td><td>프리셋 소유 직원</td></tr>
                        <tr><td><code>line_json</code></td><td>NVARCHAR(MAX)</td><td>결재선 JSON ([{emp_idx, seq, line_type}...])</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">상태 흐름도</div>
                <pre class="manual-code">기안자 작성
  └→ [status=1 작성중]
       └→ 상신
            └→ [status=2 결재대기]
                 ├→ 모든 결재자 승인 → [status=3 완결]
                 ├→ 한 명이라도 반려 → [status=5 반려]
                 └→ 기안자 회수    → [status=4 회수] → 재작성 가능</pre>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 → V2 컬럼 대응표</div>
                <table class="manual-tbl">
                    <thead><tr><th>V1 (Tb_ElectronicApproval)</th><th>V2</th></tr></thead>
                    <tbody>
                        <tr><td><code>idx, title, content</code></td><td>Tb_ApprDoc.idx, title, content</td></tr>
                        <tr><td><code>approval_gubun</code> (숫자 1~6)</td><td>Tb_ApprDoc.gubun (문자열)</td></tr>
                        <tr><td><code>status</code> (1~5 동일)</td><td>Tb_ApprDoc.status (동일)</td></tr>
                        <tr><td><code>owner_1~5, owner_confirm_1~5</code></td><td>Tb_ApprLine (seq 1~N)</td></tr>
                        <tr><td><code>owner_confirm_comment_1~5</code></td><td>Tb_ApprLine.comment</td></tr>
                        <tr><td><code>ref_1~6, referencer_1~6</code></td><td>Tb_ApprLine (line_type='ref')</td></tr>
                        <tr><td><code>doc_title</code> (문서번호)</td><td>Tb_ApprDoc.doc_no</td></tr>
                        <tr><td><code>employee_idx</code></td><td>Tb_ApprDoc.writer_emp_idx</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ V1 DB 참조 ══════════════════ -->
        <section class="manual-sec" id="sec-db-v1">
            <h2 class="manual-sec-title"><i class="fa fa-database"></i> V1 DB 참조 규칙</h2>
            <div class="manual-card">
                <div class="manual-warn"><i class="fa fa-exclamation-triangle"></i> V1 DB (<code>CSM_C004732</code>, 66번)는 <strong>ALTER/INSERT/UPDATE/DELETE 절대 금지</strong>. SELECT 조회만 허용.</div>
                <table class="manual-tbl mt-3">
                    <thead><tr><th>V1 주요 테이블</th><th>V2 대응</th><th>비고</th></tr></thead>
                    <tbody>
                        <tr><td><code>Tb_Users</code></td><td>공용 사용</td><td>로그인 계정 — authority_idx 기준 권한</td></tr>
                        <tr><td><code>Tb_Employee</code></td><td><code>Tb_GwEmployee</code></td><td>V2에서 멀티테넌트 재설계</td></tr>
                        <tr><td><code>Tb_ElectronicApproval</code></td><td><code>Tb_ApprDoc + Tb_ApprLine</code></td><td>정규화 재설계</td></tr>
                        <tr><td><code>Tb_Dept</code></td><td><code>Tb_GwDept</code></td><td>V2 재설계</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ API ══════════════════ -->
        <section class="manual-sec" id="sec-api">
            <h2 class="manual-sec-title"><i class="fa fa-plug"></i> API 엔드포인트</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <thead><tr><th>파일</th><th>todo 주요값</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td><code>dist_process/saas/Auth.php</code></td><td>csrf, login, logout, remember_session</td><td>인증 전반</td></tr>
                        <tr><td><code>dist_process/saas/Employee.php</code></td><td>org_chart, employee_list, insert_employee, update_employee, delete_employee, toggle_hidden, dept_insert, dept_update, dept_delete, toggle_hidden, upload_photo, holiday_*, overtime_*</td><td>GRP 직원·부서·근태</td></tr>
                        <tr><td><code>dist_process/saas/AuthAudit.php</code></td><td>list</td><td>감사로그 조회 (관리자)</td></tr>
                    </tbody>
                </table>
                <div class="manual-sub-title mt-3">API 호출 패턴 (JS)</div>
                <pre class="manual-code">// GET
SHV.api.get('dist_process/saas/Employee.php', { todo: 'org_chart' })
  .then(function(res) { if (res.ok) { ... } });

// POST (CSRF 자동 주입)
SHV.api.post('dist_process/saas/Employee.php', {
    todo: 'toggle_hidden',
    idx: empIdx,
    is_hidden: 1
}).then(function(res) { ... });</pre>
            </div>
        </section>

        <!-- ══════════════════ 스토리지 ══════════════════ -->
        <section class="manual-sec" id="sec-storage">
            <h2 class="manual-sec-title"><i class="fa fa-folder-open"></i> 파일 스토리지</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <thead><tr><th>구분</th><th>주소</th><th>용도</th></tr></thead>
                    <tbody>
                        <tr><td>웹서버 FTP</td><td><code>211.116.112.67:21</code></td><td>PHP 코드 배포 전용</td></tr>
                        <tr><td>이미지 FTP</td><td><code>192.168.11.66:5090</code> (내부망)</td><td>파일·이미지 저장</td></tr>
                    </tbody>
                </table>
                <table class="manual-tbl mt-3">
                    <thead><tr><th>category 키</th><th>저장 경로</th><th>용도</th></tr></thead>
                    <tbody>
                        <tr><td><code>mat_banner</code></td><td>uploads/{tid}/mat/</td><td>품목 배너</td></tr>
                        <tr><td><code>mat_attach</code></td><td>uploads/{tid}/mat/attach/</td><td>품목 첨부</td></tr>
                        <tr><td><code>mail_attach</code></td><td>uploads/{tid}/mail/attach/</td><td>메일 첨부</td></tr>
                        <tr><td><code>common</code></td><td>uploads/{tid}/common/</td><td>공통</td></tr>
                    </tbody>
                </table>
                <div class="manual-sub-title mt-3">PHP 사용 예시</div>
                <pre class="manual-code">$storage = StorageService::forTenant($context['tenant_id']);
$result  = $storage->upload('mat_banner', $_FILES['banner'], "item_{$idx}");
// $result['url'] → https://shvq.kr/SHVQ_V2/uploads/{tid}/mat/item_11_xxx.jpg</pre>
            </div>
        </section>

        <!-- ══════════════════ 보안 ══════════════════ -->
        <section class="manual-sec" id="sec-security">
            <h2 class="manual-sec-title"><i class="fa fa-shield"></i> 보안 &amp; CSRF</h2>
            <div class="manual-card">
                <div class="manual-sub-title">CSRF 토큰 흐름</div>
                <pre class="manual-code">1. index.php — PHP 세션에서 토큰 생성
   $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

2. &lt;meta name="csrf-token" content="...token..."&gt; 출력

3. csrf.js — 페이지 로드 시 메타 태그에서 즉시 읽기
   var _token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

4. api.js POST — injectCsrf() 가 자동으로 csrf_token 추가
   (호출자가 직접 csrf_token 전달하면 무시됨 — 중복 방지)

5. PHP Employee.php 등 — CsrfService::validateFromRequest() 검증</pre>
                <div class="manual-warn mt-2"><i class="fa fa-exclamation-triangle"></i> JS에서 <code>data.csrf_token</code> 직접 세팅 금지 — api.js가 자동 처리함</div>
            </div>
        </section>

        <!-- ══════════════════ 설계서 ══════════════════ -->
        <section class="manual-sec" id="sec-design-docs">
            <h2 class="manual-sec-title"><i class="fa fa-bookmark"></i> 설계서 문서 모음</h2>
            <div class="manual-card">
                <div class="manual-warn"><i class="fa fa-exclamation-triangle"></i> DB/API/구조 변경 시, 아래 설계서 링크와 내용을 함께 업데이트할 것.</div>

                <table class="manual-tbl mt-3">
                    <thead><tr><th>구분</th><th>문서</th><th>경로</th><th>설명</th></tr></thead>
                    <tbody>
                        <tr><td>통합 설계</td><td><a href="docs/saas/SHVQ_V2_설계서_통합본.pdf" target="_blank">SHVQ_V2_설계서_통합본.pdf</a></td><td><code>docs/saas/SHVQ_V2_설계서_통합본.pdf</code></td><td>V2 전체 기능/구조 통합 문서</td></tr>
                        <tr><td>아키텍처</td><td><a href="docs/saas/SHVQ_SAAS_ARCHITECTURE_V1.md" target="_blank">SHVQ_SAAS_ARCHITECTURE_V1.md</a></td><td><code>docs/saas/SHVQ_SAAS_ARCHITECTURE_V1.md</code></td><td>SaaS 전체 아키텍처 기준 문서</td></tr>
                        <tr><td>메뉴 IA</td><td><a href="docs/saas/SHVQ_V2_MENU_IA_REDESIGN_V1.md" target="_blank">SHVQ_V2_MENU_IA_REDESIGN_V1.md</a></td><td><code>docs/saas/SHVQ_V2_MENU_IA_REDESIGN_V1.md</code></td><td>메뉴 구조/정보 구조 정의</td></tr>
                        <tr><td>DB 매트릭스</td><td><a href="docs/saas/SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md" target="_blank">SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md</a></td><td><code>docs/saas/SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md</code></td><td>테이블 변경 이력 및 영향도</td></tr>
                        <tr><td>API 전환</td><td><a href="docs/saas/SHVQ_V2_API_TRANSITION_MATRIX_V1.md" target="_blank">SHVQ_V2_API_TRANSITION_MATRIX_V1.md</a></td><td><code>docs/saas/SHVQ_V2_API_TRANSITION_MATRIX_V1.md</code></td><td>V1→V2 API 전환 매트릭스</td></tr>
                        <tr><td>API 인벤토리</td><td><a href="docs/saas/SHVQ_V2_API_TODO_ACTION_INVENTORY_20260413.md" target="_blank">SHVQ_V2_API_TODO_ACTION_INVENTORY_20260413.md</a></td><td><code>docs/saas/SHVQ_V2_API_TODO_ACTION_INVENTORY_20260413.md</code></td><td>todo/action 단위 API 목록</td></tr>
                        <tr><td>API CRUD</td><td><a href="docs/saas/SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md" target="_blank">SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md</a></td><td><code>docs/saas/SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md</code></td><td>테이블 CRUD 연계 문서</td></tr>
                        <tr><td>GRP API</td><td><a href="docs/saas/SHVQ_V2_GRP_API_SPEC_20260413.md" target="_blank">SHVQ_V2_GRP_API_SPEC_20260413.md</a></td><td><code>docs/saas/SHVQ_V2_GRP_API_SPEC_20260413.md</code></td><td>그룹웨어 API 상세 스펙</td></tr>
                        <tr><td>메일 아키텍처</td><td><a href="docs/saas/SHVQ_V2_MAIL_ARCHITECTURE_V3_20260414.md" target="_blank">SHVQ_V2_MAIL_ARCHITECTURE_V3_20260414.md</a></td><td><code>docs/saas/SHVQ_V2_MAIL_ARCHITECTURE_V3_20260414.md</code></td><td>메일+실시간 알림 SaaS 아키텍처 (v3.2 최종) — 만명 업장회원, 16GB 서버, IndexedDB+FCM+SSE</td></tr>
                        <tr><td>메일 스모크테스트</td><td><a href="docs/saas/SHVQ_V2_MAIL_PHASE4_SMOKE_REGRESSION_20260414.md" target="_blank">SHVQ_V2_MAIL_PHASE4_SMOKE_REGRESSION_20260414.md</a></td><td><code>docs/saas/SHVQ_V2_MAIL_PHASE4_SMOKE_REGRESSION_20260414.md</code></td><td>Phase 4 전/후 스모크 + 회귀 테스트 결과</td></tr>
                        <tr><td>메일 배포 체크리스트</td><td><a href="docs/saas/SHVQ_V2_MAIL_PROD_DEPLOY_CHECKLIST_20260414.md" target="_blank">SHVQ_V2_MAIL_PROD_DEPLOY_CHECKLIST_20260414.md</a></td><td><code>docs/saas/SHVQ_V2_MAIL_PROD_DEPLOY_CHECKLIST_20260414.md</code></td><td>운영 배포 순서, 헬스체크, 장애 시 롤백</td></tr>
                    </tbody>
                </table>

                <div class="manual-sub-title mt-3">설계서 추가/변경 규칙</div>
                <table class="manual-tbl">
                    <thead><tr><th>항목</th><th>규칙</th></tr></thead>
                    <tbody>
                        <tr><td>신규 문서</td><td><code>docs/saas/</code> 하위에 생성 후, 본 메뉴 섹션에 링크 즉시 추가</td></tr>
                        <tr><td>문서명</td><td>주제 + 버전/날짜 포함 형식 유지 (예: <code>..._V1.md</code>, <code>..._20260414.md</code>)</td></tr>
                        <tr><td>변경 동기화</td><td>코드/DB/API 배포와 같은 라운드에서 문서 반영 및 DevLog 기록 동시 수행</td></tr>
                        <tr><td>검증</td><td>링크 클릭 가능 여부, 경로 오탈자, 최신 파일 참조 여부 점검</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 프론트엔드 규칙 ══════════════════ -->
        <section class="manual-sec" id="sec-rule-front">
            <h2 class="manual-sec-title"><i class="fa fa-paint-brush"></i> 프론트엔드 규칙</h2>
            <div class="manual-card">
                <table class="manual-tbl">
                    <thead><tr><th>규칙</th><th>내용</th></tr></thead>
                    <tbody>
                        <tr><td>CSS 프레임워크</td><td>부트스트랩 <strong>사용 안 함</strong>. css/v2/ 순수 CSS만.</td></tr>
                        <tr><td>inline style</td><td><strong>금지</strong>. JS show/hide용 <code>element.style.display</code>는 예외.</td></tr>
                        <tr><td>alert/confirm/prompt</td><td><strong>금지</strong>. <code>shvConfirm({message, type}).then()</code> + <code>SHV.toast.error()</code> 사용.</td></tr>
                        <tr><td>CSS 작성 순서</td><td>위→아래 위치 순. 레이아웃→내부→컴포넌트→상태→반응형.</td></tr>
                        <tr><td>반응형</td><td>PC → 태블릿 1024px → 모바일 768px 필수.</td></tr>
                        <tr><td>폼 그리드</td><td><code>form-row--2col</code> (2열), <code>form-row--3col</code> (3열), <code>form-group--full</code> (전체폭).</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ FTP 배포 ══════════════════ -->
        <section class="manual-sec" id="sec-rule-ftp">
            <h2 class="manual-sec-title"><i class="fa fa-upload"></i> FTP 배포</h2>
            <div class="manual-card">
                <div class="manual-sub-title">업로드 명령어</div>
                <pre class="manual-code">curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" \
  -T {로컬파일} \
  "ftp://211.116.112.67:21/SHVQ_V2/{서버경로}"</pre>
                <div class="manual-sub-title mt-3">파일 수정 전 반드시 서버 최신본 다운로드</div>
                <pre class="manual-code">curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" \
  "ftp://211.116.112.67:21/SHVQ_V2/{서버경로}" \
  -o /tmp/{파일명}</pre>
                <div class="manual-warn mt-2"><i class="fa fa-exclamation-triangle"></i> 서버 파일이 더 최신이면 덮어쓰지 말고 머지 후 업로드.</div>
            </div>
        </section>

        <!-- ══════════════════ 개발일지 (모듈별) ══════════════════ -->
        <section class="manual-sec" id="sec-devlog">
            <h2 class="manual-sec-title"><i class="fa fa-code-fork"></i> 개발일지 (모듈별)</h2>
            <div class="manual-card">
                <div class="manual-warn"><i class="fa fa-exclamation-triangle"></i> 모든 작업은 <strong>개발 완료 직후</strong> DevLog API에 기록. 모듈 단위로 분리해서 남길 것.</div>

                <div class="manual-sub-title mt-3">기본 기록 규칙</div>
                <table class="manual-tbl">
                    <thead><tr><th>항목</th><th>규칙</th></tr></thead>
                    <tbody>
                        <tr><td>category</td><td>모듈명 사용 (예: <code>FMS</code>, <code>PMS</code>, <code>BMS</code>, <code>GRP</code>, <code>MAIL</code>, <code>MAT</code>, <code>CAD</code>, <code>FACILITY</code>, <code>API</code>, <code>MANAGE</code>, <code>COMMON</code>)</td></tr>
                        <tr><td>title</td><td><code>[모듈] 작업요약</code> 형식 권장 (예: <code>[MAIL] 목록 필터 UX 개선</code>)</td></tr>
                        <tr><td>content</td><td>변경 이유 + 핵심 변경점 + 위험/주의사항 + 배포 경로</td></tr>
                        <tr><td>file_count</td><td>실제 수정/추가 파일 건수</td></tr>
                        <tr><td>dev_time</td><td>서버 반영 시각 기준 (<code>YYYY-MM-DD HH:MM:SS</code>)</td></tr>
                    </tbody>
                </table>

                <div class="manual-sub-title mt-3">모듈별 category 예시</div>
                <table class="manual-tbl">
                    <thead><tr><th>영역</th><th>category</th><th>예시 title</th></tr></thead>
                    <tbody>
                        <tr><td>고객/현장</td><td><code>FMS</code></td><td><code>[FMS] 본사관리 목록 정렬 개선</code></td></tr>
                        <tr><td>견적/회의</td><td><code>PMS</code></td><td><code>[PMS] 견적현황 검색 조건 추가</code></td></tr>
                        <tr><td>구매/매출</td><td><code>BMS</code></td><td><code>[BMS] 매출등록 폼 검증 보강</code></td></tr>
                        <tr><td>그룹웨어</td><td><code>GRP</code></td><td><code>[GRP] 결재 대기목록 상태 배지 정리</code></td></tr>
                        <tr><td>웹메일</td><td><code>MAIL</code></td><td><code>[MAIL] 초안 자동저장 문구 통일</code></td></tr>
                        <tr><td>자재/재고</td><td><code>MAT</code></td><td><code>[MAT] 입고 이력 필터 반응형 개선</code></td></tr>
                        <tr><td>CAD</td><td><code>CAD</code></td><td><code>[CAD] 입력 모달 공통화 1차</code></td></tr>
                        <tr><td>시설/IoT</td><td><code>FACILITY</code></td><td><code>[FACILITY] 도어락 상태 패널 정렬 수정</code></td></tr>
                        <tr><td>도구/API</td><td><code>API</code></td><td><code>[API] WS 모니터링 테이블 컬럼 보강</code></td></tr>
                        <tr><td>관리/설정</td><td><code>MANAGE</code></td><td><code>[MANAGE] 인증감사로그 조회 UX 개선</code></td></tr>
                        <tr><td>공통(토큰/레이아웃)</td><td><code>COMMON</code></td><td><code>[COMMON] 공통 버튼 토큰 정리</code></td></tr>
                    </tbody>
                </table>

                <div class="manual-sub-title mt-3">DevLog 등록 템플릿</div>
                <pre class="manual-code">curl -s -X POST "http://211.116.112.67/SHVQ/dist_process/DevLog.php" \
  --data-urlencode "todo=insert" \
  --data-urlencode "system_type=V2" \
  --data-urlencode "category={모듈}" \
  --data-urlencode "title=[{모듈}] {작업 제목}" \
  --data-urlencode "content={변경 요약}" \
  --data-urlencode "status=1" \
  --data-urlencode "dev_time=YYYY-MM-DD HH:MM:SS" \
  --data-urlencode "file_count={수정 파일 수}"</pre>
            </div>
        </section>

        <!-- ══════════════════ 권한 레벨 ══════════════════ -->
        <section class="manual-sec" id="sec-role-level">
            <h2 class="manual-sec-title"><i class="fa fa-key"></i> 권한 레벨 (authority_idx)</h2>
            <div class="manual-card">
                <div class="manual-warn"><i class="fa fa-exclamation-triangle"></i> <strong>낮을수록 높은 권한</strong> — ChatGPT PHP 백엔드 작성 시 반드시 준수.</div>
                <table class="manual-tbl mt-3">
                    <thead><tr><th>authority_idx</th><th>역할</th><th>허용 작업</th></tr></thead>
                    <tbody>
                        <tr><td><strong>1</strong></td><td>최고관리자</td><td>모든 기능</td></tr>
                        <tr><td><strong>2</strong></td><td>관리자</td><td>직원 삭제·숨김, 부서 삭제, 결재 관리</td></tr>
                        <tr><td><strong>3</strong></td><td>부서장</td><td>휴가·초과근무 결재, 팀원 조회</td></tr>
                        <tr><td><strong>4</strong></td><td>일반사원</td><td>본인 정보 조회·수정, 결재 상신</td></tr>
                    </tbody>
                </table>
                <div class="manual-sub-title mt-3">PHP 체크 패턴</div>
                <pre class="manual-code">$roleLevel = (int)($context['role_level'] ?? 0); // = authority_idx 값

// 관리자급 이상만 허용 (authority_idx 1, 2)
if ($roleLevel > 2) {
    ApiResponse::error('FORBIDDEN', 'insufficient role level', 403);
    exit;
}

// 부서장 이상만 허용 (authority_idx 1, 2, 3)
if ($roleLevel > 3) {
    ApiResponse::error('FORBIDDEN', 'insufficient role level', 403);
    exit;
}</pre>
            </div>
        </section>

        <!-- ══════════════════ 메뉴얼 업데이트 규칙 ══════════════════ -->
        <section class="manual-sec" id="sec-manual-update">
            <h2 class="manual-sec-title"><i class="fa fa-pencil-square-o"></i> 메뉴얼 업데이트 규칙</h2>

            <div class="manual-card">
                <div class="manual-warn">
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong>아래 상황이 발생하면 이 메뉴얼 페이지를 반드시 업데이트해야 합니다.</strong><br>
                    Claude(프론트 담당) 또는 ChatGPT(백엔드 담당) 누구든 해당 변경을 완료한 시점에 즉시 반영합니다.
                </div>

                <table class="manual-tbl mt-3">
                    <thead>
                        <tr><th style="width:200px">트리거 상황</th><th>업데이트해야 할 내용</th><th style="width:120px">담당</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>DB 테이블 신규 생성</strong></td>
                            <td>테이블명, 컬럼 목록(타입·제약·설명), 연관 테이블, 인덱스, 마이그레이션 파일 경로 → <em>해당 DB 설명 섹션</em>에 추가</td>
                            <td>ChatGPT (백엔드)</td>
                        </tr>
                        <tr>
                            <td><strong>DB 컬럼 추가/변경/삭제</strong></td>
                            <td>변경된 컬럼, 이유, 마이그레이션 SQL 스니펫 → <em>해당 테이블 설명</em> 업데이트</td>
                            <td>ChatGPT (백엔드)</td>
                        </tr>
                        <tr>
                            <td><strong>API 엔드포인트 추가</strong></td>
                            <td>파일 경로, todo 파라미터 목록, 요청/응답 형식, 권한 레벨 → <em>API 엔드포인트 섹션</em>에 추가</td>
                            <td>ChatGPT (백엔드)</td>
                        </tr>
                        <tr>
                            <td><strong>API 파라미터/응답 변경</strong></td>
                            <td>변경 전·후 키 이름, 타입, 영향받는 프론트 파일 명시</td>
                            <td>ChatGPT (백엔드)</td>
                        </tr>
                        <tr>
                            <td><strong>신규 뷰(페이지) 개발 완료</strong></td>
                            <td>라우트명, 파일 경로, 연동 API, 주요 기능 요약 → <em>변경 이력 섹션</em>에 추가</td>
                            <td>Claude (프론트)</td>
                        </tr>
                        <tr>
                            <td><strong>CSS/JS 공통 클래스·함수 추가</strong></td>
                            <td>클래스명, 용도, 사용 예시 → <em>프론트엔드 규칙 섹션</em>에 추가</td>
                            <td>Claude (프론트)</td>
                        </tr>
                        <tr>
                            <td><strong>권한 레벨 체크 로직 변경</strong></td>
                            <td>변경된 roleLevel 기준, 영향받는 API/기능 → <em>권한 레벨 섹션</em> 업데이트</td>
                            <td>ChatGPT (백엔드)</td>
                        </tr>
                        <tr>
                            <td><strong>FTP·환경설정 변경</strong></td>
                            <td>서버 주소, 경로, .env 키 변경 사항 → <em>FTP 배포 섹션</em> 업데이트</td>
                            <td>양쪽 공통</td>
                        </tr>
                        <tr>
                            <td><strong>버그 수정 (영향 범위 큰 것)</strong></td>
                            <td>버그 내용, 수정 방법, 재발 방지 패턴 → <em>변경 이력 섹션</em>에 추가</td>
                            <td>발견한 담당</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">메뉴얼 업데이트 절차</div>
                <pre class="manual-code"># 1. 서버 최신본 다운로드
curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" \
  "ftp://211.116.112.67:21/SHVQ_V2/views/saas/manage/manual.php" > /tmp/manual.php

# 2. 해당 섹션에 내용 추가/수정

# 3. 서버 업로드
curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" \
  -T /tmp/manual.php \
  "ftp://211.116.112.67:21/SHVQ_V2/views/saas/manage/manual.php"</pre>

                <div class="manual-sub-title mt-3">섹션 ID 목록</div>
                <table class="manual-tbl">
                    <thead><tr><th>섹션 ID</th><th>내용</th><th>담당</th></tr></thead>
                    <tbody>
                        <tr><td><code>sec-db-auth</code></td><td>인증·보안 테이블</td><td>ChatGPT</td></tr>
                        <tr><td><code>sec-db-grp</code></td><td>GRP 그룹웨어 테이블</td><td>ChatGPT</td></tr>
                        <tr><td><code>sec-db-appr</code></td><td>전자결재 테이블</td><td>ChatGPT</td></tr>
                        <tr><td><code>sec-api</code></td><td>API 엔드포인트</td><td>ChatGPT</td></tr>
                        <tr><td><code>sec-rule-front</code></td><td>프론트엔드 규칙</td><td>Claude</td></tr>
                        <tr><td><code>sec-changelog-appr</code></td><td>전자결재 변경 이력</td><td>Claude</td></tr>
                        <tr><td><code>sec-changelog-grp</code></td><td>GRP 변경 이력</td><td>Claude</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ FMS 모듈 분석 ══════════════════ -->

        <!-- ── 3계층 구조 ── -->
        <section class="manual-sec" id="sec-fms-hierarchy">
            <h2 class="manual-sec-title"><i class="fa fa-sitemap"></i> 3계층 구조 (본사 / 사업장 / 현장)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">데이터 계층도</div>
                <pre class="manual-code">Tb_HeadOffice (본사)
  │ head_number: HO+YYMMDD+NNN (자동생성)
  │ head_structure: 법인/개인/기타 (V2) | 단일/다중 (V1)
  │
  └─ 1:N ─→ Tb_Members (사업장)
               head_idx → Tb_HeadOffice.idx
               link_status: 요청/연결/중단
               member_status: 예정/운영/중지/종료
               │
               ├─ 1:N ─→ Tb_Site (현장)
               │            site_status: 예정/진행/중지/완료
               │            site_number: SH00001 (자동생성)
               │            │
               │            ├─ Tb_SiteEstimate (견적)
               │            │    └─ Tb_EstimateItem (견적품목)
               │            ├─ Tb_Activity (업무활동) — fieldManager1~4
               │            ├─ Tb_PjtPlan (프로젝트계획)
               │            │    ├─ Tb_PjtPlanPhase (단계)
               │            │    └─ Tb_PjtPlanEstItem (단계별견적품목)
               │            ├─ Tb_Bill (청구)
               │            ├─ Tb_CAD_Drawing (도면)
               │            ├─ Tb_SiteContact (현장담당자)
               │            └─ Tb_Site_Access_inout (출입기록)
               │
               ├─ 1:N ─→ Tb_Users_fieldManager (현장소장)
               ├─ 1:N ─→ Tb_PhoneBook (연락처)
               └─ 1:N ─→ Tb_BranchOrgFolder (조직폴더)</pre>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 API 현황</div>
                <table class="manual-tbl">
                    <tr><th>엔티티</th><th>API 파일</th><th>액션 수</th><th>프론트 뷰</th></tr>
                    <tr><td>본사</td><td>HeadOffice.php</td><td>9 (list/detail/check_dup/insert/update/bulk_update/restore/delete/delete_attach)</td><td>member_head.php + member_head_add.php</td></tr>
                    <tr><td>사업장</td><td>Member.php</td><td>22+ (list/detail/check_dup/insert/update/inline_update/delete/restore/bulk_action/link_head/unlink_head/update_link_status/group_list/employee_list/region_list/get_required/save_member_required/save_member_regions/branch_folder_*/bill_list)</td><td>member_branch.php + member_branch_add.php + member_branch_view.php + branch_settings.php + member_settings.php + link_head.php</td></tr>
                    <tr><td>코멘트</td><td>Comment.php</td><td>4 (list/insert/delete/send→insert alias)</td><td>SHV.chat 공용모듈 (js/ui/chat.js)</td></tr>
                    <tr><td>OCR</td><td>OCR.php</td><td>2 (ocr_engines/ocr_scan)</td><td>member_branch_add.php 비전AI 바</td></tr>
                    <tr><td>현장</td><td>Site.php</td><td>6 (list/detail/search/insert/update/delete)</td><td>미구현</td></tr>
                    <tr><td>견적</td><td>Site.php</td><td>12 (est_list~est_pdf_data)</td><td>미구현</td></tr>
                </table>
            </div>
        </section>

        <!-- ── 현장소장 ── -->
        <section class="manual-sec" id="sec-fms-fieldmanager">
            <h2 class="manual-sec-title"><i class="fa fa-user-circle-o"></i> 현장소장 (FieldManager)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">V1 DB: Tb_Users_fieldManager</div>
                <table class="manual-tbl">
                    <tr><th>컬럼</th><th>타입</th><th>설명</th></tr>
                    <tr><td>idx</td><td>INT PK</td><td>고유 ID</td></tr>
                    <tr><td>member_idx</td><td>INT FK</td><td>사업장 ID</td></tr>
                    <tr><td>site_idx</td><td>INT FK</td><td>현장 ID</td></tr>
                    <tr><td>name</td><td>VARCHAR(30)</td><td>성명</td></tr>
                    <tr><td>passwd</td><td>VARCHAR(20)</td><td>비밀번호 (V1 평문 / V2 bcrypt 필요)</td></tr>
                    <tr><td>sosok</td><td>VARCHAR</td><td>소속/부서</td></tr>
                    <tr><td>part</td><td>VARCHAR(30)</td><td>직급/직책</td></tr>
                    <tr><td>hp</td><td>VARCHAR(13)</td><td>연락처</td></tr>
                    <tr><td>email</td><td>VARCHAR(100)</td><td>이메일</td></tr>
                    <tr><td>comment</td><td>VARCHAR(30)</td><td>비고</td></tr>
                    <tr><td>employee_idx</td><td>INT FK</td><td>등록 직원 (서버 실운영에서 추가)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 API: dist_process/FieldManager.php (5개 액션)</div>
                <table class="manual-tbl">
                    <tr><th>todo</th><th>기능</th><th>비고</th></tr>
                    <tr><td>InsertFieldManager</td><td>등록</td><td>HP 중복체크 (서버: 중복허용으로 변경됨)</td></tr>
                    <tr><td>UpdateFieldManager</td><td>수정</td><td>비밀번호 입력 시만 PW 갱신</td></tr>
                    <tr><td>DeleteFieldManager</td><td>삭제</td><td>Hard delete</td></tr>
                    <tr><td>excel_download</td><td>엑셀 다운로드</td><td>서버: fm_name/fm_num 검색 필터 적용</td></tr>
                    <tr><td>SelectOption</td><td>AJAX 드롭다운</td><td>member_idx + site_idx 기반 option HTML</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">타 모듈 연동 — fieldManager1~4 슬롯</div>
                <table class="manual-tbl">
                    <tr><th>모듈</th><th>파일</th><th>사용 방식</th></tr>
                    <tr><td>활동관리</td><td>add_activity.php / control_activity.php</td><td>select2 드롭다운 4슬롯</td></tr>
                    <tr><td>지원요청</td><td>add_support.php</td><td>select2 드롭다운 4슬롯</td></tr>
                    <tr><td>업무보고</td><td>add_task_report.php / control_task_report.php</td><td>select2 드롭다운 4슬롯</td></tr>
                    <tr><td>주문</td><td>add_order.php / add_order_work.php</td><td>select2 드롭다운 4슬롯</td></tr>
                    <tr><td>갤러리</td><td>add_gallery.php</td><td>select2 드롭다운 4슬롯</td></tr>
                </table>
                <p class="mt-2 text-sm opacity-70">PJT/견적에는 직접 fieldManager 컬럼 없음 — Activity를 통해 간접 연결</p>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 구현 상태</div>
                <p><span class="badge badge-warn">미착수</span> — FmsInputValidator.php만 존재. 백엔드 API/프론트 뷰 미구현</p>
            </div>
        </section>

        <!-- ── PJT ── -->
        <section class="manual-sec" id="sec-fms-pjt">
            <h2 class="manual-sec-title"><i class="fa fa-folder-open-o"></i> PJT (프로젝트)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">V1 핵심 테이블</div>
                <table class="manual-tbl">
                    <tr><th>테이블</th><th>용도</th></tr>
                    <tr><td>Tb_Activity</td><td>업무/활동 마스터 (PJT 단위 포함, fieldManager1~4)</td></tr>
                    <tr><td>Tb_PjtPlan</td><td>프로젝트 계획 마스터</td></tr>
                    <tr><td>Tb_PjtPlanPhase</td><td>단계별 계획 (status 0/1/3, deadline, sort_order)</td></tr>
                    <tr><td>Tb_PjtPlanEstItem</td><td>단계별 견적 품목 (check_qty_limit 검증용)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">PJT 상태 — $Activity['project']['status']</div>
                <table class="manual-tbl">
                    <tr><th>키</th><th>값</th><th>트리거</th></tr>
                    <tr><td>1</td><td>지시</td><td>최초 생성</td></tr>
                    <tr><td>2</td><td>지정</td><td>작업자 지정 시</td></tr>
                    <tr><td>3</td><td>첨부</td><td>파일 첨부 시</td></tr>
                    <tr><td>4</td><td>완료</td><td>완료 버튼</td></tr>
                    <tr><td>5</td><td>초과</td><td>마감일자 경과</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">PJT 필터링 모드</div>
                <table class="manual-tbl">
                    <tr><th>mode</th><th>대상</th><th>권한</th></tr>
                    <tr><td>all</td><td>전체 프로젝트</td><td>경영관리팀만</td></tr>
                    <tr><td>team</td><td>팀별</td><td>소속팀</td></tr>
                    <tr><td>ac</td><td>담당자/작업자별</td><td>본인</td></tr>
                    <tr><td>dist_all</td><td>캘린더 전체</td><td>전체</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">check_qty_limit API (주의!)</div>
                <p><code>dist_process/Project.php</code> — PJT 수량제한 API</p>
                <p>Tb_EstimateItem + Tb_PjtPlanEstItem(미확정 단계) 합산 검증. 속성별 제한 로직(총수량/품목갯수 모드). <span class="text-danger">수정 시 반드시 사용자 재확인 필요</span></p>
            </div>
        </section>

        <!-- ── 견적 ── -->
        <section class="manual-sec" id="sec-fms-estimate">
            <h2 class="manual-sec-title"><i class="fa fa-file-text-o"></i> 견적 (Estimate)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">V1 견적 상태 — $Site['estimate_status']</div>
                <table class="manual-tbl">
                    <tr><th>키</th><th>값</th><th>버튼 CSS</th></tr>
                    <tr><td>1</td><td>미승인</td><td>btn-secondary</td></tr>
                    <tr><td>2</td><td>승인</td><td>btn-primary</td></tr>
                    <tr><td>3</td><td>수주</td><td>btn-success</td></tr>
                    <tr><td>4</td><td>실패</td><td>btn-danger</td></tr>
                    <tr><td>5</td><td>매출</td><td>btn-warning</td></tr>
                </table>
                <p class="mt-2 text-sm opacity-70">V2 현재: DRAFT/APPROVED/CANCELLED 3단계만 (이관 필요)</p>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">견적 연동 관계 (V1 FK)</div>
                <table class="manual-tbl">
                    <tr><th>FK 컬럼</th><th>연동 대상</th><th>V2</th></tr>
                    <tr><td>relative_activity_idx</td><td>업무(Activity)</td><td>미구현</td></tr>
                    <tr><td>relative_bill_idx</td><td>청구(Bill)</td><td>미구현</td></tr>
                    <tr><td>relative_purchase_idx</td><td>자재(Purchase)</td><td>미구현</td></tr>
                    <tr><td>relative_typesale_idx</td><td>매출유형(TypeSale)</td><td>미구현</td></tr>
                    <tr><td>relative_agency_idx</td><td>대리점(Agency)</td><td>미구현</td></tr>
                    <tr><td>Tb_SiteEstimateG_idx</td><td>그룹견적</td><td>미구현</td></tr>
                    <tr><td>sale_idx</td><td>상품정보(Sale)</td><td>미구현</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">견적 수정/삭제 제한 (V1 비즈니스 규칙)</div>
                <table class="manual-tbl">
                    <tr><th>조건</th><th>수정불가</th><th>삭제불가</th></tr>
                    <tr><td>세금계산서 전송완료 (status=3)</td><td>O</td><td>O</td></tr>
                    <tr><td>수금완료 (Bill status=4)</td><td>O</td><td>-</td></tr>
                    <tr><td>청구 존재 (Bill status&gt;0)</td><td>-</td><td>O</td></tr>
                    <tr><td>활동 존재</td><td>-</td><td>O</td></tr>
                    <tr><td>상품 존재</td><td>-</td><td>O</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">품목 속성 — $ITEM_PROPERTY (V1 config.php 기준)</div>
                <table class="manual-tbl">
                    <tr><th>키</th><th>값</th><th>색상</th></tr>
                    <tr><td>0</td><td>없음</td><td style="background:#94a3b8;color:#fff">#94a3b8</td></tr>
                    <tr><td>1</td><td>HDEL_표준B</td><td style="background:#3b82f6;color:#fff">#3b82f6</td></tr>
                    <tr><td>2</td><td>HDEL표준A</td><td style="background:#10b981;color:#fff">#10b981</td></tr>
                    <tr><td>3</td><td>HDEL_비표준A</td><td style="background:#f59e0b;color:#fff">#f59e0b</td></tr>
                    <tr><td>4</td><td>HDEL_비표준B</td><td style="background:#e11d48;color:#fff">#e11d48</td></tr>
                    <tr><td>5</td><td>HDEL_보수</td><td style="background:#8b5cf6;color:#fff">#8b5cf6</td></tr>
                    <tr><td>6</td><td>HDEL_리모델링</td><td style="background:#ef4444;color:#fff">#ef4444</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 견적 모달 V1 통합 이식 (2026-04-19)</div>
                <p class="text-sm">V1 add_estimate.php (1460줄) → V2 est_add.php + est_pick.php 리팩터링 이식 완료.</p>
                <table class="manual-tbl mt-2">
                    <tr><th>영역</th><th>구현</th><th>위치</th></tr>
                    <tr><td>견적 모달 헤더</td><td>견적항목 카운트 배지 + 사업장명 + PJT 태그</td><td>est_add.php</td></tr>
                    <tr><td>합계(권고액)</td><td>22px 큰 폰트 강조</td><td>est_add.php</td></tr>
                    <tr><td>카트 본체+구성 합산</td><td>구성품은 부모 행 안에 "구성: A, B" 표시</td><td>est_add.php renderCart</td></tr>
                    <tr><td>autoEstName</td><td>첫 품목명 + " 외 N건" 자동 생성 (사용자 편집 후 중단)</td><td>est_add.php</td></tr>
                    <tr><td>구버전 매핑 영역</td><td>완료/대기/실패 배지 + summary 카운트 (수정 모드)</td><td>est_add.php</td></tr>
                    <tr><td>품목 검색 모달</td><td>frequent_items 우선 → list 폴백 / 카드 카테고리 + 자재번호 표시</td><td>est_pick.php</td></tr>
                    <tr><td>상세 모드 토글</td><td>OFF=카드 클릭 즉시 카트 추가 / ON=별도 overlay 팝업</td><td>est_pick.php</td></tr>
                    <tr><td>구성품 자동 로드</td><td>component_list todo (Tb_ItemComponent 기반, parent_idx 컬럼 없음)</td><td>est_pick.php</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">백엔드 API 신규 (2026-04-19)</div>
                <table class="manual-tbl">
                    <tr><th>todo</th><th>endpoint</th><th>용도</th></tr>
                    <tr><td>est_tab_list</td><td>Site.php</td><td>사이트별 PJT 탭 목록 (V1 use_estimate_idxs fallback)</td></tr>
                    <tr><td>est_legacy_mapping_status</td><td>Site.php</td><td>견적 품목별 V1→V2 매핑 상태 (완료/대기/실패)</td></tr>
                    <tr><td>est_category_badges</td><td>Site.php</td><td>카테고리 옵션 배지 + region 매칭 (option_1~10 fallback)</td></tr>
                    <tr><td>item_property_master</td><td>Material.php</td><td>PJT 속성 마스터 (Tb_UserSettings ITEM_PROPERTY/ITEM_PROPERTY_COLORS)</td></tr>
                    <tr><td>list (cat_idx 필터 + include_subtree)</td><td>Material.php</td><td>cat_idx 필터 버그 수정 + include_subtree=1 옵션 (하위 카테고리 포함)</td></tr>
                    <tr><td>insert_est / update_est</td><td>Site.php</td><td>cost_total / increase_amount 저장 추가</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">DB 스키마 변경</div>
                <table class="manual-tbl">
                    <tr><th>테이블</th><th>컬럼</th><th>마이그레이션</th></tr>
                    <tr><td>Tb_SiteEstimate</td><td>cost_total int NULL (신규 추가)</td><td>20260419_wave13_site_estimate_cost_total.sql</td></tr>
                    <tr><td>Tb_Item</td><td>attribute (V1에는 있으나 V2 누락 — 별도 마이그레이션 대기)</td><td>예정</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">디자인 시스템 — REFINED DARK LIQUID GLASS</div>
                <p class="text-sm">Claude Design 리디자인 — 다크 테마 글래스 오버라이드 (전 페이지 자동 적용).</p>
                <p class="text-sm">위치: <code>css/v2/glass.css</code> 끝 (137 → 363줄, +222줄)</p>
                <ul class="text-sm mt-2" style="padding-left:20px;">
                    <li>Body 배경: 깊은 미드나이트 + 3중 라디얼 그라디언트</li>
                    <li>Topbar / Sidebar: 프리미엄 네이비 글래스</li>
                    <li>KPI 카드: 색상 액센트 상단 바 (4색) + hover 떠오름</li>
                    <li>채도 낮춤 (8시간 작업 피로도 ↓)</li>
                </ul>
            </div>
        </section>

        <!-- ── V1 Config 전수 ── -->
        <section class="manual-sec" id="sec-fms-config">
            <h2 class="manual-sec-title"><i class="fa fa-cogs"></i> V1 Config 옵션 전수 (서버 실운영 기준)</h2>
            <div class="manual-card">
                <p class="text-sm opacity-70">출처: SHV 서버 dist/config.php (2046줄) — 2026-04-15 기준</p>
                <table class="manual-tbl">
                    <tr><th>#</th><th>Config 위치</th><th>키</th><th>값</th><th>V2</th></tr>
                    <tr><td>1</td><td>$Site</td><td>estimate_status</td><td>1미승인/2승인/3수주/4실패/5매출</td><td>3단계만</td></tr>
                    <tr><td>2</td><td>$Site</td><td>estimate_fcm</td><td>O/X/요청</td><td>미구현</td></tr>
                    <tr><td>3</td><td>$Site</td><td>deadline_status</td><td>0입력/1진행/2완료/3마감</td><td>미구현</td></tr>
                    <tr><td>4</td><td>$Site</td><td>warranty_period</td><td>1/6/12/24/36 (개월)</td><td>미구현</td></tr>
                    <tr><td>5</td><td>$Site</td><td>safety_management_expenses</td><td>O/X</td><td>미구현</td></tr>
                    <tr><td>6</td><td>$Site</td><td>joint_supplyNdemand</td><td>O/X</td><td>미구현</td></tr>
                    <tr><td>7</td><td>$Site</td><td>transaction_referer</td><td>1견적/2도급</td><td>미구현</td></tr>
                    <tr><td>8</td><td>$Site</td><td>monthly_installment_plan</td><td>SHV/36/1/24/12</td><td>미구현</td></tr>
                    <tr><td>9</td><td>$Activity</td><td>status</td><td>1지시/2열람/3진행/4초과/5완료/6청구</td><td>미구현</td></tr>
                    <tr><td>10</td><td>$Activity</td><td>gubun</td><td>22개 (HDEL표준~HDEL(JQPR))</td><td>미구현</td></tr>
                    <tr><td>11</td><td>$Activity</td><td>project.status</td><td>1지시/2지정/3첨부/4완료/5초과</td><td>미구현</td></tr>
                    <tr><td>12</td><td>$Activity</td><td>work_status</td><td>1지시/2열람/3진행/4완료</td><td>미구현</td></tr>
                    <tr><td>13</td><td>$Activity</td><td>work_gubun</td><td>1정상/2연장/3특근/4재택</td><td>미구현</td></tr>
                    <tr><td>14</td><td>$Activity</td><td>confirm_status</td><td>1미승인~6완료</td><td>미구현</td></tr>
                    <tr><td>15</td><td>$Bill</td><td>StatusCode</td><td>1입력/2결재/3전표/4완료/5미수/6진행</td><td>미구현</td></tr>
                    <tr><td>16</td><td>$Bill</td><td>DepositStatusCode</td><td>1통장/2현금/3어음/4상계</td><td>미구현</td></tr>
                    <tr><td>17</td><td>$Purchase</td><td>status</td><td>1입력~5지급/6반려</td><td>미구현</td></tr>
                    <tr><td>18</td><td>$TaxInvoice</td><td>taxinvoice_status</td><td>0미발행~5반려</td><td>미구현</td></tr>
                    <tr><td>19</td><td>$TaxInvoice</td><td>taxType</td><td>1과세10%/2영세0%/3면세</td><td>미구현</td></tr>
                    <tr><td>20</td><td>$TaxInvoice</td><td>BarobillState</td><td>11종 바로빌 API 상태</td><td>미구현</td></tr>
                    <tr><td>21</td><td>$TaxInvoice</td><td>NTSSendState</td><td>1전송전~5전송실패</td><td>미구현</td></tr>
                    <tr><td>22</td><td>$TaxInvoice</td><td>ModifyCode</td><td>6종 수정사유</td><td>미구현</td></tr>
                    <tr><td>23</td><td>$Product</td><td>property</td><td>6종 속성 + 색상</td><td>미구현</td></tr>
                    <tr><td>24</td><td>$Estimate</td><td>status</td><td>1입력/10분할완료/20전체승인완료</td><td>미구현</td></tr>
                    <tr><td>25</td><td>$File_config</td><td>DocumentCode</td><td>26종 문서코드</td><td>미구현</td></tr>
                    <tr><td>26</td><td>$Subcontract</td><td>status</td><td>1지시전~6반려 + 권역/출장지</td><td>미구현</td></tr>
                    <tr><td>27</td><td>$Company</td><td>gubun</td><td>도급/자재/주관사/대리점</td><td>미구현</td></tr>
                    <tr><td>28</td><td>$ElectronicApproval</td><td>approval_gubun</td><td>1지급/2협조/3품의/4공문/5기타/6내부</td><td>미구현</td></tr>
                    <tr><td>29</td><td>$Employee</td><td>official_position</td><td>1사원~8대표</td><td>미구현</td></tr>
                    <tr><td>30</td><td>$Employee</td><td>part_position</td><td>1조원/2조장/3팀장/4그룹장</td><td>미구현</td></tr>
                    <tr><td>31</td><td>$Permission</td><td>전체</td><td>allow/limit/charge/confirm/HR_*/Expense_control/Display_Estimate_Amount</td><td>미구현</td></tr>
                </table>
            </div>
        </section>

        <!-- ── V2 구현 현황 ── -->
        <section class="manual-sec" id="sec-fms-v2status">
            <h2 class="manual-sec-title"><i class="fa fa-tasks"></i> V2 구현 현황 (2026-04-15)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">모듈별 구현 상태</div>
                <table class="manual-tbl">
                    <tr><th>모듈</th><th>V2 L0</th><th>백엔드</th><th>프론트</th><th>상태</th></tr>
                    <tr><td>본사관리</td><td>FMS</td><td>HeadOffice.php (9)</td><td>member_head</td><td><span class="badge badge-ok">완료</span></td></tr>
                    <tr><td>사업장관리</td><td>FMS</td><td>Member.php (22+), Comment.php, OCR.php</td><td>목록(12컬럼)+등록(15+필드+OCR)+상세(8탭+인라인편집)+설정모달+글로벌설정+본사연결+코멘트(SHV.chat)</td><td><span class="badge badge-accent">완료</span></td></tr>
                    <tr><td>현장관리</td><td>FMS</td><td>Site.php (6)</td><td>라우트만</td><td><span class="badge badge-info">백엔드만</span></td></tr>
                    <tr><td>견적관리</td><td>FMS/PMS</td><td>Site.php (12)</td><td>라우트만</td><td><span class="badge badge-info">백엔드만</span></td></tr>
                    <tr><td>PJT</td><td>FMS</td><td>스텁만</td><td>라우트만(5)</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>업무활동</td><td>FMS</td><td>-</td><td>라우트만</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>현장소장</td><td>FMS</td><td>Validator만</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>직원관리</td><td>GRP</td><td>Employee.php</td><td>라우트만</td><td><span class="badge badge-info">백엔드만</span></td></tr>
                    <tr><td>전자결재</td><td>GRP</td><td>Approval.php</td><td>views 4파일</td><td><span class="badge badge-ok">구현</span></td></tr>
                    <tr><td>채팅</td><td>GRP</td><td>Chat.php</td><td>chat.php</td><td><span class="badge badge-ok">구현</span></td></tr>
                    <tr><td>웹메일</td><td>MAIL</td><td>Mail.php</td><td>views 5파일</td><td><span class="badge badge-ok">구현</span></td></tr>
                    <tr><td>품목/재고</td><td>MAT</td><td>Material+Stock.php</td><td>views 10파일</td><td><span class="badge badge-ok">구현</span></td></tr>
                    <tr><td>인증/보안</td><td>관리</td><td>Auth+AuthAudit</td><td>auth_audit.php</td><td><span class="badge badge-ok">구현</span></td></tr>
                    <tr><td>대시보드</td><td>FMS</td><td>Dashboard.php</td><td>라우트만</td><td><span class="badge badge-info">백엔드만</span></td></tr>
                    <tr><td>청구/수금/매출</td><td>BMS</td><td>-</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>구매관리</td><td>BMS</td><td>-</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>비용/자금/자산</td><td>BMS</td><td>-</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>세금계산서</td><td>BMS</td><td>-</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                    <tr><td>도급/SRM</td><td>FMS</td><td>-</td><td>-</td><td><span class="badge badge-warn">미착수</span></td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 vs V2 수치 요약</div>
                <table class="manual-tbl">
                    <tr><th>항목</th><th>V1</th><th>V2</th></tr>
                    <tr><td>dist_library 클래스</td><td>47개</td><td>16개</td></tr>
                    <tr><td>dist_process API</td><td>40+개</td><td>20개</td></tr>
                    <tr><td>config.php 전역 배열</td><td>31개 (2046줄)</td><td>부분 이관 (~10%)</td></tr>
                    <tr><td>전체 라우트</td><td>PC 17+모바일 14 섹션</td><td>119개 등록</td></tr>
                    <tr><td>뷰 파일 있는 라우트</td><td>-</td><td>36개 (고유 25파일)</td></tr>
                </table>
            </div>
        </section>

        <!-- ── V2 라우트 맵 ── -->
        <section class="manual-sec" id="sec-fms-route">
            <h2 class="manual-sec-title"><i class="fa fa-map-signs"></i> V2 라우트 맵 (119개)</h2>
            <div class="manual-card">
                <p class="text-sm opacity-70">출처: js/core/router.js ROUTE_MAP — file 속성 있으면 뷰 파일 존재</p>
                <table class="manual-tbl">
                    <tr><th>L0 탭</th><th>라우트 수</th><th>뷰 파일 있음</th><th>미구현</th></tr>
                    <tr><td>FMS</td><td>27</td><td>member_head.php (루트)</td><td>26</td></tr>
                    <tr><td>PMS</td><td>9</td><td>0</td><td>9</td></tr>
                    <tr><td>BMS</td><td>26</td><td>0</td><td>26</td></tr>
                    <tr><td>CTM</td><td>1</td><td>0</td><td>1</td></tr>
                    <tr><td>GRP</td><td>14</td><td>14 (views/saas/grp/)</td><td>0</td></tr>
                    <tr><td>MAIL</td><td>10</td><td>10 (views/saas/mail/)</td><td>0</td></tr>
                    <tr><td>MAT</td><td>10</td><td>10 (views/saas/mat/)</td><td>0</td></tr>
                    <tr><td>CAD</td><td>1</td><td>smartcad.php</td><td>0</td></tr>
                    <tr><td>시설</td><td>6</td><td>0</td><td>6</td></tr>
                    <tr><td>도구</td><td>7</td><td>0</td><td>7</td></tr>
                    <tr><td>관리</td><td>6</td><td>2 (manual, auth_audit)</td><td>4</td></tr>
                    <tr><td><strong>합계</strong></td><td><strong>119</strong></td><td><strong>36</strong></td><td><strong>83</strong></td></tr>
                </table>
            </div>
        </section>

        <!-- ── FMS 구현 파일 목록 ── -->
        <section class="manual-sec" id="sec-fms-impl">
            <h2 class="manual-sec-title"><i class="fa fa-folder-open-o"></i> FMS 구현 파일 목록 (2026-04-15)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">프론트엔드 뷰 (views/saas/fms/)</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>역할</th><th>주요 기능</th></tr>
                    <tr><td>member_branch.php</td><td>사업장 목록</td><td>12컬럼(그룹/담당자/현장수 포함), N배지(이번달 신규), 담당자 필터(?employee=me), 본사 미연결 시 연결 버튼, 무한스크롤, 모바일 카드+바텀시트, CSV 내보내기, 선택 삭제</td></tr>
                    <tr><td>member_branch_add.php</td><td>사업장 등록/수정 모달</td><td>15+필드(V1 전체 이식): 고객번호/업태/업종/그룹/휴대폰/팩스/담당자검색DD/등록자/권역/사용견적태그/등록일/생년월일. 비전AI OCR(사업자등록증/명함). 필수항목 동적 적용(get_required). 사업자번호 중복체크(수정시 exclude_idx). 키보드 네비게이션</td></tr>
                    <tr><td>member_branch_view.php</td><td>사업장 상세 (8탭)</td><td>head_view.php 패턴 + detail-view.css 공용. 기본정보(인라인편집11필드/주소지도/주소복사) / 연결현장(7컬럼) / 연락처(8컬럼+등록/삭제) / 현장소장(등록/삭제) / 특기사항(SHV.chat 공용모듈) / 견적현황(est_list) / 수금현황(bill_list) / 조직도(준비중). 설정/수정 모달 연결</td></tr>
                    <tr><td>branch_settings.php</td><td>사업장 설정 모달</td><td>본사연결+연결상태/사업장상태 토글, 담당자 4명 검색DD, 사용견적 체크리스트, 하부조직 폴더 3depth CRUD, 메모, 전체 일괄 저장</td></tr>
                    <tr><td>link_head.php</td><td>본사 연결 모달</td><td>본사 검색+선택, 본사 상세보기(모달 내), 사업장 정보로 본사 자동생성, 직접 입력 생성</td></tr>
                    <tr><td>site_list.php</td><td>현장 목록</td><td>PC 테이블+모바일 카드, 무한스크롤, 검색, 상태 필터, CSV 내보내기</td></tr>
                    <tr><td>site_add.php</td><td>현장 등록/수정 모달</td><td>사업장 드롭다운, 착공/준공일, 주소검색, modify 분기</td></tr>
                    <tr><td>site_view.php</td><td>현장 상세 (2탭)</td><td>기본정보 / 견적목록 탭, 수정/삭제</td></tr>
                    <tr><td>head_view.php</td><td>본사 상세 (6탭)</td><td>기본정보(정보그룹·주소컴팩트·사용견적·메모) / 연결사업장 / 예정사업장 / 사업장현황 / 조직도(4서브탭) / 특기사항, detail-view.css 공용 CSS 사용</td></tr>
                    <tr><td>fm_add.php</td><td>현장소장 등록/수정 모달</td><td>성명/비밀번호/소속/직급/연락처/이메일/비고</td></tr>
                    <tr><td>pb_add.php</td><td>연락처 등록/수정 모달</td><td>이름/연락처/이메일/전화/부서/직책/비고</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">CSS</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>주요 클래스</th></tr>
                    <tr><td>css/v2/pages/fms.css</td><td>hd-/mb-/st-pc-only (PC/모바일 분기), badge-status-* (상태 배지), hd-mc/mb-mc (모바일 카드), hd-sr/mb-sr (시트 행), hda-grid-2/3 (모달 그리드), mb-head-link/btn-link-head (본사 링크/연결버튼), bv-il/bv-editable (인라인수정), bs-section/bs-row (설정모달), est-dd-* (드롭다운), sc-* (SHV.chat 코멘트모듈: sc-msgs/sc-row/sc-bub-me/sc-bub-other/sc-date-bar/sc-avatar/sc-del-btn/sc-img-modal), mba-ocr-* (비전AI OCR 바)</td></tr>
                    <tr><td>css/v2/detail-view.css</td><td>dv-header/dv-summary/dv-info-section/dv-row-grid/dv-editable/dv-edit-input (상세뷰 공용 인라인편집)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">라우터 등록 (js/core/router.js)</div>
                <table class="manual-tbl">
                    <tr><th>라우트</th><th>file</th></tr>
                    <tr><td>member_branch / member_planned</td><td>views/saas/fms/member_branch.php</td></tr>
                    <tr><td>member_branch_view</td><td>views/saas/fms/member_branch_view.php</td></tr>
                    <tr><td>member_settings</td><td>views/saas/manage/member_settings.php</td></tr>
                    <tr><td>head_view</td><td>views/saas/fms/head_view.php</td></tr>
                    <tr><td>site_new</td><td>views/saas/fms/site_list.php</td></tr>
                    <tr><td>site_view</td><td>views/saas/fms/site_view.php</td></tr>
                </table>
            </div>
        </section>

        <!-- ── FMS API 연동 맵 ── -->
        <section class="manual-sec" id="sec-fms-api-map">
            <h2 class="manual-sec-title"><i class="fa fa-plug"></i> FMS API 연동 맵</h2>
            <div class="manual-card">
                <div class="manual-sub-title">프론트 → 백엔드 API 호출 맵</div>
                <table class="manual-tbl">
                    <tr><th>프론트 파일</th><th>API</th><th>todo 액션</th></tr>
                    <tr><td>member_branch.php</td><td>Member.php</td><td>list (new_count, employee 필터 포함), member_delete</td></tr>
                    <tr><td rowspan="5">member_branch_add.php</td><td>Member.php</td><td>insert, update, detail, check_dup, get_required, group_list, employee_list, region_list</td></tr>
                    <tr><td>HeadOffice.php</td><td>list (본사 드롭다운)</td></tr>
                    <tr><td>Material.php</td><td>tab_list (사용견적 후보)</td></tr>
                    <tr><td>OCR.php</td><td>ocr_scan (비전AI)</td></tr>
                    <tr><td>Member.php</td><td>detail (수정 시 데이터 로드)</td></tr>
                    <tr><td rowspan="6">member_branch_view.php</td><td>Member.php</td><td>detail, update (인라인편집), member_delete, employee_list (담당자 검색), bill_list (수금탭)</td></tr>
                    <tr><td>Site.php</td><td>list (현장탭), est_list (견적탭)</td></tr>
                    <tr><td>FieldManager.php</td><td>list, delete (현장소장 탭)</td></tr>
                    <tr><td>PhoneBook.php</td><td>list, delete (연락처 탭)</td></tr>
                    <tr><td>Comment.php</td><td>list, insert, delete (특기사항 — SHV.chat 공용모듈)</td></tr>
                    <tr><td>Member.php</td><td>bill_list (수금현황 탭)</td></tr>
                    <tr><td>branch_settings.php</td><td>Member.php</td><td>detail, update, employee_list, branch_folder_list/insert/update/delete</td></tr>
                    <tr><td>branch_settings.php</td><td>Material.php</td><td>tab_list (사용견적 체크리스트)</td></tr>
                    <tr><td>member_settings.php</td><td>Member.php</td><td>get_required, save_member_required, region_list, save_member_regions</td></tr>
                    <tr><td>link_head.php</td><td>HeadOffice.php</td><td>list, detail, create_head_from_member</td></tr>
                    <tr><td>link_head.php</td><td>Member.php</td><td>link_head</td></tr>
                    <tr><td>site_list.php</td><td>Site.php</td><td>list, delete</td></tr>
                    <tr><td>site_add.php</td><td>Site.php</td><td>insert, update, detail</td></tr>
                    <tr><td>site_add.php</td><td>Member.php</td><td>list (사업장 드롭다운)</td></tr>
                    <tr><td>site_view.php</td><td>Site.php</td><td>detail (견적 포함), delete</td></tr>
                    <tr><td>head_view.php</td><td>HeadOffice.php</td><td>detail, delete</td></tr>
                    <tr><td>head_view.php</td><td>Member.php</td><td>list (사업장 탭)</td></tr>
                    <tr><td>fm_add.php</td><td>FieldManager.php</td><td>insert, update, detail</td></tr>
                    <tr><td>pb_add.php</td><td>PhoneBook.php</td><td>insert, update, detail</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">백엔드 API 파일 (dist_process/saas/)</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>DB 테이블</th><th>todo 액션</th><th>담당</th></tr>
                    <tr><td>HeadOffice.php</td><td>Tb_HeadOffice</td><td>list/detail/check_dup/insert/update/bulk_update/restore/delete/delete_attach</td><td>ChatGPT</td></tr>
                    <tr><td>Member.php</td><td>Tb_Members</td><td>list/detail/check_dup/insert/update/member_inline_update/member_delete/restore/member_bulk_action</td><td>ChatGPT</td></tr>
                    <tr><td>Site.php</td><td>Tb_Site + Tb_SiteEstimate + Tb_EstimateItem</td><td>list/detail/search/insert/update/delete + est_list~est_pdf_data (12)</td><td>ChatGPT</td></tr>
                    <tr><td>FieldManager.php</td><td>Tb_Users_fieldManager</td><td>list/detail/insert/update/delete/select_option</td><td>ChatGPT</td></tr>
                    <tr><td>PhoneBook.php</td><td>Tb_PhoneBook</td><td>list/detail/insert/update/delete/move</td><td>ChatGPT</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">페이지 네비게이션 파이프라인</div>
                <pre class="manual-code">본사 목록 (?r=member_head)
  └→ 본사 상세 (?r=head_view&head_idx=N)
       └→ 사업장 행 클릭
            └→ 사업장 상세 (?r=member_branch_view&member_idx=N)
                 ├── 기본정보 탭
                 ├── 현장 탭 → 현장 행 클릭
                 │    └→ 현장 상세 (?r=site_view&site_idx=N)
                 │         ├── 기본정보 탭
                 │         └── 견적 탭
                 ├── 현장소장 탭 (등록/삭제)
                 └── 연락처 탭 (등록/삭제)

사업장 목록 (?r=member_branch)
  └→ 사업장 상세 (동일)

현장 목록 (?r=site_new)
  └→ 현장 상세 (동일)</pre>
            </div>
        </section>

        <!-- ── V1→V2 미구현 API 갭 ── -->
        <section class="manual-sec" id="sec-fms-v1gap">
            <h2 class="manual-sec-title"><i class="fa fa-exclamation-triangle"></i> V1→V2 미구현 API 갭 (2026-04-15)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">HeadOffice.php — V1에 있고 V2에 없는 9개 액션</div>
                <table class="manual-tbl">
                    <tr><th>V1 todo</th><th>용도</th><th>프론트 의존</th></tr>
                    <tr><td>create_head_from_member</td><td>사업장에서 본사 자동 생성</td><td>link_head.php</td></tr>
                    <tr><td>org_folder_list</td><td>조직도 폴더 목록</td><td>head_settings, head_view</td></tr>
                    <tr><td>org_folder_insert</td><td>폴더 추가</td><td>head_settings, head_view</td></tr>
                    <tr><td>org_folder_update</td><td>폴더 이름변경</td><td>head_settings, head_view</td></tr>
                    <tr><td>org_folder_delete</td><td>폴더 삭제</td><td>head_settings, head_view</td></tr>
                    <tr><td>org_folder_reorder</td><td>폴더 순서변경</td><td>head_settings</td></tr>
                    <tr><td>assign_branch_folder</td><td>사업장 폴더배치</td><td>head_settings, head_view DnD</td></tr>
                    <tr><td>reorder_branches</td><td>사업장 순서변경</td><td>head_settings</td></tr>
                    <tr><td>update_settings</td><td>본사 설정 저장</td><td>head_settings</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">Member.php — V1에 있고 V2에 없는 15개 액션</div>
                <table class="manual-tbl">
                    <tr><th>V1 todo</th><th>용도</th></tr>
                    <tr><td>excel_download</td><td>엑셀 다운로드</td></tr>
                    <tr><td>branch_folder_list/insert/update/delete/reorder</td><td>사업장 조직폴더 CRUD (5개)</td></tr>
                    <tr><td>update_contact</td><td>연락처 수정</td></tr>
                    <tr><td>move_contact</td><td>연락처 사업장 간 이동</td></tr>
                    <tr><td>inline_update_contact</td><td>연락처 인라인 수정 (직급/직책/전화 등)</td></tr>
                    <tr><td>assign_contact_folder</td><td>연락처 폴더배치</td></tr>
                    <tr><td>quick_add_contact</td><td>연락처 빠른등록</td></tr>
                    <tr><td>unlink_head</td><td>본사 연결 해제</td></tr>
                    <tr><td>update_link_status</td><td>연결 상태 변경 (요청/연결/중단)</td></tr>
                    <tr><td>link_head</td><td>사업장→본사 연결</td></tr>
                    <tr><td>add_comment</td><td>특기사항 등록</td></tr>
                    <tr><td>set_employees</td><td>담당자 일괄배정</td></tr>
                    <tr><td>tab_data</td><td>탭 데이터 지연로드</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">PhoneBook.php — V1에 있고 V2에 없는 1개 액션</div>
                <table class="manual-tbl">
                    <tr><th>V1 todo</th><th>용도</th></tr>
                    <tr><td>toggle_hidden</td><td>연락처 숨김/해제 (조직도에서 비표시)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2에서 추가 필요한 공통 JS 함수</div>
                <table class="manual-tbl">
                    <tr><th>함수</th><th>용도</th><th>V2 대응</th></tr>
                    <tr><td>copyPageLink()</td><td>현재 URL 클립보드 복사</td><td>없음 — 신규</td></tr>
                    <tr><td>shvShowMap(address)</td><td>카카오맵 팝업</td><td>없음 — 신규</td></tr>
                    <tr><td>shvPrompt(title, placeholder, cb)</td><td>커스텀 입력 모달</td><td>없음 — 신규</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 member_head_add.php 값 불일치</div>
                <table class="manual-tbl">
                    <tr><th>필드</th><th>V2 현재</th><th>V1 정확한 값</th><th>필드명</th></tr>
                    <tr><td>본사구조</td><td class="text-danger">법인/개인/기타</td><td>단일/지사</td><td>head_structure</td></tr>
                    <tr><td>유형</td><td class="text-danger">없음</td><td>법인/개인</td><td>head_type</td></tr>
                    <tr><td>계약</td><td class="text-danger">없음</td><td>없음/단일/협력</td><td>contract_type</td></tr>
                    <tr><td>업태/업종/법인등록번호/주요사업</td><td class="text-danger">없음</td><td>있음</td><td>business_type/class/identity_number/main_business</td></tr>
                    <tr><td>담당자</td><td>텍스트 입력</td><td>직원 검색 드롭다운</td><td>employee_idx</td></tr>
                </table>
            </div>
        </section>

        <!-- ── 본사 상세 보강 작업 목록 ── -->
        <section class="manual-sec" id="sec-fms-head-todo">
            <h2 class="manual-sec-title"><i class="fa fa-check-circle"></i> 본사 상세 구현 완료 (V1 37기능 100%)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">A. 즉시 수정 (V2 오류) — ChatGPT 의존 없음</div>
                <table class="manual-tbl">
                    <tr><th>#</th><th>내용</th><th>파일</th></tr>
                    <tr><td>A1</td><td>본사구조 옵션: 법인/개인/기타 → 단일/지사</td><td>member_head_add.php</td></tr>
                    <tr><td>A2</td><td>유형(head_type) 필드 추가: 법인/개인</td><td>member_head_add.php</td></tr>
                    <tr><td>A3</td><td>계약(contract_type) 필드 추가: 없음/단일/협력</td><td>member_head_add.php</td></tr>
                    <tr><td>A4</td><td>업태/업종/법인등록번호/주요사업 필드 추가</td><td>member_head_add.php</td></tr>
                    <tr><td>A5</td><td>담당자: 텍스트→직원 드롭다운 (employee_idx)</td><td>member_head_add.php</td></tr>
                    <tr><td>A6</td><td>사용견적 데이터 형식 통일 (JSON 객체 배열)</td><td>member_head_add.php</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">B. head_view.php 보강 — ChatGPT 의존 없음</div>
                <table class="manual-tbl">
                    <tr><th>#</th><th>내용</th><th>난이도</th></tr>
                    <tr><td>B1</td><td>기본정보 필드 12개 추가 + 이메일링크 + 지도 + 주소복사</td><td>소</td></tr>
                    <tr><td>B2</td><td>헤더 배지 (유형/구조/계약) + 링크복사</td><td>소</td></tr>
                    <tr><td>B3</td><td>연결사업장 탭 보강 (검색+추가+연결상태+담당자+현장건수)</td><td>중</td></tr>
                    <tr><td>B4</td><td>예정사업장 탭 추가</td><td>중</td></tr>
                    <tr><td>B5</td><td>사업장현황 탭 (본사→사업장 트리 다이어그램)</td><td>대</td></tr>
                    <tr><td>B6</td><td>특기사항 탭</td><td>소</td></tr>
                    <tr><td>B7</td><td>탭 상태 sessionStorage 복원</td><td>소</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">C. 신규 파일 — ChatGPT 백엔드 의존</div>
                <table class="manual-tbl">
                    <tr><th>#</th><th>파일</th><th>내용</th><th>필요 API</th></tr>
                    <tr><td>C1</td><td>views/saas/fms/link_head.php</td><td>본사 연결 모달 (검색+선택+연결+새로생성)</td><td>link_head, create_head_from_member</td></tr>
                    <tr><td>C2</td><td>views/saas/fms/head_settings.php</td><td>설정 모달 (조직도설정+사업장구조+폴더관리+직급직책)</td><td>update_settings, org_folder_*, reorder_branches, unlink_head, assign_branch_folder</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">D. 조직도 탭 — ChatGPT 백엔드 의존 (대규모)</div>
                <table class="manual-tbl">
                    <tr><th>#</th><th>내용</th><th>필요 API</th></tr>
                    <tr><td>D1</td><td>미배치 서브탭 (테이블+검색+필터+인라인수정)</td><td>PhoneBook 추가필드 + inline_update_contact</td></tr>
                    <tr><td>D2</td><td>트리 서브탭 (카드뷰+줌/패닝+연락처DnD+사업장DnD)</td><td>assign_contact_folder + org_chart_common</td></tr>
                    <tr><td>D3</td><td>테이블 서브탭 (전체목록+정렬+상세팝오버)</td><td>동일</td></tr>
                    <tr><td>D4</td><td>숨김 서브탭 (숨김 연락처+해제)</td><td>toggle_hidden</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 원본 파일 분석 범위 (총 7185줄+)</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>줄 수</th><th>역할</th></tr>
                    <tr><td>head_view.php</td><td>1161</td><td>본사 상세 뷰 (탭 5개 + 조직도 4서브탭)</td></tr>
                    <tr><td>head_settings.php</td><td>800</td><td>본사 설정 모달</td></tr>
                    <tr><td>add_head.php</td><td>367</td><td>본사 등록/수정 폼</td></tr>
                    <tr><td>member_head.php</td><td>349</td><td>본사 목록</td></tr>
                    <tr><td>org_chart_common.php</td><td>183</td><td>조직도 공통 헬퍼 (PHP+JS)</td></tr>
                    <tr><td>link_head.php</td><td>182</td><td>본사 연결 모달</td></tr>
                    <tr><td>member_view.css</td><td>432</td><td>상세뷰 공통 CSS</td></tr>
                    <tr><td>member_branch_view.php</td><td>2676</td><td>사업장 상세 (후속 작업)</td></tr>
                    <tr><td>branch_settings.php</td><td>1044</td><td>사업장 설정 (후속 작업)</td></tr>
                    <tr><td>HeadOffice.php (API)</td><td>374</td><td>V1 백엔드 16개 액션</td></tr>
                    <tr><td>Member.php (API)</td><td>787</td><td>V1 백엔드 24개 액션</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 사업장관리 V2 이식 완료 ══════════════════ -->
        <section class="manual-sec" id="sec-fms-branch-done">
            <h2 class="manual-sec-title"><i class="fa fa-check-circle"></i> 사업장관리 V2 이식 완료 (2026-04-16)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">구현 파일 목록</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>줄수</th><th>역할</th></tr>
                    <tr><td>views/saas/fms/member_branch.php</td><td>534</td><td>사업장 목록 (12컬럼, N배지, 담당자필터, 본사연결버튼, CSV)</td></tr>
                    <tr><td>views/saas/fms/member_branch_add.php</td><td>979</td><td>사업장 등록/수정 (15+필드, OCR 비전AI, 필수항목 동적, 사용견적 태그)</td></tr>
                    <tr><td>views/saas/fms/member_branch_view.php</td><td>1318</td><td>사업장 상세 10탭 (기본정보/연결현장/연락처/현장소장/특기사항/견적/수금/메일/첨부/조직도)</td></tr>
                    <tr><td>views/saas/fms/branch_settings.php</td><td>596</td><td>사업장 설정 모달 (본사연결, 담당자5명, 사용견적, 품목옵션, PJT예정6단계, 하부조직폴더CRUD)</td></tr>
                    <tr><td>views/saas/manage/member_settings.php</td><td>343</td><td>고객관리 글로벌 설정 (필수항목 ON/OFF, 권역 CRUD, PJT속성 CRUD)</td></tr>
                    <tr><td>js/ui/chat.js</td><td>371</td><td>SHV.chat 공용 코멘트 모듈 (카카오톡 스타일, 파일DnD, 이미지붙여넣기)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">SHV.chat 공용 코멘트 모듈</div>
                <table class="manual-tbl">
                    <tr><th>항목</th><th>내용</th></tr>
                    <tr><td>사용법</td><td><code>SHV.chat.init(containerId, toTable, toIdx, pjtKey)</code> — 어디서든 1줄</td></tr>
                    <tr><td>디자인</td><td>카카오톡 스타일 (노란/회색 말풍선+꼬리), 1분 그룹핑, 날짜 구분바</td></tr>
                    <tr><td>기능</td><td>낙관적 업데이트, 파일 DnD, 이미지 붙여넣기, 삭제, IME 처리</td></tr>
                    <tr><td>CSS</td><td>fms.css 내 sc-* 클래스 180줄+ (다크모드 포함)</td></tr>
                    <tr><td>백엔드</td><td>Comment.php (list/insert/delete + 파일업로드 + EventStream)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">공용 CSS 사용 현황</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>공용 범위</th></tr>
                    <tr><td>css/v2/detail-view.css</td><td>본사/사업장/현장 상세 공용 (dv-header, dv-summary, dv-row-grid, dv-editable, 인라인편집)</td></tr>
                    <tr><td>css/v2/pages/fms.css</td><td>FMS 전체 공용 (hd-/mb-/st- 목록, badge-status, bv-il 인라인수정, bs-* 설정모달, sc-* 채팅)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">백엔드 API (ChatGPT 작업)</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>액션</th></tr>
                    <tr><td>Member.php</td><td>list(new_count/employee), get_required, group_list, employee_list(hold_flag), region_list, save_member_required, save_member_regions, branch_folder_*, bill_list</td></tr>
                    <tr><td>Comment.php (신규)</td><td>list, insert, delete + 파일업로드 + Tb_EventStream</td></tr>
                    <tr><td>OCR.php (신규)</td><td>ocr_scan (Claude/OpenAI/Naver 엔진)</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 현장관리 + PJT V2 이식 기획 ══════════════════ -->
        <section class="manual-sec" id="sec-fms-site-plan">
            <h2 class="manual-sec-title"><i class="fa fa-map-marker"></i> 현장관리 + PJT V2 이식 완료 (전체 Phase + V1 GAP 해소)</h2>

            <div class="manual-card">
                <div class="manual-sub-title">개요</div>
                <table class="manual-tbl">
                    <tr><th>항목</th><th>내용</th></tr>
                    <tr><td>범위</td><td>V1 현장관리 12탭 + PJT V2 4서브탭 → V2 통합 이식 + 리팩터링</td></tr>
                    <tr><td>V1 코드량</td><td>~15,000줄 (site_view 4,020 + add_estimate 1,460 + add_bill 781 + PJT 모달 7,229 + pjt_register.js 725)</td></tr>
                    <tr><td>V2 예상</td><td>~6,800줄 (55% 감소) — 지연로딩 + PJT JS 모듈 분리 + SHV.api 통일</td></tr>
                    <tr><td>전략</td><td>리팩터링하면서 이식 (V1 복사 아님), PJT와 현장 동시 작업 (연관성 높음)</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 현재 상태 (뼈대만)</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>현재 줄수</th><th>상태</th></tr>
                    <tr><td>site_list.php</td><td>473</td><td>6컬럼, 검색1종, 상태필터만</td></tr>
                    <tr><td>site_add.php</td><td>373</td><td>8필드 (V1 대비 절반 누락)</td></tr>
                    <tr><td>site_view.php</td><td>229</td><td>2탭만 (기본정보+견적), 95% 누락</td></tr>
                    <tr><td>Site.php (백엔드)</td><td>1,894</td><td>18개 API (기본CRUD + 견적CRUD + 승인 + PDF)</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 대비 누락 기능 GAP</div>
                <table class="manual-tbl">
                    <tr><th>영역</th><th>V1</th><th>V2 현재</th><th>GAP</th></tr>
                    <tr><td>목록 컬럼</td><td>13개</td><td>6개</td><td>7컬럼 + 필터8종 + 엑셀</td></tr>
                    <tr><td>등록 필드</td><td>현장번호/건설사/담당자DD/부서/총수량/보증기간/지도좌표</td><td>8필드</td><td>8필드 추가</td></tr>
                    <tr><td>상세뷰 탭</td><td>12탭 + PJT(4서브탭)</td><td>2탭</td><td>10탭 + PJT 전체</td></tr>
                    <tr><td>견적 등록</td><td>add_estimate 1,460줄 (쇼핑카트UI)</td><td>없음</td><td>전체 신규</td></tr>
                    <tr><td>수금</td><td>add_bill 781줄 (3모드, 합계바)</td><td>없음</td><td>전체 신규</td></tr>
                    <tr><td>PJT</td><td>4서브탭 + 호기상세 + 단계상세</td><td>없음</td><td>전체 신규</td></tr>
                    <tr><td>현장 설정</td><td>탭ON/OFF + 엑셀 + 연락처필수항목</td><td>없음</td><td>전체 신규</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">리팩터링 핵심 전략</div>
                <table class="manual-tbl">
                    <tr><th>V1 패턴</th><th>V2 리팩터링</th></tr>
                    <tr><td>site_view_new.php 4,020줄 모놀리스</td><td>site_view.php ~1,500줄 (헤더+탭바+지연로딩)</td></tr>
                    <tr><td>PJT JS 인라인 700줄+</td><td>js/pages/pjt.js ~500줄 (SHV.pjt.* 네임스페이스)</td></tr>
                    <tr><td>inline style 수백 곳</td><td>detail-view.css + fms.css 공용 클래스</td></tr>
                    <tr><td>fetch() 직접 호출</td><td>SHV.api.get/post (CSRF 자동)</td></tr>
                    <tr><td>SSR 전체 렌더링</td><td>SPA 탭별 지연로딩 (_loaded 플래그)</td></tr>
                    <tr><td>onclick="fn()"</td><td>SHV.events.action() 이벤트 위임</td></tr>
                    <tr><td>alert/confirm/prompt</td><td>SHV.confirm/SHV.toast/SHV.prompt</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">Phase별 실행 계획</div>
                <table class="manual-tbl">
                    <tr><th>Phase</th><th>내용</th><th>파일</th><th>상태</th></tr>
                    <tr><td>A</td><td>목록 강화 (12컬럼, 필터5종, CSV확장)</td><td>site_list.php</td><td>완료</td></tr>
                    <tr><td>B</td><td>등록 폼 +6필드 (현장번호/건설사/담당자DD/부서/총수량/보증)</td><td>site_add.php</td><td>완료</td></tr>
                    <tr><td>C+D</td><td>상세뷰 전면 재작성 (11탭+지연로딩+기본정보/견적/수금/연락처/특기사항)</td><td>site_view.php</td><td>완료</td></tr>
                    <tr><td>E</td><td>탭 API 연동 (도면/첨부/도급/출입/메일)</td><td>site_view.php</td><td>완료</td></tr>
                    <tr><td>F</td><td>PJT 탭 + pjt.js 모듈 (4서브탭)</td><td>site_view.php, pjt.js</td><td>완료</td></tr>
                    <tr><td>G</td><td>PJT 등록 + 호기 상세 + 단계 상세 모달</td><td>pjt_plan_add, pjt_hogi_view, pjt_survey_view</td><td>완료</td></tr>
                    <tr><td>H-1</td><td>견적 등록 모달 (탭+카테고리+부모자식+수량팔로우)</td><td>est_add.php</td><td>완료</td></tr>
                    <tr><td>H-2</td><td>수금 등록 모달 (new/edit/deposit 3모드)</td><td>bill_add.php</td><td>완료</td></tr>
                    <tr><td>I</td><td>현장 설정 + 라우터/메뉴 + 호기정보</td><td>site_settings, branch_settings, router.js</td><td>완료</td></tr>
                    <tr><td>GAP</td><td>V1 누락 해소 (행삭제/CSV/CAD탭/발주담당/지도/유형컬럼)</td><td>전체</td><td>완료</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 파일 수정/생성 목록</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>작업</th><th>Phase</th></tr>
                    <tr><td>views/saas/fms/site_list.php</td><td>수정</td><td>A</td></tr>
                    <tr><td>views/saas/fms/site_add.php</td><td>수정</td><td>B</td></tr>
                    <tr><td>views/saas/fms/site_view.php</td><td>전면 재작성</td><td>C~F</td></tr>
                    <tr><td>views/saas/fms/est_add.php</td><td>신규</td><td>H</td></tr>
                    <tr><td>views/saas/fms/bill_add.php</td><td>신규</td><td>H</td></tr>
                    <tr><td>views/saas/fms/pjt_plan_add.php</td><td>신규</td><td>G</td></tr>
                    <tr><td>views/saas/fms/pjt_hogi_view.php</td><td>신규</td><td>G</td></tr>
                    <tr><td>views/saas/fms/pjt_survey_view.php</td><td>신규</td><td>G</td></tr>
                    <tr><td>views/saas/fms/site_settings.php</td><td>신규</td><td>I</td></tr>
                    <tr><td>js/pages/pjt.js</td><td>신규</td><td>F</td></tr>
                    <tr><td>css/v2/pages/fms.css</td><td>수정</td><td>전 Phase</td></tr>
                    <tr><td>css/v2/detail-view.css</td><td>공용 재사용</td><td>-</td></tr>
                    <tr><td>js/core/router.js</td><td>수정</td><td>I</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V2 기존 백엔드 API (Site.php 18개)</div>
                <table class="manual-tbl">
                    <tr><th>API</th><th>용도</th></tr>
                    <tr><td>list / detail / search</td><td>현장 조회</td></tr>
                    <tr><td>insert / update / delete</td><td>현장 CRUD</td></tr>
                    <tr><td>est_list / est_detail</td><td>견적 조회</td></tr>
                    <tr><td>insert_est / update_est / delete_estimate</td><td>견적 CRUD</td></tr>
                    <tr><td>copy_est / recalc_est</td><td>견적 복사/재계산</td></tr>
                    <tr><td>upsert_est_items / update_est_item / delete_est_item</td><td>견적 품목</td></tr>
                    <tr><td>approve_est / est_pdf_data</td><td>견적 승인/PDF</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">ChatGPT 추가 요청 백엔드 API</div>
                <table class="manual-tbl">
                    <tr><th>#</th><th>API</th><th>파일</th><th>용도</th></tr>
                    <tr><td>1</td><td>bill_list / bill_detail</td><td>Site.php</td><td>수금 목록/상세</td></tr>
                    <tr><td>2</td><td>insert_bill / update_bill / delete_bill</td><td>Site.php</td><td>수금 CRUD</td></tr>
                    <tr><td>3</td><td>deposit_bill</td><td>Site.php</td><td>입금 처리</td></tr>
                    <tr><td>4</td><td>contact_list + CRUD</td><td>PhoneBook</td><td>현장 연락처</td></tr>
                    <tr><td>5</td><td>floor_plan_list</td><td>Site.php</td><td>도면 목록</td></tr>
                    <tr><td>6</td><td>subcontract_list</td><td>Site.php</td><td>도급 목록</td></tr>
                    <tr><td>7</td><td>access_log_list</td><td>Site.php</td><td>출입 기록</td></tr>
                    <tr><td>8</td><td>site_settings / save_site_settings</td><td>Site.php</td><td>현장 설정</td></tr>
                    <tr><td>9</td><td>pjt_property_list / save_item_property</td><td>Member.php</td><td>PJT속성 관리</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">담당자 체계 (현장)</div>
                <table class="manual-tbl">
                    <tr><th>출처</th><th>필드</th><th>역할</th></tr>
                    <tr><td>Tb_Site</td><td>employee_idx</td><td>현장 담당자</td></tr>
                    <tr><td>Tb_Site</td><td>employee_idx2</td><td>현장 부담당자</td></tr>
                    <tr><td>Tb_Site</td><td>employee_idx3</td><td>현장 예산담당</td></tr>
                    <tr><td>Tb_Site</td><td>employee_idx4</td><td>현장 현장담당</td></tr>
                    <tr><td>Tb_Site</td><td>phonebook_idx</td><td>발주담당PM (연락처 연결)</td></tr>
                    <tr><td>Tb_Site</td><td>target_team</td><td>담당부서</td></tr>
                    <tr><td>Tb_Members</td><td>employee_idx x 4</td><td>사업장에서 상속</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">PJT + 호기 데이터 체인</div>
                <table class="manual-tbl">
                    <tr><th>단계</th><th>테이블</th><th>내용</th></tr>
                    <tr><td>1. 속성 정의</td><td>Tb_MemberConfig</td><td>PJT 속성명+색상 CRUD (member_settings)</td></tr>
                    <tr><td>2. 옵션값 설정</td><td>Tb_MemberHogi</td><td>속성별 옵션값 + char_match (branch_settings)</td></tr>
                    <tr><td>3. 견적 품목</td><td>Tb_EstimateItem</td><td>품목에 attribute(속성) 연결</td></tr>
                    <tr><td>4. PJT 예정</td><td>Tb_PjtPlanEstItem</td><td>미확정 단계 품목</td></tr>
                    <tr><td>5. PJT 등록</td><td>Tb_PjtProject</td><td>호기 레코드 생성</td></tr>
                    <tr><td>6. 수량 검증</td><td>Project.php</td><td>check_qty_limit (총수량/품목갯수 합산)</td></tr>
                    <tr><td>7. 수금 연결</td><td>Tb_BillGroup / Tb_Bill</td><td>대금/기성금/수금/잔금 합계바</td></tr>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 원본 파일 분석 범위</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>줄수</th><th>역할</th></tr>
                    <tr><td>site_view_new.php</td><td>4,020</td><td>현장 상세 (12탭 + PJT 4서브탭)</td></tr>
                    <tr><td>add_estimate.php</td><td>1,460</td><td>견적 등록 (쇼핑카트 UI)</td></tr>
                    <tr><td>add_bill.php</td><td>781</td><td>수금 등록/수정/입금 (3모드)</td></tr>
                    <tr><td>view_pjt_survey.php</td><td>4,832</td><td>PJT 단계 상세 (7단계)</td></tr>
                    <tr><td>view_pjt_hogi.php</td><td>1,702</td><td>호기 상세</td></tr>
                    <tr><td>add_pjt_plan_v2.php</td><td>695</td><td>PJT예정 등록</td></tr>
                    <tr><td>pjt_register.js</td><td>725</td><td>PJT 등록 UI</td></tr>
                    <tr><td>Project.php</td><td>5,401</td><td>PJT 백엔드 69액션</td></tr>
                    <tr><td>ProjectV2.php</td><td>1,389</td><td>PJT현황 8액션</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 변경이력 — FMS 사업장관리 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-fms-branch">
            <h2 class="manual-sec-title"><i class="fa fa-building-o"></i> 변경 이력 — FMS 사업장관리 (2026-04-16)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">신규 파일</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>역할</th></tr>
                    <tr><td>views/saas/fms/branch_settings.php</td><td>사업장 설정 모달 (본사연결, 담당자5명, 사용견적, 품목옵션, PJT예정6단계, 조직폴더CRUD)</td></tr>
                    <tr><td>views/saas/manage/member_settings.php</td><td>고객관리 글로벌 설정 (필수항목ON/OFF, 권역CRUD, PJT속성CRUD)</td></tr>
                    <tr><td>js/ui/chat.js</td><td>SHV.chat 공용 코멘트 모듈 (카카오톡 스타일 18기능)</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">수정 파일</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>변경 내용</th></tr>
                    <tr><td>member_branch.php</td><td>12컬럼, N배지, 담당자 필터, 본사연결 버튼, CSV 확장</td></tr>
                    <tr><td>member_branch_add.php</td><td>PHP parse error 수정, V1 15+필드, OCR 비전AI, 필수항목 동적, 사용견적 태그</td></tr>
                    <tr><td>member_branch_view.php</td><td>전면 재작성 10탭 (head_view 패턴, 인라인편집 11필드)</td></tr>
                    <tr><td>css/v2/pages/fms.css</td><td>사업장 전용 CSS (bv-il, bs-*, sc-*, mba-ocr-*, btn-link-head)</td></tr>
                    <tr><td>js/core/router.js</td><td>member_settings 라우트 추가</td></tr>
                    <tr><td>index.php</td><td>SHV._user 전역 주입 + chat.js 로드 + 메뉴 추가</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 변경이력 — FMS 본사관리 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-fms-head">
            <h2 class="manual-sec-title"><i class="fa fa-building"></i> 변경 이력 — FMS 본사관리 (2026-04-15~16)</h2>
            <div class="manual-card">
                <div class="manual-sub-title">신규 파일</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>역할</th></tr>
                    <tr><td>views/saas/fms/head_view.php</td><td>본사 상세 (헤더+요약카드+기본정보+탭6개)</td></tr>
                    <tr><td>views/saas/fms/head_settings.php</td><td>본사 설정 모달 (조직도설정+사업장구조+폴더관리+직급직책)</td></tr>
                    <tr><td>views/saas/fms/link_head.php</td><td>본사 연결 모달 (검색+선택+연결+새로생성)</td></tr>
                    <tr><td>js/pages/org_chart.js</td><td>조직도 공용 JS 모듈 (4서브탭+DnD+줌+인라인수정)</td></tr>
                    <tr><td>js/ui/prompt.js</td><td>SHV.prompt() 커스텀 입력 모달</td></tr>
                    <tr><td>css/v2/detail-view.css</td><td>상세뷰 공용 CSS (본사/사업장/현장 공유) — dv-header, dv-pill, dv-summary, dv-info-grid, dv-info-group, dv-addr-compact, dv-zip, dv-ue-badge, shv-map-*, shv-prompt-*, 다크모드, 반응형</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">수정 파일</div>
                <table class="manual-tbl">
                    <tr><th>파일</th><th>변경 내용</th></tr>
                    <tr><td>member_head_add.php</td><td>본사구조(단일/지사), 유형(법인/개인), 계약(없음/단일/협력), 업태/업종/법인등록번호/주요사업/이메일 추가, 사용견적 JSON+동적로드</td></tr>
                    <tr><td>css/v2/pages/fms.css</td><td>oc-*/hs-*/lh-* CSS 추가 (dv-*/shv-map-*/shv-prompt-* → detail-view.css로 분리)</td></tr>
                    <tr><td>js/core/router.js</td><td>head_view/member_branch_view/site_view 라우트 file 등록</td></tr>
                    <tr><td>index.php</td><td>prompt.js 로드 추가, router.js 캐시버스터</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">V1 대비 구현 기능 (37/37 = 100%)</div>
                <table class="manual-tbl">
                    <tr><th>영역</th><th>기능</th><th>상태</th></tr>
                    <tr><td>헤더</td><td>본사명+배지(유형/구조/계약)+pill(코드/대표자/사업자번호/전화/담당자)+링크복사+설정+수정+삭제</td><td>완료</td></tr>
                    <tr><td>요약 카드</td><td>연결사업장/예정사업장/전체현장 건수</td><td>완료</td></tr>
                    <tr><td>기본정보 탭</td><td>카드 그리드 (주소+우편번호+지도/복사, 담당, 업태/업종/주요사업, 법인등록번호, 이메일, 협력계약, 그룹, 등록일/자, 사용견적 배지, 메모, 첨부)</td><td>완료</td></tr>
                    <tr><td>연결사업장 탭</td><td>검색+사업장추가+연결상태+담당자+현장건수</td><td>완료</td></tr>
                    <tr><td>예정사업장 탭</td><td>검색+테이블</td><td>완료</td></tr>
                    <tr><td>사업장현황 탭</td><td>본사→사업장 트리 다이어그램</td><td>완료</td></tr>
                    <tr><td>조직도 탭</td><td>4서브탭(미배치/트리/테이블/숨김)+검색+필터(상태/직급/직책)+인라인수정+DnD+줌/패닝+폴더CRUD+상세팝오버+숨김/해제</td><td>완료</td></tr>
                    <tr><td>특기사항 탭</td><td>빈 상태 표시</td><td>완료</td></tr>
                    <tr><td>설정 모달</td><td>조직도설정+사업장순서변경+연결해제+상세보기+폴더CRUD+직급직책옵션+메모+저장</td><td>완료</td></tr>
                    <tr><td>본사연결 모달</td><td>본사검색+선택+연결+사업장정보로생성+새로입력생성</td><td>완료</td></tr>
                    <tr><td>지도 팝업</td><td>구글맵 위성뷰 iframe+카카오맵/네이버 외부링크</td><td>완료</td></tr>
                    <tr><td>기타</td><td>탭 sessionStorage 복원, 빈값 대시(-), 사용견적 동적로드(Tb_ItemTab)</td><td>완료</td></tr>
                </table>
            </div>
            <div class="manual-card mt-3">
                <div class="manual-sub-title">연동 백엔드 API</div>
                <table class="manual-tbl">
                    <tr><th>API</th><th>todo 액션</th></tr>
                    <tr><td>HeadOffice.php</td><td>list, detail, check_dup, insert, update, delete, bulk_update, restore, delete_attach, org_folder_list/insert/update/delete/reorder, assign_branch_folder, reorder_branches, update_settings, create_head_from_member (18개)</td></tr>
                    <tr><td>Member.php</td><td>list, detail, link_head, unlink_head, member_delete, inline_update_contact, assign_contact_folder, move_contact (8개)</td></tr>
                    <tr><td>PhoneBook.php</td><td>list, delete, toggle_hidden (3개)</td></tr>
                    <tr><td>Material.php</td><td>tab_list (1개)</td></tr>
                    <tr><td>Settings.php</td><td>save_member_required, save_member_regions, save_item_property (3개)</td></tr>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 변경이력 — 전자결재 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-appr">
            <h2 class="manual-sec-title"><i class="fa fa-file-text-o"></i> 변경 이력 — 전자결재 시스템</h2>

            <div class="manual-card">
                <div class="manual-tbl-title"><i class="fa fa-calendar"></i> 2026-04-13 ~ 14 &nbsp;<span style="font-weight:400;color:#888">초기 구축 완료</span></div>
                <table class="manual-tbl">
                    <thead><tr><th style="width:120px">분류</th><th>내용</th><th style="width:100px">담당</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>DB 신규</td>
                            <td><code>Tb_ApprDoc</code> — 결재 문서 본문 (doc_no, doc_type, title, body_html, status, writer_user_idx, current_line_order)</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>DB 신규</td>
                            <td><code>Tb_ApprLine</code> — 결재선 (line_type: APPROVER/REFERENCE, line_order, actor_user_idx, decision_status: PENDING/APPROVED/REJECTED, decision_comment)</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>DB 신규</td>
                            <td><code>Tb_ApprLinePreset</code> — 결재선 프리셋 (preset_name, line_order, actor_user_idx)</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>API 신규</td>
                            <td><code>dist_process/saas/Appr.php</code> — todos: doc_list, doc_detail, doc_save, doc_submit, doc_recall, line_approve, line_reject, preset_list, preset_save</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>서비스 신규</td>
                            <td><code>dist_library/saas/ApprovalService.php</code> — 결재 비즈니스 로직 전담 클래스</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>css/v2/pages/approval.css</code> — 전자결재 전용 CSS (스켈레톤·pulse·카드 섹션 포함)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>views/saas/grp/approval_req.php</code> — 결재함 (탭 4종: 결재하기·처리완료·참조·작성중, 검색, 상세·반려 모달)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>views/saas/grp/approval_write.php</code> — 결재 작성/수정 (결재선 직원검색, 참조자, 임시저장, 상신)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>views/saas/grp/approval_done.php</code> — 완결문서함 (내가 처리한 문서, AJAX)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>views/saas/grp/approval_official.php</code> — 공문함 (OFFICIAL type 필터, 상태 필터, 승인·반려 포함)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>버그수정</td>
                            <td>doc_save 응답 키 오류: <code>res.data.doc_id</code> → <code>res.data.item.idx</code> (docSave가 docDetail 결과를 item으로 감싸 반환)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>버그수정</td>
                            <td>결재자 PK 오류: <code>emp.idx</code>(사원PK) → <code>emp.user_idx</code>(로그인PK) 매핑. userPkOf() 수정</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>버그수정</td>
                            <td>직원 필드명 오류: <code>emp.name</code>→<code>emp.emp_name</code>, <code>emp.position</code>→<code>emp.position_name</code></td>
                            <td>Claude</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">API 파라미터 요약 — Appr.php</div>
                <table class="manual-tbl">
                    <thead><tr><th style="width:160px">todo</th><th>주요 파라미터</th><th>응답 키</th></tr></thead>
                    <tbody>
                        <tr><td><code>doc_list</code></td><td>tab (pending/done/reference/draft/all), search, doc_type, status</td><td>data.items[]</td></tr>
                        <tr><td><code>doc_detail</code></td><td>doc_id</td><td>data.item (approver_lines[], reference_lines[])</td></tr>
                        <tr><td><code>doc_save</code></td><td>doc_id(수정시), doc_type, title, body_html, approver_user_ids(콤마), reference_user_ids(콤마)</td><td>data.item.idx = 저장된 doc_id</td></tr>
                        <tr><td><code>doc_submit</code></td><td>doc_id</td><td>ok</td></tr>
                        <tr><td><code>doc_recall</code></td><td>doc_id</td><td>ok</td></tr>
                        <tr><td><code>line_approve</code></td><td>doc_id, comment(선택)</td><td>ok</td></tr>
                        <tr><td><code>line_reject</code></td><td>doc_id, comment(필수)</td><td>ok</td></tr>
                        <tr><td><code>preset_list</code></td><td>-</td><td>data.items[]</td></tr>
                        <tr><td><code>preset_save</code></td><td>preset_name, approver_user_ids(콤마)</td><td>data.preset_id</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 변경이력 — GRP 그룹웨어 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-grp">
            <h2 class="manual-sec-title"><i class="fa fa-users"></i> 변경 이력 — GRP 그룹웨어</h2>

            <div class="manual-card">
                <div class="manual-tbl-title"><i class="fa fa-calendar"></i> 2026-04-13 ~ 14</div>
                <table class="manual-tbl">
                    <thead><tr><th style="width:120px">분류</th><th>내용</th><th style="width:100px">담당</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>DB 변경</td>
                            <td>직원 상태값 <code>INACTIVE</code> → <code>RESIGNED</code> 마이그레이션 (92건). status 컬럼 ENUM: ACTIVE / RESIGNED / LEAVE</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>버그수정</td>
                            <td>org_chart 관리자 버튼(숨김·삭제) 403 오류 — <code>Employee.php</code> authority_idx 역전 버그. <code>$roleLevel &lt; 4</code> → <code>$roleLevel &gt; 2</code> 수정 (낮을수록 높은 권한)</td>
                            <td>Claude / ChatGPT</td>
                        </tr>
                        <tr>
                            <td>UI 개선</td>
                            <td>직원 편집 모달 기본정보 섹션 3-col Grid 레이아웃 적용. CSS 클래스: <code>form-row--2col</code>, <code>form-row--3col</code>, <code>form-group--full</code>, <code>form-group--wide</code></td>
                            <td>Claude</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════ 변경이력 — MAT 품목관리 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-mat">
            <h2 class="manual-sec-title"><i class="fa fa-cubes"></i> 변경 이력 — MAT 품목관리</h2>

            <div class="manual-card">
                <div class="manual-tbl-title"><i class="fa fa-calendar"></i> 2026-04-14 &nbsp;<span style="font-weight:400;color:#888">뷰 페이지 초기 구축 완료</span></div>
                <table class="manual-tbl">
                    <thead><tr><th style="width:120px">분류</th><th>내용</th><th style="width:100px">담당</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>views/saas/mat/view.php</code> — 품목 상세 뷰 페이지. PC 한화면 고정 레이아웃 (overflow:hidden + flex min-height:0). 좌패널 190px (이미지 탭·KPI·감사미니) + 우패널 flex (3섹션 수평 + 구성품 카드)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>css/v2/pages/mat.css</code> — MAT 전용 CSS. 뷰 레이아웃 클래스: <code>.mat-view-inner</code>, <code>.mat-view-left</code>, <code>.mat-view-right</code>, <code>.mat-view-img-tabs</code>, <code>.mat-view-kpi</code>, <code>.mat-view-sections-row</code>, <code>.mat-view-section</code>, <code>.mat-view-field</code>, <code>.mat-view-comp-card</code></td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>UI 컴포넌트</td>
                            <td>매입처 자동완성 드롭다운. CSS: <code>.mat-company-search</code> (relative wrapper), <code>.mat-company-dropdown</code> (absolute, max-height:200px, z-index:300), <code>.mat-company-dropdown.open</code> (표시), <code>.mat-company-option</code> (항목, hover 강조). JS: debounce 250ms, company_list API 연동</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>서비스 변경</td>
                            <td><code>dist_library/erp/MaterialService.php</code> — <code>detail()</code> SELECT에 <code>company_idx</code>, <code>company_name</code> 추가 (Tb_Company/Tb_Vendor 자동 JOIN). <code>companyList()</code> 메서드 신규 추가</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>API 신규</td>
                            <td><code>dist_process/saas/Material.php</code> — <code>todo=company_list</code> (GET, q: 검색어, limit: 건수). 응답: <code>{data[], source_table, id_column, name_column}</code></td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>라우트</td>
                            <td><code>?r=mat_view&amp;idx={idx}</code> — 품목 상세 뷰. SHV.router.navigate('mat_view', {idx}) 호출</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>미결사항</td>
                            <td>구성품 테이블 (<code>Tb_ItemComponent</code>, <code>Tb_ItemChild</code>) V2 DB 미생성 — ChatGPT 확인·마이그레이션 필요. 구성품 미표시 중</td>
                            <td>ChatGPT</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">뷰 레이아웃 구조 요약</div>
                <pre class="manual-code">section[data-page="mat-view"]          height:100%; overflow:hidden
└── .page-header                         flex-shrink:0
└── .mat-view-inner                      flex:1; min-height:0; display:flex; gap
    ├── .mat-view-left (width:190px)
    │   ├── .mat-view-img-tabs           flex:1; min-height:0; 배너/상세 탭
    │   ├── .mat-view-kpi                flex-shrink:0; KPI 3개
    │   └── .mat-view-audit-mini         flex-shrink:0; 등록/수정 감사
    └── .mat-view-right (flex:1)
        ├── .mat-view-sections-row       display:flex; gap; flex-shrink:0
        │   ├── .mat-view-section        기본정보 (1-col)
        │   ├── .mat-view-section        가격정보 (2-col)
        │   └── .mat-view-section--wide  재고/분류 (2-col)
        └── .mat-view-comp-card          height:190px; flex-shrink:0; 구성품 테이블</pre>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">매입처 자동완성 사용법</div>
                <pre class="manual-code">&lt;!-- HTML --&gt;
&lt;div class="mat-company-search"&gt;
    &lt;input id="meCompanyName" type="text" class="form-input" placeholder="매입처명 검색..." autocomplete="off"&gt;
    &lt;ul id="meCompanyDropdown" class="mat-company-dropdown"&gt;&lt;/ul&gt;
&lt;/div&gt;
&lt;input type="hidden" id="meCompanyIdx" value="0"&gt;

/* JS: debounce 250ms → SHV.api.get(api, {todo:'company_list', q, limit:20})
   선택 시 input.value = name, idxInput.value = idx
   외부 클릭 시 dropdown 닫힘 */</pre>
            </div>
        </section>

        <!-- ══════════════════ 메일 시스템 변경 이력 ══════════════════ -->
        <section class="manual-sec" id="sec-changelog-mail">
            <h2 class="manual-sec-title"><i class="fa fa-envelope"></i> 변경 이력 — 메일 시스템</h2>

            <div class="manual-card">
                <div class="manual-tbl-title"><i class="fa fa-calendar"></i> 2026-04-14 &nbsp;<span style="font-weight:400;color:#888">SaaS 메일 아키텍처 구현 (설계서 v3.2)</span></div>
                <table class="manual-tbl">
                    <thead><tr><th style="width:120px">분류</th><th>내용</th><th style="width:100px">담당</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>설계서</td>
                            <td><code>docs/saas/SHVQ_V2_MAIL_ARCHITECTURE_V3_20260414.md</code> — SaaS 메일+실시간알림 아키텍처 v3.2 (만명 업장회원, 16GB 서버, IndexedDB+FCM+SSE)</td>
                            <td>공동</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/mail/indexeddb.js</code> — IndexedDB 스키마 (4 store: mail_body, mail_headers, search_tokens, sync_meta) + CRUD + LRU eviction 500MB + corruption 자동 복구 + 5분 주기 체크</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/mail/cache.js</code> — body 캐시 (bodyHash 비교 무효화, stale-while-revalidate 헤더, 서버 fallback)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/mail/search.js</code> — 토큰화 (한글2자+/영문2자+) + IndexedDB AND 검색 + 서버 subject/from fallback 병합</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/mail/realtime.js</code> — SSE 단일탭 (BroadcastChannel) + reconnect jitter + Visibility API + heartbeat 30초</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/mail/push.js</code> — FCM 웹(Push API) + Capacitor 네이티브 자동감지 + 토큰 서버 등록</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 신규</td>
                            <td><code>js/core/sw-mail.js</code> — Service Worker (push 수신 → OS 알림 → 클릭 시 메일 페이지 열기)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 수정</td>
                            <td><code>js/pages/mail.js</code> — initModules/connectRealtime/loadMailDetailCached/searchMail API 추가</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>프론트 수정</td>
                            <td><code>js/pages/mail_pages.js</code> — loadDetail IndexedDB 캐시, loadMailList 헤더 캐시, 검색 이중검색, _mailRealtime BroadcastChannel/heartbeat/jitter, showMailToast()</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>CSS 수정</td>
                            <td><code>css/v2/pages/mail.css</code> — 토스트 알림 컴포넌트 (<code>.mail-toast</code> 계열, 슬라이드-인 애니메이션, 다크모드, 모바일 반응형)</td>
                            <td>Claude</td>
                        </tr>
                        <tr>
                            <td>백엔드 신규</td>
                            <td><code>node/fcm.js</code> — firebase-admin 래퍼, sliding debounce (5초+1분, key=userPk+accountIdx), invalid token 삭제, 1회 retry</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>백엔드 수정</td>
                            <td><code>node/worker.js</code> — IMAP fetch priority queue+aging, FETCH retry 3회, online-only IDLE (SETEX heartbeat), watchdog 10분, reconnect backoff, global hard cap 800, FCM 분기, SSE rate limit, bulk INSERT transaction+ROWLOCK</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>백엔드 수정</td>
                            <td><code>node/ecosystem.config.js</code> — PM2 cluster 2, max_memory_restart 4GB</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>API 신규</td>
                            <td><code>dist_process/saas/Mail.php</code> — <code>todo=fcm_register</code> (POST, token+device_type), <code>todo=fcm_unregister</code> (POST, token)</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>DB 마이그레이션</td>
                            <td><code>20260414_wave5_mail_fcm_realtime.sql</code> — Tb_Mail_FcmToken 신규, MessageCache에 body_hash/fcm_notified 추가, body_preview 200자 제한</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>FCM 설정</td>
                            <td>Firebase 프로젝트 <code>shvq-1fad7</code>, VAPID Key 설정, <code>fcm_service_account.json</code> 서버 배치, <code>ecosystem.config.js</code> FCM_SERVICE_ACCOUNT_PATH 추가</td>
                            <td>공동</td>
                        </tr>
                        <tr>
                            <td>Cron 최적화</td>
                            <td><code>cron/saas/mail_sync_cron.php</code> — 배치 200, 24시간 로그인 필터, Redis online 제외, last_synced_at 우선, <code>--dry-run</code> 옵션</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>로그인 sync</td>
                            <td><code>Mail.php</code> mail_list에 로그인 20분 이내 + last_synced_at 5분 stale → 자동 mailSync 트리거</td>
                            <td>ChatGPT</td>
                        </tr>
                        <tr>
                            <td>문서</td>
                            <td>스모크/회귀 테스트 결과 + 운영 배포 체크리스트 (롤백 순서 포함) → <code>docs/saas/</code></td>
                            <td>ChatGPT</td>
                        </tr>
                    </tbody>
                </table>

                <div class="manual-warn mt-3" style="border-left-color:var(--m-ok,#28a745)"><i class="fa fa-check-circle"></i> <strong>Phase 1~4 전체 구현 완료</strong> (2026-04-14). Phase 5 부하 테스트는 운영 안정화 후 진행 예정.</div>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">IndexedDB 구조 (shvq_mail_v2)</div>
                <table class="manual-tbl">
                    <thead><tr><th>Store</th><th>keyPath</th><th>주요 Index</th><th>용도</th></tr></thead>
                    <tbody>
                        <tr><td><code>mail_body</code></td><td><code>cacheKey</code> ({accIdx}_{folder}_{uid})</td><td>by_account, by_accessedAt</td><td>본문 HTML/Text 캐시 (LRU 500MB)</td></tr>
                        <tr><td><code>mail_headers</code></td><td><code>cacheKey</code></td><td>by_account_folder_date, by_account</td><td>오프라인 목록 헤더 (stale-while-revalidate)</td></tr>
                        <tr><td><code>search_tokens</code></td><td>autoIncrement</td><td>by_account_token, by_cache_key</td><td>body 전문 검색 토큰 (한글2자+/영문2자+)</td></tr>
                        <tr><td><code>sync_meta</code></td><td><code>key</code></td><td>—</td><td>동기화 상태</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="manual-card mt-3">
                <div class="manual-sub-title">토스트 알림 CSS 클래스</div>
                <table class="manual-tbl">
                    <thead><tr><th>클래스</th><th>용도</th></tr></thead>
                    <tbody>
                        <tr><td><code>.mail-toast</code></td><td>컨테이너 (fixed, 우측 상단, z-9000)</td></tr>
                        <tr><td><code>.mail-toast-item</code></td><td>알림 카드 (glass, border-left accent)</td></tr>
                        <tr><td><code>.mail-toast-item.is-leaving</code></td><td>퇴장 애니메이션</td></tr>
                        <tr><td><code>.mail-toast-icon</code></td><td>아이콘 원형</td></tr>
                        <tr><td><code>.mail-toast-sender</code></td><td>발신자명</td></tr>
                        <tr><td><code>.mail-toast-subject</code></td><td>제목</td></tr>
                        <tr><td><code>.mail-toast-close</code></td><td>닫기 버튼</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ═══════════════════ CAD (SmartCAD) ═══════════════════ -->

        <section class="manual-sec" id="sec-cad-overview">
            <h2>CAD 아키텍처</h2>
            <h3>파일 구조</h3>
            <pre style="font-size:11px;line-height:1.5">
CAD/
├── index.php              # HTML 진입점 (UI 렌더링)
├── cad.php                # API 백엔드 (저장/불러오기/삭제/내보내기) + HTML
├── config.php             # V2 DbConnection + 보안 서비스 팩토리
├── login.php              # CAD 로그인 페이지
├── smartcad.php           # ERP→CAD iframe 연결 (토큰 발급)
├── oda_watch_v2.bat       # DWG 변환 감시 스크립트 (V2 경로)
├── css/style.css          # CAD 전용 CSS (V2 tokens.css 기반)
├── js/
│   ├── CAD_config.js      # 설정/권한/상태코드
│   ├── CAD_engine.js      # 핵심 엔진 (6000줄+) — Canvas 2D
│   └── CAD_ui.js          # 저장/불러오기/모달 UI
├── dist/
│   ├── login.php          # 인증 API (직접/토큰/세션)
│   ├── site_api.php       # 현장 연동 API
│   ├── dwg_convert.php    # DWG↔DXF 변환 (V2 경로)
│   └── CAD_xlsx_parser.php # Excel 파싱
└── cad_saves/             # 도면 JSON 저장 + DWG 변환 폴더</pre>

            <h3>접속 경로 (8가지)</h3>
            <table class="manual-tbl">
                <thead><tr><th>#</th><th>진입점</th><th>인증</th><th>UI 모드</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>ERP 사이드바 → iframe <code>?from=portal</code></td><td>세션 상속</td><td>portalSiteBar (topbar 숨김)</td></tr>
                    <tr><td>2</td><td><code>smartcad.php</code> → 토큰 발급 → iframe</td><td>CadTokenService</td><td>portalSiteBar</td></tr>
                    <tr><td>3</td><td>현장 상세 "CAD 실행" → 새 창</td><td>세션 상속</td><td>portalSiteBar</td></tr>
                    <tr><td>4</td><td>현장 도면 "열기" → 새 창 <code>?drawing_idx=</code></td><td>세션 상속</td><td>portalSiteBar</td></tr>
                    <tr><td>5</td><td>PJT 실사 "CAD 실행"</td><td>세션 상속</td><td>portalSiteBar</td></tr>
                    <tr><td>6</td><td><code>?demo=KEY</code></td><td>demo_tokens.json</td><td>topbar (독립)</td></tr>
                    <tr><td>7</td><td><code>/CAD/login.php</code> 직접 로그인</td><td>ID/PW</td><td>topbar (독립)</td></tr>
                    <tr><td>8</td><td><code>/CAD/login.php?token=</code></td><td>CadToken DB</td><td>topbar (독립)</td></tr>
                </tbody>
            </table>

            <h3>권한 시스템</h3>
            <table class="manual-tbl">
                <thead><tr><th>레벨</th><th>역할</th><th>권한</th></tr></thead>
                <tbody>
                    <tr><td>0</td><td>게스트</td><td>보기</td></tr>
                    <tr><td>1</td><td>뷰어</td><td>보기 + 내보내기</td></tr>
                    <tr><td>2</td><td>작성자</td><td>+ 편집</td></tr>
                    <tr><td>3</td><td>검수자</td><td>+ 상태변경 + 승인</td></tr>
                    <tr><td>4</td><td>관리자</td><td>+ 삭제</td></tr>
                    <tr><td>5</td><td>시스템관리자</td><td>+ 설정</td></tr>
                </tbody>
            </table>
        </section>

        <section class="manual-sec" id="sec-cad-refactor">
            <h2>V2 리팩터링 완료 (2026-04-16)</h2>

            <h3>Wave 1~5: 기반 통합</h3>
            <table class="manual-tbl">
                <thead><tr><th>Wave</th><th>내용</th><th>파일</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>DB → V2 <code>DbConnection</code> 싱글턴</td><td>config, dist/login, dist/site_api</td></tr>
                    <tr><td>2</td><td>Auth → PasswordService(bcrypt) + CSRF + RateLimiter</td><td>config, login, dist/login</td></tr>
                    <tr><td>3</td><td>native prompt 6건 → <code>cadPrompt()</code> 커스텀 모달</td><td>CAD_engine.js</td></tr>
                    <tr><td>4</td><td>CSS → V2 tokens.css 기반 + 반응형 1024px</td><td>style.css, index.php</td></tr>
                    <tr><td>5</td><td>API → <code>ApiResponse::fromLegacy()</code> + 보안헤더</td><td>cad.php, site_api, dwg_convert</td></tr>
                </tbody>
            </table>

            <h3>보안 강화</h3>
            <table class="manual-tbl">
                <thead><tr><th>항목</th><th>내용</th></tr></thead>
                <tbody>
                    <tr><td>XSS 방지</td><td><code>_esc()</code> 헬퍼 + innerHTML 21개소 이스케이프</td></tr>
                    <tr><td>CSRF</td><td>fetch 글로벌 래퍼로 <code>X-CSRF-Token</code> 자동 전송, cad.php API 검증 (save/delete/export/xlsx)</td></tr>
                    <tr><td>평문 비밀번호</td><td><code>PasswordService::verifyAndMigrate()</code> — bcrypt 자동 마이그레이션</td></tr>
                    <tr><td>Rate Limiting</td><td>로그인 5회/5분 초과 시 5분 잠금</td></tr>
                    <tr><td>DWG 인증</td><td>dwg_convert.php에 세션 체크 추가</td></tr>
                </tbody>
            </table>

            <h3>코드 품질</h3>
            <table class="manual-tbl">
                <thead><tr><th>항목</th><th>Before</th><th>After</th></tr></thead>
                <tbody>
                    <tr><td>console.log</td><td>7건</td><td>0건</td></tr>
                    <tr><td>native alert/confirm/prompt</td><td>9건+</td><td>0건</td></tr>
                    <tr><td>모달 코드</td><td>3중 중복 (150줄)</td><td><code>_cadModal()</code> 팩토리 (40줄)</td></tr>
                    <tr><td>inline style (cad.php)</td><td>256건</td><td>134건 (-122건)</td></tr>
                    <tr><td>CSS 클래스</td><td>0</td><td>80+개 추가</td></tr>
                    <tr><td>V1 경로 잔존</td><td>/SHVQ/ 참조</td><td>전부 /SHVQ_V2/ 수정</td></tr>
                    <tr><td>DWG 변환 경로</td><td>V1 (SHV_NEW)</td><td>V2 (SHVQ_V2) 분리</td></tr>
                </tbody>
            </table>

            <h3>CAD CSS 클래스 (주요)</h3>
            <table class="manual-tbl">
                <thead><tr><th>클래스</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>.cad-hint</code> / <code>.cad-val</code> / <code>.cad-accent</code></td><td>텍스트 스타일 (dim/value/accent)</td></tr>
                    <tr><td><code>.cad-flex</code> / <code>.cad-fill</code> / <code>.cad-grid-2</code></td><td>레이아웃</td></tr>
                    <tr><td><code>.cad-card</code> / <code>.cad-card-accent</code></td><td>카드 컨테이너</td></tr>
                    <tr><td><code>.cad-btn-site</code> / <code>.cad-btn-logout</code></td><td>버튼 변형</td></tr>
                    <tr><td><code>.modal-flex</code> / <code>.modal-header</code> / <code>.modal-step</code></td><td>모달 내부</td></tr>
                    <tr><td><code>.wire-table</code> / <code>.wire-input</code></td><td>배선 테이블</td></tr>
                    <tr><td><code>.cad-empty</code> / <code>.cad-list-row</code></td><td>JS 동적 HTML</td></tr>
                </tbody>
            </table>
        </section>

        <section class="manual-sec" id="sec-cad-overhaul">
            <h2>CAD 제품화 개편안</h2>
            <p>목표: SHV SmartCAD를 "건설 ERP 연동 웹 2D CAD"로 제품화. ODA 의존성 제거, 브라우저 DWG 지원.</p>
            <p><strong>원칙:</strong> 엔진 코어 전면 재작성 금지. 국소 수정만. 구조 변경과 기능 변경을 같은 배포에 섞지 않기.</p>

            <h3>Phase 1: 트림 버그 5건 (2~3일)</h3>
            <table class="manual-tbl">
                <thead><tr><th>#</th><th>버그</th><th>수정</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>_getAllCuts가 경계 선택(trimEdges) 무시</td><td>trimEdges 필터 적용</td></tr>
                    <tr><td>2</td><td>rect/circle 트림 대상 불가</td><td>트림 대상 타입 확장</td></tr>
                    <tr><td>3</td><td>드래그 트림 undo 문제</td><td>드래그 시작 시 1회 pushUndo</td></tr>
                    <tr><td>4</td><td>polyline 단일 교차점만 처리</td><td>양쪽 교차점 사이 구간 잘라내기</td></tr>
                    <tr><td>5</td><td>트림 UX: 경계 선택 단계 필수</td><td>기본 전체 경계 (AutoCAD 방식)</td></tr>
                </tbody>
            </table>

            <h3>Phase 2: 누락 기능 8개 (2~3주)</h3>
            <table class="manual-tbl">
                <thead><tr><th>기능</th><th>설명</th><th>예상</th></tr></thead>
                <tbody>
                    <tr><td>호 (Arc)</td><td>3점 호, 시작/끝/반지름</td><td>2~3일</td></tr>
                    <tr><td>해치 (Hatch)</td><td>영역 패턴 채우기</td><td>3~5일</td></tr>
                    <tr><td>필렛/챔퍼</td><td>모서리 라운드/모따기</td><td>2~3일</td></tr>
                    <tr><td>배열 (Array)</td><td>직사각형/원형 배열</td><td>1~2일</td></tr>
                    <tr><td>멀티라인 텍스트</td><td>서식 있는 텍스트</td><td>2~3일</td></tr>
                    <tr><td>테이블</td><td>부품표, 범례표</td><td>2~3일</td></tr>
                    <tr><td>OSnap 강화</td><td>접선/수직/4분점 등</td><td>2~3일</td></tr>
                    <tr><td>타원/스플라인</td><td>곡선 요소</td><td>2~3일</td></tr>
                </tbody>
            </table>

            <h3>Phase 2.5: 블록 시스템 재설계 (2~3주)</h3>
            <p>현재 "그룹"(Visual Grouping) → AutoCAD 방식 "블록 정의/참조" 구조로 전환</p>
            <table class="manual-tbl">
                <thead><tr><th>기능</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td>블록 정의/참조</td><td>1개 정의 → N개 참조(인스턴스)</td></tr>
                    <tr><td>블록 라이브러리 패널</td><td>전기/배관/가구 등 업종별 심볼</td></tr>
                    <tr><td>삽입점 + 축척/회전</td><td>배치 시 크기/각도 지정</td></tr>
                    <tr><td>블록 편집 모드</td><td>더블클릭 → 내부 편집 → 전체 반영</td></tr>
                    <tr><td>DXF BLOCK 섹션</td><td>DXF 표준 블록 정의 내보내기</td></tr>
                </tbody>
            </table>

            <h3>Phase 2.7: PDF 임포트 (1~1.5주)</h3>
            <table class="manual-tbl">
                <thead><tr><th>방식</th><th>설명</th><th>품질</th></tr></thead>
                <tbody>
                    <tr><td>A. 배경 이미지</td><td>pdf.js → Canvas → 배경. 마크업/트레이싱용</td><td>100%</td></tr>
                    <tr><td>B. 벡터 변환</td><td>pdf.js operatorList → 선/텍스트 CAD 객체. 편집 가능</td><td>60~70%</td></tr>
                </tbody>
            </table>

            <h3>Phase 2.8: 레이어 보강 (1주)</h3>
            <p>버그: 잠금 선택 차단, DXF 색상 인덱스, LTYPE 테이블</p>
            <p>기능: ByLayer/ByBlock 색상, 0 레이어, 프리즈, 인쇄 ON/OFF, 투명도, 필터</p>

            <h3>Phase 2.85: 글꼴/텍스트 스타일 (1주)</h3>
            <p>일반 텍스트 글꼴 선택, 텍스트 정렬, 텍스트 스타일 관리자, 치수 스타일, DXF STYLE 테이블</p>

            <h3>Phase 2.9: 리본 UI 개편 (1.5~2주)</h3>
            <p>SVG 아이콘 세트, 대형/소형 혼합, 그룹 박스 + 하단 레이블, Quick Access Toolbar, 상세 툴팁, 리본 최소화</p>

            <h3>Phase 3: ODA 제거 → WASM DWG (1~2주)</h3>
            <p>LibreDWG WASM 커스텀 빌드로 DWG R2000 브라우저 저장. 서버 비용 $0, 동시접속 병목 없음.</p>

            <h3>Phase 4: 제품화</h3>
            <p>멀티테넌트 도면 저장, 모바일/태블릿 터치, 도면 버전 관리, 공유/협업</p>

            <h3>타임라인</h3>
            <table class="manual-tbl">
                <thead><tr><th>Phase</th><th>내용</th><th>기간</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>트림 버그</td><td>2~3일</td></tr>
                    <tr><td>2</td><td>누락 기능 8개</td><td>2~3주</td></tr>
                    <tr><td>2.5</td><td>블록 재설계</td><td>2~3주</td></tr>
                    <tr><td>2.7</td><td>PDF 임포트</td><td>1~1.5주</td></tr>
                    <tr><td>2.8</td><td>레이어 보강</td><td>1주</td></tr>
                    <tr><td>2.85</td><td>글꼴/텍스트 스타일</td><td>1주</td></tr>
                    <tr><td>2.9</td><td>리본 UI 개편</td><td>1.5~2주</td></tr>
                    <tr><td>3</td><td>ODA→WASM DWG</td><td>1~2주</td></tr>
                    <tr><td colspan="2"><strong>합계 (Phase 1~3)</strong></td><td><strong>약 3.5~4개월</strong></td></tr>
                </tbody>
            </table>
            <p>현재 AutoCAD 2D 기능의 ~65% 구현. Phase 1~2.9 완료 시 ~95% 도달.</p>
        </section>

        <!-- ═══════════════════ 시설 (Facility) ═══════════════════ -->

        <section class="manual-sec" id="sec-facility-onvif">
            <h2 class="manual-sec-title"><i class="fa fa-video-camera"></i> ONVIF 카메라 관리 (2026-04-16)</h2>

            <h3>개요</h3>
            <p>ONVIF 프로토콜 기반 IP 카메라를 등록·관리하고, 로컬 뷰어(SHV CCTV Viewer)를 통해 실시간 영상을 시청하는 페이지입니다. 서버 부하 없이 사용자 PC에서 직접 스트리밍하여 만 명 이상 동시 사용 가능합니다.</p>

            <h3>아키텍처</h3>
            <table class="manual-tbl">
                <thead><tr><th>항목</th><th>내용</th></tr></thead>
                <tbody>
                    <tr><td>스트리밍 방식</td><td>로컬 뷰어 (<code>localhost:1984</code>, go2rtc) — 카메라→사용자 PC 직접 연결. 서버 부하 0.</td></tr>
                    <tr><td>선행 조건</td><td>SHV CCTV Viewer 앱 설치 (Windows: SHV_Viewer_Setup.exe / Mac: SHV_Viewer.pkg)</td></tr>
                    <tr><td>소유권 모델</td><td>테넌트 공유 (<code>tenant_id</code>) — 같은 회사 직원 공유, CRUD는 관리자(role_level ≥ 4)만</td></tr>
                    <tr><td>프로토콜</td><td>WebRTC (1차) → MSE/MP4 (폴백) via go2rtc</td></tr>
                </tbody>
            </table>

            <h3>화면 경로</h3>
            <p><code>?r=onvif</code> — 시설 &gt; ONVIF 카메라</p>

            <h3>파일 구조</h3>
            <table class="manual-tbl">
                <thead><tr><th>파일</th><th>용도</th><th>담당</th></tr></thead>
                <tbody>
                    <tr><td><code>views/saas/facility/onvif.php</code></td><td>ONVIF 뷰 (HTML + JS)</td><td>Claude</td></tr>
                    <tr><td><code>css/v2/pages/facility.css</code></td><td>시설 전용 CSS (<code>ov-*</code> 프리픽스)</td><td>Claude</td></tr>
                    <tr><td><code>dist_process/saas/Onvif.php</code></td><td>ONVIF API 엔드포인트</td><td>ChatGPT</td></tr>
                    <tr><td><code>js/core/router.js</code></td><td>라우트 매핑 (<code>onvif → views/saas/facility/onvif.php</code>)</td><td>Claude</td></tr>
                </tbody>
            </table>

            <h3>주요 기능</h3>
            <table class="manual-tbl">
                <thead><tr><th>#</th><th>기능</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>뷰어 상태 확인</td><td>WebSocket(<code>ws://localhost:1984/api/ws</code>)으로 로컬 뷰어 설치 여부 감지</td></tr>
                    <tr><td>2</td><td>카메라 목록</td><td>카드 뷰 + 리스트 뷰 (탭 전환), 검색 필터</td></tr>
                    <tr><td>3</td><td>카메라 CRUD</td><td>추가/수정/삭제 (SHV.modal, 관리자 전용)</td></tr>
                    <tr><td>4</td><td>연결방식 3종</td><td>ONVIF 자동 / 제조사 선택(VIGI, Hikvision, Dahua) / RTSP 직접 입력</td></tr>
                    <tr><td>5</td><td>스트림 뷰어</td><td>전체화면 오버레이, WebRTC + MSE 폴백, 화질 토글(Main/Sub)</td></tr>
                    <tr><td>6</td><td>PTZ 제어</td><td>8방향 + 줌인/줌아웃 + 프리셋 (PTZ 카메라만)</td></tr>
                    <tr><td>7</td><td>녹화</td><td>시작/중지/타이머, 사용자 PC 로컬 저장 (서버 폴더 예비: <code>uploads/{tid}/cctv/recording/</code>)</td></tr>
                    <tr><td>8</td><td>멀티뷰</td><td>1/4/6/8/16 분할 그리드, 클릭 확대/복원</td></tr>
                    <tr><td>9</td><td>자동 검색</td><td>UDP 멀티캐스트 ONVIF 디스커버리</td></tr>
                    <tr><td>10</td><td>채널 불러오기</td><td>ONVIF GetProfiles → 채널 목록 + 일괄 등록</td></tr>
                </tbody>
            </table>

            <h3>CSS 클래스 (주요)</h3>
            <table class="manual-tbl">
                <thead><tr><th>클래스</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>.ov-grid</code></td><td>카메라 카드 그리드 (auto-fill minmax(300px,1fr))</td></tr>
                    <tr><td><code>.ov-card</code> / <code>.ov-card-thumb</code></td><td>카메라 카드 + 16:9 썸네일</td></tr>
                    <tr><td><code>.ov-dot-online</code> / <code>-offline</code> / <code>-unknown</code></td><td>상태 점</td></tr>
                    <tr><td><code>.ov-ptz-badge</code></td><td>PTZ 미니 배지</td></tr>
                    <tr><td><code>.ov-badge-vigi</code> / <code>-hik</code> / <code>-dahua</code></td><td>제조사 뱃지</td></tr>
                    <tr><td><code>.ov-viewer-overlay</code></td><td>스트림 뷰어 전체화면 오버레이</td></tr>
                    <tr><td><code>.ov-stream-box</code></td><td>영상 컨테이너 (16:9)</td></tr>
                    <tr><td><code>.ov-ptz-grid</code> / <code>.ov-ptz-btn</code></td><td>PTZ 3×3 방향 패드</td></tr>
                    <tr><td><code>.ov-mv-overlay</code> / <code>.ov-mv-grid</code></td><td>멀티뷰 오버레이 + 그리드</td></tr>
                    <tr><td><code>.ov-conn-method</code></td><td>연결방식 3탭 선택 UI</td></tr>
                    <tr><td><code>.ov-tabs</code> / <code>.ov-tab-btn</code></td><td>카드/리스트 탭</td></tr>
                    <tr><td><code>.ov-status</code> / <code>-ready</code> / <code>-install</code> / <code>-checking</code></td><td>뷰어 상태 배너</td></tr>
                </tbody>
            </table>

            <h3>반응형 분기점</h3>
            <table class="manual-tbl">
                <thead><tr><th>분기</th><th>카드 그리드</th><th>멀티뷰</th><th>기타</th></tr></thead>
                <tbody>
                    <tr><td>PC (≥1024px)</td><td>auto-fill 4열</td><td>원본 (4열)</td><td>리스트 뷰 가능</td></tr>
                    <tr><td>태블릿 (768~1023px)</td><td>2열</td><td>2열</td><td>리스트 숨김</td></tr>
                    <tr><td>모바일 (&lt;768px)</td><td>2열</td><td>2열</td><td>스트림 컨트롤 세로</td></tr>
                    <tr><td>소형 (&lt;480px)</td><td>1열</td><td>1열</td><td>PTZ 38px 축소</td></tr>
                </tbody>
            </table>

            <h3>DB 테이블</h3>
            <p><strong>Tb_OnvifCameras</strong> (CSM_C004732_V2)</p>
            <table class="manual-tbl">
                <thead><tr><th>컬럼</th><th>타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td>idx</td><td>INT IDENTITY PK</td><td>자동증가</td></tr>
                    <tr><td>tenant_id</td><td>INT NOT NULL</td><td>테넌트 ID</td></tr>
                    <tr><td>created_by</td><td>INT NOT NULL</td><td>등록자 user_pk</td></tr>
                    <tr><td>camera_id</td><td>NVARCHAR(80)</td><td>카메라 고유 ID (cam_xxx)</td></tr>
                    <tr><td>name</td><td>NVARCHAR(200)</td><td>카메라명</td></tr>
                    <tr><td>channel</td><td>NVARCHAR(50)</td><td>채널 (CH01 등)</td></tr>
                    <tr><td>ip</td><td>NVARCHAR(100)</td><td>IP/DDNS 주소</td></tr>
                    <tr><td>port</td><td>INT</td><td>ONVIF 포트 (기본 80)</td></tr>
                    <tr><td>login_user / login_pass</td><td>NVARCHAR</td><td>카메라 인증</td></tr>
                    <tr><td>conn_method</td><td>NVARCHAR(20)</td><td>onvif / manufacturer / rtsp</td></tr>
                    <tr><td>manufacturer</td><td>NVARCHAR(50)</td><td>vigi / hikvision / dahua</td></tr>
                    <tr><td>rtsp_port</td><td>INT</td><td>RTSP 포트 (기본 554)</td></tr>
                    <tr><td>default_stream</td><td>NVARCHAR(10)</td><td>main / sub</td></tr>
                    <tr><td>is_ptz</td><td>TINYINT</td><td>0=일반, 1=PTZ</td></tr>
                    <tr><td>rtsp_main / rtsp_sub</td><td>NVARCHAR(1000)</td><td>RTSP URL</td></tr>
                    <tr><td>status</td><td>NVARCHAR(20)</td><td>online / offline / unknown</td></tr>
                    <tr><td>is_deleted</td><td>TINYINT</td><td>소프트 삭제</td></tr>
                </tbody>
            </table>
            <p>인덱스: <code>UQ_OnvifCameras_TenantCamera</code> (tenant_id, camera_id) UNIQUE, <code>IX_OnvifCameras_Tenant</code> (tenant_id, is_deleted, created_at)</p>
            <p>마이그레이션: <code>scripts/migrations/20260416_wave8_onvif_tenant.sql</code></p>

            <h3>파일 스토리지</h3>
            <table class="manual-tbl">
                <thead><tr><th>category</th><th>경로</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>cctv_snapshot</code></td><td><code>uploads/{tid}/cctv/snapshot/</code></td><td>CCTV 캡처 이미지</td></tr>
                    <tr><td><code>cctv_timelapse</code></td><td><code>uploads/{tid}/cctv/timelapse/</code></td><td>타임랩스</td></tr>
                    <tr><td><code>cctv_recording</code></td><td><code>uploads/{tid}/cctv/recording/</code></td><td>녹화 영상 (예비, 실제는 사용자 PC 로컬)</td></tr>
                </tbody>
            </table>
        </section>

        <section class="manual-sec" id="sec-facility-cctv">
            <h2 class="manual-sec-title"><i class="fa fa-desktop"></i> CCTV Viewer (2026-04-16)</h2>
            <h3>개요</h3>
            <p>로컬 뷰어(SHV CCTV Viewer)를 통해 NVR에 직접 연결하여 CCTV를 시청합니다. ONVIF 카메라 DB에서 IP 기준으로 NVR을 자동 그룹화합니다.</p>
            <h3>화면 경로</h3>
            <p><code>?r=cctv_viewer</code> — 시설 &gt; CCTV Viewer</p>
            <h3>파일</h3>
            <table class="manual-tbl">
                <thead><tr><th>파일</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>views/saas/facility/cctv_viewer.php</code></td><td>CCTV Viewer 뷰 (HTML+JS)</td></tr>
                </tbody>
            </table>
            <h3>주요 기능</h3>
            <table class="manual-tbl">
                <thead><tr><th>#</th><th>기능</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>뷰어 상태 확인</td><td>WebSocket(<code>ws://localhost:1984/api/ws</code>)으로 로컬 뷰어 설치 여부 감지</td></tr>
                    <tr><td>2</td><td>NVR 자동 그룹화</td><td>ONVIF 카메라 DB에서 IP 기준으로 NVR 목록 생성</td></tr>
                    <tr><td>3</td><td>그리드 분할</td><td>4/6/9/16 분할 선택, 더블클릭 1분할 확대/복원</td></tr>
                    <tr><td>4</td><td>WebRTC 스트리밍</td><td>로컬 go2rtc를 통한 직접 스트리밍 (서버 부하 0)</td></tr>
                    <tr><td>5</td><td>전체 연결/중지</td><td>선택한 NVR의 전 채널 일괄 연결/해제</td></tr>
                </tbody>
            </table>
        </section>

        <section class="manual-sec" id="sec-facility-iot">
            <h2 class="manual-sec-title"><i class="fa fa-microchip"></i> IoT 통합 관리 (2026-04-16)</h2>
            <h3>개요</h3>
            <p>SmartThings + Tuya IoT 장치를 하나의 통합 페이지에서 관리합니다. V1의 SHVQ IoT / Tuya IoT / TeniQ IoT / 도어락 4개 페이지를 단일 페이지로 리팩터링했습니다.</p>
            <h3>화면 경로</h3>
            <p><code>?r=iot</code> — 시설 &gt; IoT 관리</p>
            <h3>파일</h3>
            <table class="manual-tbl">
                <thead><tr><th>파일</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>views/saas/facility/iot.php</code></td><td>통합 IoT 뷰 (HTML+JS)</td></tr>
                    <tr><td><code>dist_process/saas/IntegrationIot.php</code></td><td>IoT API 엔드포인트</td></tr>
                    <tr><td><code>dist_library/saas/IntegrationService.php</code></td><td>IoT 서비스 계층</td></tr>
                </tbody>
            </table>
            <h3>탭 구성 (6탭)</h3>
            <table class="manual-tbl">
                <thead><tr><th>탭</th><th>내용</th></tr></thead>
                <tbody>
                    <tr><td>대시보드</td><td>장치 요약(전체/온라인/오프라인/도어락), 플랫폼 연결 상태, 타입별 분포 칩, 최근 이벤트</td></tr>
                    <tr><td>장치현황</td><td>카드/테이블 뷰 전환, 위치 필터 칩, 플랫폼/상태 필터, 인벤토리 토글, 타입별 이모지, ON/OFF 제어 버튼</td></tr>
                    <tr><td>공간매핑</td><td>위치→방 트리 구조, 방 단위 장치 카드, 전원 버튼, ALL ON/OFF, 편집모드+드래그 정렬(SortableJS)</td></tr>
                    <tr><td>도어락</td><td>4개 서브탭 (목록/사용자 관리/임시 비밀번호/출입 로그)</td></tr>
                    <tr><td>스케줄</td><td>자동화 스케줄 관리</td></tr>
                    <tr><td>로그</td><td>이벤트 로그 (capability 필터 칩 + 플랫폼/유형 필터 + 이모지)</td></tr>
                </tbody>
            </table>
            <h3>CSS 클래스 (주요)</h3>
            <table class="manual-tbl">
                <thead><tr><th>클래스</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>.iot-summary-grid</code> / <code>.iot-summary-card</code></td><td>대시보드 요약 카드</td></tr>
                    <tr><td><code>.iot-device-grid</code> / <code>.iot-device-card</code></td><td>장치 카드 그리드</td></tr>
                    <tr><td><code>.iot-room-card</code> / <code>.iot-room-grid</code> / <code>.iot-room-device</code></td><td>공간별 방 카드 + 장치 버튼</td></tr>
                    <tr><td><code>.iot-doorlock-grid</code> / <code>.iot-doorlock-card</code></td><td>도어락 카드</td></tr>
                    <tr><td><code>.iot-cmd-on</code> / <code>.iot-cmd-off</code></td><td>ON/OFF 제어 버튼</td></tr>
                    <tr><td><code>.iot-loc-chip</code></td><td>위치 필터 칩</td></tr>
                    <tr><td><code>.iot-badge-st</code> / <code>.iot-badge-tuya</code></td><td>플랫폼 뱃지</td></tr>
                    <tr><td><code>.iot-state-on</code> / <code>.iot-state-off</code> / <code>.iot-state-unknown</code></td><td>상태 뱃지</td></tr>
                    <tr><td><code>.iot-sort-ghost</code> / <code>.iot-sort-chosen</code> / <code>.iot-sort-drag</code></td><td>SortableJS 드래그 정렬</td></tr>
                </tbody>
            </table>
            <h3>DB 테이블</h3>
            <table class="manual-tbl">
                <thead><tr><th>테이블</th><th>용도</th></tr></thead>
                <tbody>
                    <tr><td><code>Tb_IntProviderAccount</code></td><td>IoT 플랫폼 계정 (SmartThings/Tuya)</td></tr>
                    <tr><td><code>Tb_IntDevice</code></td><td>IoT 장치 목록 (device_type, sort_order, is_hidden 포함)</td></tr>
                    <tr><td><code>Tb_IntSyncCheckpoint</code></td><td>동기화 체크포인트</td></tr>
                    <tr><td><code>Tb_IntCommandLog</code></td><td>장치 제어 명령 로그</td></tr>
                    <tr><td><code>Tb_IntEventLog</code></td><td>IoT 이벤트 로그</td></tr>
                    <tr><td><code>Tb_IntLockCredentialMap</code></td><td>도어락 출입자 매핑</td></tr>
                    <tr><td><code>Tb_IntDoorlockTempPassword</code></td><td>도어락 임시 비밀번호</td></tr>
                </tbody>
            </table>
            <h3>V1→V2 이관 현황</h3>
            <table class="manual-tbl">
                <thead><tr><th>데이터</th><th>건수</th><th>상태</th></tr></thead>
                <tbody>
                    <tr><td>계정 (Tb_IntProviderAccount)</td><td>5건</td><td>이관 완료</td></tr>
                    <tr><td>장치 (Tb_IntDevice)</td><td>227건 (17종 타입)</td><td>이관 완료</td></tr>
                    <tr><td>이벤트 로그</td><td>-</td><td>V1 컬럼 불일치로 skip, 실시간 축적 예정</td></tr>
                </tbody>
            </table>
            <h3>권한 체계</h3>
            <p><strong>role_level: 1=최고관리자, 2=관리자, 3=일반</strong> (역순). 프론트 <code>$_roleLevel &lt;= 2</code> / JS <code>ROLE_LEVEL &lt;= 2</code>로 관리자 판별.</p>
        </section>

        <section class="manual-sec" id="sec-facility-api">
            <h2 class="manual-sec-title"><i class="fa fa-plug"></i> 시설 API 엔드포인트</h2>
            <p>엔드포인트: <code>dist_process/saas/Onvif.php?todo={action}</code></p>

            <table class="manual-tbl">
                <thead><tr><th>todo</th><th>메서드</th><th>권한</th><th>파라미터</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code>camera_list</code></td><td>GET</td><td>전체</td><td>-</td><td>테넌트 전체 카메라 목록</td></tr>
                    <tr><td><code>camera_upsert</code></td><td>POST</td><td>관리자</td><td>id, name, ip, port, user, pass, channel, memo, connMethod, manufacturer, rtspPort, defaultStream, isPtz, rtspMain, rtspSub</td><td>카메라 등록/수정 (MERGE)</td></tr>
                    <tr><td><code>camera_delete</code></td><td>POST</td><td>관리자</td><td>id</td><td>카메라 소프트 삭제</td></tr>
                    <tr><td><code>camera_bulk_upsert</code></td><td>POST</td><td>관리자</td><td>cameras (JSON string)</td><td>카메라 일괄 등록</td></tr>
                    <tr><td><code>test</code></td><td>GET</td><td>전체</td><td>ip, port, user, pass</td><td>ONVIF SOAP 연결 테스트</td></tr>
                    <tr><td><code>tcp_check</code></td><td>GET</td><td>전체</td><td>ip, port, timeout</td><td>TCP 포트 확인</td></tr>
                    <tr><td><code>rtsp_auth_check</code></td><td>GET</td><td>전체</td><td>ip, port, user, pass, timeout</td><td>RTSP 인증 확인</td></tr>
                    <tr><td><code>channels</code></td><td>GET</td><td>전체</td><td>ip, port, user, pass, timeout</td><td>ONVIF 채널 목록 조회</td></tr>
                    <tr><td><code>discover</code></td><td>GET</td><td>전체</td><td>timeout</td><td>UDP 멀티캐스트 자동 검색</td></tr>
                    <tr><td><code>ptz</code></td><td>GET</td><td>전체</td><td>dir, ip, port, user, pass</td><td>PTZ 제어 (stub)</td></tr>
                    <tr><td><code>record_start</code></td><td>POST</td><td>전체</td><td>stream_id, rtsp, duration_sec, height</td><td>녹화 시작 (FFmpeg)</td></tr>
                    <tr><td><code>record_stop</code></td><td>POST</td><td>전체</td><td>record_id, pid</td><td>녹화 중지</td></tr>
                    <tr><td><code>record_list</code></td><td>GET</td><td>전체</td><td>month</td><td>녹화 파일 목록</td></tr>
                </tbody>
            </table>

            <h3>IoT API (<code>dist_process/saas/IntegrationIot.php</code>)</h3>
            <table class="manual-tbl">
                <thead><tr><th>todo</th><th>메서드</th><th>권한</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code>dashboard</code></td><td>GET</td><td>전체</td><td>장치 요약 + 플랫폼 상태 + 타입별 분포</td></tr>
                    <tr><td><code>device_list</code></td><td>GET</td><td>전체</td><td>장치 목록 (device_type, is_ctrl, cmds, map_count 포함)</td></tr>
                    <tr><td><code>space_list</code></td><td>GET</td><td>전체</td><td>공간별 장치 그룹핑</td></tr>
                    <tr><td><code>event_log</code></td><td>GET</td><td>전체</td><td>이벤트 로그 (capability, log_type 필터)</td></tr>
                    <tr><td><code>device_cmd</code></td><td>POST</td><td>관리자</td><td>장치 제어 (on/off/open/close/lock/unlock)</td></tr>
                    <tr><td><code>device_sort_order</code></td><td>POST</td><td>관리자</td><td>장치 정렬 순서 저장</td></tr>
                    <tr><td><code>device_visibility</code></td><td>POST</td><td>관리자</td><td>장치 숨김/표시 토글</td></tr>
                    <tr><td><code>sync_devices</code></td><td>POST</td><td>관리자</td><td>장치 동기화 요청</td></tr>
                    <tr><td><code>settings_form</code></td><td>GET</td><td>관리자</td><td>설정 모달 HTML (계정/구독/Tuya API)</td></tr>
                    <tr><td><code>doorlock_users</code></td><td>GET</td><td>전체</td><td>도어락 사용자 목록</td></tr>
                    <tr><td><code>doorlock_temp_passwords</code></td><td>GET</td><td>전체</td><td>도어락 임시 비밀번호 목록</td></tr>
                    <tr><td><code>doorlock_log</code></td><td>GET</td><td>전체</td><td>도어락 출입 로그</td></tr>
                    <tr><td><code>doorlock_access_mapping</code></td><td>GET</td><td>전체</td><td>도어락 출입자 매핑</td></tr>
                </tbody>
            </table>

            <h3>응답 형식</h3>
            <p>V2 <code>ApiResponse</code> 표준: <code>{"ok":true,"success":true,"code":"OK","data":{...}}</code></p>
            <p>프론트 접근: <code>SHV.api.get/post()</code> → <code>res.ok</code>, <code>res.data.items</code>, <code>res.data.item</code></p>

            <h3>권한 체계</h3>
            <p><strong>role_level: 1=최고관리자, 2=관리자, 3=일반</strong> (낮을수록 높은 권한). 백엔드 관리자 체크: <code>role_level &gt; 2</code>이면 차단.</p>
        </section>

    </div><!-- /manual-content -->
</div><!-- /manual-wrap -->

</section>

<style>
/* ── MANUAL PAGE TOKENS (Light default) ── */
.manual-wrap {
    --m-bg: #eef4fb;
    --m-bg-2: #e4ecf8;
    --m-surface: rgba(255, 255, 255, 0.88);
    --m-surface-2: rgba(244, 248, 255, 0.92);
    --m-line: #d6e0ef;
    --m-line-strong: #c8d6eb;
    --m-text: #162237;
    --m-muted: #5b6f8d;
    --m-accent: #2563eb;
    --m-accent-soft: rgba(37, 99, 235, 0.13);
    --m-accent-line: rgba(37, 99, 235, 0.34);
    --m-code-bg: #0f172a;
    --m-code-line: #243a64;
    --m-code-text: #c8d5ef;
    --m-warn-bg: rgba(194, 120, 3, 0.08);
    --m-warn-line: rgba(194, 120, 3, 0.24);
    --m-warn-text: #9a5f06;
    --m-danger: #dc2626;
    --m-row-alt: rgba(30, 84, 186, 0.03);
}

[data-theme="dark"] .manual-wrap {
    --m-bg: #0f1d38;
    --m-bg-2: #14284d;
    --m-surface: rgba(17, 30, 53, 0.86);
    --m-surface-2: rgba(20, 37, 66, 0.92);
    --m-line: #2e4368;
    --m-line-strong: #3a5688;
    --m-text: #e9f1ff;
    --m-muted: #9fb1cd;
    --m-accent: #76a8ff;
    --m-accent-soft: rgba(118, 168, 255, 0.2);
    --m-accent-line: rgba(118, 168, 255, 0.46);
    --m-code-bg: #0b1327;
    --m-code-line: #2a4068;
    --m-code-text: #afc0dd;
    --m-warn-bg: rgba(234, 179, 8, 0.12);
    --m-warn-line: rgba(234, 179, 8, 0.3);
    --m-warn-text: #f7c44e;
    --m-danger: #ff7a7a;
    --m-row-alt: rgba(118, 168, 255, 0.06);
}

/* ── Manual shell ── */
.manual-wrap {
    position: relative;
    display: grid;
    grid-template-columns: 248px minmax(0, 1fr);
    gap: 22px;
    align-items: start;
    min-height: calc(100vh - 122px);
    padding: 18px;
    border-radius: 22px;
    border: 1px solid var(--m-line-strong);
    background:
        radial-gradient(1200px 560px at 92% -18%, rgba(80, 133, 255, 0.22), transparent 62%),
        radial-gradient(900px 500px at -10% 110%, rgba(31, 110, 255, 0.16), transparent 64%),
        linear-gradient(155deg, var(--m-bg) 0%, var(--m-bg-2) 100%);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.38),
        0 14px 34px rgba(19, 43, 88, 0.14);
}

[data-theme="dark"] .manual-wrap {
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.08),
        0 18px 46px rgba(2, 9, 20, 0.55);
}

/* ── Left nav ── */
.manual-nav {
    position: sticky;
    top: 14px;
    width: 100%;
    max-height: calc(100vh - 136px);
    overflow-y: auto;
    border-radius: 18px;
    border: 1px solid var(--m-line);
    background: var(--m-surface);
    padding: 10px 0 12px;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35);
    scrollbar-width: thin;
    scrollbar-color: rgba(69, 126, 243, 0.4) transparent;
}

.manual-nav::-webkit-scrollbar { width: 6px; }
.manual-nav::-webkit-scrollbar-thumb {
    background: rgba(69, 126, 243, 0.35);
    border-radius: 999px;
}

.manual-nav-title {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 14px 10px;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--m-line);
    color: var(--m-text);
    font-size: 14px;
    font-weight: 700;
}

.manual-nav-title i { color: var(--m-accent); }

.manual-nav-group {
    margin-top: 4px;
    padding: 8px 14px 4px;
    color: var(--m-muted);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.manual-nav-item {
    display: block;
    margin: 2px 8px;
    padding: 8px 10px 8px 12px;
    border-left: 3px solid transparent;
    border-radius: 10px;
    text-decoration: none;
    color: var(--m-muted);
    font-size: 12px;
    line-height: 1.45;
    cursor: pointer;
    transition: background .16s ease, color .16s ease, border-color .16s ease;
}

.manual-nav-item:hover {
    color: var(--m-text);
    background: rgba(52, 112, 235, 0.09);
}

.manual-nav-item.active {
    color: var(--m-accent);
    font-weight: 700;
    border-left-color: var(--m-accent);
    background: var(--m-accent-soft);
}

/* ── Content ── */
.manual-content {
    min-width: 0;
    padding: 0 4px 0 0;
}

.manual-content a {
    color: var(--m-accent);
    text-decoration: none;
    border-bottom: 1px dashed rgba(52, 112, 235, 0.44);
}

.manual-content a:hover { border-bottom-style: solid; }
.manual-content .text-danger { color: var(--m-danger); font-weight: 700; }

/* ── Section ── */
.manual-sec {
    margin-bottom: 24px;
    scroll-margin-top: 20px;
}

.manual-sec-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 12px;
    padding: 0 2px 10px;
    border-bottom: 2px solid var(--m-accent-line);
    color: var(--m-text);
    font-size: clamp(18px, 1.75vw, 24px);
    font-weight: 800;
    letter-spacing: -0.02em;
}

.manual-sec-title i { color: var(--m-accent); }

/* ── Card / text ── */
.manual-card {
    border: 1px solid var(--m-line);
    border-radius: 16px;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0)),
        var(--m-surface);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.3),
        0 8px 18px rgba(13, 31, 67, 0.09);
    padding: 16px;
    overflow-x: auto;
}

[data-theme="dark"] .manual-card {
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.08),
        0 10px 24px rgba(0, 0, 0, 0.34);
}

.manual-card.mt-3 { margin-top: 12px; }

.manual-tbl-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    color: var(--m-text);
    font-size: 14px;
    font-weight: 700;
}

.manual-sub-title {
    margin-bottom: 8px;
    color: var(--m-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.manual-sub-title.mt-3 { margin-top: 12px; }

.manual-desc {
    margin-bottom: 10px;
    color: var(--m-muted);
    font-size: 13px;
    line-height: 1.65;
}

/* ── Table ── */
.manual-tbl {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border: 1px solid var(--m-line);
    border-radius: 12px;
    overflow: hidden;
    background: var(--m-surface);
    font-size: 13px;
}

.manual-tbl.mt-3 { margin-top: 12px; }

.manual-tbl th {
    min-width: 136px;
    padding: 10px 12px;
    background: var(--m-surface-2);
    border-bottom: 1px solid var(--m-line);
    color: var(--m-muted);
    font-size: 12px;
    font-weight: 700;
    text-align: left;
    vertical-align: top;
    white-space: nowrap;
}

.manual-tbl td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--m-line);
    color: var(--m-text);
    line-height: 1.58;
    vertical-align: top;
}

.manual-tbl thead th {
    color: var(--m-text);
    background: linear-gradient(180deg, var(--m-surface-2) 0%, rgba(148, 176, 223, 0.12) 100%);
}

.manual-tbl tbody tr:nth-child(even) td {
    background: var(--m-row-alt);
}

.manual-tbl tr:last-child th,
.manual-tbl tr:last-child td {
    border-bottom: none;
}

.manual-tbl td code,
.manual-tbl th code {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 999px;
    border: 1px solid var(--m-accent-line);
    background: var(--m-accent-soft);
    color: var(--m-accent);
    font-size: 11px;
    font-family: 'SFMono-Regular', Consolas, 'D2Coding', monospace;
}

/* ── Code block ── */
.manual-code {
    margin: 0;
    padding: 14px;
    border: 1px solid var(--m-code-line);
    border-radius: 12px;
    background: var(--m-code-bg);
    color: var(--m-code-text);
    font-size: 12px;
    line-height: 1.7;
    white-space: pre;
    overflow-x: auto;
    font-family: 'SFMono-Regular', Consolas, 'D2Coding', monospace;
}

/* ── Notice / badge ── */
.manual-warn {
    margin-top: 8px;
    border: 1px solid var(--m-warn-line);
    border-radius: 10px;
    padding: 9px 12px;
    background: var(--m-warn-bg);
    color: var(--m-warn-text);
    font-size: 12px;
    line-height: 1.55;
}

.manual-warn.mt-2 { margin-top: 8px; }
.manual-warn i { margin-right: 4px; }

.manual-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(100, 116, 139, .3);
    background: rgba(100, 116, 139, .15);
    color: #70839f;
    font-size: 10px;
    font-weight: 700;
}

.manual-badge--v2 {
    border-color: var(--m-accent-line);
    background: var(--m-accent-soft);
    color: var(--m-accent);
}

.manual-badge--plan {
    border-color: var(--m-warn-line);
    background: var(--m-warn-bg);
    color: var(--m-warn-text);
}

/* ── Responsive ── */
@media (max-width: 1280px) {
    .manual-wrap { grid-template-columns: 230px minmax(0, 1fr); }
}

@media (max-width: 1024px) {
    .manual-wrap {
        grid-template-columns: 206px minmax(0, 1fr);
        gap: 16px;
        padding: 14px;
    }
}

@media (max-width: 768px) {
    .manual-wrap {
        grid-template-columns: 1fr;
        gap: 12px;
        min-height: auto;
        padding: 10px;
        border-radius: 14px;
    }

    .manual-nav {
        position: static;
        max-height: none;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 12px;
    }

    .manual-nav-title {
        width: 100%;
        margin-bottom: 2px;
        padding: 0 0 6px;
    }

    .manual-nav-group { display: none; }

    .manual-nav-item {
        margin: 0;
        padding: 6px 10px;
        border: 1px solid var(--m-line);
        border-left-width: 1px;
        border-radius: 999px;
        background: var(--m-surface-2);
        font-size: 12px;
    }

    .manual-nav-item.active {
        border-color: var(--m-accent);
        box-shadow: 0 0 0 1px var(--m-accent-line);
    }

    .manual-content { padding-right: 0; }
    .manual-sec { margin-bottom: 18px; }
    .manual-card { padding: 12px; border-radius: 12px; }
    .manual-tbl { min-width: 640px; font-size: 12px; }
    .manual-code { font-size: 11px; }
}
</style>

<script>
(function () {
    var pageRoot = document.querySelector('[data-page="manual"]');
    if (!pageRoot) return;

    var navItems = pageRoot.querySelectorAll('.manual-nav-item[data-target]');
    var sections = pageRoot.querySelectorAll('.manual-sec[id]');
    if (!navItems.length || !sections.length) return;

    var contentScrollRoot = document.getElementById('content');
    var navBySectionId = {};
    navItems.forEach(function (item) {
        navBySectionId[item.dataset.target] = item;
    });

    function activateById(sectionId) {
        navItems.forEach(function (item) {
            item.classList.toggle('active', item.dataset.target === sectionId);
        });
    }

    navItems.forEach(function (item) {
        item.addEventListener('click', function () {
            var sectionId = item.dataset.target;
            var target = pageRoot.querySelector('#' + sectionId);
            if (!target) return;
            activateById(sectionId);
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    function detectActiveSection() {
        var rootTop = contentScrollRoot ? contentScrollRoot.getBoundingClientRect().top : 0;
        var chosen = sections[0].id;
        var minDistance = Number.POSITIVE_INFINITY;

        sections.forEach(function (sec) {
            var distance = sec.getBoundingClientRect().top - rootTop - 72;
            if (distance <= 0 && Math.abs(distance) < minDistance) {
                minDistance = Math.abs(distance);
                chosen = sec.id;
            }
        });

        if (navBySectionId[chosen]) {
            activateById(chosen);
        }
    }

    var ticking = false;
    function onScroll() {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(function () {
            detectActiveSection();
            ticking = false;
        });
    }

    if (contentScrollRoot) {
        contentScrollRoot.addEventListener('scroll', onScroll, { passive: true });
    } else {
        document.addEventListener('scroll', onScroll, { passive: true });
    }
    window.addEventListener('resize', onScroll, { passive: true });
    detectActiveSection();
}());
</script>
