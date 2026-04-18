<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

header('Content-Type: application/json; charset=utf-8');

$todo = strtolower(trim((string)($_REQUEST['todo'] ?? '')));

$auth = new AuthService();
$authContext = $auth->currentContext();
if ($authContext === []) {
    ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다.', 401);
    exit;
}

$GLOBALS['ONVIF_AUTH_CONTEXT'] = $authContext;

const ONVIF_DEFAULT_TIMEOUT = 4;
const ONVIF_DISCOVERY_TIMEOUT = 3;
const ONVIF_DISCOVERY_ADDR = '239.255.255.250';
const ONVIF_DISCOVERY_PORT = 3702;
const ONVIF_API_VER = '20260407n';
const ONVIF_GO2RTC_API = 'http://127.0.0.1:1984/api';
const ONVIF_GO2RTC_RTSP = 'rtsp://127.0.0.1:8554';
const ONVIF_FFMPEG_EXE = 'D:\\SHV_ERP\\ffmpeg\\ffmpeg.exe';
const ONVIF_RECORD_ROOT = 'E:\\shvq\\videos\\onvif';
const ONVIF_RUNTIME_ROOT = 'D:\\SHV_ERP\\go2rtc\\runtime';

function jsonOut(array $payload, $status = 200)
{
    $ok = (bool)($payload['ok'] ?? $payload['success'] ?? false);
    if ($ok) {
        $code = (string)($payload['code'] ?? 'OK');
        $message = (string)($payload['message'] ?? $payload['msg'] ?? 'OK');
        $data = $payload;
        unset($data['ok'], $data['success'], $data['code'], $data['message'], $data['msg'], $data['_ver']);
        ApiResponse::success($data, $code, $message, (int)$status);
    } else {
        $code = (string)($payload['error'] ?? $payload['code'] ?? 'ONVIF_ERROR');
        if ($code === '') {
            $code = 'ONVIF_ERROR';
        }
        $message = (string)($payload['message'] ?? $payload['msg'] ?? '요청 처리 중 오류가 발생했습니다.');
        $detail = $payload;
        unset($detail['ok'], $detail['success'], $detail['error'], $detail['code'], $detail['message'], $detail['msg'], $detail['_ver']);
        ApiResponse::error($code, $message, (int)$status, $detail);
    }
    exit;
}

function reqStr($key, $default = '')
{
    return trim((string)($_REQUEST[$key] ?? $default));
}

function reqInt($key, $default)
{
    return (int)($_REQUEST[$key] ?? $default);
}

function clampInt($value, $min, $max, $default)
{
    $v = (int)$value;
    if ($v < (int)$min || $v > (int)$max) {
        return (int)$default;
    }
    return $v;
}

function onvifCurrentUserContext()
{
    $ctx = $GLOBALS['ONVIF_AUTH_CONTEXT'] ?? [];
    return [
        'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
        'user_pk' => (int)($ctx['user_pk'] ?? 0),
        'login_id' => trim((string)($ctx['login_id'] ?? '')),
        'role_level' => (int)($ctx['role_level'] ?? 0),
    ];
}

function onvifHasOwnerContext(array $ctx)
{
    return (int)($ctx['tenant_id'] ?? 0) > 0;
}

function onvifIsAdmin(array $ctx): bool
{
    $roleLevel = (int)($ctx['role_level'] ?? 0);
    return $roleLevel >= 1 && $roleLevel <= 2;
}

function onvifRequireAdmin(array $ctx): void
{
    if (!onvifIsAdmin($ctx)) {
        ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다.', 403);
        exit;
    }
}

function onvifOwnerWhereClause(array $ctx, array &$params)
{
    $params[] = (int)($ctx['tenant_id'] ?? 0);
    return 'tenant_id = ?';
}

function onvifCredentialCrypto(): CredentialCryptoService
{
    static $crypto = null;
    if (!$crypto instanceof CredentialCryptoService) {
        $crypto = CredentialCryptoService::forOnvif();
    }
    return $crypto;
}

function onvifNormalizeLegacyOwnerRows(PDO $db, array $ctx, $cameraId = '')
{
    return ['migrated' => 0, 'soft_deleted' => 0];
}

function onvifCameraOwnerPriority(array $row, array $ctx)
{
    $updatedAt = trim((string)($row['updated_at'] ?? ''));
    $createdAt = trim((string)($row['created_at'] ?? ''));
    return $updatedAt . '|' . $createdAt;
}

function onvifCompareCameraRows(array $a, array $b, array $ctx)
{
    $au = trim((string)($a['updated_at'] ?? ''));
    $bu = trim((string)($b['updated_at'] ?? ''));
    if ($au !== $bu) return strcmp($bu, $au); // DESC

    $ac = trim((string)($a['created_at'] ?? ''));
    $bc = trim((string)($b['created_at'] ?? ''));
    if ($ac !== $bc) return strcmp($bc, $ac); // DESC

    $ai = (int)($a['idx'] ?? 0);
    $bi = (int)($b['idx'] ?? 0);
    if ($ai === $bi) return 0;
    return ($ai > $bi) ? -1 : 1; // DESC
}

function onvifIsPreferredCameraRow(array $candidate, array $current, array $ctx)
{
    return onvifCompareCameraRows($candidate, $current, $ctx) < 0;
}

function onvifEnsureCameraTable(PDO $db)
{
    $db->exec("
        IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_OnvifCameras')
        CREATE TABLE Tb_OnvifCameras (
            idx             INT IDENTITY(1,1) PRIMARY KEY,
            tenant_id       INT NOT NULL DEFAULT 0,
            created_by      INT NOT NULL DEFAULT 0,
            camera_id       NVARCHAR(80) NOT NULL,
            name            NVARCHAR(200) NOT NULL,
            channel         NVARCHAR(50) DEFAULT '',
            ip              NVARCHAR(100) NOT NULL,
            port            INT NOT NULL DEFAULT 80,
            login_user      NVARCHAR(100) DEFAULT '',
            login_pass      NVARCHAR(1000) DEFAULT '',
            login_pass_encrypted TINYINT NOT NULL DEFAULT 0,
            memo            NVARCHAR(500) DEFAULT '',
            conn_method     NVARCHAR(20) DEFAULT 'onvif',
            manufacturer    NVARCHAR(50) DEFAULT '',
            rtsp_port       INT NOT NULL DEFAULT 554,
            default_stream  NVARCHAR(10) DEFAULT 'sub',
            is_ptz          TINYINT NOT NULL DEFAULT 0,
            rtsp_main       NVARCHAR(1000) DEFAULT '',
            rtsp_sub        NVARCHAR(1000) DEFAULT '',
            status          NVARCHAR(20) DEFAULT 'unknown',
            snapshot        NVARCHAR(MAX) DEFAULT '',
            created_at      DATETIME NOT NULL DEFAULT GETDATE(),
            updated_at      DATETIME NOT NULL DEFAULT GETDATE(),
            is_deleted      TINYINT NOT NULL DEFAULT 0
        )
    ");
    $db->exec("IF COL_LENGTH('Tb_OnvifCameras', 'login_pass') IS NULL ALTER TABLE Tb_OnvifCameras ADD login_pass NVARCHAR(1000) NOT NULL CONSTRAINT DF_OnvifCameras_LoginPass DEFAULT ''");
    $db->exec("IF COL_LENGTH('Tb_OnvifCameras', 'login_pass_encrypted') IS NULL ALTER TABLE Tb_OnvifCameras ADD login_pass_encrypted TINYINT NOT NULL CONSTRAINT DF_OnvifCameras_LoginPassEncrypted DEFAULT 0");
    $db->exec("
        IF COL_LENGTH('Tb_OnvifCameras', 'login_pass') IS NOT NULL
        BEGIN
            UPDATE Tb_OnvifCameras SET login_pass='' WHERE login_pass IS NULL;
            IF EXISTS (
                SELECT 1
                FROM sys.columns
                WHERE object_id = OBJECT_ID('dbo.Tb_OnvifCameras')
                  AND name = 'login_pass'
                  AND max_length < 2000
            )
            BEGIN
                ALTER TABLE Tb_OnvifCameras ALTER COLUMN login_pass NVARCHAR(1000) NOT NULL;
            END
        END
    ");
    $db->exec("IF COL_LENGTH('Tb_OnvifCameras', 'tenant_id') IS NULL ALTER TABLE Tb_OnvifCameras ADD tenant_id INT NOT NULL CONSTRAINT DF_OnvifCameras_TenantId DEFAULT 0");
    $db->exec("IF COL_LENGTH('Tb_OnvifCameras', 'created_by') IS NULL ALTER TABLE Tb_OnvifCameras ADD created_by INT NOT NULL CONSTRAINT DF_OnvifCameras_CreatedBy DEFAULT 0");

    try {
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name='UQ_OnvifCameras_TenantCamera')
                   CREATE UNIQUE INDEX UQ_OnvifCameras_TenantCamera ON Tb_OnvifCameras(tenant_id, camera_id)");
    } catch (Throwable) {
        // Unique index creation can fail when legacy duplicate data exists.
    }
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name='IX_OnvifCameras_Tenant')
               CREATE INDEX IX_OnvifCameras_Tenant ON Tb_OnvifCameras(tenant_id, is_deleted, created_at)");
}

function onvifReadJsonBody()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        $cache = [];
        return $cache;
    }
    $tmp = json_decode($raw, true);
    $cache = is_array($tmp) ? $tmp : [];
    return $cache;
}

function onvifPickField(array $src, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $src)) return $src[$k];
    }
    return $default;
}

function onvifGenerateCameraId()
{
    return 'cam_' . substr(str_replace('.', '', uniqid('', true)), -18);
}

function onvifNormalizeCameraPayload(array $src)
{
    $id = trim((string)onvifPickField($src, ['id', 'camera_id'], ''));
    if ($id === '') $id = onvifGenerateCameraId();
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
    if ($id === '') $id = onvifGenerateCameraId();

    $connMethod = strtolower(trim((string)onvifPickField($src, ['connMethod', 'conn_method'], 'onvif')));
    if (!in_array($connMethod, ['onvif', 'manufacturer', 'rtsp'], true)) $connMethod = 'onvif';

    $defaultStream = strtolower(trim((string)onvifPickField($src, ['defaultStream', 'default_stream'], 'sub')));
    if ($defaultStream !== 'main' && $defaultStream !== 'sub') $defaultStream = 'sub';

    $isPtzRaw = onvifPickField($src, ['isPtz', 'is_ptz'], '0');
    $isPtz = ((string)$isPtzRaw === '1' || (int)$isPtzRaw === 1) ? 1 : 0;

    return [
        'camera_id' => substr($id, 0, 80),
        'name' => trim((string)onvifPickField($src, ['name'], '')),
        'channel' => trim((string)onvifPickField($src, ['channel'], '')),
        'ip' => sanitizeHost((string)onvifPickField($src, ['ip'], '')),
        'port' => normalizePort((int)onvifPickField($src, ['port'], 80), 80),
        'login_user' => trim((string)onvifPickField($src, ['user', 'login_user'], '')),
        'login_pass' => (string)onvifPickField($src, ['pass', 'login_pass'], ''),
        'memo' => trim((string)onvifPickField($src, ['memo'], '')),
        'conn_method' => $connMethod,
        'manufacturer' => trim((string)onvifPickField($src, ['manufacturer'], '')),
        'rtsp_port' => normalizePort((int)onvifPickField($src, ['rtspPort', 'rtsp_port'], 554), 554),
        'default_stream' => $defaultStream,
        'is_ptz' => $isPtz,
        'rtsp_main' => trim((string)onvifPickField($src, ['rtspMain', 'rtsp_main'], '')),
        'rtsp_sub' => trim((string)onvifPickField($src, ['rtspSub', 'rtsp_sub'], '')),
        'status' => trim((string)onvifPickField($src, ['status'], 'unknown')),
        'snapshot' => trim((string)onvifPickField($src, ['snapshot'], '')),
        'created_at' => trim((string)onvifPickField($src, ['created', 'created_at'], '')),
    ];
}

function onvifDbRowToCamera(array $r)
{
    $hasCredentials = trim((string)($r['login_user'] ?? '')) !== '' || trim((string)($r['login_pass'] ?? '')) !== '';

    return [
        'id' => (string)($r['camera_id'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'channel' => (string)($r['channel'] ?? ''),
        'ip' => (string)($r['ip'] ?? ''),
        'port' => (string)($r['port'] ?? '80'),
        'user' => (string)($r['login_user'] ?? ''),
        'has_credentials' => $hasCredentials,
        'memo' => (string)($r['memo'] ?? ''),
        'connMethod' => (string)($r['conn_method'] ?? 'onvif'),
        'manufacturer' => (string)($r['manufacturer'] ?? ''),
        'rtspPort' => (string)($r['rtsp_port'] ?? '554'),
        'defaultStream' => (string)($r['default_stream'] ?? 'sub'),
        'isPtz' => ((int)($r['is_ptz'] ?? 0) === 1) ? '1' : '0',
        'rtspMain' => (string)($r['rtsp_main'] ?? ''),
        'rtspSub' => (string)($r['rtsp_sub'] ?? ''),
        'status' => (string)($r['status'] ?? 'unknown'),
        'snapshot' => (string)($r['snapshot'] ?? ''),
        'created' => (string)($r['created_at'] ?? ''),
    ];
}

function onvifUpsertCameraRow(PDO $db, array $ctx, array $cam): array
{
    $createdAt = trim((string)($cam['created_at'] ?? ''));
    if (!preg_match('/^\d{4}\-\d{2}\-\d{2}([ T]\d{2}:\d{2}:\d{2})?$/', $createdAt)) {
        $createdAt = '';
    }

    $tenantId = (int)($ctx['tenant_id'] ?? 0);
    $cameraId = (string)($cam['camera_id'] ?? '');
    $requestedPass = (string)($cam['login_pass'] ?? '');

    $existingStmt = $db->prepare("\n        SELECT TOP 1 login_pass, ISNULL(login_pass_encrypted, 0) AS login_pass_encrypted\n        FROM Tb_OnvifCameras\n        WHERE tenant_id = ? AND camera_id = ?\n        ORDER BY idx DESC\n    ");
    $existingStmt->execute([$tenantId, $cameraId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $crypto = onvifCredentialCrypto();
    $storedPass = '';
    $storedPassEncrypted = 0;

    if ($requestedPass !== '') {
        $storedPass = $crypto->encrypt($requestedPass);
        $storedPassEncrypted = ($storedPass !== '') ? 1 : 0;
    } elseif (is_array($existing)) {
        $legacyPass = (string)($existing['login_pass'] ?? '');
        $legacyEncrypted = ((int)($existing['login_pass_encrypted'] ?? 0) === 1) || $crypto->isEncrypted($legacyPass);

        if ($legacyPass === '') {
            $storedPass = '';
            $storedPassEncrypted = 0;
        } elseif ($legacyEncrypted) {
            $plain = $crypto->decrypt($legacyPass);
            if ($plain === '') {
                // Keep existing ciphertext when decrypt is not possible (avoid data loss).
                $storedPass = $legacyPass;
                $storedPassEncrypted = 1;
            } else {
                $storedPass = $crypto->encrypt($plain);
                $storedPassEncrypted = 1;
            }
        } else {
            // Lazy migration: legacy plaintext is encrypted on next save.
            $storedPass = $crypto->encrypt($legacyPass);
            $storedPassEncrypted = ($storedPass !== '') ? 1 : 0;
            if ($storedPassEncrypted === 0) {
                $storedPass = $legacyPass;
            }
        }
    }

    $sql = "MERGE Tb_OnvifCameras AS T
            USING (SELECT ? AS tenant_id, ? AS camera_id) AS S
            ON T.tenant_id=S.tenant_id AND T.camera_id=S.camera_id
            WHEN MATCHED THEN UPDATE SET
                name=?, channel=?, ip=?, port=?, login_user=?, login_pass=?, login_pass_encrypted=?, memo=?, conn_method=?,
                manufacturer=?, rtsp_port=?, default_stream=?, is_ptz=?, rtsp_main=?, rtsp_sub=?,
                status=?, snapshot=?, is_deleted=0, updated_at=GETDATE()
            WHEN NOT MATCHED THEN INSERT
                (tenant_id,created_by,camera_id,name,channel,ip,port,login_user,login_pass,login_pass_encrypted,memo,conn_method,
                 manufacturer,rtsp_port,default_stream,is_ptz,rtsp_main,rtsp_sub,status,snapshot,created_at,updated_at,is_deleted)
            VALUES
                (S.tenant_id,?,S.camera_id,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                 CASE WHEN ?='' THEN GETDATE() ELSE CONVERT(DATETIME, ?, 120) END, GETDATE(),0);";

    $camVals = [
        (string)$cam['name'], (string)$cam['channel'], (string)$cam['ip'], (int)$cam['port'],
        (string)$cam['login_user'], $storedPass, $storedPassEncrypted, (string)$cam['memo'], (string)$cam['conn_method'],
        (string)$cam['manufacturer'], (int)$cam['rtsp_port'], (string)$cam['default_stream'], (int)$cam['is_ptz'],
        (string)$cam['rtsp_main'], (string)$cam['rtsp_sub'], (string)$cam['status'], (string)$cam['snapshot'],
    ];
    $params = array_merge(
        [$tenantId, $cameraId],
        $camVals,
        [(int)($ctx['user_pk'] ?? 0)],
        $camVals,
        [$createdAt, $createdAt]
    );
    $db->prepare($sql)->execute($params);

    return [
        'login_pass' => $storedPass,
        'login_pass_encrypted' => $storedPassEncrypted,
    ];
}

function onvifBoolFlag($value)
{
    $s = strtolower(trim((string)$value));
    return ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'y' || $s === 'on');
}

function onvifSafeStatus($status)
{
    $s = strtolower(trim((string)$status));
    if (!in_array($s, ['online', 'offline', 'unknown'], true)) {
        $s = 'unknown';
    }
    return $s;
}

function onvifValidateCameraPayload(array $cam, &$msg = '')
{
    $strlen = function ($v) {
        if (function_exists('mb_strlen')) return mb_strlen((string)$v, 'UTF-8');
        return strlen((string)$v);
    };

    if (trim((string)($cam['camera_id'] ?? '')) === '') {
        $msg = 'camera_id가 비어 있습니다.';
        return false;
    }
    if (trim((string)($cam['name'] ?? '')) === '') {
        $msg = '카메라명이 비어 있습니다.';
        return false;
    }
    if (sanitizeHost((string)($cam['ip'] ?? '')) === '') {
        $msg = '유효한 IP/호스트가 아닙니다.';
        return false;
    }
    if ($strlen((string)($cam['name'] ?? '')) > 200) {
        $msg = '카메라명은 200자를 초과할 수 없습니다.';
        return false;
    }
    if ($strlen((string)($cam['channel'] ?? '')) > 50) {
        $msg = '채널명은 50자를 초과할 수 없습니다.';
        return false;
    }
    if ($strlen((string)($cam['memo'] ?? '')) > 500) {
        $msg = '메모는 500자를 초과할 수 없습니다.';
        return false;
    }
    return true;
}

function onvifBuildBulkCameraList(array $rawList, array &$stats)
{
    $stats = [
        'raw' => count($rawList),
        'valid' => 0,
        'invalid' => 0,
        'duplicate' => 0,
    ];

    $map = [];
    foreach ($rawList as $row) {
        if (!is_array($row)) {
            $stats['invalid']++;
            continue;
        }

        $cam = onvifNormalizeCameraPayload($row);
        $cam['status'] = onvifSafeStatus($cam['status'] ?? 'unknown');
        $err = '';
        if (!onvifValidateCameraPayload($cam, $err)) {
            $stats['invalid']++;
            continue;
        }

        $key = strtolower(trim((string)$cam['camera_id']));
        if (isset($map[$key])) {
            $stats['duplicate']++;
            continue;
        }
        $map[$key] = $cam;
    }

    $stats['valid'] = count($map);
    return array_values($map);
}

function onvifIsListArray(array $arr)
{
    $i = 0;
    foreach ($arr as $k => $v) {
        if ($k !== $i) return false;
        $i++;
    }
    return true;
}

function onvifExtractCameraArrayFromPayload($payload)
{
    if (is_array($payload)) {
        if (onvifIsListArray($payload)) return $payload;

        foreach (['items', 'cameras'] as $k) {
            if (isset($payload[$k]) && is_array($payload[$k])) {
                return $payload[$k];
            }
        }

        foreach (['data', 'payload', 'json'] as $k) {
            if (!array_key_exists($k, $payload)) continue;
            $tmp = onvifExtractCameraArrayFromPayload($payload[$k]);
            if (is_array($tmp)) return $tmp;
        }
        return null;
    }

    if (is_string($payload)) {
        $txt = trim($payload);
        if ($txt === '') return null;
        $tmp = json_decode($txt, true);
        if (is_array($tmp)) return onvifExtractCameraArrayFromPayload($tmp);
    }

    return null;
}

function onvifFetchOwnerCameraRows(PDO $db, array $ctx, $cameraId = '')
{
    $params = [];
    $whereOwner = onvifOwnerWhereClause($ctx, $params);
    $camWhere = '';
    $camId = trim((string)$cameraId);
    if ($camId !== '') {
        $camId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $camId);
        if ($camId === '') return [];
        $camWhere = ' AND camera_id=?';
        $params[] = $camId;
    }

    $sql = "SELECT idx,tenant_id,created_by,camera_id,name,channel,ip,port,login_user,login_pass,ISNULL(login_pass_encrypted,0) AS login_pass_encrypted,memo,conn_method,manufacturer,
                   rtsp_port,default_stream,is_ptz,rtsp_main,rtsp_sub,status,snapshot,
                   CONVERT(VARCHAR(19), created_at, 120) AS created_at,
                   CONVERT(VARCHAR(19), updated_at, 120) AS updated_at
            FROM Tb_OnvifCameras
            WHERE {$whereOwner}{$camWhere} AND ISNULL(is_deleted,0)=0
            ORDER BY updated_at DESC, created_at DESC, idx DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function onvifRowsToCameraItems(array $rows)
{
    $items = [];
    foreach ($rows as $r) $items[] = onvifDbRowToCamera($r);
    return $items;
}

function onvifApiException($label, Throwable $e, $publicMsg = '요청 처리 중 오류가 발생했습니다.')
{
    error_log('[ONVIF_API][' . $label . '] ' . $e->getMessage());
    jsonOut(['success' => false, 'msg' => $publicMsg, '_ver' => ONVIF_API_VER], 500);
}

function onvifIsPrivateOrReservedIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function onvifHostAllowlist(): array
{
    $raw = (string)(getenv('ONVIF_HOST_ALLOWLIST') ?: '');
    if ($raw === '') {
        return [];
    }

    $items = preg_split('/[\s,;]+/', $raw) ?: [];
    $allowed = [];
    foreach ($items as $item) {
        $v = strtolower(trim((string)$item));
        if ($v !== '') {
            $allowed[$v] = true;
        }
    }

    return $allowed;
}

function onvifHostResolvesToBlockedIp(string $host): bool
{
    $ipv4List = @gethostbynamel($host);
    if (is_array($ipv4List)) {
        foreach ($ipv4List as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && onvifIsPrivateOrReservedIp($ip)) {
                return true;
            }
        }
    }

    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $row) {
                $ipv6 = (string)($row['ipv6'] ?? '');
                if ($ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && onvifIsPrivateOrReservedIp($ipv6)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function sanitizeHost($host)
{
    $host = trim((string)$host);
    if ($host === '') {
        return '';
    }

    $allowlist = onvifHostAllowlist();
    $normalized = strtolower($host);
    if (isset($allowlist[$normalized])) {
        return $host;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (onvifIsPrivateOrReservedIp($host)) {
            return '';
        }
        return $host;
    }

    $localhostSuffix = '.localhost';
    $hasLocalhostSuffix = strlen($normalized) >= strlen($localhostSuffix)
        && substr($normalized, -strlen($localhostSuffix)) === $localhostSuffix;
    if ($normalized === 'localhost' || $hasLocalhostSuffix) {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        if (onvifHostResolvesToBlockedIp($host)) {
            return '';
        }
        return $host;
    }

    return '';
}

function normalizePort($port, $default = 80)
{
    $p = (int)$port;
    if ($p < 1 || $p > 65535) {
        return (int)$default;
    }
    return $p;
}

function toHostForUrl($host)
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return '[' . $host . ']';
    }
    return $host;
}

function xmlEsc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function normalizeUrlKey($url)
{
    return strtolower(trim((string)$url));
}

function clipDebugText($text, $limit = 500)
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s+/', ' ', $text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, (int)$limit, 'UTF-8');
    }

    return substr($text, 0, (int)$limit);
}

function detectResponseFlavor($body)
{
    $body = trim((string)$body);
    if ($body === '') {
        return 'empty';
    }

    if (strpos($body, '<') === 0) {
        return 'xml';
    }

    $json = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        if (isset($json['error_code']) || isset($json['data']['encrypt_type']) || isset($json['data']['nonce'])) {
            return 'tplink_json';
        }
        return 'json';
    }

    return 'text';
}

function detectNonOnvifHint($body, $port)
{
    $flavor = detectResponseFlavor($body);
    if ($flavor !== 'tplink_json' && $flavor !== 'json') {
        return [];
    }

    $payload = json_decode((string)$body, true);
    if (!is_array($payload)) {
        return [];
    }

    $hint = [
        'detected_protocol' => ($flavor === 'tplink_json') ? 'tplink_rest_api' : 'json_non_onvif',
        'response_flavor' => $flavor,
        'body_preview' => clipDebugText($body, 500),
        'suggested_ports' => [2020, 80],
        'current_port' => (int)$port,
    ];

    if (isset($payload['error_code'])) {
        $hint['error_code'] = $payload['error_code'];
    }
    if (isset($payload['data']['code'])) {
        $hint['data_code'] = $payload['data']['code'];
    }
    if (isset($payload['data']['encrypt_type'])) {
        $hint['encrypt_type'] = $payload['data']['encrypt_type'];
    }
    if (isset($payload['data']['nonce'])) {
        $hint['nonce'] = $payload['data']['nonce'];
    }

    return $hint;
}

function appendEndpointCandidate(array &$bucket, $url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return;
    }

    $key = normalizeUrlKey($url);
    if (isset($bucket[$key])) {
        return;
    }

    $bucket[$key] = $url;
}

function sanitizeStreamId($value, $fallback = 'camera')
{
    $value = trim((string)$value);
    $value = preg_replace('/[^A-Za-z0-9_\-]/', '_', $value);
    $value = trim((string)$value, '_-');
    if ($value === '') {
        $value = $fallback;
    }
    return strtolower(substr($value, 0, 80));
}

function sanitizeRecordId($value)
{
    return sanitizeStreamId($value, 'record');
}

function ensureDirExists($path)
{
    $path = trim((string)$path);
    if ($path === '') {
        return false;
    }
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0777, true);
}

function getRuntimeMetaPath()
{
    ensureDirExists(ONVIF_RUNTIME_ROOT);
    return ONVIF_RUNTIME_ROOT . DIRECTORY_SEPARATOR . 'onvif_recordings.json';
}

function loadRecordingMeta()
{
    $file = getRuntimeMetaPath();
    if (!is_file($file)) {
        return [];
    }
    $json = @file_get_contents($file);
    if ($json === false || trim($json) === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveRecordingMeta(array $rows)
{
    $file = getRuntimeMetaPath();
    @file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function winQuote($value)
{
    return '"' . str_replace('"', '""', (string)$value) . '"';
}

function getRequestScheme()
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return 'https';
    }
    $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded === 'https') {
        return 'https';
    }
    return 'http';
}

function getPublicHost()
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        $host = '127.0.0.1';
    }
    $host = preg_replace('/:\d+$/', '', $host);
    return $host;
}

function getPublicGo2rtcBase()
{
    return getRequestScheme() . '://' . getPublicHost() . ':1984';
}

function go2rtcApiRequest($method, $path, array $query = [], $body = null, array $headers = [])
{
    $url = rtrim(ONVIF_GO2RTC_API, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    $curlHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper((string)$method),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => $curlHeaders,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $respBody = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    $json = null;
    if ($respBody !== false && trim((string)$respBody) !== '') {
        $tmp = json_decode((string)$respBody, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $tmp;
        }
    }

    return [
        'ok' => ($respBody !== false && $errNo === 0 && $status >= 200 && $status < 300),
        'status' => $status,
        'body' => ($respBody === false ? '' : (string)$respBody),
        'json' => $json,
        'err_no' => $errNo,
        'err_msg' => $errMsg,
        'url' => $url,
    ];
}

function buildRecordDir($month)
{
    $month = preg_match('/^\d{6}$/', (string)$month) ? (string)$month : date('Ym');
    return rtrim(ONVIF_RECORD_ROOT, '\\/') . DIRECTORY_SEPARATOR . $month;
}

function buildRecordingSource($streamId, $rtspUrl = '')
{
    $rtspUrl = trim((string)$rtspUrl);
    if ($rtspUrl !== '') {
        return $rtspUrl;
    }
    $streamId = sanitizeStreamId($streamId, 'camera');
    return rtrim(ONVIF_GO2RTC_RTSP, '/') . '/' . rawurlencode($streamId);
}

function buildRtspUrlFromParts(array $parts, $host)
{
    $userInfo = '';
    if (array_key_exists('user', $parts)) {
        $userInfo = rawurlencode((string)$parts['user']);
        if (array_key_exists('pass', $parts)) {
            $userInfo .= ':' . rawurlencode((string)$parts['pass']);
        }
        $userInfo .= '@';
    }

    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $port = isset($parts['port']) ? ':' . intval($parts['port']) : '';
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $userInfo . $host . $port . $path . $query . $fragment;
}

function resolveRtspHostnameToIp($src)
{
    $src = trim((string)$src);
    if ($src === '') {
        return [
            'src' => '',
            'resolved' => false,
            'host' => '',
            'ip' => '',
            'reason' => 'empty_src',
        ];
    }

    $parts = @parse_url($src);
    if (!is_array($parts) || empty($parts['host'])) {
        return [
            'src' => $src,
            'resolved' => false,
            'host' => '',
            'ip' => '',
            'reason' => 'parse_failed',
        ];
    }

    $host = trim((string)$parts['host']);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return [
            'src' => $src,
            'resolved' => false,
            'host' => $host,
            'ip' => $host,
            'reason' => 'already_ip',
        ];
    }

    // DNS 캐시 (5분 TTL) - gethostbyname 반복 호출 지연 완화
    $resolvedIp = '';
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shvq_dns_' . md5($host) . '.txt';
    $cacheTtl = 300; // 5遺?
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = trim((string)@file_get_contents($cacheFile));
        if ($cached !== '' && filter_var($cached, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'src' => buildRtspUrlFromParts($parts, $cached),
                'resolved' => true,
                'host' => $host,
                'ip' => $cached,
                'reason' => 'dns_cached',
            ];
        }
    }
    $ghbn = @gethostbyname($host);
    if (is_string($ghbn) && $ghbn !== '' && $ghbn !== $host && filter_var($ghbn, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $resolvedIp = $ghbn;
        @file_put_contents($cacheFile, $resolvedIp);
    }

    if ($resolvedIp === '') {
        return [
            'src' => $src,
            'resolved' => false,
            'host' => $host,
            'ip' => '',
            'reason' => 'dns_lookup_failed',
        ];
    }

    return [
        'src' => buildRtspUrlFromParts($parts, $resolvedIp),
        'resolved' => true,
        'host' => $host,
        'ip' => $resolvedIp,
        'reason' => 'dns_resolved',
    ];
}

function registerGo2rtcStream($streamId, $src)
{
    $streamId = sanitizeStreamId($streamId, 'camera');
    $src = trim((string)$src);
    if ($src === '') {
        return [
            'success' => false,
            'msg' => 'RTSP 주소를 입력하세요.',
            '_ver' => ONVIF_API_VER,
        ];
    }

    $streamLookup = go2rtcFindStreamById($streamId);
    if (!empty($streamLookup['checked']) && !empty($streamLookup['exists'])) {
        $streamRow = is_array($streamLookup['stream']) ? $streamLookup['stream'] : [];
        $producer0 = (isset($streamRow['producers'][0]) && is_array($streamRow['producers'][0])) ? $streamRow['producers'][0] : [];
        $existingSrc = trim((string)($producer0['url'] ?? $producer0['src'] ?? $streamRow['src'] ?? ''));
        if ($existingSrc === '') {
            $existingSrc = $src;
        }

        $publicBase = getPublicGo2rtcBase();
        return [
            'success' => true,
            'msg' => 'go2rtc 스트림이 이미 존재하여 PUT 생략',
            'stream_id' => $streamId,
            'src' => $existingSrc,
            'original_src' => $src,
            'skipped_put' => true,
            'go2rtc' => [
                'api_base' => ONVIF_GO2RTC_API,
                'public_base' => $publicBase,
                'stream_api' => rtrim(ONVIF_GO2RTC_API, '/') . '/streams',
                'webrtc_api' => rtrim(ONVIF_GO2RTC_API, '/') . '/webrtc?src=' . rawurlencode($streamId),
                'mse_url' => $publicBase . '/api/stream.mp4?src=' . rawurlencode($streamId),
                'hls_url' => $publicBase . '/api/stream.m3u8?src=' . rawurlencode($streamId),
                'snapshot_url' => $publicBase . '/api/frame.jpeg?src=' . rawurlencode($streamId),
                'stream_page' => $publicBase . '/stream.html?src=' . rawurlencode($streamId),
            ],
            'debug' => [
                'check_url' => (string)($streamLookup['url'] ?? ''),
                'check_status' => (int)($streamLookup['status'] ?? 0),
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    $resolved = resolveRtspHostnameToIp($src);
    $registerSrc = trim((string)($resolved['src'] ?? ''));
    if ($registerSrc === '') {
        $registerSrc = $src;
    }

    $res = go2rtcApiRequest('PUT', 'streams', [
        'src' => $registerSrc,
        'name' => $streamId,
    ]);
    if (!$res['ok']) {
        return [
            'success' => false,
            'msg' => 'go2rtc 스트림 등록 실패: ' . ($res['err_msg'] !== '' ? $res['err_msg'] : ('HTTP ' . $res['status'])),
            'debug' => [
                'request_url' => $res['url'],
                'status' => $res['status'],
                'original_src' => $src,
                'register_src' => $registerSrc,
                'resolved_host' => $resolved['host'] ?? '',
                'resolved_ip' => $resolved['ip'] ?? '',
                'resolve_reason' => $resolved['reason'] ?? '',
                'body' => clipDebugText($res['body'], 500),
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    $publicBase = getPublicGo2rtcBase();
    return [
        'success' => true,
        'msg' => 'go2rtc 스트림 등록 완료',
        'stream_id' => $streamId,
        'src' => $registerSrc,
        'original_src' => $src,
        'resolve' => [
            'resolved' => !empty($resolved['resolved']),
            'host' => $resolved['host'] ?? '',
            'ip' => $resolved['ip'] ?? '',
            'reason' => $resolved['reason'] ?? '',
        ],
        'go2rtc' => [
            'api_base' => ONVIF_GO2RTC_API,
            'public_base' => $publicBase,
            'stream_api' => rtrim(ONVIF_GO2RTC_API, '/') . '/streams?src=' . rawurlencode($registerSrc) . '&name=' . rawurlencode($streamId),
            'webrtc_api' => rtrim(ONVIF_GO2RTC_API, '/') . '/webrtc?src=' . rawurlencode($streamId),
            'mse_url' => $publicBase . '/api/stream.mp4?src=' . rawurlencode($streamId),
            'hls_url' => $publicBase . '/api/stream.m3u8?src=' . rawurlencode($streamId),
            'snapshot_url' => $publicBase . '/api/frame.jpeg?src=' . rawurlencode($streamId),
            'stream_page' => $publicBase . '/stream.html?src=' . rawurlencode($streamId),
        ],
        'debug' => [
            'request_url' => $res['url'],
            'status' => $res['status'],
            'body' => clipDebugText($res['body'], 500),
        ],
        '_ver' => ONVIF_API_VER,
    ];
}

function go2rtcFindStreamById($streamId)
{
    $streamId = sanitizeStreamId($streamId, 'camera');
    if ($streamId === '') {
        return [
            'checked' => false,
            'exists' => false,
            'stream' => null,
            'status' => 0,
            'url' => '',
            'err_msg' => 'stream_id empty',
        ];
    }

    $res = go2rtcApiRequest('GET', 'streams');
    $result = [
        'checked' => (bool)($res['ok'] ?? false),
        'exists' => false,
        'stream' => null,
        'status' => (int)($res['status'] ?? 0),
        'url' => (string)($res['url'] ?? ''),
        'err_msg' => (string)($res['err_msg'] ?? ''),
    ];

    if (!$result['checked'] || !is_array($res['json'])) {
        return $result;
    }

    $payload = $res['json'];

    if (isset($payload[$streamId]) && is_array($payload[$streamId])) {
        $result['exists'] = true;
        $result['stream'] = $payload[$streamId];
        return $result;
    }

    if (isset($payload['streams']) && is_array($payload['streams'])) {
        $nested = $payload['streams'];
        if (isset($nested[$streamId]) && is_array($nested[$streamId])) {
            $result['exists'] = true;
            $result['stream'] = $nested[$streamId];
            return $result;
        }
        foreach ($nested as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = trim((string)($row['name'] ?? $row['id'] ?? $row['stream'] ?? ''));
            if ($candidate === $streamId) {
                $result['exists'] = true;
                $result['stream'] = $row;
                return $result;
            }
        }
    }

    foreach ($payload as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = trim((string)($row['name'] ?? $row['id'] ?? $row['stream'] ?? ''));
        if ($candidate === $streamId) {
            $result['exists'] = true;
            $result['stream'] = $row;
            return $result;
        }
    }

    return $result;
}

function startRecordingJob($streamId, $rtspUrl, $durationSec, $qualityHeight)
{
    $streamId = sanitizeStreamId($streamId, 'camera');
    $recordId = sanitizeRecordId($streamId . '_' . date('Ymd_His'));
    $month = date('Ym');
    $recordDir = buildRecordDir($month);
    if (!ensureDirExists($recordDir)) {
        return [
            'success' => false,
            'msg' => '녹화 폴더 생성 실패',
            'dir' => $recordDir,
            '_ver' => ONVIF_API_VER,
        ];
    }

    $source = buildRecordingSource($streamId, $rtspUrl);
    $resolved = resolveRtspHostnameToIp($source);
    $recordSource = trim((string)($resolved['src'] ?? ''));
    if ($recordSource === '') {
        $recordSource = $source;
    }
    $qualityHeight = max(240, min(2160, (int)$qualityHeight));
    $durationSec = max(0, min(86400, (int)$durationSec));
    $outputFile = $recordDir . DIRECTORY_SEPARATOR . $recordId . '.mp4';

    $args = [
        '-y',
        '-hide_banner',
        '-nostdin',
        '-rtsp_transport', 'tcp',
        '-i', $recordSource,
    ];
    if ($durationSec > 0) {
        $args[] = '-t';
        $args[] = (string)$durationSec;
    }
    $args[] = '-c:v';
    $args[] = 'copy';
    $args[] = '-an';
    $args[] = $outputFile;

    $cmdParts = ['start', '/LOW', '/B', '""', winQuote(ONVIF_FFMPEG_EXE)];
    foreach ($args as $arg) {
        $cmdParts[] = winQuote($arg);
    }
    $command = implode(' ', $cmdParts) . ' > NUL 2>&1';
    $proc = @popen($command, 'r');
    if (is_resource($proc)) {
        @pclose($proc);
    } else {
        return [
            'success' => false,
            'msg' => 'FFmpeg 녹화 시작 실패',
            'debug' => [
                'command' => $command,
                'launch_method' => 'popen',
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    // PID는 record_stop에서 tasklist로 찾음. FastCGI 크래시 방지를 위해 sleep+탐지는 제거.
    $pid = 0;

    $meta = loadRecordingMeta();
    $meta[$recordId] = [
        'record_id' => $recordId,
        'pid' => $pid,
        'stream_id' => $streamId,
        'source' => $recordSource,
        'original_source' => $source,
        'output_file' => $outputFile,
        'month' => $month,
        'file_name' => basename($outputFile),
        'started_at' => date('Y-m-d H:i:s'),
        'duration_sec' => $durationSec,
        'quality_height' => 0,
        'status' => 'recording',
        'launch_command' => $command,
    ];
    saveRecordingMeta($meta);

    return [
        'success' => true,
        'msg' => '녹화 시작',
        'record_id' => $recordId,
        'pid' => $pid,
        'stream_id' => $streamId,
        'source' => $recordSource,
        'original_source' => $source,
        'output_file' => $outputFile,
        'duration_sec' => $durationSec,
        'quality_height' => 0,
        'debug' => [
            'ffmpeg_count_check' => 'skipped_fastcgi_safety',
            'launch_method' => 'popen',
            'launch_command' => $command,
            'resolved_host' => $resolved['host'] ?? '',
            'resolved_ip' => $resolved['ip'] ?? '',
            'resolve_reason' => $resolved['reason'] ?? '',
        ],
        '_ver' => ONVIF_API_VER,
    ];
}

function stopRecordingJob($recordId = '', $pid = 0)
{
    $meta = loadRecordingMeta();
    $recordId = trim((string)$recordId);
    $recordMeta = ($recordId !== '' && isset($meta[$recordId]) && is_array($meta[$recordId])) ? $meta[$recordId] : [];

    $outputFile = trim((string)($recordMeta['output_file'] ?? ''));
    if ($outputFile === '') {
        return [
            'success' => false,
            'msg' => '중지할 녹화 파일 정보를 찾을 수 없습니다.',
            'record_id' => $recordId,
            'debug' => [
                'meta' => $recordMeta,
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    // IIS PHP 환경에서 wmic/exec/shell_exec 대신 popen + taskkill만 사용
    $cmd = 'taskkill /IM ffmpeg.exe /F >NUL 2>&1';
    $proc = @popen($cmd, 'r');
    $code = 0;
    $output = ['taskkill via popen'];
    if (is_resource($proc)) {
        @pclose($proc);
    } else {
        $code = 1;
        $output[] = 'popen failed';
    }

    if ($recordId !== '' && isset($meta[$recordId])) {
        $meta[$recordId]['status'] = ($code === 0) ? 'stopped' : 'stop_failed';
        $meta[$recordId]['stopped_at'] = date('Y-m-d H:i:s');
        saveRecordingMeta($meta);
    }

    return [
        'success' => ($code === 0),
        'msg' => ($code === 0) ? '녹화 중지 완료' : '녹화 중지 실패',
        'record_id' => $recordId,
        'debug' => [
            'command' => $cmd,
            'exit_code' => $code,
            'output' => $output,
        ],
        '_ver' => ONVIF_API_VER,
    ];
}

function captureSnapshotImage($streamId, $rtspUrl)
{
    $streamId = sanitizeStreamId($streamId, 'camera');
    $rtspUrl = trim((string)$rtspUrl);

    if ($rtspUrl === '') {
        return [
            'success' => false,
            'msg' => 'RTSP URL을 입력하세요.',
            '_ver' => ONVIF_API_VER,
        ];
    }

    $resolved = resolveRtspHostnameToIp($rtspUrl);
    $snapshotSource = trim((string)($resolved['src'] ?? ''));
    if ($snapshotSource === '') {
        $snapshotSource = $rtspUrl;
    }

    $snapshotDir = rtrim(ONVIF_RECORD_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'snapshots';
    if (!ensureDirExists($snapshotDir)) {
        return [
            'success' => false,
            'msg' => '스냅샷 폴더 생성 실패',
            'dir' => $snapshotDir,
            '_ver' => ONVIF_API_VER,
        ];
    }

    $snapshotPath = $snapshotDir . DIRECTORY_SEPARATOR . $streamId . '.jpg';
    @unlink($snapshotPath);

    $args = [
        '-y',
        '-hide_banner',
        '-nostdin',
        '-rtsp_transport', 'tcp',
        '-i', $snapshotSource,
        '-frames:v', '1',
        '-q:v', '5',
        $snapshotPath,
    ];

    $cmdParts = [winQuote(ONVIF_FFMPEG_EXE)];
    foreach ($args as $arg) {
        $cmdParts[] = winQuote($arg);
    }
    $command = implode(' ', $cmdParts) . ' >NUL 2>&1';
    $proc = @popen($command, 'r');
    $exitCode = 1;
    if (is_resource($proc)) {
        $exitCode = (int)@pclose($proc);
    } else {
        return [
            'success' => false,
            'msg' => '스냅샷 캡처 시작 실패',
            'debug' => [
                'command' => $command,
                'launch_method' => 'popen',
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    clearstatcache(true, $snapshotPath);
    $size = (int)@filesize($snapshotPath);
    if (!is_file($snapshotPath) || $size <= 0) {
        return [
            'success' => false,
            'msg' => '스냅샷 캡처 실패',
            'debug' => [
                'command' => $command,
                'exit_code' => $exitCode,
                'snapshot_path' => $snapshotPath,
                'resolved_host' => $resolved['host'] ?? '',
                'resolved_ip' => $resolved['ip'] ?? '',
                'resolve_reason' => $resolved['reason'] ?? '',
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    @readfile($snapshotPath);
    exit;
}

function listRecordingFiles($month = '')
{
    $meta = loadRecordingMeta();
    $months = [];
    if (preg_match('/^\d{6}$/', (string)$month)) {
        $months[] = (string)$month;
    } else {
        $root = rtrim(ONVIF_RECORD_ROOT, '\\/');
        if (is_dir($root)) {
            $dirs = @scandir($root);
            if (is_array($dirs)) {
                foreach ($dirs as $dir) {
                    if (preg_match('/^\d{6}$/', (string)$dir)) {
                        $months[] = $dir;
                    }
                }
            }
        }
    }

    rsort($months);
    $rows = [];
    foreach ($months as $ym) {
        $dir = buildRecordDir($ym);
        if (!is_dir($dir)) {
            continue;
        }

        $files = @scandir($dir);
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }

            $recordId = pathinfo($file, PATHINFO_FILENAME);
            $metaRow = isset($meta[$recordId]) && is_array($meta[$recordId]) ? $meta[$recordId] : [];
            $rows[] = [
                'record_id' => $recordId,
                'month' => $ym,
                'file_name' => $file,
                'size' => (int)@filesize($path),
                'mtime' => @date('Y-m-d H:i:s', (int)@filemtime($path)),
                'status' => (string)($metaRow['status'] ?? 'saved'),
                'pid' => (int)($metaRow['pid'] ?? 0),
                'stream_id' => (string)($metaRow['stream_id'] ?? ''),
            ];
        }
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)$b['mtime'], (string)$a['mtime']);
    });

    return [
        'success' => true,
        'msg' => '녹화 파일 ' . count($rows) . '건',
        'month' => $month,
        'count' => count($rows),
        'files' => $rows,
        '_ver' => ONVIF_API_VER,
    ];
}

function resolveRecordingPath($recordId = '', $month = '', $fileName = '')
{
    $recordId = trim((string)$recordId);
    $month = trim((string)$month);
    $fileName = basename(trim((string)$fileName));
    $meta = loadRecordingMeta();

    if ($recordId !== '' && isset($meta[$recordId])) {
        $candidate = (string)($meta[$recordId]['output_file'] ?? '');
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
        $month = (string)($meta[$recordId]['month'] ?? $month);
        $fileName = (string)($meta[$recordId]['file_name'] ?? ($recordId . '.mp4'));
    }

    if (!preg_match('/^\d{6}$/', $month)) {
        return '';
    }
    if ($fileName === '') {
        return '';
    }

    $path = buildRecordDir($month) . DIRECTORY_SEPARATOR . $fileName;
    return is_file($path) ? $path : '';
}

function downloadRecordingFile($recordId = '', $month = '', $fileName = '')
{
    $path = resolveRecordingPath($recordId, $month, $fileName);
    if ($path === '') {
        return false;
    }

    $downloadName = basename($path);
    header('Content-Type: video/mp4');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    readfile($path);
    exit;
}

function buildDeviceServiceCandidates($host, $port)
{
    $host = sanitizeHost($host);
    if ($host === '') {
        return [];
    }

    $safeHost = toHostForUrl($host);
    $port = normalizePort($port, 80);

    $candidates = [];
    $candidates[] = 'http://' . $safeHost . ':' . $port . '/onvif/device_service';
    $candidates[] = 'https://' . $safeHost . ':' . $port . '/onvif/device_service';

    if ($port === 80) {
        $candidates[] = 'http://' . $safeHost . '/onvif/device_service';
    }
    if ($port === 443) {
        $candidates[] = 'https://' . $safeHost . '/onvif/device_service';
    }

    return array_values(array_unique($candidates));
}

function buildSoapEnvelope($bodyXml)
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
        . '<soap:Body>' . $bodyXml . '</soap:Body>'
        . '</soap:Envelope>';
}

function onvifSoapRequest($xaddr, $action, $bodyXml, $user = '', $pass = '', $timeout = ONVIF_DEFAULT_TIMEOUT)
{
    $payload = buildSoapEnvelope($bodyXml);

    $ch = curl_init($xaddr);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/soap+xml; charset=utf-8; action="' . $action . '"',
            'SOAPAction: "' . $action . '"',
        ],
        CURLOPT_TIMEOUT => max(1, (int)$timeout),
        CURLOPT_CONNECTTIMEOUT => min(3, max(1, (int)$timeout)),
        CURLOPT_USERAGENT => 'SHV-ERP/2.0',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($user !== '' || $pass !== '') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    return [
        'ok' => ($body !== false && $errNo === 0 && $status >= 200 && $status < 300 && detectResponseFlavor($body) === 'xml'),
        'status' => $status,
        'body' => ($body === false ? '' : (string)$body),
        'err_no' => $errNo,
        'err_msg' => $errMsg,
        'response_flavor' => ($body === false ? 'empty' : detectResponseFlavor($body)),
    ];
}

function xmlToDom($xml)
{
    if (trim((string)$xml) === '') {
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();

    if (!$ok) {
        return null;
    }

    return $dom;
}

function xmlEvalString(DOMXPath $xp, $expr, $contextNode = null)
{
    $v = ($contextNode === null)
        ? $xp->evaluate($expr)
        : $xp->evaluate($expr, $contextNode);
    return trim((string)$v);
}

function parseDeviceInfo($xml)
{
    $dom = xmlToDom($xml);
    if (!$dom) {
        return [];
    }

    $xp = new DOMXPath($dom);

    $manufacturer = xmlEvalString($xp, 'string(//*[local-name()="GetDeviceInformationResponse"]/*[local-name()="Manufacturer"][1])');
    $model = xmlEvalString($xp, 'string(//*[local-name()="GetDeviceInformationResponse"]/*[local-name()="Model"][1])');
    $firmware = xmlEvalString($xp, 'string(//*[local-name()="GetDeviceInformationResponse"]/*[local-name()="FirmwareVersion"][1])');
    $serial = xmlEvalString($xp, 'string(//*[local-name()="GetDeviceInformationResponse"]/*[local-name()="SerialNumber"][1])');
    $hardware = xmlEvalString($xp, 'string(//*[local-name()="GetDeviceInformationResponse"]/*[local-name()="HardwareId"][1])');

    return [
        'manufacturer' => $manufacturer,
        'model' => $model,
        'firmware' => $firmware,
        'serial' => $serial,
        'hardware' => $hardware,
    ];
}

function hasAuthError($status, $body)
{
    if ((int)$status === 401 || (int)$status === 403) {
        return true;
    }

    $body = strtolower((string)$body);
    return (strpos($body, 'notauthorized') !== false
        || strpos($body, 'not authorized') !== false
        || strpos($body, 'unauthorized') !== false
        || strpos($body, 'sender not authorized') !== false);
}

function extractHostFromPeer($peer)
{
    $peer = trim((string)$peer);
    if ($peer === '') {
        return '';
    }

    if (preg_match('/^\[([^\]]+)\]:\d+$/', $peer, $m)) {
        return $m[1];
    }

    if (preg_match('/^([^:]+):\d+$/', $peer, $m)) {
        return $m[1];
    }

    if (filter_var($peer, FILTER_VALIDATE_IP)) {
        return $peer;
    }

    return $peer;
}

function makeDefaultRtsp($host)
{
    if ($host === '') {
        return '';
    }
    $safeHost = toHostForUrl($host);
    return 'rtsp://' . $safeHost . ':554/stream1';
}

function parseScopeName($scopes)
{
    $scopes = trim((string)$scopes);
    if ($scopes === '') {
        return '';
    }

    $list = preg_split('/\s+/', $scopes);
    foreach ($list as $scope) {
        if ($scope === '') {
            continue;
        }
        $pos = stripos($scope, '/name/');
        if ($pos !== false) {
            $name = substr($scope, $pos + 6);
            $name = rawurldecode($name);
            $name = trim(str_replace(['_', '-'], ' ', $name));
            if ($name !== '') {
                return $name;
            }
        }
    }

    return '';
}

function parseDiscoveryPacket($xml, $peer = '')
{
    $dom = xmlToDom($xml);
    if (!$dom) {
        return null;
    }

    $xp = new DOMXPath($dom);
    $xaddrsText = xmlEvalString($xp, 'string(//*[local-name()="XAddrs"][1])');
    $scopesText = xmlEvalString($xp, 'string(//*[local-name()="Scopes"][1])');
    $address = xmlEvalString($xp, 'string(//*[local-name()="Address"][1])');

    $xaddr = '';
    if ($xaddrsText !== '') {
        $arr = preg_split('/\s+/', $xaddrsText);
        if (!empty($arr[0])) {
            $xaddr = trim($arr[0]);
        }
    }

    $host = '';
    $port = 80;

    if ($xaddr !== '') {
        $u = @parse_url($xaddr);
        if (is_array($u)) {
            $host = trim((string)($u['host'] ?? ''));
            $scheme = strtolower((string)($u['scheme'] ?? 'http'));
            $port = isset($u['port']) ? (int)$u['port'] : ($scheme === 'https' ? 443 : 80);
        }
    }

    if ($host === '') {
        $host = extractHostFromPeer($peer);
    }

    $name = parseScopeName($scopesText);
    if ($name === '') {
        $name = 'ONVIF 카메라';
        if ($host !== '') {
            $name .= ' (' . $host . ')';
        }
    }

    if ($host === '') {
        return null;
    }

    return [
        'name' => $name,
        'ip' => $host,
        'port' => (string)$port,
        'xaddr' => $xaddr,
        'urn' => $address,
        'scopes' => $scopesText,
        'rtsp' => makeDefaultRtsp($host),
    ];
}

function rewriteUrlHost($url, $targetHost, $fallbackPort = 80)
{
    $url = trim((string)$url);
    $targetHost = sanitizeHost($targetHost);
    if ($url === '' || $targetHost === '') {
        return '';
    }

    $u = @parse_url($url);
    if (!is_array($u)) {
        return '';
    }

    $scheme = strtolower((string)($u['scheme'] ?? 'http'));
    if ($scheme !== 'http' && $scheme !== 'https' && $scheme !== 'rtsp') {
        $scheme = 'http';
    }

    $defaultPort = ($scheme === 'https') ? 443 : (($scheme === 'rtsp') ? 554 : 80);
    $port = isset($u['port']) ? normalizePort((int)$u['port'], $defaultPort) : normalizePort($fallbackPort, $defaultPort);

    $path = (string)($u['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }

    $query = (isset($u['query']) && $u['query'] !== '') ? ('?' . $u['query']) : '';
    $fragment = (isset($u['fragment']) && $u['fragment'] !== '') ? ('#' . $u['fragment']) : '';

    return $scheme . '://' . toHostForUrl($targetHost) . ':' . $port . $path . $query . $fragment;
}

function buildMediaServiceCandidates($host, $port, $deviceXaddr, array $rawMediaXaddrs = [])
{
    $host = sanitizeHost($host);
    if ($host === '') {
        return [];
    }

    $safeHost = toHostForUrl($host);
    $port = normalizePort($port, 80);
    $bucket = [];

    foreach ($rawMediaXaddrs as $rawUrl) {
        appendEndpointCandidate($bucket, $rawUrl);
        appendEndpointCandidate($bucket, rewriteUrlHost($rawUrl, $host, $port));
    }

    $deviceVariants = [
        ['device_service', 'media_service'],
        ['device_service', 'Media'],
        ['device_service', 'media'],
        ['device_service', 'media2_service'],
        ['device_service', 'Media2'],
        ['device_service', 'onvif/services'],
        ['onvif/device_service', 'onvif/media_service'],
        ['onvif/device_service', 'onvif/Media'],
        ['onvif/device_service', 'onvif/media'],
        ['onvif/device_service', 'onvif/media2_service'],
        ['onvif/device_service', 'onvif/Media2'],
        ['onvif/device_service', 'onvif/services'],
    ];

    foreach ($deviceVariants as $pair) {
        if (strpos($deviceXaddr, $pair[0]) !== false) {
            appendEndpointCandidate($bucket, str_replace($pair[0], $pair[1], $deviceXaddr));
        }
    }

    $baseCandidates = [
        'http://' . $safeHost . ':' . $port . '/onvif/media_service',
        'https://' . $safeHost . ':' . $port . '/onvif/media_service',
        'http://' . $safeHost . ':' . $port . '/onvif/Media',
        'https://' . $safeHost . ':' . $port . '/onvif/Media',
        'http://' . $safeHost . ':' . $port . '/onvif/media',
        'https://' . $safeHost . ':' . $port . '/onvif/media',
        'http://' . $safeHost . ':' . $port . '/onvif/media2_service',
        'https://' . $safeHost . ':' . $port . '/onvif/media2_service',
        'http://' . $safeHost . ':' . $port . '/onvif/Media2',
        'https://' . $safeHost . ':' . $port . '/onvif/Media2',
        'http://' . $safeHost . ':' . $port . '/onvif/services',
        'https://' . $safeHost . ':' . $port . '/onvif/services',
    ];
    if ($port === 80) {
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/media_service';
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/Media';
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/media';
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/media2_service';
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/Media2';
        $baseCandidates[] = 'http://' . $safeHost . '/onvif/services';
    }
    if ($port === 443) {
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/media_service';
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/Media';
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/media';
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/media2_service';
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/Media2';
        $baseCandidates[] = 'https://' . $safeHost . '/onvif/services';
    }

    foreach ($baseCandidates as $cand) {
        appendEndpointCandidate($bucket, $cand);
    }

    return array_values($bucket);
}

function tcpPortCheck($host, $port, $timeout)
{
    $host = sanitizeHost($host);
    $port = normalizePort($port, 554);
    $timeout = max(1, min(15, (int)$timeout));

    if ($host === '') {
        return [
            'success' => false,
            'msg' => 'IP 주소를 입력하세요.',
            'errno' => 0,
        ];
    }

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (is_resource($fp)) {
        fclose($fp);
        return [
            'success' => true,
            'msg' => '포트 열림',
            'ip' => $host,
            'port' => (string)$port,
            '_ver' => ONVIF_API_VER,
        ];
    }

    return [
        'success' => false,
        'msg' => '연결 실패: ' . ($errstr !== '' ? $errstr : 'unknown error'),
        'errno' => (int)$errno,
        'ip' => $host,
        'port' => (string)$port,
        '_ver' => ONVIF_API_VER,
    ];
}

function normalizeRtspCheckPath($path)
{
    $path = trim((string)$path);
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '') {
        $path = 'ch1/sub/av_stream';
    }
    return $path;
}

function buildRtspUrlWithAuth($host, $port, $user, $pass, $path)
{
    $safeHost = toHostForUrl((string)$host);
    $safePort = normalizePort((int)$port, 554);
    $path = normalizeRtspCheckPath($path);

    $auth = rawurlencode((string)$user);
    $pass = (string)$pass;
    if ($pass !== '') {
        $auth .= ':' . rawurlencode($pass);
    }

    return 'rtsp://' . $auth . '@' . $safeHost . ':' . $safePort . '/' . $path;
}

function maskRtspCredential($rtspUrl)
{
    $rtspUrl = trim((string)$rtspUrl);
    if ($rtspUrl === '') {
        return '';
    }

    if (preg_match('#^(rtsp://[^:/@]+:)([^@]*)(@.+)$#i', $rtspUrl, $m)) {
        return $m[1] . '***' . $m[3];
    }

    return preg_replace('#^(rtsp://[^@]+)@#i', 'rtsp://***@', $rtspUrl);
}

function rtspDescribeWithCurl($rtspUrl, $user, $pass, $timeout)
{
    $timeout = max(1, min(15, (int)$timeout));
    if (!function_exists('curl_init') || !defined('CURLOPT_RTSP_REQUEST') || !defined('CURL_RTSPREQ_DESCRIBE')) {
        return [
            'available' => false,
            'method' => 'curl_rtsp',
            'status' => 0,
            'ok' => false,
            'timeout' => false,
            'err_no' => 0,
            'err_msg' => 'curl rtsp 미지원',
            'response_ms' => 0,
            'debug' => '',
        ];
    }

    $ch = curl_init($rtspUrl);
    $start = microtime(true);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPAUTH => defined('CURLAUTH_ANY') ? CURLAUTH_ANY : 0,
        CURLOPT_USERPWD => (string)$user . ':' . (string)$pass,
        CURLOPT_RTSP_REQUEST => CURL_RTSPREQ_DESCRIBE,
        CURLOPT_RTSP_STREAM_URI => $rtspUrl,
        CURLOPT_HTTPHEADER => ['Accept: application/sdp'],
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);

    $respText = is_string($resp) ? $resp : '';
    if ($status === 0 && $respText !== '' && preg_match('/RTSP\/\d\.\d\s+(\d{3})/i', $respText, $m)) {
        $status = (int)$m[1];
    }

    $timeoutErr = (($errNo === 28) || stripos($errMsg, 'timed out') !== false);
    return [
        'available' => true,
        'method' => 'curl_rtsp',
        'status' => $status,
        'ok' => ($status >= 200 && $status < 300),
        'timeout' => $timeoutErr,
        'err_no' => $errNo,
        'err_msg' => (string)$errMsg,
        'response_ms' => $elapsedMs,
        'debug' => clipDebugText($respText !== '' ? $respText : $errMsg, 500),
    ];
}

function rtspDescribeWithFfmpeg($rtspUrl, $timeout)
{
    $timeout = max(1, min(15, (int)$timeout));
    if (!is_file(ONVIF_FFMPEG_EXE)) {
        return [
            'available' => false,
            'method' => 'ffmpeg_rtsp',
            'status' => 0,
            'ok' => false,
            'timeout' => false,
            'err_no' => 0,
            'err_msg' => 'ffmpeg 없음',
            'response_ms' => 0,
            'debug' => '',
        ];
    }

    $usec = max(1000000, $timeout * 1000000);
    $cmd = winQuote(ONVIF_FFMPEG_EXE)
        . ' -nostdin -hide_banner -loglevel error'
        . ' -rtsp_transport tcp -stimeout ' . (int)$usec . ' -rw_timeout ' . (int)$usec
        . ' -i ' . winQuote($rtspUrl)
        . ' -frames:v 1 -f null NUL 2>&1';

    $start = microtime(true);
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);
    $raw = trim(implode("\n", $out));
    $lower = strtolower($raw);

    $status = 0;
    $isTimeout = (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false || strpos($lower, '10060') !== false);
    if ($code === 0) {
        $status = 200;
    } elseif (strpos($lower, '401') !== false || strpos($lower, 'unauthorized') !== false) {
        $status = 401;
    } elseif (strpos($lower, '403') !== false || strpos($lower, 'forbidden') !== false) {
        $status = 403;
    } elseif (strpos($lower, '404') !== false || strpos($lower, 'not found') !== false) {
        $status = 404;
    } elseif ($isTimeout) {
        $status = 408;
    }

    return [
        'available' => true,
        'method' => 'ffmpeg_rtsp',
        'status' => $status,
        'ok' => ($status >= 200 && $status < 300),
        'timeout' => $isTimeout,
        'err_no' => (int)$code,
        'err_msg' => ($code === 0 ? '' : $raw),
        'response_ms' => $elapsedMs,
        'debug' => clipDebugText($raw, 500),
    ];
}

function rtspAuthCheck($host, $port, $user, $pass, $timeout, $rtspPath = 'ch1/sub/av_stream')
{
    $host = sanitizeHost($host);
    $port = normalizePort($port, 554);
    $timeout = max(1, min(15, (int)$timeout));
    $user = trim((string)$user);
    $pass = (string)$pass;
    $rtspPath = normalizeRtspCheckPath($rtspPath);

    if ($host === '') {
        return [
            'success' => false,
            'auth_failed' => false,
            'msg' => 'IP 주소를 입력하세요.',
            'ip' => '',
            'port' => (string)$port,
            '_ver' => ONVIF_API_VER,
        ];
    }
    if ($user === '') {
        return [
            'success' => false,
            'auth_failed' => false,
            'msg' => '로그인 ID를 입력하세요.',
            'ip' => $host,
            'port' => (string)$port,
            '_ver' => ONVIF_API_VER,
        ];
    }

    $tcpStart = microtime(true);
    $tcp = tcpPortCheck($host, $port, $timeout);
    $tcpMs = (int)round((microtime(true) - $tcpStart) * 1000);
    if (empty($tcp['success'])) {
        return [
            'success' => false,
            'auth_failed' => false,
            'msg' => (string)($tcp['msg'] ?? '연결 실패'),
            'ip' => $host,
            'port' => (string)$port,
            'reachable' => false,
            'tcp_ms' => $tcpMs,
            '_ver' => ONVIF_API_VER,
        ];
    }

    $rtspUrl = buildRtspUrlWithAuth($host, $port, $user, $pass, $rtspPath);
    $resolved = resolveRtspHostnameToIp($rtspUrl);
    $checkUrl = trim((string)($resolved['src'] ?? ''));
    if ($checkUrl === '') {
        $checkUrl = $rtspUrl;
    }

    $probe = rtspDescribeWithCurl($checkUrl, $user, $pass, $timeout);
    if (!$probe['available'] || ((int)($probe['status'] ?? 0) === 0 && empty($probe['ok']) && empty($probe['timeout']))) {
        $ff = rtspDescribeWithFfmpeg($checkUrl, $timeout);
        if (!empty($ff['available'])) {
            $probe = $ff;
        }
    }

    $status = (int)($probe['status'] ?? 0);
    $timeoutHit = !empty($probe['timeout']);
    $msg = 'RTSP 연결 실패';
    $authFailed = false;
    $success = false;

    if ($status === 200 || !empty($probe['ok'])) {
        $success = true;
        $msg = 'RTSP 연결 + 인증 성공';
    } elseif ($status === 401) {
        $authFailed = true;
        $msg = 'RTSP 인증 실패 (401)';
    } elseif ($timeoutHit || $status === 408) {
        $msg = 'RTSP 연결 실패: timeout';
    } elseif ($status > 0) {
        $msg = 'RTSP 응답 코드: ' . $status;
    } elseif (trim((string)($probe['err_msg'] ?? '')) !== '') {
        $msg = 'RTSP 연결 실패: ' . clipDebugText((string)$probe['err_msg'], 200);
    }

    return [
        'success' => $success,
        'auth_failed' => $authFailed,
        'msg' => $msg,
        'ip' => $host,
        'port' => (string)$port,
        'reachable' => true,
        'rtsp_path' => $rtspPath,
        'rtsp_url' => maskRtspCredential($rtspUrl),
        'status_code' => $status,
        'check_method' => (string)($probe['method'] ?? ''),
        'response_ms' => (int)($probe['response_ms'] ?? 0),
        'tcp_ms' => $tcpMs,
        'dns_resolved' => !empty($resolved['resolved']),
        'resolved_ip' => (string)($resolved['ip'] ?? ''),
        'debug' => [
            'probe_err_no' => (int)($probe['err_no'] ?? 0),
            'probe_err_msg' => clipDebugText((string)($probe['err_msg'] ?? ''), 200),
            'probe_preview' => (string)($probe['debug'] ?? ''),
        ],
        '_ver' => ONVIF_API_VER,
    ];
}

function resolveReachableDeviceService($host, $port, $timeout)
{
    $candidates = buildDeviceServiceCandidates($host, $port);
    if (empty($candidates)) {
        return ['success' => false, 'msg' => '유효한 IP/호스트를 입력하세요.'];
    }

    $lastError = 'ONVIF 장치 응답이 없습니다.';

    foreach ($candidates as $xaddr) {
        $probe = onvifSoapRequest(
            $xaddr,
            'http://www.onvif.org/ver10/device/wsdl/GetSystemDateAndTime',
            '<tds:GetSystemDateAndTime xmlns:tds="http://www.onvif.org/ver10/device/wsdl"/>',
            '',
            '',
            $timeout
        );

        $nonOnvifHint = detectNonOnvifHint($probe['body'] ?? '', $port);
        if (!empty($nonOnvifHint)) {
            return [
                'success' => false,
                'xaddr' => $xaddr,
                'probe' => $probe,
                'non_onvif_hint' => $nonOnvifHint,
                'msg' => '해당 포트는 ONVIF가 아니며 다른 장치 API로 응답합니다.',
            ];
        }

        if ($probe['ok'] || hasAuthError($probe['status'], $probe['body'])) {
            return [
                'success' => true,
                'xaddr' => $xaddr,
                'probe' => $probe,
            ];
        }

        if ($probe['err_msg'] !== '') {
            $lastError = $probe['err_msg'];
        } elseif ($probe['status'] > 0) {
            $lastError = 'HTTP ' . $probe['status'];
        }
    }

    return [
        'success' => false,
        'msg' => '연결 실패: ' . $lastError,
    ];
}

function parseMediaServiceXaddrs($xml)
{
    $dom = xmlToDom($xml);
    if (!$dom) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $found = [];

    $nodes = $xp->query('//*[local-name()="Capabilities"]//*[local-name()="Media" or local-name()="Media2"]//*[local-name()="XAddr"]');
    if ($nodes) {
        foreach ($nodes as $n) {
            $v = trim((string)$n->textContent);
            if ($v !== '' && preg_match('#^https?://#i', $v)) {
                $found[normalizeUrlKey($v)] = $v;
            }
        }
    }

    if (empty($found)) {
        $fallback = $xp->query('//*[local-name()="XAddr"]');
        if ($fallback) {
            foreach ($fallback as $n) {
                $v = trim((string)$n->textContent);
                if ($v !== '' && preg_match('#^https?://#i', $v) && stripos($v, 'media') !== false) {
                    $found[normalizeUrlKey($v)] = $v;
                }
            }
        }
    }

    return array_values($found);
}

function parseProfiles($xml)
{
    $dom = xmlToDom($xml);
    if (!$dom) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $nodes = $xp->query('//*[local-name()="Profiles" or local-name()="Profile"]');
    if (!$nodes || $nodes->length === 0) {
        return [];
    }

    $rows = [];
    $seen = [];

    foreach ($nodes as $node) {
        $token = trim((string)$node->getAttribute('token'));
        if ($token === '') {
            $token = trim((string)$node->getAttribute('Token'));
        }
        if ($token === '') {
            continue;
        }

        $tKey = strtolower($token);
        if (isset($seen[$tKey])) {
            continue;
        }
        $seen[$tKey] = true;

        $name = xmlEvalString($xp, 'string(./*[local-name()="Name"][1])', $node);
        if ($name === '') {
            $name = xmlEvalString($xp, 'string(.//*[local-name()="Name"][1])', $node);
        }

        $encoding = strtoupper(xmlEvalString($xp, 'string(.//*[local-name()="Encoding"][1])', $node));
        $width = (int)$xp->evaluate('number(.//*[local-name()="Resolution"]/*[local-name()="Width"][1])', $node);
        $height = (int)$xp->evaluate('number(.//*[local-name()="Resolution"]/*[local-name()="Height"][1])', $node);
        $fps = (float)$xp->evaluate('number(.//*[local-name()="FrameRateLimit"][1])', $node);
        if ($fps != $fps || $fps < 0) {
            $fps = 0;
        }

        if ($width < 0) $width = 0;
        if ($height < 0) $height = 0;

        $rows[] = [
            'token' => $token,
            'name' => $name,
            'encoding' => $encoding,
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
        ];
    }

    return $rows;
}

function parseStreamUri($xml)
{
    $dom = xmlToDom($xml);
    if (!$dom) {
        return '';
    }

    $xp = new DOMXPath($dom);
    $uri = xmlEvalString($xp, 'string(//*[local-name()="GetStreamUriResponse"]//*[local-name()="Uri"][1])');
    if ($uri === '') {
        $uri = xmlEvalString($xp, 'string(//*[local-name()="Uri"][1])');
    }

    return $uri;
}

function buildGetStreamUriBody($profileToken, $mediaVersion = '10')
{
    $token = xmlEsc($profileToken);

    if ((string)$mediaVersion === '20') {
        return '<tr2:GetStreamUri xmlns:tr2="http://www.onvif.org/ver20/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">'
            . '<tr2:Protocol>RTSP</tr2:Protocol>'
            . '<tr2:ProfileToken>' . $token . '</tr2:ProfileToken>'
            . '</tr2:GetStreamUri>';
    }

    return '<trt:GetStreamUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">'
        . '<trt:StreamSetup>'
        . '<tt:Stream>RTP-Unicast</tt:Stream>'
        . '<tt:Transport><tt:Protocol>RTSP</tt:Protocol></tt:Transport>'
        . '</trt:StreamSetup>'
        . '<trt:ProfileToken>' . $token . '</trt:ProfileToken>'
        . '</trt:GetStreamUri>';
}

function extractChannelNumber($uri, $fallback = 1)
{
    $fallback = max(1, (int)$fallback);
    $uri = (string)$uri;

    if (preg_match('/[?&]channel=(\d+)/i', $uri, $m)) {
        return max(1, (int)$m[1]);
    }

    if (preg_match('#/Channels/(\d{3,})#i', $uri, $m)) {
        $v = (int)$m[1];
        $ch = (int)floor($v / 100);
        if ($ch > 0) {
            return $ch;
        }
    }

    if (preg_match('/(?:ch|channel)[_\-:=\/](\d+)/i', $uri, $m)) {
        return max(1, (int)$m[1]);
    }

    return $fallback;
}

function injectRtspCredentials($uri, $user, $pass)
{
    $uri = trim((string)$uri);
    $user = trim((string)$user);
    $pass = (string)$pass;

    if ($uri === '' || $user === '') {
        return $uri;
    }

    $u = @parse_url($uri);
    if (!is_array($u)) {
        return $uri;
    }

    $scheme = strtolower((string)($u['scheme'] ?? ''));
    if ($scheme !== 'rtsp') {
        return $uri;
    }

    if (!empty($u['user'])) {
        return $uri;
    }

    $host = trim((string)($u['host'] ?? ''));
    if ($host === '') {
        return $uri;
    }

    $auth = rawurlencode($user);
    if ($pass !== '') {
        $auth .= ':' . rawurlencode($pass);
    }

    $rebuilt = 'rtsp://' . $auth . '@' . toHostForUrl($host);

    if (isset($u['port'])) {
        $rebuilt .= ':' . (int)$u['port'];
    }

    $path = (string)($u['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    $rebuilt .= $path;

    if (isset($u['query']) && $u['query'] !== '') {
        $rebuilt .= '?' . $u['query'];
    }
    if (isset($u['fragment']) && $u['fragment'] !== '') {
        $rebuilt .= '#' . $u['fragment'];
    }

    return $rebuilt;
}

function onvifTestConnection($host, $port, $user, $pass, $timeout)
{
    $reach = resolveReachableDeviceService($host, $port, $timeout);
    if (!$reach['success']) {
        if (!empty($reach['non_onvif_hint'])) {
            return [
                'success' => false,
                'msg' => '해당 포트는 ONVIF가 아닙니다. TP-Link REST API 응답이 감지되었습니다. ONVIF 포트(기본 2020 또는 80)를 확인하세요.',
                'xaddr' => (string)($reach['xaddr'] ?? ''),
                'host' => $host,
                'port' => (string)normalizePort($port, 80),
                'debug' => [
                    'device_xaddr' => (string)($reach['xaddr'] ?? ''),
                    'probe_status' => (int)($reach['probe']['status'] ?? 0),
                    'probe_response_flavor' => (string)($reach['probe']['response_flavor'] ?? ''),
                    'non_onvif_hint' => $reach['non_onvif_hint'],
                ],
                '_ver' => ONVIF_API_VER,
            ];
        }
        return $reach;
    }

    $xaddr = $reach['xaddr'];

    $infoRes = onvifSoapRequest(
        $xaddr,
        'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation',
        '<tds:GetDeviceInformation xmlns:tds="http://www.onvif.org/ver10/device/wsdl"/>',
        $user,
        $pass,
        $timeout
    );

    $nonOnvifHint = detectNonOnvifHint($infoRes['body'] ?? '', $port);
    if (!empty($nonOnvifHint)) {
        return [
            'success' => false,
            'msg' => '해당 포트는 ONVIF가 아닙니다. TP-Link REST API 응답이 감지되었습니다. ONVIF 포트(기본 2020 또는 80)를 확인하세요.',
            'xaddr' => $xaddr,
            'host' => $host,
            'port' => (string)normalizePort($port, 80),
            'debug' => [
                'device_xaddr' => $xaddr,
                'probe_status' => (int)($reach['probe']['status'] ?? 0),
                'info_status' => (int)($infoRes['status'] ?? 0),
                'info_response_flavor' => (string)($infoRes['response_flavor'] ?? ''),
                'non_onvif_hint' => $nonOnvifHint,
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    if ($infoRes['ok']) {
        $info = parseDeviceInfo($infoRes['body']);
        return [
            'success' => true,
            'msg' => '연결 성공',
            'xaddr' => $xaddr,
            'host' => $host,
            'port' => (string)normalizePort($port, 80),
            'manufacturer' => $info['manufacturer'] ?? '',
            'model' => $info['model'] ?? '',
            'firmware' => $info['firmware'] ?? '',
            'serial' => $info['serial'] ?? '',
            'hardware' => $info['hardware'] ?? '',
            'channel_api' => 'todo=channels',
            '_ver' => ONVIF_API_VER,
        ];
    }

    if (hasAuthError($infoRes['status'], $infoRes['body'])) {
        if ($user === '' && $pass === '') {
            return [
                'success' => false,
                'msg' => '장치 응답은 확인했지만 인증이 필요합니다.',
                'xaddr' => $xaddr,
                'auth_required' => true,
                '_ver' => ONVIF_API_VER,
            ];
        }

        return [
            'success' => false,
            'msg' => '장치 응답은 확인했지만 계정 인증에 실패했습니다.',
            'xaddr' => $xaddr,
            'auth_required' => true,
            '_ver' => ONVIF_API_VER,
        ];
    }

    return [
        'success' => true,
        'msg' => '연결 성공 (장치 정보 일부 미수신)',
        'xaddr' => $xaddr,
        'host' => $host,
        'port' => (string)normalizePort($port, 80),
        'channel_api' => 'todo=channels',
        '_ver' => ONVIF_API_VER,
    ];
}

function onvifGetChannels($host, $port, $user, $pass, $timeout)
{
    $reach = resolveReachableDeviceService($host, $port, $timeout);
    if (!$reach['success']) {
        if (!empty($reach['non_onvif_hint'])) {
            return [
                'success' => false,
                'msg' => '채널 조회 실패 (non_onvif_port): 해당 포트는 ONVIF media service가 아니며 TP-Link REST API로 보입니다. ONVIF 포트(기본 2020 또는 80)를 확인하세요.',
                'xaddr' => (string)($reach['xaddr'] ?? ''),
                'debug' => [
                    'device_xaddr' => (string)($reach['xaddr'] ?? ''),
                    'raw_media_xaddrs' => [],
                    'media_candidates' => [],
                    'attempts' => [],
                    'probe_status' => (int)($reach['probe']['status'] ?? 0),
                    'probe_response_flavor' => (string)($reach['probe']['response_flavor'] ?? ''),
                    'non_onvif_hint' => $reach['non_onvif_hint'],
                ],
                '_ver' => ONVIF_API_VER,
            ];
        }
        return $reach;
    }

    $deviceXaddr = $reach['xaddr'];

    $capRes = onvifSoapRequest(
        $deviceXaddr,
        'http://www.onvif.org/ver10/device/wsdl/GetCapabilities',
        '<tds:GetCapabilities xmlns:tds="http://www.onvif.org/ver10/device/wsdl"><tds:Category>All</tds:Category></tds:GetCapabilities>',
        $user,
        $pass,
        $timeout
    );

    if (!$capRes['ok'] && hasAuthError($capRes['status'], $capRes['body'])) {
        if ($user === '' && $pass === '') {
            return ['success' => false, 'msg' => '채널 조회를 위해 ONVIF 계정 인증이 필요합니다.', 'auth_required' => true, 'xaddr' => $deviceXaddr, '_ver' => ONVIF_API_VER];
        }
        return ['success' => false, 'msg' => 'ONVIF 계정 인증 실패로 채널 조회에 실패했습니다.', 'auth_required' => true, 'xaddr' => $deviceXaddr, '_ver' => ONVIF_API_VER];
    }

    $capNonOnvifHint = detectNonOnvifHint($capRes['body'] ?? '', $port);
    if (!empty($capNonOnvifHint)) {
        return [
            'success' => false,
            'msg' => '채널 조회 실패 (non_onvif_port): 해당 포트는 ONVIF media service가 아니며 TP-Link REST API로 보입니다. ONVIF 포트(기본 2020 또는 80)를 확인하세요.',
            'xaddr' => $deviceXaddr,
            'debug' => [
                'device_xaddr' => $deviceXaddr,
                'raw_media_xaddrs' => [],
                'media_candidates' => [],
                'attempts' => [],
                'non_onvif_hint' => $capNonOnvifHint,
            ],
            '_ver' => ONVIF_API_VER,
        ];
    }

    $rawMedia = $capRes['ok'] ? parseMediaServiceXaddrs($capRes['body']) : [];
    $mediaXaddrs = buildMediaServiceCandidates($host, $port, $deviceXaddr, $rawMedia);

    $channels = [];
    $usedMediaXaddr = '';
    $lastErr = '채널 프로파일을 찾지 못했습니다.';
    $lastStage = 'profiles_v10+v20 모두 실패';
    $debug = [
        'device_xaddr' => $deviceXaddr,
        'raw_media_xaddrs' => $rawMedia,
        'media_candidates' => $mediaXaddrs,
        'attempts' => [],
    ];

    foreach ($mediaXaddrs as $mediaXaddr) {
        $profiles = [];
        $mediaVersion = '10';
        $attempt = [
            'url' => $mediaXaddr,
            'v10_status' => 0,
            'v10_ok' => false,
            'v20_status' => 0,
            'v20_ok' => false,
            'v10_body' => '',
            'v20_body' => '',
        ];

        $profilesRes10 = onvifSoapRequest(
            $mediaXaddr,
            'http://www.onvif.org/ver10/media/wsdl/GetProfiles',
            '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>',
            $user,
            $pass,
            $timeout
        );
        $attempt['v10_status'] = (int)($profilesRes10['status'] ?? 0);
        $attempt['v10_ok'] = !empty($profilesRes10['ok']);
        $attempt['v10_body'] = clipDebugText($profilesRes10['body'] ?? '', 500);

        if ($profilesRes10['ok']) {
            $profiles = parseProfiles($profilesRes10['body']);
            $mediaVersion = '10';
        } elseif (hasAuthError($profilesRes10['status'], $profilesRes10['body'])) {
            $debug['attempts'][] = $attempt;
            if ($user === '' && $pass === '') {
                return ['success' => false, 'msg' => '채널 조회를 위해 ONVIF 계정 인증이 필요합니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
            }
            return ['success' => false, 'msg' => 'ONVIF 계정 인증 실패로 채널 조회에 실패했습니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
        }

        if (empty($profiles)) {
            $profilesRes20 = onvifSoapRequest(
                $mediaXaddr,
                'http://www.onvif.org/ver20/media/wsdl/GetProfiles',
                '<tr2:GetProfiles xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"/>',
                $user,
                $pass,
                $timeout
            );
            $attempt['v20_status'] = (int)($profilesRes20['status'] ?? 0);
            $attempt['v20_ok'] = !empty($profilesRes20['ok']);
            $attempt['v20_body'] = clipDebugText($profilesRes20['body'] ?? '', 500);

            if ($profilesRes20['ok']) {
                $profiles = parseProfiles($profilesRes20['body']);
                $mediaVersion = '20';
            } elseif (hasAuthError($profilesRes20['status'], $profilesRes20['body'])) {
                $debug['attempts'][] = $attempt;
                if ($user === '' && $pass === '') {
                    return ['success' => false, 'msg' => '채널 조회를 위해 ONVIF 계정 인증이 필요합니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
                }
                return ['success' => false, 'msg' => 'ONVIF 계정 인증 실패로 채널 조회에 실패했습니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
            } elseif ($profilesRes20['err_msg'] !== '') {
                $lastErr = $profilesRes20['err_msg'];
                $lastStage = 'profiles_v20';
            } elseif ($profilesRes20['status'] > 0) {
                $lastErr = 'HTTP ' . $profilesRes20['status'];
                $lastStage = 'profiles_v10+v20 모두 실패';
            }
        }

        if (!$profilesRes10['ok']) {
            if ($profilesRes10['err_msg'] !== '') {
                $lastErr = $profilesRes10['err_msg'];
                $lastStage = 'profiles_v10';
            } elseif ($profilesRes10['status'] > 0 && empty($profiles)) {
                $lastErr = 'HTTP ' . $profilesRes10['status'];
                $lastStage = 'profiles_v10';
            }
        }

        $debug['attempts'][] = $attempt;

        if (empty($profiles)) {
            continue;
        }

        $usedMediaXaddr = $mediaXaddr;

        foreach ($profiles as $i => $profile) {
            $token = trim((string)($profile['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $streamUri = '';

            $streamAction = ($mediaVersion === '20')
                ? 'http://www.onvif.org/ver20/media/wsdl/GetStreamUri'
                : 'http://www.onvif.org/ver10/media/wsdl/GetStreamUri';

            $streamBody = buildGetStreamUriBody($token, $mediaVersion);

            $streamRes = onvifSoapRequest($mediaXaddr, $streamAction, $streamBody, $user, $pass, $timeout);
            if ($streamRes['ok']) {
                $streamUri = parseStreamUri($streamRes['body']);
            } elseif (hasAuthError($streamRes['status'], $streamRes['body'])) {
                if ($user === '' && $pass === '') {
                    return ['success' => false, 'msg' => 'RTSP URL 조회를 위해 ONVIF 계정 인증이 필요합니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
                }
                return ['success' => false, 'msg' => 'ONVIF 계정 인증 실패로 RTSP URL 조회에 실패했습니다.', 'auth_required' => true, 'xaddr' => $mediaXaddr, 'debug' => $debug, '_ver' => ONVIF_API_VER];
            } elseif ($streamRes['err_msg'] !== '') {
                $lastErr = $streamRes['err_msg'];
                $lastStage = 'stream_uri_v' . $mediaVersion;
            } elseif ($streamRes['status'] > 0) {
                $lastErr = 'HTTP ' . $streamRes['status'];
                $lastStage = 'stream_uri_v' . $mediaVersion;
            }

            if ($streamUri === '') {
                $altVersion = ($mediaVersion === '20') ? '10' : '20';
                $altAction = ($altVersion === '20')
                    ? 'http://www.onvif.org/ver20/media/wsdl/GetStreamUri'
                    : 'http://www.onvif.org/ver10/media/wsdl/GetStreamUri';
                $altBody = buildGetStreamUriBody($token, $altVersion);
                $altRes = onvifSoapRequest($mediaXaddr, $altAction, $altBody, $user, $pass, $timeout);
                if ($altRes['ok']) {
                    $streamUri = parseStreamUri($altRes['body']);
                } elseif ($altRes['err_msg'] !== '') {
                    $lastErr = $altRes['err_msg'];
                    $lastStage = 'stream_uri_v' . $altVersion;
                } elseif ($altRes['status'] > 0) {
                    $lastErr = 'HTTP ' . $altRes['status'];
                    $lastStage = 'stream_uri_v' . $altVersion;
                }
            }

            if ($streamUri === '') {
                $streamUri = makeDefaultRtsp($host);
            }

            $streamUriAuth = injectRtspCredentials($streamUri, $user, $pass);

            $channelNo = extractChannelNumber($streamUri, count($channels) + 1);
            $name = trim((string)($profile['name'] ?? ''));
            if ($name === '') {
                $name = 'CH' . str_pad((string)$channelNo, 2, '0', STR_PAD_LEFT);
            }

            $width = (int)($profile['width'] ?? 0);
            $height = (int)($profile['height'] ?? 0);
            $resolution = ($width > 0 && $height > 0) ? ($width . 'x' . $height) : '';

            $channels[] = [
                'idx' => count($channels) + 1,
                'channel_no' => $channelNo,
                'name' => $name,
                'token' => $token,
                'rtsp' => $streamUriAuth,
                'rtsp_raw' => $streamUri,
                'encoding' => (string)($profile['encoding'] ?? ''),
                'resolution' => $resolution,
                'fps' => (float)($profile['fps'] ?? 0),
            ];
        }

        if (!empty($channels)) {
            break;
        }
    }

    if (empty($channels)) {
        return [
            'success' => false,
            'msg' => '채널 조회 실패 (' . $lastStage . '): ' . $lastErr,
            'xaddr' => $deviceXaddr,
            'debug' => $debug,
            '_ver' => ONVIF_API_VER,
        ];
    }

    usort($channels, function ($a, $b) {
        $ca = (int)($a['channel_no'] ?? 0);
        $cb = (int)($b['channel_no'] ?? 0);
        if ($ca === $cb) {
            return ((int)$a['idx']) <=> ((int)$b['idx']);
        }
        return $ca <=> $cb;
    });

    return [
        'success' => true,
        'msg' => '채널 ' . count($channels) . '개 조회 성공',
        'xaddr_device' => $deviceXaddr,
        'xaddr_media' => $usedMediaXaddr,
        'count' => count($channels),
        'channels' => $channels,
        '_ver' => ONVIF_API_VER,
    ];
}

function buildDiscoveryProbe()
{
    $uuid = 'uuid:' . sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"'
        . ' xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
        . ' xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"'
        . ' xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
        . '<e:Header>'
        . '<w:MessageID>' . $uuid . '</w:MessageID>'
        . '<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
        . '<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
        . '</e:Header>'
        . '<e:Body>'
        . '<d:Probe>'
        . '<d:Types>dn:NetworkVideoTransmitter</d:Types>'
        . '</d:Probe>'
        . '</e:Body>'
        . '</e:Envelope>';
}

function onvifDiscoverDevices($timeoutSec)
{
    $addr = 'udp://' . ONVIF_DISCOVERY_ADDR . ':' . ONVIF_DISCOVERY_PORT;
    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client($addr, $errno, $errstr, 2, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return [
            'success' => false,
            'msg' => '검색 소켓 생성 실패: ' . ($errstr ?: 'unknown error'),
        ];
    }

    @stream_set_blocking($socket, false);

    $probe = buildDiscoveryProbe();
    $written = @fwrite($socket, $probe);
    if ($written === false || $written <= 0) {
        fclose($socket);
        return [
            'success' => false,
            'msg' => '검색 패킷 전송 실패',
        ];
    }

    $devices = [];
    $endAt = microtime(true) + max(1, (int)$timeoutSec);

    while (microtime(true) < $endAt) {
        $read = [$socket];
        $write = null;
        $except = null;

        $changed = @stream_select($read, $write, $except, 0, 250000);
        if ($changed === false) {
            break;
        }
        if ($changed === 0) {
            continue;
        }

        $peer = '';
        $buf = @stream_socket_recvfrom($socket, 65535, 0, $peer);
        if ($buf === false || $buf === '') {
            continue;
        }

        $item = parseDiscoveryPacket($buf, $peer);
        if (!$item || empty($item['ip'])) {
            continue;
        }

        $key = strtolower((string)$item['ip']);
        if (!isset($devices[$key])) {
            $devices[$key] = $item;
        }
    }

    fclose($socket);

    return [
        'success' => true,
        'count' => count($devices),
        'devices' => array_values($devices),
    ];
}

$ctx = onvifCurrentUserContext();
if (!onvifHasOwnerContext($ctx)) {
    ApiResponse::error('TENANT_REQUIRED', '테넌트 정보가 필요합니다.', 403);
    exit;
}

$cameraCrudTodos = [
    'camera_upsert',
    'cam_save',
    'camera_delete',
    'cam_delete',
    'camera_bulk_upsert',
    'cam_bulk_save',
    'camera_replace',
    'cam_replace',
];
$writePostTodos = array_merge($cameraCrudTodos, ['record_start', 'record_stop']);
$adminOnlyTodos = array_merge($cameraCrudTodos, ['ptz', 'snapshot', 'record_start', 'record_stop']);

if (in_array($todo, $writePostTodos, true)) {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
        exit;
    }
    if (!$auth->validateCsrf()) {
        ApiResponse::error('CSRF_TOKEN_INVALID', '보안 토큰이 유효하지 않습니다', 403);
        exit;
    }
}

if (in_array($todo, $adminOnlyTodos, true)) {
    onvifRequireAdmin($ctx);
}

switch ($todo) {
    case 'camera_list':
    case 'cam_list':
        try {
            $db = DbConnection::get();
            onvifEnsureCameraTable($db);
            $ctx = onvifCurrentUserContext();
            if (!onvifHasOwnerContext($ctx)) {
                jsonOut(['success' => false, 'error' => 'TENANT_REQUIRED', 'msg' => '테넌트 정보를 확인할 수 없습니다.'], 401);
            }
            $ownerFix = onvifNormalizeLegacyOwnerRows($db, $ctx);

            $pickedRows = onvifFetchOwnerCameraRows($db, $ctx);
            $items = onvifRowsToCameraItems($pickedRows);

            jsonOut([
                'success' => true,
                'count' => count($items),
                'items' => $items,
                'source' => 'db',
                'owner_fix' => $ownerFix,
                '_ver' => ONVIF_API_VER,
            ]);
        } catch (Throwable $e) {
            onvifApiException('camera_list', $e, '카메라 목록 조회에 실패했습니다.');
        }
        break;

    case 'camera_export':
    case 'cam_export':
    case 'camera_import':
    case 'cam_import':
        ApiResponse::error('NOT_SUPPORTED', '해당 기능은 V2에서 지원하지 않습니다', 400);
        exit;

    case 'camera_upsert':
    case 'cam_save':
        try {
            $ctx = onvifCurrentUserContext();
            if (!onvifHasOwnerContext($ctx)) {
                jsonOut(['success' => false, 'error' => 'TENANT_REQUIRED', 'msg' => '테넌트 정보를 확인할 수 없습니다.'], 401);
            }

            $body = onvifReadJsonBody();
            $raw = is_array($body) && !empty($body) ? $body : $_REQUEST;
            if (isset($raw['camera']) && is_array($raw['camera'])) {
                $raw = $raw['camera'];
            }

            $cam = onvifNormalizeCameraPayload((array)$raw);
            $cam['status'] = onvifSafeStatus($cam['status'] ?? 'unknown');
            $err = '';
            if (!onvifValidateCameraPayload($cam, $err)) {
                jsonOut(['success' => false, 'msg' => $err, '_ver' => ONVIF_API_VER], 400);
            }

            $db = DbConnection::get();
            onvifEnsureCameraTable($db);
            onvifNormalizeLegacyOwnerRows($db, $ctx, (string)$cam['camera_id']);
            $storedCredential = onvifUpsertCameraRow($db, $ctx, $cam);
            jsonOut([
                'success' => true,
                'msg' => '카메라 저장 완료',
                'item' => onvifDbRowToCamera([
                    'camera_id' => $cam['camera_id'],
                    'name' => $cam['name'],
                    'channel' => $cam['channel'],
                    'ip' => $cam['ip'],
                    'port' => $cam['port'],
                    'login_user' => $cam['login_user'],
                    'login_pass' => (string)($storedCredential['login_pass'] ?? ''),
                    'login_pass_encrypted' => (int)($storedCredential['login_pass_encrypted'] ?? 0),
                    'memo' => $cam['memo'],
                    'conn_method' => $cam['conn_method'],
                    'manufacturer' => $cam['manufacturer'],
                    'rtsp_port' => $cam['rtsp_port'],
                    'default_stream' => $cam['default_stream'],
                    'is_ptz' => $cam['is_ptz'],
                    'rtsp_main' => $cam['rtsp_main'],
                    'rtsp_sub' => $cam['rtsp_sub'],
                    'status' => $cam['status'],
                    'snapshot' => $cam['snapshot'],
                    'created_at' => ($cam['created_at'] !== '' ? $cam['created_at'] : date('Y-m-d H:i:s')),
                ]),
                '_ver' => ONVIF_API_VER,
            ]);
        } catch (Throwable $e) {
            onvifApiException('camera_upsert', $e, '카메라 저장에 실패했습니다.');
        }
        break;

    case 'camera_delete':
    case 'cam_delete':
        try {
            $ctx = onvifCurrentUserContext();
            if (!onvifHasOwnerContext($ctx)) {
                jsonOut(['success' => false, 'error' => 'TENANT_REQUIRED', 'msg' => '테넌트 정보를 확인할 수 없습니다.'], 401);
            }
            $camId = trim((string)($_REQUEST['id'] ?? $_REQUEST['camera_id'] ?? ''));
            $camId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $camId);
            if ($camId === '') jsonOut(['success' => false, 'error' => 'INVALID_INPUT', 'msg' => 'camera_id가 필요합니다.'], 422);

            $db = DbConnection::get();
            onvifEnsureCameraTable($db);
            onvifNormalizeLegacyOwnerRows($db, $ctx, $camId);
            $params = [];
            $whereOwner = onvifOwnerWhereClause($ctx, $params);
            $sql = "UPDATE Tb_OnvifCameras SET is_deleted=1, updated_at=GETDATE()
                    WHERE {$whereOwner} AND camera_id=?";
            $params[] = $camId;
            $st = $db->prepare($sql);
            $st->execute($params);

            jsonOut([
                'success' => true,
                'msg' => '카메라 삭제 완료',
                'affected' => (int)$st->rowCount(),
                '_ver' => ONVIF_API_VER,
            ]);
        } catch (Throwable $e) {
            onvifApiException('camera_delete', $e, '카메라 삭제에 실패했습니다.');
        }
        break;

    case 'camera_bulk_upsert':
    case 'cam_bulk_save':
    case 'camera_replace':
    case 'cam_replace':
        try {
            $ctx = onvifCurrentUserContext();
            if (!onvifHasOwnerContext($ctx)) {
                jsonOut(['success' => false, 'error' => 'TENANT_REQUIRED', 'msg' => '테넌트 정보를 확인할 수 없습니다.'], 401);
            }

            $body = onvifReadJsonBody();
            $replaceMode = ($todo === 'camera_replace' || $todo === 'cam_replace');
            if (isset($_REQUEST['replace'])) {
                $replaceMode = onvifBoolFlag($_REQUEST['replace']);
            } elseif (isset($body['replace'])) {
                $replaceMode = onvifBoolFlag($body['replace']);
            }

            $rawList = onvifExtractCameraArrayFromPayload($body);
            if (!is_array($rawList)) {
                $rawList = onvifExtractCameraArrayFromPayload($_REQUEST);
            }
            if (!is_array($rawList)) $rawList = [];
            if (count($rawList) > 1000) {
                jsonOut(['success' => false, 'msg' => '카메라 일괄 저장은 최대 1000건까지 가능합니다.', '_ver' => ONVIF_API_VER], 400);
            }

            $allowEmpty = false;
            if (isset($_REQUEST['allow_empty'])) {
                $allowEmpty = onvifBoolFlag($_REQUEST['allow_empty']);
            } elseif (isset($body['allow_empty'])) {
                $allowEmpty = onvifBoolFlag($body['allow_empty']);
            }

            $bulkStats = [];
            $valid = onvifBuildBulkCameraList($rawList, $bulkStats);
            if ($replaceMode && empty($valid) && !$allowEmpty) {
                jsonOut([
                    'success' => false,
                    'msg' => 'camera_replace는 유효한 카메라 목록이 필요합니다. 전체 삭제가 필요하면 allow_empty=1을 함께 전달하세요.',
                    'replace' => true,
                    'allow_empty' => false,
                    'stats' => $bulkStats,
                    '_ver' => ONVIF_API_VER,
                ], 400);
            }

            $db = DbConnection::get();
            onvifEnsureCameraTable($db);
            onvifNormalizeLegacyOwnerRows($db, $ctx);
            $db->beginTransaction();
            if ($replaceMode) {
                $params = [];
                $whereOwner = onvifOwnerWhereClause($ctx, $params);
                $db->prepare("UPDATE Tb_OnvifCameras SET is_deleted=1, updated_at=GETDATE() WHERE {$whereOwner}")
                   ->execute($params);
            }
            foreach ($valid as $cam) {
                onvifUpsertCameraRow($db, $ctx, $cam);
            }
            $db->commit();

            jsonOut([
                'success' => true,
                'msg' => '카메라 일괄 저장 완료',
                'replace' => $replaceMode,
                'allow_empty' => $allowEmpty,
                'saved' => count($valid),
                'stats' => $bulkStats,
                '_ver' => ONVIF_API_VER,
            ]);
        } catch (Throwable $e) {
            try {
                if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
            } catch (Throwable $ignore) {}
            onvifApiException('camera_bulk', $e, '카메라 일괄 저장에 실패했습니다.');
        }
        break;

    case 'stream_url':
        $streamId = sanitizeStreamId(reqStr('stream_id', reqStr('camera_id', reqStr('name', 'camera'))), 'camera');
        $src = trim((string)($_REQUEST['src'] ?? $_REQUEST['rtsp'] ?? $_REQUEST['rtsp_url'] ?? ''));
        jsonOut(registerGo2rtcStream($streamId, $src));
        break;

    case 'record_start':
        $streamId = sanitizeStreamId(reqStr('stream_id', reqStr('camera_id', 'camera')), 'camera');
        $rtspUrl = trim((string)($_REQUEST['rtsp'] ?? $_REQUEST['rtsp_url'] ?? ''));
        $durationSec = clampInt(reqInt('duration_sec', reqInt('duration', 0)), 0, 86400, 0);
        $qualityHeight = clampInt(reqInt('height', 720), 240, 2160, 720);
        jsonOut(startRecordingJob($streamId, $rtspUrl, $durationSec, $qualityHeight));
        break;

    case 'record_stop':
        $recordId = sanitizeRecordId(reqStr('record_id', ''));
        $pid = reqInt('pid', 0);
        jsonOut(stopRecordingJob($recordId, $pid));
        break;

    case 'record_list':
        $month = reqStr('month', '');
        jsonOut(listRecordingFiles($month));
        break;

    case 'record_download':
        $recordId = sanitizeRecordId(reqStr('record_id', ''));
        $month = reqStr('month', '');
        $fileName = reqStr('file', reqStr('file_name', ''));
        if (!downloadRecordingFile($recordId, $month, $fileName)) {
            jsonOut([
                'success' => false,
                'msg' => '녹화 파일을 찾을 수 없습니다.',
                '_ver' => ONVIF_API_VER,
            ], 404);
        }
        break;

    case 'snapshot':
        $streamId = sanitizeStreamId(reqStr('stream_id', reqStr('camera_id', 'camera')), 'camera');
        $rtspUrl = trim((string)($_REQUEST['rtsp'] ?? $_REQUEST['rtsp_url'] ?? $_REQUEST['src'] ?? ''));
        $snapshotResult = captureSnapshotImage($streamId, $rtspUrl);
        if (is_array($snapshotResult)) {
            jsonOut($snapshotResult, 500);
        }
        break;

    case 'ptz':
        $host = sanitizeHost(reqStr('ip'));
        $port = normalizePort(reqInt('port', 80), 80);
        $dir = strtolower(reqStr('dir', ''));
        if ($host === '') {
            jsonOut(['success' => false, 'error' => 'INVALID_INPUT', 'msg' => 'IP 주소를 입력하세요.'], 422);
        }
        if (!in_array($dir, ['up', 'down', 'left', 'right', 'upleft', 'upright', 'downleft', 'downright', 'zoomin', 'zoomout', 'stop'], true)) {
            jsonOut(['success' => false, 'error' => 'INVALID_INPUT', 'msg' => '유효하지 않은 PTZ 방향입니다.'], 422);
        }
        jsonOut([
            'success' => true,
            'msg' => 'PTZ 명령이 접수되었습니다.',
            'executed' => false,
            'dir' => $dir,
            'ip' => $host,
            'port' => $port,
        ]);
        break;

    case 'tcp_check':
        $host = sanitizeHost(reqStr('ip'));
        $port = normalizePort(reqInt('port', 554), 554);
        $timeout = clampInt(reqInt('timeout', 3), 1, 15, 3);
        $user = reqStr('user');
        $pass = (string)($_REQUEST['pass'] ?? '');
        $rtspPath = reqStr('rtsp_path', reqStr('path', reqStr('stream_path', 'ch1/sub/av_stream')));
        $wantsAuthCheck = onvifBoolFlag($_REQUEST['auth'] ?? '0')
            || $user !== ''
            || array_key_exists('pass', $_REQUEST);

        if ($wantsAuthCheck) {
            jsonOut(rtspAuthCheck($host, $port, $user, $pass, $timeout, $rtspPath));
        }
        jsonOut(tcpPortCheck($host, $port, $timeout));
        break;

    case 'rtsp_auth_check':
        $host = sanitizeHost(reqStr('ip'));
        $port = normalizePort(reqInt('port', 554), 554);
        $user = reqStr('user');
        $pass = (string)($_REQUEST['pass'] ?? '');
        $timeout = clampInt(reqInt('timeout', 5), 1, 15, 5);
        $rtspPath = reqStr('rtsp_path', reqStr('path', reqStr('stream_path', 'ch1/sub/av_stream')));
        jsonOut(rtspAuthCheck($host, $port, $user, $pass, $timeout, $rtspPath));
        break;

    case 'test':
        $host = sanitizeHost(reqStr('ip'));
        $port = normalizePort(reqInt('port', 80), 80);
        $user = reqStr('user');
        $pass = (string)($_REQUEST['pass'] ?? '');
        $timeout = clampInt(reqInt('timeout', ONVIF_DEFAULT_TIMEOUT), 1, 15, ONVIF_DEFAULT_TIMEOUT);

        if ($host === '') {
            jsonOut(['success' => false, 'error' => 'INVALID_INPUT', 'msg' => 'IP 주소를 입력하세요.'], 422);
        }

        jsonOut(onvifTestConnection($host, $port, $user, $pass, $timeout));
        break;

    case 'channels':
    case 'list_channels':
        $host = sanitizeHost(reqStr('ip'));
        $port = normalizePort(reqInt('port', 80), 80);
        $user = reqStr('user');
        $pass = (string)($_REQUEST['pass'] ?? '');
        $timeout = clampInt(reqInt('timeout', 6), 2, 20, 6);

        if ($host === '') {
            jsonOut(['success' => false, 'error' => 'INVALID_INPUT', 'msg' => 'IP 주소를 입력하세요.'], 422);
        }

        jsonOut(onvifGetChannels($host, $port, $user, $pass, $timeout));
        break;

    case 'discover':
        $timeout = clampInt(reqInt('timeout', ONVIF_DISCOVERY_TIMEOUT), 1, 10, ONVIF_DISCOVERY_TIMEOUT);
        jsonOut(onvifDiscoverDevices($timeout));
        break;

    default:
        jsonOut(['success' => false, 'error' => 'UNSUPPORTED_TODO', 'msg' => '지원하지 않는 요청입니다.'], 400);
}

