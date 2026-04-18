# SHVQ_V2 남은 13건 실행 백로그 (P0/P1)

- 작성일: 2026-04-13
- 업데이트: 2026-04-13 (BL-01~BL-13 반영)
- 목적: 남은 오픈 항목 13건을 즉시 실행 가능한 단일 백로그로 고정
- 출처: `SHVQ_V2_API_TRANSITION_MATRIX_V1.md`, `SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md`, `SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md`, `SHVQ_V2_E2E_SCENARIOS_V1.md`
- 우선순위 원칙: 현재 구현 차단/리스크 직접 연관 항목은 `P0`, 연계/정합성 확장 항목은 `P1`

## 요약

- 총 13건
- 완료: 13건
- 미완료: 0건

## 실행 백로그

| ID | 우선순위 | 작업 항목 | 상태 | 산출물 |
|---|---|---|---|---|
| BL-01 | P0 | 파일별 `todo/action` 인벤토리 추출(73+ 엔드포인트) | 완료 | `SHVQ_V2_API_TODO_ACTION_INVENTORY_20260413.md` |
| BL-02 | P0 | Wave 1 API별 Shadow Write 적용 체크리스트 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 2장 |
| BL-03 | P0 | API별 E2E 케이스 ID 맵핑 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 3장 |
| BL-04 | P0 | `mail/api` 포함 인증/권한 공통 미들웨어 점검 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 4장 |
| BL-05 | P0 | 584개 액션 `todo`별 파라미터 정규화 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 5장 |
| BL-06 | P0 | API-테이블 단위 트랜잭션 경계 정의 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 6장 |
| BL-07 | P0 | `todo/action` 기준 API-테이블 CRUD 2차 매트릭스 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 7장 |
| BL-08 | P0 | E2E 최종 시나리오 ID(Unique 95) 확정 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 8장 |
| BL-09 | P1 | 테이블별 이관 검증 SQL 템플릿 확정 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 9장 |
| BL-10 | P1 | BMS 계좌/매출/구매 세부 V1 테이블 목록 확정 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 10장 |
| BL-11 | P1 | `Tb_Trash` 계열 운영 테이블명 정규화 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 11장 |
| BL-12 | P1 | 자동화 대상 시나리오 지정(스모크/야간회귀) | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 12장 |
| BL-13 | P1 | 시스템별 owner 배정 및 일정 캘린더 반영 | 완료 | `SHVQ_V2_BL02_BL13_EXECUTION_PACK_20260413.md` 13장 |

## 완료 기준

1. BL-01 독립 산출물 1건 완료
2. BL-02~BL-13 통합 실행팩 1건 완료
3. 문서/FTP/DevLog 반영 완료
