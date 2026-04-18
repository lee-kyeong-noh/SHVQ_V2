# SHVQ_V2 FMS 권한 매트릭스 (Wave1)

- 작성일: 2026-04-13
- 대상 API: `dist_process/saas/Site.php`, `dist_process/saas/HeadOffice.php`, `dist_process/saas/Member.php`
- 기준: 현재 코드 구현 (`AuthService::currentContext`, CSRF 검증, role_level 가드)

## 1) 공통 규칙

- 인증: 3개 API 모두 로그인 세션 필수 (`AUTH_REQUIRED`)
- 쓰기 todo: 모두 `POST + CSRF` 필수
- 역할 기준:
  - `role_level >= 2`: 일반 등록/수정/추가 write
  - `role_level >= 4`: 삭제/복구/일괄 삭제 계열

## 2) Site API

| todo | 메서드 | CSRF | 최소 role_level | 비고 |
|---|---|---|---:|---|
| `list` | GET | - | 1+ | 조회 |
| `detail` | GET | - | 1+ | 조회 |
| `search` | GET | - | 1+ | 조회 |
| `est_list` | GET | - | 1+ | 조회 |
| `est_detail` | GET | - | 1+ | 조회 |
| `est_pdf_data` | GET | - | 1+ | 조회 |
| `insert` | POST | required | 2+ | 사이트 등록 |
| `update` | POST | required | 2+ | 사이트 수정 |
| `delete` | POST | required | 2+ | 사이트 삭제(연결 청구서 존재 시 차단/force 허용) |
| `insert_est` | POST | required | 2+ | 견적 등록 |
| `update_est` | POST | required | 2+ | 견적 수정 |
| `delete_estimate` | POST | required | 2+ | 견적 삭제 |
| `copy_est` | POST | required | 2+ | 견적 복사 |
| `recalc_est` | POST | required | 2+ | 합계 재계산 |
| `upsert_est_items` | POST | required | 2+ | 품목 일괄 업서트 |
| `update_est_item` | POST | required | 2+ | 품목 단건 수정 |
| `delete_est_item` | POST | required | 2+ | 품목 삭제 |
| `approve_est` | POST | required | 2+ | 견적 승인/상태전환 |

## 3) HeadOffice API

| todo | 메서드 | CSRF | 최소 role_level | 비고 |
|---|---|---|---:|---|
| `list` | GET | - | 1+ | 조회 |
| `detail` | GET | - | 1+ | 조회 |
| `check_dup` | GET | - | 1+ | 중복확인 |
| `insert` | POST | required | 2+ | 본사 등록 |
| `update` | POST | required | 2+ | 본사 수정 |
| `bulk_update` | POST | required | 2+ | 본사 일괄수정 |
| `delete_attach` | POST | required | 2+ | 본사 첨부 삭제 |
| `restore` | POST | required | 4+ | 본사 복구 |
| `delete` | POST | required | 4+ | 본사 삭제 |

## 4) Member API

| todo | 메서드 | CSRF | 최소 role_level | 비고 |
|---|---|---|---:|---|
| `list` | GET | - | 1+ | 조회 |
| `detail` | GET | - | 1+ | 조회 |
| `check_dup` | GET | - | 1+ | 중복확인 |
| `insert` | POST | required | 2+ | 사업장 등록 |
| `update` | POST | required | 2+ | 사업장 수정 |
| `update_branch_settings` | POST | required | 2+ | 내부적으로 `update` alias 처리 |
| `member_inline_update` | POST | required | 2+ | 인라인 수정 |
| `member_delete` | POST | required | 4+ | 사업장 삭제 |
| `restore` | POST | required | 4+ | 사업장 복구 |
| `member_bulk_action` | POST | required | 4+ | 내부 라우팅(`member_delete`/`restore`) |

## 5) 감사/추적 포인트

- 쓰기 todo는 `Tb_SvcAuditLog` 가능 시 감사 로그 기록
- 쓰기 todo는 `ShadowWriteQueueService::enqueueJob`로 Shadow 큐 적재
- 큐 payload는 `dist_process/saas/*.php` api 경로 기준으로 저장
