<?php
declare(strict_types=1);
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/env.php';

$dsn = "sqlsrv:Server=" . shvEnv('DB_HOST') . "," . shvEnv('DB_PORT') . ";Database=CSM_C004732_V2;Encrypt=0;TrustServerCertificate=1";
$pdo = new PDO($dsn, shvEnv('DB_USER'), shvEnv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$accounts = $pdo->query("SELECT idx, account_key, display_name, is_primary, status FROM dbo.Tb_IntProviderAccount WHERE provider='mail' ORDER BY idx")->fetchAll(PDO::FETCH_ASSOC);
$creds    = $pdo->query("SELECT c.provider_account_idx, c.secret_type FROM dbo.Tb_IntCredential c INNER JOIN dbo.Tb_IntProviderAccount a ON a.idx=c.provider_account_idx WHERE a.provider='mail' ORDER BY c.provider_account_idx, c.secret_type")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['accounts' => $accounts, 'credentials' => $creds], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
