# SHVQ_V2 API TODO/ACTION 인벤토리 (BL-01)

- 작성일: 2026-04-13
- 범위: `dist_process/saas/*.php` 19개 엔드포인트
- 기준: 코드 정적 스캔(`$todo` 직접비교, `in_array($todo, [...])`, `*Todos` 배열) + 수동 보정(AuthAudit=`list`, ShadowAudit=`summary`)

## 1) 집계 요약

- API 파일 수: 19
- TODO 별칭(alias) 총계: 180
- TODO 고유값(중복 제거): 161
- WRITE 경로(POST+CSRF 또는 CSRF) 포함 API: 12

## 2) 파일별 실행 인벤토리

| API | TODO/ACTION 인벤토리 | 핵심 메서드/처리 | 권한/보안 게이트 | 필수 파라미터(대표) |
|---|---|---|---|---|
| `dist_process/saas/Auth.php` | `csrf`, `login`, `remember_session`, `cad_token_issue`, `cad_token_verify`, `logout` | `AuthService` (`csrfToken/login/restoreFromRememberCookie/issueCadToken/verifyCadToken/logout`) | 인증 전 처리(`csrf/login`) 허용, 미지원 todo 차단 | `login_id`, `password`, `csrf_token`, `token` |
| `dist_process/saas/AuthAudit.php` | `list` | `AuthAuditService::list` | 로그인 필수, `role_level >= security.auth_audit.min_role_level(기본 4)` | `page`, `limit`, `login_id`, `action_key`, `result_code`, `user_pk`, `from_at`, `to_at` |
| `dist_process/saas/Dashboard.php` | `summary` | `DashboardService::summary` | 로그인 필수, `service_code/tenant_id`는 role에 따라 context 강제 | (선택) `service_code`, `tenant_id` |
| `dist_process/saas/Notification.php` | `summary`, `queue_list`, `delivery_list` | `NotificationService` (`summary/queueList/deliveryList`) | 로그인 필수, role에 따라 scope 강제 | `status`, `channel`, `result_code`, `page`, `limit` |
| `dist_process/saas/IntegrationMail.php` | `summary`, `account_list`, `checkpoint_list`, `error_queue_list` | `IntegrationService` (`summary/accountList/checkpointList/errorQueueList`) | 로그인 필수, role에 따라 scope 강제 | `status`, `job_type`, `page`, `limit` |
| `dist_process/saas/IntegrationIot.php` | `summary`, `account_list`, `checkpoint_list`, `error_queue_list` | `IntegrationService` (`summary/accountList/checkpointList/errorQueueList`) | 로그인 필수, role에 따라 scope 강제 | `status`, `job_type`, `page`, `limit` |
| `dist_process/saas/Tenant.php` | `list_tenants`, `list`, `get_tenant`, `get`, `tenant_users`, `users`, `create_tenant`, `update_tenant_status`, `assign_tenant_user`, `init_default` | `TenantService` (`list/get/listUsers/create/updateStatus/assign/initDefault`) | 로그인 + `role_level >= 5`; write todo CSRF 검증 | `tenant_id`, `tenant_code`, `tenant_name`, `status`, `user_idx` |
| `dist_process/saas/Platform.php` | `shadow_queue_stats`, `shadow_queue_list`, `shadow_queue_get`, `shadow_wave1_matrix`, `shadow_queue_requeue`, `shadow_queue_resolve`, `shadow_queue_insert_test`, `shadow_queue_monitor`, `shadow_queue_replay_one`, `shadow_queue_replay_batch` | `ShadowWriteQueueService`, `ShadowReplayService`, `Wave1ApiMatrix` | 로그인 + `min_role_level`(기본4), 운영/재생성 일부는 `role_level >= 5`, write todo CSRF 검증 | `idx`, `note`, `limit`, `status`, `tenant_id` |
| `dist_process/saas/ShadowAudit.php` | `summary` | `ShadowAuditSummaryService::summary` | 로그인 + `role_level >= 5` | `days`, `service_code`, `tenant_id` |
| `dist_process/saas/Mail.php` | `folder_list`, `mail_list`, `mail_detail`, `mail_send_policy`, `mail_send`, `mail_draft_save`, `mail_draft_list`, `mail_draft_delete`, `mail_delete`, `mail_mark_read`, `account_list`, `account_save`, `account_delete`, `account_test` | `MailboxService`, `MailComposeService`, `MailAccountService` | 로그인 필수, write todo는 POST+CSRF, mail write는 `role>=2`, account write는 `role>=4` | `account_idx`, `to`, `subject`, `draft_id`, `uid_list`, `id_list` |
| `dist_process/saas/Material.php` | `material_list`, `list`, `material_detail`, `detail`, `view`, `material_create`, `create`, `insert`, `material_update`, `update`, `edit`, `material_delete`, `delete`, `remove` | `MaterialService` (`list/detail/create/update/deleteByIds`) | 로그인 필수, write todo POST+CSRF, write `role>=2` | `idx`, `item_idx`, `idx_list` |
| `dist_process/saas/MaterialSettings.php` | `material_settings_get`, `settings_get`, `get`, `material_settings_save`, `settings_save`, `save`, `material_settings_save_pjt_items`, `save_pjt_items`, `material_settings_save_category_option_labels`, `save_category_option_labels` | `MaterialSettingsService` (`get/save/savePjtItems/saveCategoryOptionLabels`) | 로그인 필수, write todo POST+CSRF, write `role>=2` | `pjt_items`, `category_option_labels` |
| `dist_process/saas/Stock.php` | `stock_status`, `status_list`, `stock_in`, `stock_out`, `stock_transfer`, `stock_adjust`, `stock_log`, `log_list`, `stock_settings_get`, `settings_get`, `stock_settings_save`, `save_settings`, `branch_list`, `item_search`, `site_search`, `item_stock_detail` | `StockService` (`stockStatus/stockIn/stockOut/stockTransfer/stockAdjust/stockLog/...`) | 로그인 필수, write todo POST+CSRF, write `role>=2` | `item_idx`, `q`, `tab_idx`, `limit` |
| `dist_process/saas/Site.php` | `list`, `detail`, `search`, `insert`, `update`, `delete`, `est_list`, `est_detail`, `insert_est`, `update_est`, `delete_estimate`, `copy_est`, `recalc_est`, `upsert_est_items`, `update_est_item`, `delete_est_item`, `approve_est`, `est_pdf_data` | 파일 내부 트랜잭션 처리(DB 직접 처리) | 로그인 필수, write todo POST+CSRF, write `role>=2` | `idx/site_idx`, `estimate_idx`, `idx_list` |
| `dist_process/saas/HeadOffice.php` | `list`, `detail`, `check_dup`, `insert`, `update`, `bulk_update`, `restore`, `delete_attach`, `delete` | 파일 내부 트랜잭션 처리(DB 직접 처리) | 로그인 필수, write todo POST+CSRF, `insert/update/bulk_update role>=2`, `restore/delete role>=4` | `idx`, `name`, `idx_list` |
| `dist_process/saas/Member.php` | `list`, `detail`, `check_dup`, `insert`, `update`, `update_branch_settings`, `member_inline_update`, `member_delete`, `restore`, `member_bulk_action` | 파일 내부 트랜잭션 처리(DB 직접 처리) | 로그인 필수, write todo POST+CSRF, `insert/update role>=2`, `member_delete/restore/bulk role>=4` | `idx`, `field`, `value`, `idx_list` |
| `dist_process/saas/Employee.php` | `summary`, `emp_summary`, `home_summary`, `dept_list`, `org_chart`, `dept_insert`, `dept_update`, `dept_delete`, `employee_list`, `org_employee_list`, `employee_detail`, `get_employee`, `insert_employee`, `update_employee`, `phonebook_list`, `org_chart_card`, `phonebook_save`, `phonebook_insert`, `phonebook_update`, `phonebook_delete`, `attitude_list`, `attendance_list`, `save_attitude`, `attendance_save`, `holiday_list`, `save_holiday`, `holiday_approve`, `holiday_reject`, `holiday_cancel`, `overtime_list`, `work_overtime_list`, `save_overtime`, `overtime_approve`, `overtime_reject`, `overtime_cancel` | `GroupwareService` (부서/직원/전화번호/근태/연차/초과근무 처리) | 로그인 필수, write todo CSRF, 승인/반려 일부 `role>=4` | `dept_id`, `employee_id`, `phonebook_id`, `holiday_id`, `overtime_id` |
| `dist_process/saas/Approval.php` | `approval_req`, `list_req`, `approval_done`, `list_done`, `doc_all`, `list_all`, `approval_official`, `list_official`, `approval_detail`, `doc_detail`, `approval_write`, `draft_create`, `approval_submit`, `approval_approve`, `approval_reject`, `approval_cancel`, `approval_comment`, `add_comment` | `GroupwareService` (`list/create/submit/action/comment`) + action map(`approval_approve->approve`, `approval_reject->reject`, `approval_cancel->cancel`) | 로그인 필수, write todo CSRF | `doc_id`, `comment` |
| `dist_process/saas/Chat.php` | `room_list`, `chat_rooms`, `room_detail`, `chat_room_detail`, `room_create`, `chat_room_create`, `room_join`, `chat_room_join`, `room_leave`, `chat_room_leave`, `message_list`, `chat_messages`, `message_send`, `chat_send`, `message_delete`, `chat_delete`, `mark_read`, `chat_read`, `unread_count`, `chat_unread_count` | `GroupwareService` (채팅방/메시지/읽음 처리) | 로그인 필수, write todo CSRF | `room_idx`, `target_user_idx`, `message_idx`, `last_message_idx` |

## 3) BL-01 완료 체크

- [x] 파일별 `todo/action` 목록 정리
- [x] 파일별 핵심 처리 메서드 정리
- [x] 권한/CSRF/HTTP 메서드 게이트 정리
- [x] 대표 필수 파라미터 정리

## 4) 후속 연계 (BL-05 연결 포인트)

- BL-05(파라미터 정규화)에서 본 문서의 `필수 파라미터(대표)`를 기준으로 타입/필수/기본값/검증규칙을 상세화한다.
