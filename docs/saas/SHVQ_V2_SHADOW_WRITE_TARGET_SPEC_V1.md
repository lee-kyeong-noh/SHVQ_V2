# SHVQ_V2 Shadow Write 대상 명세서 v1

- 작성일: 2026-04-12
- 입력 근거: Claude Wave 1 쓰기 엔드포인트 추출 결과
- 범위: Wave 1 API 쓰기 엔드포인트 Shadow Write 적용 우선순위

---

## 1) 추출 요약

1. Wave 1 쓰기 엔드포인트 총계: `167개`
2. 영향 고유 테이블: `42개`
3. 파일별 분포
  - `Site.php` 40
  - `Material.php` 25
  - `Settings.php` 21
  - `Member.php` 17
  - `HeadOffice.php` 11
  - `Employee.php` 9
  - `Expense.php` 7
  - `Purchase.php` 7
  - `Stock.php` 6
  - `Trash.php` 5
  - `Sales.php` 3
  - `CalendarV2.php` 1
  - `Calendar.php` 1
  - `Fund.php` 1

---

## 2) Tier 분류

## 2.1 Tier 1 (필수) — 63개

데이터 정합성/업무 연속성 핵심. Phase 1에서 Shadow Write 필수 적용.

1. `Site.php`
  - `insert_est`, `update_est`, `delete_estimate`, `update`, `bill_insert`, `bill_update`, `bill_delete`
2. `Material.php`
  - `item_insert`, `item_update`, `item_inline_update`, `item_delete`, `delete_items`
3. `Stock.php`
  - `stock_in`, `stock_out`, `stock_transfer`, `stock_adjust`
4. `Member.php`
  - `update_branch_settings`, `member_delete`, `member_inline_update`
5. `HeadOffice.php`
  - `insert`, `update`, `delete`
6. `Employee.php`
  - `insert_employee`, `update_employee`

## 2.2 Tier 2 (권장) — 72개

보조 데이터 및 운영 기능. Phase 2에서 Shadow Write 확대 적용.

1. `Settings.php` 전체 쓰기 엔드포인트
2. `Purchase.php` 전체 쓰기 엔드포인트
3. `Sales.php` 전체 쓰기 엔드포인트
4. `Expense.php` 전체 쓰기 엔드포인트
5. `Fund.php` 전체 쓰기 엔드포인트
6. `Trash.php` 전체 쓰기 엔드포인트
7. `Site.php`의 댓글/파일/결재/출입 관련 쓰기

## 2.3 Tier 3 (후순위) — 32개

저위험 변경. Phase 3 이후 선택 적용.

1. 폴더 정렬(`reorder`) 계열
2. 연락처 이동/정렬 계열
3. 캘린더 마감일 변경 계열

---

## 3) Shadow Write 적용 규칙

1. 기준 데이터
  - 병행운영 기준은 V1 DB(구DB)이며 V2는 eventual consistency 허용
2. 성공/실패 처리
  - V1 성공 + V2 실패: 요청 성공 + 재동기화 큐 등록
  - V1 실패 + V2 성공: 요청 실패 + V2 보상 처리
3. 로깅
  - 모든 Shadow Write는 `request_id`, `api`, `todo`, `v1_result`, `v2_result` 기록
4. 알림
  - V2 재처리 누적 실패 임계치 도달 시 운영 알림 발송

---

## 4) 도입 계획

1. Phase 1
  - Tier 1 전체 적용
2. Phase 2
  - Tier 2 적용 + Tier 1 안정화
3. Phase 3
  - Tier 3 선택 적용
4. Cutover 직전
  - Tier 1/2 대상 최신 델타 재적재 + 검증 쿼리 실행

---

## 5) 검증 지표

1. Shadow Write 성공률
  - Tier 1: 99.9% 이상
  - Tier 2: 99.5% 이상
2. 재처리 큐 적체
  - 임계치(예: 100건, 10분 이상) 초과 시 경보
3. 데이터 정합성
  - 일일 샘플 비교 + 주간 전체 집계 비교

---

## 6) 후속 작업

1. 파일별 `todo` 상세 목록 + 파라미터 + 대상 테이블 매핑 추가
2. Tier 1 API별 롤백/보상 시나리오 문서화
3. 배치 재동기화 SQL 템플릿 작성

