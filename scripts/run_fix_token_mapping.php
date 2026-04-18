<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
header('Content-Type: application/json; charset=utf-8');
if (trim((string)($_GET['token'] ?? '')) !== 'shvq_fix_token') { http_response_code(403); echo '{"ok":false}'; exit; }

$db = DbConnection::get();
$results = [];

try {
    /* 현재 상태 확인 */
    $st = $db->query("SELECT idx, service_code, tenant_id, provider, provider_account_idx, token_hint, status FROM Tb_IntProviderToken ORDER BY idx");
    $tokens = $st->fetchAll(PDO::FETCH_ASSOC);
    $results[] = 'V2 tokens: ' . json_encode($tokens);

    $st = $db->query("SELECT idx, service_code, tenant_id, provider, account_key, status FROM Tb_IntProviderAccount ORDER BY idx");
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC);
    $results[] = 'V2 accounts: ' . json_encode($accounts);

    /* V1 매핑 확인 */
    $st = $db->query("SELECT CASE WHEN DB_ID('teniq_db') IS NULL THEN 0 ELSE 1 END");
    if ((int)$st->fetchColumn() === 1) {
        $st = $db->query("SELECT idx, service_code, tenant_id, provider FROM teniq_db.dbo.Tb_IotProviderAccount ORDER BY idx");
        $v1accounts = $st->fetchAll(PDO::FETCH_ASSOC);
        $results[] = 'V1 accounts: ' . json_encode($v1accounts);

        /* V1 account idx → V2 account idx 매핑 빌드 */
        $mapping = []; // v1_idx => v2_idx
        foreach ($v1accounts as $v1) {
            foreach ($accounts as $v2) {
                if ($v1['service_code'] === $v2['service_code']
                    && (int)$v1['tenant_id'] === (int)$v2['tenant_id']
                    && $v1['provider'] === $v2['provider']) {
                    $mapping[(int)$v1['idx']] = (int)$v2['idx'];
                    break;
                }
            }
        }
        $results[] = 'MAPPING v1→v2: ' . json_encode($mapping);

        /* 토큰의 provider_account_idx를 V2 기준으로 UPDATE */
        $updated = 0;
        foreach ($tokens as $tok) {
            $oldIdx = (int)$tok['provider_account_idx'];
            if (isset($mapping[$oldIdx]) && $mapping[$oldIdx] !== $oldIdx) {
                $newIdx = $mapping[$oldIdx];
                $st = $db->prepare("UPDATE Tb_IntProviderToken SET provider_account_idx=? WHERE idx=?");
                $st->execute([$newIdx, (int)$tok['idx']]);
                $updated++;
                $results[] = "TOKEN {$tok['idx']}: pa_idx {$oldIdx} → {$newIdx}";
            }
        }
        $results[] = 'TOKENS updated: ' . $updated;

        /* 장치의 provider_account_idx도 V2 기준으로 UPDATE */
        $devUpdated = 0;
        foreach ($mapping as $v1Idx => $v2Idx) {
            if ($v1Idx !== $v2Idx) {
                $st = $db->prepare("UPDATE Tb_IntDevice SET provider_account_idx=? WHERE provider_account_idx=?");
                $st->execute([$v2Idx, $v1Idx]);
                $devUpdated += (int)$st->rowCount();
            }
        }
        $results[] = 'DEVICES pa_idx updated: ' . $devUpdated;
    }

    /* 최종 확인 */
    $st = $db->query("SELECT idx, provider_account_idx, provider, status FROM Tb_IntProviderToken ORDER BY idx");
    $results[] = 'FINAL tokens: ' . json_encode($st->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'results' => $results], JSON_UNESCAPED_UNICODE);
}
