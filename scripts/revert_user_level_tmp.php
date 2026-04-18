<?php
declare(strict_types=1);
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/env.php';
$dsn = "sqlsrv:Server=".shvEnv('DB_HOST').",".shvEnv('DB_PORT').";Database=CSM_C004732_V2;Encrypt=0;TrustServerCertificate=1";
$pdo = new PDO($dsn, shvEnv('DB_USER'), shvEnv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stmt = $pdo->prepare("UPDATE dbo.Tb_Users SET authority_idx=1 WHERE idx=1");
$stmt->execute();

$after = $pdo->query("SELECT idx, name, id, authority_idx FROM dbo.Tb_Users WHERE idx=1")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['after' => $after, 'msg' => '원복 완료'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
