<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../dist_library/saas/security/DbConnection.php';

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'shv2026grp') { http_response_code(403); exit('[DENIED]'); }

$db = DbConnection::get();

echo "=== 테넌트/서비스 현황 ===\n";
foreach (['Tb_SvcService','Tb_SvcTenant','Tb_SvcTenantUser'] as $t) {
    try {
        $rows = $db->query("SELECT TOP 5 * FROM dbo.$t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n[$t] " . count($rows) . "건\n";
        foreach ($rows as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) { echo "[$t] ERR: " . $e->getMessage() . "\n"; }
}

echo "\n=== 그룹웨어 테이블 데이터 건수 ===\n";
$tables = [
    'Tb_GwDepartment','Tb_GwEmployee','Tb_GwPhoneBook',
    'Tb_GwAttendance','Tb_GwHoliday','Tb_GwOvertime',
    'Tb_GwApprovalDoc','Tb_GwApprovalLine','Tb_GwApprovalComment',
];
foreach ($tables as $t) {
    try {
        $cnt = $db->query("SELECT COUNT(1) FROM dbo.$t")->fetchColumn();
        echo "  $t: {$cnt}건\n";
    } catch (Throwable $e) { echo "  $t: ERR\n"; }
}

echo "\n=== Users (상위 10) ===\n";
try {
    $rows = $db->query("SELECT TOP 10 idx, login_id, display_name, role_level FROM dbo.Tb_Users ORDER BY idx")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { echo "ERR: " . $e->getMessage() . "\n"; }
