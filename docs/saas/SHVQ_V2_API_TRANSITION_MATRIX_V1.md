# SHVQ_V2 API 전환 매트릭스 v1.1

- 작성일: 2026-04-12
- 기준 소스:
  - `SHV_NEW/dist_process/*.php`
  - `SHV_NEW/mail/api/*.php`
- 파일 집계:
  - dist_process 운영 대상: 50개 (`_tmp_*` 제외)
  - mail/api 대상: 7개
  - 총 운영 대상: 57개
- 참고:
  - 본 문서는 파일 단위 매트릭스이며, 실제 `todo/action` 단위 엔드포인트는 73개 이상

---

## 1) Wave 정의

1. Wave 0 (인증 선행)
  - Phase 1 인증 전환과 동기화되는 선행 API
2. Wave 1 (핵심 도메인)
  - PJT/견적/MAT/FMS/BMS 핵심 쓰기 API
3. Wave 2 (연동/실시간)
  - IoT/메일/알림/파일/외부연동 API
4. Wave 3 (부가/유틸)
  - 조회/운영도구/보조 기능

---

## 2) Wave 0 — 인증 선행 전환 (Phase 1 정렬)

| API 파일 | 도메인 | tenant 스코프 | 우선순위 | 리스크 |
|---|---|---|---|---|
| `Login.php` | 인증/로그인 | 공통 | W0 | H |
| `mail/api/ws_token.php` | 메일 실시간 토큰 | 공통 | W0 | H |
| `mail/api/check.php` | 메일 계정/연결 점검 | 공통 | W0 | M |

---

## 3) Wave 1 — ERP Core 우선 전환

| API 파일 | 도메인 | tenant 스코프 | 우선순위 | 리스크 |
|---|---|---|---|---|
| `Project.php` | PJT/견적 핵심 | 필수 | W1 | H |
| `ProjectV2.php` | PJT v2 | 필수 | W1 | H |
| `Site.php` | 현장/견적/결재 연계 | 필수 | W1 | H |
| `Member.php` | 사업장 | 필수 | W1 | H |
| `HeadOffice.php` | 본사 | 필수 | W1 | M |
| `Material.php` | 품목(MAT) | 필수 | W1 | H |
| `Stock.php` | 재고(MAT) | 필수 | W1 | H |
| `Purchase.php` | 구매(BMS) | 필수 | W1 | M |
| `Sales.php` | 매출(BMS) | 필수 | W1 | M |
| `Expense.php` | 경비(BMS) | 필수 | W1 | M |
| `Fund.php` | 자금(BMS) | 필수 | W1 | M |
| `Settings.php` | 권한/설정 | 필수 | W1 | H |
| `Employee.php` | 직원/조직 | 필수 | W1 | M |
| `Trash.php` | 소프트삭제 복구 | 필수 | W1 | H |
| `CalendarV2.php` | PJT 캘린더 v2 | 필수 | W1 | M |
| `Calendar.php` | PJT 캘린더 | 필수 | W1 | M |

---

## 4) Wave 2 — Integration/Realtime 전환

| API 파일 | 도메인 | tenant 스코프 | 우선순위 | 리스크 |
|---|---|---|---|---|
| `ShvqIot.php` | SHVQ IoT Hub | 필수 | W2 | H |
| `TeniqIot.php` | Teniq IoT Hub | 필수 | W2 | H |
| `TuyaIot.php` | Tuya IoT | 필수 | W2 | H |
| `Onvif.php` | ONVIF 카메라 | 필수 | W2 | M |
| `Nvr.php` | NVR 장비 | 필수 | W2 | M |
| `Push.php` | Web Push | 필수 | W2 | H |
| `FileDownload.php` | 파일 다운로드 | 필수 | W2 | M |
| `FileUpload.php` | 파일 업로드 | 필수 | W2 | M |
| `Comment.php` | 코멘트/파일 | 필수 | W2 | M |
| `Chat.php` | 채팅 | 필수 | W2 | M |
| `Contact.php` | 통합 연락처 검색 | 필수 | W2 | M |
| `PhoneBook.php` | 연락처 | 필수 | W2 | M |
| `OCR.php` | OCR 외부연동 | 필수 | W2 | M |
| `NaraTender.php` | 나라장터 API | 선택 | W2 | M |
| `NaraCron.php` | 나라장터 수집 배치 | 선택 | W2 | M |
| `Elevator.php` | 승강기 조회 | 선택 | W2 | L |
| `ElevatorCollect.php` | 승강기 수집 | 선택 | W2 | M |
| `Weather.php` | 날씨 프록시 | 선택 | W2 | L |
| `AlimTalk.php` | 알림톡 연계 | 선택 | W2 | M |
| `mail_contacts.php` | 메일 연락처 연계 | 필수 | W2 | M |
| `mail/api/accounts.php` | 메일 계정 관리 | 필수 | W2 | H |
| `mail/api/messages.php` | 메일 목록/상세 | 필수 | W2 | H |
| `mail/api/sync.php` | 메일 동기화 | 필수 | W2 | H |
| `mail/api/drafts.php` | 임시저장 관리 | 필수 | W2 | M |
| `mail/api/attachments.php` | 첨부 처리 | 필수 | W2 | M |

---

## 5) Wave 3 — Utility/Admin 전환

| API 파일 | 도메인 | tenant 스코프 | 우선순위 | 리스크 |
|---|---|---|---|---|
| `DevLog.php` | 개발일지 | 공통 | W3 | L |
| `AiLog.php` | AI 로그 | 공통 | W3 | L |
| `Download.php` | 다운로드 유틸 | 공통 | W3 | L |
| `ImageProxy.php` | 이미지 프록시 | 공통 | W3 | L |
| `News.php` | 뉴스 연계 | 선택 | W3 | L |
| `ShortUrl.php` | 단축 URL | 선택 | W3 | L |
| `AptBid.php` | 입찰 결과 도구 | 선택 | W3 | L |
| `TennisReserv.php` | 테니스 예약 | 선택 | W3 | L |
| `TennisCron.php` | 테니스 배치 | 선택 | W3 | L |
| `TechnicalSupport.php` | 기술지원 보조 | 선택 | W3 | L |
| `Asset.php` | 자산 보조 | 선택 | W3 | L |
| `CarAccident.php` | 사고 보조 | 선택 | W3 | L |
| `PayRoll.php` | 급여 보조 | 선택 | W3 | M |

---

## 6) 전환 규칙

1. 모든 Wave 공통
  - `resolveTenantContext()` 적용
  - tenant 키 없는 SELECT/UPDATE/DELETE 금지
2. Wave 0
  - 인증 전환 완료 후 Wave 1 착수
3. Wave 1
  - Shadow Write 대상 우선 지정
  - PJT/견적/MAT 회귀 시나리오 우선 수행
4. Wave 2
  - 외부 API Rate Limit/재시도 표준 적용
5. Wave 3
  - 영향도 낮은 기능부터 점진 전환

---

## 7) 즉시 TODO

1. 파일별 `todo/action` 인벤토리 추출 (73+ 엔드포인트 상세화)
2. Wave 1 API별 Shadow Write 적용 여부 체크리스트 작성
3. API별 E2E 테스트 케이스 맵핑 (케이스 ID 연결)
4. `mail/api` 경로를 포함한 인증/권한 공통 미들웨어 적용 점검

---

## 8) Shadow Write 상세 연계 문서

1. `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md`를 Wave 1 상세 기준으로 사용한다.
2. 요약
  - Wave 1 쓰기 엔드포인트: 167개
  - Tier 1(필수): 63개
  - Tier 2(권장): 72개
  - Tier 3(후순위): 32개
