# SHVQ_V2 DB 테이블 변경 매트릭스 v1

- 작성일: 2026-04-12
- 기준:
  - V1: `CSM_C004732` (원본, ALTER 금지)
  - V2: `CSM_C004732_V2` (신규 스키마 생성/이관 대상)
- 출처:
  - `CLAUDE_DB.md` 변경 이력
  - `SHVQ_SAAS_ARCHITECTURE_V1.md` v1.1

---

## 1) 변경 정책

1. V1 DB는 조회/원본 보존용이며 스키마 변경하지 않는다.
2. V2 DB에 신규/확장 테이블을 생성한다.
3. 이관은 `STG_* -> MAP_* -> APP_*` 순으로 수행한다.

---

## 2) Control Plane (V2 신규)

| 도메인 | V2 테이블 | 변경유형 | 비고 |
|---|---|---|---|
| Service | `Tb_SvcService` | CREATE | 서비스 코드 마스터 |
| Tenant | `Tb_SvcTenant` | CREATE | 테넌트 마스터 |
| TenantUser | `Tb_SvcTenantUser` | CREATE | 테넌트-사용자 매핑 |
| Role | `Tb_SvcRole` | CREATE | 역할 정의 |
| Permission | `Tb_SvcRolePermission` | CREATE | 권한 매핑 |
| Audit | `Tb_SvcAuditLog` | CREATE | 감사로그 |
| Route Alias | `Tb_MenuRouteAlias` | CREATE | 메뉴 라우트 별칭 (V2 DB 전용) |

---

## 3) Integration / Event / Notification

| 도메인 | V1 소스 | V2 대상 | 변경유형 | 비고 |
|---|---|---|---|---|
| Provider Account | `Tb_IotProviderAccount`(기존 계열) | `Tb_IntProviderAccount` | CREATE+MIGRATE | `service_code`,`tenant_id` 강제 |
| Credential | (분산 저장/기존 계열) | `Tb_IntCredential` | CREATE | 암호화 토큰 저장 |
| Device | `Tb_IotDevice` | `Tb_IntDevice` | CREATE+MIGRATE | 외부장비 공통화 |
| Device Map | `Tb_IotCourtMap`(기존 매핑) | `Tb_IntDeviceMap` | CREATE+MIGRATE | ERP 객체 매핑 |
| Sync Checkpoint | (없음/분산) | `Tb_IntSyncCheckpoint` | CREATE | 동기화 커서 관리 |
| Error Queue | (없음/분산) | `Tb_IntErrorQueue` | CREATE | 재시도 큐 |
| Raw Event | `Tb_IotEventLog.raw_json` | `Tb_EventRaw` | CREATE+MIGRATE | 원문 이벤트 통합 |
| Event Stream | `Tb_IotEventLog` | `Tb_EventStream` | CREATE+MIGRATE | 정규화 이벤트 |
| Notify Rule | (없음) | `Tb_NotifyRule` | CREATE | 알림 규칙 |
| Notify Queue | (없음) | `Tb_NotifyQueue` | CREATE | 발송 큐 |
| Delivery Log | (없음) | `Tb_NotifyDeliveryLog` | CREATE | 발송 결과 |
| IoT Webhook App | `Tb_IotWebhookApp` | `Tb_IotWebhookApp` | COPY+EXTEND | V2에 이관 후 tenant 정합성 |
| IoT Event Log | `Tb_IotEventLog` | `Tb_IotEventLog` | COPY+EXTEND | 보존용 + 신모델 병행 |
| Onvif Cameras | `Tb_OnvifCameras` | `Tb_OnvifCameras` | COPY+EXTEND | tenant 컬럼 보강 |
| NVR Device | `Tb_NvrDevice` | `Tb_NvrDevice` | COPY+EXTEND | tenant 컬럼 보강 |

---

## 4) ERP Core (FMS/PMS/BMS/MAT)

| 모듈 | V1 소스 테이블 | V2 대상 테이블 | 변경유형 | 이관 우선순위 |
|---|---|---|---|---|
| FMS-본사 | `Tb_HeadOffice` | `Tb_HeadOffice` | COPY+EXTEND | 1 |
| FMS-사업장 | `Tb_Members` | `Tb_Members` | COPY+EXTEND | 2 |
| FMS-현장 | `Tb_Site` | `Tb_Site` | COPY+EXTEND | 3 |
| FMS-부서 | `Tb_Department` | `Tb_Department` | COPY+EXTEND | 1 |
| FMS-직원 | `Tb_Employee` | `Tb_Employee` | COPY+EXTEND | 2 |
| FMS-연락처 | `Tb_PhoneBook` | `Tb_PhoneBook` | COPY+EXTEND | 3 |
| PMS-PJT예정 | `Tb_PjtPlan` | `Tb_PjtPlan` | COPY+EXTEND | 4 |
| PMS-PJT단계 | `Tb_PjtPlanPhase` | `Tb_PjtPlanPhase` | COPY+EXTEND | 4 |
| PMS-PJT품목 | `Tb_PjtPlanEstItem` | `Tb_PjtPlanEstItem` | COPY+EXTEND | 5 |
| PMS-PJT품목로그 | `Tb_PjtPlanEstItemLog` | `Tb_PjtPlanEstItemLog` | COPY+EXTEND | 5 |
| PMS-견적마스터 | `Tb_SiteEstimate` | `Tb_SiteEstimate` | COPY+EXTEND | 5 |
| PMS-견적품목 | `Tb_EstimateItem` | `Tb_EstimateItem` | COPY+EXTEND | 5 |
| PMS-첨부 | `Tb_PjtFile`,`Tb_EstFile` | 동일 | COPY+EXTEND | 6 |
| MAT-품목 | `Tb_Item`,`Tb_ItemCategory` | 동일 | COPY+EXTEND | 4 |
| MAT-재고 | `Tb_Stock`,`Tb_StockLog`,`Tb_StockSetting` | 동일 | COPY+EXTEND | 5 |
| BMS-구매 | (구매 관련 테이블) | 기존+신규정의 | COPY+EXTEND | 6 |
| BMS-매출 | (매출 관련 테이블) | 기존+신규정의 | COPY+EXTEND | 6 |
| BMS-비용 | `Tb_Expense`,`Tb_ExpenseCategory` | 동일 | COPY+EXTEND | 6 |
| BMS-자금 | (계좌/자금 관련 테이블) | 기존+신규정의 | COPY+EXTEND | 6 |
| 공통-댓글 | `Tb_Comment` | `Tb_Comment` | COPY+EXTEND | 4 |
| 공통-휴지통 | `Tb_Trash` 계열 | `Tb_Trash` 계열 | COPY+EXTEND | 6 |

---

## 5) Mail / Realtime / Settings

| 도메인 | V1 소스 | V2 대상 | 변경유형 | 비고 |
|---|---|---|---|---|
| Mail Account | `Tb_Mail_Accounts` | `Tb_Mail_Accounts` | COPY+EXTEND | tenant 스코프 확장 |
| Mail Cache | `Tb_Mail_MessageCache` | `Tb_Mail_MessageCache` | COPY+EXTEND | thread 컬럼 유지 |
| Mail Draft | `Tb_Mail_Draft` | `Tb_Mail_Draft` | COPY+EXTEND | tenant 스코프 확장 |
| Mail WS Token | `Tb_Mail_WsToken` | `Tb_Mail_WsToken` | COPY+EXTEND | 인증 정책 반영 |
| Push Subscription | `Tb_PushSubscription` | `Tb_PushSubscription` | COPY+EXTEND | tenant 스코프 확장 |
| User Settings | `Tb_UserSettings` | `Tb_UserSettings` | COPY+EXTEND | tenant 컨텍스트 기반 |
| App Settings | `Tb_AppSettings` | `Tb_AppSettings` | COPY+EXTEND | 시스템 설정 |

---

## 6) 이관 운영 테이블 (V2 신규)

| 테이블 | 목적 |
|---|---|
| `MIG_Run` | 실행 이력 |
| `MIG_TableStatus` | 테이블별 진행 상태 |
| `MIG_RowChecksum` | 원본/대상 검증 집계 |
| `MIG_Error` | 실패 레코드/재처리 |
| `MIG_CutoverLog` | 컷오버 타임라인 |

---

## 7) 실행 순서(요약)

1. V2 DB 생성 + Control/Integration 테이블 생성
2. `STG_*` 적재
3. `MAP_*` 생성(본사→사업장→현장→PJT/견적→MAT 순)
4. ERP Core 이관
5. Mail/IoT/알림 이관
6. Shadow Write 병행운영
7. 컷오버 + 검증 + 롤백 리허설

---

## 8) 오픈 항목

1. BMS 계좌/매출/구매 세부 테이블의 정확한 V1 목록 확정
2. `Tb_Trash` 계열 실제 운영 테이블명 정규화
3. `todo/action` 기준 API-테이블 CRUD 매핑(2차 매트릭스) 작성

