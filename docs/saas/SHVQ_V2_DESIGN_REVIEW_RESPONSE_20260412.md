# SHVQ_V2 설계 리뷰 반영표 (Claude 리뷰 대응)

- 기준 리뷰일: 2026-04-12
- 대상 문서:
  - `SHVQ_SAAS_ARCHITECTURE_V1.md` (v1.1 갱신)
  - `SHVQ_V2_MENU_IA_REDESIGN_V1.md` (v1.1 갱신)

---

## 1) P0 이슈 반영

1. ERP Core 재설계 누락
  - 조치: `ERP Core 리팩토링 범위` 섹션 추가
  - 상태: 반영 완료
2. 인증/보안 재설계 누락
  - 조치: 인증/보안 전용 장(비밀번호 해시, CSRF, 자격증명 분리) 추가
  - 상태: 반영 완료
3. 프론트엔드 아키텍처 결정 부재
  - 조치: 기술 스택 확정(1차 Vanilla JS + ES Module 유지) 명시
  - 상태: 반영 완료

## 2) P1 이슈 반영

1. resolveTenantContext 적용 범위 불명확
  - 조치: 테넌트 스코프 적용 순서 + API Wave 전환 전략 추가
  - 상태: 반영 완료
2. Stage 3~4 ERP 매핑 구체성 부족
  - 조치: 핵심 테이블/규칙 매핑 명세 추가
  - 상태: 반영 완료
3. 병행운영 dual-write 전략 없음
  - 조치: Shadow Write + Cutover Freeze 전략 명시
  - 상태: 반영 완료
4. SmartThings 아키텍처 충돌
  - 조치: 브라우저 직접 호출 + 서버사이드 동기화 하이브리드 확정
  - 상태: 반영 완료
5. 메뉴 IA system 코드 호환 불완전
  - 조치: L0 ↔ `system` 매핑표 및 규칙 추가
  - 상태: 반영 완료

## 3) P2 보완 반영

1. Node Worker 위치 불명확
  - 조치: 실시간 게이트웨이 전용으로 역할 명시
  - 상태: 반영 완료
2. Redis 언급 부족
  - 조치: 실시간 구성요소로 명시
  - 상태: 반영 완료
3. ONVIF/NVR 제약 미반영
  - 조치: VIGI replay 제약사항 반영
  - 상태: 반영 완료
4. 나라장터 HTTPS 우회 제약
  - 조치: 인프라 제약 장 추가
  - 상태: 반영 완료
5. CAD 통합 전략 부재
  - 조치: CAD 인증/DB 통합 과제 명시
  - 상태: 부분 반영 (상세 설계 필요)
6. 일정 과소추정
  - 조치: 6~7주 -> 최소 16주(약 4개월)로 재산정
  - 상태: 반영 완료

---

## 4) Go/No-Go 재판정

현재 상태: `조건부 Go`

근거:

1. P0 3건 모두 문서 반영 완료
2. P1 5건 모두 실행 가능한 수준으로 반영 완료
3. 남은 리스크는 상세 설계/실행 문서(테이블 매트릭스, 테스트케이스, 보안 점검표) 작성 단계

---

## 5) 다음 산출물 (문서만)

1. API 73개 전환 매트릭스 (`api_name`, `tenant_scope`, `wave`, `risk`)
  - 완료: `SHVQ_V2_API_TRANSITION_MATRIX_V1.md` 작성
2. DB 테이블 변경 매트릭스 (`table`, `add_columns`, `migration_order`)
  - 완료: `SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md` 작성
3. 인증 전환 상세서 (`password`, `session`, `csrf`, `secret rotation`)
  - 완료: `SHVQ_V2_AUTH_MIGRATION_DETAIL_V1.md` 작성
4. Shadow Write 대상 API 명세서
  - 완료: `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md` 작성
5. E2E 핵심 시나리오(시스템별 10개+, 총 90개+)
  - 완료: `SHVQ_V2_E2E_SCENARIOS_V1.md` 작성
6. API-테이블 CRUD 매핑(액션-테이블 추적)
  - 완료: `SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md` 작성

---

## 6) 3차 리뷰 추가 반영 (2026-04-12)

1. Shadow Write 실패 처리 전략 상세화
  - V1 성공/V2 실패 시 비동기 재동기화 큐로 복구
  - V1 실패/V2 성공 시 보상 작업으로 V2 정합성 복구
2. ERP Core 리팩토링 폴더 구조 명시
  - `dist_library/erp/ProjectService.php` 등 서비스 레이어 경로 추가
3. V1/V2 ALTER 정책 명문화
  - V1 스키마 변경 금지, V2 신규 스키마 원칙 확정
4. 관리 메뉴 system 코드 모호성 해소
  - 관리 메뉴 URL을 `system=groupware`로 정규화
5. E2E 최소 기준 상향
  - 시스템별 최소 10개(총 90개+)로 조정
6. CSS 파일 전용 스타일 정책 추가
  - PHP `<style>`/인라인 `style=""` 금지, 런타임 좌표 스타일만 예외 허용
7. API 매트릭스 Mail API 누락 보완
  - `mail/api/accounts|messages|sync|drafts|attachments|ws_token|check` 반영
8. Login Wave 정렬
  - `Login.php`를 Wave 0(인증 선행)으로 재배치
9. BMS 서비스 경로 보완
  - `dist_library/erp/Purchase|Sales|Fund|ExpenseService.php` 추가 명시
10. DB 테이블 변경 매트릭스 문서 추가
  - `SHVQ_V2_DB_TABLE_CHANGE_MATRIX_V1.md` 신규 작성
11. 개발 단계별 메뉴 게이팅 정책 추가
  - 현재 Wave 외 메뉴 비활성 + feature_flag + canDo() 이중 제어
12. Shadow Write 대상 명세서 문서 추가
  - `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md` (167개 엔드포인트, Tier 1~3)
13. 개발 거버넌스 정책 명시
  - 개발규칙 우선순위(매뉴얼 > AGENTS), DevLog 의무, 매뉴얼 게시판형 운영 기준 추가
14. 인증 전환 상세서 문서 추가
  - `SHVQ_V2_AUTH_MIGRATION_DETAIL_V1.md` (평문/MD5/CSRF/세션/쿠키/레이트리미팅 전환)
15. E2E 시나리오 문서 추가
  - `SHVQ_V2_E2E_SCENARIOS_V1.md` (Raw 120, 운영 KPI 95 기준)
16. API-테이블 CRUD 매핑 문서 추가
  - `SHVQ_V2_API_TABLE_CRUD_MATRIX_V1.md` (총 584개 액션, 55개+ 테이블)
