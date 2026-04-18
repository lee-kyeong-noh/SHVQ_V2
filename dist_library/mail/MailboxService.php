<?php
declare(strict_types=1);

namespace SHVQ\Mail;

use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once __DIR__ . '/MailAdapter.php';
require_once __DIR__ . '/MailRepository.php';

final class MailboxService
{
    private PDO $db;
    private MailRepository $repo;
    private MailAdapter $mailAdapter;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private bool $cacheMetaLoaded = false;
    private ?array $cacheTableMeta = null;

    public function __construct(PDO $db, ?MailAdapter $mailAdapter = null)
    {
        $this->db = $db;
        $this->repo = new MailRepository($db);
        $this->mailAdapter = $mailAdapter ?? new MailAdapter($db, null, $this->repo);
    }

    public function folderList(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $folders = [];
        $source = 'imap_list';

        // IMAP LIST "" "*" 결과를 우선 사용 (체크포인트 stale INBOX-only 방지)
        try {
            $imapFolders = $this->listImapFolders($serviceCode, $tenantId, $accountIdx);
            $imapRows = (array)($imapFolders['folders'] ?? []);
            $uniq = [];

            foreach ($imapRows as $folderRow) {
                if (!is_array($folderRow)) {
                    continue;
                }
                $folderName = trim((string)($folderRow['name'] ?? ''));
                if ($folderName === '') {
                    continue;
                }

                // folder key는 API/IMAP 응답명을 그대로 사용, UTF-8 폴더명 유지
                $folderKey = $folderName;
                if (isset($uniq[$folderKey])) {
                    continue;
                }
                $uniq[$folderKey] = true;

                $folders[] = [
                    'folder_key' => $folderKey,
                    'folder_name' => $folderName,
                    'folder_type' => $this->guessFolderType($folderName, $folderKey),
                ];
            }
        } catch (\Throwable $_) {
            $folders = [];
        }

        if ($folders === []) {
            $folders = $this->loadFolderMapFromCheckpoint($serviceCode, $tenantId, $accountIdx);
            $source = 'checkpoint';
        }

        if ($folders === []) {
            try {
                $synced = $this->mailAdapter->syncFolders($accountIdx);
                $syncFolders = [];
                if (is_array($synced) && isset($synced['folders']) && is_array($synced['folders'])) {
                    $syncFolders = $synced['folders'];
                } elseif (is_array($synced)) {
                    $syncFolders = $synced;
                }

                foreach ($syncFolders as $folder) {
                    if (!is_array($folder)) {
                        continue;
                    }
                    $folderKey = trim((string)($folder['folder_key'] ?? $folder['folder_name'] ?? ''));
                    if ($folderKey === '') {
                        continue;
                    }
                    $folderName = trim((string)($folder['folder_name'] ?? $folderKey));
                    $folders[] = [
                        'folder_key' => $folderKey,
                        'folder_name' => $folderName,
                        'folder_type' => $this->guessFolderType($folderName, $folderKey),
                    ];
                }
                $source = 'imap_adapter';
            } catch (\Throwable $_) {
                // 폴더맵/IMAP 모두 실패한 경우 기본 INBOX 보장
            }
        }

        if ($folders === []) {
            $folders[] = [
                'folder_key' => 'INBOX',
                'folder_name' => 'INBOX',
                'folder_type' => 'inbox',
            ];
            $source = 'default';
        }

        $stats = $this->folderStatsFromCache($serviceCode, $tenantId, $accountIdx);

        $items = [];
        foreach ($folders as $folder) {
            $folderKey = trim((string)($folder['folder_key'] ?? ''));
            $folderName = trim((string)($folder['folder_name'] ?? $folderKey));
            if ($folderKey === '') {
                continue;
            }

            $stat = $stats[$folderKey] ?? $stats[$folderName] ?? [
                'total' => 0,
                'unread' => 0,
            ];

            $items[] = [
                'folder_key' => $folderKey,
                'folder_name' => $folderName,
                'folder_type' => (string)($folder['folder_type'] ?? $this->guessFolderType($folderName, $folderKey)),
                'total_count' => (int)($stat['total'] ?? 0),
                'unread_count' => (int)($stat['unread'] ?? 0),
            ];
        }

        return [
            'account_idx' => $accountIdx,
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'source' => $source,
            'items' => $items,
            'total' => count($items),
        ];
    }

    public function mailList(string $serviceCode, int $tenantId, int $accountIdx, array $query = []): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $folder = trim((string)($query['folder'] ?? 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(200, (int)($query['limit'] ?? 20)));
        $search = trim((string)($query['search'] ?? ''));
        $unreadOnly = $this->toBool($query['unread_only'] ?? false);
        $includeDeleted = $this->toBool($query['include_deleted'] ?? false);
        $forceImap = $this->toBool($query['force_imap'] ?? false);

        // force_imap=1 이면 먼저 증분 동기화 후 DB 조회 (V1 방식)
        if ($forceImap && function_exists('imap_open')) {
            $this->mailSync($serviceCode, $tenantId, $accountIdx, ['folder' => $folder]);
        }

        return $this->loadMailListFromCache(
            $serviceCode,
            $tenantId,
            $accountIdx,
            $folder,
            $page,
            $limit,
            $search,
            $unreadOnly,
            $includeDeleted
        );
    }

    public function mailDetail(string $serviceCode, int $tenantId, int $accountIdx, array $query = []): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $folder = trim((string)($query['folder'] ?? 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }

        $uid = (int)($query['uid'] ?? 0);
        $msgno = (int)($query['msgno'] ?? 0);
        $rowId = (int)($query['id'] ?? $query['mail_id'] ?? 0);
        $messageId = trim((string)($query['message_id'] ?? ''));

        $cacheRow = $this->loadMailDetailFromCache($serviceCode, $tenantId, $accountIdx, $folder, $uid, $rowId, $messageId);
        if ($cacheRow !== null) {
            if ($uid <= 0) {
                $uid = (int)($cacheRow['uid'] ?? 0);
            }
            if ($folder === 'INBOX' && trim((string)($cacheRow['folder'] ?? '')) !== '') {
                $folder = (string)$cacheRow['folder'];
            }
        }

        if (function_exists('imap_open') && ($uid > 0 || $msgno > 0)) {
            try {
                $imapDetail = $this->loadMailDetailFromImap($serviceCode, $tenantId, $accountIdx, $folder, $uid, $msgno);
                if ($imapDetail !== []) {
                    if ($cacheRow !== null) {
                        $imapDetail['id'] = (int)($cacheRow['id'] ?? $imapDetail['id'] ?? 0);
                        $imapDetail['thread_id'] = (string)($cacheRow['thread_id'] ?? $imapDetail['thread_id'] ?? '');
                        $imapDetail['is_deleted'] = (int)($cacheRow['is_deleted'] ?? $imapDetail['is_deleted'] ?? 0);
                    }
                    $imapDetail['source'] = 'imap';
                    return $imapDetail;
                }
            } catch (\Throwable $_) {
                // 캐시 결과로 폴백
            }
        }

        if ($cacheRow !== null) {
            $cacheRow['source'] = 'cache';
            $cacheRow['inline_parts'] = $this->buildInlineCidParts((array)($cacheRow['attachments'] ?? []));
            return $cacheRow;
        }

        throw new RuntimeException('MAIL_NOT_FOUND');
    }

    /**
     * inlineImage : CID 인라인 파트 단건 바이너리 조회
     * - 입력: uid(or msgno) + cid(or partno)
     * - 출력: content_type + binary payload
     */
    public function inlineImage(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        array $query = [],
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        $this->assertAccountOwnerOrManager($account, $actorUserPk, $actorRoleLevel);

        if (!function_exists('imap_open')) {
            throw new RuntimeException('IMAP_EXTENSION_REQUIRED');
        }

        $folder = trim((string)($query['folder'] ?? 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }

        $uid = (int)($query['uid'] ?? 0);
        $msgno = (int)($query['msgno'] ?? 0);
        $cid = trim((string)($query['cid'] ?? ''));
        $partNo = trim((string)($query['partno'] ?? ''));

        if ($uid <= 0 && $msgno <= 0) {
            throw new InvalidArgumentException('uid or msgno is required');
        }
        if ($cid === '' && $partNo === '') {
            throw new InvalidArgumentException('cid or partno is required');
        }

        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        $stream = $imap['stream'];

        try {
            if ($msgno <= 0 && $uid > 0) {
                $msgno = (int)@imap_msgno($stream, $uid);
            }
            if ($uid <= 0 && $msgno > 0) {
                $uid = (int)@imap_uid($stream, $msgno);
            }
            if ($msgno <= 0) {
                throw new RuntimeException('MAIL_NOT_FOUND');
            }

            $structure = @imap_fetchstructure($stream, $msgno);
            if (!is_object($structure)) {
                throw new RuntimeException('MAIL_STRUCTURE_NOT_FOUND');
            }

            $partMeta = $this->resolveInlinePartMeta($structure, $cid, $partNo);
            if ($partMeta === null) {
                throw new RuntimeException('INLINE_IMAGE_NOT_FOUND');
            }

            $targetPartNo = trim((string)($partMeta['partno'] ?? ''));
            if ($targetPartNo === '') {
                throw new RuntimeException('INLINE_IMAGE_NOT_FOUND');
            }

            $rawBody = @imap_fetchbody($stream, $msgno, $targetPartNo);
            if (!is_string($rawBody)) {
                $rawBody = '';
            }
            if ($rawBody === '' && $targetPartNo === '1') {
                $fallbackBody = @imap_body($stream, $msgno);
                if (is_string($fallbackBody)) {
                    $rawBody = $fallbackBody;
                }
            }
            if ($rawBody === '') {
                throw new RuntimeException('INLINE_IMAGE_NOT_FOUND');
            }

            $binary = $this->decodeTransferEncoding($rawBody, (int)($partMeta['encoding'] ?? 0));
            if ($binary === '' && $rawBody !== '') {
                // 일부 서버는 encoding 플래그가 부정확할 수 있어 원문으로 폴백
                $binary = $rawBody;
            }

            $contentType = trim((string)($partMeta['content_type'] ?? ''));
            if ($contentType === '' || !preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $contentType)) {
                $contentType = 'application/octet-stream';
            }

            $filename = trim((string)($partMeta['name'] ?? ''));
            if ($filename === '') {
                $filename = 'inline-' . ($uid > 0 ? $uid : $msgno) . '-' . str_replace('.', '-', $targetPartNo) . '.bin';
            }

            return [
                'account_idx' => $accountIdx,
                'folder' => $folder,
                'uid' => $uid,
                'msgno' => $msgno,
                'partno' => $targetPartNo,
                'cid' => (string)($partMeta['content_id'] ?? ''),
                'content_type' => $contentType,
                'filename' => $filename,
                'content' => $binary,
                'size' => strlen($binary),
            ];
        } finally {
            @imap_close($stream);
        }
    }

    public function markRead(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList = [],
        bool $isRead = true,
        array $idList = []
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $uidList = $this->normalizeIntList($uidList);
        $idList = $this->normalizeIntList($idList);

        if ($uidList === [] && $idList === []) {
            throw new InvalidArgumentException('uid_list or id_list is required');
        }

        $affected = $this->updateReadFlagInCache($serviceCode, $tenantId, $accountIdx, $folder, $uidList, $idList, $isRead);

        if ($uidList !== [] && function_exists('imap_open')) {
            try {
                $this->updateReadFlagInImap($serviceCode, $tenantId, $accountIdx, $folder, $uidList, $isRead);
            } catch (\Throwable $_) {
                // IMAP 반영 실패 시 캐시 업데이트만 유지
            }
        }

        return [
            'account_idx' => $accountIdx,
            'folder' => $folder,
            'is_read' => $isRead ? 1 : 0,
            'affected' => $affected,
            'uid_count' => count($uidList),
            'id_count' => count($idList),
        ];
    }

    public function markFlag(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList = [],
        bool $isFlagged = true,
        array $idList = []
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $uidList = $this->normalizeIntList($uidList);
        $idList = $this->normalizeIntList($idList);

        if ($uidList === [] && $idList === []) {
            throw new InvalidArgumentException('uid_list or id_list is required');
        }

        $affected = $this->updateFlagInCache($serviceCode, $tenantId, $accountIdx, $folder, $uidList, $idList, $isFlagged);

        if ($uidList !== [] && function_exists('imap_open')) {
            try {
                $this->updateFlagInImap($serviceCode, $tenantId, $accountIdx, $folder, $uidList, $isFlagged);
            } catch (\Throwable $_) {
                // IMAP 반영 실패 시 캐시 업데이트만 유지
            }
        }

        return [
            'account_idx' => $accountIdx,
            'folder' => $folder,
            'is_flagged' => $isFlagged ? 1 : 0,
            'affected' => $affected,
            'uid_count' => count($uidList),
            'id_count' => count($idList),
        ];
    }

    /**
     * 증분 동기화 (V1 sync.php 로직 이식)
     * last_uid 이후 신규 메일만 IMAP fetch 후 Tb_Mail_MessageCache insert
     * Tb_Mail_FolderSyncState MERGE로 last_uid 갱신
     */
    public function mailSync(string $serviceCode, int $tenantId, int $accountIdx, array $options = []): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $folder      = trim((string)($options['folder'] ?? 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }
        $fullResync = !empty($options['full_resync']);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();

        // 전체 동기화: DB 캐시 초기화
        if ($fullResync && $meta !== null) {
            $tbl    = $meta['table'];
            $colAcc = $meta['account'];
            $colFld = $meta['folder'];
            $this->repo->prepareStatement("DELETE FROM [{$tbl}] WHERE [{$colAcc}] = ? AND [{$colFld}] = ?")
                     ->execute([$accountIdx, $folder]);
            if ($this->tableExists('Tb_Mail_FolderSyncState')) {
                $this->repo->prepareStatement("DELETE FROM [Tb_Mail_FolderSyncState] WHERE [account_idx] = ? AND [folder] = ?")
                         ->execute([$accountIdx, $folder]);
            }
        }

        // IMAP 연결
        $imap   = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        $stream = $imap['stream'];

        // UIDVALIDITY 확인
        $statusMailbox = $imap['mailbox'] . mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $status        = @imap_status($stream, $statusMailbox, SA_UIDVALIDITY | SA_UIDNEXT | SA_MESSAGES);
        $uidvalidity   = ($status !== false && is_object($status)) ? (int)($status->uidvalidity ?? 0) : 0;
        $imapMessages  = ($status !== false && is_object($status)) ? (int)($status->messages ?? 0) : @imap_num_msg($stream);

        // FolderSyncState 조회 (테이블 없으면 전체 fetch로 폴백)
        $hasSyncState = $this->tableExists('Tb_Mail_FolderSyncState');
        $lastUid      = 0;
        if ($hasSyncState) {
            $syncRow = $this->repo->prepareStatement("SELECT last_uid, uidvalidity FROM [Tb_Mail_FolderSyncState] WHERE [account_idx] = ? AND [folder] = ?");
            $syncRow->execute([$accountIdx, $folder]);
            $syncState = $syncRow->fetch(\PDO::FETCH_ASSOC);

            if ($syncState) {
                // UIDVALIDITY 변경 시 캐시 전체 초기화
                if ($uidvalidity > 0 && (int)$syncState['uidvalidity'] !== $uidvalidity && $meta !== null) {
                    $tbl    = $meta['table'];
                    $colAcc = $meta['account'];
                    $colFld = $meta['folder'];
                    $this->repo->prepareStatement("DELETE FROM [{$tbl}] WHERE [{$colAcc}] = ? AND [{$colFld}] = ?")
                             ->execute([$accountIdx, $folder]);
                    $lastUid = 0;
                } else {
                    $lastUid = (int)($syncState['last_uid'] ?? 0);
                }
            }
        }

        // 증분 fetch
        // 첫 sync(lastUid=0)는 최근 200건만 로드해 응답 지연 방지, 나머지는 cron으로 수집
        $firstSyncLimit = 200;
        if ($lastUid > 0) {
            // 증분: UID 기반 (last_uid+1 이후만)
            $rawOverview = @imap_fetch_overview($stream, ($lastUid + 1) . ':*', FT_UID);
        } else {
            // 첫 sync: 최신 N건만 msgno 기반으로 가져오기
            $totalMsg = (int)@imap_num_msg($stream);
            if ($totalMsg > 0) {
                $startMsg    = max(1, $totalMsg - $firstSyncLimit + 1);
                $rawOverview = @imap_fetch_overview($stream, $startMsg . ':' . $totalMsg, 0);
            } else {
                $rawOverview = [];
            }
        }
        $overviews = is_array($rawOverview) ? $rawOverview : [];

        $synced  = 0;
        $skipped = 0;
        $maxUid  = $lastUid;
        $total   = count($overviews);

        if ($total > 0 && $meta !== null) {
            $tbl    = $meta['table'];
            $colAcc = $meta['account'];
            $colFld = $meta['folder'];
            $colUid = $meta['uid'] ?? 'uid';

            // 기존 UID 맵(중복 방지)
            $existStmt = $this->repo->prepareStatement("SELECT [{$colUid}] FROM [{$tbl}] WHERE [{$colAcc}] = ? AND [{$colFld}] = ?");
            $existStmt->execute([$accountIdx, $folder]);
            $existingMap = array_flip(array_map('intval', $existStmt->fetchAll(\PDO::FETCH_COLUMN)));

            // 동적 INSERT 컬럼 구성은 루프 밖에서 1회 계산
            $insertCols  = [];
            $insertBinds = [];
            $knownCols = [
                $colAcc, $colFld, $colUid,
                'message_id', 'subject', 'from_address', 'to_address',
                'date', 'is_seen', 'has_attachment',
            ];
            foreach ($knownCols as $c) {
                if ($this->columnExists($tbl, $c)) {
                    $insertCols[] = "[{$c}]";
                    $insertBinds[] = ":{$c}";
                }
            }
            // optional 컬럼 존재 여부는 루프 밖에서 pre-compute
            $optColExists = [];
            foreach (['in_reply_to', 'references', 'thread_id', 'body_preview', 'is_deleted', 'is_flagged', 'size'] as $c) {
                $optColExists[$c] = $this->columnExists($tbl, $c);
                if ($optColExists[$c]) {
                    $insertCols[] = "[{$c}]";
                    $insertBinds[] = ":{$c}";
                }
            }
            if ($this->columnExists($tbl, 'created_at')) {
                $insertCols[] = '[created_at]';
                $insertBinds[] = 'GETDATE()';
            }

            $insertSql  = "INSERT INTO [{$tbl}] (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertBinds) . ")";
            $insertStmt = $this->repo->prepareStatement($insertSql);

            // message_id 중복 체크용 prepared statement
            $midColName  = $meta['message_id'] ?? 'message_id';
            $dupCheckStmt = null;
            if ($this->columnExists($tbl, $midColName)) {
                $dupCheckStmt = $this->repo->prepareStatement(
                    "SELECT [{$colUid}] FROM [{$tbl}] WHERE [{$colAcc}] = ? AND [{$colFld}] = ? AND [{$midColName}] = ? AND [{$colUid}] != ?"
                );
            }

            foreach ($overviews as $msg) {
                $uid = (int)($msg->uid ?? 0);
                if ($uid <= $lastUid || isset($existingMap[$uid])) {
                    $skipped++;
                    continue;
                }

                $subject   = $this->decodeMimeHeader((string)($msg->subject ?? ''));
                $from      = $this->syncCleanFromAddress($this->decodeMimeHeader((string)($msg->from ?? '')));
                if ($from === '') {
                    $from = (string)($account['email'] ?? $account['login_id'] ?? '');
                }
                $to        = $this->decodeMimeHeader((string)($msg->to ?? ''));
                $rawDate   = (string)($msg->date ?? '');
                $ts        = $rawDate !== '' ? strtotime($rawDate) : false;
                $dateVal   = ($ts !== false && $ts > 0) ? date('Y-m-d H:i:s', $ts) : null;
                $isSeen    = (int)(isset($msg->seen) && $msg->seen);
                $isFlagged = (int)(isset($msg->flagged) && $msg->flagged);
                $msgSize   = (int)($msg->size ?? 0);

                $messageId  = $this->syncNormalizeMessageId((string)($msg->message_id ?? ''));
                $inReplyTo  = $this->syncNormalizeMessageId((string)($msg->in_reply_to ?? ''));
                $references = $this->syncNormalizeReferences((string)($msg->references ?? ''));
                $threadId   = $this->syncBuildThreadId($messageId, $inReplyTo, $references, $subject);

                // 중복 메일 체크
                $targetFolder = $folder;
                if ($messageId !== '' && $dupCheckStmt !== null) {
                    $dupCheckStmt->execute([$accountIdx, $folder, $messageId, $uid]);
                    if ($dupCheckStmt->fetchColumn() !== false) {
                        $targetFolder = '중복메일함';
                    }
                }

                $params = [
                    ":{$colAcc}" => $accountIdx,
                    ":{$colFld}" => $targetFolder,
                    ":{$colUid}" => $uid,
                    ':message_id'   => mb_substr($messageId, 0, 500),
                    ':subject'      => mb_substr($subject, 0, 1000),
                    ':from_address' => mb_substr($from, 0, 500),
                    ':to_address'   => mb_substr($to, 0, 500),
                    ':date'         => $dateVal,
                    ':is_seen'      => $isSeen,
                    ':has_attachment' => 0,
                ];
                // pre-computed optional 컬럼만 바인딩 추가
                if ($optColExists['in_reply_to'])  { $params[':in_reply_to']  = mb_substr($inReplyTo, 0, 300); }
                if ($optColExists['references'])   { $params[':references']   = mb_substr($references, 0, 4000); }
                if ($optColExists['thread_id'])    { $params[':thread_id']    = mb_substr($threadId, 0, 200); }
                if ($optColExists['body_preview']) { $params[':body_preview'] = ''; }
                if ($optColExists['is_deleted'])   { $params[':is_deleted']   = 0; }
                if ($optColExists['is_flagged'])   { $params[':is_flagged']   = $isFlagged; }
                if ($optColExists['size'])         { $params[':size']         = $msgSize; }

                try {
                    $insertStmt->execute($params);
                    $synced++;
                } catch (\Throwable $_) {
                    $skipped++;
                }

                if ($uid > $maxUid) {
                    $maxUid = $uid;
                }
            }
        }

        @imap_close($stream);

        // FolderSyncState MERGE (테이블 존재 시에만 수행)
        if ($hasSyncState) {
            $this->repo->prepareStatement("
                MERGE [Tb_Mail_FolderSyncState] AS tgt
                USING (SELECT ? AS account_idx, ? AS folder) AS src
                    ON tgt.account_idx = src.account_idx AND tgt.folder = src.folder
                WHEN MATCHED THEN
                    UPDATE SET uidvalidity = ?, last_uid = ?, last_synced_at = GETDATE(), updated_at = GETDATE()
                WHEN NOT MATCHED THEN
                    INSERT (account_idx, folder, uidvalidity, last_uid, last_synced_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, GETDATE(), GETDATE(), GETDATE());
            ")->execute([
                $accountIdx, $folder, $uidvalidity, $maxUid,
                $accountIdx, $folder, $uidvalidity, $maxUid,
            ]);
        }

        return [
            'account_idx' => $accountIdx,
            'folder'      => $folder,
            'synced'      => $synced,
            'skipped'     => $skipped,
            'total'       => $total,
            'last_uid'    => $maxUid,
            'imap_messages' => $imapMessages,
        ];
    }

    // sync 전용 헬퍼

    private function syncCleanFromAddress(string $str): string
    {
        $str = trim($str);
        if (preg_match('/^["\\\\"]+(.+?)["\\\\"]+\s*(<[^>]+>)\s*$/su', $str, $m)) {
            return trim($m[1]) . ' ' . trim($m[2]);
        }
        if (preg_match('/^["\\\\"]+(.+?)["\\\\"]+\s*$/su', $str, $m)) {
            return trim($m[1]);
        }
        return $str;
    }

    private function syncNormalizeMessageId(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        if (preg_match('/<[^>]+>/', $v, $m)) {
            return trim($m[0]);
        }
        return mb_substr($v, 0, 500);
    }

    private function syncNormalizeReferences(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }
        if (preg_match_all('/<[^>]+>/', $raw, $m) && !empty($m[0])) {
            return mb_substr(implode(' ', array_map('trim', $m[0])), 0, 4000);
        }
        return mb_substr($raw, 0, 4000);
    }

    private function syncBuildThreadId(string $messageId, string $inReplyTo, string $references, string $subject = ''): string
    {
        $refs = trim($references);
        if ($refs !== '' && preg_match('/<[^>]+>/', $refs, $m)) {
            return mb_substr(trim($m[0]), 0, 200);
        }
        $mid = $this->syncNormalizeMessageId($messageId);
        if ($mid !== '') {
            return mb_substr($mid, 0, 200);
        }
        $irt = $this->syncNormalizeMessageId($inReplyTo);
        if ($irt !== '') {
            return mb_substr($irt, 0, 200);
        }
        if ($subject !== '') {
            $clean = preg_replace('/^(Re|Fwd?|답장|전달)[\s:>]+/iu', '', $subject);
            $clean = trim((string)$clean);
            return mb_substr($clean, 0, 200);
        }
        return '';
    }

    public function deleteSoft(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList = [],
        array $idList = []
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId = max(0, $tenantId);
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $uidList = $this->normalizeIntList($uidList);
        $idList = $this->normalizeIntList($idList);

        if ($uidList === [] && $idList === []) {
            throw new InvalidArgumentException('uid_list or id_list is required');
        }

        $affected = $this->softDeleteInCache($serviceCode, $tenantId, $accountIdx, $folder, $uidList, $idList);

        return [
            'account_idx' => $accountIdx,
            'folder' => $folder,
            'affected' => $affected,
            'uid_count' => count($uidList),
            'id_count' => count($idList),
            'soft_deleted' => true,
        ];
    }

    private function loadMailListFromCache(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $page,
        int $limit,
        string $search,
        bool $unreadOnly,
        bool $includeDeleted
    ): array {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null) {
            return [
                'source' => 'cache',
                'account_idx' => $accountIdx,
                'folder' => $folder,
                'items' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'pages' => 1,
            ];
        }

        $params = [
            'account_idx' => $accountIdx,
        ];
        $where = [$this->qcol((string)$meta['account']) . ' = :account_idx'];

        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['folder'])) {
            $where[] = $this->qcol((string)$meta['folder']) . ' = :folder';
            $params['folder'] = $folder;
        }
        if (!$includeDeleted && !empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }
        if ($unreadOnly && !empty($meta['is_seen'])) {
            $where[] = $this->qcol((string)$meta['is_seen']) . ' = 0';
        }

        $search = trim($search);
        if ($search !== '') {
            $searchColumns = [];
            foreach (['subject', 'from', 'to', 'cc', 'body_preview'] as $key) {
                $col = (string)($meta[$key] ?? '');
                if ($col !== '') {
                    $searchColumns[] = $this->qcol($col) . ' LIKE :search';
                }
            }
            if ($searchColumns !== []) {
                $where[] = '(' . implode(' OR ', $searchColumns) . ')';
                $params['search'] = '%' . $search . '%';
            }
        }

        $whereSql = implode(' AND ', $where);
        $table = 'dbo.' . (string)$meta['table'];

        $countSql = 'SELECT COUNT(1) AS cnt FROM ' . $table . ' WHERE ' . $whereSql;
        $countStmt = $this->repo->prepareStatement($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($countRow['cnt'] ?? 0);

        $pages = max(1, (int)ceil(($total > 0 ? $total : 1) / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $orderBy = '';
        if (!empty($meta['date'])) {
            $orderBy = $this->qcol((string)$meta['date']) . ' DESC';
        } elseif (!empty($meta['id'])) {
            $orderBy = $this->qcol((string)$meta['id']) . ' DESC';
        } else {
            $orderBy = $this->qcol((string)$meta['account']) . ' DESC';
        }
        if (!empty($meta['uid'])) {
            $orderBy .= ', ' . $this->qcol((string)$meta['uid']) . ' DESC';
        }

        $listSql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $whereSql
            . ' ORDER BY ' . $orderBy
            . ' OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$limit . ' ROWS ONLY';

        $listStmt = $this->repo->prepareStatement($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->mapCacheRowToMessage($row, $meta);
        }

        return [
            'source' => 'cache',
            'account_idx' => $accountIdx,
            'folder' => $folder,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
        ];
    }

    private function loadMailDetailFromCache(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $uid,
        int $rowId,
        string $messageId
    ): ?array {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null) {
            return null;
        }

        $params = [
            'account_idx' => $accountIdx,
        ];
        $where = [$this->qcol((string)$meta['account']) . ' = :account_idx'];

        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['folder'])) {
            $where[] = $this->qcol((string)$meta['folder']) . ' = :folder';
            $params['folder'] = $folder;
        }
        if (!empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }

        if ($uid > 0 && !empty($meta['uid'])) {
            $where[] = $this->qcol((string)$meta['uid']) . ' = :uid';
            $params['uid'] = $uid;
        } elseif ($rowId > 0 && !empty($meta['id'])) {
            $where[] = $this->qcol((string)$meta['id']) . ' = :row_id';
            $params['row_id'] = $rowId;
        } elseif ($messageId !== '' && !empty($meta['message_id'])) {
            $where[] = $this->qcol((string)$meta['message_id']) . ' = :message_id';
            $params['message_id'] = $messageId;
        } else {
            return null;
        }

        $orderBy = !empty($meta['date']) ? $this->qcol((string)$meta['date']) . ' DESC' : (!empty($meta['id']) ? $this->qcol((string)$meta['id']) . ' DESC' : '1 DESC');

        $sql = 'SELECT TOP 1 * FROM dbo.' . (string)$meta['table']
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $orderBy;

        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $message = $this->mapCacheRowToMessage($row, $meta);
        $message['body_html'] = (string)$this->rowValue($row, (string)($meta['body_html'] ?? ''));
        $message['body_text'] = (string)$this->rowValue($row, (string)($meta['body_text'] ?? ''));

        if ($message['body_html'] === '' && $message['body_text'] !== '') {
            $message['body_html'] = nl2br(htmlspecialchars($message['body_text'], ENT_QUOTES, 'UTF-8'));
        }
        $message['body_html'] = $this->sanitizeHtmlBody((string)$message['body_html']);
        $inlineResolved = $this->materializeInlineCidAssets(
            $tenantId,
            $accountIdx,
            (int)($message['uid'] ?? 0),
            0,
            (string)$message['body_html'],
            (array)($message['attachments'] ?? [])
        );
        $message['body_html'] = (string)($inlineResolved['body_html'] ?? $message['body_html']);
        $message['inline_parts'] = $this->applyInlineCidUrls(
            $this->buildInlineCidParts((array)($message['attachments'] ?? [])),
            (array)($inlineResolved['cid_url_map'] ?? [])
        );

        return $message;
    }

    private function loadMailListFromImap(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $page,
        int $limit,
        string $search,
        bool $unreadOnly
    ): array {
        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        $stream = $imap['stream'];

        // 전체 overview를 한 번에 가져오기 (V1 방식: 메일 N건이어도 IMAP 왕복 1회)
        $msgCount = @imap_num_msg($stream);
        $allOverviews = [];
        if ($msgCount > 0) {
            $raw = @imap_fetch_overview($stream, '1:' . $msgCount, 0);
            if (is_array($raw)) {
                $allOverviews = $raw;
            }
        }

        @imap_close($stream);

        // UID 내림차순 정렬 (최신순)
        usort($allOverviews, static function ($a, $b) {
            return (int)($b->uid ?? 0) - (int)($a->uid ?? 0);
        });

        $search = trim($search);
        $filteredRows = [];

        foreach ($allOverviews as $overview) {
            if (!is_object($overview)) {
                continue;
            }

            $isSeen = ((int)($overview->seen ?? 0) === 1);
            if ($unreadOnly && $isSeen) {
                continue;
            }

            if ($search !== '') {
                $subject = $this->decodeMimeHeader((string)($overview->subject ?? ''));
                $from    = $this->decodeMimeHeader((string)($overview->from ?? ''));
                $to      = $this->decodeMimeHeader((string)($overview->to ?? ''));
                $probe   = mb_strtolower($subject . ' ' . $from . ' ' . $to);
                if (mb_strpos($probe, mb_strtolower($search)) === false) {
                    continue;
                }
                $filteredRows[] = ['ov' => $overview, 'subject' => $subject, 'from' => $from, 'to' => $to];
            } else {
                $filteredRows[] = ['ov' => $overview, 'subject' => null, 'from' => null, 'to' => null];
            }
        }

        $filteredTotal = count($filteredRows);
        $start = ($page - 1) * $limit;
        $pageSlice = array_slice($filteredRows, $start, $limit);

        $items = [];
        foreach ($pageSlice as $row) {
            $ov      = $row['ov'];
            $uid     = (int)($ov->uid ?? 0);
            $subject = $row['subject'] ?? $this->decodeMimeHeader((string)($ov->subject ?? ''));
            $from    = $row['from']    ?? $this->decodeMimeHeader((string)($ov->from ?? ''));
            $to      = $row['to']      ?? $this->decodeMimeHeader((string)($ov->to ?? ''));

            $items[] = [
                'id'           => 0,
                'uid'          => $uid,
                'folder'       => $folder,
                'message_id'   => trim((string)($ov->message_id ?? '')),
                'subject'      => $subject,
                'from_address' => $from,
                'to_address'   => $to,
                'cc_address'   => '',
                'date'         => $this->normalizeDate((string)($ov->date ?? '')),
                'is_seen'      => ((int)($ov->seen ?? 0) === 1) ? 1 : 0,
                'is_flagged'   => ((int)($ov->flagged ?? 0) === 1) ? 1 : 0,
                'is_deleted'   => 0,
                'has_attachment' => 0,
                'size'         => (int)($ov->size ?? 0),
                'thread_id'    => '',
                'body_preview' => '',
                'body_html'    => '',
                'body_text'    => '',
                'in_reply_to'  => '',
                'references'   => '',
                'attachments'  => [],
            ];
        }

        $pages = max(1, (int)ceil(($filteredTotal ?: 1) / $limit));

        return [
            'source'      => 'imap',
            'account_idx' => $accountIdx,
            'folder'      => $folder,
            'items'       => $items,
            'total'       => $filteredTotal,
            'page'        => $page,
            'limit'       => $limit,
            'pages'       => $pages,
        ];
    }

    private function loadMailDetailFromImap(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $uid,
        int $msgno
    ): array {
        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        if ($imap === null) {
            return [];
        }

        $stream = $imap['stream'];

        if ($msgno <= 0 && $uid > 0) {
            $msgno = (int)@imap_msgno($stream, $uid);
        }
        if ($uid <= 0 && $msgno > 0) {
            $uid = (int)@imap_uid($stream, $msgno);
        }
        if ($msgno <= 0) {
            @imap_close($stream);
            return [];
        }

        $overviewRows = $uid > 0
            ? @imap_fetch_overview($stream, (string)$uid, FT_UID)
            : @imap_fetch_overview($stream, (string)$msgno, 0);
        $overview = is_array($overviewRows) && isset($overviewRows[0]) && is_object($overviewRows[0]) ? $overviewRows[0] : null;

        $header = @imap_headerinfo($stream, $msgno);
        $structure = @imap_fetchstructure($stream, $msgno);

        $parsed = [
            'html' => '',
            'text' => '',
            'attachments' => [],
        ];

        if (is_object($structure)) {
            $this->parseImapStructurePart($stream, $msgno, $structure, '', $parsed);
        } else {
            $body = @imap_body($stream, $msgno);
            if (is_string($body)) {
                $parsed['text'] = $body;
            }
        }

        if ($parsed['html'] === '' && $parsed['text'] !== '') {
            $parsed['html'] = nl2br(htmlspecialchars($parsed['text'], ENT_QUOTES, 'UTF-8'));
        }
        $safeHtml = $this->sanitizeHtmlBody((string)$parsed['html']);
        $inlineResolved = $this->materializeInlineCidAssets(
            $tenantId,
            $accountIdx,
            $uid,
            $msgno,
            $safeHtml,
            (array)$parsed['attachments'],
            $stream
        );
        @imap_close($stream);

        $safeHtml = (string)($inlineResolved['body_html'] ?? $safeHtml);
        $inlineParts = $this->applyInlineCidUrls(
            $this->buildInlineCidParts((array)$parsed['attachments']),
            (array)($inlineResolved['cid_url_map'] ?? [])
        );

        $subject = '';
        if ($overview !== null) {
            $subject = $this->decodeMimeHeader((string)($overview->subject ?? ''));
        }
        if ($subject === '' && is_object($header) && isset($header->subject)) {
            $subject = $this->decodeMimeHeader((string)$header->subject);
        }

        $from = $this->imapAddressList(is_object($header) && isset($header->from) && is_array($header->from) ? $header->from : []);
        $to = $this->imapAddressList(is_object($header) && isset($header->to) && is_array($header->to) ? $header->to : []);
        $cc = $this->imapAddressList(is_object($header) && isset($header->cc) && is_array($header->cc) ? $header->cc : []);

        return [
            'id' => 0,
            'uid' => $uid,
            'msgno' => $msgno,
            'folder' => $folder,
            'message_id' => trim((string)($overview->message_id ?? '')),
            'subject' => $subject,
            'from_address' => $from,
            'to_address' => $to,
            'cc_address' => $cc,
            'date' => $this->normalizeDate((string)($overview->date ?? '')),
            'is_seen' => (int)($overview->seen ?? 0) === 1 ? 1 : 0,
            'is_flagged' => (int)($overview->flagged ?? 0) === 1 ? 1 : 0,
            'is_deleted' => 0,
            'has_attachment' => count($parsed['attachments']) > 0 ? 1 : 0,
            'size' => (int)($overview->size ?? 0),
            'thread_id' => '',
            'body_preview' => '',
            'body_html' => $safeHtml,
            'body_text' => (string)$parsed['text'],
            'in_reply_to' => '',
            'references' => '',
            'attachments' => $parsed['attachments'],
            'inline_parts' => $inlineParts,
        ];
    }

    private function updateReadFlagInCache(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList,
        array $idList,
        bool $isRead
    ): int {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['is_seen'])) {
            return 0;
        }

        $setParts = [$this->qcol((string)$meta['is_seen']) . ' = :is_seen'];
        if (!empty($meta['updated_at'])) {
            $setParts[] = $this->qcol((string)$meta['updated_at']) . ' = GETDATE()';
        }

        $params = [
            'is_seen' => $isRead ? 1 : 0,
            'account_idx' => $accountIdx,
        ];
        $where = [$this->qcol((string)$meta['account']) . ' = :account_idx'];

        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['folder'])) {
            $where[] = $this->qcol((string)$meta['folder']) . ' = :folder';
            $params['folder'] = $folder;
        }

        $targetWhere = [];
        if ($uidList !== [] && !empty($meta['uid'])) {
            $placeholders = [];
            foreach ($uidList as $idx => $uid) {
                $key = 'uid_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $uid;
            }
            $targetWhere[] = $this->qcol((string)$meta['uid']) . ' IN (' . implode(', ', $placeholders) . ')';
        }
        if ($idList !== [] && !empty($meta['id'])) {
            $placeholders = [];
            foreach ($idList as $idx => $rowId) {
                $key = 'id_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $rowId;
            }
            $targetWhere[] = $this->qcol((string)$meta['id']) . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($targetWhere === []) {
            return 0;
        }

        $where[] = '(' . implode(' OR ', $targetWhere) . ')';

        $sql = 'UPDATE dbo.' . (string)$meta['table']
            . ' SET ' . implode(', ', $setParts)
            . ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    private function updateFlagInCache(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList,
        array $idList,
        bool $isFlagged
    ): int {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['is_flagged'])) {
            return 0;
        }

        $setParts = [$this->qcol((string)$meta['is_flagged']) . ' = :is_flagged'];
        if (!empty($meta['updated_at'])) {
            $setParts[] = $this->qcol((string)$meta['updated_at']) . ' = GETDATE()';
        }

        $params = [
            'is_flagged' => $isFlagged ? 1 : 0,
            'account_idx' => $accountIdx,
        ];
        $where = [$this->qcol((string)$meta['account']) . ' = :account_idx'];

        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['folder'])) {
            $where[] = $this->qcol((string)$meta['folder']) . ' = :folder';
            $params['folder'] = $folder;
        }

        $targetWhere = [];
        if ($uidList !== [] && !empty($meta['uid'])) {
            $placeholders = [];
            foreach ($uidList as $idx => $uid) {
                $key = 'uid_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $uid;
            }
            $targetWhere[] = $this->qcol((string)$meta['uid']) . ' IN (' . implode(', ', $placeholders) . ')';
        }
        if ($idList !== [] && !empty($meta['id'])) {
            $placeholders = [];
            foreach ($idList as $idx => $rowId) {
                $key = 'id_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $rowId;
            }
            $targetWhere[] = $this->qcol((string)$meta['id']) . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($targetWhere === []) {
            return 0;
        }

        $where[] = '(' . implode(' OR ', $targetWhere) . ')';

        $sql = 'UPDATE dbo.' . (string)$meta['table']
            . ' SET ' . implode(', ', $setParts)
            . ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    private function softDeleteInCache(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList,
        array $idList
    ): int {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null) {
            return 0;
        }

        $setParts = [];
        $params = [
            'account_idx' => $accountIdx,
        ];

        if (!empty($meta['is_deleted'])) {
            $setParts[] = $this->qcol((string)$meta['is_deleted']) . ' = 1';
            if (!empty($meta['deleted_at'])) {
                $setParts[] = $this->qcol((string)$meta['deleted_at']) . ' = GETDATE()';
            }
        } elseif (!empty($meta['folder'])) {
            $setParts[] = $this->qcol((string)$meta['folder']) . ' = :trash_folder';
            $params['trash_folder'] = '휴지통';
        } else {
            throw new RuntimeException('cache table has no soft delete strategy');
        }

        if (!empty($meta['updated_at'])) {
            $setParts[] = $this->qcol((string)$meta['updated_at']) . ' = GETDATE()';
        }

        $where = [$this->qcol((string)$meta['account']) . ' = :account_idx'];
        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['folder'])) {
            $where[] = $this->qcol((string)$meta['folder']) . ' = :folder';
            $params['folder'] = $folder;
        }

        $targetWhere = [];
        if ($uidList !== [] && !empty($meta['uid'])) {
            $placeholders = [];
            foreach ($uidList as $idx => $uid) {
                $key = 'uid_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $uid;
            }
            $targetWhere[] = $this->qcol((string)$meta['uid']) . ' IN (' . implode(', ', $placeholders) . ')';
        }
        if ($idList !== [] && !empty($meta['id'])) {
            $placeholders = [];
            foreach ($idList as $idx => $rowId) {
                $key = 'id_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $rowId;
            }
            $targetWhere[] = $this->qcol((string)$meta['id']) . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($targetWhere === []) {
            return 0;
        }

        $where[] = '(' . implode(' OR ', $targetWhere) . ')';

        $sql = 'UPDATE dbo.' . (string)$meta['table']
            . ' SET ' . implode(', ', $setParts)
            . ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    private function updateReadFlagInImap(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList,
        bool $isRead
    ): void {
        if ($uidList === []) {
            return;
        }

        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        if ($imap === null) {
            return;
        }

        $uidSequence = implode(',', array_map(static fn (int $v): string => (string)$v, $uidList));
        if ($uidSequence === '') {
            @imap_close($imap['stream']);
            return;
        }

        if ($isRead) {
            @imap_setflag_full($imap['stream'], $uidSequence, '\\Seen', ST_UID);
        } else {
            @imap_clearflag_full($imap['stream'], $uidSequence, '\\Seen', ST_UID);
        }

        @imap_close($imap['stream']);
    }

    private function updateFlagInImap(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        array $uidList,
        bool $isFlagged
    ): void {
        if ($uidList === []) {
            return;
        }

        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $folder);
        if ($imap === null) {
            return;
        }

        $uidSequence = implode(',', array_map(static fn (int $v): string => (string)$v, $uidList));
        if ($uidSequence === '') {
            @imap_close($imap['stream']);
            return;
        }

        if ($isFlagged) {
            @imap_setflag_full($imap['stream'], $uidSequence, '\\Flagged', ST_UID);
        } else {
            @imap_clearflag_full($imap['stream'], $uidSequence, '\\Flagged', ST_UID);
        }

        @imap_close($imap['stream']);
    }

    private function openImapMailbox(string $serviceCode, int $tenantId, int $accountIdx, string $folder): array
    {
        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('IMAP 계정을 찾을 수 없습니다. (account_idx=' . $accountIdx . ')');
        }

        $raw = json_decode((string)($account['raw_json'] ?? '{}'), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $credentials = $this->loadCredentialMap($accountIdx);
        $host = trim((string)($credentials['host'] ?? $raw['host'] ?? ''));
        $port = (int)($credentials['port'] ?? $raw['port'] ?? 993);
        $sslRaw = strtolower(trim((string)($credentials['ssl'] ?? $raw['ssl'] ?? '1')));
        $useSsl = in_array($sslRaw, ['1', 'true', 'yes', 'on'], true);

        $loginId = trim((string)($credentials['login_id'] ?? $raw['login_id'] ?? ''));
        $password = (string)($credentials['password'] ?? $raw['password'] ?? '');

        if ($host === '') {
            throw new \RuntimeException('IMAP Host가 설정되지 않았습니다.');
        }
        if ($loginId === '' || $password === '') {
            throw new \RuntimeException('IMAP 로그인 정보(ID/비밀번호)를 불러올 수 없습니다. 계정 설정에서 비밀번호를 다시 저장해 주세요.');
        }

        if ($port <= 0) {
            $port = 993;
        }

        $flags = '/imap' . ($useSsl ? '/ssl/novalidate-cert' : '/notls');
        $baseMailbox = '{' . $host . ':' . $port . $flags . '}';
        $encodedFolder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $mailbox = $baseMailbox . $encodedFolder;

        if (defined('IMAP_OPENTIMEOUT'))  { @imap_timeout(IMAP_OPENTIMEOUT,  10); }
        if (defined('IMAP_READTIMEOUT'))  { @imap_timeout(IMAP_READTIMEOUT,  20); }
        if (defined('IMAP_WRITETIMEOUT')) { @imap_timeout(IMAP_WRITETIMEOUT, 20); }

        $stream = @imap_open($mailbox, $loginId, $password, 0, 1);
        if ($stream === false) {
            $err = imap_last_error() ?: 'imap_open failed';
            throw new \RuntimeException('IMAP 연결 실패: ' . $err);
        }

        return [
            'stream' => $stream,
            'mailbox' => $baseMailbox,
            'folder' => $folder,
        ];
    }

    private function parseImapStructurePart($stream, int $msgno, object $part, string $partNo, array &$parsed): void
    {
        $parts = (isset($part->parts) && is_array($part->parts)) ? $part->parts : [];
        if ($parts !== []) {
            foreach ($parts as $idx => $subPart) {
                if (!is_object($subPart)) {
                    continue;
                }
                $nextPartNo = $partNo === '' ? (string)($idx + 1) : $partNo . '.' . ($idx + 1);
                $this->parseImapStructurePart($stream, $msgno, $subPart, $nextPartNo, $parsed);
            }
            return;
        }

        $fetchPartNo = $partNo !== '' ? $partNo : '1';
        $rawBody = @imap_fetchbody($stream, $msgno, $fetchPartNo);
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        $decodedBody = $this->decodeTransferEncoding($rawBody, (int)($part->encoding ?? 0));
        $decodedBody = $this->decodeByCharset($decodedBody, $this->extractPartCharset($part));

        $filename = $this->extractPartFilename($part);
        $disposition = strtolower(trim((string)($part->disposition ?? '')));
        $contentId = trim((string)($part->id ?? ''), '<>');
        $isAttachment = $filename !== '' || in_array($disposition, ['attachment', 'inline'], true) || $contentId !== '';

        if ($isAttachment) {
            if ($filename === '') {
                $filename = 'attachment-' . $fetchPartNo;
            }
            $parsed['attachments'][] = [
                'partno' => $fetchPartNo,
                'name' => $filename,
                'size' => (int)($part->bytes ?? strlen($decodedBody)),
                'is_inline' => $disposition === 'inline' ? 1 : 0,
                'content_id' => $contentId,
                'content_type' => $this->resolvePartContentType($part),
                'encoding' => (int)($part->encoding ?? 0),
            ];
        }

        $type = (int)($part->type ?? 0);
        $subtype = strtoupper(trim((string)($part->subtype ?? '')));

        if ($type === 0) {
            if ($subtype === 'HTML') {
                if ((string)$parsed['html'] === '') {
                    $parsed['html'] = $decodedBody;
                } else {
                    $parsed['html'] .= "\n" . $decodedBody;
                }
            } else {
                if ((string)$parsed['text'] === '') {
                    $parsed['text'] = $decodedBody;
                } else {
                    $parsed['text'] .= "\n" . $decodedBody;
                }
            }
        }
    }

    private function buildInlineCidParts(array $attachments): array
    {
        $items = [];
        $seen = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $cidRaw = trim((string)($attachment['content_id'] ?? ''));
            $cidNorm = $this->normalizeCidToken($cidRaw);
            if ($cidNorm === '') {
                continue;
            }

            if (isset($seen[$cidNorm])) {
                continue;
            }
            $seen[$cidNorm] = true;

            $items[] = [
                'cid' => $cidRaw !== '' ? $cidRaw : $cidNorm,
                'content_type' => (string)($attachment['content_type'] ?? 'application/octet-stream'),
                'partno' => (string)($attachment['partno'] ?? ''),
                'name' => (string)($attachment['name'] ?? ''),
                'size' => (int)($attachment['size'] ?? 0),
                'is_inline' => $this->toBool($attachment['is_inline'] ?? false) ? 1 : 0,
            ];
        }

        return $items;
    }

    private function resolveInlinePartMeta(object $structure, string $cid, string $partNo = ''): ?array
    {
        $partNo = trim($partNo);
        $cidNorm = $this->normalizeCidToken($cid);
        $parts = [];
        $this->collectImapLeafParts($structure, '', $parts);
        if ($parts === []) {
            return null;
        }

        if ($partNo !== '') {
            foreach ($parts as $partMeta) {
                if ((string)($partMeta['partno'] ?? '') === $partNo) {
                    return $partMeta;
                }
            }
        }

        if ($cidNorm === '') {
            return null;
        }

        $fallback = null;
        foreach ($parts as $partMeta) {
            $partCid = $this->normalizeCidToken((string)($partMeta['content_id'] ?? ''));
            if ($partCid === '' || $partCid !== $cidNorm) {
                continue;
            }

            $isInline = $this->toBool($partMeta['is_inline'] ?? false);
            if ($isInline) {
                return $partMeta;
            }

            if ($fallback === null) {
                $fallback = $partMeta;
            }
        }

        return $fallback;
    }

    private function collectImapLeafParts(object $part, string $partNo, array &$collector): void
    {
        $parts = (isset($part->parts) && is_array($part->parts)) ? $part->parts : [];
        if ($parts !== []) {
            foreach ($parts as $idx => $subPart) {
                if (!is_object($subPart)) {
                    continue;
                }
                $nextPartNo = $partNo === '' ? (string)($idx + 1) : $partNo . '.' . ($idx + 1);
                $this->collectImapLeafParts($subPart, $nextPartNo, $collector);
            }
            return;
        }

        $fetchPartNo = $partNo !== '' ? $partNo : '1';
        $disposition = strtolower(trim((string)($part->disposition ?? '')));
        $collector[] = [
            'partno' => $fetchPartNo,
            'name' => $this->extractPartFilename($part),
            'size' => (int)($part->bytes ?? 0),
            'is_inline' => $disposition === 'inline' ? 1 : 0,
            'content_id' => trim((string)($part->id ?? ''), '<>'),
            'content_type' => $this->resolvePartContentType($part),
            'encoding' => (int)($part->encoding ?? 0),
        ];
    }

    private function normalizeCidToken(string $cid): string
    {
        $cid = trim($cid);
        if ($cid === '') {
            return '';
        }

        $cid = html_entity_decode($cid, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cid = rawurldecode($cid);
        $cid = preg_replace('/^cid:/i', '', $cid) ?? '';
        $cid = trim($cid, "<> \t\n\r\0\x0B\"'");
        if ($cid === '') {
            return '';
        }

        return strtolower($cid);
    }

    private function resolvePartContentType(object $part): string
    {
        $typeMap = [
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'application',
            8 => 'application',
        ];

        $type = (int)($part->type ?? 3);
        $base = $typeMap[$type] ?? 'application';
        $sub = strtolower(trim((string)($part->subtype ?? '')));

        if ($sub === '') {
            if ($base === 'text') {
                $sub = 'plain';
            } elseif ($base === 'multipart') {
                $sub = 'mixed';
            } else {
                $sub = 'octet-stream';
            }
        }

        return $base . '/' . $sub;
    }

    private function decodeTransferEncoding(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 3:
                $decoded = base64_decode($body, true);
                return is_string($decoded) ? $decoded : '';
            case 4:
                return quoted_printable_decode($body);
            default:
                return $body;
        }
    }

    private function extractPartCharset(object $part): string
    {
        $params = [];
        if (isset($part->parameters) && is_array($part->parameters)) {
            $params = array_merge($params, $part->parameters);
        }
        if (isset($part->dparameters) && is_array($part->dparameters)) {
            $params = array_merge($params, $part->dparameters);
        }

        foreach ($params as $obj) {
            if (!is_object($obj)) {
                continue;
            }
            $attr = strtoupper(trim((string)($obj->attribute ?? '')));
            if ($attr !== 'CHARSET') {
                continue;
            }
            return trim((string)($obj->value ?? ''));
        }

        return '';
    }

    private function extractPartFilename(object $part): string
    {
        $params = [];
        if (isset($part->dparameters) && is_array($part->dparameters)) {
            $params = array_merge($params, $part->dparameters);
        }
        if (isset($part->parameters) && is_array($part->parameters)) {
            $params = array_merge($params, $part->parameters);
        }

        foreach ($params as $obj) {
            if (!is_object($obj)) {
                continue;
            }
            $attr = strtolower(trim((string)($obj->attribute ?? '')));
            if (!in_array($attr, ['filename', 'name'], true)) {
                continue;
            }
            $value = trim((string)($obj->value ?? ''));
            if ($value === '') {
                continue;
            }
            return $this->decodeMimeHeader($value);
        }

        return '';
    }

    private function normalizeCharset(string $charset): string
    {
        static $map = [
            // 한국어 EUC-KR 계열 (실무 메일서버 호환)
            'ks_c_5601-1987'  => 'EUC-KR',
            'ks_c_5601'       => 'EUC-KR',
            'ks-c-5601-1987'  => 'EUC-KR',
            'euc-kr'          => 'EUC-KR',
            'euckr'           => 'EUC-KR',
            'euc_kr'          => 'EUC-KR',
            'cseuckr'         => 'EUC-KR',
            'csksc56011987'   => 'EUC-KR',
            'korean'          => 'EUC-KR',
            'iso-2022-kr'     => 'EUC-KR',
            'windows-949'     => 'EUC-KR',
            'cp949'           => 'EUC-KR',
            'x-windows-949'   => 'EUC-KR',
            'ms949'           => 'EUC-KR',
            // 일본어
            'shift_jis'       => 'SJIS',
            'shift-jis'       => 'SJIS',
            'iso-2022-jp'     => 'ISO-2022-JP',
            'euc-jp'          => 'EUC-JP',
            // 서유럽
            'windows-1252'    => 'CP1252',
            'windows-1251'    => 'CP1251',
            'iso-8859-1'      => 'ISO-8859-1',
            'iso-8859-2'      => 'ISO-8859-2',
            // UTF
            'utf-8'           => 'UTF-8',
            'utf8'            => 'UTF-8',
            // ASCII
            'us-ascii'        => 'ASCII',
            'ascii'           => 'ASCII',
        ];
        $key = strtolower(trim($charset));
        return $map[$key] ?? $charset;
    }

    private function decodeByCharset(string $text, string $charset): string
    {
        $charset = $this->normalizeCharset(trim($charset));
        if ($charset === '' || strtoupper($charset) === 'UTF-8' || strtoupper($charset) === 'DEFAULT') {
            return $text;
        }

        // iconv 우선 (V1과 동일 방식, 다양한 charset alias 지원)
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        // mb_convert_encoding 폴백: PHP 8에서 ValueError throw 가능하므로 try-catch 필수
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = mb_convert_encoding($text, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            } catch (\Throwable $_) {
                // 변환 실패 charset은 원본 반환
            }
        }

        return $text;
    }

    private function imapAddressList(array $addressObjects): string
    {
        $list = [];
        foreach ($addressObjects as $obj) {
            if (!is_object($obj)) {
                continue;
            }
            $mailbox = trim((string)($obj->mailbox ?? ''));
            $host = trim((string)($obj->host ?? ''));
            if ($mailbox === '' || $host === '') {
                continue;
            }
            $email = $mailbox . '@' . $host;
            $personal = trim($this->decodeMimeHeader((string)($obj->personal ?? '')));
            if ($personal !== '') {
                $list[] = $personal . ' <' . $email . '>';
            } else {
                $list[] = $email;
            }
        }

        return implode(', ', $list);
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
            $text = (string)($part->text ?? '');
            $charset = strtoupper(trim((string)($part->charset ?? 'UTF-8')));
            if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'DEFAULT') {
                $text = $this->decodeByCharset($text, $charset);
            }
            $decoded .= $text;
        }

        $decoded = trim($decoded);
        return $decoded !== '' ? $decoded : $value;
    }

    private function mapCacheRowToMessage(array $row, array $meta): array
    {
        $id = (int)$this->rowValue($row, (string)($meta['id'] ?? ''));
        $uid = (int)$this->rowValue($row, (string)($meta['uid'] ?? ''));
        $folder = (string)$this->rowValue($row, (string)($meta['folder'] ?? ''));

        $subject = (string)$this->rowValue($row, (string)($meta['subject'] ?? ''));
        $from = (string)$this->rowValue($row, (string)($meta['from'] ?? ''));
        $to = (string)$this->rowValue($row, (string)($meta['to'] ?? ''));
        $cc = (string)$this->rowValue($row, (string)($meta['cc'] ?? ''));

        $bodyPreview = (string)$this->rowValue($row, (string)($meta['body_preview'] ?? ''));

        return [
            'id' => $id,
            'uid' => $uid,
            'folder' => $folder,
            'message_id' => (string)$this->rowValue($row, (string)($meta['message_id'] ?? '')),
            'subject' => $subject,
            'from_address' => $from,
            'to_address' => $to,
            'cc_address' => $cc,
            'date' => $this->normalizeDate((string)$this->rowValue($row, (string)($meta['date'] ?? ''))),
            'is_seen' => $this->toBool($this->rowValue($row, (string)($meta['is_seen'] ?? ''))) ? 1 : 0,
            'is_flagged' => $this->toBool($this->rowValue($row, (string)($meta['is_flagged'] ?? ''))) ? 1 : 0,
            'is_deleted' => $this->toBool($this->rowValue($row, (string)($meta['is_deleted'] ?? ''))) ? 1 : 0,
            'has_attachment' => $this->toBool($this->rowValue($row, (string)($meta['has_attachment'] ?? ''))) ? 1 : 0,
            'size' => (int)$this->rowValue($row, (string)($meta['size'] ?? '')),
            'thread_id' => (string)$this->rowValue($row, (string)($meta['thread_id'] ?? '')),
            'body_preview' => $bodyPreview,
            'body_html' => '',
            'body_text' => '',
            'in_reply_to' => (string)$this->rowValue($row, (string)($meta['in_reply_to'] ?? '')),
            'references' => (string)$this->rowValue($row, (string)($meta['references'] ?? '')),
            'attachments' => [],
            'inline_parts' => [],
        ];
    }

    private function rowValue(array $row, string $column): mixed
    {
        if ($column === '') {
            return null;
        }
        return $row[$column] ?? null;
    }

    private function loadFolderMapFromCheckpoint(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        if (!$this->tableExists('Tb_IntSyncCheckpoint')) {
            return [];
        }

        $sql = "SELECT TOP 1 cursor_value
                FROM dbo.Tb_IntSyncCheckpoint
                WHERE service_code = :service_code
                  AND tenant_id = :tenant_id
                  AND provider_account_idx = :account_idx
                  AND sync_scope = 'mail.folder_map'
                ORDER BY idx DESC";
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'account_idx' => $accountIdx,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $decoded = json_decode((string)($row['cursor_value'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $folders = [];
        foreach ($decoded as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $folderKey = trim((string)($folder['folder_key'] ?? ''));
            if ($folderKey === '') {
                continue;
            }
            $folderName = trim((string)($folder['folder_name'] ?? $folderKey));
            $folders[] = [
                'folder_key' => $folderKey,
                'folder_name' => $folderName,
                'folder_type' => (string)($folder['folder_type'] ?? $this->guessFolderType($folderName, $folderKey)),
            ];
        }

        return $folders;
    }

    private function folderStatsFromCache(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['folder'])) {
            return [];
        }

        $folderCol = $this->qcol((string)$meta['folder']);
        $accountCol = $this->qcol((string)$meta['account']);
        $table = 'dbo.' . (string)$meta['table'];

        $params = [
            'account_idx' => $accountIdx,
        ];
        $where = [$accountCol . ' = :account_idx'];

        if (!empty($meta['service'])) {
            $where[] = $this->qcol((string)$meta['service']) . ' = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[] = $this->qcol((string)$meta['tenant']) . ' = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if (!empty($meta['is_deleted'])) {
            $where[] = $this->qcol((string)$meta['is_deleted']) . ' = 0';
        }

        $unreadExpr = !empty($meta['is_seen'])
            ? 'SUM(CASE WHEN ' . $this->qcol((string)$meta['is_seen']) . ' = 0 THEN 1 ELSE 0 END)'
            : '0';

        $sql = 'SELECT ' . $folderCol . ' AS folder_name, COUNT(1) AS total, ' . $unreadExpr . ' AS unread'
            . ' FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY ' . $folderCol;

        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['folder_name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $stats[$key] = [
                'total' => (int)($row['total'] ?? 0),
                'unread' => (int)($row['unread'] ?? 0),
            ];
        }

        return $stats;
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
        $stmt = $this->repo->prepareStatement($sql);
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

        $stmt = $this->repo->prepareStatement($sql);
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

    private function sanitizeHtmlBody(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
        $html = preg_replace(
            '#<\s*(script|iframe|object|embed|form|input|button|meta|link|base)[^>]*>.*?<\s*/\s*\1\s*>#is',
            '',
            $html
        ) ?? '';
        $html = preg_replace(
            '#<\s*(script|iframe|object|embed|form|input|button|meta|link|base)[^>]*/?\s*>#is',
            '',
            $html
        ) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';

        $allowedTags = '<a><b><strong><i><em><u><s><br><p><div><span><ul><ol><li><blockquote><pre><code><table><thead><tbody><tr><th><td><hr><h1><h2><h3><h4><h5><h6><img><style>';
        $html = strip_tags($html, $allowedTags);

        $html = preg_replace_callback(
            '/\s(href|src)\s*=\s*("|\')(.*?)\2/i',
            function (array $matches): string {
                $attr = strtolower((string)($matches[1] ?? ''));
                $quote = (string)($matches[2] ?? '"');
                $value = (string)($matches[3] ?? '');
                $safeValue = $this->sanitizeHtmlUri($value, $attr);
                return ' ' . $attr . '=' . $quote . htmlspecialchars($safeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $quote;
            },
            $html
        ) ?? $html;

        return trim($html);
    }

    private function sanitizeHtmlUri(string $value, string $attr): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/[\x00-\x20\x7F]+/u', '', $value);
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }
        $lower = strtolower($normalized);

        if ($lower[0] === '#' || str_starts_with($lower, '/')) {
            return $value;
        }

        $allowPrefixes = ['http://', 'https://', 'mailto:', 'cid:'];
        foreach ($allowPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $value;
            }
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $lower) === 1) {
            return '';
        }

        return $value;
    }

    private function materializeInlineCidAssets(
        int $tenantId,
        int $accountIdx,
        int $uid,
        int $msgno,
        string $html,
        array $attachments,
        $stream = null
    ): array {
        $html = (string)$html;
        if ($html === '') {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        $cidTokens = $this->extractCidTokensFromHtml($html);
        if ($cidTokens === []) {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        $inlineStorage = $this->resolveInlineStorage($tenantId);
        $dirPath = (string)($inlineStorage['dir_path'] ?? '');
        $dirUrl = (string)($inlineStorage['dir_url'] ?? '');
        if ($dirPath === '' || $dirUrl === '') {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        if (!is_dir($dirPath) && !@mkdir($dirPath, 0775, true) && !is_dir($dirPath)) {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        $resolvedUid = $uid > 0 ? $uid : $msgno;
        if ($resolvedUid <= 0) {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        $attachmentIndex = $this->buildInlineAttachmentIndexByCid($attachments);
        $cidUrlMap = [];
        $structure = null;

        foreach ($cidTokens as $cidNorm) {
            if (!is_string($cidNorm) || $cidNorm === '') {
                continue;
            }
            if (isset($cidUrlMap[$cidNorm])) {
                continue;
            }

            $existing = $this->findExistingInlineAssetForCid($dirPath, $dirUrl, $accountIdx, $resolvedUid, $cidNorm);
            if (is_array($existing) && !empty($existing['url'])) {
                $cidUrlMap[$cidNorm] = (string)$existing['url'];
                continue;
            }

            $partMeta = $attachmentIndex[$cidNorm] ?? null;
            if (!is_array($partMeta) && (is_resource($stream) || is_object($stream)) && $msgno > 0) {
                if (!is_object($structure)) {
                    $rawStructure = @imap_fetchstructure($stream, $msgno);
                    $structure = is_object($rawStructure) ? $rawStructure : null;
                }
                if (is_object($structure)) {
                    $partMeta = $this->resolveInlinePartMeta($structure, $cidNorm, '');
                }
            }
            if (!is_array($partMeta)) {
                continue;
            }

            $partNo = trim((string)($partMeta['partno'] ?? ''));
            if ($partNo === '' || $msgno <= 0) {
                continue;
            }
            if (!is_resource($stream) && !is_object($stream)) {
                continue;
            }

            $binary = $this->fetchImapPartBinary($stream, $msgno, $partNo, (int)($partMeta['encoding'] ?? 0));
            if ($binary === '') {
                continue;
            }

            $ext = $this->resolveInlineAssetExtension(
                (string)($partMeta['name'] ?? ''),
                (string)($partMeta['content_type'] ?? '')
            );
            $filename = $this->buildInlineAssetFilename($accountIdx, $resolvedUid, $cidNorm, $ext);
            $targetPath = rtrim($dirPath, '/\\') . '/' . $filename;

            if (!is_file($targetPath) || (int)@filesize($targetPath) <= 0) {
                $tmpPath = $targetPath . '.tmp-' . str_replace('.', '', (string)microtime(true)) . '-' . mt_rand(1000, 9999);
                $written = @file_put_contents($tmpPath, $binary, LOCK_EX);
                if (!is_int($written) || $written <= 0) {
                    @unlink($tmpPath);
                    continue;
                }
                @chmod($tmpPath, 0644);
                if (!@rename($tmpPath, $targetPath)) {
                    if (!is_file($targetPath)) {
                        @copy($tmpPath, $targetPath);
                    }
                    @unlink($tmpPath);
                }
            }

            if (is_file($targetPath) && (int)@filesize($targetPath) > 0) {
                $cidUrlMap[$cidNorm] = rtrim($dirUrl, '/') . '/' . rawurlencode($filename);
            }
        }

        if ($cidUrlMap === []) {
            return ['body_html' => $html, 'cid_url_map' => []];
        }

        return [
            'body_html' => $this->replaceCidSrcWithInlineUrls($html, $cidUrlMap),
            'cid_url_map' => $cidUrlMap,
        ];
    }

    private function buildInlineAttachmentIndexByCid(array $attachments): array
    {
        $index = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $cidNorm = $this->normalizeCidToken((string)($attachment['content_id'] ?? ''));
            if ($cidNorm === '') {
                continue;
            }

            $currentScore = $this->inlineAttachmentScore($attachment);
            $existingScore = isset($index[$cidNorm]) ? $this->inlineAttachmentScore((array)$index[$cidNorm]) : -1;
            if (!isset($index[$cidNorm]) || $currentScore > $existingScore) {
                $index[$cidNorm] = $attachment;
            }
        }

        return $index;
    }

    private function inlineAttachmentScore(array $attachment): int
    {
        $score = 0;
        if ($this->toBool($attachment['is_inline'] ?? false)) {
            $score += 10;
        }

        $contentType = strtolower(trim((string)($attachment['content_type'] ?? '')));
        if (str_starts_with($contentType, 'image/')) {
            $score += 5;
        }

        if (trim((string)($attachment['name'] ?? '')) !== '') {
            $score += 1;
        }

        return $score;
    }

    private function extractCidTokensFromHtml(string $html): array
    {
        $found = preg_match_all(
            '/\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i',
            $html,
            $matches,
            PREG_SET_ORDER
        );
        if (!is_int($found) || $found <= 0) {
            return [];
        }

        $tokens = [];
        $seen = [];

        foreach ($matches as $match) {
            $value = (string)($match[1] ?? $match[2] ?? $match[3] ?? '');
            $cidNorm = $this->normalizeCidUriValue($value);
            if ($cidNorm === '' || isset($seen[$cidNorm])) {
                continue;
            }
            $seen[$cidNorm] = true;
            $tokens[] = $cidNorm;
        }

        return $tokens;
    }

    private function normalizeCidUriValue(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return '';
        }
        if (stripos($value, 'cid:') !== 0) {
            return '';
        }

        return $this->normalizeCidToken($value);
    }

    private function replaceCidSrcWithInlineUrls(string $html, array $cidUrlMap): string
    {
        if ($html === '' || $cidUrlMap === []) {
            return $html;
        }

        $replaced = preg_replace_callback(
            '/(\bsrc\s*=\s*)(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i',
            function (array $matches) use ($cidUrlMap): string {
                $prefix = (string)($matches[1] ?? 'src=');
                $value = (string)($matches[2] ?? $matches[3] ?? $matches[4] ?? '');
                $cidNorm = $this->normalizeCidUriValue($value);
                if ($cidNorm === '') {
                    return (string)($matches[0] ?? '');
                }

                $url = trim((string)($cidUrlMap[$cidNorm] ?? ''));
                if ($url === '') {
                    return (string)($matches[0] ?? '');
                }

                return $prefix . '"' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            },
            $html
        );

        return is_string($replaced) ? $replaced : $html;
    }

    private function applyInlineCidUrls(array $inlineParts, array $cidUrlMap): array
    {
        if ($inlineParts === [] || $cidUrlMap === []) {
            return $inlineParts;
        }

        $items = [];
        foreach ($inlineParts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $cidNorm = $this->normalizeCidToken((string)($part['cid'] ?? ''));
            if ($cidNorm !== '' && isset($cidUrlMap[$cidNorm])) {
                $part['url'] = (string)$cidUrlMap[$cidNorm];
            }
            $items[] = $part;
        }

        return $items;
    }

    private function resolveInlineStorage(int $tenantId): array
    {
        static $cached = null;
        if (!is_array($cached)) {
            $projectRoot = dirname(__DIR__, 2);
            $cfgPath = $projectRoot . '/config/storage.php';
            $cfg = [];
            if (is_file($cfgPath)) {
                $loaded = require $cfgPath;
                if (is_array($loaded)) {
                    $cfg = $loaded;
                }
            }

            // 인라인 CID 캐시는 프로젝트 uploads 경로를 고정 사용한다.
            // 요구사항: uploads/{tenant_id}/mail/inline/
            $basePath = rtrim(str_replace('\\', '/', $projectRoot . '/uploads'), '/');
            $baseUrl = rtrim((string)($cfg['base_url'] ?? 'https://shvq.kr/SHVQ_V2'), '/');

            $cached = [
                'base_path' => $basePath,
                'base_url' => $baseUrl,
            ];
        }

        $tenantId = max(0, $tenantId);
        $basePath = (string)($cached['base_path'] ?? '');
        $baseUrl = (string)($cached['base_url'] ?? '');
        if ($basePath === '' || $baseUrl === '') {
            return ['dir_path' => '', 'dir_url' => ''];
        }

        return [
            'dir_path' => $basePath . '/' . $tenantId . '/mail/inline',
            'dir_url' => $baseUrl . '/uploads/' . $tenantId . '/mail/inline',
        ];
    }

    private function findExistingInlineAssetForCid(
        string $dirPath,
        string $dirUrl,
        int $accountIdx,
        int $uid,
        string $cidNorm
    ): ?array {
        if ($dirPath === '' || $cidNorm === '' || $accountIdx <= 0 || $uid <= 0) {
            return null;
        }

        $cidHash = sha1($cidNorm);
        $prefix = $accountIdx . '_' . $uid . '_' . $cidHash;
        $matches = glob(rtrim($dirPath, '/\\') . '/' . $prefix . '.*') ?: [];
        if ($matches === []) {
            return null;
        }

        sort($matches, SORT_STRING);
        foreach ($matches as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            if ((int)@filesize($path) <= 0) {
                continue;
            }
            $filename = basename($path);
            return [
                'filename' => $filename,
                'path' => $path,
                'url' => rtrim($dirUrl, '/') . '/' . rawurlencode($filename),
            ];
        }

        return null;
    }

    private function resolveInlineAssetExtension(string $filename, string $contentType): string
    {
        $ext = strtolower(trim((string)pathinfo($filename, PATHINFO_EXTENSION)));
        if (preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1) {
            return $ext;
        }

        $contentType = strtolower(trim($contentType));
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/svg+xml' => 'svg',
        ];
        if (isset($map[$contentType])) {
            return $map[$contentType];
        }

        if (str_starts_with($contentType, 'image/')) {
            $sub = preg_replace('/[^a-z0-9]+/i', '', substr($contentType, 6)) ?? '';
            $sub = strtolower($sub);
            if ($sub === 'jpeg') {
                $sub = 'jpg';
            }
            if ($sub !== '' && preg_match('/^[a-z0-9]{1,10}$/', $sub) === 1) {
                return $sub;
            }
        }

        return 'bin';
    }

    private function buildInlineAssetFilename(int $accountIdx, int $uid, string $cidNorm, string $ext): string
    {
        $accountIdx = max(0, $accountIdx);
        $uid = max(0, $uid);
        $cidHash = sha1($cidNorm);

        $ext = strtolower(trim($ext));
        if (preg_match('/^[a-z0-9]{1,10}$/', $ext) !== 1) {
            $ext = 'bin';
        }

        return $accountIdx . '_' . $uid . '_' . $cidHash . '.' . $ext;
    }

    private function fetchImapPartBinary($stream, int $msgno, string $partNo, int $encoding): string
    {
        if ($msgno <= 0 || trim($partNo) === '') {
            return '';
        }

        $rawBody = @imap_fetchbody($stream, $msgno, $partNo);
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        if ($rawBody === '' && $partNo === '1') {
            $fallbackBody = @imap_body($stream, $msgno);
            if (is_string($fallbackBody)) {
                $rawBody = $fallbackBody;
            }
        }
        if ($rawBody === '') {
            return '';
        }

        $binary = $this->decodeTransferEncoding($rawBody, $encoding);
        if ($binary === '' && $rawBody !== '') {
            $binary = $rawBody;
        }

        return $binary;
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

    private function resolveMailCacheTableMeta(): ?array
    {
        if ($this->cacheMetaLoaded) {
            return $this->cacheTableMeta;
        }

        $this->cacheMetaLoaded = true;

        foreach (['Tb_Mail', 'Tb_Mail_MessageCache'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $meta = [
                'table' => $table,
                'id' => $this->firstExistingColumn($table, ['id', 'idx']),
                'account' => $this->firstExistingColumn($table, ['account_idx', 'provider_account_idx', 'account_id']),
                'service' => $this->firstExistingColumn($table, ['service_code']),
                'tenant' => $this->firstExistingColumn($table, ['tenant_id']),
                'folder' => $this->firstExistingColumn($table, ['folder', 'folder_key', 'mail_folder']),
                'uid' => $this->firstExistingColumn($table, ['uid', 'mail_uid']),
                'message_id' => $this->firstExistingColumn($table, ['message_id']),
                'subject' => $this->firstExistingColumn($table, ['subject', 'title']),
                'from' => $this->firstExistingColumn($table, ['from_address', 'sender', 'from_email']),
                'to' => $this->firstExistingColumn($table, ['to_address', 'recipient', 'to_email']),
                'cc' => $this->firstExistingColumn($table, ['cc_address', 'cc']),
                'date' => $this->firstExistingColumn($table, ['date', 'received_at', 'sent_at', 'created_at']),
                'is_seen' => $this->firstExistingColumn($table, ['is_seen', 'is_read']),
                'is_flagged' => $this->firstExistingColumn($table, ['is_flagged']),
                'is_deleted' => $this->firstExistingColumn($table, ['is_deleted']),
                'deleted_at' => $this->firstExistingColumn($table, ['deleted_at']),
                'has_attachment' => $this->firstExistingColumn($table, ['has_attachment', 'is_attach']),
                'size' => $this->firstExistingColumn($table, ['size', 'mail_size']),
                'thread_id' => $this->firstExistingColumn($table, ['thread_id']),
                'body_preview' => $this->firstExistingColumn($table, ['body_preview', 'summary']),
                'in_reply_to' => $this->firstExistingColumn($table, ['in_reply_to', 'inreplyto']),
                'references' => $this->firstExistingColumn($table, ['references', 'message_references']),
                'body_html' => $this->firstExistingColumn($table, ['body_html']),
                'body_text' => $this->firstExistingColumn($table, ['body_text']),
                'updated_at' => $this->firstExistingColumn($table, ['updated_at']),
            ];

            if ((string)($meta['account'] ?? '') === '') {
                continue;
            }

            $this->cacheTableMeta = $meta;
            return $this->cacheTableMeta;
        }

        $this->cacheTableMeta = null;
        return null;
    }

    private function firstExistingColumn(string $tableName, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($tableName, $column)) {
                return $column;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // V2 이식: 메일 폴더 이동 (IMAP + DB 캐시)
    // -------------------------------------------------------------------------

    /**
     * moveMessage : 메일을 다른 폴더로 이동 (IMAP + DB 캐시)
     * V1 messages.php moveMessage() 로직 이식
     */
    public function moveMessage(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $srcFolder,
        int $uid,
        string $targetFolder
    ): array {
        $serviceCode  = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId     = max(0, $tenantId);
        $srcFolder    = trim($srcFolder) !== '' ? trim($srcFolder) : 'INBOX';
        $targetFolder = trim($targetFolder);

        if ($targetFolder === '') {
            throw new InvalidArgumentException('target_folder is required');
        }
        if ($uid <= 0) {
            throw new InvalidArgumentException('uid is required');
        }
        // IMAP 인젝션 방지
        if (preg_match('/[\x00-\x1f\x7f]/', $targetFolder) || strlen($targetFolder) > 200) {
            throw new InvalidArgumentException('올바르지 않은 폴더명입니다.');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP extension not available');
        }

        $imap = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $srcFolder);
        $stream = $imap['stream'];
        $baseMailbox = $imap['mailbox'];

        // 대상 폴더 화이트리스트 검증
        $mailboxes = @imap_getmailboxes($stream, $baseMailbox, '*');
        if (!is_array($mailboxes)) {
            @imap_close($stream);
            throw new \RuntimeException('폴더 목록 조회 실패');
        }
        $allowedFolders = [];
        foreach ($mailboxes as $mbx) {
            $fullName  = (string)($mbx->name ?? '');
            $shortName = (strpos($fullName, $baseMailbox) === 0)
                ? substr($fullName, strlen($baseMailbox))
                : $fullName;
            $decoded   = trim((string)mb_convert_encoding($shortName, 'UTF-8', 'UTF7-IMAP'));
            if ($decoded !== '') {
                $allowedFolders[$decoded] = true;
            }
        }
        if (empty($allowedFolders[$targetFolder])) {
            @imap_close($stream);
            throw new \RuntimeException('사용할 수 없는 대상 폴더입니다.');
        }

        // 원본 캐시 보관
        $meta = $this->resolveMailCacheTableMeta();
        $cachedMsg = null;
        if ($meta !== null) {
            $stmt = $this->repo->prepareStatement(
                'SELECT * FROM dbo.' . $meta['table']
                . ' WHERE [' . $meta['account'] . '] = ? AND [' . $meta['folder'] . '] = ? AND [' . $meta['uid'] . '] = ?'
            );
            $stmt->execute([$accountIdx, $srcFolder, $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $cachedMsg = $row;
            }
        }

        $targetEncoded = mb_convert_encoding($targetFolder, 'UTF7-IMAP', 'UTF-8');
        $result = @imap_mail_move($stream, (string)$uid, $targetEncoded, CP_UID);

        if (!$result) {
            $err = imap_last_error() ?: '알 수 없는 오류';
            @imap_close($stream);
            throw new \RuntimeException('이동 실패: ' . $err);
        }

        @imap_expunge($stream);

        // 원본 폴더 캐시 제거
        if ($meta !== null) {
            $this->repo->prepareStatement(
                'DELETE FROM dbo.' . $meta['table']
                . ' WHERE [' . $meta['account'] . '] = ? AND [' . $meta['folder'] . '] = ? AND [' . $meta['uid'] . '] = ?'
            )->execute([$accountIdx, $srcFolder, $uid]);
        }

        // 대상 폴더에서 새 UID 조회 후 캐시 INSERT
        try {
            $targetMailbox = $baseMailbox . $targetEncoded;
            $stream2 = @imap_open($targetMailbox, '', '', 0, 1);
            // stream2 열기 실패 대비: openImapMailbox 재시도
            if (!$stream2) {
                $imap2 = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, $targetFolder);
                $stream2 = $imap2['stream'];
            }
            if ($stream2 && $cachedMsg !== null && $meta !== null) {
                $status = @imap_status($stream2, $baseMailbox . $targetEncoded, SA_UIDVALIDITY | SA_UIDNEXT);
                $newUid = ($status && is_object($status)) ? ((int)($status->uidnext ?? 1) - 1) : 0;
                if ($newUid > 0) {
                    $this->insertCacheRow($meta, $accountIdx, $targetFolder, $newUid, $cachedMsg);
                }
                @imap_close($stream2);
            }
        } catch (\Throwable $_) {
            // 캐시 INSERT 실패는 무시, 다음 sync에서 복구
        }

        @imap_close($stream);

        return [
            'account_idx'   => $accountIdx,
            'src_folder'    => $srcFolder,
            'target_folder' => $targetFolder,
            'uid'           => $uid,
            'moved'         => true,
        ];
    }

    /**
     * markAllRead : 폴더 내 전체 읽음 처리 (DB 캐시)
     * V1 messages.php mark_all_read 로직 이식
     */
    public function markAllRead(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $folder      = trim($folder) !== '' ? trim($folder) : 'INBOX';

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['is_seen'])) {
            return ['account_idx' => $accountIdx, 'folder' => $folder, 'affected' => 0];
        }

        $where  = [
            '[' . $meta['account'] . '] = :account_idx',
            '[' . $meta['folder']  . '] = :folder',
            '[' . $meta['is_seen'] . '] = 0',
        ];
        $params = [
            'account_idx' => $accountIdx,
            'folder'      => $folder,
        ];

        if (!empty($meta['service'])) {
            $where[]              = '[' . $meta['service'] . '] = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[]            = '[' . $meta['tenant'] . '] = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $setParts = ['[' . $meta['is_seen'] . '] = 1'];
        if (!empty($meta['updated_at'])) {
            $setParts[] = '[' . $meta['updated_at'] . '] = GETDATE()';
        }

        $sql  = 'UPDATE dbo.' . $meta['table']
              . ' SET ' . implode(', ', $setParts)
              . ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);
        $affected = (int)$stmt->rowCount();

        return [
            'account_idx' => $accountIdx,
            'folder'      => $folder,
            'affected'    => $affected,
        ];
    }

    /**
     * spamMessage : 스팸 처리(스팸메일함 이동) 또는 스팸 해제(INBOX 이동)
     */
    public function spamMessage(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $uid,
        bool $isSpam
    ): array {
        $targetFolder = $isSpam ? '스팸메일함' : 'INBOX';
        return $this->moveMessage($serviceCode, $tenantId, $accountIdx, $folder, $uid, $targetFolder);
    }

    /**
     * linkSite : 현장 연결 (Tb_Site_Mail INSERT)
     * V1 messages.php linkSite() 로직 이식
     */
    public function linkSite(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $folder,
        int $uid,
        int $siteIdx,
        int $empIdx = 0,
        bool $force = false,
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $folder      = trim($folder) !== '' ? trim($folder) : 'INBOX';

        if ($uid <= 0 || $siteIdx <= 0) {
            throw new InvalidArgumentException('메일 UID와 현장 IDX가 필요합니다.');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        $this->assertManagerRole($actorRoleLevel);
        $this->ensureSiteMailTable();

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null) {
            throw new \RuntimeException('메일 캐시 테이블을 찾을 수 없습니다.');
        }

        // 메일 캐시 조회
        $whereParts = [
            '[' . $meta['account'] . '] = :account_idx',
            '[' . $meta['folder'] . '] = :folder',
            '[' . $meta['uid'] . '] = :uid',
        ];
        $whereParams = [
            'account_idx' => $accountIdx,
            'folder' => $folder,
            'uid' => $uid,
        ];
        if (!empty($meta['service'])) {
            $whereParts[] = '[' . $meta['service'] . '] = :service_code';
            $whereParams['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $whereParts[] = '[' . $meta['tenant'] . '] = :tenant_id';
            $whereParams['tenant_id'] = $tenantId;
        }

        $stmt = $this->repo->prepareStatement(
            'SELECT * FROM dbo.' . $meta['table']
            . ' WHERE ' . implode(' AND ', $whereParts)
        );
        $stmt->execute($whereParams);
        $mail = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($mail)) {
            throw new \RuntimeException('메일을 찾을 수 없습니다.');
        }

        $cacheId  = (int)($mail[$meta['id'] ?? 'id'] ?? 0);
        $title    = (string)($mail[$meta['subject'] ?? 'subject'] ?? '');
        $sender   = (string)($mail[$meta['from'] ?? 'from_address'] ?? '');
        $messageId = (string)($mail[$meta['message_id'] ?? 'message_id'] ?? '');

        if (!$force && $this->columnExists('Tb_Site_Mail', 'service_code') && $this->columnExists('Tb_Site_Mail', 'tenant_id')) {
            $dupStmt = $this->repo->prepareStatement(
                "SELECT TOP 10 idx, site_idx, account_idx, mail_uid, mail_folder
                 FROM dbo.Tb_Site_Mail
                 WHERE service_code = :service_code
                   AND tenant_id = :tenant_id
                   AND account_idx = :account_idx
                   AND site_idx = :site_idx
                   AND mail_uid = :mail_uid
                   AND ISNULL(is_deleted, 0) = 0
                 ORDER BY idx DESC"
            );
            $dupStmt->execute([
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'account_idx' => $accountIdx,
                'site_idx' => $siteIdx,
                'mail_uid' => $uid,
            ]);
            $duplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($duplicates)) {
                return ['duplicate' => true, 'duplicates' => $duplicates, 'message' => '중복 메일이 있습니다.'];
            }
        } elseif (!$force && $this->tableExists('Tb_Site_Mail')) {
            $dupStmt = $this->repo->prepareStatement(
                "SELECT TOP 10 idx, title, sender
                 FROM dbo.Tb_Site_Mail
                 WHERE site_idx = ? AND (mail_cache_id = ? OR (title = ? AND sender = ?))
                 ORDER BY idx DESC"
            );
            $dupStmt->execute([$siteIdx, $cacheId, $title, $sender]);
            $duplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($duplicates)) {
                return ['duplicate' => true, 'duplicates' => $duplicates, 'message' => '중복 메일이 있습니다.'];
            }
        }

        if ($this->columnExists('Tb_Site_Mail', 'service_code') && $this->columnExists('Tb_Site_Mail', 'tenant_id')) {
            $insertCols = ['service_code', 'tenant_id', 'account_idx', 'site_idx'];
            $insertVals = [':service_code', ':tenant_id', ':account_idx', ':site_idx'];
            $params = [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'account_idx' => $accountIdx,
                'site_idx' => $siteIdx,
            ];

            if ($this->columnExists('Tb_Site_Mail', 'mail_cache_id')) {
                $insertCols[] = 'mail_cache_id';
                $insertVals[] = ':mail_cache_id';
                $params['mail_cache_id'] = $cacheId > 0 ? $cacheId : null;
            }
            if ($this->columnExists('Tb_Site_Mail', 'mail_uid')) {
                $insertCols[] = 'mail_uid';
                $insertVals[] = ':mail_uid';
                $params['mail_uid'] = $uid;
            }
            if ($this->columnExists('Tb_Site_Mail', 'mail_folder')) {
                $insertCols[] = 'mail_folder';
                $insertVals[] = ':mail_folder';
                $params['mail_folder'] = $folder;
            }
            if ($this->columnExists('Tb_Site_Mail', 'message_id')) {
                $insertCols[] = 'message_id';
                $insertVals[] = ':message_id';
                $params['message_id'] = mb_substr($messageId, 0, 500);
            }
            if ($this->columnExists('Tb_Site_Mail', 'subject')) {
                $insertCols[] = 'subject';
                $insertVals[] = ':subject';
                $params['subject'] = mb_substr($title, 0, 500);
            }
            if ($this->columnExists('Tb_Site_Mail', 'from_address')) {
                $insertCols[] = 'from_address';
                $insertVals[] = ':from_address';
                $params['from_address'] = mb_substr($sender, 0, 320);
            }
            if ($this->columnExists('Tb_Site_Mail', 'employee_idx')) {
                $insertCols[] = 'employee_idx';
                $insertVals[] = ':employee_idx';
                $params['employee_idx'] = $empIdx > 0 ? $empIdx : null;
            }
            if ($this->columnExists('Tb_Site_Mail', 'status')) {
                $insertCols[] = 'status';
                $insertVals[] = ':status';
                $params['status'] = 'ACTIVE';
            }
            if ($this->columnExists('Tb_Site_Mail', 'is_deleted')) {
                $insertCols[] = 'is_deleted';
                $insertVals[] = ':is_deleted';
                $params['is_deleted'] = 0;
            }
            if ($this->columnExists('Tb_Site_Mail', 'created_by')) {
                $insertCols[] = 'created_by';
                $insertVals[] = ':created_by';
                $params['created_by'] = $actorUserPk > 0 ? $actorUserPk : null;
            }
            if ($this->columnExists('Tb_Site_Mail', 'updated_by')) {
                $insertCols[] = 'updated_by';
                $insertVals[] = ':updated_by';
                $params['updated_by'] = $actorUserPk > 0 ? $actorUserPk : null;
            }
            if ($this->columnExists('Tb_Site_Mail', 'linked_at')) {
                $insertCols[] = 'linked_at';
                $insertVals[] = 'GETDATE()';
            }
            if ($this->columnExists('Tb_Site_Mail', 'created_at')) {
                $insertCols[] = 'created_at';
                $insertVals[] = 'GETDATE()';
            }
            if ($this->columnExists('Tb_Site_Mail', 'updated_at')) {
                $insertCols[] = 'updated_at';
                $insertVals[] = 'GETDATE()';
            }

            $sql = 'INSERT INTO dbo.Tb_Site_Mail (' . implode(', ', $insertCols) . ')'
                 . ' VALUES (' . implode(', ', $insertVals) . ')';
            $stmt = $this->repo->prepareStatement($sql);
            $stmt->execute($params);
        } else {
            $memberIdx = 0;
            if ($this->tableExists('Tb_Site')) {
                $siteStmt = $this->repo->prepareStatement("SELECT member_idx FROM Tb_Site WHERE idx = ?");
                $siteStmt->execute([$siteIdx]);
                $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
                $memberIdx = (int)($site['member_idx'] ?? 0);
            }

            $this->repo->prepareStatement(
                "INSERT INTO Tb_Site_Mail (site_idx, member_idx, title, contents, sender, recipient, employee_idx, reg_date, registered_date, status, mail_cache_id, mail_account_id, mail_uid, mail_folder)"
                . " VALUES (?, ?, ?, '', ?, '', ?, CONVERT(DATE,GETDATE()), GETDATE(), 1, ?, ?, ?, ?)"
            )->execute([$siteIdx, $memberIdx, $title, $sender, $empIdx, $cacheId, $accountIdx, $uid, $folder]);
        }

        return [
            'account_idx' => $accountIdx,
            'uid'         => $uid,
            'site_idx'    => $siteIdx,
            'service_code'=> $serviceCode,
            'tenant_id'   => $tenantId,
            'linked'      => true,
        ];
    }

    /**
     * getThreadMessages : 스레드 묶음 조회 (thread_id 우선, subject fallback)
     * V1 messages.php getThreadMessages() 로직 이식
     */
    public function getThreadMessages(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $threadId,
        string $threadSubject = ''
    ): array {
        $serviceCode   = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId      = max(0, $tenantId);
        $threadId      = trim($threadId);
        $threadSubject = trim($threadSubject);

        if ($threadId === '' && $threadSubject === '') {
            throw new InvalidArgumentException('thread_id 또는 thread_subject가 필요합니다.');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null) {
            return ['messages' => [], 'thread_count' => 0, 'thread_id' => $threadId, 'thread_subject' => $threadSubject];
        }

        $tbl     = $meta['table'];
        $colAcc  = $meta['account'];
        $colDate = $meta['date'] ?? '';
        $colUid  = $meta['uid'] ?? '';
        $params  = ['account_idx' => $accountIdx];
        $where   = ['[' . $colAcc . '] = :account_idx'];

        if (!empty($meta['service'])) {
            $where[]              = '[' . $meta['service'] . '] = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[]            = '[' . $meta['tenant'] . '] = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        if ($threadId !== '' && !empty($meta['thread_id'])) {
            $where[]             = '[' . $meta['thread_id'] . '] = :thread_id';
            $params['thread_id'] = $threadId;
        } elseif ($threadSubject !== '' && !empty($meta['subject'])) {
            // fallback: thread_id 없는 건 + 제목 일치
            if (!empty($meta['thread_id'])) {
                $where[] = "(ISNULL([" . $meta['thread_id'] . "],'') = '' OR [" . $meta['thread_id'] . "] = '')";
            }
            $where[]                = '[' . $meta['subject'] . '] = :thread_subject';
            $params['thread_subject'] = $threadSubject;
        } else {
            return ['messages' => [], 'thread_count' => 0, 'thread_id' => $threadId, 'thread_subject' => $threadSubject];
        }

        $orderBy = '';
        if ($colDate !== '') {
            $orderBy = '[' . $colDate . '] ASC';
        }
        if ($colUid !== '') {
            $orderBy .= ($orderBy !== '' ? ', ' : '') . '[' . $colUid . '] ASC';
        }
        if ($orderBy === '') {
            $orderBy = '1 ASC';
        }

        $sql  = 'SELECT * FROM dbo.' . $tbl . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy;
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $messages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $messages[] = $this->mapCacheRowToMessage($row, $meta);
        }

        return [
            'account_idx'    => $accountIdx,
            'messages'       => $messages,
            'thread_count'   => count($messages),
            'thread_id'      => $threadId,
            'thread_subject' => $threadSubject,
        ];
    }

    /**
     * markNotDuplicate : 단건 중복메일함 -> INBOX 이동 (DB 캐시만 변경)
     */
    public function markNotDuplicate(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        int $uid
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);

        if ($uid <= 0) {
            throw new InvalidArgumentException('uid is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['folder']) || empty($meta['uid'])) {
            throw new \RuntimeException('cache table not available');
        }

        $params = ['account_idx' => $accountIdx, 'uid' => $uid];
        $where  = [
            '[' . $meta['account'] . '] = :account_idx',
            '[' . $meta['folder']  . '] = :dup_folder',
            '[' . $meta['uid']     . '] = :uid',
        ];
        $params['dup_folder'] = '중복메일함';

        $sql  = 'UPDATE dbo.' . $meta['table']
              . " SET [" . $meta['folder'] . "] = 'INBOX'"
              . ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return [
            'account_idx' => $accountIdx,
            'uid'         => $uid,
            'moved_to'    => 'INBOX',
            'affected'    => (int)$stmt->rowCount(),
        ];
    }

    /**
     * batchMarkNotDuplicate : 일괄 중복메일함 -> INBOX 이동 (DB 캐시 id 기반)
     * V1 messages.php batch_not_duplicate 로직 이식
     */
    public function batchMarkNotDuplicate(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        array $idList
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $idList      = $this->normalizeIntList($idList);

        if ($idList === []) {
            throw new InvalidArgumentException('ids is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['folder']) || empty($meta['id'])) {
            throw new \RuntimeException('cache table not available');
        }

        $placeholders = [];
        $params       = ['account_idx' => $accountIdx, 'dup_folder' => '중복메일함'];
        foreach ($idList as $i => $rowId) {
            $key               = 'id_' . $i;
            $placeholders[]    = ':' . $key;
            $params[$key]      = $rowId;
        }

        $sql  = 'UPDATE dbo.' . $meta['table']
              . " SET [" . $meta['folder'] . "] = 'INBOX'"
              . ' WHERE [' . $meta['account'] . '] = :account_idx'
              . ' AND [' . $meta['folder'] . '] = :dup_folder'
              . ' AND [' . $meta['id'] . '] IN (' . implode(', ', $placeholders) . ')';
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return [
            'account_idx' => $accountIdx,
            'moved_to'    => 'INBOX',
            'affected'    => (int)$stmt->rowCount(),
            'count'       => count($idList),
        ];
    }

    /**
     * clearDuplicates : 중복메일함 전체 삭제 (DB 캐시에서 DELETE)
     * V1 messages.php clear_duplicates 로직 이식
     */
    public function clearDuplicates(
        string $serviceCode,
        int $tenantId,
        int $accountIdx
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }

        $meta = $this->resolveMailCacheTableMeta();
        if ($meta === null || empty($meta['folder'])) {
            return ['account_idx' => $accountIdx, 'deleted' => 0];
        }

        $params = ['account_idx' => $accountIdx, 'dup_folder' => '중복메일함'];
        $where  = [
            '[' . $meta['account'] . '] = :account_idx',
            '[' . $meta['folder']  . '] = :dup_folder',
        ];

        if (!empty($meta['service'])) {
            $where[]              = '[' . $meta['service'] . '] = :service_code';
            $params['service_code'] = $serviceCode;
        }
        if (!empty($meta['tenant'])) {
            $where[]            = '[' . $meta['tenant'] . '] = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $sql  = 'DELETE FROM dbo.' . $meta['table'] . ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->repo->prepareStatement($sql);
        $stmt->execute($params);

        return [
            'account_idx' => $accountIdx,
            'deleted'     => (int)$stmt->rowCount(),
        ];
    }

    // -------------------------------------------------------------------------
    // V2 이식: IMAP 폴더 CRUD
    // -------------------------------------------------------------------------

    /**
     * listImapFolders : IMAP 서버에서 실제 폴더 목록 조회
     * V1 accounts.php GET action=folders 로직 이식
     */
    public function listImapFolders(string $serviceCode, int $tenantId, int $accountIdx): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP extension not available');
        }

        $imap        = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, 'INBOX');
        $stream      = $imap['stream'];
        $baseMailbox = $imap['mailbox'];

        $systemFolders = ['INBOX', '보낸메일함', '스팸메일함', '휴지통', '보관메일함', '임시보관함'];
        $folders       = @imap_list($stream, $baseMailbox, '*');
        $result        = [];

        if (is_array($folders)) {
            foreach ($folders as $f) {
                $raw     = str_replace($baseMailbox, '', (string)$f);
                $decoded = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF7-IMAP');
                $result[] = [
                    'name'   => $decoded,
                    'raw'    => $raw,
                    'system' => in_array($decoded, $systemFolders, true),
                ];
            }
        }

        @imap_close($stream);

        return [
            'account_idx' => $accountIdx,
            'folders'     => $result,
            'total'       => count($result),
        ];
    }

    /**
     * createFolder : IMAP 폴더 생성
     * V1 accounts.php POST action=create_folder 로직 이식
     */
    public function createFolder(string $serviceCode, int $tenantId, int $accountIdx, string $folderName): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $folderName  = trim($folderName);

        if ($folderName === '') {
            throw new InvalidArgumentException('folder_name is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP extension not available');
        }

        $imap        = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, 'INBOX');
        $stream      = $imap['stream'];
        $baseMailbox = $imap['mailbox'];
        $encoded     = mb_convert_encoding($folderName, 'UTF7-IMAP', 'UTF-8');

        if (!@imap_createmailbox($stream, $baseMailbox . $encoded)) {
            $err = imap_last_error() ?: '알 수 없는 오류';
            @imap_close($stream);
            throw new \RuntimeException('폴더 생성 실패: ' . $err);
        }

        @imap_close($stream);

        return [
            'account_idx' => $accountIdx,
            'folder_name' => $folderName,
            'created'     => true,
        ];
    }

    /**
     * deleteFolder : IMAP 폴더 삭제 (DB 캐시도 삭제)
     * V1 accounts.php POST action=delete_folder 로직 이식
     */
    public function deleteFolder(string $serviceCode, int $tenantId, int $accountIdx, string $folderName): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $folderName  = trim($folderName);

        if ($folderName === '') {
            throw new InvalidArgumentException('folder_name is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP extension not available');
        }

        $imap        = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, 'INBOX');
        $stream      = $imap['stream'];
        $baseMailbox = $imap['mailbox'];
        $encoded     = mb_convert_encoding($folderName, 'UTF7-IMAP', 'UTF-8');

        if (!@imap_deletemailbox($stream, $baseMailbox . $encoded)) {
            $err = imap_last_error() ?: '알 수 없는 오류';
            @imap_close($stream);
            throw new \RuntimeException('폴더 삭제 실패: ' . $err);
        }

        // DB 캐시도 삭제
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta !== null && !empty($meta['folder'])) {
            $this->repo->prepareStatement(
                'DELETE FROM dbo.' . $meta['table']
                . ' WHERE [' . $meta['account'] . '] = ? AND [' . $meta['folder'] . '] = ?'
            )->execute([$accountIdx, $folderName]);
        }

        @imap_close($stream);

        return [
            'account_idx' => $accountIdx,
            'folder_name' => $folderName,
            'deleted'     => true,
        ];
    }

    /**
     * renameFolder : IMAP 폴더 이름변경 (DB 캐시 폴더명도 갱신)
     * V1 accounts.php POST action=rename_folder 로직 이식
     */
    public function renameFolder(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $oldName,
        string $newName
    ): array {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);
        $oldName     = trim($oldName);
        $newName     = trim($newName);

        if ($oldName === '' || $newName === '') {
            throw new InvalidArgumentException('old_name and new_name are required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP extension not available');
        }

        $imap        = $this->openImapMailbox($serviceCode, $tenantId, $accountIdx, 'INBOX');
        $stream      = $imap['stream'];
        $baseMailbox = $imap['mailbox'];
        $oldEncoded  = mb_convert_encoding($oldName, 'UTF7-IMAP', 'UTF-8');
        $newEncoded  = mb_convert_encoding($newName, 'UTF7-IMAP', 'UTF-8');

        if (!@imap_renamemailbox($stream, $baseMailbox . $oldEncoded, $baseMailbox . $newEncoded)) {
            $err = imap_last_error() ?: '알 수 없는 오류';
            @imap_close($stream);
            throw new \RuntimeException('폴더 이름변경 실패: ' . $err);
        }

        // DB 캐시 폴더명 갱신
        $meta = $this->resolveMailCacheTableMeta();
        if ($meta !== null && !empty($meta['folder'])) {
            $this->repo->prepareStatement(
                'UPDATE dbo.' . $meta['table']
                . ' SET [' . $meta['folder'] . '] = ?'
                . ' WHERE [' . $meta['account'] . '] = ? AND [' . $meta['folder'] . '] = ?'
            )->execute([$newName, $accountIdx, $oldName]);
        }

        @imap_close($stream);

        return [
            'account_idx' => $accountIdx,
            'old_name'    => $oldName,
            'new_name'    => $newName,
            'renamed'     => true,
        ];
    }

    private function assertManagerRole(int $actorRoleLevel): void
    {
        $actorRoleLevel = max(0, $actorRoleLevel);
        if ($actorRoleLevel === 0 || $actorRoleLevel > 4) {
            throw new \RuntimeException('FORBIDDEN');
        }
    }

    private function assertAccountOwnerOrManager(array $account, int $actorUserPk, int $actorRoleLevel): void
    {
        $actorRoleLevel = max(0, $actorRoleLevel);
        $actorUserPk = max(0, $actorUserPk);
        if ($actorRoleLevel === 0) {
            throw new \RuntimeException('FORBIDDEN');
        }

        if ($actorRoleLevel > 4) {
            $ownerUserPk = (int)($account['user_pk'] ?? 0);
            if ($ownerUserPk <= 0 || $actorUserPk <= 0 || $ownerUserPk !== $actorUserPk) {
                throw new \RuntimeException('FORBIDDEN_ACCOUNT_SCOPE');
            }
        }
    }

    private function ensureSiteMailTable(): void
    {
        if (!$this->tableExists('Tb_Site_Mail')) {
            $this->repo->executeStatement("
                CREATE TABLE dbo.Tb_Site_Mail (
                    idx BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Tb_Site_Mail PRIMARY KEY,
                    service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Site_Mail_sc DEFAULT ('shvq'),
                    tenant_id INT NOT NULL CONSTRAINT DF_Tb_Site_Mail_tid DEFAULT (0),
                    account_idx INT NOT NULL,
                    site_idx INT NOT NULL,
                    mail_cache_id INT NULL,
                    mail_uid BIGINT NULL,
                    mail_folder NVARCHAR(255) NULL,
                    message_id NVARCHAR(500) NULL,
                    subject NVARCHAR(500) NULL,
                    from_address NVARCHAR(320) NULL,
                    employee_idx INT NULL,
                    status VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_Mail_status DEFAULT ('ACTIVE'),
                    is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Site_Mail_is_deleted DEFAULT (0),
                    linked_at DATETIME NULL,
                    created_by INT NULL,
                    updated_by INT NULL,
                    created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_created_at DEFAULT (GETDATE()),
                    updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_updated_at DEFAULT (GETDATE())
                )
            ");
            unset($this->tableExistsCache['Tb_Site_Mail']);
            $this->columnExistsCache = [];
        }

        if (!$this->tableExists('Tb_Site_Mail')) {
            return;
        }

        $alterMap = [
            'service_code' => "ALTER TABLE dbo.Tb_Site_Mail ADD service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Site_Mail_sc_alt DEFAULT ('shvq')",
            'tenant_id' => "ALTER TABLE dbo.Tb_Site_Mail ADD tenant_id INT NOT NULL CONSTRAINT DF_Tb_Site_Mail_tid_alt DEFAULT (0)",
            'account_idx' => "ALTER TABLE dbo.Tb_Site_Mail ADD account_idx INT NULL",
            'site_idx' => "ALTER TABLE dbo.Tb_Site_Mail ADD site_idx INT NULL",
            'mail_cache_id' => "ALTER TABLE dbo.Tb_Site_Mail ADD mail_cache_id INT NULL",
            'mail_uid' => "ALTER TABLE dbo.Tb_Site_Mail ADD mail_uid BIGINT NULL",
            'mail_folder' => "ALTER TABLE dbo.Tb_Site_Mail ADD mail_folder NVARCHAR(255) NULL",
            'message_id' => "ALTER TABLE dbo.Tb_Site_Mail ADD message_id NVARCHAR(500) NULL",
            'subject' => "ALTER TABLE dbo.Tb_Site_Mail ADD subject NVARCHAR(500) NULL",
            'from_address' => "ALTER TABLE dbo.Tb_Site_Mail ADD from_address NVARCHAR(320) NULL",
            'employee_idx' => "ALTER TABLE dbo.Tb_Site_Mail ADD employee_idx INT NULL",
            'status' => "ALTER TABLE dbo.Tb_Site_Mail ADD status VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_Mail_status_alt DEFAULT ('ACTIVE')",
            'is_deleted' => "ALTER TABLE dbo.Tb_Site_Mail ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Site_Mail_is_deleted_alt DEFAULT (0)",
            'linked_at' => "ALTER TABLE dbo.Tb_Site_Mail ADD linked_at DATETIME NULL",
            'created_by' => "ALTER TABLE dbo.Tb_Site_Mail ADD created_by INT NULL",
            'updated_by' => "ALTER TABLE dbo.Tb_Site_Mail ADD updated_by INT NULL",
            'created_at' => "ALTER TABLE dbo.Tb_Site_Mail ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_created_at_alt DEFAULT (GETDATE())",
            'updated_at' => "ALTER TABLE dbo.Tb_Site_Mail ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_updated_at_alt DEFAULT (GETDATE())",
        ];
        foreach ($alterMap as $column => $sql) {
            if (!$this->columnExists('Tb_Site_Mail', $column)) {
                $this->repo->executeStatement($sql);
                $this->columnExistsCache = [];
            }
        }
    }

    // -------------------------------------------------------------------------
    // V2 이식: 자동분류 규칙 (Tb_Mail_FilterRule)
    // -------------------------------------------------------------------------

    /**
     * ensureFilterRuleTable : Tb_Mail_FilterRule 테이블 자동 생성
     * V1 accounts.php save_filter 테이블 자동생성 로직 이식
     */
    private function ensureFilterRuleTable(): void
    {
        if (!$this->tableExists('Tb_Mail_FilterRule')) {
            $this->repo->executeStatement("
                CREATE TABLE dbo.Tb_Mail_FilterRule (
                    id            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Tb_Mail_FilterRule PRIMARY KEY,
                    account_idx   INT           NOT NULL,
                    service_code  VARCHAR(30)   NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_sc  DEFAULT ('shvq'),
                    tenant_id     INT           NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_tid DEFAULT (0),
                    from_address  NVARCHAR(300) NOT NULL,
                    target_folder NVARCHAR(200) NOT NULL,
                    is_active     BIT           NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_act DEFAULT (1),
                    is_deleted    BIT           NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_del DEFAULT (0),
                    created_by    INT           NULL,
                    updated_by    INT           NULL,
                    created_at    DATETIME      NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ca  DEFAULT (GETDATE()),
                    updated_at    DATETIME      NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ua  DEFAULT (GETDATE())
                )
            ");

            unset($this->tableExistsCache['Tb_Mail_FilterRule']);
            $this->columnExistsCache = [];
        }

        if (!$this->tableExists('Tb_Mail_FilterRule')) {
            return;
        }

        $alterMap = [
            'account_idx' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD account_idx INT NULL",
            'service_code' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_sc_alt DEFAULT ('shvq')",
            'tenant_id' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD tenant_id INT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_tid_alt DEFAULT (0)",
            'from_address' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD from_address NVARCHAR(300) NULL",
            'target_folder' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD target_folder NVARCHAR(200) NULL",
            'is_active' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD is_active BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_act_alt DEFAULT (1)",
            'is_deleted' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_del_alt DEFAULT (0)",
            'created_by' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD created_by INT NULL",
            'updated_by' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD updated_by INT NULL",
            'created_at' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ca_alt DEFAULT (GETDATE())",
            'updated_at' => "ALTER TABLE dbo.Tb_Mail_FilterRule ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ua_alt DEFAULT (GETDATE())",
        ];

        foreach ($alterMap as $column => $sql) {
            if (!$this->columnExists('Tb_Mail_FilterRule', $column)) {
                $this->repo->executeStatement($sql);
                $this->columnExistsCache = [];
            }
        }

        if (!$this->columnExists('Tb_Mail_FilterRule', 'id') && $this->columnExists('Tb_Mail_FilterRule', 'idx')) {
            // idx 스키마 호환 (V1 테이블에서도 별도 처리 없이 조회 시 idx를 id로 alias)
            return;
        }
    }

    /**
     * getFilterRules : 자동분류 규칙 목록 조회
     * V1 accounts.php POST action=list_filters 로직 이식
     */
    public function getFilterRules(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        $this->assertAccountOwnerOrManager($account, $actorUserPk, $actorRoleLevel);

        try {
            $this->ensureFilterRuleTable();
            $idCol = $this->columnExists('Tb_Mail_FilterRule', 'id') ? 'id' : 'idx';
            $stmt = $this->repo->prepareStatement(
                "SELECT {$idCol} AS id, account_idx, service_code, tenant_id, from_address, target_folder, is_active, is_deleted, created_by, updated_by, created_at, updated_at
                 FROM dbo.Tb_Mail_FilterRule
                 WHERE account_idx = :account_idx
                   AND service_code = :service_code
                   AND tenant_id = :tenant_id
                   AND ISNULL(is_active, 1) = 1
                   AND ISNULL(is_deleted, 0) = 0
                 ORDER BY created_at DESC, {$idCol} DESC"
            );
            $stmt->execute([
                'account_idx' => $accountIdx,
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) {
            $rows = [];
        }

        return [
            'account_idx' => $accountIdx,
            'rules'       => $rows,
            'total'       => count($rows),
        ];
    }

    /**
     * saveFilterRule : 자동분류 규칙 저장 (UPSERT)
     * V1 accounts.php POST action=save_filter 로직 이식
     */
    public function saveFilterRule(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $fromAddress,
        string $targetFolder,
        int $ruleId = 0,
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array {
        $serviceCode  = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId     = max(0, $tenantId);
        $fromAddress  = trim($fromAddress);
        $targetFolder = trim($targetFolder);

        if ($fromAddress === '' || $targetFolder === '') {
            throw new InvalidArgumentException('from_address and target_folder are required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        $this->assertAccountOwnerOrManager($account, $actorUserPk, $actorRoleLevel);

        $this->ensureFilterRuleTable();
        $idCol = $this->columnExists('Tb_Mail_FilterRule', 'id') ? 'id' : 'idx';
        $ruleId = max(0, $ruleId);

        if ($ruleId > 0) {
            $stmt = $this->repo->prepareStatement(
                "SELECT {$idCol} AS id
                 FROM dbo.Tb_Mail_FilterRule
                 WHERE {$idCol} = :rule_id
                   AND account_idx = :account_idx
                   AND service_code = :service_code
                   AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                'rule_id' => $ruleId,
                'account_idx' => $accountIdx,
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new \RuntimeException('규칙을 찾을 수 없거나 권한이 없습니다.');
            }

            $this->repo->prepareStatement(
                "UPDATE dbo.Tb_Mail_FilterRule
                 SET from_address = :from_address,
                     target_folder = :target_folder,
                     is_active = 1,
                     is_deleted = 0,
                     updated_by = :updated_by,
                     updated_at = GETDATE()
                 WHERE {$idCol} = :rule_id"
            )->execute([
                'from_address' => $fromAddress,
                'target_folder' => $targetFolder,
                'updated_by' => $actorUserPk > 0 ? $actorUserPk : null,
                'rule_id' => $ruleId,
            ]);
        } else {
            $stmt = $this->repo->prepareStatement(
                "SELECT TOP 1 {$idCol} AS id
                 FROM dbo.Tb_Mail_FilterRule
                 WHERE account_idx = :account_idx
                   AND service_code = :service_code
                   AND tenant_id = :tenant_id
                   AND from_address = :from_address
                 ORDER BY {$idCol} DESC"
            );
            $stmt->execute([
                'account_idx' => $accountIdx,
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'from_address' => $fromAddress,
            ]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                $ruleId = (int)$existing['id'];
                $this->repo->prepareStatement(
                    "UPDATE dbo.Tb_Mail_FilterRule
                     SET target_folder = :target_folder,
                         is_active = 1,
                         is_deleted = 0,
                         updated_by = :updated_by,
                         updated_at = GETDATE()
                     WHERE {$idCol} = :rule_id"
                )->execute([
                    'target_folder' => $targetFolder,
                    'updated_by' => $actorUserPk > 0 ? $actorUserPk : null,
                    'rule_id' => $ruleId,
                ]);
            } else {
                $this->repo->prepareStatement(
                    "INSERT INTO dbo.Tb_Mail_FilterRule
                     (account_idx, service_code, tenant_id, from_address, target_folder, is_active, is_deleted, created_by, updated_by, created_at, updated_at)
                     VALUES
                     (:account_idx, :service_code, :tenant_id, :from_address, :target_folder, 1, 0, :created_by, :updated_by, GETDATE(), GETDATE())"
                )->execute([
                    'account_idx' => $accountIdx,
                    'service_code' => $serviceCode,
                    'tenant_id' => $tenantId,
                    'from_address' => $fromAddress,
                    'target_folder' => $targetFolder,
                    'created_by' => $actorUserPk > 0 ? $actorUserPk : null,
                    'updated_by' => $actorUserPk > 0 ? $actorUserPk : null,
                ]);
                $idRow = $this->repo->fetchOne("SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx");
                $ruleId = (int)($idRow['idx'] ?? 0);
            }
        }

        return [
            'account_idx'   => $accountIdx,
            'rule_id'       => $ruleId,
            'from_address'  => $fromAddress,
            'target_folder' => $targetFolder,
            'saved'         => true,
        ];
    }

    /**
     * deleteFilterRule : 자동분류 규칙 삭제 (소프트: is_active=0)
     * V1 accounts.php POST action=delete_filter 로직 이식
     */
    public function deleteFilterRule(
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        int $ruleId,
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array
    {
        $serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $tenantId    = max(0, $tenantId);

        if ($ruleId <= 0) {
            throw new InvalidArgumentException('rule_id is required');
        }

        $account = $this->loadAccountScoped($serviceCode, $tenantId, $accountIdx);
        if ($account === null) {
            throw new \RuntimeException('MAIL_ACCOUNT_NOT_FOUND');
        }
        $this->assertAccountOwnerOrManager($account, $actorUserPk, $actorRoleLevel);

        try {
            $this->ensureFilterRuleTable();
            $idCol = $this->columnExists('Tb_Mail_FilterRule', 'id') ? 'id' : 'idx';
            // 권한 확인: 해당 계정 소유 규칙만 삭제
            $chkStmt = $this->repo->prepareStatement(
                "SELECT {$idCol} AS id
                 FROM dbo.Tb_Mail_FilterRule
                 WHERE {$idCol} = ?
                   AND account_idx = ?
                   AND service_code = ?
                   AND tenant_id = ?"
            );
            $chkStmt->execute([$ruleId, $accountIdx, $serviceCode, $tenantId]);
            if (!$chkStmt->fetch()) {
                throw new \RuntimeException('규칙을 찾을 수 없거나 권한이 없습니다.');
            }

            $this->repo->prepareStatement(
                "UPDATE dbo.Tb_Mail_FilterRule
                 SET is_active = 0,
                     is_deleted = 1,
                     updated_by = :updated_by,
                     updated_at = GETDATE()
                 WHERE {$idCol} = :rule_id"
            )->execute([
                'updated_by' => $actorUserPk > 0 ? $actorUserPk : null,
                'rule_id' => $ruleId,
            ]);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('규칙 삭제 실패: ' . $e->getMessage());
        }

        return [
            'account_idx' => $accountIdx,
            'rule_id'     => $ruleId,
            'deleted'     => true,
        ];
    }

    // -------------------------------------------------------------------------
    // 내부 헬퍼: 캐시 재 INSERT (moveMessage 이동 후 새 UID 캐시 보강)
    // -------------------------------------------------------------------------

    /**
     * insertCacheRow : 이동된 메일을 새 UID로 캐시 INSERT
     */
    private function insertCacheRow(array $meta, int $accountIdx, string $folder, int $newUid, array $srcRow): void
    {
        $tbl    = $meta['table'];
        $colAcc = $meta['account'];
        $colFld = $meta['folder'];
        $colUid = $meta['uid'] ?? 'uid';

        $setCols  = ['[' . $colAcc . ']', '[' . $colFld . ']', '[' . $colUid . ']'];
        $setBinds = [':account_idx', ':folder', ':new_uid'];
        $params   = [
            ':account_idx' => $accountIdx,
            ':folder'      => $folder,
            ':new_uid'     => $newUid,
        ];

        $copyFields = [
            'message_id', 'subject', 'from_address', 'to_address',
            'date', 'is_seen', 'is_flagged', 'has_attachment', 'body_preview',
            'in_reply_to', 'references', 'thread_id', 'size',
        ];
        foreach ($copyFields as $field) {
            if ($this->columnExists($tbl, $field) && array_key_exists($field, $srcRow)) {
                $setCols[]     = '[' . $field . ']';
                $setBinds[]    = ':' . $field;
                $params[':' . $field] = $srcRow[$field];
            }
        }

        if ($this->columnExists($tbl, 'created_at')) {
            $setCols[]  = '[created_at]';
            $setBinds[] = 'GETDATE()';
        }

        $sql  = 'INSERT INTO dbo.' . $tbl
              . ' (' . implode(', ', $setCols) . ')'
              . ' VALUES (' . implode(', ', $setBinds) . ')';

        try {
            $this->repo->prepareStatement($sql)->execute($params);
        } catch (\Throwable $_) {
            // 중복 무시
        }
    }

    private function guessFolderType(string $folderName, string $folderKey): string
    {
        $probe = strtolower(trim($folderName . ' ' . $folderKey));
        if ($probe === '' || str_contains($probe, 'inbox')) {
            return 'inbox';
        }
        if (str_contains($probe, 'sent') || str_contains($probe, '보낸')) {
            return 'sent';
        }
        if (str_contains($probe, 'draft') || str_contains($probe, '임시')) {
            return 'draft';
        }
        if (str_contains($probe, 'trash') || str_contains($probe, 'bin') || str_contains($probe, '휴지통')) {
            return 'trash';
        }
        if (str_contains($probe, 'spam') || str_contains($probe, 'junk') || str_contains($probe, '스팸')) {
            return 'spam';
        }
        if (str_contains($probe, 'archive') || str_contains($probe, '보관')) {
            return 'archive';
        }
        return 'custom';
    }

    private function normalizeDate(string $dateText): string
    {
        $dateText = trim($dateText);
        if ($dateText === '') {
            return '';
        }

        $ts = strtotime($dateText);
        if ($ts === false) {
            return $dateText;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeIntList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $num = (int)$value;
            if ($num > 0) {
                $list[] = $num;
            }
        }
        $list = array_values(array_unique($list));
        sort($list, SORT_NUMERIC);
        return $list;
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

        $stmt = $this->repo->prepareStatement('SELECT CASE WHEN OBJECT_ID(:obj, :type) IS NULL THEN 0 ELSE 1 END AS exists_flag');
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

        $stmt = $this->repo->prepareStatement('SELECT CASE WHEN COL_LENGTH(:obj, :col) IS NULL THEN 0 ELSE 1 END AS exists_flag');
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

