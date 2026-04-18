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
require_once __DIR__ . '/MailAdapter.php';
require_once __DIR__ . '/../../config/integration.php';

final class MailAccountService
{
    private PDO $db;
    private array $config;
    private MailAdapter $mailAdapter;
    private MailImapAdapter $imapAdapter;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private const POLICY_TABLE = 'Tb_MailSetting';
    private const POLICY_DEFAULT_MAX_PER_FILE_MB = 10;
    private const POLICY_DEFAULT_MAX_TOTAL_MB = 25;
    private const POLICY_DEFAULT_ALLOWED_EXTS = [
        'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx',
        'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv', 'hwp',
    ];

    public function __construct(PDO $db, ?array $integrationConfig = null)
    {
        $this->db = $db;
        $this->config = is_array($integrationConfig) ? $integrationConfig : (require __DIR__ . '/../../config/integration.php');
        $this->mailAdapter = new MailAdapter($db, $this->config);
        $this->imapAdapter = new MailImapAdapter($this->config);
    }

    public function listAccounts(
        string $serviceCode,
        int $tenantId,
        string $status = '',
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array
    {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            return [
                'items' => [],
                'total' => 0,
            ];
        }

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $status = $this->normalizeStatus($status, true);
        $actorUserPk = max(0, $actorUserPk);
        $actorRoleLevel = max(0, $actorRoleLevel);

        $selectColumns = ['idx', 'service_code', 'tenant_id', 'provider', 'account_key'];
        if ($this->columnExists('Tb_IntProviderAccount', 'user_pk')) {
            $selectColumns[] = 'user_pk';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'display_name')) {
            $selectColumns[] = 'display_name';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'is_primary')) {
            $selectColumns[] = 'is_primary';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'status')) {
            $selectColumns[] = 'status';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'raw_json')) {
            $selectColumns[] = 'raw_json';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'created_at')) {
            $selectColumns[] = 'created_at';
        }
        if ($this->columnExists('Tb_IntProviderAccount', 'updated_at')) {
            $selectColumns[] = 'updated_at';
        }

        $where = [
            'service_code = :service_code',
            'tenant_id = :tenant_id',
            "provider = 'mail'",
        ];
        $params = [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
        ];

        $canViewAllAccounts = $actorRoleLevel >= 4;
        if (!$canViewAllAccounts) {
            if ($actorUserPk <= 0 || !$this->columnExists('Tb_IntProviderAccount', 'user_pk')) {
                return [
                    'items' => [],
                    'total' => 0,
                ];
            }
            $where[] = 'user_pk = :user_pk';
            $params['user_pk'] = $actorUserPk;
        }

        if ($status !== '' && $this->columnExists('Tb_IntProviderAccount', 'status')) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT ' . implode(', ', $selectColumns)
            . ' FROM dbo.Tb_IntProviderAccount'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . ($this->columnExists('Tb_IntProviderAccount', 'is_primary') ? 'is_primary DESC, ' : '') . 'idx ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];

        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $accountIdx = (int)($row['idx'] ?? 0);
            $rawJson = json_decode((string)($row['raw_json'] ?? '{}'), true);
            if (!is_array($rawJson)) {
                $rawJson = [];
            }
            $credentials = $this->loadCredentialMap($accountIdx);

            $items[] = [
                'idx' => $accountIdx,
                'service_code' => (string)($row['service_code'] ?? $serviceCode),
                'tenant_id' => (int)($row['tenant_id'] ?? $tenantId),
                'provider' => 'mail',
                'account_key' => (string)($row['account_key'] ?? ''),
                'user_pk' => array_key_exists('user_pk', $row) ? (int)($row['user_pk'] ?? 0) : 0,
                'display_name' => (string)($row['display_name'] ?? ''),
                'is_primary' => (int)($row['is_primary'] ?? 0),
                'status' => (string)($row['status'] ?? 'ACTIVE'),
                'host' => (string)($credentials['host'] ?? $rawJson['host'] ?? ''),
                'port' => (string)($credentials['port'] ?? $rawJson['port'] ?? ''),
                'ssl' => (string)($credentials['ssl'] ?? $rawJson['ssl'] ?? ''),
                'login_id' => (string)($credentials['login_id'] ?? $rawJson['login_id'] ?? ''),
                'password_set' => trim((string)($credentials['password'] ?? '')) !== '',
                'smtp_host' => (string)($credentials['smtp_host'] ?? $rawJson['smtp_host'] ?? ''),
                'smtp_port' => (string)($credentials['smtp_port'] ?? $rawJson['smtp_port'] ?? ''),
                'smtp_ssl' => (string)($credentials['smtp_ssl'] ?? $rawJson['smtp_ssl'] ?? ''),
                'from_email' => (string)($credentials['from_email'] ?? $rawJson['from_email'] ?? ''),
                'from_name' => (string)($credentials['from_name'] ?? $rawJson['from_name'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
        ];
    }

    public function getAdminSettings(string $serviceCode, int $tenantId): array
    {
        $this->ensureMailSettingTable();

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $map = [];
        if ($this->tableExists(self::POLICY_TABLE)) {
            $sql = 'SELECT setting_key, setting_value
                    FROM dbo.' . self::POLICY_TABLE . '
                    WHERE service_code = :service_code
                      AND tenant_id = :tenant_id
                      AND ISNULL(is_deleted, 0) = 0';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string)($row['setting_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $map[$key] = trim((string)($row['setting_value'] ?? ''));
            }
        }

        $maxPerFileMb = array_key_exists('max_per_file_mb', $map)
            ? $this->normalizePolicyInt($map['max_per_file_mb'], self::POLICY_DEFAULT_MAX_PER_FILE_MB, 1, 100)
            : self::POLICY_DEFAULT_MAX_PER_FILE_MB;
        $maxTotalMb = array_key_exists('max_total_mb', $map)
            ? $this->normalizePolicyInt($map['max_total_mb'], self::POLICY_DEFAULT_MAX_TOTAL_MB, 1, 500)
            : self::POLICY_DEFAULT_MAX_TOTAL_MB;

        if ($maxTotalMb < $maxPerFileMb) {
            $maxTotalMb = $maxPerFileMb;
        }

        $allowedExtensions = array_key_exists('allowed_exts', $map)
            ? $this->normalizeAllowedExtensions((string)$map['allowed_exts'])
            : self::POLICY_DEFAULT_ALLOWED_EXTS;
        $allowedExts = implode(',', $allowedExtensions);

        return [
            'max_per_file_mb' => $maxPerFileMb,
            'max_total_mb' => $maxTotalMb,
            'allowed_exts' => $allowedExts,
            'allowed_extensions' => $allowedExtensions,
        ];
    }

    public function saveAdminSettings(string $serviceCode, int $tenantId, array $input, int $actorUserPk = 0): array
    {
        $this->ensureMailSettingTable();

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $current = $this->getAdminSettings($serviceCode, $tenantId);

        $maxPerFileMb = array_key_exists('max_per_file_mb', $input)
            ? $this->parsePolicyIntInput($input['max_per_file_mb'] ?? null, 'max_per_file_mb', 1, 100)
            : (int)($current['max_per_file_mb'] ?? self::POLICY_DEFAULT_MAX_PER_FILE_MB);

        $maxTotalMb = array_key_exists('max_total_mb', $input)
            ? $this->parsePolicyIntInput($input['max_total_mb'] ?? null, 'max_total_mb', 1, 500)
            : (int)($current['max_total_mb'] ?? self::POLICY_DEFAULT_MAX_TOTAL_MB);

        if ($maxTotalMb < $maxPerFileMb) {
            throw new InvalidArgumentException('max_total_mb must be greater than or equal to max_per_file_mb');
        }

        $allowedRaw = array_key_exists('allowed_exts', $input)
            ? (string)($input['allowed_exts'] ?? '')
            : (string)($current['allowed_exts'] ?? implode(',', self::POLICY_DEFAULT_ALLOWED_EXTS));
        $allowedExtensions = $this->normalizeAllowedExtensions($allowedRaw);
        $allowedExts = implode(',', $allowedExtensions);

        $startedTransaction = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $actorUserPk = max(0, $actorUserPk);
            $this->upsertPolicySetting($serviceCode, $tenantId, 'max_per_file_mb', (string)$maxPerFileMb, 'int', $actorUserPk);
            $this->upsertPolicySetting($serviceCode, $tenantId, 'max_total_mb', (string)$maxTotalMb, 'int', $actorUserPk);
            $this->upsertPolicySetting($serviceCode, $tenantId, 'allowed_exts', $allowedExts, 'string', $actorUserPk);

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getAdminSettings($serviceCode, $tenantId);
    }

    public function saveAccount(string $serviceCode, int $tenantId, array $input, int $actorUserPk = 0): array
    {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            throw new RuntimeException('Tb_IntProviderAccount table is missing');
        }

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $actorUserPk = max(0, $actorUserPk);
        $accountIdx = (int)($input['idx'] ?? $input['account_idx'] ?? 0);
        $isUpdate = $accountIdx > 0;

        $existing = $isUpdate ? $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx) : null;
        if ($isUpdate && $existing === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $ownerUserPk = $isUpdate ? (int)($existing['user_pk'] ?? 0) : 0;
        if (array_key_exists('user_pk', $input)) {
            $candidateUserPk = (int)($input['user_pk'] ?? 0);
            $ownerUserPk = $candidateUserPk > 0 ? $candidateUserPk : 0;
        } elseif (!$isUpdate && $actorUserPk > 0) {
            $ownerUserPk = $actorUserPk;
        }

        $accountKey = trim((string)($input['account_key'] ?? ''));
        if ($accountKey === '' && $existing !== null) {
            $accountKey = trim((string)($existing['account_key'] ?? ''));
        }

        if ($accountKey === '') {
            $seed = (string)($input['login_id'] ?? $input['from_email'] ?? 'mail');
            $seed = strtolower(trim($seed));
            if ($seed === '') {
                $seed = 'mail';
            }
            $accountKey = preg_replace('/[^a-z0-9._-]+/i', '_', $seed) ?: 'mail';
            $accountKey = trim($accountKey, '_');
            if ($accountKey === '') {
                $accountKey = 'mail';
            }
        }

        if (mb_strlen($accountKey) > 120) {
            $accountKey = mb_substr($accountKey, 0, 120);
        }

        $displayName = trim((string)($input['display_name'] ?? $input['account_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $accountKey;
        }
        if (mb_strlen($displayName) > 180) {
            $displayName = mb_substr($displayName, 0, 180);
        }

        $status = $this->normalizeStatus((string)($input['status'] ?? ($existing['status'] ?? 'ACTIVE')), false);
        $isPrimary = $this->boolToInt($input['is_primary'] ?? ($existing['is_primary'] ?? 0));

        $currentCredentials = $existing !== null ? $this->loadCredentialMap((int)($existing['idx'] ?? 0)) : [];
        $credentialMap = $this->normalizeCredentialInput($input, $currentCredentials);

        if (trim((string)($credentialMap['host'] ?? '')) === '') {
            throw new InvalidArgumentException('host is required');
        }
        if (trim((string)($credentialMap['login_id'] ?? '')) === '') {
            throw new InvalidArgumentException('login_id is required');
        }
        if (trim((string)($credentialMap['password'] ?? '')) === '') {
            throw new InvalidArgumentException('password is required');
        }

        $this->assertDuplicateAccountKey($serviceCode, $tenantId, $accountKey, $isUpdate ? $accountIdx : 0);

        $rawJson = $this->buildRawJsonPayload($existing, $credentialMap, $input);
        $rawJsonEncoded = json_encode($rawJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawJsonEncoded)) {
            $rawJsonEncoded = '{}';
        }

        $startedTransaction = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            if ($isUpdate) {
                $setParts = [];
                $params = [
                    'idx' => $accountIdx,
                    'service_code' => $serviceCode,
                    'tenant_id' => $tenantId,
                ];

                if ($this->columnExists('Tb_IntProviderAccount', 'account_key')) {
                    $setParts[] = 'account_key = :account_key';
                    $params['account_key'] = $accountKey;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'user_pk')) {
                    $setParts[] = 'user_pk = :user_pk';
                    $params['user_pk'] = $ownerUserPk > 0 ? $ownerUserPk : null;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'display_name')) {
                    $setParts[] = 'display_name = :display_name';
                    $params['display_name'] = $displayName;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'is_primary')) {
                    $setParts[] = 'is_primary = :is_primary';
                    $params['is_primary'] = $isPrimary;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'status')) {
                    $setParts[] = 'status = :status';
                    $params['status'] = $status;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'raw_json')) {
                    $setParts[] = 'raw_json = :raw_json';
                    $params['raw_json'] = $rawJsonEncoded;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'updated_at')) {
                    $setParts[] = 'updated_at = GETDATE()';
                }

                if ($setParts !== []) {
                    $sql = 'UPDATE dbo.Tb_IntProviderAccount SET ' . implode(', ', $setParts)
                        . ' WHERE idx = :idx AND service_code = :service_code AND tenant_id = :tenant_id AND provider = :provider';
                    $params['provider'] = 'mail';
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                }
            } else {
                $insertCols = [];
                $insertValues = [];
                $params = [];

                $requiredCols = ['service_code', 'tenant_id', 'provider', 'account_key'];
                foreach ($requiredCols as $required) {
                    if (!$this->columnExists('Tb_IntProviderAccount', $required)) {
                        throw new RuntimeException('Tb_IntProviderAccount missing required column: ' . $required);
                    }
                }

                $insertCols[] = 'service_code';
                $insertValues[] = ':service_code';
                $params['service_code'] = $serviceCode;

                $insertCols[] = 'tenant_id';
                $insertValues[] = ':tenant_id';
                $params['tenant_id'] = $tenantId;

                $insertCols[] = 'provider';
                $insertValues[] = ':provider';
                $params['provider'] = 'mail';

                $insertCols[] = 'account_key';
                $insertValues[] = ':account_key';
                $params['account_key'] = $accountKey;

                if ($this->columnExists('Tb_IntProviderAccount', 'user_pk')) {
                    $insertCols[] = 'user_pk';
                    $insertValues[] = ':user_pk';
                    $params['user_pk'] = $ownerUserPk > 0 ? $ownerUserPk : null;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'display_name')) {
                    $insertCols[] = 'display_name';
                    $insertValues[] = ':display_name';
                    $params['display_name'] = $displayName;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'is_primary')) {
                    $insertCols[] = 'is_primary';
                    $insertValues[] = ':is_primary';
                    $params['is_primary'] = $isPrimary;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'status')) {
                    $insertCols[] = 'status';
                    $insertValues[] = ':status';
                    $params['status'] = $status;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'raw_json')) {
                    $insertCols[] = 'raw_json';
                    $insertValues[] = ':raw_json';
                    $params['raw_json'] = $rawJsonEncoded;
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'created_at')) {
                    $insertCols[] = 'created_at';
                    $insertValues[] = 'GETDATE()';
                }
                if ($this->columnExists('Tb_IntProviderAccount', 'updated_at')) {
                    $insertCols[] = 'updated_at';
                    $insertValues[] = 'GETDATE()';
                }

                $sql = 'INSERT INTO dbo.Tb_IntProviderAccount (' . implode(', ', $insertCols) . ')'
                    . ' VALUES (' . implode(', ', $insertValues) . ')';

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                $accountIdx = $this->fetchInsertedAccountIdx();
                if ($accountIdx <= 0) {
                    throw new RuntimeException('MAIL_ACCOUNT_SAVE_FAILED');
                }
            }

            if ($isPrimary === 1 && $this->columnExists('Tb_IntProviderAccount', 'is_primary')) {
                $this->clearPrimaryOthers($serviceCode, $tenantId, $accountIdx);
            }

            $this->saveCredentialMap($accountIdx, $credentialMap);

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $saved = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($saved === null) {
            throw new RuntimeException('MAIL_ACCOUNT_SAVE_FAILED');
        }

        return [
            'idx' => $accountIdx,
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'provider' => 'mail',
            'account_key' => (string)($saved['account_key'] ?? $accountKey),
            'user_pk' => array_key_exists('user_pk', $saved) ? (int)($saved['user_pk'] ?? 0) : ($ownerUserPk > 0 ? $ownerUserPk : 0),
            'display_name' => (string)($saved['display_name'] ?? $displayName),
            'is_primary' => (int)($saved['is_primary'] ?? $isPrimary),
            'status' => (string)($saved['status'] ?? $status),
            'credentials_saved' => array_keys($credentialMap),
        ];
    }

    public function deleteAccount(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        if ($accountIdx <= 0) {
            throw new InvalidArgumentException('account_idx is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        if ($this->columnExists('Tb_IntProviderAccount', 'status')) {
            $sql = 'UPDATE dbo.Tb_IntProviderAccount
                    SET status = :status'
                . ($this->columnExists('Tb_IntProviderAccount', 'updated_at') ? ', updated_at = GETDATE()' : '')
                . ' WHERE idx = :idx AND service_code = :service_code AND tenant_id = :tenant_id AND provider = :provider';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => 'DELETED',
                'idx' => $accountIdx,
                'service_code' => trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq',
                'tenant_id' => max(0, $tenantId),
                'provider' => 'mail',
            ]);
        } else {
            throw new RuntimeException('status column is required for soft delete');
        }

        if ($this->tableExists('Tb_IntCredential') && $this->columnExists('Tb_IntCredential', 'status')) {
            $sql = 'UPDATE dbo.Tb_IntCredential
                    SET status = :status'
                . ($this->columnExists('Tb_IntCredential', 'updated_at') ? ', updated_at = GETDATE()' : '')
                . ' WHERE provider_account_idx = :provider_account_idx';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => 'INACTIVE',
                'provider_account_idx' => $accountIdx,
            ]);
        }

        return [
            'idx' => $accountIdx,
            'deleted' => true,
            'status' => 'DELETED',
        ];
    }

    public function testConnection(string $serviceCode, int $tenantId, mixed $input = [], int $actorUserPk = 0): array
    {
        $payload = [];
        if (is_array($input)) {
            $payload = $input;
        } elseif (is_scalar($input)) {
            $text = trim((string)$input);
            if ($text !== '' && preg_match('/^-?\d+$/', $text)) {
                $payload = ['account_idx' => (int)$text];
            }
        }

        $actorUserPk = max(0, $actorUserPk);
        if ($actorUserPk > 0 && !array_key_exists('actor_user_pk', $payload)) {
            $payload['actor_user_pk'] = $actorUserPk;
        }

        $accountIdx = (int)($payload['account_idx'] ?? $payload['idx'] ?? 0);
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $testedAt = date('c');

        try {
            $health = [
                'status' => 'DOWN',
                'message' => 'imap_open failed',
                'latency_ms' => null,
            ];
            $folders = [];
            $credentials = [];

            if ($accountIdx > 0) {
                $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
                if ($account === null) {
                    return [
                        'ok' => false,
                        'account_idx' => $accountIdx,
                        'health' => [
                            'status' => 'DOWN',
                            'message' => 'MAIL_ACCOUNT_NOT_FOUND',
                            'latency_ms' => null,
                        ],
                        'smtp' => [
                            'status' => 'DOWN',
                            'message' => 'smtp check skipped',
                            'latency_ms' => null,
                        ],
                        'folder_count' => 0,
                        'folders' => [],
                        'tested_at' => $testedAt,
                    ];
                }

                $credentials = $this->normalizeCredentialInput([], $this->loadCredentialMap($accountIdx));
                $context = AdapterContext::fromArray([
                    'provider' => 'mail',
                    'service_code' => $serviceCode,
                    'tenant_id' => $tenantId,
                    'account_idx' => $accountIdx,
                    'credentials' => $credentials,
                ]);
            } else {
                $credentials = $this->normalizeCredentialInput($payload, []);
                $context = AdapterContext::fromArray([
                    'provider' => 'mail',
                    'service_code' => $serviceCode,
                    'tenant_id' => $tenantId,
                    'account_idx' => 0,
                    'credentials' => $credentials,
                ]);

                $validation = $this->imapAdapter->validateAccount($context);
                if (!($validation['ok'] ?? false)) {
                    $smtpHealth = $this->probeSmtpHealth($credentials);
                    return [
                        'ok' => false,
                        'account_idx' => 0,
                        'health' => [
                            'status' => 'DOWN',
                            'message' => (string)($validation['message'] ?? 'account validation failed'),
                            'latency_ms' => null,
                        ],
                        'smtp' => $smtpHealth,
                        'folder_count' => 0,
                        'folders' => [],
                        'tested_at' => $testedAt,
                    ];
                }
            }

            try {
                $health = $this->imapAdapter->health($context);
            } catch (\Throwable $e) {
                $health['message'] = $e->getMessage();
            }

            if (strtoupper((string)($health['status'] ?? 'DOWN')) === 'UP') {
                try {
                    if ($accountIdx > 0) {
                        $synced = $this->mailAdapter->syncFolders($accountIdx);
                        if (is_array($synced)) {
                            $folders = $this->extractFoldersFromSyncResult($synced);
                        }
                    } else {
                        $synced = $this->imapAdapter->syncFolders($context);
                        if (is_array($synced)) {
                            $folders = $synced;
                        }
                    }
                } catch (\Throwable $e) {
                    $health['message'] = $e->getMessage();
                }
            }

            $smtpHealth = $this->probeSmtpHealth($credentials);
            $imapUp = strtoupper((string)($health['status'] ?? 'DOWN')) === 'UP';
            $smtpUp = strtoupper((string)($smtpHealth['status'] ?? 'DOWN')) === 'UP';

            if ($imapUp && !$smtpUp) {
                $health['status'] = 'DOWN';
                $health['message'] = 'smtp check failed: ' . trim((string)($smtpHealth['message'] ?? 'smtp connection failed'));
            }

            return [
                'ok' => $imapUp && $smtpUp,
                'account_idx' => $accountIdx,
                'health' => $health,
                'smtp' => $smtpHealth,
                'folder_count' => is_array($folders) ? count($folders) : 0,
                'folders' => is_array($folders) ? $folders : [],
                'tested_at' => $testedAt,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'account_idx' => $accountIdx,
                'health' => [
                    'status' => 'DOWN',
                    'message' => $e->getMessage(),
                    'latency_ms' => null,
                ],
                'smtp' => [
                    'status' => 'DOWN',
                    'message' => 'smtp check skipped due previous error',
                    'latency_ms' => null,
                ],
                'folder_count' => 0,
                'folders' => [],
                'tested_at' => $testedAt,
            ];
        }
    }

    private function extractFoldersFromSyncResult(array $syncResult): array
    {
        $folders = $syncResult['folders'] ?? $syncResult;
        if (!is_array($folders)) {
            return [];
        }

        if (array_is_list($folders)) {
            return $folders;
        }

        $normalized = [];
        foreach ($folders as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    private function probeSmtpHealth(array $credentialMap): array
    {
        $smtpHost = trim((string)($credentialMap['smtp_host'] ?? $credentialMap['host'] ?? ''));
        $smtpSsl = $this->boolToInt($credentialMap['smtp_ssl'] ?? ($credentialMap['ssl'] ?? '1')) === 1;
        $smtpPort = (int)($credentialMap['smtp_port'] ?? 0);

        if ($smtpPort <= 0) {
            $smtpPort = $smtpSsl ? 465 : 587;
        }

        if ($smtpHost === '') {
            return [
                'status' => 'DOWN',
                'message' => 'smtp host is required',
                'latency_ms' => null,
            ];
        }

        $primary = $this->smtpSocketProbe($smtpHost, $smtpPort, $smtpSsl);
        if (strtoupper((string)($primary['status'] ?? 'DOWN')) === 'UP') {
            return $primary;
        }

        $shouldFallback = $smtpSsl && $smtpPort === 465;
        if (!$shouldFallback) {
            return $primary;
        }

        $fallback = $this->smtpSocketProbe($smtpHost, 587, false);
        if (strtoupper((string)($fallback['status'] ?? 'DOWN')) === 'UP') {
            return [
                'status' => 'UP',
                'message' => 'ok (fallback 587 STARTTLS)',
                'latency_ms' => (int)($fallback['latency_ms'] ?? 0),
                'port' => 587,
                'ssl' => 0,
                'fallback_used' => 1,
            ];
        }

        $primaryMessage = trim((string)($primary['message'] ?? 'smtp connection failed'));
        $fallbackMessage = trim((string)($fallback['message'] ?? 'smtp connection failed'));

        return [
            'status' => 'DOWN',
            'message' => $primaryMessage . '; fallback 587 failed: ' . $fallbackMessage,
            'latency_ms' => max((int)($primary['latency_ms'] ?? 0), (int)($fallback['latency_ms'] ?? 0)),
            'port' => $smtpPort,
            'ssl' => $smtpSsl ? 1 : 0,
            'fallback_port' => 587,
            'fallback_ssl' => 0,
        ];
    }

    private function smtpSocketProbe(string $smtpHost, int $smtpPort, bool $smtpSsl): array
    {
        $target = ($smtpSsl ? 'ssl://' : '') . $smtpHost;
        $startedAt = microtime(true);
        $errno = 0;
        $errstr = '';

        try {
            $stream = @fsockopen($target, $smtpPort, $errno, $errstr, 5.0);
            $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

            if (!is_resource($stream)) {
                $message = trim($errstr) !== '' ? trim($errstr) : ('smtp connect failed (' . $errno . ')');
                return [
                    'status' => 'DOWN',
                    'message' => $message,
                    'latency_ms' => $latencyMs,
                    'port' => $smtpPort,
                    'ssl' => $smtpSsl ? 1 : 0,
                ];
            }

            @stream_set_timeout($stream, 5);
            $banner = @fgets($stream, 512);
            @fwrite($stream, "QUIT\r\n");
            @fclose($stream);

            if (is_string($banner) && trim($banner) !== '' && preg_match('/^220\\b/', trim($banner)) !== 1) {
                return [
                    'status' => 'DOWN',
                    'message' => 'unexpected smtp banner: ' . trim($banner),
                    'latency_ms' => $latencyMs,
                    'port' => $smtpPort,
                    'ssl' => $smtpSsl ? 1 : 0,
                ];
            }

            return [
                'status' => 'UP',
                'message' => 'ok',
                'latency_ms' => $latencyMs,
                'port' => $smtpPort,
                'ssl' => $smtpSsl ? 1 : 0,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'DOWN',
                'message' => $e->getMessage(),
                'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'port' => $smtpPort,
                'ssl' => $smtpSsl ? 1 : 0,
            ];
        }
    }

    private function loadAccountScoped(string $serviceCode, int $tenantId, int $accountIdx): ?array
    {
        if ($accountIdx <= 0 || !$this->tableExists('Tb_IntProviderAccount')) {
            return null;
        }

        $where = [
            'idx = :idx',
            'service_code = :service_code',
            'tenant_id = :tenant_id',
            "provider = 'mail'",
        ];

        if ($this->columnExists('Tb_IntProviderAccount', 'status')) {
            $where[] = "status <> 'DELETED'";
        }

        $sql = 'SELECT TOP 1 * FROM dbo.Tb_IntProviderAccount WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'idx' => $accountIdx,
            'service_code' => trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq',
            'tenant_id' => max(0, $tenantId),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function assertDuplicateAccountKey(string $serviceCode, int $tenantId, string $accountKey, int $excludeIdx = 0): void
    {
        $sql = 'SELECT TOP 1 idx
                FROM dbo.Tb_IntProviderAccount
                WHERE service_code = :service_code
                  AND tenant_id = :tenant_id
                  AND provider = :provider
                  AND account_key = :account_key';
        $params = [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'provider' => 'mail',
            'account_key' => $accountKey,
        ];

        if ($excludeIdx > 0) {
            $sql .= ' AND idx <> :exclude_idx';
            $params['exclude_idx'] = $excludeIdx;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new InvalidArgumentException('duplicate account_key: ' . $accountKey);
        }
    }

    private function fetchInsertedAccountIdx(): int
    {
        $stmt = $this->db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
        if (!$stmt) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['idx'] ?? 0);
    }

    private function clearPrimaryOthers(string $serviceCode, int $tenantId, int $accountIdx): void
    {
        $sql = 'UPDATE dbo.Tb_IntProviderAccount
                SET is_primary = 0'
            . ($this->columnExists('Tb_IntProviderAccount', 'updated_at') ? ', updated_at = GETDATE()' : '')
            . ' WHERE service_code = :service_code
                  AND tenant_id = :tenant_id
                  AND provider = :provider
                  AND idx <> :idx';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'provider' => 'mail',
            'idx' => $accountIdx,
        ]);
    }

    private function loadCredentialMap(int $providerAccountIdx): array
    {
        if ($providerAccountIdx <= 0 || !$this->tableExists('Tb_IntCredential')) {
            return [];
        }

        $where = ['provider_account_idx = :provider_account_idx'];
        if ($this->columnExists('Tb_IntCredential', 'status')) {
            $where[] = "status = 'ACTIVE'";
        }

        $sql = 'SELECT secret_type, secret_value_enc
                FROM dbo.Tb_IntCredential
                WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['provider_account_idx' => $providerAccountIdx]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['secret_type'] ?? '')));
            if ($type === '') {
                continue;
            }
            $map[$type] = $this->decryptSecretValue((string)($row['secret_value_enc'] ?? ''));
        }

        return $map;
    }

    private function saveCredentialMap(int $providerAccountIdx, array $credentialMap): void
    {
        if ($providerAccountIdx <= 0 || !$this->tableExists('Tb_IntCredential')) {
            return;
        }

        foreach ($credentialMap as $type => $value) {
            $type = strtolower(trim((string)$type));
            if ($type === '') {
                continue;
            }
            if (mb_strlen($type) > 60) {
                $type = mb_substr($type, 0, 60);
            }

            $value = (string)$value;
            if ($value === '') {
                continue;
            }
            $encryptedValue = $this->encryptSecretValue($value);

            $selectSql = 'SELECT TOP 1 idx
                          FROM dbo.Tb_IntCredential
                          WHERE provider_account_idx = :provider_account_idx
                            AND secret_type = :secret_type';
            $stmt = $this->db->prepare($selectSql);
            $stmt->execute([
                'provider_account_idx' => $providerAccountIdx,
                'secret_type' => $type,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($row) && (int)($row['idx'] ?? 0) > 0) {
                $setParts = ['secret_value_enc = :secret_value_enc'];
                if ($this->columnExists('Tb_IntCredential', 'status')) {
                    $setParts[] = "status = 'ACTIVE'";
                }
                if ($this->columnExists('Tb_IntCredential', 'updated_at')) {
                    $setParts[] = 'updated_at = GETDATE()';
                }
                $updateSql = 'UPDATE dbo.Tb_IntCredential SET ' . implode(', ', $setParts) . ' WHERE idx = :idx';
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([
                    'secret_value_enc' => $encryptedValue,
                    'idx' => (int)$row['idx'],
                ]);
                continue;
            }

            $cols = ['provider_account_idx', 'secret_type', 'secret_value_enc'];
            $vals = [':provider_account_idx', ':secret_type', ':secret_value_enc'];
            $params = [
                'provider_account_idx' => $providerAccountIdx,
                'secret_type' => $type,
                'secret_value_enc' => $encryptedValue,
            ];

            if ($this->columnExists('Tb_IntCredential', 'status')) {
                $cols[] = 'status';
                $vals[] = ':status';
                $params['status'] = 'ACTIVE';
            }
            if ($this->columnExists('Tb_IntCredential', 'created_at')) {
                $cols[] = 'created_at';
                $vals[] = 'GETDATE()';
            }
            if ($this->columnExists('Tb_IntCredential', 'updated_at')) {
                $cols[] = 'updated_at';
                $vals[] = 'GETDATE()';
            }

            $insertSql = 'INSERT INTO dbo.Tb_IntCredential (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute($params);
        }
    }

    private function encryptSecretValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('openssl extension is required for credential encryption');
        }

        $key = $this->credentialCryptoKey();
        $iv = random_bytes(16);
        $cipherRaw = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipherRaw) || $cipherRaw === '') {
            throw new RuntimeException('credential encryption failed');
        }

        $mac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        return 'enc:v1:' . $this->base64UrlEncode($iv . $mac . $cipherRaw);
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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

    private function normalizeCredentialInput(array $input, array $fallback): array
    {
        $map = [];
        $keys = [
            'host',
            'port',
            'ssl',
            'login_id',
            'password',
            'smtp_host',
            'smtp_port',
            'smtp_ssl',
            'smtp_login_id',
            'smtp_password',
            'from_email',
            'from_name',
        ];

        foreach ($keys as $key) {
            $raw = $input[$key] ?? null;
            $value = is_scalar($raw) ? trim((string)$raw) : '';
            if ($value === '' && isset($fallback[$key]) && is_scalar($fallback[$key])) {
                $value = trim((string)$fallback[$key]);
            }
            if ($value !== '') {
                $map[$key] = $value;
            }
        }

        if (!isset($map['port'])) {
            $map['port'] = (string)((int)($this->config['provider']['mail']['default_port'] ?? 993));
        }
        if (!isset($map['ssl'])) {
            $map['ssl'] = ((bool)($this->config['provider']['mail']['default_ssl'] ?? true)) ? '1' : '0';
        }

        return $map;
    }

    private function buildRawJsonPayload(?array $existing, array $credentialMap, array $input): array
    {
        $raw = [];
        if (is_array($existing)) {
            $decoded = json_decode((string)($existing['raw_json'] ?? '{}'), true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $raw['host'] = (string)($credentialMap['host'] ?? $raw['host'] ?? '');
        $raw['port'] = (string)($credentialMap['port'] ?? $raw['port'] ?? '');
        $raw['ssl'] = (string)($credentialMap['ssl'] ?? $raw['ssl'] ?? '');
        $raw['login_id'] = (string)($credentialMap['login_id'] ?? $raw['login_id'] ?? '');

        $raw['smtp_host'] = (string)($credentialMap['smtp_host'] ?? $raw['smtp_host'] ?? '');
        $raw['smtp_port'] = (string)($credentialMap['smtp_port'] ?? $raw['smtp_port'] ?? '');
        $raw['smtp_ssl'] = (string)($credentialMap['smtp_ssl'] ?? $raw['smtp_ssl'] ?? '');
        $raw['from_email'] = (string)($credentialMap['from_email'] ?? $raw['from_email'] ?? '');
        $raw['from_name'] = (string)($credentialMap['from_name'] ?? $raw['from_name'] ?? '');

        $providerHint = trim((string)($input['provider_hint'] ?? 'imap'));
        $raw['provider_hint'] = $providerHint !== '' ? $providerHint : 'imap';

        return $raw;
    }

    private function normalizeStatus(string $status, bool $allowEmpty): string
    {
        $status = strtoupper(trim($status));
        if ($status === '') {
            return $allowEmpty ? '' : 'ACTIVE';
        }
        if (!in_array($status, ['ACTIVE', 'INACTIVE', 'DELETED'], true)) {
            return 'ACTIVE';
        }
        return $status;
    }

    private function boolToInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private function normalizePolicyInt(mixed $value, int $default, int $min, int $max): int
    {
        $text = trim((string)$value);
        if ($text === '' || !preg_match('/^-?\d+$/', $text)) {
            return $default;
        }

        $parsed = (int)$text;
        if ($parsed < $min) {
            return $default;
        }
        if ($parsed > $max) {
            return $max;
        }

        return $parsed;
    }

    private function parsePolicyIntInput(mixed $value, string $field, int $min, int $max): int
    {
        $text = trim((string)$value);
        if ($text === '' || !preg_match('/^-?\d+$/', $text)) {
            throw new InvalidArgumentException($field . ' must be an integer');
        }

        $parsed = (int)$text;
        if ($parsed < $min || $parsed > $max) {
            throw new InvalidArgumentException($field . ' must be between ' . $min . ' and ' . $max);
        }

        return $parsed;
    }

    private function normalizeAllowedExtensions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;|]+/', strtolower($raw)) ?: [];
        $unique = [];
        $items = [];

        foreach ($parts as $part) {
            $ext = ltrim(trim((string)$part), '.');
            if ($ext === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $ext)) {
                continue;
            }
            if (isset($unique[$ext])) {
                continue;
            }
            $unique[$ext] = true;
            $items[] = $ext;
            if (count($items) >= 100) {
                break;
            }
        }

        return $items;
    }

    private function upsertPolicySetting(
        string $serviceCode,
        int $tenantId,
        string $key,
        string $value,
        string $type,
        int $actorUserPk
    ): void {
        $sql = 'SELECT TOP 1 idx
                FROM dbo.' . self::POLICY_TABLE . '
                WHERE service_code = :service_code
                  AND tenant_id = :tenant_id
                  AND setting_key = :setting_key';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'setting_key' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $idx = is_array($row) ? (int)($row['idx'] ?? 0) : 0;

        if ($idx > 0) {
            $setParts = [
                'setting_value = :setting_value',
                'setting_type = :setting_type',
            ];
            $params = [
                'idx' => $idx,
                'setting_value' => $value,
                'setting_type' => $type,
            ];
            if ($this->columnExists(self::POLICY_TABLE, 'is_deleted')) {
                $setParts[] = 'is_deleted = 0';
            }
            if ($this->columnExists(self::POLICY_TABLE, 'updated_by')) {
                $setParts[] = 'updated_by = :updated_by';
                $params['updated_by'] = $actorUserPk;
            }
            if ($this->columnExists(self::POLICY_TABLE, 'updated_at')) {
                $setParts[] = 'updated_at = GETDATE()';
            }
            $updateSql = 'UPDATE dbo.' . self::POLICY_TABLE
                . ' SET ' . implode(', ', $setParts)
                . ' WHERE idx = :idx';
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute($params);
            return;
        }

        $columns = ['service_code', 'tenant_id', 'setting_key', 'setting_value', 'setting_type'];
        $values = [':service_code', ':tenant_id', ':setting_key', ':setting_value', ':setting_type'];
        $params = [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'setting_key' => $key,
            'setting_value' => $value,
            'setting_type' => $type,
        ];

        if ($this->columnExists(self::POLICY_TABLE, 'updated_by')) {
            $columns[] = 'updated_by';
            $values[] = ':updated_by';
            $params['updated_by'] = $actorUserPk;
        }
        if ($this->columnExists(self::POLICY_TABLE, 'is_deleted')) {
            $columns[] = 'is_deleted';
            $values[] = '0';
        }
        if ($this->columnExists(self::POLICY_TABLE, 'regdate')) {
            $columns[] = 'regdate';
            $values[] = 'GETDATE()';
        } elseif ($this->columnExists(self::POLICY_TABLE, 'created_at')) {
            $columns[] = 'created_at';
            $values[] = 'GETDATE()';
        }
        if ($this->columnExists(self::POLICY_TABLE, 'updated_at')) {
            $columns[] = 'updated_at';
            $values[] = 'GETDATE()';
        }

        $insertSql = 'INSERT INTO dbo.' . self::POLICY_TABLE
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $insertStmt = $this->db->prepare($insertSql);
        $insertStmt->execute($params);
    }

    private function ensureMailSettingTable(): void
    {
        if ($this->tableExists(self::POLICY_TABLE)) {
            $this->ensureMailSettingColumns();
            return;
        }

        $createSql = 'CREATE TABLE dbo.' . self::POLICY_TABLE . ' (
            idx INT IDENTITY(1,1) PRIMARY KEY,
            service_code NVARCHAR(50) NOT NULL DEFAULT \'\',
            tenant_id INT NOT NULL DEFAULT 0,
            setting_key NVARCHAR(120) NOT NULL,
            setting_value NVARCHAR(MAX) NOT NULL DEFAULT \'\',
            setting_type NVARCHAR(20) NOT NULL DEFAULT \'string\',
            updated_by INT NOT NULL DEFAULT 0,
            is_deleted BIT NOT NULL DEFAULT 0,
            regdate DATETIME NOT NULL DEFAULT GETDATE(),
            updated_at DATETIME NULL
        )';

        try {
            $this->db->exec($createSql);
        } catch (\Throwable $e) {
            if (!$this->tableExists(self::POLICY_TABLE)) {
                throw $e;
            }
        }

        try {
            $this->db->exec(
                'CREATE UNIQUE INDEX UX_Tb_MailSetting_ScopeKey ON dbo.'
                . self::POLICY_TABLE
                . '(service_code, tenant_id, setting_key)'
            );
        } catch (\Throwable) {
            // 이미 생성된 인덱스면 무시
        }

        $this->tableExistsCache[self::POLICY_TABLE] = true;
    }

    private function ensureMailSettingColumns(): void
    {
        if (!$this->tableExists(self::POLICY_TABLE)) {
            return;
        }

        $columns = [
            'service_code' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD service_code NVARCHAR(50) NOT NULL DEFAULT \'\'',
            'tenant_id' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD tenant_id INT NOT NULL DEFAULT 0',
            'setting_key' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD setting_key NVARCHAR(120) NOT NULL DEFAULT \'\'',
            'setting_value' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD setting_value NVARCHAR(MAX) NOT NULL DEFAULT \'\'',
            'setting_type' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD setting_type NVARCHAR(20) NOT NULL DEFAULT \'string\'',
            'updated_by' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD updated_by INT NOT NULL DEFAULT 0',
            'is_deleted' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD is_deleted BIT NOT NULL DEFAULT 0',
            'regdate' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD regdate DATETIME NOT NULL DEFAULT GETDATE()',
            'updated_at' => 'ALTER TABLE dbo.' . self::POLICY_TABLE . ' ADD updated_at DATETIME NULL',
        ];

        foreach ($columns as $name => $ddl) {
            if ($this->columnExists(self::POLICY_TABLE, $name)) {
                continue;
            }
            try {
                $this->db->exec($ddl);
                $this->columnExistsCache[self::POLICY_TABLE . '.' . $name] = true;
            } catch (\Throwable) {
                // 동시 DDL 충돌 시 무시
            }
        }

        try {
            $this->db->exec(
                'CREATE UNIQUE INDEX UX_Tb_MailSetting_ScopeKey ON dbo.'
                . self::POLICY_TABLE
                . '(service_code, tenant_id, setting_key)'
            );
        } catch (\Throwable) {
            // 이미 생성된 인덱스면 무시
        }
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

    private function columnExists(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        if (!$this->tableExists($tableName)) {
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        $stmt = $this->db->prepare('SELECT CASE WHEN COL_LENGTH(:obj, :col) IS NULL THEN 0 ELSE 1 END AS exists_flag');
        $stmt->execute([
            'obj' => 'dbo.' . $tableName,
            'col' => $columnName,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $exists = ((int)($row['exists_flag'] ?? 0)) === 1;
        $this->columnExistsCache[$cacheKey] = $exists;

        return $exists;
    }
}
