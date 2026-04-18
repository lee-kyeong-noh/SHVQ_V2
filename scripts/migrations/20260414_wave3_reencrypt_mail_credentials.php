<?php
declare(strict_types=1);

/*
  SHVQ_V2 - Wave3 Mail Credential Re-encryption
  목적:
  - Tb_IntCredential 의 평문/비암호화 password 계열 값을 enc:v1 형식으로 재암호화
  - 대상: 23060701@shvision.co.kr, 26031601@shvision.co.kr (필요 시 idx 3,4 포함)

  실행:
    php scripts/migrations/20260414_wave3_reencrypt_mail_credentials.php
*/

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

function credentialCryptoKey(): string
{
    $candidates = [
        getenv('MAIL_CREDENTIAL_KEY'),
        getenv('INT_CREDENTIAL_KEY'),
        getenv('APP_KEY'),
        getenv('SECRET_KEY'),
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            return hash('sha256', $candidate, true);
        }
    }
    return hash('sha256', __FILE__ . '|shvq_v2_mail_credential_key', true);
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function encryptSecretValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('openssl extension is required');
    }

    $key = credentialCryptoKey();
    $iv = random_bytes(16);
    $cipherRaw = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if (!is_string($cipherRaw) || $cipherRaw === '') {
        throw new RuntimeException('credential encryption failed');
    }
    $mac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
    return 'enc:v1:' . base64UrlEncode($iv . $mac . $cipherRaw);
}

try {
    /** @var PDO $db */
    $db = DbConnection::get();

    $targetAccountKeys = [
        '23060701@shvision.co.kr',
        '26031601@shvision.co.kr',
    ];
    $targetIdxHints = [3, 4];

    $accountSql = "
        SELECT idx, account_key
        FROM dbo.Tb_IntProviderAccount
        WHERE provider = 'mail'
          AND (
                account_key IN (:key1, :key2)
             OR idx IN (:idx1, :idx2)
          )
          AND ISNULL(status, 'ACTIVE') <> 'DELETED'
        ORDER BY idx ASC
    ";
    $accountStmt = $db->prepare($accountSql);
    $accountStmt->execute([
        'key1' => $targetAccountKeys[0],
        'key2' => $targetAccountKeys[1],
        'idx1' => $targetIdxHints[0],
        'idx2' => $targetIdxHints[1],
    ]);
    $accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($accounts === []) {
        echo "[INFO] target mail accounts not found.\n";
        exit(0);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($accounts as $account) {
        $accountIdx = (int)($account['idx'] ?? 0);
        if ($accountIdx <= 0) {
            continue;
        }

        $credStmt = $db->prepare("
            SELECT idx, secret_type, secret_value_enc
            FROM dbo.Tb_IntCredential
            WHERE provider_account_idx = :account_idx
              AND secret_type IN ('password', 'smtp_password')
              AND ISNULL(status, 'ACTIVE') = 'ACTIVE'
            ORDER BY idx ASC
        ");
        $credStmt->execute(['account_idx' => $accountIdx]);
        $creds = $credStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($creds as $cred) {
            $credIdx = (int)($cred['idx'] ?? 0);
            $raw = trim((string)($cred['secret_value_enc'] ?? ''));
            if ($credIdx <= 0 || $raw === '') {
                $skipped++;
                continue;
            }

            if (str_starts_with($raw, 'enc:v1:')) {
                $skipped++;
                continue;
            }

            $encrypted = encryptSecretValue($raw);
            if ($encrypted === '') {
                $skipped++;
                continue;
            }

            $up = $db->prepare("
                UPDATE dbo.Tb_IntCredential
                SET secret_value_enc = :secret_value_enc,
                    updated_at = GETDATE()
                WHERE idx = :idx
            ");
            $up->execute([
                'secret_value_enc' => $encrypted,
                'idx' => $credIdx,
            ]);
            $updated += (int)$up->rowCount();
        }
    }

    echo "[OK] re-encryption completed. updated={$updated}, skipped={$skipped}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
