<?php
declare(strict_types=1);

namespace SHVQ\Mail;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SHVQ\Integration\Adapter\MailImapAdapter;
use SHVQ\Integration\DTO\AdapterContext;

require_once __DIR__ . '/../saas/Integration/Adapter/MailImapAdapter.php';
require_once __DIR__ . '/../saas/Integration/DTO/AdapterContext.php';
require_once __DIR__ . '/../../config/integration.php';

final class MailAdapter
{
    private PDO $db;
    private array $config;
    private MailImapAdapter $legacyAdapter;
    private array $tableExistsCache = [];

    public function __construct(PDO $db, ?array $integrationConfig = null)
    {
        $this->db = $db;
        $this->config = is_array($integrationConfig) ? $integrationConfig : (require __DIR__ . '/../../config/integration.php');
        $this->legacyAdapter = new MailImapAdapter($this->config);
    }

    public function syncFolders(int $accountIdx): array
    {
        $account = $this->loadAccount($accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $context = $this->buildContext($account);
        $validation = $this->legacyAdapter->validateAccount($context);
        if (!($validation['ok'] ?? false)) {
            throw new RuntimeException((string)($validation['message'] ?? 'mail account validation failed'));
        }

        $folders = $this->legacyAdapter->syncFolders($context);
        return [
            'account_idx' => (int)$account['idx'],
            'service_code' => (string)$account['service_code'],
            'tenant_id' => (int)$account['tenant_id'],
            'folders' => is_array($folders) ? $folders : [],
            'synced_at' => date('c'),
        ];
    }

    public function syncMessagesIncremental(int $accountIdx, string $folderKey = 'INBOX', ?string $cursor = null, int $limit = 500): array
    {
        $account = $this->loadAccount($accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $context = $this->buildContext($account);
        $validation = $this->legacyAdapter->validateAccount($context);
        if (!($validation['ok'] ?? false)) {
            throw new RuntimeException((string)($validation['message'] ?? 'mail account validation failed'));
        }

        $folderKey = trim($folderKey);
        if ($folderKey === '') {
            $folderKey = 'INBOX';
        }

        $limit = max(1, min($limit, 1000));

        if (!function_exists('imap_open')) {
            $batch = $this->legacyAdapter->syncMessages($context, $folderKey, $cursor, $limit);
            return [
                'account_idx' => (int)$account['idx'],
                'folder_key' => $folderKey,
                'cursor' => $batch->cursor(),
                'has_more' => $batch->hasMore(),
                'items' => $batch->items(),
                'synced_at' => $batch->syncedAt(),
                'warnings' => $batch->warnings(),
                'strategy' => 'legacy_adapter',
            ];
        }

        $mailbox = $this->buildMailbox($context, $folderKey);
        $imap = @imap_open($mailbox, $context->credential('login_id'), $context->credential('password'), 0, 1);
        if ($imap === false) {
            $error = $this->lastImapError('imap_open failed');
            throw new RuntimeException($error);
        }

        $cursorInt = is_numeric((string)$cursor) ? max(0, (int)$cursor) : 0;
        $uidFrom = $cursorInt > 0 ? ($cursorInt + 1) : 1;

        $uids = @imap_search($imap, 'UID ' . $uidFrom . ':*', SE_UID);
        if (!is_array($uids)) {
            $uids = [];
        }
        sort($uids, SORT_NUMERIC);

        $hasMore = count($uids) > $limit;
        $slice = array_slice($uids, 0, $limit);

        $items = [];
        $maxUid = $cursorInt;
        foreach ($slice as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }

            $overviewRows = @imap_fetch_overview($imap, (string)$uid, FT_UID);
            $overview = is_array($overviewRows) && isset($overviewRows[0]) ? $overviewRows[0] : null;

            $subject = $this->decodeMimeHeader(is_object($overview) ? (string)($overview->subject ?? '') : '');
            $from = $this->decodeMimeHeader(is_object($overview) ? (string)($overview->from ?? '') : '');
            $to = $this->decodeMimeHeader(is_object($overview) ? (string)($overview->to ?? '') : '');

            $items[] = [
                'folder_key' => $folderKey,
                'uid' => $uid,
                'message_id' => is_object($overview) ? trim((string)($overview->message_id ?? '')) : '',
                'subject' => $subject,
                'from' => $from,
                'to' => $to,
                'date' => $this->normalizeDate(is_object($overview) ? (string)($overview->date ?? '') : ''),
                'size' => is_object($overview) ? (int)($overview->size ?? 0) : 0,
                'is_seen' => is_object($overview) ? ((int)($overview->seen ?? 0) === 1) : false,
                'raw' => [
                    'udate' => is_object($overview) ? (int)($overview->udate ?? 0) : 0,
                    'recent' => is_object($overview) ? (int)($overview->recent ?? 0) : 0,
                    'answered' => is_object($overview) ? (int)($overview->answered ?? 0) : 0,
                ],
            ];

            if ($uid > $maxUid) {
                $maxUid = $uid;
            }
        }

        @imap_close($imap);

        return [
            'account_idx' => (int)$account['idx'],
            'service_code' => (string)$account['service_code'],
            'tenant_id' => (int)$account['tenant_id'],
            'folder_key' => $folderKey,
            'cursor' => $maxUid > 0 ? (string)$maxUid : $cursor,
            'has_more' => $hasMore,
            'items' => $items,
            'synced_at' => date('c'),
            'warnings' => [],
            'strategy' => 'imap_uid_search',
        ];
    }

    public function loadAccount(int $accountIdx): ?array
    {
        if ($accountIdx <= 0 || !$this->tableExists('Tb_IntProviderAccount')) {
            return null;
        }

        $sql = "SELECT TOP 1 idx, service_code, tenant_id, provider, account_key, display_name, raw_json
                FROM dbo.Tb_IntProviderAccount
                WHERE idx = :idx
                  AND provider = 'mail'
                  AND status = 'ACTIVE'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['idx' => $accountIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function buildContext(array $account): AdapterContext
    {
        $rawJson = json_decode((string)($account['raw_json'] ?? '{}'), true);
        $credentials = is_array($rawJson) ? $rawJson : [];

        if ($this->tableExists('Tb_IntCredential')) {
            $stmt = $this->db->prepare(
                "SELECT secret_type, secret_value_enc
                 FROM dbo.Tb_IntCredential
                 WHERE provider_account_idx = :idx AND status = 'ACTIVE'"
            );
            $stmt->execute(['idx' => (int)($account['idx'] ?? 0)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = strtolower(trim((string)($row['secret_type'] ?? '')));
                if ($type === '') {
                    continue;
                }
                $credentials[$type] = $this->decryptSecretValue((string)($row['secret_value_enc'] ?? ''));
            }
        }

        if (!isset($credentials['host'])) {
            $credentials['host'] = '';
        }
        if (!isset($credentials['login_id'])) {
            $credentials['login_id'] = (string)($credentials['id'] ?? '');
        }
        if (!isset($credentials['password'])) {
            $credentials['password'] = (string)($credentials['passwd'] ?? '');
        }

        return AdapterContext::fromArray([
            'provider' => 'mail',
            'service_code' => (string)($account['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($account['tenant_id'] ?? 0),
            'account_idx' => (int)($account['idx'] ?? 0),
            'credentials' => $credentials,
            'options' => [
                'timeout_ms' => (int)($this->config['provider']['mail']['timeout_ms'] ?? 5000),
                'retry_max' => (int)($this->config['provider']['mail']['retry_max'] ?? 3),
            ],
            'trace_id' => uniqid('mail_ctx_', true),
        ]);
    }

    private function buildMailbox(AdapterContext $context, string $folder = ''): string
    {
        $host = trim($context->credential('host', ''));
        if ($host === '') {
            throw new RuntimeException('mail host is required');
        }

        $port = (int)$context->credential('port', '0');
        if ($port <= 0) {
            $port = (int)($this->config['provider']['mail']['default_port'] ?? 993);
        }

        $useSsl = $context->credential('ssl', '') !== ''
            ? in_array(strtolower($context->credential('ssl')), ['1', 'true', 'yes', 'on'], true)
            : (bool)($this->config['provider']['mail']['default_ssl'] ?? true);

        $flags = '/imap' . ($useSsl ? '/ssl/validate-cert' : '/tls/validate-cert');

        $folder = trim($folder);
        if ($folder === '') {
            $folder = 'INBOX';
        }

        return '{' . $host . ':' . $port . $flags . '}' . $folder;
    }

    private function decodeMimeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $parts = @imap_mime_header_decode($value);
        if (!is_array($parts) || $parts === []) {
            return $value;
        }

        $decoded = '';
        foreach ($parts as $part) {
            if (!is_object($part)) {
                continue;
            }
            $charset = strtoupper((string)($part->charset ?? 'UTF-8'));
            $text = (string)($part->text ?? '');

            if ($charset !== 'DEFAULT' && $charset !== '' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if (is_string($converted) && $converted !== '') {
                    $decoded .= $converted;
                    continue;
                }
            }
            $decoded .= $text;
        }

        return trim($decoded !== '' ? $decoded : $value);
    }

    private function normalizeDate(string $dateText): string
    {
        $ts = strtotime($dateText);
        if ($ts === false) {
            return date('c');
        }
        return date('c', $ts);
    }

    private function lastImapError(string $fallback): string
    {
        $errors = function_exists('imap_errors') ? imap_errors() : null;
        if (is_array($errors) && $errors !== []) {
            return (string)end($errors);
        }
        return $fallback;
    }

    private function decryptSecretValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $prefix = 'enc:v1:';
        if (!str_starts_with($value, $prefix)) {
            return $value;
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = $this->base64UrlDecode(substr($value, strlen($prefix)));
        if ($raw === '' || strlen($raw) <= 48) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipherRaw = substr($raw, 48);
        if ($iv === '' || $mac === '' || $cipherRaw === '') {
            return '';
        }

        $key = $this->credentialCryptoKey();
        $expectedMac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        if (!hash_equals($mac, $expectedMac)) {
            return '';
        }

        $plain = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }

    private function credentialCryptoKey(): string
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

        return hash('sha256', __DIR__ . '|shvq_v2_mail_credential_key', true);
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        if ($value === '') {
            return '';
        }
        $padLen = strlen($value) % 4;
        if ($padLen > 0) {
            $value .= str_repeat('=', 4 - $padLen);
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        $stmt = $this->db->prepare('SELECT CASE WHEN OBJECT_ID(:obj, :type) IS NULL THEN 0 ELSE 1 END AS exists_flag');
        $stmt->execute([
            'obj' => 'dbo.' . $tableName,
            'type' => 'U',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $exists = ((int)($row['exists_flag'] ?? 0)) === 1;
        $this->tableExistsCache[$tableName] = $exists;

        return $exists;
    }
}
