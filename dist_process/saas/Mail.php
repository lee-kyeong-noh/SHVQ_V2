<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/mail/MailboxService.php';
require_once __DIR__ . '/../../dist_library/mail/MailComposeService.php';
require_once __DIR__ . '/../../dist_library/mail/MailAccountService.php';

header('Content-Type: application/json; charset=utf-8');

$__mailJsonInput = null;

function mailJsonInput(): array
{
    global $__mailJsonInput;

    if (is_array($__mailJsonInput)) {
        return $__mailJsonInput;
    }

    $__mailJsonInput = [];
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $__mailJsonInput;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $__mailJsonInput = $decoded;
    }

    return $__mailJsonInput;
}

function mailParam(string $key, mixed $default = null): mixed
{
    $json = mailJsonInput();

    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }

    return $default;
}

function mailParamList(string $key, array $aliases = []): array
{
    $candidateKeys = array_merge([$key], $aliases);

    foreach ($candidateKeys as $candidate) {
        $value = mailParam($candidate, null);
        if ($value === null) {
            continue;
        }

        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $text = trim((string)$item);
                if ($text !== '') {
                    $list[] = $text;
                }
            }
            return $list;
        }

        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $parts = preg_split('/[,;\n]+/', $text) ?: [];
            $list = [];
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $list[] = $part;
                }
            }
            if ($list !== []) {
                return $list;
            }
        }
    }

    return [];
}

function mailParamBool(string $key, bool $default = false): bool
{
    $raw = mailParam($key, null);
    if ($raw === null) {
        return $default;
    }

    if (is_bool($raw)) {
        return $raw;
    }

    $text = strtolower(trim((string)$raw));
    if ($text === '') {
        return $default;
    }

    return in_array($text, ['1', 'true', 'yes', 'on', 'y'], true);
}

function mailSafeInlineFilename(string $name, string $fallback = 'inline.bin'): string
{
    $name = trim($name);
    if ($name === '') {
        return $fallback;
    }

    $name = str_replace(["\r", "\n", "\0"], '', $name);
    $name = basename($name);
    $name = preg_replace('/[\\\\\\/:"*?<>|]+/u', '_', $name) ?? '';
    $name = trim($name);

    return $name !== '' ? $name : $fallback;
}

function mailWhitelistPayload(array $input, array $allowedKeys): array
{
    $allowMap = [];
    foreach ($allowedKeys as $key) {
        $allowMap[(string)$key] = true;
    }

    $result = [];
    foreach ($input as $key => $value) {
        $name = (string)$key;
        if ($name === '' || !isset($allowMap[$name])) {
            continue;
        }
        $result[$name] = $value;
    }

    return $result;
}

try {
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $todoRaw = trim((string)mailParam('todo', 'mail_list'));
    $todo = strtolower($todoRaw);
    if ($todo === '') {
        $todo = 'mail_list';
    }

    if (
        in_array($todo, ['mail_detail', 'inline_image'], true)
        && function_exists('opcache_invalidate')
    ) {
        @opcache_invalidate(__DIR__ . '/../../dist_library/mail/MailboxService.php', true);
    }

    $roleLevel = (int)($context['role_level'] ?? 0);

    $serviceCode = trim((string)mailParam('service_code', ''));
    if ($serviceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
    }

    /* tenant_id는 반드시 세션에서만 가져온다 (사용자 입력 신뢰 금지) */
    $tenantId = (int)($context['tenant_id'] ?? 0);

    $writeTodos = [
        'mail_send',
        'mail_draft_save',
        'mail_flag',
        'mail_draft_delete',
        'mail_delete',
        'mail_mark_read',
        'mail_move',
        'mail_mark_all_read',
        'mail_spam',
        'mail_link_site',
        'mail_not_duplicate',
        'mail_batch_not_duplicate',
        'mail_clear_duplicates',
        'folder_create',
        'folder_delete',
        'folder_rename',
        'filter_save',
        'filter_delete',
        'account_save',
        'account_delete',
        'account_test',
        'mail_admin_settings_save',
    ];
    // POST 필수이나 CSRF 불필요 — IMAP 캐시 갱신 / WS 토큰 발급은 세션 인증으로 충분
    $postOnlyTodos = ['mail_sync', 'mail_full_resync', 'ws_token_issue', 'fcm_register', 'fcm_unregister'];
    $accountWriteTodos = ['account_save', 'account_delete', 'account_test', 'mail_admin_settings_save', 'mail_link_site'];
    $mailWriteTodos = [
        'mail_send', 'mail_draft_save', 'mail_flag', 'mail_draft_delete', 'mail_delete', 'mail_mark_read',
        'mail_move', 'mail_mark_all_read', 'mail_spam', 'mail_link_site',
        'mail_not_duplicate', 'mail_batch_not_duplicate', 'mail_clear_duplicates',
        'folder_create', 'folder_delete', 'folder_rename', 'filter_save', 'filter_delete',
    ];

    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }

        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    if (in_array($todo, $postOnlyTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
    }

    if (in_array($todo, $accountWriteTodos, true) && ($roleLevel === 0 || $roleLevel > 4)) {
        ApiResponse::error('FORBIDDEN', 'manager role required for account write', 403);
        exit;
    }

    // 세션 잠금 해제 — IMAP 동기화 등 장시간 처리 중 다른 요청이 block되지 않도록
    session_write_close();

    if (in_array($todo, $mailWriteTodos, true) && $roleLevel === 0) {
        ApiResponse::error('FORBIDDEN', 'writer role required for mail write', 403);
        exit;
    }

    $db = DbConnection::get();
    $mailboxService = new SHVQ\Mail\MailboxService($db);
    $composeService = new SHVQ\Mail\MailComposeService($db);
    $accountService = new SHVQ\Mail\MailAccountService($db);

    $actor = [
        'user_pk' => (int)($context['user_pk'] ?? 0),
        'login_id' => (string)($context['login_id'] ?? ''),
        'role_level' => $roleLevel,
    ];

    if ($todo === 'folder_list') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $result = $mailboxService->folderList($serviceCode, $tenantId, $accountIdx);
        ApiResponse::success($result, 'OK', 'mail folders loaded');
        exit;
    }

    if ($todo === 'mail_list') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $query = [
            'folder' => (string)mailParam('folder', 'INBOX'),
            'page' => (int)mailParam('page', 1),
            'limit' => (int)mailParam('limit', 20),
            'search' => (string)mailParam('search', ''),
            'unread_only' => mailParamBool('unread_only', false),
            'include_deleted' => mailParamBool('include_deleted', false),
            'force_imap' => mailParamBool('force_imap', false),
        ];

        // 로그인 직후(세션 login_at 기준) 최초 목록 진입 시, 마지막 동기화가 5분 이상 지났으면 즉시 증분 동기화
        // Phase 4 요구사항: login 시점 즉시 sync 트리거
        $folderForAutoSync = strtoupper(trim((string)($query['folder'] ?? 'INBOX')));
        if (!$query['force_imap'] && $folderForAutoSync === 'INBOX') {
            $loginAtRaw = trim((string)($context['login_at'] ?? ''));
            $loginAtTs = $loginAtRaw !== '' ? strtotime($loginAtRaw) : false;
            $recentLogin = ($loginAtTs !== false) && ((time() - $loginAtTs) <= (20 * 60));

            if ($recentLogin) {
                try {
                    $lastSyncedAt = null;
                    $stateStmt = $db->prepare("
                        SELECT TOP 1 last_synced_at
                        FROM dbo.Tb_Mail_FolderSyncState
                        WHERE account_idx = ? AND folder = N'INBOX'
                    ");
                    $stateStmt->execute([$accountIdx]);
                    $stateRow = $stateStmt->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($stateRow) && isset($stateRow['last_synced_at'])) {
                        $lastSyncedAt = trim((string)$stateRow['last_synced_at']);
                    }

                    $shouldTrigger = false;
                    if ($lastSyncedAt === null || $lastSyncedAt === '') {
                        $shouldTrigger = true;
                    } else {
                        $lastSyncedTs = strtotime($lastSyncedAt);
                        if ($lastSyncedTs === false || (time() - $lastSyncedTs) >= 300) {
                            $shouldTrigger = true;
                        }
                    }

                    if ($shouldTrigger) {
                        $mailboxService->mailSync($serviceCode, $tenantId, $accountIdx, [
                            'folder' => 'INBOX',
                        ]);
                    }
                } catch (\Throwable $_) {
                    // 자동 트리거 실패 시 목록 조회는 계속 진행 (UX 보호)
                }
            }
        }

        $result = $mailboxService->mailList($serviceCode, $tenantId, $accountIdx, $query);
        ApiResponse::success($result, 'OK', 'mail list loaded');
        exit;
    }

    if ($todo === 'mail_check_new') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'GET method required', 405);
            exit;
        }

        $userPk = (int)($context['user_pk'] ?? 0);
        if ($userPk <= 0) {
            ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
            exit;
        }

        $folder = strtoupper(trim((string)mailParam('folder', 'INBOX')));
        if ($folder === '') {
            $folder = 'INBOX';
        }

        $sql = "
            SELECT
                a.idx AS account_idx,
                CASE
                    WHEN NULLIF(LTRIM(RTRIM(a.account_key)), '') IS NOT NULL THEN LTRIM(RTRIM(a.account_key))
                    WHEN NULLIF(LTRIM(RTRIM(a.display_name)), '') IS NOT NULL THEN LTRIM(RTRIM(a.display_name))
                    ELSE CONCAT('account-', CAST(a.idx AS VARCHAR(20)))
                END AS email,
                ISNULL(u.unread, 0) AS unread
            FROM dbo.Tb_IntProviderAccount a
            LEFT JOIN (
                SELECT mc.account_idx, COUNT_BIG(1) AS unread
                FROM dbo.Tb_Mail_MessageCache mc
                WHERE mc.folder = :folder
                  AND ISNULL(mc.is_seen, 0) = 0
                  AND ISNULL(mc.is_deleted, 0) = 0
                GROUP BY mc.account_idx
            ) u ON u.account_idx = a.idx
            WHERE a.provider = 'mail'
              AND a.service_code = :service_code
              AND a.tenant_id = :tenant_id
              AND a.user_pk = :user_pk
              AND ISNULL(a.status, 'ACTIVE') = 'ACTIVE'
            ORDER BY ISNULL(a.is_primary, 0) DESC, a.idx ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'folder' => $folder,
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'user_pk' => $userPk,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $accounts = [];
        $unreadTotal = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $unread = max(0, (int)($row['unread'] ?? 0));
            $unreadTotal += $unread;
            $accounts[] = [
                'account_idx' => (int)($row['account_idx'] ?? 0),
                'email' => (string)($row['email'] ?? ''),
                'unread' => $unread,
            ];
        }

        ApiResponse::success([
            'unread_total' => $unreadTotal,
            'accounts' => $accounts,
        ], 'OK', 'mail unread summary loaded');
        exit;
    }

    if ($todo === 'mail_detail') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $query = [
            'folder' => (string)mailParam('folder', 'INBOX'),
            'uid' => (int)mailParam('uid', 0),
            'msgno' => (int)mailParam('msgno', 0),
            'id' => (int)mailParam('id', mailParam('mail_id', 0)),
            'message_id' => (string)mailParam('message_id', ''),
        ];

        $result = $mailboxService->mailDetail($serviceCode, $tenantId, $accountIdx, $query);

        $replyMode = trim((string)mailParam('reply_mode', ''));
        if ($replyMode !== '') {
            $identityEmails = [];
            $loginId = trim((string)($context['login_id'] ?? ''));
            if (filter_var($loginId, FILTER_VALIDATE_EMAIL)) {
                $identityEmails[] = $loginId;
            }
            $identityEmails = array_merge($identityEmails, mailParamList('identity_emails', ['identity_email']));
            $result['reply_payload'] = $composeService->buildReplyPayload($result, $replyMode, $identityEmails);
        }

        ApiResponse::success($result, 'OK', 'mail detail loaded');
        exit;
    }

    if ($todo === 'inline_image') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $query = [
            'folder' => (string)mailParam('folder', 'INBOX'),
            'uid' => (int)mailParam('uid', 0),
            'msgno' => (int)mailParam('msgno', 0),
            'cid' => (string)mailParam('cid', ''),
            'partno' => (string)mailParam('partno', ''),
        ];

        try {
            $result = $mailboxService->inlineImage(
                $serviceCode,
                $tenantId,
                $accountIdx,
                $query,
                (int)($actor['user_pk'] ?? 0),
                $roleLevel
            );
        } catch (InvalidArgumentException $e) {
            ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
            exit;
        } catch (RuntimeException $e) {
            $message = (string)$e->getMessage();
            if (in_array($message, ['MAIL_NOT_FOUND', 'MAIL_STRUCTURE_NOT_FOUND', 'INLINE_IMAGE_NOT_FOUND'], true)) {
                ApiResponse::error('NOT_FOUND', $message, 404);
                exit;
            }
            if ($message === 'MAIL_ACCOUNT_NOT_FOUND') {
                ApiResponse::error('NOT_FOUND', $message, 404);
                exit;
            }
            if ($message === 'IMAP_EXTENSION_REQUIRED') {
                ApiResponse::error('MAIL_ERROR', $message, 500);
                exit;
            }
            throw $e;
        }

        $content = (string)($result['content'] ?? '');
        if ($content === '') {
            ApiResponse::error('NOT_FOUND', 'INLINE_IMAGE_NOT_FOUND', 404);
            exit;
        }

        $contentType = trim((string)($result['content_type'] ?? ''));
        if ($contentType === '' || !preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $contentType)) {
            $contentType = 'application/octet-stream';
        }

        $filename = mailSafeInlineFilename((string)($result['filename'] ?? 'inline.bin'));

        header_remove('Content-Type');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

    if ($todo === 'mail_send_policy') {
        $result = $composeService->sendPolicy($serviceCode, $tenantId);
        ApiResponse::success($result, 'OK', 'mail send policy loaded');
        exit;
    }

    if ($todo === 'mail_admin_settings_save') {
        $payload = mailJsonInput();
        if ($payload === []) {
            $payload = $_POST;
        }
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload = mailWhitelistPayload($payload, [
            'max_per_file_mb',
            'max_total_mb',
            'allowed_exts',
        ]);

        $result = $accountService->saveAdminSettings(
            $serviceCode,
            $tenantId,
            $payload,
            (int)($actor['user_pk'] ?? 0)
        );
        ApiResponse::success($result, 'OK', 'mail admin settings saved');
        exit;
    }

    if ($todo === 'mail_send') {
        $payload = [
            'account_idx' => (int)mailParam('account_idx', mailParam('account_id', 0)),
            'subject' => (string)mailParam('subject', ''),
            'body_html' => (string)mailParam('body_html', mailParam('body', '')),
            'body_text' => (string)mailParam('body_text', ''),
            'to' => mailParamList('to', ['to_list']),
            'cc' => mailParamList('cc', ['cc_list']),
            'bcc' => mailParamList('bcc', ['bcc_list']),
            'from_email' => (string)mailParam('from_email', ''),
            'from_name' => (string)mailParam('from_name', ''),
            'reply_to' => (string)mailParam('reply_to', ''),
            'draft_id' => (int)mailParam('draft_id', mailParam('id', 0)),
        ];
        if (!is_array($payload['to']) || $payload['to'] === []) {
            ApiResponse::error('INVALID_INPUT', 'to is required', 422);
            exit;
        }

        $result = $composeService->sendMail($serviceCode, $tenantId, $actor, $payload, $_FILES);
        ApiResponse::success($result, 'OK', 'mail sent');
        exit;
    }

    if ($todo === 'mail_draft_save') {
        $payload = [
            'id' => (int)mailParam('id', mailParam('draft_id', 0)),
            'account_idx' => (int)mailParam('account_idx', mailParam('account_id', 0)),
            'subject' => (string)mailParam('subject', ''),
            'body_html' => (string)mailParam('body_html', mailParam('body', '')),
            'to' => mailParamList('to', ['to_list']),
            'cc' => mailParamList('cc', ['cc_list']),
            'bcc' => mailParamList('bcc', ['bcc_list']),
            'reply_mode' => (string)mailParam('reply_mode', mailParam('mode', '')),
            'source_uid' => (int)mailParam('source_uid', mailParam('uid', 0)),
            'source_folder' => (string)mailParam('source_folder', mailParam('folder', 'INBOX')),
        ];

        $replyMode = strtolower(trim((string)$payload['reply_mode']));
        if (
            in_array($replyMode, ['reply', 'reply_all', 'forward'], true)
            && (int)$payload['account_idx'] > 0
            && (int)$payload['source_uid'] > 0
            && ($payload['subject'] === '' || $payload['to'] === [])
        ) {
            try {
                $detail = $mailboxService->mailDetail($serviceCode, $tenantId, (int)$payload['account_idx'], [
                    'folder' => (string)$payload['source_folder'],
                    'uid' => (int)$payload['source_uid'],
                ]);
                $identityEmails = [];
                $loginId = trim((string)($context['login_id'] ?? ''));
                if (filter_var($loginId, FILTER_VALIDATE_EMAIL)) {
                    $identityEmails[] = $loginId;
                }
                $template = $composeService->buildReplyPayload($detail, $replyMode, $identityEmails);

                if ($payload['subject'] === '') {
                    $payload['subject'] = (string)($template['subject'] ?? '');
                }
                if ($payload['body_html'] === '') {
                    $payload['body_html'] = (string)($template['body_html'] ?? '');
                }
                if ($payload['to'] === []) {
                    $payload['to'] = is_array($template['to'] ?? null) ? $template['to'] : [];
                }
                if ($payload['cc'] === []) {
                    $payload['cc'] = is_array($template['cc'] ?? null) ? $template['cc'] : [];
                }
                if ((int)$payload['source_uid'] <= 0) {
                    $payload['source_uid'] = (int)($template['source_uid'] ?? 0);
                }
                if ((string)$payload['source_folder'] === '') {
                    $payload['source_folder'] = (string)($template['source_folder'] ?? 'INBOX');
                }
            } catch (\Throwable $_) {
                // 답장 템플릿 생성 실패 시 사용자 입력값 그대로 저장
            }
        }

        $result = $composeService->saveDraft($serviceCode, $tenantId, $actor, $payload);
        ApiResponse::success($result, 'OK', 'draft saved');
        exit;
    }

    if ($todo === 'mail_draft_list') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        $page = (int)mailParam('page', 1);
        $limit = (int)mailParam('limit', 50);

        $result = $composeService->listDrafts($serviceCode, $tenantId, $actor, $accountIdx, $page, $limit);
        ApiResponse::success($result, 'OK', 'draft list loaded');
        exit;
    }

    if ($todo === 'mail_draft_detail') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'GET method required', 405);
            exit;
        }

        $draftId = (int)mailParam('draft_id', mailParam('id', 0));
        if ($draftId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'draft_id is required', 422);
            exit;
        }

        try {
            $result = $composeService->getDraftDetail($serviceCode, $tenantId, $actor, $draftId);
        } catch (RuntimeException $e) {
            if ((string)$e->getMessage() === 'MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN') {
                ApiResponse::error('NOT_FOUND', 'MAIL_DRAFT_NOT_FOUND_OR_FORBIDDEN', 404);
                exit;
            }
            throw $e;
        }

        ApiResponse::success($result, 'OK', 'draft detail loaded');
        exit;
    }

    if ($todo === 'mail_draft_delete') {
        $draftId = (int)mailParam('draft_id', mailParam('id', 0));
        if ($draftId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'draft_id is required', 422);
            exit;
        }

        $result = $composeService->deleteDraft($serviceCode, $tenantId, $actor, $draftId);
        ApiResponse::success($result, 'OK', 'draft deleted');
        exit;
    }

    if ($todo === 'mail_delete') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uids = mailParamList('uid_list', ['uids', 'uid']);
        $ids = mailParamList('id_list', ['ids', 'mail_ids']);

        $result = $mailboxService->deleteSoft(
            $serviceCode,
            $tenantId,
            $accountIdx,
            (string)mailParam('folder', 'INBOX'),
            $uids,
            $ids
        );
        ApiResponse::success($result, 'OK', 'mail deleted (soft)');
        exit;
    }

    if ($todo === 'mail_mark_read') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uids = mailParamList('uid_list', ['uids', 'uid']);
        $ids = mailParamList('id_list', ['ids', 'mail_ids']);
        $isRead = mailParamBool('is_read', true);

        $result = $mailboxService->markRead(
            $serviceCode,
            $tenantId,
            $accountIdx,
            (string)mailParam('folder', 'INBOX'),
            $uids,
            $isRead,
            $ids
        );
        ApiResponse::success($result, 'OK', 'mail read flag updated');
        exit;
    }

    if ($todo === 'mail_flag') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uids = mailParamList('uid_list', ['uids', 'uid']);
        $ids = mailParamList('id_list', ['ids', 'mail_ids']);
        $isFlagged = mailParamBool('is_flagged', true);

        $result = $mailboxService->markFlag(
            $serviceCode,
            $tenantId,
            $accountIdx,
            (string)mailParam('folder', 'INBOX'),
            $uids,
            $isFlagged,
            $ids
        );
        ApiResponse::success($result, 'OK', 'mail flagged state updated');
        exit;
    }

    if ($todo === 'mail_sync' || $todo === 'mail_full_resync') {
        $accountIdx = (int)mailParam('account_idx', 0);
        $folder     = trim((string)mailParam('folder', 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx required', 422);
            exit;
        }
        $result = $mailboxService->mailSync($serviceCode, $tenantId, $accountIdx, [
            'folder'      => $folder,
            'full_resync' => ($todo === 'mail_full_resync'),
        ]);
        ApiResponse::success($result, 'OK', '동기화 완료 (신규 ' . ($result['synced'] ?? 0) . '건)');
        exit;
    }

    if ($todo === 'account_list') {
        $status = (string)mailParam('status', '');
        $result = $accountService->listAccounts(
            $serviceCode,
            $tenantId,
            $status,
            (int)($actor['user_pk'] ?? 0),
            $roleLevel
        );
        ApiResponse::success($result, 'OK', 'mail accounts loaded');
        exit;
    }

    if ($todo === 'account_save') {
        $payload = mailJsonInput();
        if ($payload === []) {
            $payload = $_POST;
        }
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload = mailWhitelistPayload($payload, [
            'idx',
            'account_idx',
            'account_id',
            'user_pk',
            'account_key',
            'display_name',
            'account_name',
            'is_primary',
            'status',
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
            'provider_hint',
        ]);

        $result = $accountService->saveAccount($serviceCode, $tenantId, $payload, (int)($actor['user_pk'] ?? 0));
        ApiResponse::success($result, 'OK', 'mail account saved');
        exit;
    }

    if ($todo === 'account_delete') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', mailParam('idx', 0)));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $result = $accountService->deleteAccount($serviceCode, $tenantId, $accountIdx);
        ApiResponse::success($result, 'OK', 'mail account deleted');
        exit;
    }

    if ($todo === 'account_test') {
        $payload = mailJsonInput();
        if ($payload === []) {
            $payload = $_POST;
        }
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload = mailWhitelistPayload($payload, [
            'idx',
            'account_idx',
            'account_id',
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
        ]);

        $result = $accountService->testConnection(
            $serviceCode,
            $tenantId,
            $payload,
            (int)($actor['user_pk'] ?? 0)
        );
        ApiResponse::success($result, 'OK', 'mail account tested');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  메일 폴더 이동 (IMAP + DB 캐시)
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_move') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uid          = (int)mailParam('uid', 0);
        $srcFolder    = (string)mailParam('folder', 'INBOX');
        $targetFolder = (string)mailParam('target_folder', '');

        if ($uid <= 0) {
            ApiResponse::error('INVALID_INPUT', 'uid is required', 422);
            exit;
        }
        if ($targetFolder === '') {
            ApiResponse::error('INVALID_INPUT', 'target_folder is required', 422);
            exit;
        }

        $result = $mailboxService->moveMessage($serviceCode, $tenantId, $accountIdx, $srcFolder, $uid, $targetFolder);
        ApiResponse::success($result, 'OK', '이동 완료');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  폴더 전체 읽음 처리
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_mark_all_read') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $folder = (string)mailParam('folder', 'INBOX');
        $result = $mailboxService->markAllRead($serviceCode, $tenantId, $accountIdx, $folder);
        ApiResponse::success($result, 'OK', $result['affected'] . '건 읽음 처리');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  스팸 처리 / 스팸 해제
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_spam') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uid    = (int)mailParam('uid', 0);
        $folder = (string)mailParam('folder', 'INBOX');
        $isSpam = mailParamBool('is_spam', true); // true=스팸처리, false=스팸해제

        if ($uid <= 0) {
            ApiResponse::error('INVALID_INPUT', 'uid is required', 422);
            exit;
        }

        $result = $mailboxService->spamMessage($serviceCode, $tenantId, $accountIdx, $folder, $uid, $isSpam);
        $msg    = $isSpam ? '스팸 처리 완료' : '스팸 해제 완료';
        ApiResponse::success($result, 'OK', $msg);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  현장 연결
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_link_site') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uid     = (int)mailParam('uid', 0);
        $folder  = (string)mailParam('folder', 'INBOX');
        $siteIdx = (int)mailParam('site_idx', 0);
        $empIdx  = (int)mailParam('employee_idx', mailParam('emp_idx', 0));
        $force   = mailParamBool('force', false);

        if ($uid <= 0 || $siteIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', '메일 uid와 site_idx가 필요합니다.', 422);
            exit;
        }

        $result = $mailboxService->linkSite(
            $serviceCode,
            $tenantId,
            $accountIdx,
            $folder,
            $uid,
            $siteIdx,
            $empIdx,
            $force,
            (int)($actor['user_pk'] ?? 0),
            $roleLevel
        );

        if (!empty($result['duplicate'])) {
            ApiResponse::error('DUPLICATE', '중복 메일이 있습니다.', 409, $result);
            exit;
        }

        ApiResponse::success($result, 'OK', '현장에 연결되었습니다.');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  단건 중복아님 처리 (중복메일함 → INBOX)
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_not_duplicate') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $uid = (int)mailParam('uid', 0);
        if ($uid <= 0) {
            ApiResponse::error('INVALID_INPUT', 'uid is required', 422);
            exit;
        }

        $result = $mailboxService->markNotDuplicate($serviceCode, $tenantId, $accountIdx, $uid);
        ApiResponse::success($result, 'OK', '받은편지함으로 이동');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  일괄 중복아님 처리 (id 배열 기반)
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_batch_not_duplicate') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $ids = mailParamList('ids', ['id_list']);
        if (empty($ids)) {
            ApiResponse::error('INVALID_INPUT', '선택 항목이 없습니다.', 422);
            exit;
        }

        $result = $mailboxService->batchMarkNotDuplicate($serviceCode, $tenantId, $accountIdx, $ids);
        ApiResponse::success($result, 'OK', count($ids) . '건 받은편지함으로 이동');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  중복메일함 전체 삭제
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_clear_duplicates') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $result = $mailboxService->clearDuplicates($serviceCode, $tenantId, $accountIdx);
        ApiResponse::success($result, 'OK', $result['deleted'] . '건 삭제 완료');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  스레드 묶음 조회
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'mail_thread') {
        $accountIdx    = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $threadId      = (string)mailParam('thread_id', '');
        $threadSubject = (string)mailParam('thread_subject', '');

        if ($threadId === '' && $threadSubject === '') {
            ApiResponse::error('INVALID_INPUT', 'thread_id 또는 thread_subject가 필요합니다.', 422);
            exit;
        }

        $result = $mailboxService->getThreadMessages($serviceCode, $tenantId, $accountIdx, $threadId, $threadSubject);
        ApiResponse::success($result, 'OK', '스레드 로드 완료');
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  IMAP 폴더 CRUD
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'folder_list_imap') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $result = $mailboxService->listImapFolders($serviceCode, $tenantId, $accountIdx);
        ApiResponse::success($result, 'OK', 'IMAP 폴더 목록 로드 완료');
        exit;
    }

    if ($todo === 'folder_create') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $folderName = (string)mailParam('folder_name', '');
        if ($folderName === '') {
            ApiResponse::error('INVALID_INPUT', 'folder_name is required', 422);
            exit;
        }

        $result = $mailboxService->createFolder($serviceCode, $tenantId, $accountIdx, $folderName);
        ApiResponse::success($result, 'OK', "'{$folderName}' 폴더가 생성되었습니다.");
        exit;
    }

    if ($todo === 'folder_delete') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $folderName = (string)mailParam('folder_name', '');
        if ($folderName === '') {
            ApiResponse::error('INVALID_INPUT', 'folder_name is required', 422);
            exit;
        }

        $result = $mailboxService->deleteFolder($serviceCode, $tenantId, $accountIdx, $folderName);
        ApiResponse::success($result, 'OK', "'{$folderName}' 폴더가 삭제되었습니다.");
        exit;
    }

    if ($todo === 'folder_rename') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $oldName = (string)mailParam('old_name', '');
        $newName = (string)mailParam('new_name', '');
        if ($oldName === '' || $newName === '') {
            ApiResponse::error('INVALID_INPUT', 'old_name and new_name are required', 422);
            exit;
        }

        $result = $mailboxService->renameFolder($serviceCode, $tenantId, $accountIdx, $oldName, $newName);
        ApiResponse::success($result, 'OK', "'{$newName}'(으)로 변경되었습니다.");
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  자동분류 규칙
    // ─────────────────────────────────────────────────────────────────────────

    if ($todo === 'filter_list') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $result = $mailboxService->getFilterRules(
            $serviceCode,
            $tenantId,
            $accountIdx,
            (int)($actor['user_pk'] ?? 0),
            $roleLevel
        );
        ApiResponse::success($result, 'OK', '자동분류 규칙 로드 완료');
        exit;
    }

    if ($todo === 'filter_save') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $ruleId       = (int)mailParam('rule_id', mailParam('id', 0));
        $fromAddress  = (string)mailParam('from_address', '');
        $targetFolder = (string)mailParam('target_folder', '');
        if ($fromAddress === '' || $targetFolder === '') {
            ApiResponse::error('INVALID_INPUT', 'from_address and target_folder are required', 422);
            exit;
        }

        $result = $mailboxService->saveFilterRule(
            $serviceCode,
            $tenantId,
            $accountIdx,
            $fromAddress,
            $targetFolder,
            $ruleId,
            (int)($actor['user_pk'] ?? 0),
            $roleLevel
        );
        ApiResponse::success($result, 'OK', '자동 분류 규칙이 저장되었습니다.');
        exit;
    }

    if ($todo === 'filter_delete') {
        $accountIdx = (int)mailParam('account_idx', mailParam('account_id', 0));
        if ($accountIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'account_idx is required', 422);
            exit;
        }

        $ruleId = (int)mailParam('rule_id', mailParam('id', 0));
        if ($ruleId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'rule_id is required', 422);
            exit;
        }

        $result = $mailboxService->deleteFilterRule(
            $serviceCode,
            $tenantId,
            $accountIdx,
            $ruleId,
            (int)($actor['user_pk'] ?? 0),
            $roleLevel
        );
        ApiResponse::success($result, 'OK', '규칙이 삭제되었습니다.');
        exit;
    }

    // WS/SSE 토큰 발급 — 브라우저가 Node.js WebSocket 서버에 연결하기 위한 단기 토큰
    if ($todo === 'ws_token_issue') {
        $accountIdx = (int)mailParam('account_idx', 0);
        $userPk     = (int)($context['user_pk'] ?? 0);

        if ($userPk <= 0) {
            ApiResponse::error('AUTH_REQUIRED', '로그인이 필요합니다', 401);
            exit;
        }

        // Tb_Mail_WsToken 테이블 없으면 자동 생성
        try {
            $checkTable = $db->query("
                SELECT COUNT(*) AS cnt
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_NAME = 'Tb_Mail_WsToken'
            ");
            if ((int)($checkTable->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0) === 0) {
                $db->exec("
                    CREATE TABLE dbo.Tb_Mail_WsToken (
                        token        NVARCHAR(64)  NOT NULL CONSTRAINT PK_Tb_Mail_WsToken PRIMARY KEY,
                        user_pk      INT           NOT NULL,
                        account_idx  INT           NULL,
                        service_code VARCHAR(30)   NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_sc  DEFAULT ('shvq'),
                        tenant_id    INT           NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_tid DEFAULT (0),
                        expires_at   DATETIME      NOT NULL,
                        created_at   DATETIME      NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_ca  DEFAULT (GETDATE())
                    )
                ");
            }
        } catch (\Throwable $_) {
            // 이미 존재하거나 권한 없음 — 무시
        }

        $token     = bin2hex(random_bytes(32)); // 64자 hex
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        try {
            // 만료된 토큰 정리 (오래된 항목 최대 100건)
            $db->exec("
                DELETE TOP (100) FROM dbo.Tb_Mail_WsToken
                WHERE expires_at < GETDATE()
            ");
        } catch (\Throwable $_) {}

        $stmt = $db->prepare("
            INSERT INTO dbo.Tb_Mail_WsToken (token, user_pk, account_idx, service_code, tenant_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$token, $userPk, $accountIdx > 0 ? $accountIdx : null, $serviceCode, $tenantId, $expiresAt]);

        ApiResponse::success([
            'token'      => $token,
            'expires_at' => $expiresAt,
        ], 'OK', 'WS 토큰 발급 완료');
        exit;
    }

    if ($todo === 'fcm_register') {
        $userPk = (int)($context['user_pk'] ?? 0);
        if ($userPk <= 0) {
            ApiResponse::error('AUTH_REQUIRED', '로그인이 필요합니다', 401);
            exit;
        }

        $token = trim((string)mailParam('token', ''));
        if ($token === '') {
            ApiResponse::error('INVALID_INPUT', 'token is required', 422);
            exit;
        }

        $deviceType = strtolower(trim((string)mailParam('device_type', 'web')));
        if ($deviceType === '') {
            $deviceType = 'web';
        }
        $userAgent = trim((string)mailParam('user_agent', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));

        try {
            $db->exec("
                IF OBJECT_ID(N'dbo.Tb_Mail_FcmToken', N'U') IS NULL
                BEGIN
                    CREATE TABLE dbo.Tb_Mail_FcmToken (
                        id          INT IDENTITY(1,1) PRIMARY KEY,
                        user_pk     INT NOT NULL,
                        tenant_id   INT NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_tid DEFAULT (0),
                        token       NVARCHAR(500) NOT NULL,
                        device_type VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_device DEFAULT ('web'),
                        user_agent  NVARCHAR(300) NULL,
                        created_at  DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_created DEFAULT (GETDATE()),
                        updated_at  DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_updated DEFAULT (GETDATE())
                    );
                    CREATE UNIQUE INDEX UX_FcmToken_Token ON dbo.Tb_Mail_FcmToken(token);
                    CREATE INDEX IX_FcmToken_UserPk ON dbo.Tb_Mail_FcmToken(user_pk, tenant_id);
                END
            ");
        } catch (\Throwable $_) {
            // 테이블이 이미 존재하거나 DDL 권한이 없으면 무시
        }

        $sql = "
            MERGE dbo.Tb_Mail_FcmToken AS tgt
            USING (SELECT CAST(? AS NVARCHAR(500)) AS token) AS src
               ON tgt.token = src.token
            WHEN MATCHED THEN
                UPDATE SET
                    user_pk     = ?,
                    tenant_id   = ?,
                    device_type = ?,
                    user_agent  = ?,
                    updated_at  = GETDATE()
            WHEN NOT MATCHED THEN
                INSERT (user_pk, tenant_id, token, device_type, user_agent, created_at, updated_at)
                VALUES (?, ?, src.token, ?, ?, GETDATE(), GETDATE());
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $token,
            $userPk,
            $tenantId,
            $deviceType,
            $userAgent,
            $userPk,
            $tenantId,
            $deviceType,
            $userAgent,
        ]);

        ApiResponse::success([
            'registered' => true,
            'device_type' => $deviceType,
        ], 'OK', 'FCM 토큰이 등록되었습니다.');
        exit;
    }

    if ($todo === 'fcm_unregister') {
        $userPk = (int)($context['user_pk'] ?? 0);
        if ($userPk <= 0) {
            ApiResponse::error('AUTH_REQUIRED', '로그인이 필요합니다', 401);
            exit;
        }

        $token = trim((string)mailParam('token', ''));
        $deviceType = strtolower(trim((string)mailParam('device_type', '')));

        $where = 'user_pk = ?';
        $params = [$userPk];
        if ($token !== '') {
            $where .= ' AND token = ?';
            $params[] = $token;
        }
        if ($deviceType !== '') {
            $where .= ' AND device_type = ?';
            $params[] = $deviceType;
        }

        $deleted = 0;
        try {
            $stmt = $db->prepare("DELETE FROM dbo.Tb_Mail_FcmToken WHERE {$where}");
            $stmt->execute($params);
            $deleted = (int)$stmt->rowCount();
        } catch (\Throwable $e) {
            ApiResponse::error('DB_ERROR', 'FCM 토큰 해제에 실패했습니다: ' . $e->getMessage(), 500);
            exit;
        }

        ApiResponse::success([
            'deleted' => $deleted,
        ], 'OK', 'FCM 토큰이 해제되었습니다.');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    ApiResponse::error('MAIL_ERROR', $e->getMessage(), 500);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
        500
    );
}
