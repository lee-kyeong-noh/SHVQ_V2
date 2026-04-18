# SHVQ V2 — AI 개발 규칙

## 프로젝트 개요
- SH Vision ERP Portal v2.0 (SaaS 멀티테넌트 재설계)
- Vanilla JS + 순수 CSS (프레임워크 없음), PHP + MSSQL (PDO sqlsrv)
- URL: https://shvq.kr/SHVQ_V2/
- FTP 경로: ftp://211.116.112.67:21/SHVQ_V2/

## 역할 분담 (고정)

| 영역 | Claude | ChatGPT |
|------|--------|---------|
| CSS/디자인 (리퀴드글래스, 반응형, 토큰 기반) | ✅ 강점 | - |
| HTML 구조 (시맨틱, 접근성, V2 규칙 준수) | ✅ 강점 | - |
| JS 프론트 (SPA 라이프사이클, 이벤트 위임, 메모리 관리) | ✅ 강점 | - |
| 코드 리뷰 (인라인 스타일, 리스너 누적, XSS 등 꼼꼼) | ✅ 강점 | - |
| PHP 백엔드 (SOAP/ONVIF, DB 스키마, 인증, 암호화) | - | ✅ 강점 |
| DB 설계 (테이블 설계, 마이그레이션, 컬럼 fallback) | - | ✅ 강점 |
| API 설계 (권한 체크, CSRF, FormData 호환) | - | ✅ 강점 |
| 외부 연동 (SmartThings/Tuya API, 토큰 관리) | - | ✅ 강점 |
| FTP 배포 | ✅ | - |
| 브라우저 검증 | ✅ | - |

- **프론트 파일 수정 금지** — ChatGPT는 CSS/HTML/JS 파일 직접 수정 금지
- **백엔드 파일 수정 금지** — Claude는 dist_process/saas/*.php, dist_library/**/*.php 직접 수정 금지 (코드 리뷰 + 스펙 전달만)
- **권한 체계** — role_level: 1=최고관리자, 2=관리자, 3=일반 (낮을수록 높은 권한). 관리자 판별: `role_level <= 2`

## 파일 업로드 규칙
- **서버 파일 비교 필수** — 모든 파일을 서버에 업로드하기 전에 사이즈/타임스탬프를 서버 파일과 비교. 서버 파일이 더 최신이면 다운로드 후 수정하여 업로드하거나 머징 (동료 작업 덮어쓰기 방지)
- **파일 수정 시작 전 반드시 서버 최신본 다운로드** — 작업 시작 시점에 FTP로 해당 파일을 먼저 받고, 로컬에서 수정 후 업로드
- FTP 코드 배포: `curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" -T {로컬파일} "ftp://211.116.112.67:21/SHVQ_V2/{경로}"`

## 파일 스토리지
> 웹 배포 FTP(211.116.112.67:21)와 **별개**의 이미지 전용 FTP 서버

| 구분 | 주소 | 용도 |
|------|------|------|
| 웹서버 FTP | `211.116.112.67:21` | PHP 코드 배포 전용 |
| 이미지 FTP | `192.168.11.66:5090` (내부망) | 파일/이미지 저장 (E: 드라이브로 마운트) |

- **웹서버 루트**: `D:/SHV_ERP/` (shvq.kr 서빙)
- **업로드 베이스 경로**: `D:/SHV_ERP/SHVQ_V2/uploads/`
- **HTTP URL 베이스**: `https://shvq.kr/SHVQ_V2/uploads/`
- **img.shv.kr 미사용** — shvq.kr 단일 도메인으로 통합

**폴더 구조**
```
D:/SHV_ERP/SHVQ_V2/uploads/
├── mat/                        ← V1 이관 이미지 584개 (tenant 없음)
│   └── attach/
├── mail/
│   └── attach/
├── common/
├── temp/
└── {tenant_id}/                ← V2 신규 업로드 (tenant 격리)
    ├── mat/                    ← 품목 이미지/첨부
    │   └── attach/
    ├── mail/                   ← 메일
    │   ├── attach/
    │   └── inline/
    ├── employee/               ← 직원 프로필
    ├── common/                 ← 공통
    ├── temp/                   ← 임시
    ├── head/attach/            ← 본사 첨부
    ├── member/                 ← 사업장
    │   ├── attach/
    │   └── ocr/
    ├── site/                   ← 현장
    │   ├── attach/
    │   ├── est/
    │   ├── bill/
    │   ├── floor/
    │   └── subcontract/
    ├── contact/photo/          ← 연락처 사진
    ├── comment/                ← 코멘트(특기사항) 파일
    ├── pjt/                    ← PJT
    │   ├── attach/
    │   ├── photo/
    │   └── inspect/
    ├── cad/                    ← CAD
    │   ├── drawing/
    │   └── export/
    ├── cctv/                   ← CCTV/NVR
    │   ├── snapshot/
    │   ├── timelapse/
    │   └── recording/
    ├── iot/data/               ← IoT 센서
    └── grp/                    ← 그룹웨어
        ├── approval/
        └── board/
```

**카테고리 목록**

| category 키 | 저장 경로 | 용도 |
|---|---|---|
| **MAT 품목관리** | | |
| `mat_banner` | `uploads/{tid}/mat/` | 품목 배너 이미지 |
| `mat_detail` | `uploads/{tid}/mat/` | 품목 상세 이미지 |
| `mat_attach` | `uploads/{tid}/mat/attach/` | 품목 첨부파일 |
| **메일** | | |
| `mail_attach` | `uploads/{tid}/mail/attach/` | 메일 첨부파일 |
| `mail_inline` | `uploads/{tid}/mail/inline/` | 메일 인라인 이미지 |
| **인사/직원** | | |
| `employee` | `uploads/{tid}/employee/` | 직원 프로필 사진 |
| **공통** | | |
| `common` | `uploads/{tid}/common/` | 공통 파일 |
| `temp` | `uploads/{tid}/temp/` | 임시 파일 |
| **FMS 본사** | | |
| `head_attach` | `uploads/{tid}/head/attach/` | 본사 첨부파일 |
| **FMS 사업장** | | |
| `member_attach` | `uploads/{tid}/member/attach/` | 사업장 첨부파일 |
| `ocr_scan` | `uploads/{tid}/member/ocr/` | OCR 스캔 이미지 (명함/사업자등록증) |
| **FMS 현장** | | |
| `site_attach` | `uploads/{tid}/site/attach/` | 현장 첨부파일 |
| `est_attach` | `uploads/{tid}/site/est/` | 견적 첨부파일 |
| `bill_attach` | `uploads/{tid}/site/bill/` | 수금 첨부파일 |
| `floor_plan` | `uploads/{tid}/site/floor/` | 도면 파일 |
| `subcontract` | `uploads/{tid}/site/subcontract/` | 도급 계약서 |
| **FMS 연락처** | | |
| `contact_photo` | `uploads/{tid}/contact/photo/` | 연락처 프로필/명함 사진 |
| **코멘트 (특기사항)** | | |
| `comment_file` | `uploads/{tid}/comment/` | SHV.chat 첨부파일 |
| **PJT** | | |
| `pjt_attach` | `uploads/{tid}/pjt/attach/` | PJT 첨부파일 |
| `pjt_photo` | `uploads/{tid}/pjt/photo/` | PJT 현장 사진 |
| `pjt_inspect` | `uploads/{tid}/pjt/inspect/` | PJT 검사 사진 |
| **CAD** | | |
| `cad_drawing` | `uploads/{tid}/cad/drawing/` | CAD 도면 파일 |
| `cad_export` | `uploads/{tid}/cad/export/` | CAD 내보내기 |
| **CCTV / NVR** | | |
| `cctv_snapshot` | `uploads/{tid}/cctv/snapshot/` | CCTV 캡처 이미지 |
| `cctv_timelapse` | `uploads/{tid}/cctv/timelapse/` | 타임랩스 이미지 |
| `cctv_recording` | `uploads/{tid}/cctv/recording/` | 녹화 영상 |
| **IoT** | | |
| `iot_data` | `uploads/{tid}/iot/data/` | IoT 센서 데이터/이미지 |
| **GRP 그룹웨어** | | |
| `approval_attach` | `uploads/{tid}/grp/approval/` | 전자결재 첨부 |
| `board_attach` | `uploads/{tid}/grp/board/` | 게시판 첨부 |

**ChatGPT API 사용법**
```php
// 신규 파일 업로드
$storage = StorageService::forTenant($context['tenant_id']);
$result  = $storage->upload('mat_banner', $_FILES['banner'], "item_{$idx}");
// $result['url']      → https://shvq.kr/SHVQ_V2/uploads/{tid}/mat/item_11_xxx.jpg
// $result['filename'] → item_11_xxx.jpg

// V1 이관 이미지 URL (upload_files_banner / upload_files_detail 컬럼값)
StorageService::legacyUrl($row['upload_files_banner'])
// → https://shvq.kr/SHVQ_V2/uploads/mat/11_upload_files_banner_20231012.png
```

**클래스 위치**
- `dist_library/saas/storage/StorageDriver.php` — PHP file 함수 기반 드라이버
- `dist_library/saas/storage/StorageService.php` — `forTenant($tenantId)` 진입점
- `config/storage.php` — 경로·URL·제한 설정
- `.env` — `STORAGE_BASE_PATH=D:/SHV_ERP/SHVQ_V2/uploads/` / `STORAGE_BASE_URL=https://shvq.kr/SHVQ_V2`

## 개발 규칙
- V1(SHV_NEW/) 디자인/코드 참조 금지 — 사용자가 명시적으로 요청할 때만 예외
- 작업 전 반드시 검토부터. 프론트 검토 시 10번 이상 정독, 한줄한줄 한땀한땀
- 부트스트랩 사용 안 함, 순수 CSS만 (css/v2/ 폴더)
- **inline style 사용 금지** — PHP 뷰, JS 동적 HTML 생성 모두 해당. 반드시 css/v2/ 클래스로 처리. JS로 show/hide 제어하는 `element.style.display` 등 동작성 코드는 허용. 예외가 필요한 경우 반드시 사용자 승인 후 적용
- **CSS 파일 작성 순서** — 파일 내 셀렉터는 화면 위→아래 위치 순으로 작성. 레이아웃 큰 틀(wrapper/header) → 내부 영역(body/content) → 하위 컴포넌트(list/item/cell) → 상태·변형(modifier/state) → 반응형(media query) 순서. 같은 컴포넌트 내 속성도 박스모델 기준(position→display→width/height→margin→padding→border→background→font→기타) 순서 유지
- 반응형 필수 (PC → 태블릿 1024px → 모바일 768px)
- alert / confirm / prompt 사용 금지 → 모달팝업으로 통일
- 개발 완료 시 개발일지(DevLog) API로 자동 기록
- 수정 후 FTP로 자동 서버 업로드

## 메뉴얼 페이지 업데이트 규칙
아래 상황 발생 시 **반드시** 메뉴얼 페이지에 해당 내용 반영:

| 상황 | 반영 내용 |
|------|---------|
| 기능 개발 완료 | 기능명, 사용법, 관련 화면 경로, 담당 파일 |
| DB 테이블 추가/변경 | 테이블명, 컬럼 목록, 용도, 관계(FK) |
| API 추가/변경 | 엔드포인트, todo 액션명, 파라미터, 반환값 |
| 서비스 클래스 추가/변경 | 클래스명, 주요 메서드, 위치 |
| 개발 규칙 변경 | 변경된 규칙 내용 및 적용 범위 |
| **디자인 컴포넌트 추가/변경** | 컴포넌트명, CSS 클래스명, 사용법, 예시 코드 |
| **디자인 토큰 추가/변경** | 토큰명, 값, 용도 (tokens.css 기준) |
| **페이지 레이아웃 변경** | 레이아웃 구조, 주요 클래스, 반응형 분기점 |
| **공통 스타일 추가** | 클래스명, 적용 범위, 사용 예시 |

- **Claude·ChatGPT 모두 적용** — 각자 작업 완료 시 메뉴얼 업데이트 의무
- 메뉴얼 업데이트 없이 작업 완료 처리 금지

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
