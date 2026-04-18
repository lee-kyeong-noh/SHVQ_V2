# SHVQ_V2 메뉴 IA 재설계안 v1.1

- 작성일: 2026-04-12
- 대상: SHVQ_V2 (SaaS 전환 기준)
- 목적: 기존 메뉴 복잡도 해소 + 구버전 이관 시 라우트 불일치 방지

---

## 1) 현재 메뉴 구조 진단 (AS-IS)

현재 구조를 `index.php` + `index_header_*.php` 기준으로 점검한 결과 핵심 이슈는 아래와 같다.

1. 시스템 단위 메뉴는 분리되어 있으나, 신/구버전 메뉴가 동일 섹션에 혼재되어 있다.
2. 동일 기능이 서로 다른 시스템에 중복 노출된다.
  - 예: `ONVIF`가 API/Facility 양쪽에 존재
3. `CAD`는 별도 시스템이지만 FMS 메뉴를 일부 공유해 사용자 인지부하가 높다.
4. `CTM`은 비어 있어 상단/하단 네비게이션 일관성이 깨진다.
5. 메일은 Groupware 하위이면서 홈 사이드바에서 독립 항목으로도 노출된다.
6. 라우팅은 `?system=...&r=...`로 정규화되어 있으나, 메뉴 분류 기준이 업무흐름과 완전히 일치하지 않는다.
7. PJT/견적/MAT의 중요 로직 메뉴가 여러 섹션에 분산되어 운영자가 찾기 어렵다.

---

## 2) 재설계 원칙 (TO-BE)

1. 업무흐름 우선
  - 사용자 기준 흐름: 고객 -> 현장 -> PJT -> 견적/수주 -> 구매/매출/수금 -> 자재/재고
2. 라우트 안정성 우선
  - 기존 핵심 `r` 값은 유지하고 메뉴만 재배치
3. 구버전 공존
  - 신버전/구버전 분리 섹션을 명확히 표기하고 단계적으로 제거
4. 시스템 코드 호환
  - `fms/pms/bms/mat/ctm/groupware/cad/facility/api` 유지
5. 권한 기반 노출
  - 메뉴 노출은 `canDo()` 및 역할 정책으로 제어

---

## 3) V2 메뉴 구조

### 3.1 L0 (상단 워크스페이스)

1. `운영(ERP)`
2. `협업(GRP)`
3. `시설/IoT`
4. `도구/API`
5. `CAD`
6. `관리`

### 3.2 L0 ↔ system 코드 매핑 (P1 반영)

L0는 UI 그룹이며 URL의 `system` 코드 체계를 대체하지 않는다.

1. 운영(ERP)
  - `fms`, `pms`, `bms`, `mat`
2. 협업(GRP)
  - `groupware`
3. 시설/IoT
  - `facility`
4. 도구/API
  - `api`
5. CAD
  - `cad`
6. 관리
  - `groupware` 고정 (1차)

규칙:

1. URL은 기존과 동일하게 `?system=기존코드&r=라우트`를 사용한다.
2. L0 클릭 시 기본 진입 라우트만 이동시키고 `system` 코드는 표준 코드로 유지한다.
3. 신규 `system=erp` 코드는 1차 도입하지 않는다.
4. 관리 메뉴 URL은 `?system=groupware&r=my_settings|settings|devlog|manual|trash`로 정규화한다.

### 3.3 L1/L2 (사이드바)

### 운영(ERP)

1. 고객/현장
  - 본사관리(`member_head`)
  - 사업장관리(`member_branch`)
  - 예정사업장(`member_planned`)
  - 현장조회(`site_new`)
2. PJT 실행
  - PJT 현황(`project_dashboard`)
  - 해야할 PJT(`pjt_todo.php`)
  - PJT 캘린더(`pjt_calendar_v2.php`)
  - 전체 프로젝트(`project_main`)
  - PJT 일정(`project_schedule`)
3. 견적/수주
  - 예산현황(`budgetlist`)
  - 견적/수주/실패(`quotationStatus.php`)
  - 산출내역서(`calcList`)
  - 수주현황/잔고(`order_status`, `order_balance`)
4. 매입/매출/수금/비용/자금
  - 업체조회(`company`)
  - 자재구매/도급/대리점(신버전 우선)
  - 매출현황/등록/세금계산서(`sales_status`, `sales_register`, `sales_tax`)
  - 수금현황/미수/미청구(`collect_status`, `collect_unpaid`, `collect_unclaimed`)
  - 경비(신버전 우선)
  - 자금(`accountList`, `account_balance`, `resolution`)
5. 자재/재고(MAT)
  - 품목관리(`material_list`)
  - 품목수령리스트(`material_takelist`)
  - 재고현황/입출고/이동/조정/이력(`stock_*`)
6. 운영보조
  - 기술지원, 안전관리, SRM, 휴지통

### 협업(GRP)

1. 홈
  - 대시보드(`emp.php`)
  - 채팅(`chat`)
2. 조직/HR
  - 조직도/주소록/근태/휴가
3. 전자결재/문서함
  - 결재함, 결재작성, 완결문서, 공문
4. 웹메일
  - 받은편지함/보낸편지함/임시보관함/메일쓰기
  - 계정설정/관리자설정

### 시설/IoT

1. CCTV
  - ONVIF(`onvif`)
  - CCTV Viewer(`cctv_viewer`)
2. IoT Hub
  - SHVQ IoT(`shvq_iot`)
  - Tuya IoT(`tuya_iot`)
  - TeniQ IoT(`teniq_iot`)  ※ API에서 시설로 이동
3. 출입
  - 도어락(`doorlock`)

### 도구/API

1. 외부데이터 도구
  - 승강기, 나라장터, 입찰결과
2. 유틸리티
  - 문서뷰어, PDF리더, QR스캐너, 단축URL
3. 모니터링
  - WS 접속자 현황(`ws_monitor`)
4. 플랫폼
  - TeniQ 포털(`teniq_portal`)

### CAD

1. SmartCAD 메인(`smartcad`)
2. CAD 기능 리본은 CAD 내부에서만 제공
3. ERP 업무메뉴는 CAD에서 직접 노출하지 않음

### 관리

1. 개인설정(`my_settings`)
2. 관리자설정(`settings`)
3. 개발일지(`devlog`)
4. 매뉴얼(`manual`)
5. 휴지통(`trash`)
6. 운영 규칙
  - 개발일지(`devlog`)는 작업 완료 보고의 단일 등록창으로 사용한다.
  - 매뉴얼(`manual`)은 게시판형 탭 구조(공지/개발규칙/릴리즈노트/운영가이드)로 운영한다.
  - 개발 진행 중 Wave 외 메뉴는 비활성 표시(`준비중`)를 기본 적용한다.

---

## 4) 구버전 이관 호환 설계 (핵심)

### 4.1 절대 변경 금지 라우트 (1차)

아래 라우트 키는 이관 기간 동안 변경하지 않는다.

1. PJT
  - `project_dashboard`, `pjt_todo`, `pjt_calendar_v2`, `project_main`, `project_schedule`, `project_settings`
2. 견적
  - `budgetlist`, `quotationStatus`, `calcList`, `add_estimate`, `site_view_new`
3. MAT
  - `material_list`, `material_takelist`, `material_settings`, `stock_status`, `stock_in`, `stock_out`, `stock_transfer`, `stock_adjust`, `stock_log`, `stock_settings`

### 4.2 메뉴 재배치 방식

1. 라우트는 그대로 유지
2. 메뉴 위치만 변경
3. 구버전 메뉴는 `레거시` 그룹으로 별도 격리
4. 레거시 메뉴 클릭 시 동일 라우트로 이동

### 4.3 라우트 별칭(Alias) 규칙

V2에서는 별칭 테이블을 두고 구링크를 허용한다.

1. 테이블 권장: `Tb_MenuRouteAlias`
  - `old_route`, `new_route`, `system_code`, `is_active`, `created_at`
2. 생성 위치
  - `CSM_C004732_V2`(V2 DB)에만 생성한다.
  - V1 DB에는 테이블을 만들지 않는다(DDL 금지 원칙 준수).
3. 원칙
  - `old_route` 요청 시 `new_route`로 내부 해석
  - URL 표시는 단계별 정책으로 유지/정규화 선택
4. V1 참조 방식
  - V1 라우팅 코드는 DB 직접 조인 대신 `route_alias_map` 스냅샷(파일/캐시)으로 참조한다.
  - 스냅샷 원본은 V2 `Tb_MenuRouteAlias`이며 배포/배치로 동기화한다.
5. 효과
  - 즐겨찾기/매뉴얼/외부링크 호환성 유지

---

## 5) PJT/견적/MAT 보호 규칙

1. PJT
  - 단계/호기/첨부/완료 흐름 메뉴를 한 섹션에 유지
  - `project_settings` 접근 경로를 항상 동일하게 노출
2. 견적
  - `check_qty_limit` 관련 화면 진입 경로를 분리하지 않는다.
  - 현장 상세(`site_view_new`)와 견적 탭 이동 동선을 유지한다.
3. MAT
  - 자재번호 정책 메뉴(`material_settings`)는 숨기지 않는다.
  - 품목/재고 메뉴를 분리하지 않고 동일 그룹으로 유지한다.

---

## 6) 적용 단계

1. Phase 1: 메뉴 레지스트리화
  - 현행 메뉴를 DB/설정으로 추출, UI 렌더만 교체
2. Phase 2: V2 메뉴 적용
  - 신규 IA 반영, 라우트는 현행 유지
3. Phase 3: 레거시 축소
  - 사용량 낮은 구버전 메뉴를 레거시 그룹으로 이동
4. Phase 4: 정리
  - 완전 전환 후 비사용 메뉴 비활성화

### 6.1 개발 진행 중 메뉴 잠금 정책

1. 기본값은 `잠금(비활성)`이며, 현재 개발 Wave 메뉴만 오픈한다.
2. 잠금 상태는 `숨김` 또는 `읽기전용`으로 운영한다.
3. 해제는 `테스트 통과 -> 이관 검증 통과 -> 운영 승인` 순으로 진행한다.
4. PJT/견적/MAT는 보호군으로 분류해 가장 보수적으로 해제한다.
5. 메뉴 노출은 `feature_flag + canDo()` 조합으로 제어한다.

---

## 7) 바로 실행할 작업

1. `index_header_*` 기준 메뉴 인벤토리 CSV 생성
2. `PJT/견적/MAT` 라우트 잠금 목록 확정
3. `Tb_MenuRouteAlias` 스키마 초안 작성
4. L0-L1 메뉴와 `system` 코드 자동 매핑 규칙 구현
