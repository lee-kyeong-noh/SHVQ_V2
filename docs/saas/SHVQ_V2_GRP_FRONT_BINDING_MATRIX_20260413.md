# SHVQ V2 GRP Front Binding Matrix

- 작성일: 2026-04-13
- 목적: 프런트 구현 시 화면별 API 바인딩 포인트와 필수 필드 체크를 한 장으로 통합
- 기준 파일:
  - `views/saas/grp/*.php`
  - `dist_process/saas/Employee.php`, `Chat.php`, `Approval.php`

## 1) 구현 완료 화면 바인딩

### 1.1 `emp` (홈 대시보드)

| 구분 | 내용 |
|---|---|
| 데이터 로드 | 서버 렌더에서 `GroupwareService::dashboardSummary()` 사용 |
| 의존 카운트 키 | `department_count`, `employee_count`, `phonebook_count`, `holiday_requested_count`, `overtime_requested_count`, `approval_pending_my_count`, `chat_unread_count`, `attendance_today_count` |
| 네비게이션 | `data-grp-nav` 클릭으로 `SHV.router.navigate(...)` |
| 실패 대응 | 테이블 미준비 시 empty-state 노출 |

### 1.2 `org_chart` (조직도/직원)

| 기능 | endpoint | todo | 요청 필드 | 응답 필드 |
|---|---|---|---|---|
| 부서 목록 | `Employee.php` | `dept_list` | - | `data.items[]` (`idx`, `parent_idx`, `dept_name`, `dept_code`, `is_active`) |
| 직원 목록 | `Employee.php` | `employee_list` | `search`, `dept_idx`, `status`, `limit` | `data.items[]` (`idx`, `emp_no`, `emp_name`, `dept_idx`, `dept_name`, `status`) |
| 직원 상세 | `Employee.php` | `employee_detail` | `idx` | `data.item` |
| 부서 저장 | `Employee.php` | `dept_insert` / `dept_update` | `idx`, `dept_name`, `dept_code`, `parent_idx`, `is_active`, `csrf_token` | `data.item` |
| 부서 삭제 | `Employee.php` | `dept_delete` | `dept_id`, `csrf_token` | `data.dept_id` |
| 직원 저장 | `Employee.php` | `insert_employee` / `update_employee` | `idx`, `emp_name`, `emp_no`, `dept_idx`, `position_name`, `job_title`, `phone`, `email`, `status`, `hire_date`, `leave_date`, `csrf_token` | `data.item` |

### 1.3 `org_chart_card` (주소록 카드)

| 기능 | endpoint | todo | 요청 필드 | 응답 필드 |
|---|---|---|---|---|
| 주소록 목록 | `Employee.php` | `phonebook_list` | `search`, `limit` | `data.items[]` (`idx`, `contact_name`, `company_name`, `department_name`, `position_name`, `phone`, `email`, `memo`) |
| 주소록 저장 | `Employee.php` | `phonebook_insert` / `phonebook_update` | `idx`, `contact_name`, `company_name`, `department_name`, `position_name`, `phone`, `email`, `memo`, `csrf_token` | `data.item` |
| 주소록 삭제 | `Employee.php` | `phonebook_delete` | `idx`, `csrf_token` | `data.phonebook_id` |

### 1.4 `holiday` (휴가)

| 기능 | endpoint | todo | 요청 필드 | 응답 필드 |
|---|---|---|---|---|
| 목록 | `Employee.php` | `holiday_list` | `employee_idx`, `status`, `start_date`, `end_date`, `limit` | `data.items[]` (`idx`, `employee_idx`, `employee_name`, `holiday_type`, `start_date`, `end_date`, `reason`, `status`) |
| 신청 | `Employee.php` | `save_holiday` | `employee_idx`, `holiday_type`, `start_date`, `end_date`, `reason`, `csrf_token` | `data.item.idx`, `data.item.status` |
| 상태변경 | `Employee.php` | `holiday_approve` / `holiday_reject` / `holiday_cancel` | `holiday_id`, `csrf_token` | `data.item.status` |

### 1.5 `work_overtime` (초과근무)

| 기능 | endpoint | todo | 요청 필드 | 응답 필드 |
|---|---|---|---|---|
| 목록 | `Employee.php` | `overtime_list` | `employee_idx`, `status`, `start_date`, `end_date`, `limit` | `data.items[]` (`idx`, `employee_idx`, `employee_name`, `work_date`, `start_time`, `end_time`, `minutes`, `reason`, `status`) |
| 신청 | `Employee.php` | `save_overtime` | `employee_idx`, `work_date`, `start_time`, `end_time`, `reason`, `csrf_token` | `data.item.idx`, `data.item.status` |
| 상태변경 | `Employee.php` | `overtime_approve` / `overtime_reject` / `overtime_cancel` | `overtime_id`, `csrf_token` | `data.item.status` |

### 1.6 `attitude` (근태)

| 기능 | endpoint | todo | 요청 필드 | 응답 필드 |
|---|---|---|---|---|
| 목록 | `Employee.php` | `attendance_list` | `employee_idx`, `start_date`, `end_date`, `limit` | `data.items[]` (`idx`, `employee_idx`, `employee_name`, `work_date`, `check_in`, `check_out`, `work_minutes`, `status`, `note`) |
| 저장(관리자) | `Employee.php` | `attendance_save` | `employee_idx`, `work_date`, `check_in`, `check_out`, `status`, `note`, `csrf_token` | `data.item` |

## 2) Placeholder 화면 바인딩 우선안

### 2.1 `chat` (P0)

| 기능 | endpoint | todo | 필수 필드 |
|---|---|---|---|
| 방목록 | `Chat.php` | `room_list` | - |
| 방생성 | `Chat.php` | `room_create` | `room_name`, `room_type`, `member_ids`, `csrf_token` |
| 메시지목록 | `Chat.php` | `message_list` | `room_idx`, `last_idx`, `limit` |
| 메시지전송 | `Chat.php` | `message_send` | `room_idx`, `message_text`, `csrf_token` |
| 읽음처리 | `Chat.php` | `mark_read` | `room_idx`, `last_message_idx`, `csrf_token` |
| 미읽음카운트 | `Chat.php` | `unread_count` | - |

### 2.2 `approval_req` / `approval_write` (P0)

| 기능 | endpoint | todo | 필수 필드 |
|---|---|---|---|
| 대기함 목록 | `Approval.php` | `approval_req` | - |
| 문서상세 | `Approval.php` | `approval_detail` | `doc_id` |
| 초안작성 | `Approval.php` | `approval_write` | `title`, `body_text`, `doc_type`, `approver_ids`, `csrf_token` |
| 상신 | `Approval.php` | `approval_submit` | `doc_id`, `csrf_token` |
| 승인/반려 | `Approval.php` | `approval_approve` / `approval_reject` | `doc_id`, `comment`, `csrf_token` |
| 코멘트 | `Approval.php` | `approval_comment` | `doc_id`, `comment`, `csrf_token` |

### 2.3 `approval_done` / `doc_all` / `approval_official` (P1~P2)

| 화면 | endpoint | todo | 필터 키 |
|---|---|---|---|
| 완료함 | `Approval.php` | `approval_done` | `limit` |
| 문서함 | `Approval.php` | `doc_all` | `status`, `doc_type`, `search`, `limit` |
| 공문서함 | `Approval.php` | `approval_official` | `status`, `search`, `limit` |

## 3) 공통 프런트 처리 규칙

1. 쓰기 요청 전 CSRF 재발급
- `GET dist_process/saas/Auth.php?todo=csrf`
- 발급 토큰을 해당 요청 `csrf_token`으로 첨부

2. 성공 판정 기준
- `res && res.status === 'OK'` (현재 뷰 코드 패턴 유지)

3. 에러 분기 권장
- `403`: 권한/CSRF 문제
- `409`: 상태 충돌(이미 처리됨)
- `422`: 필수값 누락/형식 오류

4. 상태 버튼 노출
- 휴가/초과근무: `REQUESTED`에서만 승인/반려/취소 버튼 표시
- 결재: `SUBMITTED` + 현재 결재 차수에서만 승인/반려 표시

## 4) 병렬 검증 스크립트

- 파일: `scripts/grp_front_api_check.sh`
- 용도: 프런트 화면 연동 직전에 read-only 계약 확인
- 실행:

```bash
bash scripts/grp_front_api_check.sh "https://shvq.kr/SHVQ_V2" ./cookies.txt
```

- 포함 체크:
  - `emp`, `org_chart`, `org_chart_card`, `holiday`, `work_overtime`, `attitude`
  - placeholder 백엔드 준비도: `chat`, `approval_req`, `approval_done`, `doc_all`, `approval_official`
