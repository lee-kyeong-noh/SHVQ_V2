<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/GroupwareService.php';
require_once __DIR__ . '/../../dist_library/saas/storage/StorageDriver.php';
require_once __DIR__ . '/../../dist_library/saas/storage/StorageService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $service = new GroupwareService(DbConnection::get(), $security);

    $todo = trim((string)($_POST['todo'] ?? $_GET['todo'] ?? 'summary'));
    if ($todo === '') {
        $todo = 'summary';
    }

    $scope = $service->resolveScope(
        $context,
        (string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''),
        (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0)
    );

    $requiredTables = $service->requiredTablesByDomain('employee');
    $missingTables = $service->missingTables($requiredTables);
    if ($missingTables !== []) {
        ApiResponse::error('GROUPWARE_SCHEMA_NOT_READY', 'groupware employee tables are not ready', 503, [
            'missing_tables' => $missingTables,
            'required_tables' => $requiredTables,
        ]);
        exit;
    }

    $writeTodos = [
        'dept_insert',
        'dept_update',
        'dept_delete',
        'dept_reorder',
        'dept_move',
        'branch_update_info',
        'insert_employee',
        'update_employee',
        'delete_employee',
        'toggle_hidden',
        'save_settings',
        'upload_photo',
        'phonebook_save',
        'phonebook_insert',
        'phonebook_update',
        'phonebook_delete',
        'insert_card',
        'update_card',
        'delete_card',
        'save_attitude',
        'attendance_save',
        'save_holiday',
        'holiday_approve',
        'holiday_reject',
        'holiday_cancel',
        'save_overtime',
        'overtime_approve',
        'overtime_reject',
        'overtime_cancel',
    ];

    if (in_array($todo, $writeTodos, true)) {
        $session = new SessionManager($security);
        $csrf = new CsrfService($session, $security);
        if (!$csrf->validateFromRequest()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    $userPk = (int)($context['user_pk'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);

    if (in_array($todo, ['summary', 'emp_summary', 'home_summary'], true)) {
        $summary = $service->dashboardSummary($scope, $userPk);
        ApiResponse::success($summary, 'OK', 'groupware dashboard summary loaded');
        exit;
    }

    if (in_array($todo, ['dept_list', 'org_chart'], true)) {
        $rows = $service->listDepartments($scope);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'department list loaded');
        exit;
    }

    if ($todo === 'dept_insert' || $todo === 'dept_update') {
        $payload = $_POST + $_GET;
        if ($todo === 'dept_update' && !isset($payload['idx']) && isset($payload['dept_id'])) {
            $payload['idx'] = $payload['dept_id'];
        }

        $saved = $service->saveDepartment($scope, $payload, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'department saved');
        exit;
    }

    if ($todo === 'dept_delete') {
        /* authority_idx: 1=최고관리자, 2=관리자, 3=부서장, 4=일반사원 (낮을수록 높은 권한) */
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $deptId = (int)($_POST['dept_id'] ?? $_POST['idx'] ?? $_GET['dept_id'] ?? $_GET['idx'] ?? 0);
        if ($deptId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'dept_id is required', 422);
            exit;
        }

        $ok = $service->deleteDepartment($scope, $deptId, $userPk);
        if (!$ok) {
            ApiResponse::error('DEPARTMENT_NOT_FOUND', 'department not found', 404, ['dept_id' => $deptId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'dept_id' => $deptId,
        ], 'OK', 'department deleted');
        exit;
    }

    if ($todo === 'dept_reorder') {
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $orderedIds = $_POST['ordered_ids'] ?? $_POST['dept_ids'] ?? $_GET['ordered_ids'] ?? $_GET['dept_ids'] ?? [];
        $parentIdx = $_POST['parent_idx'] ?? $_GET['parent_idx'] ?? null;
        $parentIdx = ($parentIdx === null || trim((string)$parentIdx) === '')
            ? null
            : (int)$parentIdx;

        $count = $service->reorderDepartments($scope, $orderedIds, $userPk, $parentIdx);

        ApiResponse::success([
            'scope' => $scope,
            'reordered_count' => $count,
            'parent_idx' => $parentIdx,
        ], 'OK', 'department reordered');
        exit;
    }

    if ($todo === 'dept_move') {
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $deptId = (int)($_POST['dept_id'] ?? $_POST['idx'] ?? $_GET['dept_id'] ?? $_GET['idx'] ?? 0);
        if ($deptId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'dept_id is required', 422);
            exit;
        }

        $parentIdx = (int)($_POST['parent_idx'] ?? $_GET['parent_idx'] ?? 0);
        $saved = $service->moveDepartment($scope, $deptId, $parentIdx, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'department moved');
        exit;
    }

    if ($todo === 'branch_update_info') {
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $deptId = (int)($_POST['dept_id'] ?? $_POST['idx'] ?? $_GET['dept_id'] ?? $_GET['idx'] ?? 0);
        if ($deptId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'dept_id is required', 422);
            exit;
        }

        $saved = $service->updateDepartmentBranchInfo($scope, $deptId, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'department branch info updated');
        exit;
    }

    if ($todo === 'get_settings') {
        $settingGroup = (string)($_GET['setting_group'] ?? $_POST['setting_group'] ?? 'groupware');
        $data = $service->getSettings($scope, $settingGroup);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $data,
        ], 'OK', 'settings loaded');
        exit;
    }

    if ($todo === 'save_settings') {
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $saved = $service->saveSettings($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'settings saved');
        exit;
    }

    if (in_array($todo, ['employee_list', 'org_employee_list'], true)) {
        $rows = $service->listEmployees($scope, [
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'dept_idx' => (int)($_GET['dept_idx'] ?? $_POST['dept_idx'] ?? 0),
            'unassigned' => (string)($_GET['unassigned'] ?? $_POST['unassigned'] ?? '0'),
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'employee list loaded');
        exit;
    }

    if (in_array($todo, ['employee_hidden_list', 'hidden_employee_list'], true)) {
        /* authority_idx <= 2 (관리자+)만 숨김 직원 목록 조회 가능 */
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $rows = $service->listHiddenEmployees($scope, [
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'dept_idx' => (int)($_GET['dept_idx'] ?? $_POST['dept_idx'] ?? 0),
            'unassigned' => (string)($_GET['unassigned'] ?? $_POST['unassigned'] ?? '0'),
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'hidden employee list loaded');
        exit;
    }

    if (in_array($todo, ['employee_detail', 'get_employee'], true)) {
        $employeeId = (int)($_GET['employee_id'] ?? $_GET['idx'] ?? $_POST['employee_id'] ?? $_POST['idx'] ?? 0);
        if ($employeeId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'employee_id is required', 422);
            exit;
        }

        $row = $service->getEmployeeById($scope, $employeeId);
        if ($row === null) {
            ApiResponse::error('EMPLOYEE_NOT_FOUND', 'employee not found', 404, ['employee_id' => $employeeId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'item' => $row,
        ], 'OK', 'employee loaded');
        exit;
    }

    if ($todo === 'insert_employee' || $todo === 'update_employee') {
        $payload = $_POST + $_GET;
        if ($todo === 'update_employee' && !isset($payload['idx']) && isset($payload['employee_id'])) {
            $payload['idx'] = $payload['employee_id'];
        }

        $saved = $service->saveEmployee($scope, $payload, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'employee saved');
        exit;
    }

    if ($todo === 'delete_employee') {
        /* authority_idx <= 2 (관리자+)만 직원 삭제 가능 */
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $employeeId = (int)($_POST['idx'] ?? $_POST['employee_id'] ?? $_GET['idx'] ?? $_GET['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'idx is required', 422);
            exit;
        }

        $ok = $service->deleteEmployee($scope, $employeeId, $userPk);
        if (!$ok) {
            ApiResponse::error('EMPLOYEE_NOT_FOUND', 'employee not found', 404, ['employee_id' => $employeeId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'employee_id' => $employeeId,
        ], 'OK', 'employee deleted');
        exit;
    }

    if ($todo === 'toggle_hidden') {
        /* authority_idx <= 2 (관리자+)만 숨김 처리 가능 */
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 2,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $employeeId = (int)($_POST['employee_id'] ?? $_POST['idx'] ?? $_GET['employee_id'] ?? $_GET['idx'] ?? 0);
        if ($employeeId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'employee_id is required', 422);
            exit;
        }

        $hiddenRaw = $_POST['hidden'] ?? $_POST['is_hidden'] ?? $_GET['hidden'] ?? $_GET['is_hidden'] ?? null;
        if ($hiddenRaw === null || trim((string)$hiddenRaw) === '') {
            ApiResponse::error('INVALID_INPUT', 'hidden is required', 422);
            exit;
        }

        $hiddenToken = strtolower(trim((string)$hiddenRaw));
        $hidden = in_array($hiddenToken, ['1', 'true', 'y', 'yes', 'on'], true);

        $saved = $service->toggleHidden($scope, $employeeId, $hidden, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'employee_id' => $employeeId,
            'is_hidden' => $hidden ? 1 : 0,
            'item' => $saved,
        ], 'OK', 'employee hidden state updated');
        exit;
    }

    if ($todo === 'upload_photo') {
        $tenantId = (int)($scope['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            ApiResponse::error('INVALID_SCOPE', 'tenant_id is required for upload', 422);
            exit;
        }

        $employeeId = (int)($_POST['employee_id'] ?? $_POST['employee_idx'] ?? $_POST['idx'] ?? $_GET['employee_id'] ?? $_GET['employee_idx'] ?? $_GET['idx'] ?? 0);

        $uploadFile = null;
        foreach (['photo', 'employee_photo', 'file', 'upload'] as $field) {
            if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
                $uploadFile = $_FILES[$field];
                break;
            }
        }

        if (!is_array($uploadFile)) {
            ApiResponse::error('INVALID_INPUT', 'photo file is required', 422);
            exit;
        }

        $prefix = $employeeId > 0
            ? 'employee_' . $employeeId
            : 'employee_tmp_' . max(1, $userPk);

        $storage = StorageService::forTenant($tenantId);
        $uploaded = $storage->put('employee', $uploadFile, $prefix);
        $photoUrl = (string)($uploaded['url'] ?? '');

        $saved = null;
        if ($employeeId > 0) {
            $saved = $service->updateEmployeePhoto($scope, $employeeId, $photoUrl, $userPk);
        }

        ApiResponse::success([
            'scope' => $scope,
            'employee_id' => $employeeId > 0 ? $employeeId : 0,
            'photo_url' => $photoUrl,
            'item' => $saved,
            'file' => $uploaded,
        ], 'OK', 'employee photo uploaded');
        exit;
    }

    if (in_array($todo, ['phonebook_list', 'org_chart_card'], true)) {
        $rows = $service->listPhoneBook($scope, [
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'phonebook list loaded');
        exit;
    }

    if (in_array($todo, ['phonebook_save', 'phonebook_insert', 'phonebook_update'], true)) {
        $saved = $service->savePhoneBook($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'phonebook saved');
        exit;
    }

    if ($todo === 'phonebook_delete') {
        $rowId = (int)($_POST['phonebook_id'] ?? $_POST['idx'] ?? $_GET['phonebook_id'] ?? $_GET['idx'] ?? 0);
        if ($rowId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'phonebook_id is required', 422);
            exit;
        }

        $ok = $service->deletePhoneBook($scope, $rowId, $userPk);
        if (!$ok) {
            ApiResponse::error('PHONEBOOK_NOT_FOUND', 'phonebook row not found', 404, ['phonebook_id' => $rowId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'phonebook_id' => $rowId,
        ], 'OK', 'phonebook deleted');
        exit;
    }

    if ($todo === 'card_list') {
        $rows = $service->listEmployeeCards($scope, [
            'employee_idx' => (int)($_GET['employee_idx'] ?? $_POST['employee_idx'] ?? 0),
            'search' => (string)($_GET['search'] ?? $_POST['search'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'employee card list loaded');
        exit;
    }

    if (in_array($todo, ['insert_card', 'update_card'], true)) {
        $saved = $service->saveEmployeeCard($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'employee card saved');
        exit;
    }

    if ($todo === 'delete_card') {
        $rowId = (int)($_POST['card_id'] ?? $_POST['idx'] ?? $_GET['card_id'] ?? $_GET['idx'] ?? 0);
        if ($rowId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'card_id is required', 422);
            exit;
        }

        $ok = $service->deleteEmployeeCard($scope, $rowId, $userPk);
        if (!$ok) {
            ApiResponse::error('CARD_NOT_FOUND', 'employee card not found', 404, ['card_id' => $rowId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'card_id' => $rowId,
        ], 'OK', 'employee card deleted');
        exit;
    }

    if (in_array($todo, ['attitude_list', 'attendance_list'], true)) {
        $rows = $service->listAttendance($scope, [
            'employee_idx' => (int)($_GET['employee_idx'] ?? $_POST['employee_idx'] ?? 0),
            'start_date' => (string)($_GET['start_date'] ?? $_POST['start_date'] ?? ''),
            'end_date' => (string)($_GET['end_date'] ?? $_POST['end_date'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'attendance list loaded');
        exit;
    }

    if (in_array($todo, ['save_attitude', 'attendance_save'], true)) {
        $saved = $service->saveAttendance($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'attendance saved');
        exit;
    }

    if ($todo === 'holiday_list') {
        $rows = $service->listHoliday($scope, [
            'employee_idx' => (int)($_GET['employee_idx'] ?? $_POST['employee_idx'] ?? 0),
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'start_date' => (string)($_GET['start_date'] ?? $_POST['start_date'] ?? ''),
            'end_date' => (string)($_GET['end_date'] ?? $_POST['end_date'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'holiday list loaded');
        exit;
    }

    if ($todo === 'save_holiday') {
        $saved = $service->saveHoliday($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'holiday saved');
        exit;
    }

    if (in_array($todo, ['holiday_approve', 'holiday_reject', 'holiday_cancel'], true)) {
        /* authority_idx <= 3 (부서장+)만 휴가 결재 가능 */
        if (in_array($todo, ['holiday_approve', 'holiday_reject'], true) && $roleLevel > 3) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 3,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $holidayId = (int)($_POST['holiday_id'] ?? $_POST['idx'] ?? $_GET['holiday_id'] ?? $_GET['idx'] ?? 0);
        if ($holidayId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'holiday_id is required', 422);
            exit;
        }

        $statusMap = [
            'holiday_approve' => 'APPROVED',
            'holiday_reject' => 'REJECTED',
            'holiday_cancel' => 'CANCELED',
        ];
        $status = $statusMap[$todo];

        $saved = $service->updateHolidayStatus($scope, $holidayId, $status, $userPk, $roleLevel);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'holiday status updated');
        exit;
    }

    if (in_array($todo, ['overtime_list', 'work_overtime_list'], true)) {
        $rows = $service->listOvertime($scope, [
            'employee_idx' => (int)($_GET['employee_idx'] ?? $_POST['employee_idx'] ?? 0),
            'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
            'start_date' => (string)($_GET['start_date'] ?? $_POST['start_date'] ?? ''),
            'end_date' => (string)($_GET['end_date'] ?? $_POST['end_date'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? $_POST['limit'] ?? 200),
        ]);

        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'overtime list loaded');
        exit;
    }

    if ($todo === 'save_overtime') {
        $saved = $service->saveOvertime($scope, $_POST + $_GET, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'overtime saved');
        exit;
    }

    if (in_array($todo, ['overtime_approve', 'overtime_reject', 'overtime_cancel'], true)) {
        /* authority_idx <= 3 (부서장+)만 초과근무 결재 가능 */
        if (in_array($todo, ['overtime_approve', 'overtime_reject'], true) && $roleLevel > 3) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, [
                'required_max' => 3,
                'current' => $roleLevel,
            ]);
            exit;
        }

        $overtimeId = (int)($_POST['overtime_id'] ?? $_POST['idx'] ?? $_GET['overtime_id'] ?? $_GET['idx'] ?? 0);
        if ($overtimeId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'overtime_id is required', 422);
            exit;
        }

        $statusMap = [
            'overtime_approve' => 'APPROVED',
            'overtime_reject' => 'REJECTED',
            'overtime_cancel' => 'CANCELED',
        ];
        $status = $statusMap[$todo];

        $saved = $service->updateOvertimeStatus($scope, $overtimeId, $status, $userPk, $roleLevel);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'overtime status updated');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    $message = $e->getMessage();

    if (str_contains($message, 'not found')) {
        ApiResponse::error('NOT_FOUND', $message, 404);
        exit;
    }

    if (str_contains($message, 'forbidden') || str_contains($message, 'insufficient role')) {
        ApiResponse::error('FORBIDDEN', $message, 403);
        exit;
    }

    ApiResponse::error('CONFLICT', $message, 409);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
