<?php
declare(strict_types=1);

namespace SHVQ\Mail;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MailComposeService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private bool $draftMetaLoaded = false;
    private ?array $draftMeta = null;
    private const ATTACH_MAX_FILE_BYTES = 10485760; // 10MB
    private const ATTACH_MAX_TOTAL_BYTES = 26214400; // 25MB
    private const ATTACH_ALLOWED_EXT = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'zip', 'csv', 'txt', 'hwp',
    ];
    private const ATTACH_EXT_MIME_MAP = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
        'csv' => ['text/csv', 'application/csv'],
        'txt' => ['text/plain'],
        'hwp' => ['application/x-hwp', 'application/haansofthwp'],
    ];
    private const ATTACH_ALLOWED_MIME = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
        'text/csv',
        'application/csv',
        'text/plain',
        'application/x-hwp',
        'application/haansofthwp',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function sendPolicy(string $serviceCode = 'shvq', int $tenantId = 0): array
    {
        $policy = $this->resolveAttachmentPolicy($serviceCode, $tenantId);

        return [
            'max_file_bytes' => (int)$policy['max_file_bytes'],
            'max_total_bytes' => (int)$policy['max_total_bytes'],
            'max_file_mb' => (int)$policy['max_file_mb'],
            'max_total_mb' => (int)$policy['max_total_mb'],
            'max_per_file_mb' => (int)$policy['max_file_mb'],
            'allowed_extensions' => (array)$policy['allowed_extensions'],
            'allowed_exts' => implode(',', (array)$policy['allowed_extensions']),
            'allowed_mime_types' => (array)$policy['allowed_mime_types'],
        ];
    }

    public function sendMail(string $serviceCode, int $tenantId, array $actor, array $payload, array $files = []): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $accountIdx = (int)($payload['account_idx'] ?? $payload['account_id'] ?? 0);
        if ($accountIdx <= 0) {
            throw new InvalidArgumentException('account_idx is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $raw = json_decode((string)($account['raw_json'] ?? '{}'), true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $credentials = $this->loadCredentialMap($accountIdx);

        $toList = $this->normalizeRecipientList($payload['to'] ?? $payload['to_list'] ?? []);
        $ccList = $this->normalizeRecipientList($payload['cc'] ?? $payload['cc_list'] ?? []);
        $bccList = $this->normalizeRecipientList($payload['bcc'] ?? $payload['bcc_list'] ?? []);
        if ($toList === []) {
            throw new InvalidArgumentException('to is required');
        }

        $subject = $this->sanitizeHeaderValue((string)($payload['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('subject is required');
        }

        $bodyHtml = (string)($payload['body_html'] ?? $payload['body'] ?? '');
        /* XSS 방지: 스크립트·이벤트핸들러·위험 속성 제거 */
        $bodyHtml = $this->sanitizeBodyHtml($bodyHtml);
        $bodyText = trim((string)($payload['body_text'] ?? ''));
        if ($bodyText === '') {
            $bodyText = trim(strip_tags($bodyHtml));
        }
        if ($bodyHtml === '' && $bodyText !== '') {
            $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        }

        $smtpHost = trim((string)($credentials['smtp_host'] ?? ''));
        if ($smtpHost === '') {
            $smtpHost = trim((string)($raw['smtp_host'] ?? ''));
        }
        if ($smtpHost === '') {
            $smtpHost = trim((string)($credentials['host'] ?? ''));
        }
        if ($smtpHost === '') {
            $smtpHost = trim((string)($raw['host'] ?? ''));
        }

        $smtpPortRaw = (string)($credentials['smtp_port'] ?? '');
        if (trim($smtpPortRaw) === '') {
            $smtpPortRaw = (string)($raw['smtp_port'] ?? '');
        }
        $smtpPort = (int)$smtpPortRaw;

        $smtpSslRaw = $credentials['smtp_ssl'] ?? null;
        if ($smtpSslRaw === null || (is_string($smtpSslRaw) && trim($smtpSslRaw) === '')) {
            $smtpSslRaw = $raw['smtp_ssl'] ?? null;
        }
        if ($smtpSslRaw === null || (is_string($smtpSslRaw) && trim($smtpSslRaw) === '')) {
            $smtpSslRaw = $credentials['ssl'] ?? null;
        }
        if ($smtpSslRaw === null || (is_string($smtpSslRaw) && trim($smtpSslRaw) === '')) {
            $smtpSslRaw = $raw['ssl'] ?? null;
        }
        if ($smtpSslRaw === null || (is_string($smtpSslRaw) && trim($smtpSslRaw) === '')) {
            $smtpSslRaw = true;
        }
        $smtpSsl = $this->toBool($smtpSslRaw);

        if ($smtpPort <= 0) {
            $smtpPort = $smtpSsl ? 465 : 587;
        }

        $smtpUser = trim((string)($credentials['smtp_login_id'] ?? ''));
        if ($smtpUser === '') {
            $smtpUser = trim((string)($credentials['login_id'] ?? ''));
        }
        if ($smtpUser === '') {
            $smtpUser = trim((string)($raw['smtp_login_id'] ?? ''));
        }
        if ($smtpUser === '') {
            $smtpUser = trim((string)($raw['login_id'] ?? ''));
        }

        $smtpPass = (string)($credentials['smtp_password'] ?? '');
        if ($smtpPass === '') {
            $smtpPass = (string)($credentials['password'] ?? '');
        }
        if ($smtpPass === '') {
            $smtpPass = (string)($raw['smtp_password'] ?? '');
        }
        if ($smtpPass === '') {
            $smtpPass = (string)($raw['password'] ?? '');
        }

        if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
            throw new RuntimeException('smtp host/login_id/password is required');
        }

        $fromEmail = trim((string)($payload['from_email'] ?? ''));
        if ($fromEmail === '') {
            $fromEmail = trim((string)($credentials['from_email'] ?? ''));
        }
        if ($fromEmail === '') {
            $fromEmail = trim((string)($raw['from_email'] ?? ''));
        }
        if ($fromEmail === '') {
            $fromEmail = $smtpUser;
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('from_email is invalid');
        }

        $fromName = trim((string)($payload['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = trim((string)($credentials['from_name'] ?? ''));
        }
        if ($fromName === '') {
            $fromName = trim((string)($raw['from_name'] ?? ''));
        }
        if ($fromName === '') {
            $fromName = trim((string)($account['display_name'] ?? $fromEmail));
        }
        if ($fromName === '') {
            $fromName = $fromEmail;
        }

        $replyTo = trim((string)($payload['reply_to'] ?? ''));
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = '';
        }

        $policy = $this->resolveAttachmentPolicy($serviceCode, $tenantId);
        $attachments = $this->collectUploadedFiles($files, $policy);

        $messageId = '<' . md5(uniqid('mail_', true)) . '@' . $this->messageIdHost($fromEmail) . '>';
        $mime = $this->buildMimeMessage($fromName, $fromEmail, $toList, $ccList, $subject, $bodyText, $bodyHtml, $attachments, $replyTo, $messageId);

        $recipients = array_values(array_unique(array_merge($toList, $ccList, $bccList)));

        $smtpResult = $this->smtpSendWithFallback(
            $smtpHost,
            $smtpPort,
            $smtpSsl,
            $smtpUser,
            $smtpPass,
            $fromEmail,
            $recipients,
            $mime
        );

        if (!($smtpResult['ok'] ?? false)) {
            throw new RuntimeException((string)($smtpResult['message'] ?? 'SMTP send failed'));
        }

        $sentCopy = $this->appendSentCopy($credentials, $raw, $mime);

        $draftId = (int)($payload['draft_id'] ?? $payload['id'] ?? 0);
        if ($draftId > 0) {
            try {
                $this->deleteDraft($serviceCode, $tenantId, $actor, $draftId);
            } catch (\Throwable $_) {
                // 발송 성공 우선
            }
        }

        return [
            'account_idx' => $accountIdx,
            'accepted' => (int)($smtpResult['accepted'] ?? 0),
            'rejected' => (int)($smtpResult['rejected'] ?? 0),
            'message_id' => $messageId,
            'sent_copy_appended' => $sentCopy,
            'to_count' => count($toList),
            'cc_count' => count($ccList),
            'bcc_count' => count($bccList),
            'attachment_count' => count($attachments),
            'sent_at' => date('c'),
        ];
    }

    public function saveDraft(string $serviceCode, int $tenantId, array $actor, array $payload): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $meta = $this->resolveDraftMeta();
        if ($meta === null) {
            throw new RuntimeException('Tb_Mail_Draft table is missing');
        }

        $draftId = (int)($payload['id'] ?? $payload['draft_id'] ?? 0);
        $accountIdx = (int)($payload['account_idx'] ?? $payload['account_id'] ?? 0);

        $subject = trim((string)($payload['subject'] ?? ''));
        $bodyHtml = (string)($payload['body_html'] ?? $payload['body'] ?? '');

        $toList = $this->normalizeRecipientList($payload['to'] ?? $payload['to_list'] ?? []);
        $ccList = $this->normalizeRecipientList($payload['cc'] ?? $payload['cc_list'] ?? []);
        $bccList = $this->normalizeRecipientList($payload['bcc'] ?? $payload['bcc_list'] ?? []);

        $toJson = json_encode($toList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ccJson = json_encode($ccList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bccJson = json_encode($bccList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($toJson)) {
            $toJson = '[]';
        }
        if (!is_string($ccJson)) {
            $ccJson = '[]';
        }
        if (!is_string($bccJson)) {
            $bccJson = '[]';
        }

        $replyMode = trim((string)($payload['reply_mode'] ?? $payload['mode'] ?? ''));
        $sourceFolder = trim((string)($payload['source_folder'] ?? ''));
        $sourceUid = (int)($payload['source_uid'] ?? 0);

        if ($draftId > 0) {
            $setParts = [];
            $params = [
                'draft_id' => $draftId,
            ];

            if (!empty($meta['account'])) {
                $setParts[] = $this->qcol((string)$meta['account']) . ' = :account_idx';
                $params['account_idx'] = $accountIdx;
            }
            if (!empty($meta['subject'])) {
                $setParts[] = $this->qcol((string)$meta['subject']) . ' = :subject';
                $params['subject'] = $subject;
            }
            if (!empty($meta['body_html'])) {
                $setParts[] = $this->qcol((string)$meta['body_html']) . ' = :body_html';
                $params['body_html'] = $bodyHtml;
            }
            if (!empty($meta['to_list'])) {
                $setParts[] = $this->qcol((string)$meta['to_list']) . ' = :to_list';
                $params['to_list'] = $toJson;
            }
            if (!empty($meta['cc_list'])) {
                $setParts[] = $this->qcol((string)$meta['cc_list']) . ' = :cc_list';
                $params['cc_list'] = $ccJson;
            }
            if (!empty($meta['bcc_list'])) {
                $setParts[] = $this->qcol((string)$meta['bcc_list']) . ' = :bcc_list';
                $params['bcc_list'] = $bccJson;
            }
            if (!empty($meta['reply_mode'])) {
                $setParts[] = $this->qcol((string)$meta['reply_mode']) . ' = :reply_mode';
                $params['reply_mode'] = $replyMode;
            }
            if (!empty($meta['source_folder'])) {
                $setParts[] = $this->qcol((string)$meta['source_folder']) . ' = :source_folder';
                $params['source_folder'] = $sourceFolder;
            }
            if (!empty($meta['source_uid'])) {
                $setParts[] = $this->qcol((string)$meta['source_uid']) . ' = :source_uid';
                $params['source_uid'] = $sourceUid;
            }
            if (!empty($meta['is_deleted'])) {
                $setParts[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
            }
            if (!empty($meta['updated_at'])) {
                $setParts[] = $this->qcol((string)$meta['updated_at']) . ' = GETDATE()';
            }

            if ($setParts === []) {
                throw new RuntimeException('no writable columns in Tb_Mail_Draft');
            }

            $whereParts = [$this->qcol((string)$meta['id']) . ' = :draft_id'];
            $ownerWhere = $this->buildOwnerWhereClause($meta, $actor, $params);
            if ($ownerWhere !== '') {
                $whereParts[] = $ownerWhere;
            }
            $scopeWhere = $this->buildScopeWhereClause($meta, $serviceCode, $tenantId, $params);
            if ($scopeWhere !== '') {
                $whereParts[] = $scopeWhere;
            }

            $sql = 'UPDATE dbo.Tb_Mail_Draft
                    SET ' . implode(', ', $setParts)
                . ' WHERE ' . implode(' AND ', $whereParts);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ((int)$stmt->rowCount() <= 0) {
                throw new RuntimeException('MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN');
            }
        } else {
            $columns = [];
            $values = [];
            $params = [];

            if (!empty($meta['account'])) {
                $columns[] = (string)$meta['account'];
                $values[] = ':account_idx';
                $params['account_idx'] = $accountIdx;
            }
            if (!empty($meta['subject'])) {
                $columns[] = (string)$meta['subject'];
                $values[] = ':subject';
                $params['subject'] = $subject;
            }
            if (!empty($meta['body_html'])) {
                $columns[] = (string)$meta['body_html'];
                $values[] = ':body_html';
                $params['body_html'] = $bodyHtml;
            }
            if (!empty($meta['to_list'])) {
                $columns[] = (string)$meta['to_list'];
                $values[] = ':to_list';
                $params['to_list'] = $toJson;
            }
            if (!empty($meta['cc_list'])) {
                $columns[] = (string)$meta['cc_list'];
                $values[] = ':cc_list';
                $params['cc_list'] = $ccJson;
            }
            if (!empty($meta['bcc_list'])) {
                $columns[] = (string)$meta['bcc_list'];
                $values[] = ':bcc_list';
                $params['bcc_list'] = $bccJson;
            }
            if (!empty($meta['reply_mode'])) {
                $columns[] = (string)$meta['reply_mode'];
                $values[] = ':reply_mode';
                $params['reply_mode'] = $replyMode;
            }
            if (!empty($meta['source_folder'])) {
                $columns[] = (string)$meta['source_folder'];
                $values[] = ':source_folder';
                $params['source_folder'] = $sourceFolder;
            }
            if (!empty($meta['source_uid'])) {
                $columns[] = (string)$meta['source_uid'];
                $values[] = ':source_uid';
                $params['source_uid'] = $sourceUid;
            }

            $this->appendOwnerColumns($meta, $actor, $columns, $values, $params);
            $this->appendScopeColumns($meta, $serviceCode, $tenantId, $columns, $values, $params);

            if (!empty($meta['is_deleted'])) {
                $columns[] = (string)$meta['is_deleted'];
                $values[] = '0';
            }
            if (!empty($meta['created_at'])) {
                $columns[] = (string)$meta['created_at'];
                $values[] = 'GETDATE()';
            }
            if (!empty($meta['updated_at'])) {
                $columns[] = (string)$meta['updated_at'];
                $values[] = 'GETDATE()';
            }

            $sql = 'INSERT INTO dbo.Tb_Mail_Draft (' . implode(', ', array_map([$this, 'qcol'], $columns)) . ')'
                . ' VALUES (' . implode(', ', $values) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $draftId = $this->findLatestDraftId($meta, $actor, $serviceCode, $tenantId);
            if ($draftId <= 0) {
                throw new RuntimeException('MAIL_DRAFT_SAVE_FAILED');
            }
        }

        $saved = $this->loadDraftRowById($meta, $draftId, $actor, $serviceCode, $tenantId);
        if ($saved === null) {
            throw new RuntimeException('MAIL_DRAFT_SAVE_FAILED');
        }

        return $this->mapDraftRow($saved, $meta);
    }

    public function listDrafts(string $serviceCode, int $tenantId, array $actor, int $accountIdx = 0, int $page = 1, int $limit = 50): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $meta = $this->resolveDraftMeta();
        if ($meta === null) {
            return [
                'items' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'pages' => 1,
            ];
        }

        $params = [];
        $where = ['1=1'];

        $ownerWhere = $this->buildOwnerWhereClause($meta, $actor, $params);
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
        }

        $scopeWhere = $this->buildScopeWhereClause($meta, $serviceCode, $tenantId, $params);
        if ($scopeWhere !== '') {
            $where[] = $scopeWhere;
        }

        if ($accountIdx > 0 && !empty($meta['account'])) {
            $where[] = $this->qcol((string)$meta['account']) . ' = :account_idx';
            $params['account_idx'] = $accountIdx;
        }

        if (!empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = 'SELECT COUNT(1) AS cnt FROM dbo.Tb_Mail_Draft WHERE ' . $whereSql;
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($countRow['cnt'] ?? 0);

        $pages = max(1, (int)ceil(($total > 0 ? $total : 1) / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $orderCol = !empty($meta['updated_at'])
            ? $this->qcol((string)$meta['updated_at'])
            : (!empty($meta['created_at']) ? $this->qcol((string)$meta['created_at']) : $this->qcol((string)$meta['id']));

        $listSql = 'SELECT * FROM dbo.Tb_Mail_Draft'
            . ' WHERE ' . $whereSql
            . ' ORDER BY ' . $orderCol . ' DESC, ' . $this->qcol((string)$meta['id']) . ' DESC'
            . ' OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$limit . ' ROWS ONLY';

        $listStmt = $this->db->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->mapDraftRow($row, $meta);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
        ];
    }

    public function getDraftDetail(string $serviceCode, int $tenantId, array $actor, int $draftId): array
    {
        if ($draftId <= 0) {
            throw new InvalidArgumentException('draft_id is required');
        }

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $meta = $this->resolveDraftMeta();
        if ($meta === null) {
            throw new RuntimeException('Tb_Mail_Draft table is missing');
        }

        $row = $this->loadDraftRowById($meta, $draftId, $actor, $serviceCode, $tenantId);
        if ($row === null) {
            throw new RuntimeException('MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN');
        }

        return $this->mapDraftRow($row, $meta);
    }

    public function deleteDraft(string $serviceCode, int $tenantId, array $actor, int $draftId): array
    {
        if ($draftId <= 0) {
            throw new InvalidArgumentException('draft_id is required');
        }

        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $meta = $this->resolveDraftMeta();
        if ($meta === null) {
            throw new RuntimeException('Tb_Mail_Draft table is missing');
        }

        $params = [
            'draft_id' => $draftId,
        ];
        $where = [$this->qcol((string)$meta['id']) . ' = :draft_id'];

        $ownerWhere = $this->buildOwnerWhereClause($meta, $actor, $params);
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
        }

        $scopeWhere = $this->buildScopeWhereClause($meta, $serviceCode, $tenantId, $params);
        if ($scopeWhere !== '') {
            $where[] = $scopeWhere;
        }

        if (!empty($meta['is_deleted'])) {
            $setParts = [$this->qcol((string)$meta['is_deleted']) . ' = 1'];
            if (!empty($meta['deleted_at'])) {
                $setParts[] = $this->qcol((string)$meta['deleted_at']) . ' = GETDATE()';
            }
            if (!empty($meta['updated_at'])) {
                $setParts[] = $this->qcol((string)$meta['updated_at']) . ' = GETDATE()';
            }

            $sql = 'UPDATE dbo.Tb_Mail_Draft SET ' . implode(', ', $setParts)
                . ' WHERE ' . implode(' AND ', $where);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ((int)$stmt->rowCount() <= 0) {
                throw new RuntimeException('MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN');
            }
        } else {
            $sql = 'DELETE FROM dbo.Tb_Mail_Draft WHERE ' . implode(' AND ', $where);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ((int)$stmt->rowCount() <= 0) {
                throw new RuntimeException('MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN');
            }
        }

        return [
            'draft_id' => $draftId,
            'deleted' => true,
        ];
    }

    public function buildReplyPayload(array $messageDetail, string $mode = 'reply', array $identityEmails = []): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['reply', 'reply_all', 'forward'], true)) {
            $mode = 'reply';
        }

        $identityMap = [];
        foreach ($identityEmails as $identity) {
            $email = strtolower(trim((string)$identity));
            if ($email !== '') {
                $identityMap[$email] = true;
            }
        }

        $fromEmails = $this->extractEmailsFromText((string)($messageDetail['from_address'] ?? $messageDetail['from'] ?? ''));
        $toEmails = $this->extractEmailsFromText((string)($messageDetail['to_address'] ?? $messageDetail['to'] ?? ''));
        $ccEmails = $this->extractEmailsFromText((string)($messageDetail['cc_address'] ?? $messageDetail['cc'] ?? ''));

        $to = [];
        $cc = [];

        if ($mode === 'reply') {
            $to = array_values($fromEmails);
        } elseif ($mode === 'reply_all') {
            $to = array_values($fromEmails);
            $all = array_values(array_unique(array_merge($fromEmails, $toEmails, $ccEmails)));
            $primaryTo = isset($to[0]) ? strtolower($to[0]) : '';
            foreach ($all as $email) {
                $low = strtolower($email);
                if ($low === '' || isset($identityMap[$low])) {
                    continue;
                }
                if ($primaryTo !== '' && $low === $primaryTo) {
                    continue;
                }
                $cc[] = $email;
            }
        }

        $subject = trim((string)($messageDetail['subject'] ?? ''));
        if ($mode === 'forward') {
            if (!preg_match('/^fwd\s*:/i', $subject)) {
                $subject = 'Fwd: ' . $subject;
            }
        } else {
            if (!preg_match('/^re\s*:/i', $subject)) {
                $subject = 'Re: ' . $subject;
            }
        }

        $origDate = trim((string)($messageDetail['date'] ?? ''));
        $origFrom = trim((string)($messageDetail['from_address'] ?? $messageDetail['from'] ?? ''));
        $origTo = trim((string)($messageDetail['to_address'] ?? $messageDetail['to'] ?? ''));
        $origBodyHtml = (string)($messageDetail['body_html'] ?? '');
        $origBodyText = trim((string)($messageDetail['body_text'] ?? ''));

        if ($origBodyHtml === '' && $origBodyText !== '') {
            $origBodyHtml = nl2br(htmlspecialchars($origBodyText, ENT_QUOTES, 'UTF-8'));
        }

        $quoted = '<div><br></div><div style="border-left:3px solid #d9d9d9;padding-left:12px;color:#555;">'
            . '<div><b>From:</b> ' . htmlspecialchars($origFrom, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><b>Date:</b> ' . htmlspecialchars($origDate, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><b>To:</b> ' . htmlspecialchars($origTo, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><b>Subject:</b> ' . htmlspecialchars((string)($messageDetail['subject'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div style="margin-top:8px;">' . $origBodyHtml . '</div>'
            . '</div>';

        return [
            'mode' => $mode,
            'to' => array_values(array_unique($to)),
            'cc' => array_values(array_unique($cc)),
            'bcc' => [],
            'subject' => $subject,
            'body_html' => $quoted,
            'source_uid' => (int)($messageDetail['uid'] ?? 0),
            'source_folder' => (string)($messageDetail['folder'] ?? 'INBOX'),
        ];
    }

    private function buildMimeMessage(
        string $fromName,
        string $fromEmail,
        array $toList,
        array $ccList,
        string $subject,
        string $bodyText,
        string $bodyHtml,
        array $attachments,
        string $replyTo,
        string $messageId
    ): string {
        $boundarySeed = md5(uniqid('mail_mime_', true));
        $mixedBoundary = 'mixed_' . $boundarySeed;
        $altBoundary = 'alt_' . $boundarySeed;

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: ' . $this->encodeHeaderText($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'To: ' . implode(', ', $toList);
        if ($ccList !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccList);
        }
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Subject: ' . $this->encodeHeaderText($subject);

        $hasAttachment = $attachments !== [];

        if ($hasAttachment) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';
            $body = '';
            $body .= '--' . $mixedBoundary . "\r\n";
            $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n\r\n";
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
            $body = '';
        }

        $body .= '--' . $altBoundary . "\r\n";
        $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($bodyText)) . "\r\n";

        $body .= '--' . $altBoundary . "\r\n";
        $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($bodyHtml)) . "\r\n";

        $body .= '--' . $altBoundary . "--\r\n";

        if ($hasAttachment) {
            foreach ($attachments as $attachment) {
                $path = (string)($attachment['path'] ?? '');
                if ($path === '' || !is_file($path)) {
                    continue;
                }

                $raw = @file_get_contents($path);
                if (!is_string($raw)) {
                    continue;
                }

                $name = trim((string)($attachment['name'] ?? 'file'));
                if ($name === '') {
                    $name = 'file';
                }

                $mime = $this->guessMimeType($name);

                $body .= '--' . $mixedBoundary . "\r\n";
                $body .= 'Content-Type: ' . $mime . '; name="' . addslashes($name) . '"' . "\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . addslashes($name) . '"' . "\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($raw)) . "\r\n";
            }

            $body .= '--' . $mixedBoundary . "--\r\n";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function smtpSend(
        string $host,
        int $port,
        bool $ssl,
        string $username,
        string $password,
        string $from,
        array $recipients,
        string $message
    ): array {
        $recipients = array_values(array_filter(array_map('trim', $recipients), static fn (string $v): bool => $v !== ''));
        if ($recipients === []) {
            return [
                'ok' => false,
                'message' => 'recipient is required',
                'accepted' => 0,
                'rejected' => 0,
            ];
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $scheme = $ssl ? 'ssl' : 'tcp';
        $remote = $scheme . '://' . $host . ':' . $port;

        $stream = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) {
            return [
                'ok' => false,
                'message' => 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')',
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        stream_set_timeout($stream, 20);

        $banner = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($banner, ['220'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'SMTP banner invalid: ' . trim($banner),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        $hostName = gethostname() ?: 'localhost';

        fwrite($stream, 'EHLO ' . $hostName . "\r\n");
        $respEhlo = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respEhlo, ['250'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'EHLO failed: ' . trim($respEhlo),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        if (!$ssl) {
            fwrite($stream, "STARTTLS\r\n");
            $respStartTls = $this->readSmtpResponse($stream);
            if (!$this->smtpCodeIn($respStartTls, ['220'])) {
                fclose($stream);
                return [
                    'ok' => false,
                    'message' => 'STARTTLS failed: ' . trim($respStartTls),
                    'accepted' => 0,
                    'rejected' => count($recipients),
                ];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($stream);
                return [
                    'ok' => false,
                    'message' => 'STARTTLS negotiation failed',
                    'accepted' => 0,
                    'rejected' => count($recipients),
                ];
            }

            fwrite($stream, 'EHLO ' . $hostName . "\r\n");
            $respEhlo = $this->readSmtpResponse($stream);
            if (!$this->smtpCodeIn($respEhlo, ['250'])) {
                fclose($stream);
                return [
                    'ok' => false,
                    'message' => 'EHLO after STARTTLS failed: ' . trim($respEhlo),
                    'accepted' => 0,
                    'rejected' => count($recipients),
                ];
            }
        }

        fwrite($stream, "AUTH LOGIN\r\n");
        $respAuth = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respAuth, ['334'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'AUTH LOGIN failed: ' . trim($respAuth),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        fwrite($stream, base64_encode($username) . "\r\n");
        $respUser = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respUser, ['334'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'SMTP username rejected: ' . trim($respUser),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        fwrite($stream, base64_encode($password) . "\r\n");
        $respPass = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respPass, ['235'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'SMTP password rejected: ' . trim($respPass),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        fwrite($stream, 'MAIL FROM:<' . $from . ">\r\n");
        $respFrom = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respFrom, ['250'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'MAIL FROM failed: ' . trim($respFrom),
                'accepted' => 0,
                'rejected' => count($recipients),
            ];
        }

        $accepted = 0;
        $rejected = 0;
        foreach ($recipients as $recipient) {
            fwrite($stream, 'RCPT TO:<' . $recipient . ">\r\n");
            $respRcpt = $this->readSmtpResponse($stream);
            if ($this->smtpCodeIn($respRcpt, ['250', '251', '252'])) {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        if ($accepted <= 0) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'all recipients were rejected',
                'accepted' => 0,
                'rejected' => $rejected,
            ];
        }

        fwrite($stream, "DATA\r\n");
        $respData = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respData, ['354'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'DATA failed: ' . trim($respData),
                'accepted' => $accepted,
                'rejected' => $rejected,
            ];
        }

        $safeMessage = preg_replace('/(?m)^\./', '..', $message);
        if (!is_string($safeMessage)) {
            $safeMessage = $message;
        }

        fwrite($stream, $safeMessage . "\r\n.\r\n");
        $respSend = $this->readSmtpResponse($stream);
        if (!$this->smtpCodeIn($respSend, ['250'])) {
            fclose($stream);
            return [
                'ok' => false,
                'message' => 'message send failed: ' . trim($respSend),
                'accepted' => $accepted,
                'rejected' => $rejected,
            ];
        }

        fwrite($stream, "QUIT\r\n");
        fclose($stream);

        return [
            'ok' => true,
            'message' => 'sent',
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    private function smtpSendWithFallback(
        string $host,
        int $port,
        bool $ssl,
        string $username,
        string $password,
        string $from,
        array $recipients,
        string $message
    ): array {
        $primary = $this->smtpSend($host, $port, $ssl, $username, $password, $from, $recipients, $message);
        if (($primary['ok'] ?? false) === true) {
            $primary['used_port'] = $port;
            $primary['used_ssl'] = $ssl ? 1 : 0;
            return $primary;
        }

        if (!$this->shouldFallbackToStartTls($primary, $port, $ssl)) {
            $primary['used_port'] = $port;
            $primary['used_ssl'] = $ssl ? 1 : 0;
            return $primary;
        }

        $fallback = $this->smtpSend($host, 587, false, $username, $password, $from, $recipients, $message);
        if (($fallback['ok'] ?? false) === true) {
            $fallback['message'] = 'sent (fallback 587 STARTTLS)';
            $fallback['used_port'] = 587;
            $fallback['used_ssl'] = 0;
            $fallback['fallback_used'] = 1;
            $fallback['fallback_from_port'] = $port;
            $fallback['fallback_from_ssl'] = $ssl ? 1 : 0;
            return $fallback;
        }

        $primaryMessage = trim((string)($primary['message'] ?? 'SMTP send failed'));
        $fallbackMessage = trim((string)($fallback['message'] ?? 'SMTP send failed'));

        return [
            'ok' => false,
            'message' => $primaryMessage . '; fallback 587 failed: ' . $fallbackMessage,
            'accepted' => (int)($fallback['accepted'] ?? $primary['accepted'] ?? 0),
            'rejected' => (int)($fallback['rejected'] ?? $primary['rejected'] ?? count($recipients)),
            'used_port' => $port,
            'used_ssl' => $ssl ? 1 : 0,
            'fallback_port' => 587,
            'fallback_ssl' => 0,
        ];
    }

    private function shouldFallbackToStartTls(array $result, int $port, bool $ssl): bool
    {
        if (!$ssl || $port !== 465) {
            return false;
        }

        $message = strtolower(trim((string)($result['message'] ?? '')));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'smtp connect failed')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection timed out')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'network is unreachable')
            || str_contains($message, 'no route to host');
    }

    private function readSmtpResponse($stream): string
    {
        $response = '';
        $guard = 0;

        while (!feof($stream) && $guard < 100) {
            $line = fgets($stream, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            $guard++;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return $response;
    }

    private function smtpCodeIn(string $response, array $codes): bool
    {
        $code = substr(trim($response), 0, 3);
        return in_array($code, $codes, true);
    }

    private function appendSentCopy(array $credentials, array $raw, string $mime): bool
    {
        if (!function_exists('imap_open') || !function_exists('imap_append')) {
            return false;
        }

        $host = trim((string)($credentials['host'] ?? $raw['host'] ?? ''));
        $port = (int)($credentials['port'] ?? $raw['port'] ?? 993);
        $ssl = $this->toBool($credentials['ssl'] ?? $raw['ssl'] ?? true);
        $loginId = trim((string)($credentials['login_id'] ?? $raw['login_id'] ?? ''));
        $password = (string)($credentials['password'] ?? $raw['password'] ?? '');

        if ($host === '' || $loginId === '' || $password === '') {
            return false;
        }
        if ($port <= 0) {
            $port = 993;
        }

        $flags = '/imap' . ($ssl ? '/ssl/validate-cert' : '/tls/validate-cert');
        $baseMailbox = '{' . $host . ':' . $port . $flags . '}';

        $stream = @imap_open($baseMailbox . 'INBOX', $loginId, $password, 0, 1);
        if (!$stream) {
            return false;
        }

        $candidates = [];
        foreach (['sent_folder', 'sent_mailbox'] as $key) {
            $candidate = trim((string)($raw[$key] ?? $credentials[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        $candidates = array_merge($candidates, ['Sent', '보낸메일함', 'INBOX.Sent']);
        $candidates = array_values(array_unique($candidates));

        $ok = false;
        foreach ($candidates as $folder) {
            $encoded = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
            if (@imap_append($stream, $baseMailbox . $encoded, $mime, '\\Seen')) {
                $ok = true;
                break;
            }
        }

        @imap_close($stream);
        return $ok;
    }

    private function resolveAttachmentPolicy(string $serviceCode, int $tenantId): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $maxFileMb = (int)(self::ATTACH_MAX_FILE_BYTES / 1024 / 1024);
        $maxTotalMb = (int)(self::ATTACH_MAX_TOTAL_BYTES / 1024 / 1024);
        $allowedExtensions = self::ATTACH_ALLOWED_EXT;

        try {
            if (class_exists(MailAccountService::class)) {
                $settingsService = new MailAccountService($this->db);
                $settings = $settingsService->getAdminSettings($serviceCode, $tenantId);
                if (is_array($settings)) {
                    $candidateMaxFile = (int)($settings['max_per_file_mb'] ?? 0);
                    $candidateMaxTotal = (int)($settings['max_total_mb'] ?? 0);
                    if ($candidateMaxFile > 0) {
                        $maxFileMb = min(100, $candidateMaxFile);
                    }
                    if ($candidateMaxTotal > 0) {
                        $maxTotalMb = min(500, $candidateMaxTotal);
                    }

                    if (array_key_exists('allowed_extensions', $settings)) {
                        $allowedExtensions = $this->normalizeAllowedExtensions($settings['allowed_extensions']);
                    } elseif (array_key_exists('allowed_exts', $settings)) {
                        $allowedExtensions = $this->normalizeAllowedExtensions((string)$settings['allowed_exts']);
                    }
                }
            }
        } catch (\Throwable $_) {
            // 정책 로드 실패 시 안전한 기본 정책 유지
        }

        if ($maxTotalMb < $maxFileMb) {
            $maxTotalMb = $maxFileMb;
        }

        $maxFileBytes = max(1, $maxFileMb) * 1024 * 1024;
        $maxTotalBytes = max(1, $maxTotalMb) * 1024 * 1024;
        $allowedMimeTypes = $this->mimeTypesForExtensions($allowedExtensions);

        return [
            'max_file_bytes' => $maxFileBytes,
            'max_total_bytes' => $maxTotalBytes,
            'max_file_mb' => max(1, $maxFileMb),
            'max_total_mb' => max(1, $maxTotalMb),
            'allowed_extensions' => $allowedExtensions,
            'allowed_mime_types' => $allowedMimeTypes,
        ];
    }

    private function normalizeAllowedExtensions(mixed $value): array
    {
        $tokens = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $tokens[] = (string)$item;
            }
        } else {
            $raw = trim((string)$value);
            if ($raw === '') {
                return [];
            }
            $tokens = preg_split('/[\s,;|]+/', $raw) ?: [];
        }

        $unique = [];
        $extensions = [];
        foreach ($tokens as $token) {
            $ext = strtolower(ltrim(trim((string)$token), '.'));
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
            $extensions[] = $ext;
            if (count($extensions) >= 100) {
                break;
            }
        }

        return $extensions;
    }

    private function mimeTypesForExtensions(array $extensions): array
    {
        $result = [];
        foreach ($extensions as $extension) {
            foreach ($this->mimeTypesForExtension((string)$extension) as $mime) {
                $result[$mime] = true;
            }
        }

        return array_keys($result);
    }

    private function mimeTypesForExtension(string $extension): array
    {
        $extension = strtolower(trim($extension));
        if ($extension === '') {
            return [];
        }

        $mapped = self::ATTACH_EXT_MIME_MAP[$extension] ?? [];
        if (!is_array($mapped) || $mapped === []) {
            return [];
        }

        $list = [];
        foreach ($mapped as $mime) {
            $mime = strtolower(trim((string)$mime));
            if ($mime !== '') {
                $list[] = $mime;
            }
        }

        return $list;
    }

    private function collectUploadedFiles(array $files, array $policy): array
    {
        $list = [];
        $totalSize = 0;
        $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;

        try {
            foreach ($files as $field => $spec) {
                if (!is_array($spec) || !array_key_exists('name', $spec)) {
                    continue;
                }

                $names = $spec['name'];
                $tmpNames = $spec['tmp_name'] ?? [];
                $errors = $spec['error'] ?? [];
                $sizes = $spec['size'] ?? [];

                if (is_array($names)) {
                    foreach ($names as $idx => $name) {
                        $tmp = is_array($tmpNames) ? (string)($tmpNames[$idx] ?? '') : '';
                        $err = is_array($errors) ? (int)($errors[$idx] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                        $size = is_array($sizes) ? (int)($sizes[$idx] ?? 0) : 0;
                        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
                            continue;
                        }

                        $safeName = trim((string)$name);
                        if ($safeName === '') {
                            $safeName = basename($tmp);
                        }
                        $this->appendUploadedFile($list, (string)$field, $safeName, $tmp, $size, $finfo, $totalSize, $policy);
                    }
                    continue;
                }

                $tmp = is_scalar($tmpNames) ? (string)$tmpNames : '';
                $err = is_scalar($errors) ? (int)$errors : UPLOAD_ERR_NO_FILE;
                $size = is_scalar($sizes) ? (int)$sizes : 0;

                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
                    continue;
                }

                $safeName = trim((string)$names);
                if ($safeName === '') {
                    $safeName = basename($tmp);
                }

                $this->appendUploadedFile($list, (string)$field, $safeName, $tmp, $size, $finfo, $totalSize, $policy);
            }
        } finally {
            if ((is_resource($finfo) || is_object($finfo)) && function_exists('finfo_close')) {
                @finfo_close($finfo);
            }
        }

        return $list;
    }

    private function appendUploadedFile(
        array &$list,
        string $field,
        string $name,
        string $tmpPath,
        int $size,
        mixed $finfo,
        int &$totalSize,
        array $policy
    ): void
    {
        $safeName = basename(str_replace("\0", '', $name));
        if ($safeName === '') {
            $safeName = basename($tmpPath);
        }

        $realSize = $size > 0 ? $size : (int)@filesize($tmpPath);
        $detectedMime = $this->detectMimeType($tmpPath, $finfo);
        $this->assertAttachmentAllowed($safeName, $detectedMime, $realSize, $policy);

        $maxTotalBytes = (int)($policy['max_total_bytes'] ?? self::ATTACH_MAX_TOTAL_BYTES);
        if ($maxTotalBytes <= 0) {
            $maxTotalBytes = self::ATTACH_MAX_TOTAL_BYTES;
        }
        $nextTotal = $totalSize + $realSize;
        if ($nextTotal > $maxTotalBytes) {
            throw new InvalidArgumentException(
                'total attachment size exceeds ' . max(1, (int)ceil($maxTotalBytes / 1024 / 1024)) . 'MB'
            );
        }
        $totalSize = $nextTotal;

        $list[] = [
            'field' => $field,
            'name' => $safeName,
            'path' => $tmpPath,
            'size' => $realSize,
            'mime' => $detectedMime,
        ];
    }

    private function detectMimeType(string $path, mixed $finfo): string
    {
        $mime = '';
        if ((is_resource($finfo) || is_object($finfo)) && function_exists('finfo_file')) {
            $detected = @finfo_file($finfo, $path);
            if (is_string($detected) && trim($detected) !== '') {
                $mime = strtolower(trim($detected));
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && trim($detected) !== '') {
                $mime = strtolower(trim($detected));
            }
        }

        return $mime;
    }

    private function assertAttachmentAllowed(string $fileName, string $mimeType, int $size, array $policy): void
    {
        if ($size <= 0) {
            throw new InvalidArgumentException('attachment file is empty');
        }
        $maxFileBytes = (int)($policy['max_file_bytes'] ?? self::ATTACH_MAX_FILE_BYTES);
        if ($maxFileBytes <= 0) {
            $maxFileBytes = self::ATTACH_MAX_FILE_BYTES;
        }
        if ($size > $maxFileBytes) {
            throw new InvalidArgumentException(
                'attachment exceeds per-file size limit (' . max(1, (int)ceil($maxFileBytes / 1024 / 1024)) . 'MB)'
            );
        }

        $allowedExtensions = $this->normalizeAllowedExtensions($policy['allowed_extensions'] ?? self::ATTACH_ALLOWED_EXT);
        if ($allowedExtensions === []) {
            return;
        }

        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            throw new InvalidArgumentException('attachment extension is not allowed: ' . $ext);
        }

        if ($this->mimeTypesForExtension($ext) === []) {
            return;
        }

        $effectiveMime = strtolower(trim($mimeType));
        if ($effectiveMime === '' || $effectiveMime === 'application/octet-stream') {
            $effectiveMime = strtolower($this->guessMimeType($fileName));
        }

        $allowedMimes = (array)($policy['allowed_mime_types'] ?? self::ATTACH_ALLOWED_MIME);
        if ($allowedMimes !== [] && !in_array($effectiveMime, $allowedMimes, true)) {
            throw new InvalidArgumentException('attachment MIME type is not allowed: ' . $effectiveMime);
        }
    }

    private function normalizeRecipientList(mixed $value): array
    {
        $tokens = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $itemText = trim((string)$item);
                if ($itemText !== '') {
                    $tokens[] = $itemText;
                }
            }
        } elseif (is_scalar($value)) {
            $raw = trim((string)$value);
            if ($raw !== '') {
                $tokens = preg_split('/[,;\n]+/', $raw) ?: [];
            }
        }

        $emails = [];
        foreach ($tokens as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }

            $email = $this->extractEmailFromToken($token);
            if ($email === null) {
                continue;
            }

            $emails[] = strtolower($email);
        }

        return array_values(array_unique($emails));
    }

    private function extractEmailsFromText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);
        $emails = [];
        foreach (($matches[0] ?? []) as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function extractEmailFromToken(string $token): ?string
    {
        if (preg_match('/<([^>]+)>/', $token, $match) === 1) {
            $candidate = trim((string)$match[1]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        $candidate = trim($token, " \t\r\n,;\"'");
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }

        return null;
    }

    private function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string)$value);
    }

    private function encodeHeaderText(string $value): string
    {
        $safe = $this->sanitizeHeaderValue($value);
        if ($safe === '') {
            return '';
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($safe, 'UTF-8', 'B', "\r\n");
        }
        return $safe;
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'hwp' => 'application/x-hwp',
            default => 'application/octet-stream',
        };
    }

    private function messageIdHost(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) >= 2) {
            return preg_replace('/[^a-z0-9.-]/i', '', $parts[1]) ?: 'localhost';
        }
        return 'localhost';
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
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
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

    private function resolveDraftMeta(): ?array
    {
        if ($this->draftMetaLoaded) {
            return $this->draftMeta;
        }

        $this->draftMetaLoaded = true;

        if (!$this->tableExists('Tb_Mail_Draft')) {
            $this->draftMeta = null;
            return null;
        }

        $meta = [
            'id' => $this->firstExistingColumn('Tb_Mail_Draft', ['id', 'idx']),
            'account' => $this->firstExistingColumn('Tb_Mail_Draft', ['account_idx', 'account_id', 'provider_account_idx']),
            'owner_pk' => $this->firstExistingColumn('Tb_Mail_Draft', ['user_pk', 'user_idx']),
            'owner_id' => $this->firstExistingColumn('Tb_Mail_Draft', ['user_id', 'login_id']),
            'service' => $this->firstExistingColumn('Tb_Mail_Draft', ['service_code']),
            'tenant' => $this->firstExistingColumn('Tb_Mail_Draft', ['tenant_id']),
            'subject' => $this->firstExistingColumn('Tb_Mail_Draft', ['subject']),
            'body_html' => $this->firstExistingColumn('Tb_Mail_Draft', ['body_html']),
            'to_list' => $this->firstExistingColumn('Tb_Mail_Draft', ['to_list']),
            'cc_list' => $this->firstExistingColumn('Tb_Mail_Draft', ['cc_list']),
            'bcc_list' => $this->firstExistingColumn('Tb_Mail_Draft', ['bcc_list']),
            'reply_mode' => $this->firstExistingColumn('Tb_Mail_Draft', ['reply_mode', 'mode']),
            'source_uid' => $this->firstExistingColumn('Tb_Mail_Draft', ['source_uid']),
            'source_folder' => $this->firstExistingColumn('Tb_Mail_Draft', ['source_folder']),
            'is_deleted' => $this->firstExistingColumn('Tb_Mail_Draft', ['is_deleted']),
            'deleted_at' => $this->firstExistingColumn('Tb_Mail_Draft', ['deleted_at']),
            'created_at' => $this->firstExistingColumn('Tb_Mail_Draft', ['created_at']),
            'updated_at' => $this->firstExistingColumn('Tb_Mail_Draft', ['updated_at']),
        ];

        if ((string)($meta['id'] ?? '') === '') {
            $this->draftMeta = null;
            return null;
        }

        $this->draftMeta = $meta;
        return $this->draftMeta;
    }

    private function buildOwnerWhereClause(array $meta, array $actor, array &$params): string
    {
        $clauses = [];

        $userPk = (int)($actor['user_pk'] ?? 0);
        $loginId = trim((string)($actor['login_id'] ?? ''));

        if (!empty($meta['owner_pk']) && $userPk > 0) {
            $params['owner_pk'] = $userPk;
            $clauses[] = $this->qcol((string)$meta['owner_pk']) . ' = :owner_pk';
        }
        if (!empty($meta['owner_id']) && $loginId !== '') {
            $params['owner_id'] = $loginId;
            $clauses[] = $this->qcol((string)$meta['owner_id']) . ' = :owner_id';
        }

        if ($clauses === []) {
            return '';
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }

    private function buildScopeWhereClause(array $meta, string $serviceCode, int $tenantId, array &$params): string
    {
        $clauses = [];

        if (!empty($meta['service'])) {
            $params['scope_service_code'] = $serviceCode;
            $clauses[] = $this->qcol((string)$meta['service']) . ' = :scope_service_code';
        }
        if (!empty($meta['tenant'])) {
            $params['scope_tenant_id'] = $tenantId;
            $clauses[] = $this->qcol((string)$meta['tenant']) . ' = :scope_tenant_id';
        }

        if ($clauses === []) {
            return '';
        }

        return implode(' AND ', $clauses);
    }

    private function appendOwnerColumns(array $meta, array $actor, array &$columns, array &$values, array &$params): void
    {
        $userPk = (int)($actor['user_pk'] ?? 0);
        $loginId = trim((string)($actor['login_id'] ?? ''));

        if (!empty($meta['owner_pk']) && $userPk > 0) {
            $columns[] = (string)$meta['owner_pk'];
            $values[] = ':owner_pk';
            $params['owner_pk'] = $userPk;
        }
        if (!empty($meta['owner_id']) && $loginId !== '') {
            $columns[] = (string)$meta['owner_id'];
            $values[] = ':owner_id';
            $params['owner_id'] = $loginId;
        }
    }

    private function appendScopeColumns(array $meta, string $serviceCode, int $tenantId, array &$columns, array &$values, array &$params): void
    {
        if (!empty($meta['service'])) {
            $columns[] = (string)$meta['service'];
            $values[] = ':scope_service_code';
            $params['scope_service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $columns[] = (string)$meta['tenant'];
            $values[] = ':scope_tenant_id';
            $params['scope_tenant_id'] = $tenantId;
        }
    }

    private function findLatestDraftId(array $meta, array $actor, string $serviceCode, int $tenantId): int
    {
        $params = [];
        $where = ['1=1'];

        $ownerWhere = $this->buildOwnerWhereClause($meta, $actor, $params);
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
        }

        $scopeWhere = $this->buildScopeWhereClause($meta, $serviceCode, $tenantId, $params);
        if ($scopeWhere !== '') {
            $where[] = $scopeWhere;
        }

        if (!empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }

        $orderCol = !empty($meta['updated_at']) ? $this->qcol((string)$meta['updated_at']) : $this->qcol((string)$meta['id']);

        $sql = 'SELECT TOP 1 ' . $this->qcol((string)$meta['id']) . ' AS draft_id'
            . ' FROM dbo.Tb_Mail_Draft'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $orderCol . ' DESC, ' . $this->qcol((string)$meta['id']) . ' DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return (int)($row['draft_id'] ?? 0);
    }

    private function loadDraftRowById(array $meta, int $draftId, array $actor, string $serviceCode, int $tenantId): ?array
    {
        $params = [
            'draft_id' => $draftId,
        ];
        $where = [$this->qcol((string)$meta['id']) . ' = :draft_id'];

        $ownerWhere = $this->buildOwnerWhereClause($meta, $actor, $params);
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
        }

        $scopeWhere = $this->buildScopeWhereClause($meta, $serviceCode, $tenantId, $params);
        if ($scopeWhere !== '') {
            $where[] = $scopeWhere;
        }

        if (!empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }

        $sql = 'SELECT TOP 1 * FROM dbo.Tb_Mail_Draft WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function mapDraftRow(array $row, array $meta): array
    {
        $toList = $this->decodeAddressList((string)($row[(string)($meta['to_list'] ?? '')] ?? '[]'));
        $ccList = $this->decodeAddressList((string)($row[(string)($meta['cc_list'] ?? '')] ?? '[]'));
        $bccList = $this->decodeAddressList((string)($row[(string)($meta['bcc_list'] ?? '')] ?? '[]'));

        $toSummary = '';
        if ($toList !== []) {
            $toSummary = implode(', ', array_slice($toList, 0, 2));
            if (count($toList) > 2) {
                $toSummary .= ' 외 ' . (count($toList) - 2) . '명';
            }
        }

        return [
            'id' => (int)($row[(string)($meta['id'] ?? '')] ?? 0),
            'account_idx' => (int)($row[(string)($meta['account'] ?? '')] ?? 0),
            'subject' => (string)($row[(string)($meta['subject'] ?? '')] ?? ''),
            'body_html' => (string)($row[(string)($meta['body_html'] ?? '')] ?? ''),
            'to_list' => $toList,
            'cc_list' => $ccList,
            'bcc_list' => $bccList,
            'to_summary' => $toSummary,
            'reply_mode' => (string)($row[(string)($meta['reply_mode'] ?? '')] ?? ''),
            'source_uid' => (int)($row[(string)($meta['source_uid'] ?? '')] ?? 0),
            'source_folder' => (string)($row[(string)($meta['source_folder'] ?? '')] ?? ''),
            'created_at' => (string)($row[(string)($meta['created_at'] ?? '')] ?? ''),
            'updated_at' => (string)($row[(string)($meta['updated_at'] ?? '')] ?? ''),
        ];
    }

    private function decodeAddressList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $result = [];
            foreach ($decoded as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $result[] = $item;
                }
            }
            return array_values(array_unique($result));
        }

        return $this->normalizeRecipientList($raw);
    }

    private function firstExistingColumn(string $tableName, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->columnExists($tableName, $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function qcol(string $column): string
    {
        return '[' . str_replace(']', '', $column) . ']';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on', 'y'], true);
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

    /**
     * 메일 발송 body_html XSS 방어 sanitize
     * - script / 이벤트핸들러(on*) / javascript: 프로토콜 제거
     * - style 태그·속성은 메일 클라이언트 호환을 위해 보존
     */
    private function sanitizeBodyHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        /* <script> 태그 제거 */
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        /* <iframe>, <frame>, <object>, <embed>, <applet> 제거 */
        $html = preg_replace('/<(iframe|frame|object|embed|applet)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(iframe|frame|object|embed|applet)\b[^>]*\/>/is', '', $html) ?? $html;

        /* <form> 제거 */
        $html = preg_replace('/<\/?(form|input|button|select|textarea)\b[^>]*>/is', '', $html) ?? $html;

        /* on* 이벤트 핸들러 속성 제거 (onclick, onload 등) */
        $html = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;

        /* href/src/action 속성의 javascript: 프로토콜 제거 */
        $html = preg_replace('/\b(href|src|action)\s*=\s*(["\'])?\s*javascript:/i', '$1=$2#', $html) ?? $html;

        return $html;
    }
}
