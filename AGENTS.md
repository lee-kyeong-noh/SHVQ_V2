# SHVQ V2 — AI 개발 규칙

## 프로젝트 개요
- SH Vision ERP Portal v2.0 (SaaS 멀티테넌트 재설계)
- Vanilla JS + 순수 CSS (프레임워크 없음), PHP + MSSQL (PDO sqlsrv)
- URL: https://shvq.kr/SHVQ_V2/
- FTP 경로: ftp://211.116.112.67:21/SHVQ_V2/

## 역할 분담 (고정)
- **ChatGPT**: PHP 백엔드 (컨트롤러/모델/DB 쿼리/인증/권한) 전담
- **Codex**: CSS/HTML/JS/뷰 템플릿 작성, FTP 서버 반영, 전체 코드 리뷰 담당
- **프론트 파일 수정 금지** — ChatGPT는 CSS/HTML/JS 파일 직접 수정 금지

## 협업 운영 원칙 (2026-04-16 확정)
- 강점 기반 분업 유지: **프론트(CSS/HTML/JS/뷰/브라우저 검증/FTP)는 Codex**, **백엔드(PHP/DB/API/외부연동)는 ChatGPT**
- 역할 역침범 금지: 백엔드 작업 중 프론트 변경 금지, 프론트 작업 중 백엔드 로직 변경은 사용자 승인 후 진행
- 공통 필수 검증:
  - `role_level` 기준은 **1=최고관리자, 2=관리자, 3=일반 (숫자 낮을수록 권한 높음)**
  - 권한식 치환 원칙: `>= 4` → `<= 2`, `< 4` → `> 2`
  - 멀티테넌트 키 정합성: `tenant_id`, `service_code`, `provider_account_idx` 동시 검증
  - 외부연동 토큰 문제 시 `Tb_IntProviderToken` 실데이터와 토큰 조회 SQL의 WHERE 조건 불일치부터 우선 점검

## 파일 업로드 규칙
- **서버 파일 비교 필수** — 모든 파일을 서버에 업로드하기 전에 사이즈/타임스탬프를 서버 파일과 비교. 서버 파일이 더 최신이면 다운로드 후 수정하여 업로드하거나 머징 (동료 작업 덮어쓰기 방지)
- **파일 수정 시작 전 반드시 서버 최신본 다운로드** — 작업 시작 시점에 FTP로 해당 파일을 먼저 받고, 로컬에서 수정 후 업로드
- FTP: `curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" -T {로컬파일} "ftp://211.116.112.67:21/SHVQ_V2/{경로}"`

## 개발 규칙
- V1(SHV_NEW/) 디자인/코드 참조 금지 — 사용자가 명시적으로 요청할 때만 예외
- 작업 전 반드시 검토부터. 프론트 검토 시 10번 이상 정독, 한줄한줄 한땀한땀
- 부트스트랩 사용 안 함, 순수 CSS만 (css/v2/ 폴더)
- 반응형 필수 (PC → 태블릿 1024px → 모바일 768px)
- alert / confirm / prompt 사용 금지 → 모달팝업으로 통일
- 개발 완료 시 개발일지(DevLog) API로 자동 기록
- 수정 후 FTP로 자동 서버 업로드

## 주의 API
- **check_qty_limit** (`dist_process/Project.php`) — PJT 수량제한 API. 속성별 제한 로직(총수량/품목갯수 모드), Tb_EstimateItem + Tb_PjtPlanEstItem(미확정 단계) 합산 검증. **수정 시 반드시 사용자 재확인 필요**

## DB 규칙
- 활성 DB: **CSM_C004732_V2** (67번 개발DB)
- V1 DB(CSM_C004732) ALTER 금지, 조회 전용
- 상용DB 66번 접근 절대 금지

## 파일 구조
```
SHVQ_V2/
├── login.php                         # V2 로그인 (AJAX + CSRF)
├── index.php                         # SPA 메인 (미구현)
├── config/
│   ├── env.php                       # 환경변수 로더
│   ├── security.php                  # 보안 설정
│   └── database.php                  # DB 연결 설정
├── dist_library/saas/security/       # 인증/보안 클래스
│   ├── init.php                      # 클래스 로더 (의존성 순서 보장)
│   ├── AuthService.php               # 핵심 인증 서비스
│   ├── SessionManager.php            # 세션 + V1 호환 레이어
│   ├── CsrfService.php               # CSRF 토큰
│   ├── RateLimiter.php               # 레이트 리미팅
│   ├── PasswordService.php           # bcrypt + legacy 마이그레이션
│   ├── RememberTokenService.php      # selector:validator 자동로그인
│   ├── CadTokenService.php           # CAD 원타임 토큰
│   ├── AuditLogger.php               # 감사 로그
│   ├── AuthAuditService.php          # 감사 로그 조회
│   ├── ApiResponse.php               # 응답 표준화
│   ├── ClientIpResolver.php          # IP 해석 (proxy 안전)
│   └── DbConnection.php              # PDO 싱글턴
├── dist_process/saas/                # API 엔드포인트
│   ├── Auth.php                      # csrf/login/remember_session/logout/cad_token
│   └── AuthAudit.php                 # 감사 로그 조회 (관리자용)
├── css/v2/                           # V2 전용 CSS
│   ├── tokens.css                    # 디자인 토큰
│   ├── reset.css
│   ├── layout.css
│   ├── glass.css                     # Liquid Glass 컴포넌트
│   ├── components.css
│   ├── utilities.css
│   ├── responsive.css
│   ├── login.css                     # 로그인 페이지 전용
│   └── img/login_bg.png
├── js/
│   ├── login.js                      # 로그인 페이지 JS
│   └── core/
│       ├── dom.js                    # SHV.$ / SHV.dom.*
│       ├── csrf.js                   # SHV.csrf.*
│       ├── api.js                    # SHV.api.get/post/upload
│       ├── events.js                 # SHV.events.on/off/action
│       └── router.js                 # SHV.router (?system=&r=)
└── scripts/migrations/
    └── 20260412_wave0_auth_security.sql
```

## 개발일지 기록
```
curl -s -X POST "http://211.116.112.67/SHVQ/dist_process/DevLog.php" \
  --data-urlencode "todo=insert" \
  --data-urlencode "system_type=V2" \
  --data-urlencode "category={분류}" \
  --data-urlencode "title={제목}" \
  --data-urlencode "content={내용}" \
  --data-urlencode "status=1" \
  --data-urlencode "dev_time=YYYY-MM-DD HH:MM:SS" \
  --data-urlencode "file_count={수정/추가 파일 건수}"
```
