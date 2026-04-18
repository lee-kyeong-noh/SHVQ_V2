# SHVQ_V2 BL-02~BL-13 통합 실행팩

- 작성일: 2026-04-13
- 목적: `BL-02 ~ BL-13`를 한 번에 닫기 위한 실행 산출물 고정
- 기준 문서: `SHVQ_V2_API_TRANSITION_MATRIX_V1.md`, `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md`, `SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md`, `SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md`, `SHVQ_V2_E2E_SCENARIOS_V1.md`

## 1) 완료 요약

| BL | 결과 | 산출물 |
|---|---|---|
| BL-02 | 완료 | 본 문서 2장 |
| BL-03 | 완료 | 본 문서 3장 |
| BL-04 | 완료 | 본 문서 4장 |
| BL-05 | 완료 | 본 문서 5장 |
| BL-06 | 완료 | 본 문서 6장 |
| BL-07 | 완료 | 본 문서 7장 |
| BL-08 | 완료 | 본 문서 8장 |
| BL-09 | 완료 | 본 문서 9장 |
| BL-10 | 완료 | 본 문서 10장 |
| BL-11 | 완료 | 본 문서 11장 |
| BL-12 | 완료 | 본 문서 12장 |
| BL-13 | 완료 | 본 문서 13장 |

## 2) BL-02 — Wave 1 Shadow Write 체크리스트 (167)

### 2.1 파일별 체크리스트

| API 파일 | 쓰기 엔드포인트 수 | Tier | Shadow Write | 담당 | 상태 |
|---|---:|---:|---|---|---|
| Site.php | 40 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| Material.php | 25 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| Settings.php | 21 | 2 | 권장 | Backend-Platform | 체크리스트 확정 |
| Member.php | 17 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| HeadOffice.php | 11 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| Employee.php | 9 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| Expense.php | 7 | 2 | 권장 | Backend-BMS | 체크리스트 확정 |
| Purchase.php | 7 | 2 | 권장 | Backend-BMS | 체크리스트 확정 |
| Stock.php | 6 | 1 | 필수 | Backend-Core | 체크리스트 확정 |
| Trash.php | 5 | 2 | 권장 | Backend-Platform | 체크리스트 확정 |
| Sales.php | 3 | 2 | 권장 | Backend-BMS | 체크리스트 확정 |
| Fund.php | 1 | 2 | 권장 | Backend-BMS | 체크리스트 확정 |
| CalendarV2.php | 1 | 3 | 후순위 | Backend-PMS | 체크리스트 확정 |
| Calendar.php | 1 | 3 | 후순위 | Backend-PMS | 체크리스트 확정 |
| 합계 | 167 | - | - | - | 완료 |

### 2.2 공통 적용 기준

1. Tier1: `shadow_write_required=Y`, 실패 시 큐 적재 필수
2. Tier2: `shadow_write_required=Y`, 배포 게이트 완화(운영창구 승인)
3. Tier3: `shadow_write_required=Optional`, 컷오버 직전 재평가
4. 공통 로그 필드: `request_id`, `api`, `todo`, `tenant_id`, `service_code`, `v1_result`, `v2_result`, `queued_idx`

## 3) BL-03 — API별 E2E 케이스 ID 맵핑

### 3.1 시나리오 번들(최종 ID셋 연동)

- FMS: `FMS-001~FMS-012`
- PMS: `PMS-001~PMS-009`
- BMS: `BMS-001~BMS-016`
- MAT: `MAT-001~MAT-012`
- GRP: `GRP-001~GRP-016`
- IOT: `IOT-001~IOT-007`
- CCTV: `CCTV-001~CCTV-007`
- API: `API-001~API-004`
- ADM: `ADM-001~ADM-006`
- XCT: `XCT-001~XCT-005`
- MIG: `MIG-001`

### 3.2 API 파일 매핑표

| API | wave | E2E ID 매핑 |
|---|---|---|
| Auth.php | W0 | GRP-001, GRP-002, XCT-001 |
| AuthAudit.php | W0 | ADM-001, XCT-005 |
| Dashboard.php | W3 | ADM-002 |
| Tenant.php | W0 | ADM-003, MIG-001 |
| Platform.php | W2 | ADM-004, XCT-003, XCT-004 |
| ShadowAudit.php | W2 | ADM-005, XCT-005 |
| Notification.php | W2 | GRP-015, ADM-006 |
| IntegrationMail.php | W2 | GRP-011, GRP-012, GRP-013 |
| Mail.php | W2 | GRP-010, GRP-011, GRP-012, GRP-014 |
| IntegrationIot.php | W2 | IOT-001~IOT-007 |
| Site.php | W1 | FMS-001~FMS-012, PMS-004~PMS-006 |
| HeadOffice.php | W1 | FMS-001~FMS-004 |
| Member.php | W1 | FMS-003~FMS-008, GRP-003 |
| Employee.php | W1 | GRP-001~GRP-006, FMS-009 |
| Approval.php | W2 | GRP-007~GRP-009, PMS-007 |
| Chat.php | W2 | GRP-004, GRP-016 |
| Material.php | W1 | MAT-001~MAT-008 |
| MaterialSettings.php | W1 | MAT-009~MAT-012 |
| Stock.php | W1 | MAT-004~MAT-012, PMS-008 |

검증 규칙:
- `누락 API 0건`: 현재 `dist_process/saas/*.php` 19개 모두 매핑 완료
- 각 API는 최소 1개 이상 시나리오 ID와 연결

## 4) BL-04 — mail/api 포함 인증/권한 공통 미들웨어 점검

### 4.1 점검 결과(요약)

| 점검 항목 | 결과 | 비고 |
|---|---|---|
| 인증 컨텍스트 확인(`AUTH_REQUIRED`) | PASS | Auth.php 제외 전 API 적용 |
| write CSRF 검증 | PASS | write 분기 API 모두 CSRF 검증 존재 |
| write POST 강제 | WARN | Groupware/Platform/Tenant 계열은 CSRF는 있으나 POST 강제가 일관되지 않음 |
| 권한레벨 가드 | PASS | role 기준 분기 존재(2/4/5 및 config min_role_level) |
| tenant/service scope | PASS | SaaS 계열 API에서 context 강제 또는 resolveScope 사용 |
| 메일 첨부 정책 조회 API | PASS | `mail_send_policy`로 정책 노출 완료 |

### 4.2 조치안(표준화)

1. CSRF 에러코드 표준: `CSRF_TOKEN_INVALID`로 통일
2. write HTTP 메서드 표준: `POST only` 강제
3. 권한 응답 포맷 표준: `required/current` 필드 통일

## 5) BL-05 — 584개 액션 파라미터 정규화 규약

### 5.1 정규화 스키마

| 키 | 타입 | 규칙 |
|---|---|---|
| `todo` | string | 소문자, snake_case |
| `service_code` | string | 공백 불가, 미지정 시 context 강제 |
| `tenant_id` | int | 0 이하 금지, 미지정 시 context 강제 |
| `idx`/`id` | int | 양수 |
| `idx_list`/`id_list` | int[] | 중복 제거, 최대 길이 제한 |
| `page` | int | min 1 |
| `limit` | int | API별 max 제한 |
| `search`/`q` | string | trim, 길이 제한 |
| `status` | enum | 허용값 화이트리스트 |
| `from_at`/`to_at` | date/datetime | `from<=to` |
| `csrf_token` | string | write 필수 |
| `sort`/`order` | enum | 화이트리스트 |

### 5.2 액션 패밀리별 적용

1. CRUD-write: `POST+CSRF+role`
2. CRUD-read: `scope+page/limit`
3. Batch-write: `idx_list` 정규화 필수
4. State-change: `status` enum 필수
5. Search: `q/search` length/escaping 적용

적용 기준:
- 기존 CRUD 매트릭스(`584 actions`) 전수에 위 규약을 적용하는 표준으로 고정

## 6) BL-06 — API-테이블 트랜잭션 경계 정의

| API 그룹 | 트랜잭션 경계 | 롤백 정책 |
|---|---|---|
| Site/HeadOffice/Member | API 단일 요청 단위 트랜잭션 | 예외 시 전량 롤백 |
| Mail(account/draft/send) | 계정/초안 저장은 DB 트랜잭션, 외부 SMTP 실패 시 DB 보상 분기 | 부분 실패 시 상태/로그 보존 |
| Material/Stock | write 단위 트랜잭션(서비스 계층) | 재고/이력 원자성 보장 |
| Employee/Approval/Chat | 도메인 서비스 write 단위 트랜잭션 | 승인/메시지 상태 일관성 |
| Platform(Queue) | 큐 아이템 단위 트랜잭션 | 재시도 카운트/상태 원자 갱신 |
| Tenant | 테넌트 생성/할당 단위 트랜잭션 | 매핑 실패 시 rollback |

표준 규칙:
1. `begin -> domain write -> audit/log -> commit`
2. 외부 I/O(SMTP/adapter)는 DB 트랜잭션 외부에서 보상 로직 분리
3. 롤백 메시지는 비즈니스 코드(`CONFLICT`, `BUSINESS_RULE_VIOLATION`)로 통일

## 7) BL-07 — API-테이블 CRUD 2차 매트릭스

| API | 대표 todo | table | CRUD | wave | risk |
|---|---|---|---|---|---|
| Site.php | `insert_est` | `Tb_SiteEstimate` | C | W1 | H |
| Site.php | `update_est` | `Tb_SiteEstimate`,`Tb_EstimateItem` | U | W1 | H |
| Site.php | `delete_estimate` | `Tb_SiteEstimate`,`Tb_EstimateItem` | D | W1 | H |
| Site.php | `update` | `Tb_Site` | U | W1 | H |
| HeadOffice.php | `insert/update/delete` | `Tb_HeadOffice` | C/U/D | W1 | M/H |
| Member.php | `update_branch_settings` | `Tb_Members` | U | W1 | H |
| Member.php | `member_delete` | `Tb_Members` | D | W1 | H |
| Employee.php | `insert_employee/update_employee` | `Tb_Employee` | C/U | W1 | M |
| Material.php | `material_create/update/delete` | `Tb_Item` | C/U/D | W1 | H |
| MaterialSettings.php | `settings_save` | `Tb_ItemCategory`(설정성) | U | W1 | M |
| Stock.php | `stock_in/out/transfer/adjust` | `Tb_Stock`,`Tb_StockLog` | C/U | W1 | H |
| Mail.php | `mail_send` | `Tb_Mail_MessageCache`(전송결과/캐시) | C/U | W2 | H |
| Mail.php | `mail_draft_save/delete` | `Tb_Mail_Draft` | C/U/D | W2 | M |
| Mail.php | `account_save/delete` | `Tb_Mail_Accounts` | C/U/D | W2 | H |
| Approval.php | `approval_submit/approve/reject` | `Tb_Approval*` | U | W2 | M |
| Chat.php | `message_send/delete` | `Tb_Chat*` | C/U | W2 | M |
| Tenant.php | `create_tenant` | `Tb_SvcTenant` | C | W0 | H |
| Tenant.php | `assign_tenant_user` | `Tb_SvcTenantUser` | C/U | W0 | H |
| Platform.php | `shadow_queue_requeue/resolve` | `Tb_IntErrorQueue` | U | W2 | M |

## 8) BL-08 — Unique 95 최종 시나리오 ID 확정

### 8.1 Raw 120 -> Unique 95 축약표

| 시스템 | Raw | Unique | 제거 |
|---|---:|---:|---:|
| FMS | 13 | 12 | 1 |
| PMS | 10 | 9 | 1 |
| BMS | 20 | 16 | 4 |
| MAT | 14 | 12 | 2 |
| GRP | 20 | 16 | 4 |
| IoT | 8 | 7 | 1 |
| CCTV | 8 | 7 | 1 |
| API 도구 | 5 | 4 | 1 |
| 관리 | 8 | 6 | 2 |
| Cross-cutting | 10 | 5 | 5 |
| Migration | 4 | 1 | 3 |
| 합계 | 120 | 95 | 25 |

### 8.2 최종 ID 셋

- `FMS-001~FMS-012`
- `PMS-001~PMS-009`
- `BMS-001~BMS-016`
- `MAT-001~MAT-012`
- `GRP-001~GRP-016`
- `IOT-001~IOT-007`
- `CCTV-001~CCTV-007`
- `API-001~API-004`
- `ADM-001~ADM-006`
- `XCT-001~XCT-005`
- `MIG-001`

## 9) BL-09 — 테이블별 이관 검증 SQL 템플릿

```sql
-- T1: Row Count 비교
SELECT 'V1' AS src, COUNT(1) AS cnt FROM [CSM_C004732].[dbo].[{TABLE_NAME}]
UNION ALL
SELECT 'V2' AS src, COUNT(1) AS cnt FROM [CSM_C004732_V2].[dbo].[{TABLE_NAME}];
```

```sql
-- T2: PK 누락 비교
SELECT v1.{PK}
FROM [CSM_C004732].[dbo].[{TABLE_NAME}] v1
LEFT JOIN [CSM_C004732_V2].[dbo].[{TABLE_NAME}] v2 ON v2.{PK}=v1.{PK}
WHERE v2.{PK} IS NULL;
```

```sql
-- T3: 핵심 컬럼 체크섬 비교
SELECT CHECKSUM_AGG(BINARY_CHECKSUM({PK}, {COL_A}, {COL_B}, {COL_C})) AS sig
FROM [CSM_C004732].[dbo].[{TABLE_NAME}];
```

```sql
-- T4: tenant 범위 무결성 검사
SELECT COUNT(1) AS invalid_rows
FROM [CSM_C004732_V2].[dbo].[{TABLE_NAME}]
WHERE service_code IS NULL OR LTRIM(RTRIM(service_code))=''
   OR tenant_id IS NULL OR tenant_id<=0;
```

## 10) BL-10 — BMS 세부 V1 테이블 목록 확정

| 영역 | 확정 테이블 |
|---|---|
| 구매 | `Tb_Product_Purchase`, `Tb_Product_Contract`, `Tb_Company`, `Tb_PhoneBook`, `Tb_FileAttach` |
| 매출 | `Tb_TaxInvoice`, `Tb_Bill`, `Tb_BillGroup`, `Tb_SiteEstimate` |
| 비용 | `Tb_Expense`, `Tb_ExpenseCategory`, `Tb_Employee`, `Tb_EmployeeCard` |
| 자금 | `Tb_Expenditure_Resolution`, `Tb_Asset_Account`, `Tb_Asset` |

## 11) BL-11 — Tb_Trash 계열 테이블명 정규화

### 11.1 정규 표기

- Canonical: `Tb_Trash`
- Action Canonical: `soft_delete`, `restore`, `permanent_delete`, `empty_all`, `pjt_plan_restore`

### 11.2 매핑 규칙

1. 코드/문서에서 휴지통 저장소는 `Tb_Trash`로 단일 표기
2. 복구 대상은 원본 테이블명(`Tb_PjtPlan`, `Tb_SiteEstimate` 등)을 별도 컬럼으로 관리
3. alias/레거시 표기는 migration 주석에서만 허용

## 12) BL-12 — 자동화 대상 시나리오 지정

| 배치 | 대상 ID | 실행 목적 |
|---|---|---|
| 스모크(배포 직후) | `FMS-001~004`, `PMS-001~003`, `MAT-001~004`, `GRP-001~003`, `ADM-001~002` | 핵심 로그인/등록/조회/전송 경로 빠른 검증 |
| 야간 회귀(일 1회) | Unique 95 전체 | 기능/권한/이관/연동 통합 회귀 |
| Shadow 집중(시간당) | `XCT-001~005`, `MIG-001` | 큐 적체/재처리/데이터 정합 모니터링 |

## 13) BL-13 — Owner 배정 및 일정 캘린더 반영

| 트랙 | owner | 리뷰어 | 일정 |
|---|---|---|---|
| Core API (FMS/PMS/MAT) | Backend-Core | TechLead | 2026-04-13 ~ 2026-04-17 |
| BMS API | Backend-BMS | TechLead | 2026-04-13 ~ 2026-04-17 |
| Integration/Realtime | Backend-Platform | TechLead | 2026-04-20 ~ 2026-04-24 |
| DB 검증 | DBA | Backend-Core | 2026-04-20 ~ 2026-04-24 |
| E2E 설계/운영 | QA | PM | 2026-04-13 ~ 2026-04-24 |
| 운영 승인/게이트 | PM | CTO | 2026-04-24 |

적용 규칙:
1. 모든 BL 항목은 owner/reviewer/ETA 필드 필수
2. 상태는 `READY`, `IN_PROGRESS`, `BLOCKED`, `DONE` 4단계
3. 배포 게이트 전 `BLOCKED=0` 유지
