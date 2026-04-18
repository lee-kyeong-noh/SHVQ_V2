<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

final class ShadowAuditSummaryService
{
    private PDO $db;
    private string $queueTableName;
    private string $queueTableSql;
    private string $provider;
    private string $jobType;

    private array $tableExistsCache = [];
    private array $tableCountCache = [];

    private const API_TABLE_MAP = [
        'dist_process/Site.php' => [
            'default' => 'Tb_Site',
            'todos' => [
                'insert' => 'Tb_Site',
                'update' => 'Tb_Site',
                'delete' => 'Tb_Site',
                'insert_est' => 'Tb_SiteEstimate',
                'update_est' => 'Tb_SiteEstimate',
                'delete_estimate' => 'Tb_SiteEstimate',
                'copy_est' => 'Tb_SiteEstimate',
                'recalc_est' => 'Tb_SiteEstimate',
                'approve_est' => 'Tb_SiteEstimate',
                'upsert_est_items' => 'Tb_EstimateItem',
                'update_est_item' => 'Tb_EstimateItem',
                'delete_est_item' => 'Tb_EstimateItem',
                'bill_insert' => 'Tb_Bill',
                'bill_update' => 'Tb_Bill',
                'bill_delete' => 'Tb_Bill',
            ],
        ],
        'dist_process/Material.php' => [
            'default' => 'Tb_Item',
            'todos' => [
                'item_insert' => 'Tb_Item',
                'item_update' => 'Tb_Item',
                'item_inline_update' => 'Tb_Item',
                'item_delete' => 'Tb_Item',
                'delete_items' => 'Tb_Item',
            ],
        ],
        'dist_process/Stock.php' => [
            'default' => 'Tb_StockLog',
            'todos' => [
                'stock_in' => 'Tb_StockLog',
                'stock_out' => 'Tb_StockLog',
                'stock_transfer' => 'Tb_StockLog',
                'stock_adjust' => 'Tb_StockLog',
            ],
        ],
        'dist_process/Member.php' => [
            'default' => 'Tb_Members',
            'todos' => [
                'insert' => 'Tb_Members',
                'update' => 'Tb_Members',
                'update_branch_settings' => 'Tb_Members',
                'member_inline_update' => 'Tb_Members',
                'member_delete' => 'Tb_Members',
                'restore' => 'Tb_Members',
            ],
        ],
        'dist_process/HeadOffice.php' => [
            'default' => 'Tb_HeadOffice',
            'todos' => [
                'insert' => 'Tb_HeadOffice',
                'update' => 'Tb_HeadOffice',
                'bulk_update' => 'Tb_HeadOffice',
                'restore' => 'Tb_HeadOffice',
                'delete' => 'Tb_HeadOffice',
                'delete_attach' => 'Tb_FileAttach',
            ],
        ],
        'dist_process/Employee.php' => [
            'default' => 'Tb_Employee',
            'todos' => [
                'insert_employee' => 'Tb_Employee',
                'update_employee' => 'Tb_Employee',
            ],
        ],
        'dist_process/Settings.php' => [
            'default' => 'Tb_UserSettings',
            'todos' => [
                'dept_insert' => 'Tb_Department',
                'dept_update' => 'Tb_Department',
                'dept_delete' => 'Tb_Department',
                'dept_move' => 'Tb_Department',
                'dept_reorder' => 'Tb_Department',
                'branch_update_info' => 'Tb_Members',
                'save_org_ceo' => 'Tb_PjtSettings',
                'save_pjt_setting' => 'Tb_PjtSettings',
                'save_pjt_status' => 'Tb_PjtSettings',
                'save_pjt_req_config' => 'Tb_PjtSettings',
                'save_pjt_phase_presets' => 'Tb_PjtSettings',
                'save_pjt_phase_perm' => 'Tb_PjtSettings',
                'save_pjt_phase_names' => 'Tb_PjtSettings',
                'save_pjt_types' => 'Tb_PjtSettings',
                'change_password' => 'Tb_Users',
                'save_my_settings' => 'Tb_UserSettings',
                'save_dashboard_layout' => 'Tb_UserSettings',
                'save_mail_my_settings' => 'Tb_UserSettings',
                'update_idle_enabled' => 'Tb_UserSettings',
                'upload_signature' => 'Tb_UserSettings',
                'upload_seal' => 'Tb_UserSettings',
                'save_settings' => 'Tb_UserSettings',
                'save_company_info' => 'Tb_UserSettings',
                'save_collect_settings' => 'Tb_UserSettings',
                'save_site_contact_req' => 'Tb_UserSettings',
                'save_pjt_required' => 'Tb_UserSettings',
                'save_cat_options' => 'Tb_UserSettings',
                'save_item_property' => 'Tb_UserSettings',
                'save_member_required' => 'Tb_UserSettings',
                'save_member_regions' => 'Tb_UserSettings',
                'save_emp_positions' => 'Tb_UserSettings',
                'save_barcode_settings' => 'Tb_UserSettings',
            ],
        ],
        'dist_process/Purchase.php' => [
            'default' => 'Tb_Product_Purchase',
            'todos' => [
                'update_field'        => 'Tb_Product_Purchase',
                'product_confirm'     => 'Tb_Product_Purchase',
                'payment_confirm'     => 'Tb_Product_Purchase',
                'set_all'             => 'Tb_Product_Purchase',
                'delete'              => 'Tb_Product_Purchase',
                'insert_subcontract'  => 'Tb_Product_Contract',
                'update_subcontract'  => 'Tb_Product_Contract',
                'delete_subcontract'  => 'Tb_Product_Contract',
                'subcontract_status'  => 'Tb_Product_Contract',
                'subcontract_comment' => 'Tb_Product_Contract',
                'insert_company'         => 'Tb_Company',
                'update_company'         => 'Tb_Company',
                'delete_company'         => 'Tb_Company',
                'insert_company_contact' => 'Tb_PhoneBook',
                'update_company_contact' => 'Tb_PhoneBook',
                'delete_company_contact' => 'Tb_PhoneBook',
                'upload_company_file'    => 'Tb_FileAttach',
                'delete_company_file'    => 'Tb_FileAttach',
                'subcontract_upload'     => 'Tb_FileAttach',
                'delete_subcontract_file'=> 'Tb_FileAttach',
            ],
        ],
        'dist_process/Sales.php' => [
            'default' => 'Tb_TaxInvoice',
            'todos' => [
                'insert_tax' => 'Tb_TaxInvoice',
                'update_tax' => 'Tb_TaxInvoice',
                'delete_tax' => 'Tb_TaxInvoice',
            ],
        ],
        'dist_process/Expense.php' => [
            'default' => 'Tb_Expense',
            'todos' => [
                'insert_expense' => 'Tb_Expense',
                'update_expense' => 'Tb_Expense',
                'delete_expense' => 'Tb_Expense',
                'delete_expenses' => 'Tb_Expense',
                'confirm_expense' => 'Tb_Expense',
                'confirm_deposit' => 'Tb_Expense',
                'complete_expense' => 'Tb_Expense',
                'upload_receipt' => 'Tb_Expense',
            ],
        ],
        'dist_process/Fund.php' => [
            'default' => 'Tb_Expenditure_Resolution',
            'todos' => [
                'update_field' => 'Tb_Expenditure_Resolution',
                'batch_matching' => 'Tb_Expenditure_Resolution',
                'batch_delete' => 'Tb_Expenditure_Resolution',
                'confirm_resolution' => 'Tb_Expenditure_Resolution',
            ],
        ],
        'dist_process/Trash.php' => [
            'default' => 'Tb_Trash',
            'todos' => [
                'soft_delete' => 'Tb_Trash',
                'restore' => 'Tb_Trash',
                'permanent_delete' => 'Tb_Trash',
                'empty_all' => 'Tb_Trash',
                'pjt_plan_restore' => 'Tb_Trash',
            ],
        ],
    ];

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $shadowCfg = $security['shadow_write'] ?? [];
        $this->queueTableName = $this->normalizeTableName((string)($shadowCfg['queue_table'] ?? 'Tb_IntErrorQueue'));
        $this->queueTableSql = '[dbo].[' . $this->queueTableName . ']';
        $this->provider = trim((string)($shadowCfg['provider_key'] ?? 'shadow'));
        if ($this->provider === '') {
            $this->provider = 'shadow';
        }
        $this->jobType = trim((string)($shadowCfg['job_type'] ?? 'shadow_write'));
        if ($this->jobType === '') {
            $this->jobType = 'shadow_write';
        }
    }

    public function summary(int $days, string $serviceCode, int $tenantId): array
    {
        if (!$this->tableExists($this->queueTableName)) {
            throw new RuntimeException('SHADOW_QUEUE_TABLE_NOT_READY');
        }

        $days = max(1, min(365, $days));
        $serviceCode = trim($serviceCode);
        if ($tenantId < 0) {
            $tenantId = 0;
        }

        $queueRows = $this->loadQueueSummary($days, $serviceCode, $tenantId);
        $queueByKey = [];
        foreach ($queueRows as $row) {
            $key = $this->buildKey((string)$row['api'], (string)$row['todo']);
            $queueByKey[$key] = $row;
        }

        $expectedRows = $this->buildExpectedRows();
        $tableCounts = $this->loadTableCounts($this->collectReferenceTables($expectedRows, $queueRows));

        $items = [];
        $mappedRows = 0;
        $unmappedRows = 0;

        foreach ($expectedRows as $expected) {
            $api = (string)$expected['api'];
            $todo = (string)$expected['todo'];
            $v1Table = (string)$expected['v1_table'];
            $key = $this->buildKey($api, $todo);

            $queue = $queueByKey[$key] ?? [
                'queue_count' => 0,
                'pending_count' => 0,
                'retrying_count' => 0,
                'failed_count' => 0,
                'resolved_count' => 0,
                'first_queued_at' => null,
                'last_queued_at' => null,
            ];
            unset($queueByKey[$key]);

            $v1Count = (int)($tableCounts[$v1Table]['count'] ?? 0);
            $queueCount = (int)($queue['queue_count'] ?? 0);
            $gap = $v1Count - $queueCount;

            $items[] = [
                'api' => $api,
                'todo' => $todo,
                'v1_table' => $v1Table,
                'v1_count' => $v1Count,
                'queue_count' => $queueCount,
                'pending_count' => (int)($queue['pending_count'] ?? 0),
                'retrying_count' => (int)($queue['retrying_count'] ?? 0),
                'failed_count' => (int)($queue['failed_count'] ?? 0),
                'resolved_count' => (int)($queue['resolved_count'] ?? 0),
                'gap' => $gap,
                'gap_status' => $gap > 0 ? 'MISSING_SUSPECT' : 'NORMAL',
                'first_queued_at' => $queue['first_queued_at'] ?? null,
                'last_queued_at' => $queue['last_queued_at'] ?? null,
                'mapped' => true,
            ];
            $mappedRows++;
        }

        foreach ($queueByKey as $queue) {
            $api = (string)($queue['api'] ?? '');
            $todo = (string)($queue['todo'] ?? '');
            $v1Table = $this->resolveV1Table($api, $todo);
            $v1Count = $v1Table === '' ? 0 : (int)($tableCounts[$v1Table]['count'] ?? 0);
            $queueCount = (int)($queue['queue_count'] ?? 0);
            $gap = $v1Count - $queueCount;

            $items[] = [
                'api' => $api,
                'todo' => $todo,
                'v1_table' => $v1Table,
                'v1_count' => $v1Count,
                'queue_count' => $queueCount,
                'pending_count' => (int)($queue['pending_count'] ?? 0),
                'retrying_count' => (int)($queue['retrying_count'] ?? 0),
                'failed_count' => (int)($queue['failed_count'] ?? 0),
                'resolved_count' => (int)($queue['resolved_count'] ?? 0),
                'gap' => $gap,
                'gap_status' => $gap > 0 ? 'MISSING_SUSPECT' : 'NORMAL',
                'first_queued_at' => $queue['first_queued_at'] ?? null,
                'last_queued_at' => $queue['last_queued_at'] ?? null,
                'mapped' => false,
            ];
            $unmappedRows++;
        }

        usort($items, static function (array $a, array $b): int {
            $mappedCompare = ((int)$b['mapped']) <=> ((int)$a['mapped']);
            if ($mappedCompare !== 0) {
                return $mappedCompare;
            }
            $apiCompare = strcmp((string)$a['api'], (string)$b['api']);
            if ($apiCompare !== 0) {
                return $apiCompare;
            }
            return strcmp((string)$a['todo'], (string)$b['todo']);
        });

        $totals = [
            'queue_count' => 0,
            'pending_count' => 0,
            'retrying_count' => 0,
            'failed_count' => 0,
            'resolved_count' => 0,
            'v1_reference_total' => 0,
        ];
        foreach ($items as $item) {
            $totals['queue_count'] += (int)($item['queue_count'] ?? 0);
            $totals['pending_count'] += (int)($item['pending_count'] ?? 0);
            $totals['retrying_count'] += (int)($item['retrying_count'] ?? 0);
            $totals['failed_count'] += (int)($item['failed_count'] ?? 0);
            $totals['resolved_count'] += (int)($item['resolved_count'] ?? 0);
        }

        foreach ($tableCounts as $tableInfo) {
            $totals['v1_reference_total'] += (int)($tableInfo['count'] ?? 0);
        }

        return [
            'scope' => [
                'days' => $days,
                'created_from' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
                'created_to' => date('Y-m-d H:i:s'),
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'provider' => $this->provider,
                'job_type' => $this->jobType,
            ],
            'totals' => $totals,
            'summary' => [
                'mapped_rows' => $mappedRows,
                'unmapped_rows' => $unmappedRows,
                'total_rows' => count($items),
            ],
            'v1_table_counts' => array_values($tableCounts),
            'items' => $items,
        ];
    }

    private function loadQueueSummary(int $days, string $serviceCode, int $tenantId): array
    {
        // sqlsrv PDO는 동일 named parameter 중복 바인딩 불가 → 조건부 WHERE 절로 처리
        $where = "provider = :provider
                  AND job_type = :job_type
                  AND created_at >= DATEADD(DAY, (0 - :days), GETDATE())";
        $params = [
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'days' => $days,
        ];

        if ($serviceCode !== '') {
            $where .= " AND service_code = :service_code";
            $params['service_code'] = $serviceCode;
        }
        if ($tenantId > 0) {
            $where .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }

        $sql = "SELECT
                    ISNULL(NULLIF(JSON_VALUE(payload_json, '$.api'), ''), '(unknown)') AS api,
                    ISNULL(NULLIF(JSON_VALUE(payload_json, '$.todo'), ''), '(unknown)') AS todo,
                    COUNT(1) AS queue_count,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'RETRYING' THEN 1 ELSE 0 END) AS retrying_count,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) AS resolved_count,
                    MIN(created_at) AS first_queued_at,
                    MAX(created_at) AS last_queued_at
                FROM {$this->queueTableSql}
                WHERE {$where}
                GROUP BY
                    ISNULL(NULLIF(JSON_VALUE(payload_json, '$.api'), ''), '(unknown)'),
                    ISNULL(NULLIF(JSON_VALUE(payload_json, '$.todo'), ''), '(unknown)')
                ORDER BY api ASC, todo ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $api = $this->normalizeApi((string)($row['api'] ?? ''));
            $todo = trim((string)($row['todo'] ?? ''));
            if ($todo === '') {
                $todo = '(unknown)';
            }

            $result[] = [
                'api' => $api,
                'todo' => $todo,
                'queue_count' => (int)($row['queue_count'] ?? 0),
                'pending_count' => (int)($row['pending_count'] ?? 0),
                'retrying_count' => (int)($row['retrying_count'] ?? 0),
                'failed_count' => (int)($row['failed_count'] ?? 0),
                'resolved_count' => (int)($row['resolved_count'] ?? 0),
                'first_queued_at' => $this->normalizeDatetime($row['first_queued_at'] ?? null),
                'last_queued_at' => $this->normalizeDatetime($row['last_queued_at'] ?? null),
            ];
        }

        return $result;
    }

    private function buildExpectedRows(): array
    {
        $rows = [];
        foreach (self::API_TABLE_MAP as $api => $config) {
            $todos = is_array($config['todos'] ?? null) ? $config['todos'] : [];
            foreach ($todos as $todo => $table) {
                $rows[] = [
                    'api' => $api,
                    'todo' => (string)$todo,
                    'v1_table' => $this->normalizeTableName((string)$table),
                ];
            }
        }

        return $rows;
    }

    private function collectReferenceTables(array $expectedRows, array $queueRows): array
    {
        $tables = [
            'Tb_Members',
            'Tb_Site',
            'Tb_SiteEstimate',
            'Tb_EstimateItem',
        ];

        foreach ($expectedRows as $row) {
            $table = $this->normalizeTableName((string)($row['v1_table'] ?? ''));
            if ($table !== '') {
                $tables[] = $table;
            }
        }

        foreach ($queueRows as $row) {
            $resolved = $this->resolveV1Table((string)($row['api'] ?? ''), (string)($row['todo'] ?? ''));
            if ($resolved !== '') {
                $tables[] = $resolved;
            }
        }

        $tables = array_values(array_unique(array_filter($tables, static fn ($table): bool => is_string($table) && $table !== '')));
        sort($tables);
        return $tables;
    }

    private function loadTableCounts(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            $tableName = $this->normalizeTableName((string)$table);
            if ($tableName === '') {
                continue;
            }
            if (!$this->tableExists($tableName)) {
                $result[$tableName] = [
                    'table' => $tableName,
                    'exists' => false,
                    'count' => 0,
                ];
                continue;
            }

            if (isset($this->tableCountCache[$tableName])) {
                $count = (int)$this->tableCountCache[$tableName];
            } else {
                $sql = 'SELECT COUNT(1) AS cnt FROM [dbo].[' . $tableName . ']';
                $stmt = $this->db->query($sql);
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
                $count = (int)($row['cnt'] ?? 0);
                $this->tableCountCache[$tableName] = $count;
            }

            $result[$tableName] = [
                'table' => $tableName,
                'exists' => true,
                'count' => $count,
            ];
        }

        return $result;
    }

    private function resolveV1Table(string $api, string $todo): string
    {
        $api = $this->normalizeApi($api);
        $todo = trim($todo);

        $config = self::API_TABLE_MAP[$api] ?? null;
        if (!is_array($config)) {
            return '';
        }

        $todos = is_array($config['todos'] ?? null) ? $config['todos'] : [];
        if ($todo !== '' && isset($todos[$todo])) {
            return $this->normalizeTableName((string)$todos[$todo]);
        }

        return $this->normalizeTableName((string)($config['default'] ?? ''));
    }

    private function normalizeApi(string $api): string
    {
        $api = trim($api);
        if ($api === '') {
            return '(unknown)';
        }

        if (isset(self::API_TABLE_MAP[$api])) {
            return $api;
        }

        if (preg_match('/^dist_process\/saas\/([A-Za-z0-9_]+\.php)$/', $api, $m) === 1) {
            $legacy = 'dist_process/' . $m[1];
            if (isset(self::API_TABLE_MAP[$legacy])) {
                return $legacy;
            }
        }

        if (preg_match('/^[A-Za-z0-9_]+\.php$/', $api) === 1) {
            $candidates = ['dist_process/' . $api, 'dist_process/saas/' . $api];
            foreach ($candidates as $prefixed) {
                if (isset(self::API_TABLE_MAP[$prefixed])) {
                    return $prefixed;
                }
            }
        }

        return $api;
    }

    private function normalizeTableName(string $table): string
    {
        $trimmed = trim($table);
        if ($trimmed === '') {
            return '';
        }

        $trimmed = preg_replace('/^\[?dbo\]?\.?/i', '', $trimmed) ?? $trimmed;
        $trimmed = str_replace(['[', ']'], '', $trimmed);

        if (preg_match('/^[A-Za-z0-9_]+$/', $trimmed) !== 1) {
            return '';
        }

        return $trimmed;
    }

    private function tableExists(string $table): bool
    {
        $table = $this->normalizeTableName($table);
        if ($table === '') {
            return false;
        }
        if (isset($this->tableExistsCache[$table])) {
            return (bool)$this->tableExistsCache[$table];
        }

        $stmt = $this->db->prepare("SELECT OBJECT_ID(:full_name) AS object_id");
        $stmt->execute(['full_name' => 'dbo.' . $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $exists = ((int)($row['object_id'] ?? 0)) > 0;
        $this->tableExistsCache[$table] = $exists;

        return $exists;
    }

    private function buildKey(string $api, string $todo): string
    {
        return $this->normalizeApi($api) . '|' . trim($todo);
    }

    private function normalizeDatetime($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    if ($roleLevel < 1 || $roleLevel > 5) {
        ApiResponse::error('FORBIDDEN', 'system manager role required', 403);
        exit;
    }

    $todo = (string)($_POST['todo'] ?? $_GET['todo'] ?? 'summary');
    if ($todo !== 'summary') {
        ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
        exit;
    }

    $days = (int)($_POST['days'] ?? $_GET['days'] ?? 7);
    if ($days <= 0) {
        $days = 7;
    }

    $serviceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($serviceCode === '') {
        $serviceCode = trim((string)($context['service_code'] ?? ''));
    }

    $tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        $tenantId = (int)($context['tenant_id'] ?? 0);
    }

    $service = new ShadowAuditSummaryService(DbConnection::get(), $security);
    $result = $service->summary($days, $serviceCode, $tenantId);

    ApiResponse::success($result, 'OK', 'shadow audit summary loaded');
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    if ($message === 'SHADOW_QUEUE_TABLE_NOT_READY') {
        ApiResponse::error('SHADOW_QUEUE_TABLE_NOT_READY', 'shadow queue table is not ready', 503, [
            'table' => 'Tb_IntErrorQueue',
        ]);
        exit;
    }

    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $message : 'Internal error',
        500
    );
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
