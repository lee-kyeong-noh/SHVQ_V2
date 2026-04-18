<?php
declare(strict_types=1);

namespace SHVQ\Integration\Adapter;

use RuntimeException;
use SHVQ\Integration\DTO\AdapterContext;

require_once __DIR__ . '/../DTO/AdapterContext.php';

final class MailSyncBatch
{
    private string $cursor;
    private bool $hasMore;
    /** @var array<int,array<string,mixed>> */
    private array $items;
    private string $syncedAt;
    /** @var array<int,string> */
    private array $warnings;

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string> $warnings
     */
    public function __construct(
        string $cursor,
        bool $hasMore,
        array $items,
        string $syncedAt,
        array $warnings = []
    ) {
        $this->cursor = $cursor;
        $this->hasMore = $hasMore;
        $this->items = $items;
        $this->syncedAt = trim($syncedAt) !== '' ? trim($syncedAt) : date('c');
        $this->warnings = $warnings;
    }

    public function cursor(): string
    {
        return $this->cursor;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** @return array<int,array<string,mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function syncedAt(): string
    {
        return $this->syncedAt;
    }

    /** @return array<int,string> */
    public function warnings(): array
    {
        return $this->warnings;
    }
}

final class MailImapAdapter
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /** @return array<string,mixed> */
    public function validateAccount(AdapterContext $context): array
    {
        $missing = $this->missingCredentialFields($context);
        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'missing credentials: ' . implode(', ', $missing),
            ];
        }

        if (!function_exists('imap_open')) {
            return [
                'ok' => true,
                'message' => 'imap extension is not available; credential shape validated',
            ];
        }

        $mailbox = $this->buildMailbox($context, 'INBOX');
        $stream = @imap_open(
            $mailbox,
            $context->credential('login_id'),
            $context->credential('password'),
            0,
            1
        );

        if ($stream === false) {
            return [
                'ok' => false,
                'message' => $this->lastImapError('imap_open failed'),
            ];
        }

        @imap_close($stream);
        return [
            'ok' => true,
            'message' => 'ok',
        ];
    }

    /** @return array<string,mixed> */
    public function health(AdapterContext $context): array
    {
        $start = microtime(true);
        $validation = $this->validateAccount($context);

        $latencyMs = (int)round((microtime(true) - $start) * 1000);
        if (!($validation['ok'] ?? false)) {
            return [
                'status' => 'DOWN',
                'message' => (string)($validation['message'] ?? 'health check failed'),
                'latency_ms' => $latencyMs,
            ];
        }

        return [
            'status' => 'UP',
            'message' => (string)($validation['message'] ?? 'ok'),
            'latency_ms' => $latencyMs,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function syncFolders(AdapterContext $context): array
    {
        if (!function_exists('imap_open') || !function_exists('imap_getmailboxes')) {
            return $this->defaultFolders();
        }

        $baseMailbox = $this->baseMailbox($context);
        $stream = @imap_open(
            $baseMailbox . 'INBOX',
            $context->credential('login_id'),
            $context->credential('password'),
            0,
            1
        );
        if ($stream === false) {
            return $this->defaultFolders();
        }

        $mailboxes = @imap_getmailboxes($stream, $baseMailbox, '*');
        $items = [];

        if (is_array($mailboxes)) {
            foreach ($mailboxes as $mailbox) {
                if (!is_object($mailbox)) {
                    continue;
                }

                $fullName = (string)($mailbox->name ?? '');
                if ($fullName === '') {
                    continue;
                }
                $folderKey = $this->extractFolderKey($baseMailbox, $fullName);
                if ($folderKey === '') {
                    continue;
                }

                $display = $this->decodeUtf7Imap($folderKey);
                $items[] = [
                    'folder_key' => $folderKey,
                    'display_name' => $display !== '' ? $display : $folderKey,
                    'delimiter' => (string)($mailbox->delimiter ?? '/'),
                    'attributes' => (int)($mailbox->attributes ?? 0),
                ];
            }
        }

        @imap_close($stream);

        if ($items === []) {
            return $this->defaultFolders();
        }

        usort($items, static function (array $a, array $b): int {
            $aKey = strtoupper((string)($a['folder_key'] ?? ''));
            $bKey = strtoupper((string)($b['folder_key'] ?? ''));
            if ($aKey === 'INBOX' && $bKey !== 'INBOX') {
                return -1;
            }
            if ($bKey === 'INBOX' && $aKey !== 'INBOX') {
                return 1;
            }
            return strcmp($aKey, $bKey);
        });

        return $items;
    }

    public function syncMessages(
        AdapterContext $context,
        string $folderKey = 'INBOX',
        ?string $cursor = null,
        int $limit = 500
    ): MailSyncBatch {
        $limit = max(1, min($limit, 1000));
        $folderKey = trim($folderKey) !== '' ? trim($folderKey) : 'INBOX';

        if (!function_exists('imap_open')) {
            return new MailSyncBatch(
                (string)($cursor ?? ''),
                false,
                [],
                date('c'),
                ['imap extension is not available']
            );
        }

        $mailbox = $this->buildMailbox($context, $folderKey);
        $stream = @imap_open(
            $mailbox,
            $context->credential('login_id'),
            $context->credential('password'),
            0,
            1
        );

        if ($stream === false) {
            return new MailSyncBatch(
                (string)($cursor ?? ''),
                false,
                [],
                date('c'),
                [$this->lastImapError('imap_open failed')]
            );
        }

        $cursorInt = is_numeric((string)$cursor) ? max(0, (int)$cursor) : 0;
        $uidFrom = $cursorInt > 0 ? ($cursorInt + 1) : 1;

        $uids = @imap_search($stream, 'UID ' . $uidFrom . ':*', SE_UID);
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

            $overviewRows = @imap_fetch_overview($stream, (string)$uid, FT_UID);
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

        @imap_close($stream);

        $nextCursor = $maxUid > 0 ? (string)$maxUid : (string)($cursor ?? '');
        return new MailSyncBatch($nextCursor, $hasMore, $items, date('c'));
    }

    /** @return array<int,string> */
    private function missingCredentialFields(AdapterContext $context): array
    {
        $missing = [];
        if (trim($context->credential('host')) === '') {
            $missing[] = 'host';
        }
        if (trim($context->credential('login_id')) === '') {
            $missing[] = 'login_id';
        }
        if (trim($context->credential('password')) === '') {
            $missing[] = 'password';
        }

        return $missing;
    }

    private function buildMailbox(AdapterContext $context, string $folder = 'INBOX'): string
    {
        return $this->baseMailbox($context) . (trim($folder) !== '' ? trim($folder) : 'INBOX');
    }

    private function baseMailbox(AdapterContext $context): string
    {
        $host = trim($context->credential('host'));
        if ($host === '') {
            throw new RuntimeException('mail host is required');
        }

        $port = (int)$context->credential('port', '0');
        if ($port <= 0) {
            $port = (int)($this->config['provider']['mail']['default_port'] ?? 993);
        }

        $rawSsl = $context->credential('ssl', '');
        $useSsl = $rawSsl !== ''
            ? $this->toBool($rawSsl)
            : (bool)($this->config['provider']['mail']['default_ssl'] ?? true);

        $flags = '/imap' . ($useSsl ? '/ssl/validate-cert' : '/tls/validate-cert');
        return '{' . $host . ':' . $port . $flags . '}';
    }

    private function toBool(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /** @return array<int,array<string,mixed>> */
    private function defaultFolders(): array
    {
        return [
            ['folder_key' => 'INBOX', 'display_name' => 'INBOX', 'delimiter' => '/', 'attributes' => 0],
            ['folder_key' => 'Sent', 'display_name' => 'Sent', 'delimiter' => '/', 'attributes' => 0],
            ['folder_key' => 'Drafts', 'display_name' => 'Drafts', 'delimiter' => '/', 'attributes' => 0],
            ['folder_key' => 'Trash', 'display_name' => 'Trash', 'delimiter' => '/', 'attributes' => 0],
        ];
    }

    private function extractFolderKey(string $baseMailbox, string $fullName): string
    {
        if (str_starts_with($fullName, $baseMailbox)) {
            return trim(substr($fullName, strlen($baseMailbox)));
        }
        $pos = strrpos($fullName, '}');
        if ($pos !== false) {
            return trim(substr($fullName, $pos + 1));
        }

        return trim($fullName);
    }

    private function decodeUtf7Imap(string $folder): string
    {
        if ($folder === '') {
            return '';
        }
        if (!function_exists('mb_convert_encoding')) {
            return $folder;
        }
        $decoded = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
        if (!is_string($decoded) || trim($decoded) === '') {
            return $folder;
        }
        return $decoded;
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
}
