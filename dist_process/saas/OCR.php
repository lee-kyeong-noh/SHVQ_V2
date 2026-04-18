<?php
declare(strict_types=1);
/**
 * SHVQ V2 — OCR API (비전AI)
 *
 * todo=ocr_engines GET
 * todo=ocr_scan    POST
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/security/FmsInputValidator.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'ocr_engines'));
    $todo = strtolower($todoRaw);
    if ($todo === 'engines') {
        $todo = 'ocr_engines';
    }
    if ($todo === 'scan') {
        $todo = 'ocr_scan';
    }

    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);
    $actorUserPk = (int)($context['user_pk'] ?? 0);
    $actorLoginId = trim((string)($context['login_id'] ?? ''));

    $tableExistsCache = [];
    $tableExists = static function (PDO $pdo, string $table) use (&$tableExistsCache): bool {
        $key = strtolower($table);
        if (array_key_exists($key, $tableExistsCache)) {
            return (bool)$tableExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'\n        ");
        $stmt->execute([$table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $tableExistsCache[$key] = $exists;
        return $exists;
    };

    $columnExistsCache = [];
    $columnExists = static function (PDO $pdo, string $table, string $column) use (&$columnExistsCache): bool {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $columnExistsCache)) {
            return (bool)$columnExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_NAME = ? AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $columnExistsCache[$key] = $exists;
        return $exists;
    };

    $envFirst = static function (array $keys, ?string $default = null): ?string {
        foreach ($keys as $key) {
            $value = shvEnv((string)$key);
            if ($value !== null && trim($value) !== '') {
                return trim((string)$value);
            }
        }

        return $default;
    };

    $envIntFirst = static function (array $keys, int $default = 0) use ($envFirst): int {
        $value = $envFirst($keys, null);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int)$value;
    };

    $writeSvcAuditLog = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $todoName, array $payload): void {
        if (!$tableExistsFn($pdo, 'Tb_SvcAuditLog')) {
            return;
        }

        $columns = [];
        $values = [];
        $add = static function (string $column, mixed $value) use (&$columns, &$values, $pdo, $columnExistsFn): void {
            if ($columnExistsFn($pdo, 'Tb_SvcAuditLog', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        };

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $add('service_code', (string)($payload['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($payload['tenant_id'] ?? 0));
        $add('api_name', 'OCR.php');
        $add('todo', $todoName);
        $add('target_table', (string)($payload['target_table'] ?? 'OCR'));
        $add('target_idx', (int)($payload['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', (string)($payload['status'] ?? 'SUCCESS'));
        $add('message', (string)($payload['message'] ?? 'ocr api action'));
        $add('detail_json', $payloadJson);
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($columns === []) {
            return;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare('INSERT INTO Tb_SvcAuditLog (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
        $stmt->execute($values);
    };

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $todoName, int $targetIdx, array $payload = []): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/OCR.php',
                'todo' => $todoName,
                'target_table' => 'OCR',
                'target_idx' => $targetIdx,
                'actor_user_pk' => (int)($ctx['user_pk'] ?? 0),
                'actor_login_id' => (string)($ctx['login_id'] ?? ''),
                'requested_at' => date('c'),
                'payload' => $payload,
            ], 'PENDING', 0);
        } catch (Throwable) {
            return 0;
        }
    };

    $buildPrompt = static function (string $docType): string {
        if ($docType === 'expense_receipt') {
            $fields = '이 이미지는 한국의 영수증, 카드전표, 간이영수증, 또는 경비 관련 증빙서류입니다.';
            $fields .= ' 다음 필드를 추출하세요:';
            $fields .= ' store_name(가맹점명/상호), amount(결제금액, 숫자만), expense_date(거래일자, YYYY-MM-DD 형식),';
            $fields .= ' card_num(카드번호 뒤 4자리, 숫자만), payment_method(결제방식: card/cash/계좌이체 중 하나),';
            $fields .= ' account_subject(추정 계정과목: 식대/주유/복리후생/차량유지비/접대/교통비/소모품비/택배비/운반비/주차비/지급수수료 중 하나),';
            $fields .= ' contents(거래내용 요약, 20자 이내)';
            return $fields
                . "\n\n반드시 JSON 형식으로만 응답하세요. 추출할 수 없는 필드는 빈 문자열로 넣으세요."
                . "\n예시: {\"store_name\":\"스타벅스 강남점\",\"amount\":\"15000\",\"expense_date\":\"2026-03-31\",\"card_num\":\"1234\",\"payment_method\":\"card\",\"account_subject\":\"식대\",\"contents\":\"커피 구매\"}"
                . "\nJSON 외에 다른 텍스트는 절대 포함하지 마세요.";
        }

        if ($docType === 'business_reg') {
            $fields = '이 이미지는 한국 사업자등록증입니다.';
            $fields .= ' 다음 필드를 추출하세요:';
            $fields .= ' name(상호/법인명), card_number(사업자등록번호, 000-00-00000 형식),';
            $fields .= ' ceo_name(대표자), business_type(업태), business_class(종목/업종),';
            $fields .= ' address(사업장 소재지), tel(전화번호), fax(팩스번호)';
        } else {
            $fields = '이 이미지는 명함입니다.';
            $fields .= ' 다음 필드를 추출하세요:';
            $fields .= ' name(회사/업체명), ceo_name(이름/성명), tel(전화번호), hp(휴대폰),';
            $fields .= ' fax(팩스), email(이메일), address(주소)';
        }

        return $fields
            . "\n\n반드시 JSON 형식으로만 응답하세요. 추출할 수 없는 필드는 빈 문자열로 넣으세요."
            . "\n예시: {\"name\":\"\",\"card_number\":\"\",\"ceo_name\":\"\",\"business_type\":\"\",\"business_class\":\"\",\"address\":\"\",\"tel\":\"\",\"fax\":\"\",\"hp\":\"\",\"email\":\"\"}"
            . "\nJSON 외에 다른 텍스트는 절대 포함하지 마세요.";
    };

    $normalizeFields = static function (array $fields): array {
        $normalized = [];
        foreach ($fields as $key => $value) {
            $fieldKey = trim((string)$key);
            if ($fieldKey === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $normalized[$fieldKey] = trim((string)$value);
            } else {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $normalized[$fieldKey] = is_string($json) ? $json : '';
            }
        }
        ksort($normalized, SORT_NATURAL);
        return $normalized;
    };

    $parseOcrResult = static function (string $text) use ($normalizeFields): array {
        $candidate = trim($text);
        if ($candidate === '') {
            return ['ok' => false, 'error' => 'empty OCR response'];
        }

        if (preg_match('/```(?:json)?\s*(\{.+?\})\s*```/s', $candidate, $m) === 1) {
            $candidate = (string)$m[1];
        } elseif (preg_match('/(\{[\s\S]+\})/s', $candidate, $m) === 1) {
            $candidate = (string)$m[1];
        }

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'OCR 결과를 JSON으로 파싱하지 못했습니다', 'raw' => $text];
        }

        return [
            'ok' => true,
            'fields' => $normalizeFields($decoded),
            'raw_text' => $text,
        ];
    };

    $parseNaverText = static function (string $text, string $docType) use ($normalizeFields): array {
        if ($docType === 'expense_receipt') {
            $fields = [
                'store_name' => '',
                'amount' => '',
                'expense_date' => '',
                'card_num' => '',
                'payment_method' => '',
                'account_subject' => '',
                'contents' => '',
            ];

            if (preg_match('/(?:합\s*계|총\s*액|결제\s*금액|승인\s*금액|합\s*산)[^\d]*([0-9,]+)\s*원?/u', $text, $m) === 1) {
                $fields['amount'] = preg_replace('/[^0-9]/', '', (string)$m[1]) ?? '';
            } elseif (preg_match('/([0-9,]{4,})\s*원/u', $text, $m) === 1) {
                $fields['amount'] = preg_replace('/[^0-9]/', '', (string)$m[1]) ?? '';
            }

            if (preg_match('/(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/', $text, $m) === 1) {
                $fields['expense_date'] = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }

            if (preg_match('/\d{4}[\-\*]{1,2}\d{2,4}[\-\*]{1,2}\d{2,4}[\-\*]{1,2}(\d{4})/', $text, $m) === 1) {
                $fields['card_num'] = (string)$m[1];
            }

            if (preg_match('/(?:카드|CARD|신용|체크)/i', $text) === 1) {
                $fields['payment_method'] = 'card';
            } elseif (preg_match('/(?:현금|CASH)/i', $text) === 1) {
                $fields['payment_method'] = 'cash';
            }

            if (preg_match('/(?:상호|가맹점|매장명|업체명)\s*[:：]?\s*([\p{L}\p{N}\s()\-.,]+)/u', $text, $m) === 1) {
                $fields['store_name'] = trim((string)$m[1]);
            }

            return $normalizeFields($fields);
        }

        $fields = [
            'name' => '',
            'card_number' => '',
            'ceo_name' => '',
            'business_type' => '',
            'business_class' => '',
            'address' => '',
            'tel' => '',
            'fax' => '',
            'hp' => '',
            'email' => '',
        ];

        if (preg_match('/(\d{3})-?(\d{2})-?(\d{5})/', $text, $m) === 1) {
            $fields['card_number'] = $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        if (preg_match('/(?:전화|TEL|Tel)[^\d]*(\d{2,4}[-.\s]?\d{3,4}[-.\s]?\d{4})/i', $text, $m) === 1) {
            $fields['tel'] = preg_replace('/\s+/', '', (string)$m[1]) ?? '';
        }

        if (preg_match('/(?:팩스|FAX|Fax)[^\d]*(\d{2,4}[-.\s]?\d{3,4}[-.\s]?\d{4})/i', $text, $m) === 1) {
            $fields['fax'] = preg_replace('/\s+/', '', (string)$m[1]) ?? '';
        }

        if (preg_match('/(01[016789][-.\s]?\d{3,4}[-.\s]?\d{4})/', $text, $m) === 1) {
            $fields['hp'] = preg_replace('/\s+/', '', (string)$m[1]) ?? '';
        }

        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $text, $m) === 1) {
            $fields['email'] = (string)$m[1];
        }

        if (preg_match('/(?:상\s*호|법인명)[^\S\n]*[:\s]*([\p{L}\p{N}\s()]+)/u', $text, $m) === 1) {
            $fields['name'] = trim((string)$m[1]);
        }

        if (preg_match('/(?:대표자|성\s*명|대\s*표)[^\S\n]*[:\s]*([\p{L}]{2,12})/u', $text, $m) === 1) {
            $fields['ceo_name'] = trim((string)$m[1]);
        }

        if (preg_match('/(?:업\s*태)[^\S\n]*[:\s]*([\p{L}\p{N},\s]+)/u', $text, $m) === 1) {
            $fields['business_type'] = trim((string)$m[1]);
        }

        if (preg_match('/(?:종\s*목|업\s*종)[^\S\n]*[:\s]*([\p{L}\p{N},\s]+)/u', $text, $m) === 1) {
            $fields['business_class'] = trim((string)$m[1]);
        }

        if (preg_match('/(?:소재지|주\s*소)[^\S\n]*[:\s]*([\p{L}\p{N}\s\-.,()가-힣]+)/u', $text, $m) === 1) {
            $fields['address'] = trim((string)$m[1]);
        }

        return $normalizeFields($fields);
    };

    $httpJsonPost = static function (string $url, array $headers, array $body, int $timeoutSec = 60): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL extension is not available'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'failed to initialize curl'];
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'failed to encode request payload'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => max(10, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!is_string($response)) {
            return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'empty response', 'http_code' => $httpCode];
        }

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'body' => $response,
        ];
    };

    $cfg = [
        'default_engine' => strtolower((string)$envFirst(['OCR_DEFAULT_ENGINE', 'OCR_ENGINE'], 'openai')),
        'max_file_bytes' => max(1, $envIntFirst(['OCR_MAX_FILE_BYTES'], 10 * 1024 * 1024)),
        'claude_api_key' => (string)$envFirst(['OCR_CLAUDE_API_KEY', 'CLAUDE_API_KEY'], ''),
        'claude_model' => (string)$envFirst(['OCR_CLAUDE_MODEL', 'CLAUDE_MODEL'], 'claude-3-7-sonnet-latest'),
        'openai_api_key' => (string)$envFirst(['OCR_OPENAI_API_KEY', 'OPENAI_API_KEY'], ''),
        'openai_model' => (string)$envFirst(['OCR_OPENAI_MODEL', 'OPENAI_MODEL'], 'gpt-4o-mini'),
        'openai_endpoint' => (string)$envFirst(['OCR_OPENAI_ENDPOINT'], 'https://api.openai.com/v1/chat/completions'),
        'naver_ocr_url' => (string)$envFirst(['OCR_NAVER_URL', 'NAVER_OCR_URL'], ''),
        'naver_ocr_secret' => (string)$envFirst(['OCR_NAVER_SECRET', 'NAVER_OCR_SECRET'], ''),
    ];

    $writeTodos = ['ocr_scan'];
    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
            exit;
        }

        if ($roleLevel <= 0) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403);
            exit;
        }
    }

    if ($todo === 'ocr_engines') {
        $engines = [];
        if ($cfg['claude_api_key'] !== '') {
            $engines[] = ['id' => 'claude', 'name' => 'Claude Vision', 'icon' => 'fa-bolt'];
        }
        if ($cfg['openai_api_key'] !== '') {
            $engines[] = ['id' => 'openai', 'name' => 'OpenAI Vision', 'icon' => 'fa-commenting'];
        }
        if ($cfg['naver_ocr_url'] !== '' && $cfg['naver_ocr_secret'] !== '') {
            $engines[] = ['id' => 'naver', 'name' => 'Naver CLOVA', 'icon' => 'fa-leaf'];
        }

        $engineIds = array_map(static fn (array $e): string => (string)$e['id'], $engines);
        $defaultEngine = in_array($cfg['default_engine'], $engineIds, true)
            ? $cfg['default_engine']
            : ($engineIds[0] ?? '');

        ApiResponse::success([
            'engines' => $engines,
            'default' => $defaultEngine,
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ],
        ], 'OK', 'OCR 엔진 목록 조회 성공');
        exit;
    }

    if ($todo === 'ocr_scan') {
        $engine = strtolower(trim((string)($_POST['engine'] ?? $cfg['default_engine'])));
        $engine = FmsInputValidator::oneOf($engine, 'engine', ['claude', 'openai', 'naver'], false);

        $docType = trim((string)($_POST['doc_type'] ?? 'business_reg'));
        $docType = FmsInputValidator::oneOf($docType, 'doc_type', ['business_reg', 'namecard', 'expense_receipt'], false);

        $file = $_FILES['image'] ?? $_FILES['file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            ApiResponse::error('INVALID_PARAM', '이미지 파일(image)이 필요합니다', 422);
            exit;
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            ApiResponse::error('INVALID_PARAM', '파일 크기가 올바르지 않습니다', 422);
            exit;
        }
        if ($fileSize > $cfg['max_file_bytes']) {
            ApiResponse::error('INVALID_PARAM', '파일 크기는 ' . $cfg['max_file_bytes'] . ' bytes 이하여야 합니다', 422);
            exit;
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            ApiResponse::error('INVALID_PARAM', '업로드 파일을 읽을 수 없습니다', 422);
            exit;
        }

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi !== false) {
                $mimeType = (string)(finfo_file($fi, $tmpPath) ?: '');
                finfo_close($fi);
            }
        }
        if ($mimeType === '') {
            $mimeType = (string)($file['type'] ?? '');
        }
        $mimeType = strtolower(trim($mimeType));

        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        if (!in_array($mimeType, $allowedMime, true)) {
            ApiResponse::error('INVALID_PARAM', '지원하지 않는 파일 형식입니다', 422, ['mime' => $mimeType]);
            exit;
        }

        $binary = file_get_contents($tmpPath);
        if (!is_string($binary) || $binary === '') {
            ApiResponse::error('INVALID_PARAM', '파일 읽기에 실패했습니다', 422);
            exit;
        }

        $base64 = base64_encode($binary);
        $prompt = $buildPrompt($docType);

        $providerResult = ['ok' => false, 'error' => 'unknown'];

        if ($engine === 'claude') {
            if ($cfg['claude_api_key'] === '') {
                ApiResponse::error('OCR_PROVIDER_NOT_READY', 'Claude API key is missing', 503);
                exit;
            }

            $isPdf = $mimeType === 'application/pdf';
            $contentBlock = $isPdf
                ? [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $base64,
                    ],
                ]
                : [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => $base64,
                    ],
                ];

            $body = [
                'model' => $cfg['claude_model'],
                'max_tokens' => 1024,
                'temperature' => 0,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        $contentBlock,
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            ];

            $http = $httpJsonPost(
                'https://api.anthropic.com/v1/messages',
                [
                    'Content-Type: application/json',
                    'x-api-key: ' . $cfg['claude_api_key'],
                    'anthropic-version: 2023-06-01',
                ],
                $body,
                90
            );

            if (!($http['ok'] ?? false)) {
                $providerResult = ['ok' => false, 'error' => (string)($http['error'] ?? 'claude http error')];
            } else {
                $httpCode = (int)($http['http_code'] ?? 0);
                $decoded = json_decode((string)($http['body'] ?? ''), true);
                if ($httpCode !== 200 || !is_array($decoded)) {
                    $providerResult = [
                        'ok' => false,
                        'error' => 'Claude API 오류: HTTP ' . $httpCode,
                        'raw' => (string)($http['body'] ?? ''),
                    ];
                } else {
                    $chunks = [];
                    $content = $decoded['content'] ?? [];
                    if (is_array($content)) {
                        foreach ($content as $entry) {
                            if (is_array($entry) && ($entry['type'] ?? '') === 'text') {
                                $chunks[] = (string)($entry['text'] ?? '');
                            }
                        }
                    }
                    $text = trim(implode("\n", $chunks));
                    $parsed = $parseOcrResult($text);
                    if (!($parsed['ok'] ?? false)) {
                        $providerResult = [
                            'ok' => false,
                            'error' => (string)($parsed['error'] ?? 'OCR parse failed'),
                            'raw' => (string)($parsed['raw'] ?? $text),
                        ];
                    } else {
                        $providerResult = [
                            'ok' => true,
                            'engine' => 'Claude Vision',
                            'fields' => (array)($parsed['fields'] ?? []),
                            'raw_text' => (string)($parsed['raw_text'] ?? $text),
                        ];
                    }
                }
            }
        } elseif ($engine === 'openai') {
            if ($cfg['openai_api_key'] === '') {
                ApiResponse::error('OCR_PROVIDER_NOT_READY', 'OpenAI API key is missing', 503);
                exit;
            }
            if ($mimeType === 'application/pdf') {
                ApiResponse::error('INVALID_PARAM', 'OpenAI 엔진은 PDF 입력을 지원하지 않습니다', 422);
                exit;
            }

            $body = [
                'model' => $cfg['openai_model'],
                'temperature' => 0,
                'max_tokens' => 1024,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ]],
            ];

            $http = $httpJsonPost(
                $cfg['openai_endpoint'],
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg['openai_api_key'],
                ],
                $body,
                90
            );

            if (!($http['ok'] ?? false)) {
                $providerResult = ['ok' => false, 'error' => (string)($http['error'] ?? 'openai http error')];
            } else {
                $httpCode = (int)($http['http_code'] ?? 0);
                $decoded = json_decode((string)($http['body'] ?? ''), true);
                if ($httpCode !== 200 || !is_array($decoded)) {
                    $errMsg = '';
                    if (is_array($decoded)) {
                        $errMsg = trim((string)($decoded['error']['message'] ?? ''));
                    }
                    if ($errMsg === '') {
                        $errMsg = 'OpenAI API 오류: HTTP ' . $httpCode;
                    }
                    $providerResult = [
                        'ok' => false,
                        'error' => $errMsg,
                        'raw' => (string)($http['body'] ?? ''),
                    ];
                } else {
                    $text = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
                    $parsed = $parseOcrResult($text);
                    if (!($parsed['ok'] ?? false)) {
                        $providerResult = [
                            'ok' => false,
                            'error' => (string)($parsed['error'] ?? 'OCR parse failed'),
                            'raw' => (string)($parsed['raw'] ?? $text),
                        ];
                    } else {
                        $providerResult = [
                            'ok' => true,
                            'engine' => 'OpenAI Vision',
                            'fields' => (array)($parsed['fields'] ?? []),
                            'raw_text' => (string)($parsed['raw_text'] ?? $text),
                        ];
                    }
                }
            }
        } else {
            if ($cfg['naver_ocr_url'] === '' || $cfg['naver_ocr_secret'] === '') {
                ApiResponse::error('OCR_PROVIDER_NOT_READY', 'Naver OCR credential is missing', 503);
                exit;
            }
            if ($mimeType === 'application/pdf') {
                ApiResponse::error('INVALID_PARAM', 'Naver 엔진은 PDF 입력을 지원하지 않습니다', 422);
                exit;
            }

            $ext = 'jpg';
            if (str_contains($mimeType, 'png')) {
                $ext = 'png';
            } elseif (str_contains($mimeType, 'webp')) {
                $ext = 'webp';
            } elseif (str_contains($mimeType, 'gif')) {
                $ext = 'gif';
            }

            $body = [
                'version' => 'V2',
                'requestId' => 'ocr-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
                'timestamp' => (int)round(microtime(true) * 1000),
                'images' => [[
                    'format' => $ext,
                    'name' => 'scan',
                    'data' => $base64,
                ]],
            ];

            $http = $httpJsonPost(
                $cfg['naver_ocr_url'],
                [
                    'Content-Type: application/json',
                    'X-OCR-SECRET: ' . $cfg['naver_ocr_secret'],
                ],
                $body,
                60
            );

            if (!($http['ok'] ?? false)) {
                $providerResult = ['ok' => false, 'error' => (string)($http['error'] ?? 'naver http error')];
            } else {
                $httpCode = (int)($http['http_code'] ?? 0);
                $decoded = json_decode((string)($http['body'] ?? ''), true);
                if ($httpCode !== 200 || !is_array($decoded)) {
                    $providerResult = [
                        'ok' => false,
                        'error' => 'Naver OCR 오류: HTTP ' . $httpCode,
                        'raw' => (string)($http['body'] ?? ''),
                    ];
                } else {
                    $texts = [];
                    $fields = $decoded['images'][0]['fields'] ?? [];
                    if (is_array($fields)) {
                        foreach ($fields as $field) {
                            if (is_array($field)) {
                                $infer = trim((string)($field['inferText'] ?? ''));
                                if ($infer !== '') {
                                    $texts[] = $infer;
                                }
                            }
                        }
                    }

                    $joined = trim(implode(' ', $texts));
                    if ($joined === '') {
                        $providerResult = ['ok' => false, 'error' => 'Naver OCR 텍스트 인식 결과가 비어 있습니다'];
                    } else {
                        $providerResult = [
                            'ok' => true,
                            'engine' => 'Naver CLOVA',
                            'fields' => $parseNaverText($joined, $docType),
                            'raw_text' => $joined,
                        ];
                    }
                }
            }
        }

        if (!($providerResult['ok'] ?? false)) {
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'ocr_scan', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'OCR',
                'target_idx' => 0,
                'actor_user_pk' => $actorUserPk,
                'actor_login_id' => $actorLoginId,
                'status' => 'FAILED',
                'message' => (string)($providerResult['error'] ?? 'OCR failed'),
                'engine' => $engine,
                'doc_type' => $docType,
            ]);

            ApiResponse::error(
                'OCR_PROVIDER_ERROR',
                (string)($providerResult['error'] ?? 'OCR failed'),
                502,
                [
                    'engine' => $engine,
                    'doc_type' => $docType,
                ]
            );
            exit;
        }

        $fields = is_array($providerResult['fields'] ?? null) ? $providerResult['fields'] : [];
        $shadowId = $enqueueShadow($db, $security, $context, 'ocr_scan', 0, [
            'engine' => $engine,
            'doc_type' => $docType,
            'fields' => $fields,
        ]);

        $writeSvcAuditLog($db, $tableExists, $columnExists, 'ocr_scan', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'OCR',
            'target_idx' => 0,
            'actor_user_pk' => $actorUserPk,
            'actor_login_id' => $actorLoginId,
            'status' => 'SUCCESS',
            'message' => 'ocr scan success',
            'engine' => $engine,
            'doc_type' => $docType,
            'field_keys' => array_values(array_keys($fields)),
        ]);

        ApiResponse::success([
            'engine' => (string)($providerResult['engine'] ?? $engine),
            'fields' => $fields,
            'raw_text' => (string)($providerResult['raw_text'] ?? ''),
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ],
            'shadow_queue_idx' => $shadowId,
        ], 'OK', 'OCR 분석 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo 입니다', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
