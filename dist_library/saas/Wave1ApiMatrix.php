<?php
declare(strict_types=1);

final class Wave1ApiMatrix
{
    private const FILE_SUMMARY = [
        ['api' => 'Site.php', 'write_endpoints' => 12, 'tier' => 1, 'tables' => ['Tb_Site', 'Tb_SiteEstimate', 'Tb_EstimateItem', 'Tb_Bill']],
        ['api' => 'Material.php', 'write_endpoints' => 25, 'tier' => 1, 'tables' => ['Tb_Item', 'Tb_ItemCategory', 'Tb_ItemComponent', 'Tb_EstimateItem', 'Tb_Trash']],
        ['api' => 'Settings.php', 'write_endpoints' => 21, 'tier' => 2, 'tables' => ['Tb_Department', 'Tb_UserSettings', 'Tb_PjtSettings', 'Tb_Users']],
        ['api' => 'Member.php', 'write_endpoints' => 6, 'tier' => 1, 'tables' => ['Tb_Members', 'Tb_PhoneBook', 'Tb_BranchOrgFolder', 'Tb_Comment', 'Tb_Trash']],
        ['api' => 'HeadOffice.php', 'write_endpoints' => 6, 'tier' => 1, 'tables' => ['Tb_HeadOffice', 'Tb_Members', 'Tb_HeadOrgFolder', 'Tb_FileAttach']],
        ['api' => 'Employee.php', 'write_endpoints' => 9, 'tier' => 1, 'tables' => ['Tb_Employee', 'Tb_Users', 'Tb_Permission', 'Tb_EmployeeCard']],
        ['api' => 'Expense.php', 'write_endpoints' => 7, 'tier' => 2, 'tables' => ['Tb_Expense']],
        ['api' => 'Purchase.php', 'write_endpoints' => 7, 'tier' => 2, 'tables' => ['Tb_Company', 'Tb_PhoneBook', 'Tb_FileAttach']],
        ['api' => 'Stock.php', 'write_endpoints' => 6, 'tier' => 1, 'tables' => ['Tb_Stock', 'Tb_StockLog', 'Tb_StockSetting']],
        ['api' => 'Trash.php', 'write_endpoints' => 5, 'tier' => 2, 'tables' => ['Tb_Trash']],
        ['api' => 'Sales.php', 'write_endpoints' => 3, 'tier' => 2, 'tables' => ['Tb_TaxInvoice', 'Tb_Bill']],
        ['api' => 'CalendarV2.php', 'write_endpoints' => 1, 'tier' => 3, 'tables' => ['Tb_PjtPlanPhase']],
        ['api' => 'Calendar.php', 'write_endpoints' => 1, 'tier' => 3, 'tables' => ['Tb_PjtPlanPhase']],
        ['api' => 'Fund.php', 'write_endpoints' => 1, 'tier' => 2, 'tables' => ['Tb_Expenditure_Resolution']],
    ];

    private const TIER1_TODOS = [
        'Site.php' => [
            'insert', 'update', 'delete',
            'insert_est', 'update_est', 'delete_estimate',
            'copy_est', 'recalc_est', 'upsert_est_items',
            'update_est_item', 'delete_est_item', 'approve_est',
        ],
        'Material.php' => [
            'item_insert', 'item_update', 'item_inline_update', 'item_delete', 'delete_items',
        ],
        'Stock.php' => [
            'stock_in', 'stock_out', 'stock_transfer', 'stock_adjust',
        ],
        'Member.php' => [
            'insert', 'update', 'update_branch_settings',
            'member_delete', 'member_inline_update', 'restore',
        ],
        'HeadOffice.php' => [
            'insert', 'update', 'bulk_update', 'restore', 'delete', 'delete_attach',
        ],
        'Employee.php' => [
            'insert_employee', 'update_employee',
        ],
    ];

    public static function summary(): array
    {
        $totalWrite = 0;
        $tierBreakdown = [
            'tier1' => 0,
            'tier2' => 0,
            'tier3' => 0,
        ];
        $uniqueTables = [];

        foreach (self::FILE_SUMMARY as $item) {
            $writeEndpoints = (int)($item['write_endpoints'] ?? 0);
            $tier = (int)($item['tier'] ?? 3);
            $tables = is_array($item['tables'] ?? null) ? $item['tables'] : [];

            $totalWrite += $writeEndpoints;
            if ($tier === 1) {
                $tierBreakdown['tier1'] += $writeEndpoints;
            } elseif ($tier === 2) {
                $tierBreakdown['tier2'] += $writeEndpoints;
            } else {
                $tierBreakdown['tier3'] += $writeEndpoints;
            }

            foreach ($tables as $table) {
                $tableName = trim((string)$table);
                if ($tableName !== '') {
                    $uniqueTables[$tableName] = true;
                }
            }
        }

        return [
            'wave' => 'Wave 1',
            'total_write_endpoints' => $totalWrite,
            'unique_tables' => count($uniqueTables),
            'tier_breakdown' => $tierBreakdown,
            'source_files' => count(self::FILE_SUMMARY),
            'generated_date' => '2026-04-13',
            'notes' => [
                'tier1_first_for_shadow_write',
                'tier2_after_core_stability',
                'tier3_low_risk_deferred',
            ],
        ];
    }

    public static function fileSummary(): array
    {
        return self::FILE_SUMMARY;
    }

    public static function tier1Todos(): array
    {
        return self::TIER1_TODOS;
    }

    public static function isTier1(string $apiFile, string $todo): bool
    {
        $apiFile = trim($apiFile);
        $todo = trim($todo);
        if ($apiFile === '' || $todo === '') {
            return false;
        }
        $list = self::TIER1_TODOS[$apiFile] ?? [];
        return in_array($todo, $list, true);
    }
}
