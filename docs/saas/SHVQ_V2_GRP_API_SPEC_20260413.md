# SHVQ V2 GRP API 명세서 (Wave2)

- 작성일: 2026-04-13
- 대상: `dist_process/saas/Employee.php`, `dist_process/saas/Chat.php`, `dist_process/saas/Approval.php`
- 목적: GRP 프론트엔드가 즉시 연동 가능한 todo/파라미터/권한 규칙 단일 기준 제공

## 1) 공통 규칙

1. 인증 필수
- 세 엔드포인트 모두 세션 인증(`AuthService::currentContext`)이 없으면 `401 AUTH_REQUIRED` 반환

2. 멀티테넌트 스코프
- 공통 스코프 키: `service_code`, `tenant_id`
- 생략 시 로그인 컨텍스트에서 자동 해석
- `role_level` 낮을 경우 상위 스코프 override 제한됨

3. 테이블 준비 상태 체크
- 각 도메인별 필수 테이블 누락 시 `503 GROUPWARE_SCHEMA_NOT_READY`
- 응답 `data.missing_tables` 확인 가능

4. CSRF
- 쓰기 todo는 `csrf_token` 필수
- 미일치 시 `403 CSRF_TOKEN_INVALID`

5. 응답 포맷
- 성공: `{ ok: true, code: "OK", data: ... }`
- 실패: `{ ok: false, code: "...", message: "..." }`

6. 대표 HTTP 코드
- `401` 인증 없음
- `403` 권한/CSRF
- `404` 대상 없음
- `409` 상태충돌/중복처리
- `422` 파라미터 오류

## 2) Employee.php (부서/직원/주소록/근태/휴가/초과근무)

### 2.1 조회 todo

| todo | 설명 | 주요 파라미터 |
|---|---|---|
| `summary`, `emp_summary`, `home_summary` | GRP 대시보드 요약 | (선택) `service_code`, `tenant_id` |
| `dept_list`, `org_chart` | 부서 목록 | - |
| `employee_list`, `org_employee_list` | 직원 목록 | `search`, `dept_idx`, `status`, `limit` |
| `employee_detail`, `get_employee` | 직원 상세 | `employee_id` 또는 `idx` |
| `phonebook_list`, `org_chart_card` | 주소록 목록 | `search`, `limit` |
| `attitude_list`, `attendance_list` | 근태 목록 | `employee_idx`, `start_date`, `end_date`, `limit` |
| `holiday_list` | 휴가 목록 | `employee_idx`, `status`, `start_date`, `end_date`, `limit` |
| `overtime_list`, `work_overtime_list` | 초과근무 목록 | `employee_idx`, `status`, `start_date`, `end_date`, `limit` |

### 2.2 쓰기 todo

| todo | 설명 | 필수 파라미터 | 권한 |
|---|---|---|---|
| `dept_insert` | 부서 등록 | `dept_name` | 로그인 사용자 |
| `dept_update` | 부서 수정 | `idx`(또는 `dept_id`), `dept_name` | 로그인 사용자 |
| `dept_delete` | 부서 삭제(소프트) | `dept_id`(또는 `idx`) | `role_level >= 4` |
| `insert_employee` | 직원 등록 | `emp_name` (+ 선택 필드) | 로그인 사용자 |
| `update_employee` | 직원 수정 | `idx`(또는 `employee_id`) | 로그인 사용자 |
| `upload_photo` | 직원 사진 업로드/교체 | `csrf_token`, 파일(`photo` 또는 `employee_photo` 또는 `file` 또는 `upload`) | 로그인 사용자 |
| `phonebook_insert`/`phonebook_update`/`phonebook_save` | 주소록 저장 | `contact_name` (+ 선택 필드) | 로그인 사용자 |
| `phonebook_delete` | 주소록 삭제 | `phonebook_id`(또는 `idx`) | 로그인 사용자 |
| `attendance_save`, `save_attitude` | 근태 저장 | `employee_idx`, `work_date` | 로그인 사용자 |
| `save_holiday` | 휴가 신청/수정 | `employee_idx`, `start_date`, `end_date` | 로그인 사용자 |
| `holiday_approve` | 휴가 승인 | `holiday_id` | `role_level >= 4` |
| `holiday_reject` | 휴가 반려 | `holiday_id` | `role_level >= 4` |
| `holiday_cancel` | 휴가 취소 | `holiday_id` | 본인/생성자 또는 관리자 |
| `save_overtime` | 초과근무 신청/수정 | `employee_idx`, `work_date` | 로그인 사용자 |
| `overtime_approve` | 초과근무 승인 | `overtime_id` | `role_level >= 4` |
| `overtime_reject` | 초과근무 반려 | `overtime_id` | `role_level >= 4` |
| `overtime_cancel` | 초과근무 취소 | `overtime_id` | 본인/생성자 또는 관리자 |

### 2.3 상태 머신 규칙

- 휴가/초과근무 승인·반려·취소는 `REQUESTED` 상태에서만 전이 가능
- 이미 처리된 건은 `409 CONFLICT` 계열 실패로 반환
- 취소(`CANCELED`)는 2차 수정 반영으로 본인 취소 허용

### 2.4 직원 사진 업로드 규칙 (`upload_photo`)

- 요청 형식: `multipart/form-data`
- 파일 필드명: `photo` 우선, 호환 필드 `employee_photo`, `file`, `upload` 지원
- 선택 파라미터: `employee_id`(또는 `idx`) 전달 시 `Tb_GwEmployee.photo_url` 즉시 갱신
- 성공 응답: `data.photo_url` 반환, `employee_id` 전달 시 `data.item`(직원 row) 포함
- 교체 정책: 새 업로드 시 DB의 `photo_url`은 최신 URL로 교체되며, 기존 물리 파일은 자동 삭제하지 않음

```bash
curl -s -X POST "https://shvq.kr/SHVQ_V2/dist_process/saas/Employee.php" \
  -b ./cookies.txt -c ./cookies.txt \
  -F "todo=upload_photo" \
  -F "employee_id=123" \
  -F "photo=@/path/to/photo.png" \
  -F "csrf_token={csrf_token}"
```

## 3) Chat.php (채팅)

### 3.1 조회 todo

| todo | 설명 | 주요 파라미터 |
|---|---|---|
| `room_list`, `chat_rooms` | 내 채팅방 목록 | - |
| `room_detail`, `chat_room_detail` | 채팅방 상세 | `room_idx`(또는 `room_id`) |
| `message_list`, `chat_messages` | 메시지 목록 | `room_idx`, `last_idx`, `limit` |
| `unread_count`, `chat_unread_count` | 미읽음 개수 | - |

### 3.2 쓰기 todo

| todo | 설명 | 필수 파라미터 | 권한 |
|---|---|---|---|
| `room_create`, `chat_room_create` | 채팅방 생성 | `member_ids`(comma) | 로그인 사용자 |
| `room_join`, `chat_room_join` | 멤버 초대/참여 | `room_idx`, `target_user_idx` | 방 접근 가능자 |
| `room_leave`, `chat_room_leave` | 방 나가기/강제 제외 | `room_idx` (+선택 `target_user_idx`) | 본인 또는 관리자 |
| `message_send`, `chat_send` | 메시지 전송 | `room_idx`, `message_text` | 방 멤버 |
| `message_delete`, `chat_delete` | 메시지 삭제 | `room_idx`, `message_idx` | 작성자 또는 관리자 |
| `mark_read`, `chat_read` | 읽음 처리 | `room_idx`, `last_message_idx`(선택) | 방 멤버 |

## 4) Approval.php (전자결재)

### 4.1 조회 todo

| todo | 설명 | 주요 파라미터 |
|---|---|---|
| `approval_req`, `list_req` | 내 결재 대기함 | - |
| `approval_done`, `list_done` | 내 처리 완료함 | - |
| `doc_all`, `list_all` | 전체 문서함 | `status`, `doc_type`, `search`, `limit` |
| `approval_official`, `list_official` | 공문서함 | `status`, `search`, `limit` |
| `approval_detail`, `doc_detail` | 문서 상세 | `doc_id`(또는 `idx`) |

### 4.2 쓰기 todo

| todo | 설명 | 필수 파라미터 | 비고 |
|---|---|---|---|
| `approval_write`, `draft_create` | 결재 초안 작성 | `title`, `approver_ids`(comma) | 상태 `DRAFT` 생성 |
| `approval_submit` | 결재 상신 | `doc_id` | `DRAFT/REJECTED -> SUBMITTED` |
| `approval_approve` | 결재 승인 | `doc_id`, `comment`(선택) | 현재 차수만 승인 가능 |
| `approval_reject` | 결재 반려 | `doc_id`, `comment`(선택) | 즉시 문서 `REJECTED` |
| `approval_cancel` | 문서 취소 | `doc_id` | 작성자/권한자 취소 |
| `approval_comment`, `add_comment` | 문서 코멘트 | `doc_id`, `comment` | 빈 문자열 불가 |

### 4.3 2차 수정 반영 포인트

- `approval_req` 목록은 `current_line_order == line_order` 조건으로 현재 차수만 노출
- `approval_approve/reject` 시 현재 차수 교차 검증 적용
- 이미 처리된 결재선 중복 처리 시 `approval line already processed` 충돌 처리

## 5) 프런트 연동 최소 체크리스트

1. 쓰기 요청 직전 CSRF 재발급 (`Auth.php?todo=csrf`) 후 `csrf_token` 첨부
2. 목록 기본 `limit` 지정 (`200` 내외)
3. 상태 기반 버튼 노출
- 휴가/초과근무: `REQUESTED`에서만 승인/반려/취소 노출
- 결재: `SUBMITTED` + 현재 차수에서만 승인/반려 노출
4. 실패 코드별 처리
- `403` 권한/CSRF
- `409` 상태충돌(이미 처리)
- `422` 입력 누락

## 6) 빠른 검증 스크립트

- 파일: `scripts/smoke_test_grp.sh`
- 예시:

```bash
bash scripts/smoke_test_grp.sh "https://shvq.kr/SHVQ_V2" ./cookies.txt
```

- 시나리오: 인증/CSRF, 부서, 직원, 직원사진 업로드, 휴가 취소, 초과근무 취소, 결재(중복승인가드), 채팅, 정리(cleanup)
