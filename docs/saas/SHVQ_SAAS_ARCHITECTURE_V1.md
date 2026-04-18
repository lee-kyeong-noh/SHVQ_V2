# SHVQ SaaS 전환 기획/설계서 v1.1

- 작성일: 2026-04-12
- 기준 시스템: SHVQ 메인 + 우리 ERP(기본 테넌트) + Teniq
- 대상 범위: 메일, NVR/ONVIF, SmartThings, Tuya, 실시간 알림 서버
- 필수 제약:
  - 66번 상용 DB 직접 접근 금지
  - 67번 개발 DB 기준 설계/이관/검증
  - V2는 별도 DB(`CSM_C004732_V2`)로 구성

---

## 1) 배경 및 문제정의

현재 SHVQ는 기능이 빠르게 확장되며 운영자산(ERP/IoT/메일/알림)이 누적되었으나, SaaS 관점의 테넌트 경계가 기능별로 일관되지 않아 다음 리스크가 존재한다.

1. 기능별 데이터 경계 불일치
2. 연동 계정(메일/IoT) 수명주기 관리 분산
3. 이벤트/알림 파이프라인 표준 미흡
4. 구버전 데이터 이관 시 재실행/검증 체계 부족
5. ERP Core 모놀리식(대형 PHP 파일)으로 변경 리스크 과다
6. 인증/보안 기술부채(비밀번호/토큰/자격증명 관리) 누적

본 설계의 목표는 기존 운영기능을 버리지 않고, 구조를 SaaS 표준으로 재정렬하여 확장성과 안정성을 확보하는 것이다.

---

## 2) 목표 아키텍처

### 2.1 플랫폼 구조

SHVQ SaaS는 4계층으로 분리한다.

1. Control Plane
  - 서비스/테넌트/사용자/역할/플랜/감사로그
2. ERP Core
  - 고객/현장/PJT/견적/재고/구매/자금 등 업무 도메인
3. Integration Hub
  - Mail, ONVIF/NVR, SmartThings, Tuya 연동
4. Realtime Notification
  - 이벤트 규칙 기반 알림 큐, WS/SSE/WebPush 발송

### 2.2 서비스-테넌트 모델

1. Service
  - `shvq`, `teniq`, `vocalq`, `golfq` 등 제품 라인
2. Tenant
  - 고객사/조직 단위 운영 경계
3. Workspace(선택)
  - 테넌트 내 부서/공간/조직 분할 단위

기본 원칙은 모든 도메인 테이블 조회/저장 시 `service_code + tenant_id`를 강제하는 것이다.

### 2.3 멀티테넌트 DB 격리 전략 (결정)

이번 V2는 공유 스키마 + 행 단위 격리 모델을 채택한다.

1. 채택안
  - 단일 V2 DB(`CSM_C004732_V2`) + `service_code`, `tenant_id` 강제
2. 비채택안
  - 테넌트별 별도 DB: 운영 복잡도/비용 증가로 1차 제외
  - 테넌트별 별도 스키마: 마이그레이션 복잡도 증가로 1차 제외
3. 보완책
  - 테넌트 경계 누락 방지를 위한 공통 `resolveTenantContext()`
  - 테넌트 키 없는 SQL 금지(코드리뷰/정적 점검)

### 2.4 기술 스택 확정

1. 백엔드
  - PHP 8.x + MSSQL(PDO sqlsrv) 유지
2. 프론트엔드
  - V2 1차는 Vanilla JS + ES Module + 순수 CSS 유지
  - React/Vue 전환은 2차 재평가 항목으로 분리
3. 실시간
  - PHP API + Node Worker(실시간 게이트웨이 전용) 하이브리드
4. 메시지 버스
  - Redis(기존 운영 자산) 유지 및 확장

### 2.5 V1/V2 스키마 변경 정책 (P1-NEW 반영)

1. 원칙
  - V1 DB(`CSM_C004732`)는 스키마 변경(ALTER/신규컬럼 추가) 대상이 아니다.
  - V2 DB(`CSM_C004732_V2`)에 신규 스키마를 생성해 이관/병행운영을 수행한다.
2. 이유
  - V1 안정성 보존 및 동료 작업 충돌 최소화
  - 롤백 시 V1를 원본 기준으로 즉시 복귀 가능
3. 예외
  - 운영상 반드시 필요한 긴급 패치 외에는 V1 DDL 금지

---

## 3) 정보구조(IA) 및 모듈 경계

### 3.1 SHVQ 메인 역할

1. 플랫폼 콘솔
  - 테넌트 생성/정지, 사용자/권한, 연동 계정 관리
2. 운영 관제
  - 동기화 상태, 에러 큐, 이벤트 처리율, 알림 장애
3. 감사/로그
  - API 감사로그, 연동 이벤트 로그, 관리작업 로그

### 3.2 우리 ERP 역할

1. 기본 테넌트(`tenant_code=shvision`)로 운영
2. 기존 ERP 메뉴/데이터는 테넌트 스코프 강제 후 유지
3. 신규 기능은 SaaS 코어 테이블 참조를 기본으로 개발

### 3.3 Teniq 역할

1. 동일 플랫폼 내 독립 테넌트군
2. IoT 중심 기능은 Integration Hub를 공통 재사용
3. 테넌트/계정/장비/공간 구조는 SHVQ와 동일 정책

### 3.4 ERP Core 리팩토링 범위 (P0 반영)

ERP Core는 "유지"가 아니라 단계적 모듈 분해를 수행한다.

1. 대상 모듈
  - FMS(고객/현장)
  - PMS(PJT/견적)
  - BMS(구매/매출/수금/비용/자금)
  - MAT(품목/재고)
2. 분해 원칙
  - 컨트롤러(API 엔드포인트) / 서비스(도메인 규칙) / 리포지토리(SQL) 분리
  - 1차는 대형 파일을 서비스 함수 단위로 분리하고, 라우트는 유지
3. 우선순위
  - 1순위: PJT/견적/MAT
  - 2순위: 고객/현장
  - 3순위: BMS 주변 모듈
4. 완료 조건
  - 핵심 모듈에서 비즈니스 규칙이 서비스 레이어로 분리되어 테스트 가능 상태

### 3.5 UI/UX 디자인 방향 (Liquid Glass)

1. 디자인 테마는 전 시스템 공통으로 `Liquid Glass`를 기본 채택한다.
2. 카드/모달/패널은 반투명 레이어 + 블러 + 소프트 보더를 공통 규칙으로 사용한다.
3. 상태 색상(정상/주의/오류/정보)은 접근성 대비를 충족하도록 최소 대비 기준을 적용한다.
4. 반응형 기준은 PC/태블릿(1024px)/모바일(768px) 3단계로 고정한다.
5. 프론트 구현 책임은 역할 분담 규칙에 따라 Claude가 담당하고, 백엔드 API는 해당 UI 패턴을 지원하도록 응답 일관성을 유지한다.

### 3.6 프론트엔드 설계 원칙 (P0 반영)

1. 화면 라우팅
  - `?system=...&r=...` 단일 표준
2. 렌더링
  - Vanilla JS 모듈 기반, 페이지별 초기화 함수 표준화
3. 스타일
  - 디자인 토큰(CSS Variables) + 공통 컴포넌트 클래스
4. 금지
  - PHP 파일 내 `<style>` 태그 금지
  - PHP/HTML `style=""` 인라인 스타일 금지
  - 페이지별 중복 스타일 난립 금지
5. 예외 허용
  - JS 런타임 계산값(`top/left/width/height/transform`)은 인라인 스타일 허용
6. 운영 규칙
  - 스타일 변경은 `css/*.css` 파일에서만 수행
  - 색상/간격/그림자는 CSS 토큰으로만 관리
7. 산출물
  - 화면 컴포넌트 카탈로그(버튼/카드/테이블/모달/폼)

### 3.7 프론트엔드 리팩토링 범위 (Claude 담당)

기존 프론트엔드는 인라인 스타일 14,239개, footer.php 내 JS 1,465줄, 뷰 파일 89,564줄로 구조적 한계에 도달했다. 백엔드 ERP Core 리팩토링(§3.4)과 병행하여 프론트엔드도 단계적으로 분리한다.

#### 3.7.1 현황 진단

1. CSS
  - 인라인 스타일 14,239개 (manual.php 756, survey 547, iot 357, site_view 324)
  - backdrop-filter 83회 중복, box-shadow 6종 혼재
  - CSS 클래스 2,663개 (명명 규칙 없음)
  - CSS 변수 32개 정의, 25개만 실사용 (7개 미사용)
2. JavaScript
  - footer.php에 32개 핵심 함수 1,465줄 직접 삽입 (openModal, showToast, shvConfirm, shvChat 등)
  - 163개 PHP 파일에 `<script>` 태그 인라인
  - onclick/addEventListener 2,887개 산재
  - ES Module 사용 0건, 빌드 시스템 없음
  - common.js 177줄 (shvInfiniteScroll만 제공)
3. 뷰 구조
  - 225개 뷰 파일, 최대 4,832줄 모놀리식
  - SPA 로딩: fetch → innerHTML 교체 (ES Module 비호환)
  - 템플릿 시스템 없음, PHP/HTML/JS/CSS 혼재

#### 3.7.2 리팩토링 원칙

1. 점진적 전환
  - 전면 재작성 금지, 페이지 단위로 순차 개선
  - 백엔드 Wave 순서와 동기화 (Wave 1 페이지부터)
2. 기존 동작 보존
  - loadPage() SPA 메커니즘 유지
  - 기존 `?system=...&r=...` 라우팅 변경 없음
3. 계층 분리
  - CSS: 토큰 → 유틸리티 → 컴포넌트 → 페이지 (4단계)
  - JS: 코어 모듈 → UI 컴포넌트 → 페이지 초기화 (3단계)
4. 금지 사항
  - 새 코드에서 인라인 스타일 생성 금지 (런타임 좌표 예외)
  - footer.php에 함수 추가 금지 (신규는 모듈 파일로)
  - 전역 변수/함수 추가 금지 (네임스페이스 사용)

#### 3.7.3 CSS 설계 (V2 신규 작성, V1 참고)

V1 CSS(23개 파일, 5,889줄)는 전면 재사용하지 않는다. V2 CSS를 처음부터 새로 설계하되, V1에서 검증된 요소만 선별 채용한다.

1. V1에서 가져올 것
  - Liquid Glass 핵심값: blur(20px) saturate(180%), 글래스 그라데이션 색조
  - 검증된 반응형 브레이크포인트: 1024px / 768px
  - 작동 확인된 모달/토스트 기본 구조
  - 색상 팔레트 기조: `--accent(#3b6cf7)`, `--danger(#ff4466)`, `--success(#00c878)`
2. V1에서 버릴 것
  - 인라인 스타일 14,239개 전부
  - backdrop-filter 83회 중복 코드
  - box-shadow 6종 혼재
  - 명명 규칙 없는 클래스 2,663개
  - 페이지별 중복 정의 (login.css 독립 색상 체계 등)
3. V2 CSS 구조 (신규)
  - `css/v2/tokens.css` — 디자인 토큰 (:root 변수 전체)
  - `css/v2/reset.css` — 브라우저 초기화
  - `css/v2/layout.css` — topbar, sidebar, content 레이아웃
  - `css/v2/glass.css` — Liquid Glass 유틸리티 (.glass, .glass-card, .glass-input)
  - `css/v2/components.css` — 버튼/카드/테이블/모달/폼/뱃지/툴바
  - `css/v2/utilities.css` — 간격/정렬/텍스트/색상 유틸리티
  - `css/v2/responsive.css` — 반응형 미디어쿼리
  - `css/v2/pages/{page}.css` — 페이지 고유 스타일 (최소화)
4. 토큰 체계 (Phase 0 확정)
  - 간격: 4px 배수 (4/8/12/16/20/24/32/40/48)
  - 그림자: `--shadow-sm`, `--shadow-md`, `--shadow-lg` (3단계)
  - 반경: `--radius-sm(6px)`, `--radius-md(12px)`, `--radius-lg(20px)`
  - 색상: accent/danger/success/warn + 투명도 5단계
  - 글래스: blur/saturate/border/bg 값 토큰화
5. 컴포넌트 목록 (Phase 0~1 작성)
  - 버튼: `.btn`, `.btn-primary`, `.btn-danger`, `.btn-ghost`, `.btn-sm/md/lg`
  - 카드: `.card`, `.card-header`, `.card-body`
  - 테이블: `.tbl`, `.tbl-header`, `.tbl-row`, `.tbl-cell`
  - 모달: `.modal`, `.modal-sm/md/lg`
  - 폼: `.form-group`, `.form-label`, `.form-input`, `.form-select`
  - 뱃지: `.badge`, `.badge-success/warn/danger/info`
  - 툴바: `.toolbar`, `.toolbar-left/right`

#### 3.7.4 JS 리팩토링 (3단계)

1. 코어 모듈 추출 (Phase 0~1)
  - `js/core/api.js` — fetch 래퍼, CSRF 헤더 자동 주입, 에러 처리 표준화
  - `js/core/csrf.js` — CSRF 토큰 관리 (발급/갱신/헤더 삽입)
  - `js/core/router.js` — loadPage() 리팩토링, 페이지 초기화 훅
  - `js/core/events.js` — 이벤트 위임 유틸리티 (onclick 2,887개 대체)
  - `js/core/dom.js` — DOM 조작 헬퍼 ($, $$, createElement 등)
2. UI 컴포넌트 모듈화 (Phase 1~2)
  - `js/ui/modal.js` — footer.php openModal/closeModal 추출
  - `js/ui/toast.js` — footer.php showToast 추출
  - `js/ui/confirm.js` — footer.php shvConfirm/shvPrompt 추출
  - `js/ui/table-sort.js` — shvTblSort 추출
  - `js/ui/search-dropdown.js` — 검색 드롭다운 공통화
  - `js/ui/infinite-scroll.js` — common.js shvInfiniteScroll 이관
  - `js/ui/chat.js` — footer.php shvChat + 12개 채팅 함수 추출
  - `js/ui/draggable.js` — footer.php shvDraggable 추출
3. 페이지별 JS 분리 (Phase 2~4)
  - 각 뷰 파일 내 `<script>` → `js/pages/{page_name}.js`
  - 표준 초기화 패턴: `SHV.pages.{pageName} = { init(), destroy() }`
  - loadPage()가 페이지 로드 후 자동으로 init() 호출

#### 3.7.5 footer.php 경량화 계획

1. 현재: 32개 함수 1,465줄 인라인
2. 목표: 모듈 로더 + 이벤트 리스너만 잔류 (목표 100줄 이하)
3. 이관 순서:
  - 1차: modal/toast/confirm → js/ui/ (의존성 없음)
  - 2차: chat 관련 12개 함수 → js/ui/chat.js (가장 큰 덩어리)
  - 3차: draggable/address/pdf/push → js/ui/ (독립적)
  - 4차: 전역 이벤트 리스너 정리 → js/core/events.js

#### 3.7.6 실행 일정 (백엔드 Phase와 동기)

1. Phase 0 (2주) — 토대
  - CSS 토큰 문서 확정 + 유틸리티 클래스 CSS 작성
  - JS 코어 모듈(api/csrf/dom) 작성
  - 컴포넌트 카탈로그 HTML 페이지 제작
2. Phase 1 (4주) — 인증 + 컴포넌트
  - 로그인 페이지 V2 (AuthService API 연동)
  - footer.php → JS 모듈 1차 추출 (modal/toast/confirm)
  - 버튼/카드/뱃지/폼 컴포넌트 CSS 확정
3. Phase 2 (4주) — Wave 1 페이지 정리
  - site_view_new 인라인 324개 제거
  - material_list, member_branch_view CSS 정리
  - footer.php → JS 모듈 2차 추출 (chat)
  - 테이블/툴바 컴포넌트 표준화
4. Phase 3 (3주) — Wave 2 페이지 정리
  - IoT/CCTV 페이지 CSS 정리 (shvq_iot 357개)
  - 실시간 UI (알림 센터, 채팅 리뉴얼)
  - footer.php → JS 모듈 3차 추출 (나머지)
5. Phase 4 (3주) — 잔여 정리
  - manual(756개), survey(547개) 인라인 제거
  - footer.php 100줄 이하 달성
  - 미사용 CSS/JS 제거, 성능 최적화

#### 3.7.7 V2 프론트엔드 파일 명세 (Phase 0 구현 대상)

아래 파일은 placeholder로 생성 완료. Claude가 실제 구현을 채운다.

**CSS — `css/v2/` (7개)**

1. `tokens.css` — 디자인 토큰
  - `:root` 변수 전체 정의
  - 색상: `--accent(#3b6cf7)`, `--danger(#ff4466)`, `--success(#00c878)`, `--warn(#f5a623)` + 투명도 5단계 (`--accent-10` ~ `--accent-90`)
  - 간격: `--sp-1(4px)` ~ `--sp-12(48px)`, 4px 배수
  - 그림자: `--shadow-sm`, `--shadow-md`, `--shadow-lg`
  - 반경: `--radius-sm(6px)`, `--radius-md(12px)`, `--radius-lg(20px)`
  - 글래스: `--glass-blur(20px)`, `--glass-saturate(180%)`, `--glass-bg`, `--glass-border`
  - 타이포: `--font-base`, `--font-mono`, `--text-xs` ~ `--text-2xl`, `--leading-tight/normal/relaxed`
  - 레이아웃: `--topbar-h(60px)`, `--sidebar-w(200px)`, `--content-max`
  - 트랜지션: `--ease-default`, `--duration-fast(150ms)`, `--duration-normal(250ms)`
  - 다크모드 대비: `prefers-color-scheme: dark` 변수 오버라이드 (2차)
2. `reset.css` — 브라우저 초기화
  - box-sizing: border-box 전역
  - margin/padding 리셋
  - 기본 폰트/라인하이트 설정
  - a/button/input 기본 스타일 리셋
  - img/svg 반응형 기본값
3. `layout.css` — 레이아웃
  - `#topbar` — 고정 상단바 (60px, glass 효과)
  - `#sidebar` — 고정 사이드바 (200px, 접힘/펼침)
  - `#content` — 메인 콘텐츠 영역 (flex: 1, overflow scroll)
  - `.page-header`, `.page-body` — 페이지 내부 구조
  - sidebar 토글 애니메이션 (transform + transition)
4. `glass.css` — Liquid Glass 유틸리티
  - `.glass` — 기본 글래스 (blur + saturate + 반투명 bg + 소프트 보더)
  - `.glass-card` — 카드용 (더 강한 bg + 그림자)
  - `.glass-input` — 입력필드용 (포커스 시 보더 강조)
  - `.glass-panel` — 패널/사이드바용
  - `.glass-modal` — 모달 배경용
  - `.glass-hover` — 호버 시 글래스 강화
5. `components.css` — 공통 컴포넌트
  - 버튼: `.btn`, `.btn-primary`, `.btn-danger`, `.btn-ghost`, `.btn-outline`, `.btn-sm/md/lg`, `.btn-icon`
  - 카드: `.card`, `.card-header`, `.card-body`, `.card-footer`
  - 테이블: `.tbl`, `.tbl-header`, `.tbl-row`, `.tbl-cell`, `.tbl-sticky-header`, `.tbl-striped`
  - 모달: `.modal-overlay`, `.modal-box`, `.modal-header`, `.modal-body`, `.modal-footer`, `.modal-sm/md/lg`
  - 폼: `.form-group`, `.form-label`, `.form-input`, `.form-select`, `.form-textarea`, `.form-error`, `.form-hint`
  - 뱃지: `.badge`, `.badge-success/warn/danger/info/ghost`
  - 툴바: `.toolbar`, `.toolbar-left/right`, `.toolbar-divider`
  - 토스트: `.toast`, `.toast-success/warn/danger/info`
  - 탭: `.tabs`, `.tab-item`, `.tab-active`
  - 드롭다운: `.dropdown`, `.dropdown-menu`, `.dropdown-item`
  - 페이지네이션: `.pagination`, `.page-item`, `.page-active`
  - 스피너: `.spinner`, `.spinner-sm/md/lg`
  - 빈 상태: `.empty-state`, `.empty-icon`, `.empty-message`
6. `utilities.css` — 유틸리티
  - 간격: `.p-1` ~ `.p-12`, `.m-1` ~ `.m-12`, `.px-*`, `.py-*`, `.mx-*`, `.my-*`, `.gap-*`
  - 플렉스: `.flex`, `.flex-row`, `.flex-col`, `.items-center`, `.justify-between`, `.flex-wrap`, `.flex-1`
  - 텍스트: `.text-1`, `.text-2`, `.text-3`, `.text-accent`, `.text-danger`, `.text-success`
  - 정렬: `.text-left/center/right`
  - 폰트: `.font-bold`, `.font-medium`, `.text-xs/sm/base/lg/xl/2xl`
  - 디스플레이: `.hidden`, `.block`, `.inline-block`, `.sr-only`
  - 크기: `.w-full`, `.h-full`, `.min-h-screen`
  - 보더: `.border`, `.border-top`, `.rounded`, `.rounded-lg`
  - 그림자: `.shadow-sm`, `.shadow-md`, `.shadow-lg`, `.shadow-none`
  - 커서: `.cursor-pointer`, `.cursor-not-allowed`
  - 오버플로: `.overflow-hidden`, `.overflow-auto`, `.truncate`
7. `responsive.css` — 반응형
  - PC (기본): 1025px+
  - 태블릿: `@media (max-width: 1024px)` — sidebar 숨김, topbar 햄버거
  - 모바일: `@media (max-width: 768px)` — 1열 레이아웃, 모달 풀스크린, 테이블 카드 변환
  - 유틸리티 반응형 접두사: `.md:hidden`, `.sm:flex-col` 등

**JS — `js/core/` (5개 코어)**

1. `api.js` — API 래퍼
  - `SHV.api.get(url, params)` — GET 요청
  - `SHV.api.post(url, data)` — POST 요청 + CSRF 헤더 자동 주입
  - `SHV.api.upload(url, formData)` — 파일 업로드
  - 공통 에러 처리: 401 → 로그인 리다이렉트, 403 → 권한 토스트, 429 → 레이트리밋 안내, 500 → 서버 에러 토스트
  - 응답 규격: `{ok, data, error, message}` 파싱
  - 로딩 상태 콜백 (선택)
2. `csrf.js` — CSRF 토큰 관리
  - `SHV.csrf.get()` — 현재 토큰 반환
  - `SHV.csrf.set(token)` — 토큰 갱신 (로그인 응답에서 호출)
  - `SHV.csrf.header()` — `{'X-CSRF-Token': token}` 반환
  - 페이지 로드 시 `/dist_process/saas/Auth.php?todo=csrf`로 초기 토큰 발급
  - 로그인 성공 응답의 `csrf_token` 필드로 자동 갱신
3. `router.js` — SPA 라우팅
  - `SHV.router.navigate(system, route, params)` — 페이지 이동
  - `SHV.router.current()` — 현재 system/route 반환
  - `SHV.router.onLoad(callback)` — 페이지 로드 완료 훅
  - 기존 `loadPage()` / `hashRoute()` 호환 유지
  - 페이지 전환 시 `SHV.pages.{pageName}.destroy()` → 새 페이지 `init()` 자동 호출
4. `events.js` — 이벤트 위임
  - `SHV.events.on(selector, event, handler)` — 이벤트 위임 등록
  - `SHV.events.off(selector, event)` — 해제
  - `SHV.events.once(selector, event, handler)` — 1회 실행
  - `data-action` 속성 기반 자동 바인딩: `<button data-action="delete" data-id="123">`
  - onclick 인라인 대체 패턴
5. `dom.js` — DOM 헬퍼
  - `SHV.$(selector)` — querySelector 단축
  - `SHV.$$(selector)` — querySelectorAll 배열 반환
  - `SHV.dom.create(tag, attrs, children)` — 엘리먼트 생성
  - `SHV.dom.html(el, content)` — innerHTML 설정
  - `SHV.dom.show/hide/toggle(el)` — 표시 제어
  - `SHV.dom.addClass/removeClass/toggleClass(el, cls)` — 클래스 제어

**JS — `js/ui/` (8개 UI 컴포넌트, Phase 1~2)**

1. `modal.js` — `SHV.modal.open(url, title, size)`, `.close()`, `.confirm(msg)`
2. `toast.js` — `SHV.toast.show(msg, type, duration)`, `.success()`, `.error()`, `.warn()`
3. `confirm.js` — `SHV.confirm(msg, onOk, onCancel)`, `SHV.prompt(msg, defaultVal)`
4. `table-sort.js` — `SHV.tableSort(tableEl)`, th 클릭 시 오름/내림차순
5. `search-dropdown.js` — `SHV.searchDropdown(el, options)`, 입력필터+방향키+Enter+외부클릭
6. `infinite-scroll.js` — `SHV.infiniteScroll(container, fetchFn)`, 무한스크롤 API
7. `chat.js` — `SHV.chat.open(containerId, toTable, toIdx)`, 채팅 전체
8. `draggable.js` — `SHV.draggable(el, handleEl)`, 모달 드래그/리사이즈

**JS — `js/pages/` (Phase 2~4, 페이지별)**
- 각 뷰 파일에서 `<script>` 추출 → `js/pages/{page_name}.js`
- 표준 패턴: `SHV.pages.{pageName} = { init(), destroy() }`

#### 3.7.8 우선순위 판단 기준

1. 1순위: 사용자 접점 빈도 (매일 쓰는 페이지 → 가끔 쓰는 페이지)
2. 2순위: 백엔드 Wave와 동기 (Wave 1 API 변경 시 프론트도 같이)
3. 3순위: 인라인 스타일 수 (많은 것부터 → 적은 것)
4. 4순위: 코드 재사용성 (공통 컴포넌트 → 페이지 고유)

#### 3.7.8 완료 조건

1. 인라인 스타일 14,239개 → 500개 이하 (런타임 좌표만 잔류)
2. footer.php 1,465줄 → 100줄 이하
3. PHP 내 `<script>` 163개 파일 → 0개 (전부 외부 JS 파일로)
4. 컴포넌트 카탈로그 페이지에서 모든 UI 요소 확인 가능
5. 신규 페이지 작성 시 컴포넌트 클래스만으로 UI 구성 가능

### 3.8 개발 단계별 메뉴 게이팅 정책

1. 기본 정책
  - 개발 중인 Wave 외 메뉴는 기본적으로 비활성(숨김 또는 읽기전용) 처리한다.
2. 오픈 대상
  - 현재 개발 Wave 메뉴만 `관리자/개발자 권한`에 한해 오픈한다.
3. 해제 조건
  - 기능 테스트 + 이관 검증 + 회귀 테스트 통과 시 순차 해제한다.
4. 구현 방식
  - 메뉴 렌더 시 `feature_flag + canDo()` 이중 조건으로 노출 제어
  - 비활성 메뉴는 `준비중` 상태 라벨 표기
5. 보호 모듈
  - PJT/견적/MAT는 별도 보호군으로 지정하여 마지막에 개방한다.

### 3.8 개발 거버넌스 정책 (개발규칙/개발일지/매뉴얼)

1. 규칙 우선순위
  - 서버 매뉴얼 페이지(`manual`)의 AI 개발규칙 탭을 최우선 기준으로 한다.
  - 로컬 `AGENTS.md`와 충돌 시 매뉴얼 규칙을 우선 적용한다.
2. 스타일 규율
  - 화면 스타일은 `css/*.css` 파일에서만 관리한다.
  - PHP 파일 내 `<style>`/`style=""` 사용 금지를 기본 원칙으로 유지한다.
3. 개발일지(DevLog) 의무
  - 완료된 작업은 `dist_process/DevLog.php`로 기록한다.
  - 필수 항목: `system_type`, `category`, `title`, `content`, `status`, `dev_time`, `file_count`.
4. 매뉴얼 페이지 운영
  - 매뉴얼은 게시판형 구조(공지/개발규칙/릴리즈노트/운영가이드)로 운영한다.
  - 설계 변경 시 매뉴얼 변경 이력을 먼저 등록한 뒤 개발에 착수한다.
5. 완료 게이트
  - 작업 완료 조건에 `개발일지 등록 + 매뉴얼 업데이트`를 포함한다.
6. 서버 업로드 전 비교 의무
  - 모든 파일 업로드 전 서버 파일과 `사이즈/타임스탬프`를 비교한다.
  - 서버 파일이 더 최신이면 서버본을 먼저 내려받아 수정 후 업로드하거나, 서버 변경분 유지 기준으로 머지한다.
  - 동료 작업 덮어쓰기를 방지하기 위해 로컬본 단독 강제 업로드를 금지한다.
7. `check_qty_limit` 변경 통제
  - 대상: `dist_process/Project.php`의 `check_qty_limit` API
  - 로직: 총수량/품목갯수 모드 분기 + `Tb_EstimateItem` + `Tb_PjtPlanEstItem(미확정 단계)` 합산 검증
  - 해당 API 수정은 반드시 사용자 재확인 후 진행한다.

---

## 4) 데이터베이스 설계 (67번 기준)

### 4.1 코어 테이블(신규)

1. `Tb_SvcService`
2. `Tb_SvcTenant`
3. `Tb_SvcTenantUser`
4. `Tb_SvcRole`
5. `Tb_SvcRolePermission`
6. `Tb_SvcAuditLog`

### 4.2 연동 공통 테이블(신규)

1. `Tb_IntProviderAccount`
2. `Tb_IntCredential`
3. `Tb_IntDevice`
4. `Tb_IntDeviceMap`
5. `Tb_IntSyncCheckpoint`
6. `Tb_IntErrorQueue`

### 4.3 이벤트/알림 테이블(신규)

1. `Tb_EventRaw`
2. `Tb_EventStream`
3. `Tb_NotifyRule`
4. `Tb_NotifyQueue`
5. `Tb_NotifyDeliveryLog`

### 4.4 기존 테이블 정렬(확장/매핑)

1. IoT
  - 기존 `Tb_IotWebhookApp`, `Tb_IotEventLog`, `Tb_IotDevice*` 유지
  - `service_code`, `tenant_id`, `provider_account_idx` 정합성 보강
2. Mail
  - `Tb_Mail_Accounts`, `Tb_Mail_MessageCache`에 `tenant_id` 스코프 확장
3. NVR/ONVIF
  - `Tb_NvrDevice`, `Tb_OnvifCameras`에 테넌트 경계 컬럼 확장

### 4.5 테넌트 스코프 적용 순서 (P1 반영)

1. Step 1
  - Control/Integration 신규 테이블은 생성 시점부터 테넌트 컬럼 강제
2. Step 2
  - IoT/Mail/NVR 기존 테이블에 `service_code`, `tenant_id` 추가
3. Step 3
  - PJT/견적/MAT 핵심 테이블에 `tenant_id` 적용
4. Step 4
  - 나머지 ERP 테이블 확장
5. Step 5
  - 모든 API 쿼리에 테넌트 조건 누락 검증

---

## 5) 연동 도메인 설계

### 5.1 Mail Adapter

1. 계정 등록/검증/토큰 갱신
2. 폴더별 증분 동기화(IMAP UID 기준)
3. 신규 메일 이벤트를 `Tb_EventStream(type=mail.new)`로 발행

### 5.2 ONVIF/NVR Adapter

1. 장비 등록/연결테스트/상태수집
2. 스냅샷/채널/녹화 메타 표준화
3. 장애 이벤트(`device.offline`, `stream.error`) 발행
4. 제약 반영
  - VIGI 계열 NVR의 RTSP Replay `PLAY 454` 이슈를 알려진 제약으로 문서화
  - 지원 장비/미지원 장비 매트릭스 운영

### 5.3 SmartThings Adapter (하이브리드 확정)

1. 브라우저 직접 호출
  - 상태조회/즉시제어는 브라우저 -> SmartThings 직호출 경로 우선
2. 서버사이드 처리
  - lifecycle webhook 수신, 장비 동기화, 스케줄 실행, 이벤트 저장
3. 오류복구
  - 401/403/429 재시도 + 백오프 + 계정 fallback

### 5.4 Tuya Adapter

1. 계정 연결/디바이스 동기화
2. 상태/명령 매핑표 관리(제품군별 capability)
3. SmartThings와 동일 이벤트 표준으로 저장

---

## 6) 실시간 알림 서버 설계

### 6.1 파이프라인

1. 이벤트 수집: Adapter -> `Tb_EventRaw`
2. 정규화: Event Processor -> `Tb_EventStream`
3. 규칙 평가: Rule Engine -> `Tb_NotifyQueue`
4. 발송: Dispatcher -> WS/SSE/WebPush
5. 추적: `Tb_NotifyDeliveryLog`

### 6.2 기술 구성 명확화 (P2 반영)

1. PHP
  - 업무 API, 권한, DB 처리
2. Node Worker
  - WS/SSE 게이트웨이 전용(전체 백엔드 전환 아님)
3. Redis
  - 실시간 pub/sub, 사용자 채널 fan-out, 일시 캐시

### 6.3 대용량(10,000+ 사용자) 설계 기준

1. API/웹소켓 레이어
  - Node Worker 다중 인스턴스 + 로드밸런서
  - 사용자당 연결 상한 + 느린 소비자 정리
2. 이벤트 버스
  - Redis pub/sub + 재처리 큐 병행
3. Mail 동기화
  - 계정 파티셔닝 + 증분 동기화 + 배치 INSERT
4. SmartThings
  - 계정/테넌트 단위 작업큐 + 호출량 제한
5. DB
  - 이벤트/로그 파티션/아카이빙
6. 운영 SLO
  - 알림 p95 5초, 오류율 1% 미만

---

## 7) 인증/보안 설계 (P0 반영)

### 7.1 인증 전환

1. 비밀번호
  - 기존 평문/약한 해시를 `password_hash(BCRYPT)`로 전환
2. 로그인
  - 서버 세션 기반 유지 + 세션 고정/탈취 방어
3. 토큰
  - OAuth/연동 토큰은 암호화 저장, 만료/갱신 정책 표준화

### 7.2 CSRF/XSS/입력검증

1. 상태변경 API 전부 CSRF 필수
2. 출력 이스케이프 표준
3. SQL은 prepared statement 강제

### 7.3 비밀정보 관리

1. 자격증명 하드코딩 금지
2. `.env`/보안설정 파일로 분리
3. 로그 마스킹(토큰/비밀번호/민감 헤더)

---

## 8) API 설계 원칙

1. URL 규칙
  - `/dist_process/saas/{domain}.php?todo=...`
2. 인증/인가
  - 로그인 세션 + CSRF + `canDo()`
3. 테넌트 강제
  - `resolveTenantContext()` 공통 함수 필수
4. 에러 규격
  - `{success:false, code, message, detail?}`
5. 감사로그
  - 설정변경/연동변경/권한변경은 `Tb_SvcAuditLog` 기록

### 8.1 기존 API 73개 전환 전략 (P1 반영)

1. Wave 1
  - PJT/견적/MAT 관련 API 우선
2. Wave 2
  - FMS(고객/현장) API
3. Wave 3
  - BMS/GRP/부가 API
4. 각 Wave 공통
  - 테넌트 조건 추가 -> 회귀 테스트 -> 배포

---

## 9) 폴더/코드 구조 제안

```text
dist_process/
  saas/
    Platform.php
    Tenant.php
    IntegrationMail.php
    IntegrationIot.php
    Notification.php

dist_library/
  erp/
    ProjectService.php
    EstimateService.php
    MaterialService.php
    SiteService.php
    MemberService.php
    PurchaseService.php
    SalesService.php
    FundService.php
    ExpenseService.php
  saas/
    PlatformService.php
    TenantService.php
    IntegrationService.php
    NotificationService.php
    adapter/
      MailAdapter.php
      OnvifAdapter.php
      SmartThingsAdapterV2.php
      TuyaAdapter.php

cron/
  saas/
    mail_sync_cron.php
    iot_sync_cron.php
    notify_dispatcher.php

scripts/
  db67/
    saas/
      001_core_tables.sql
      002_integration_tables.sql
      003_notification_tables.sql
      101_migration_staging.sql
      201_migration_run.sql
      301_validation_queries.sql

docs/
  saas/
    SHVQ_SAAS_ARCHITECTURE_V1.md
```

---

## 10) 구버전 데이터 이관 설계

### 10.1 이관 원칙

1. 일괄 전환 금지, 단계 이관
2. 원본 보존(`STG_*`)
3. 매핑 분리(`MAP_*`)
4. 재실행 가능 SQL(멱등)
5. 검증 실패 시 자동 롤백

### 10.2 단계

1. Stage 0: 기준선 확정
2. Stage 1: Staging 적재
3. Stage 2: Core 이관
4. Stage 3: ERP 도메인 이관
5. Stage 4: Integration 이관
6. Stage 5: 병행운영
7. Stage 6: 전환

### 10.3 핵심 도메인 매핑 명세 (P1 반영)

1. 본사/사업장
  - `Tb_HeadOffice.idx -> MAP_HeadOffice.old_idx/new_idx`
  - `Tb_Members.head_idx`는 `MAP_HeadOffice.new_idx`로 치환
2. 현장
  - `Tb_Site.member_idx`는 `MAP_Members.new_idx` 참조
3. PJT/견적
  - `Tb_PjtPlan`, `Tb_PjtPlanPhase`, `Tb_PjtPlanEstItem`, `Tb_SiteEstimate`, `Tb_EstimateItem` 순서 이관
4. 자동생성 규칙 보존
  - 본사코드(`HO+YYMMDD+순번`), 자재번호(`MAT-[탭]-[YYMM]-[순번]`)는 신규 생성 시점에도 동일 알고리즘 유지

### 10.4 무중단 이관 최적화(테이블 증가 허용)

1. 저장 계층 분리
  - `STG_*`, `MAP_*`, `MIG_*`, `APP_*`
2. 실행 단위 분리
  - 대형 테이블 배치 이관 + 인덱스 단계적 적용
3. 재실행 최적화
  - `MERGE/UPSERT`, `hash_diff`, 실패 배치 재처리
4. 읽기 부하 분산
  - 검증 쿼리 피크 회피

### 10.5 병행운영 쓰기 전략 (P1 반영)

1. 채택안: Shadow Write + Cutover Freeze
  - 병행운영 동안 주요 쓰기 API는 구DB + V2 DB 동시 기록
  - 충돌 시 구DB를 우선 기준으로 재동기화
2. Shadow Write 실패 처리(분산 트랜잭션 미사용)
  - MSDTC 기반 분산 트랜잭션은 1차 도입하지 않는다.
  - 구DB(V1) 커밋 성공 + V2 실패:
    - 요청은 성공 처리(구DB master 유지)
    - 실패 내역을 `MIG_Error`/재동기화 큐에 기록 후 비동기 재처리
  - 구DB(V1) 실패 + V2 성공:
    - 요청은 실패 처리
    - V2 레코드는 보상 작업(soft-delete 또는 정정 이벤트)으로 정합성 복구
  - 재처리 실패 누적 시 운영 알람 발송
3. 일관성 기준
  - 병행운영 기간의 기준 데이터는 항상 V1
  - V2는 지연 허용 eventual consistency 모델로 운영
4. 컷오버
  - 짧은 쓰기 동결 -> 최종 델타 반영 -> 라우팅 전환
5. 비채택안
  - 1차에서 CDC 실시간 복제는 도입하지 않음

### 10.6 검증 체크리스트

1. 건수/합계 검증
2. 참조무결성(orphan FK) 검증
3. 샘플 시나리오 검증
4. 성능(p95) 검증
5. 롤백 리허설 검증

---

## 11) 인프라/운영 제약 반영 (P2 반영)

1. 외부 HTTPS 제약
  - 나라장터/SmartThings 일부 경로에서 DNS/HTTPS 제약 존재
  - 우회 전략 유지 여부를 인프라 개선과 분리해 관리
2. 파일 저장소
  - 1차: 기존 FTP 호환 유지
  - 2차: Object Storage(S3/MinIO) 전환 검토
3. CAD
  - 독립 로그인/DB 연결 기술부채 해소를 위한 인증 통합 계획 별도 수립

---

## 12) 테스트/관측 전략

1. 테스트
  - 단위(Service), 통합(API), E2E(핵심 플로우) 3계층
  - E2E 최소 기준: 시스템별 10개 이상(총 90개+), ERP Core(PJT/견적/MAT)는 모듈별 15개 이상
2. 모니터링
  - API 오류율, 큐 적체, 동기화 실패율, 알림 지연 모니터링
3. 로그
  - request_id 기반 추적, 민감정보 마스킹

---

## 13) 전환 로드맵 (현실화)

1. Phase 0 (2주)
  - 설계 확정, 보안 기준선, API/테이블 인벤토리
2. Phase 1 (4주)
  - Control Plane + 인증/권한 + 테넌트 컨텍스트
3. Phase 2 (4주)
  - ERP Core 1차(PJT/견적/MAT) 분리 + 테넌트 적용
4. Phase 3 (3주)
  - Integration Hub + 실시간 파이프라인 안정화
5. Phase 4 (3주)
  - 이관 리허설 2회 + 병행운영 + 컷오버
6. 총 기간
  - 최소 16주(약 4개월)

---

## 14) 완료 기준 (Definition of Done)

1. 신규 API는 `service_code + tenant_id` 스코프를 강제한다.
2. 메일/ONVIF/NVR/SmartThings/Tuya 이벤트가 공통 이벤트 스트림으로 수집된다.
3. 알림 파이프라인이 WS/SSE/WebPush로 동작하고 실패 재시도가 가능하다.
4. 구버전 이관 리허설 2회 모두 검증 통과한다.
5. 운영 전환 후 14일간 P1 장애 0건을 유지한다.
6. 메일/SmartThings 모두 10,000+ 부하테스트를 통과한다.
7. 보안 점검(비밀번호/CSRF/자격증명 분리) 항목을 통과한다.

---

## 15) 즉시 실행 항목 (Next Action)

1. 프론트 기술 스택/컴포넌트 카탈로그를 별도 문서로 확정
2. 인증 전환 상세서 작성
  - 완료: `SHVQ_V2_AUTH_MIGRATION_DETAIL_V1.md`
3. API 73개 전환 매트릭스 작성(Wave 1~3)
  - 완료: `SHVQ_V2_API_TRANSITION_MATRIX_V1.md`
4. 핵심 도메인 매핑표(`MAP_*`) 상세 작성
5. Shadow Write 대상 API 목록 확정
  - 완료: `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md` (167개)
6. E2E 핵심 시나리오 확정(95 기준)
  - 완료: `SHVQ_V2_E2E_SCENARIOS_V1.md`
7. API-테이블 CRUD 매핑 확정
  - 완료: `SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md` (584개 액션)
