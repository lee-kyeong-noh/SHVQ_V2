# SHVQ V2 GRP Placeholder 리스크/우선순위 정리

- 작성일: 2026-04-13
- 범위: `js/core/router.js`, `views/saas/grp/*`
- 목적: 프런트 진행 중 남은 placeholder 화면의 런칭 리스크를 사전 정리하고 구현 우선순위 확정

## 1) 현재 상태 요약

1. 라우터 매핑 완료
- `emp`, `org_chart`, `org_chart_card`, `holiday`, `work_overtime`, `attitude`, `chat`, `approval_req`, `approval_write`, `approval_done`, `doc_all`, `approval_official`

2. 실구현 완료 화면 (6)
- `emp`
- `org_chart`
- `org_chart_card`
- `holiday`
- `work_overtime`
- `attitude`

3. Placeholder 화면 (6)
- `chat`
- `approval_req`
- `approval_write`
- `approval_done`
- `doc_all`
- `approval_official`

## 2) 핵심 리스크

### R1. 사용자 체감 단절 (High)

- 홈 대시보드(`emp`)에서 `approval_req`, `chat`로 이동은 가능하지만 화면이 "개발 예정" 상태라 기능 단절 체감이 큼
- 런칭 시 문의/불만 포인트가 되기 쉬움

### R2. 백엔드 준비도와 프런트 노출 불일치 (High)

- `Chat.php`, `Approval.php` 백엔드는 동작 준비가 되어 있는데 UI 미구현으로 가치 전달이 안 됨
- QA에서는 "API PASS / 화면 FAIL" 형태로 리포팅 분산 가능

### R3. 결재/채팅 권한 시나리오 미검증 (Medium)

- 2차에서 결재 순서 검증과 중복 처리 가드는 보강됨
- 하지만 실제 화면 플로우(목록 -> 상세 -> 액션 -> 재조회) 레벨에서는 아직 검증 전

### R4. 운영 전환 시 문서함/공문서함 공백 (Medium)

- `doc_all`, `approval_official` 부재 시 관리자/감사 사용자가 가장 먼저 공백을 체감

## 3) 우선순위 (프런트 구현 순서 권장)

1. P0: `approval_req` + `approval_write`
- 이유: 결재 생성/처리의 핵심 루프
- 연동 todo: `approval_req`, `approval_write`, `approval_submit`, `approval_approve`, `approval_reject`, `approval_comment`, `approval_detail`

2. P0: `chat`
- 이유: 홈 대시보드에 이미 카드 노출, 즉시 사용 기대치 높음
- 연동 todo: `room_list`, `room_create`, `message_list`, `message_send`, `mark_read`, `unread_count`

3. P1: `approval_done`
- 이유: 결재 완료 이력 조회 니즈 높음
- 연동 todo: `approval_done`, `approval_detail`

4. P1: `doc_all`
- 이유: 결재 전수 검색/필터 기반 운영 필요
- 연동 todo: `doc_all`, `approval_detail`

5. P2: `approval_official`
- 이유: 공문 분류 정책 확정과 함께 투입 권장
- 연동 todo: `approval_official`, `approval_detail`

## 4) 화면별 최소 기능 스코프

### 4.1 chat

- 좌측 방목록 + 우측 메시지 패널 2단 레이아웃
- 메시지 전송/읽음 처리
- 모바일에서 방목록-대화창 스택 전환

### 4.2 approval_req

- 내 대기 문서 목록
- 문서 상세 모달
- 승인/반려 + 코멘트
- 처리 후 목록 즉시 리프레시

### 4.3 approval_write

- 제목/본문/결재선(다중 선택) 입력
- 임시저장(`approval_write`) + 상신(`approval_submit`)

### 4.4 approval_done / doc_all / approval_official

- 공통 필터(상태/검색/기간)
- 목록 + 상세
- 공통 컬럼 템플릿 재사용

## 5) QA 리허설 체크리스트

1. 권한
- 일반 사용자: 승인/반려 버튼 비노출
- 관리자/결재자: 현재 차수 문서에서만 액션 가능

2. 상태 전이
- 결재 중복승인 시 `409` 에러 표시
- 처리 후 상태 뱃지 즉시 갱신

3. 오류 UX
- `403`/`409`/`422`를 toast로 구분 표기
- 목록 재조회 전 기존 UI 잠금 해제 확인

4. 반응형
- 1024px, 768px에서 표/패널 깨짐 여부

## 6) 병렬 작업 제안 (백엔드/검증 축)

1. 스모크 자동화 유지
- `scripts/smoke_test_grp.sh`를 릴리즈 직전/직후 1회씩 실행

2. 문서 기준점 유지
- API 변경 발생 시 `SHVQ_V2_GRP_API_SPEC_20260413.md` 동시 갱신

3. 기능 토글 대응
- placeholder 6개 중 투입 완료 라우트부터 사이드바 뱃지/라벨로 "beta" 제거
