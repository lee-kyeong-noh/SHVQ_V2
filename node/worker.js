/**
 * SHVQ V2 — IMAP IDLE + WebSocket 실시간 메일 알림 서버
 *
 * 아키텍처:
 *   Tb_IntProviderAccount (provider='mail') → IMAP IDLE 연결 (계정당 1개)
 *   새 메일 감지 → Tb_Mail_MessageCache INSERT → WS broadcast
 *   브라우저 → wss://shvq.kr:2347/?token=XXX → 실시간 알림 수신 (SSL)
 *
 * V2 변경사항 (V1 대비):
 *   - DB: CSM_C004732_V2
 *   - 계정 테이블: Tb_IntProviderAccount (account_idx, user_pk)
 *   - 자격증명: Tb_IntCredential (secret_type/secret_value_enc) + raw_json 폴백
 *   - 메시지 캐시 컬럼: account_idx (V1: account_id)
 *   - WS 포트: 2347 (V1: 2346)
 *
 * 실행: node worker.js [--verbose]
 * PM2:  pm2 start ecosystem.config.js
 */

'use strict';

const { ImapFlow } = require('imapflow');
const sql          = require('mssql');
const { WebSocketServer } = require('ws');
const Redis        = require('ioredis');
const https        = require('https');
const http         = require('http');
const crypto       = require('crypto');
const url          = require('url');
const fs           = require('fs');
const path         = require('path');
const { createFcmNotifier } = require('./fcm');

// ══════════════════════════════════════
// 환경변수 헬퍼
// ══════════════════════════════════════
function requireEnv(name, fallback) {
    const value = process.env[name];
    if (value !== undefined && value !== '') return value;
    if (fallback !== undefined) return fallback;
    throw new Error(`Missing required environment variable: ${name}`);
}

function requireIntEnv(name, fallback) {
    const value = requireEnv(name, fallback);
    const parsed = parseInt(value, 10);
    if (!Number.isFinite(parsed)) throw new Error(`Invalid integer env var: ${name}`);
    return parsed;
}

// ══════════════════════════════════════
// 설정
// ══════════════════════════════════════
const CONFIG = {
    db: {
        server:   requireEnv('SHV_MAIL_DB_SERVER'),
        port:     requireIntEnv('SHV_MAIL_DB_PORT', '1433'),
        user:     requireEnv('SHV_MAIL_DB_USER'),
        password: requireEnv('SHV_MAIL_DB_PASSWORD'),
        database: requireEnv('SHV_MAIL_DB_NAME', 'CSM_C004732_V2'),
        options:  { encrypt: false, trustServerCertificate: true },
        pool:     { max: 50, min: 5, idleTimeoutMillis: 30000 },
    },
    ws: {
        port: 2347,
    },
    redis: {
        host: requireEnv('REDIS_HOST', '127.0.0.1'),
        port: requireIntEnv('REDIS_PORT', '6379'),
    },
    broadcastSecret:     requireEnv('BROADCAST_SECRET', ''),
    accountPollInterval: 5 * 60 * 1000,
    verbose:             process.argv.includes('--verbose'),
};

// ══════════════════════════════════════
// 자격증명 복호화 (AES-256-CBC + HMAC-SHA256)
// PHP MailboxService::decryptSecretValue() 와 동일 로직
// ══════════════════════════════════════
let _credKey = null;

function getCredentialKey() {
    if (_credKey) return _credKey;
    const candidates = [
        process.env['MAIL_CREDENTIAL_KEY'],
        process.env['INT_CREDENTIAL_KEY'],
        process.env['APP_KEY'],
        process.env['SECRET_KEY'],
    ];
    for (const c of candidates) {
        const s = (c || '').trim();
        if (s) {
            _credKey = crypto.createHash('sha256').update(s).digest();
            return _credKey;
        }
    }
    return null;
}

function base64UrlDecode(str) {
    const base64 = str.replace(/-/g, '+').replace(/_/g, '/');
    const pad = base64.length % 4;
    const padded = pad ? base64 + '='.repeat(4 - pad) : base64;
    return Buffer.from(padded, 'base64');
}

function decryptSecretValue(value) {
    const s = String(value || '').trim();
    if (!s) return '';
    const prefix = 'enc:v1:';
    if (!s.startsWith(prefix)) return s; // 평문 폴백

    const key = getCredentialKey();
    if (!key) return '';

    try {
        const raw = base64UrlDecode(s.slice(prefix.length));
        if (raw.length <= 48) return '';
        const iv        = raw.slice(0, 16);
        const mac       = raw.slice(16, 48);
        const cipherRaw = raw.slice(48);

        const expectedMac = crypto.createHmac('sha256', key).update(Buffer.concat([iv, cipherRaw])).digest();
        if (!crypto.timingSafeEqual(mac, expectedMac)) return '';

        const decipher = crypto.createDecipheriv('aes-256-cbc', key, iv);
        const plain    = Buffer.concat([decipher.update(cipherRaw), decipher.final()]);
        return plain.toString('utf8');
    } catch (e) {
        return '';
    }
}

// ══════════════════════════════════════
// 로깅
// ══════════════════════════════════════
function ts()           { return new Date().toISOString().replace('T',' ').substring(0,19); }
function log(m, ...a)  { console.log(`[${ts()}] ${m}`, ...a); }
function warn(m, ...a) { console.warn(`[${ts()}] ⚠ ${m}`, ...a); }
function err(m, ...a)  { console.error(`[${ts()}] ✖ ${m}`, ...a); }
function dbg(m, ...a)  { if (CONFIG.verbose) console.log(`[${ts()}] 🔍 ${m}`, ...a); }

function maskToken(token) {
    const s = String(token || '').trim();
    return s ? `${s.slice(0,8)}...` : '';
}

function normalizeIp(rawIp) { return String(rawIp||'').split(',')[0].trim(); }
function isLocalhostIp(ip)  { const n = normalizeIp(ip); return n === '127.0.0.1' || n === '::1' || n === '::ffff:127.0.0.1'; }

// ══════════════════════════════════════
// 글로벌 상태
// ══════════════════════════════════════
let dbPool       = null;
let wss          = null;
let httpServer   = null;
let wsHeartbeatTimer     = null;
let sseHeartbeatTimer    = null;
let debounceCleanupTimer = null;
let imapWatchdogTimer    = null;
let fetchQueueTimer      = null;
let idleOwnerLockTimer   = null;

const redisPublisher = new Redis({
    host: CONFIG.redis.host, port: CONFIG.redis.port,
    maxRetriesPerRequest: 3, connectTimeout: 10000, commandTimeout: 10000,
    retryStrategy: (n) => Math.min(100 * (2 ** Math.min(n, 6)), 5000),
    lazyConnect: true,
});
redisPublisher.on('error', (e) => warn('Redis pub 오류: %s', e.message));

const redisSubscriber = redisPublisher.duplicate({ lazyConnect: true });
redisSubscriber.on('error', (e) => warn('Redis sub 오류: %s', e.message));

const imapClients          = new Map(); // account_idx → { client, account, retryCount }
const accountByIdx         = new Map(); // account_idx → account
const wsClients            = new Map(); // userPk       → Set<ws>
const sseClientsById       = new Map(); // sseClientId  → clientMeta
const sseClientsByUser     = new Map(); // userPk       → Set<sseClientId>
const subscriberChannelRefCounts = new Map(); // channel → shared ref count (SSE + WS)
const unreadCacheByAccount = new Map(); // account_idx  → unread count
const accountSyncQueues    = new Map(); // account_idx  → Promise chain
const tokenCache           = new Map(); // token        → { info, cacheUntil, tokenExpireAt }
const reconnectTimersByAccount = new Map(); // account_idx → timeout
const idleStopTimersByAccount  = new Map(); // account_idx → timeout
const fetchPriorityQueue       = [];        // 우선순위 큐(배열)
const fetchPendingKeys         = new Set(); // 중복 enqueue 방지
const fetchProcessingKeys      = new Set(); // 처리중 키
const sseRateByUser            = new Map(); // userPk → { sec, count }
const lastMailActivityByUser   = new Map(); // userPk → timestamp(ms)
const recentNotifyByMailUid    = new Map(); // `${account_idx}:${uid}` → lastNotifiedAt(ms)

let sseSeq = 0;
let _lastRedisWarnAt = 0;
let _idleColumnChecked = false;
let _mailCacheHasBodyHtml = null;
let fetchWorkersRunning = 0;
let fcmNotifier = null;
let idleOwnerLockRenewRunning = false;

const SSE_HEARTBEAT_MS           = 5000;
const SSE_MAX_LIFETIME_MS        = 300000;
const SSE_MAX_CONNECTIONS        = 15000;
const SSE_MAX_CONNECTIONS_PER_USER = 3;
const SSE_MAX_WRITABLE_LENGTH    = 64 * 1024;
const SSE_RETRY_BASE_MS          = 1000;
const SSE_RETRY_JITTER_MS        = 2000;
const TOKEN_CACHE_TTL_MS         = 30000;
const DEBOUNCE_CLEANUP_INTERVAL_MS = 10 * 60 * 1000;
const DEBOUNCE_ENTRY_TTL_MS      = 10 * 60 * 1000;
const MAIL_NOTIFY_DEDUP_WINDOW_MS = 5 * 1000;
const MAIL_NOTIFY_DEDUP_ENTRY_TTL_MS = 30 * 1000;
const REALTIME_PAYLOAD_MAX_BYTES = 64 * 1024;
const BROADCAST_BODY_MAX_BYTES   = 1024 * 1024;
const IMAP_BODY_FETCH_MAX_BYTES  = 2 * 1024 * 1024;
const DB_IN_CLAUSE_CHUNK         = 400;
const DB_INSERT_BATCH            = 50;

const SSE_RATE_LIMIT_PER_SEC     = 10;
const ONLINE_TTL_SECONDS         = 60;
const ONLINE_HEARTBEAT_MS        = 30 * 1000;
const IDLE_ACTIVITY_WINDOW_MS    = 30 * 60 * 1000;
const IDLE_UNREAD_KEEP_MAX       = 200;
const IDLE_RELEASE_GRACE_MS      = 30 * 1000;
const IDLE_OWNER_LOCK_TTL_SECONDS = Math.max(30, requireIntEnv('IDLE_OWNER_LOCK_TTL_SECONDS', '90'));
const IDLE_OWNER_LOCK_RENEW_INTERVAL_MS = Math.max(10000, Math.floor((IDLE_OWNER_LOCK_TTL_SECONDS * 1000) / 3));
const IDLE_OWNER_LOCK_PREFIX      = 'mail:idle:owner:';
const WORKER_INSTANCE_ID          = `${process.pid}:${process.env.pm_id || 'na'}`;
const IMAP_GLOBAL_HARD_CAP       = 800;
const IMAP_FETCH_CONCURRENCY     = Math.max(5, Math.min(10, requireIntEnv('IMAP_FETCH_CONCURRENCY', '8')));
const IMAP_WATCHDOG_IDLE_MS      = 10 * 60 * 1000;
const IMAP_WATCHDOG_CHECK_MS     = 60 * 1000;
const FETCH_RETRY_MAX            = 3;
const FETCH_RETRY_BACKOFF_MS     = [1000, 3000, 10000];
const FETCH_AGING_SCORE_PER_SEC  = 100;
const IMAP_RECONNECT_BACKOFF_MS  = [1000, 3000, 10000, 30000, 60000, 300000];

const CORS_ALLOWED_ORIGINS = new Set([
    'https://shvq.kr',
    'https://www.shvq.kr',
    'http://localhost',
]);

function resolveCorsOrigin(req) {
    const origin = String(req?.headers?.origin || '').trim();
    return CORS_ALLOWED_ORIGINS.has(origin) ? origin : '';
}

function setCorsHeaders(req, res) {
    const origin = resolveCorsOrigin(req);
    if (origin) {
        res.setHeader('Access-Control-Allow-Origin', origin);
        res.setHeader('Vary', 'Origin');
    }
    return origin;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, ms | 0)));
}

function makePreview(htmlLike) {
    return String(htmlLike || '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&[^;]+;/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 200);
}

function sanitizeBodyHtml(htmlLike) {
    let html = String(htmlLike || '');
    if (html === '') return '';

    html = html.replace(/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/gi, '');
    html = html.replace(/<\s*(iframe|object|embed|form)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '');
    html = html.replace(/<\s*(iframe|object|embed|form)\b[^>]*\/?\s*>/gi, '');
    html = html.replace(/<meta\b[^>]*http-equiv\s*=\s*(['"]?)\s*refresh\s*\1[^>]*>/gi, '');
    html = html.replace(/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
    html = html.replace(/\s+(href|src)\s*=\s*(["'])\s*javascript:[\s\S]*?\2/gi, ' $1=$2#$2');
    html = html.replace(/\s+(href|src)\s*=\s*javascript:[^\s>]+/gi, ' $1="#"');
    return html;
}

function resolveBodyPartKeys(bodyStructure) {
    let htmlPart = '';
    let textPart = '';
    const queue = [bodyStructure];

    while (queue.length > 0) {
        const node = queue.shift();
        if (!node || typeof node !== 'object') continue;
        const part = String(node.part || '').trim() || '1';
        const type = String(node.type || '').toLowerCase().trim();
        const children = Array.isArray(node.childNodes) ? node.childNodes : [];

        if (children.length > 0) {
            for (const child of children) queue.push(child);
            continue;
        }

        if (!htmlPart && type === 'text/html') htmlPart = part;
        if (!textPart && type === 'text/plain') textPart = part;

        if (htmlPart && textPart) break;
    }

    return { htmlPart, textPart };
}

async function streamToUtf8(stream) {
    if (!stream || typeof stream.on !== 'function') return '';
    return await new Promise((resolve, reject) => {
        const chunks = [];
        stream.on('data', (chunk) => {
            if (chunk === undefined || chunk === null) return;
            chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk)));
        });
        stream.once('error', reject);
        stream.once('end', () => {
            try {
                resolve(Buffer.concat(chunks).toString('utf8'));
            } catch (_) {
                resolve('');
            }
        });
    });
}

async function downloadBodyPartText(client, uid, partKey) {
    const uidNo = parseInt(uid, 10) || 0;
    const key = String(partKey || '').trim();
    if (!client || !client.usable || uidNo <= 0 || !key) return '';
    try {
        const download = await client.download(String(uidNo), key, {
            uid: true,
            maxBytes: IMAP_BODY_FETCH_MAX_BYTES,
        });
        if (!download || !download.content) return '';
        return await streamToUtf8(download.content);
    } catch (e) {
        dbg('본문 part 다운로드 실패 uid=%d part=%s: %s', uidNo, key, e.message);
        return '';
    }
}

async function hydrateMailHtmlBodies(client, mails) {
    if (!Array.isArray(mails) || mails.length === 0) return;
    for (const mail of mails) {
        if (!mail || typeof mail !== 'object') continue;
        let html = String(mail.bodyHtml || '').trim();
        if (html === '') html = await downloadBodyPartText(client, mail.uid, mail.htmlPart || '');
        if (html === '') html = await downloadBodyPartText(client, mail.uid, mail.textPart || '');

        const safeHtml = sanitizeBodyHtml(html);
        mail.bodyHtml = safeHtml;
        mail.preview = safeHtml !== '' ? safeHtml : String(mail.preview || mail.subject || '');
    }
}

function makeBodyHash(seed) {
    return crypto.createHash('sha256').update(String(seed || '')).digest('hex');
}

function onlineKey(userPk) {
    return `mail:online:${String(userPk || '').trim()}`;
}

function markUserActive(userPk) {
    const key = String(userPk || '').trim();
    if (!key) return;
    lastMailActivityByUser.set(key, Date.now());
}

async function setUserOnlineHeartbeat(userPk) {
    const key = String(userPk || '').trim();
    if (!key) return;
    markUserActive(key);
    try {
        await redisPublisher.set(onlineKey(key), '1', 'EX', ONLINE_TTL_SECONDS);
    } catch (e) {
        dbg('online heartbeat 실패 pk=%s: %s', key, e.message);
    }
}

async function isUserOnline(userPk) {
    const key = String(userPk || '').trim();
    if (!key) return false;
    try {
        return (await redisPublisher.exists(onlineKey(key))) === 1;
    } catch (_) {
        return false;
    }
}

function isUserRecentlyActive(userPk) {
    const key = String(userPk || '').trim();
    if (!key) return false;
    const lastAt = Number(lastMailActivityByUser.get(key) || 0);
    return lastAt > 0 && (Date.now() - lastAt) <= IDLE_ACTIVITY_WINDOW_MS;
}

function normalizeUidList(uidList, fallbackUid = 0) {
    const out = [];
    const seen = new Set();
    const rows = Array.isArray(uidList) ? uidList : [];
    for (const raw of rows) {
        const uid = parseInt(raw, 10);
        if (!Number.isFinite(uid) || uid <= 0 || seen.has(uid)) continue;
        seen.add(uid);
        out.push(uid);
    }
    const fallback = parseInt(fallbackUid, 10);
    if (out.length === 0 && Number.isFinite(fallback) && fallback > 0) out.push(fallback);
    return out;
}

function filterRecentNotifyUids(accountIdx, uidList) {
    const accIdx = parseInt(accountIdx, 10) || 0;
    if (accIdx <= 0 || !Array.isArray(uidList) || uidList.length === 0) return uidList;
    const now = Date.now();
    const fresh = [];
    for (const uid of uidList) {
        const n = parseInt(uid, 10);
        if (!Number.isFinite(n) || n <= 0) continue;
        const key = `${accIdx}:${n}`;
        const lastAt = Number(recentNotifyByMailUid.get(key) || 0);
        if (lastAt > 0 && (now - lastAt) < MAIL_NOTIFY_DEDUP_WINDOW_MS) continue;
        recentNotifyByMailUid.set(key, now);
        fresh.push(n);
    }
    return fresh;
}

function idleOwnerKey(accountIdx) {
    return `${IDLE_OWNER_LOCK_PREFIX}${parseInt(accountIdx, 10) || 0}`;
}

async function getIdleOwner(accountIdx) {
    const key = idleOwnerKey(accountIdx);
    if (key.endsWith(':0')) return '';
    try {
        return String((await redisPublisher.get(key)) || '').trim();
    } catch (_) {
        return '';
    }
}

async function acquireIdleOwnerLock(accountIdx, reason = '') {
    const accIdx = parseInt(accountIdx, 10) || 0;
    if (accIdx <= 0) return false;
    const key = idleOwnerKey(accIdx);

    try {
        const setOk = await redisPublisher.set(key, WORKER_INSTANCE_ID, 'NX', 'EX', IDLE_OWNER_LOCK_TTL_SECONDS);
        if (setOk === 'OK') {
            log('IDLE owner 획득 [%d] owner=%s reason=%s', accIdx, WORKER_INSTANCE_ID, reason || '-');
            return true;
        }

        const owner = await getIdleOwner(accIdx);
        if (owner === WORKER_INSTANCE_ID) {
            await redisPublisher.expire(key, IDLE_OWNER_LOCK_TTL_SECONDS);
            return true;
        }
        dbg('IDLE owner 점유중 skip [%d] owner=%s reason=%s', accIdx, owner || '-', reason || '-');
        return false;
    } catch (e) {
        warn('IDLE owner lock 확인 실패 [%d]: %s (fail-open)', accIdx, e.message);
        return true;
    }
}

async function renewIdleOwnerLock(accountIdx) {
    const accIdx = parseInt(accountIdx, 10) || 0;
    if (accIdx <= 0) return false;
    const key = idleOwnerKey(accIdx);
    try {
        const result = await redisPublisher.eval(
            "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], ARGV[2]) else return 0 end",
            1,
            key,
            WORKER_INSTANCE_ID,
            String(IDLE_OWNER_LOCK_TTL_SECONDS)
        );
        return Number(result) === 1;
    } catch (e) {
        warn('IDLE owner renew 실패 [%d]: %s (keep-running)', accIdx, e.message);
        return true;
    }
}

async function releaseIdleOwnerLock(accountIdx) {
    const accIdx = parseInt(accountIdx, 10) || 0;
    if (accIdx <= 0) return;
    const key = idleOwnerKey(accIdx);
    try {
        await redisPublisher.eval(
            "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end",
            1,
            key,
            WORKER_INSTANCE_ID
        );
    } catch (_) {}
}

// ══════════════════════════════════════
// DB 연결
// ══════════════════════════════════════
async function connectDB() {
    if (dbPool) return dbPool;
    dbPool = await sql.connect(CONFIG.db);
    log('DB 연결 완료 (%s:%d/%s)', CONFIG.db.server, CONFIG.db.port, CONFIG.db.database);
    return dbPool;
}

async function query(strings, ...values) {
    const pool = await connectDB();
    const req  = pool.request();
    let q = '';
    strings.forEach((s, i) => {
        q += s;
        if (i < values.length) {
            req.input(`p${i}`, values[i]);
            q += `@p${i}`;
        }
    });
    return req.query(q);
}

async function hasMessageCacheBodyHtmlColumn() {
    if (_mailCacheHasBodyHtml !== null) return _mailCacheHasBodyHtml;
    try {
        const rs = await query`
            SELECT CAST(
                CASE WHEN COL_LENGTH('dbo.Tb_Mail_MessageCache', 'body_html') IS NULL
                    THEN 0 ELSE 1 END
            AS INT) AS has_col
        `;
        _mailCacheHasBodyHtml = (parseInt(rs.recordset?.[0]?.has_col, 10) || 0) === 1;
    } catch (e) {
        _mailCacheHasBodyHtml = false;
        dbg('body_html 컬럼 확인 실패: %s', e.message);
    }
    return _mailCacheHasBodyHtml;
}

// ══════════════════════════════════════
// payload 직렬화
// ══════════════════════════════════════
function stringifyPayload(data, ctx = '') {
    let raw = '';
    try { raw = JSON.stringify(data); } catch (e) { return ''; }
    if (Buffer.byteLength(raw, 'utf8') > REALTIME_PAYLOAD_MAX_BYTES) {
        warn('payload 초과 드롭 (%s)', ctx);
        return '';
    }
    return raw;
}

// ══════════════════════════════════════
// Redis pub/sub
// ══════════════════════════════════════
function publishToRedis(userPk, data, scope, serialized = '') {
    const msg = serialized || stringifyPayload(data, `redis:${scope}`);
    if (!msg) return;
    const target = String(userPk || '').trim();
    const channel = target ? `mail:user:${target}` : null;
    if (!channel) return;
    redisPublisher.publish(channel, msg).catch((e) => {
        const now = Date.now();
        if (now - _lastRedisWarnAt > 10000) {
            _lastRedisWarnAt = now;
            warn('Redis publish 실패 (%s): %s', channel, e.message);
        }
    });
}

function extractUserPkFromChannel(channel) {
    const m = String(channel || '').match(/^mail:user:(.+)$/);
    return m ? String(m[1] || '').trim() : '';
}

redisSubscriber.on('message', (channel, message) => {
    const rawJson = String(message ?? '') || '{}';
    const userPk  = extractUserPkFromChannel(channel);
    if (!userPk) return;
    broadcastSseToUser(userPk, rawJson);
    broadcastWsToUser(userPk, rawJson);
});

// ══════════════════════════════════════
// SSE
// ══════════════════════════════════════
function countSseConnections() { return sseClientsById.size; }
function countConnections()    { let n = 0; for (const s of wsClients.values()) n += s.size; return n; }

function getSseRetryMs() { return SSE_RETRY_BASE_MS + Math.floor(Math.random() * SSE_RETRY_JITTER_MS); }

function writeSseChunk(res, chunk) {
    if (!res || res.writableEnded || res.destroyed) return false;
    const ok = res.write(chunk);
    return ok && Number(res.writableLength || 0) <= SSE_MAX_WRITABLE_LENGTH;
}

function sendSseData(res, jsonPayload) {
    if (Buffer.byteLength(String(jsonPayload || ''), 'utf8') > REALTIME_PAYLOAD_MAX_BYTES) return false;
    return writeSseChunk(res, `data: ${jsonPayload}\n\n`);
}

function sendSseComment(res, text) { return writeSseChunk(res, `: ${text}\n\n`); }

async function retainSubscriberChannel(channel) {
    const key = String(channel || '').trim();
    if (!key) return;
    const prev = subscriberChannelRefCounts.get(key) || 0;
    subscriberChannelRefCounts.set(key, prev + 1);
    if (prev === 0) {
        await redisSubscriber.subscribe(key);
        dbg('Redis subscribe: %s', key);
    }
}

async function releaseSubscriberChannel(channel) {
    const key = String(channel || '').trim();
    const prev = subscriberChannelRefCounts.get(key) || 0;
    if (prev <= 1) {
        subscriberChannelRefCounts.delete(key);
        try { await redisSubscriber.unsubscribe(key); } catch (_) {}
    } else {
        subscriberChannelRefCounts.set(key, prev - 1);
    }
}

async function closeSseClient(clientId, reason = 'closed') {
    const id     = parseInt(clientId, 10);
    const meta   = sseClientsById.get(id);
    if (!meta || meta.closed) return;
    meta.closed = true;
    if (meta.lifetimeTimer) { clearTimeout(meta.lifetimeTimer); meta.lifetimeTimer = null; }
    if (meta.onlineTimer) { clearInterval(meta.onlineTimer); meta.onlineTimer = null; }
    sseClientsById.delete(id);
    const userSet = sseClientsByUser.get(meta.userPk);
    if (userSet) { userSet.delete(id); if (userSet.size === 0) sseClientsByUser.delete(meta.userPk); }
    for (const ch of meta.channels) await releaseSubscriberChannel(ch);
    try { meta.res.end(); } catch (_) {}
    dbg('SSE 해제: pk=%s (%s) — 총 %d명', meta.userPk, reason, countSseConnections());
}

function consumeSseRateBudget(userPk) {
    const key = String(userPk || '').trim();
    if (!key) return false;
    const sec = Math.floor(Date.now() / 1000);
    const row = sseRateByUser.get(key);
    if (!row || row.sec !== sec) {
        sseRateByUser.set(key, { sec, count: 1, updatedAt: Date.now() });
        return true;
    }
    if (row.count >= SSE_RATE_LIMIT_PER_SEC) {
        row.updatedAt = Date.now();
        return false;
    }
    row.count += 1;
    row.updatedAt = Date.now();
    return true;
}

function broadcastSseToUser(userPk, rawJson) {
    const key = String(userPk || '').trim();
    if (!key) return 0;
    const ids = sseClientsByUser.get(key);
    if (!ids || ids.size === 0) return 0;
    if (!consumeSseRateBudget(key)) {
        dbg('SSE rate limit drop: pk=%s', key);
        return 0;
    }
    let sent = 0;
    for (const clientId of ids) {
        const meta = sseClientsById.get(clientId);
        if (!meta || meta.closed) continue;
        try {
            if (sendSseData(meta.res, rawJson)) sent++;
            else void closeSseClient(clientId, 'slow_consumer');
        } catch (_) { void closeSseClient(clientId, 'write_error'); }
    }
    return sent;
}

function broadcastWsToUser(userPk, rawJson) {
    const key = String(userPk || '').trim();
    if (!key) return 0;
    const sockets = wsClients.get(key);
    if (!sockets || sockets.size === 0) return 0;
    let sent = 0;
    for (const ws of sockets) {
        if (ws.readyState !== 1) continue;
        try {
            ws.send(rawJson);
            sent++;
        } catch (_) {}
    }
    return sent;
}

function startSseHeartbeat() {
    if (sseHeartbeatTimer) return;
    sseHeartbeatTimer = setInterval(() => {
        for (const [clientId, meta] of sseClientsById) {
            if (!meta || meta.closed) continue;
            try {
                if (!sendSseComment(meta.res, 'heartbeat'))
                    void closeSseClient(clientId, 'slow_consumer');
            } catch (_) { void closeSseClient(clientId, 'heartbeat_error'); }
        }
    }, SSE_HEARTBEAT_MS);
}

function startMaintenanceTimers() {
    if (debounceCleanupTimer) return;
    debounceCleanupTimer = setInterval(() => {
        const now = Date.now();
        for (const [t, e] of tokenCache) {
            if (!e || now >= (e.cacheUntil || 0)) tokenCache.delete(t);
        }
        for (const [u, r] of sseRateByUser) {
            if (!r || now - Number(r.updatedAt || 0) > DEBOUNCE_ENTRY_TTL_MS) sseRateByUser.delete(u);
        }
        for (const [u, at] of lastMailActivityByUser) {
            if (!at || now - Number(at || 0) > (IDLE_ACTIVITY_WINDOW_MS * 2)) lastMailActivityByUser.delete(u);
        }
        for (const [k, at] of recentNotifyByMailUid) {
            if (!at || now - Number(at || 0) > MAIL_NOTIFY_DEDUP_ENTRY_TTL_MS) recentNotifyByMailUid.delete(k);
        }
    }, DEBOUNCE_CLEANUP_INTERVAL_MS);
}

// ══════════════════════════════════════
// WS 브로드캐스트
// ══════════════════════════════════════
function broadcastToUser(userPk, data, serialized = '') {
    const key = String(userPk || '').trim();

    const msg = serialized || stringifyPayload(data, `broadcast:pk:${key}`);
    if (!msg) return 0;

    // Redis publish (SSE + WS cross-worker fanout)
    publishToRedis(key, data, 'mail', msg);

    // Redis subscriber 비정상 시에만 로컬로 직접 전송 fallback
    if (redisSubscriber.status !== 'ready') {
        return broadcastSseToUser(key, msg) + broadcastWsToUser(key, msg);
    }

    const localSse = (sseClientsByUser.get(key)?.size || 0);
    const localWs  = (wsClients.get(key)?.size || 0);
    return localSse + localWs;
}

// ══════════════════════════════════════
// 토큰 검증 (Tb_Mail_WsToken, V2: user_pk/account_idx)
// ══════════════════════════════════════
async function verifyRealtimeToken(token) {
    const safeToken = String(token || '').trim();
    if (!safeToken) return null;
    const now = Date.now();

    const cached = tokenCache.get(safeToken);
    if (cached && now < (cached.cacheUntil || 0) && now < (cached.tokenExpireAt || 0))
        return cached.info || null;

    let result;
    try {
        result = await query`
            SELECT t.user_pk, t.account_idx, t.expires_at,
                   a.display_name AS email
            FROM Tb_Mail_WsToken t
            LEFT JOIN Tb_IntProviderAccount a ON t.account_idx = a.idx
            WHERE t.token = ${safeToken} AND t.expires_at > GETDATE()
        `;
    } catch (e) {
        warn('토큰 검증 DB 오류: %s', e.message);
        return null;
    }

    if (!result.recordset || result.recordset.length === 0) {
        dbg('토큰 검증 실패: %s', maskToken(safeToken));
        return null;
    }

    const info = result.recordset[0];
    const tokenExpireAt = new Date(info.expires_at).getTime();
    const cacheUntil = Math.min(now + TOKEN_CACHE_TTL_MS, Number.isFinite(tokenExpireAt) ? tokenExpireAt : now + TOKEN_CACHE_TTL_MS);
    tokenCache.set(safeToken, { info, tokenExpireAt: Number.isFinite(tokenExpireAt) ? tokenExpireAt : now + TOKEN_CACHE_TTL_MS, cacheUntil });
    dbg('토큰 검증 OK: pk=%s token=%s', String(info.user_pk || ''), maskToken(safeToken));
    return info;
}

// ══════════════════════════════════════
// WS 포트 설정 (DB 우선)
// ══════════════════════════════════════
async function getWsPort() {
    try {
        const r = await query`SELECT config_value FROM Tb_Mail_Config WHERE config_key = 'ws_port_v2'`;
        if (r.recordset.length > 0) {
            const p = parseInt(r.recordset[0].config_value, 10);
            if (p > 0) return p;
        }
    } catch (_) {}
    return CONFIG.ws.port;
}

// ══════════════════════════════════════
// HTTP + WS 서버
// ══════════════════════════════════════
async function startServer() {
    const port = await getWsPort();

    const keyPath  = path.join(__dirname, 'key.pem');
    const certPath = path.join(__dirname, 'cert.pem');
    const hasSsl   = fs.existsSync(keyPath) && fs.existsSync(certPath);
    if (!hasSsl) warn('key.pem / cert.pem 없음 — HTTP 모드 (ws://)');

    const serverModule = hasSsl ? require('https') : http;
    const sslOpts = hasSsl
        ? { key: fs.readFileSync(keyPath), cert: fs.readFileSync(certPath) }
        : {};

    httpServer = hasSsl
        ? serverModule.createServer(sslOpts, handleHttp)
        : serverModule.createServer(handleHttp);

    wss = new WebSocketServer({ server: httpServer });
    wss.on('connection', handleWsConnection);

    wsHeartbeatTimer = setInterval(() => {
        if (!wss) return;
        wss.clients.forEach(ws => {
            if (!ws._shvAlive) { ws.terminate(); return; }
            ws._shvAlive = false;
            ws.ping();
        });
    }, 30000);

    startSseHeartbeat();
    startMaintenanceTimers();

    httpServer.listen(port, () => log('서버 시작 — HTTP + WebSocket (포트 %d, SSL=%s)', port, hasSsl));
}

async function handleHttp(req, res) {
    const parsedUrl  = url.parse(req.url);
    const pathname   = parsedUrl.pathname;
    setCorsHeaders(req, res);

    if (req.method === 'OPTIONS') {
        res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Broadcast-Secret, Authorization');
        res.statusCode = 204; res.end(); return;
    }

    if (pathname === '/status') {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({
            running: true,
            ws_connections:  countConnections(),
            sse_connections: countSseConnections(),
            accounts:        imapClients.size,
            fetch_queue:     fetchPriorityQueue.length,
            fetch_workers:   fetchWorkersRunning,
            fcm_pending:     fcmNotifier ? fcmNotifier.getPendingCount() : 0,
            uptime:          Math.floor(process.uptime()),
        }));
        return;
    }

    if (pathname === '/sse' && req.method === 'GET') {
        await handleSseRequest(req, res, parsedUrl);
        return;
    }

    if (pathname === '/heartbeat' && (req.method === 'GET' || req.method === 'POST')) {
        await handleHeartbeatRequest(req, res, parsedUrl);
        return;
    }

    if (pathname === '/broadcast' && req.method === 'POST') {
        await handleBroadcastRequest(req, res);
        return;
    }

    res.setHeader('Content-Type', 'application/json');
    res.statusCode = 404;
    res.end(JSON.stringify({ error: 'Not found' }));
}

async function handleSseRequest(req, res, parsedUrl) {
    if (countSseConnections() >= SSE_MAX_CONNECTIONS) {
        res.statusCode = 503;
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ success: false, error: 'SSE capacity reached' }));
        return;
    }

    const params = new URLSearchParams(parsedUrl.query || '');
    const token  = String(params.get('token') || '').trim();
    if (!token) { res.statusCode = 401; res.end(JSON.stringify({ error: 'Token required' })); return; }

    let info;
    try { info = await verifyRealtimeToken(token); }
    catch (e) { res.statusCode = 500; res.end(JSON.stringify({ error: 'Server error' })); return; }

    if (!info || !info.user_pk) {
        res.statusCode = 401; res.end(JSON.stringify({ error: 'Invalid or expired token' })); return;
    }

    const userPk = String(info.user_pk);

    // 기존 연결 수 확인 후 초과 시 오래된 것 제거
    const userSet = sseClientsByUser.get(userPk) || new Set();
    sseClientsByUser.set(userPk, userSet);
    while (userSet.size >= SSE_MAX_CONNECTIONS_PER_USER) {
        const oldId = userSet.values().next().value;
        if (oldId === undefined) break;
        void closeSseClient(oldId, 'user_conn_limit');
    }

    const clientId = ++sseSeq;
    const channels = [`mail:user:${userPk}`];

    res.writeHead(200, {
        'Content-Type':    'text/event-stream; charset=utf-8',
        'Cache-Control':   'no-cache, no-store',
        'Connection':      'keep-alive',
        'X-Accel-Buffering': 'no',
    });

    const meta = {
        id: clientId,
        userPk,
        channels,
        res,
        closed: false,
        lifetimeTimer: null,
        onlineTimer: null,
        connectedAt: Date.now(),
    };
    sseClientsById.set(clientId, meta);
    userSet.add(clientId);

    try { for (const ch of channels) await retainSubscriberChannel(ch); }
    catch (e) { void closeSseClient(clientId, 'subscribe_error'); return; }

    writeSseChunk(res, `retry: ${getSseRetryMs()}\n`);
    if (!sendSseData(res, JSON.stringify({ type: 'connected', userPk, email: info.email || '' }))) {
        void closeSseClient(clientId, 'slow_consumer'); return;
    }
    await setUserOnlineHeartbeat(userPk);
    meta.onlineTimer = setInterval(() => {
        void setUserOnlineHeartbeat(userPk);
    }, ONLINE_HEARTBEAT_MS);

    meta.lifetimeTimer = setTimeout(() => {
        writeSseChunk(res, `retry: ${getSseRetryMs()}\n`);
        writeSseChunk(res, 'data: {"type":"reconnect"}\n\n');
        void closeSseClient(clientId, 'max_lifetime');
    }, SSE_MAX_LIFETIME_MS);

    const onClose = () => void closeSseClient(clientId, 'client_close');
    req.on('close', onClose); res.on('close', onClose);

    log('SSE 연결: pk=%s — 총 SSE %d명', userPk, countSseConnections());
}

async function handleHeartbeatRequest(req, res, parsedUrl) {
    res.setHeader('Content-Type', 'application/json');
    const params = new URLSearchParams(parsedUrl.query || '');
    const authHeader = String(req.headers.authorization || '').trim();
    const bearer = authHeader.toLowerCase().startsWith('bearer ') ? authHeader.slice(7).trim() : '';
    const token = String(params.get('token') || bearer || '').trim();
    if (!token) {
        res.statusCode = 401;
        res.end(JSON.stringify({ ok: false, error: 'token required' }));
        return;
    }

    let info = null;
    try {
        info = await verifyRealtimeToken(token);
    } catch (e) {
        res.statusCode = 500;
        res.end(JSON.stringify({ ok: false, error: 'token verify failed' }));
        return;
    }
    if (!info || !info.user_pk) {
        res.statusCode = 401;
        res.end(JSON.stringify({ ok: false, error: 'invalid token' }));
        return;
    }

    await setUserOnlineHeartbeat(info.user_pk);
    res.end(JSON.stringify({ ok: true, ttl: ONLINE_TTL_SECONDS }));
}

async function handleBroadcastRequest(req, res) {
    res.setHeader('Content-Type', 'application/json');
    if (!isLocalhostIp(req.socket.remoteAddress || '')) {
        res.statusCode = 403; res.end(JSON.stringify({ error: 'Forbidden' })); return;
    }

    const envSecret = String(CONFIG.broadcastSecret || '').trim();
    if (!envSecret) { res.statusCode = 503; res.end(JSON.stringify({ error: 'BROADCAST_SECRET not configured' })); return; }

    const headerSecret = Array.isArray(req.headers['x-broadcast-secret'])
        ? String(req.headers['x-broadcast-secret'][0] || '').trim()
        : String(req.headers['x-broadcast-secret'] || '').trim();
    if (headerSecret !== envSecret) { res.statusCode = 403; res.end(JSON.stringify({ error: 'Unauthorized' })); return; }

    let body = '';
    let tooLarge = false;
    req.on('data', chunk => {
        if (tooLarge) return;
        body += chunk;
        if (Buffer.byteLength(body, 'utf8') > BROADCAST_BODY_MAX_BYTES) {
            tooLarge = true; res.statusCode = 413; res.end(JSON.stringify({ error: 'Payload too large' }));
        }
    });
    req.on('end', () => {
        if (tooLarge) return;
        try {
            const data       = JSON.parse(body);
            const targetPk   = String(data.user_pk || '').trim();
            const message    = data.message || data;
            const payload    = stringifyPayload(message, '/broadcast');
            if (!payload) { res.statusCode = 413; res.end(JSON.stringify({ error: 'Payload too large' })); return; }
            const sent = targetPk ? broadcastToUser(targetPk, message, payload) : 0;
            log('브로드캐스트: type=%s pk=%s sent=%d', message.type || '-', targetPk || 'ALL', sent);
            res.end(JSON.stringify({ success: true, sent }));
        } catch (e) { res.statusCode = 400; res.end(JSON.stringify({ error: e.message })); }
    });
}

async function handleWsConnection(ws, req) {
    const params = new URLSearchParams(url.parse(req.url).query);
    const token  = params.get('token');
    const ip     = req.headers['x-forwarded-for'] || req.socket.remoteAddress || '';

    if (!token) { ws.close(4001, 'Token required'); return; }

    try {
        const info = await verifyRealtimeToken(token);
        if (!info || !info.user_pk) { ws.close(4003, 'Invalid or expired token'); return; }

        const userPk = String(info.user_pk);
        const userChannel = `mail:user:${userPk}`;
        try {
            await retainSubscriberChannel(userChannel);
        } catch (e) {
            warn('WS subscribe 실패: pk=%s channel=%s err=%s', userPk, userChannel, e.message);
            ws.close(4502, 'Subscribe failed');
            return;
        }
        ws._shvUserPk      = userPk;
        ws._shvChannel     = userChannel;
        ws._shvAccountIdx  = info.account_idx;
        ws._shvEmail       = info.email || '';
        ws._shvConnectedAt = ts();
        ws._shvAlive       = true;
        ws._shvIp          = ip;

        if (!wsClients.has(userPk)) wsClients.set(userPk, new Set());
        wsClients.get(userPk).add(ws);

        ws.on('close', () => {
            const sockets = wsClients.get(userPk);
            if (sockets) { sockets.delete(ws); if (sockets.size === 0) wsClients.delete(userPk); }
            if (ws._shvChannel) void releaseSubscriberChannel(ws._shvChannel);
            log('WS 해제: pk=%s — 총 %d명', userPk, countConnections());
        });
        ws.on('error', (e) => dbg('WS 에러 pk=%s: %s', userPk, e.message));
        ws.on('pong', () => {
            ws._shvAlive = true;
            void setUserOnlineHeartbeat(userPk);
        });

        ws.send(JSON.stringify({ type: 'connected', userPk, email: info.email || '' }));
        await setUserOnlineHeartbeat(userPk);

        log('WS 연결: pk=%s [%s] — 총 %d명', userPk, ip, countConnections());
    } catch (e) {
        err('WS 인증 오류:', e.message);
        ws.close(4500, 'Server error');
    }
}

// ══════════════════════════════════════
// DB 쓰기 헬퍼
// ══════════════════════════════════════
async function fetchExistingInboxUidSet(accountIdx, uids) {
    const existing = new Set();
    const normalized = [...new Set((uids || []).map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0))];
    if (normalized.length === 0) return existing;

    const pool = await connectDB();
    for (let i = 0; i < normalized.length; i += DB_IN_CLAUSE_CHUNK) {
        const chunk = normalized.slice(i, i + DB_IN_CLAUSE_CHUNK);
        const req   = pool.request();
        req.input('accIdx', parseInt(accountIdx, 10));
        const ph = chunk.map((uid, idx) => { req.input(`uid${idx}`, uid); return `@uid${idx}`; });
        const rs = await req.query(`
            SELECT uid FROM Tb_Mail_MessageCache
            WHERE account_idx = @accIdx AND folder = 'INBOX'
              AND uid IN (${ph.join(',')})
        `);
        for (const row of (rs.recordset || [])) {
            const uid = parseInt(row.uid, 10);
            if (Number.isFinite(uid) && uid > 0) existing.add(uid);
        }
    }
    return existing;
}

async function insertInboxBatch(accountIdx, uidvalidity, mails) {
    if (!Array.isArray(mails) || mails.length === 0) return { inserted: 0, insertedUids: [] };
    const pool = await connectDB();
    const safeUidVal = parseInt(uidvalidity, 10) || 0;
    const includeBodyHtml = await hasMessageCacheBodyHtmlColumn();
    const insertColumns = includeBodyHtml
        ? `(account_idx, folder, uid, uidvalidity, message_id,
            in_reply_to, [references], thread_id, subject,
            from_address, to_address, [date], flags, is_seen, is_flagged,
            has_attachment, body_preview, body_html, body_hash, fcm_notified, created_at)`
        : `(account_idx, folder, uid, uidvalidity, message_id,
            in_reply_to, [references], thread_id, subject,
            from_address, to_address, [date], flags, is_seen, is_flagged,
            has_attachment, body_preview, body_hash, fcm_notified, created_at)`;
    let inserted = 0;
    const insertedUids = [];

    for (let i = 0; i < mails.length; i += DB_INSERT_BATCH) {
        const chunk = mails.slice(i, i + DB_INSERT_BATCH);
        const tx = new sql.Transaction(pool);
        try {
            await tx.begin();
            const req = new sql.Request(tx);
            req.input('accIdx', parseInt(accountIdx, 10));
            req.input('uidvalidity', safeUidVal);

            const values = [];
            chunk.forEach((m, idx) => {
                const uid = parseInt(m.uid, 10) || 0;
                const safeHtml = sanitizeBodyHtml(m.bodyHtml || m.preview || m.subject || '');
                const preview = makePreview(safeHtml || m.subject || '');
                const bodyHash = makeBodyHash(`${accountIdx}:${uid}:${m.messageId || ''}`);

                req.input(`uid${idx}`, uid);
                req.input(`messageId${idx}`, String(m.messageId || ''));
                req.input(`inReplyTo${idx}`, String(m.inReplyTo || ''));
                req.input(`references${idx}`, String(m.references || ''));
                req.input(`threadId${idx}`, String(m.threadId || ''));
                req.input(`subject${idx}`, String(m.subject || ''));
                req.input(`fromAddr${idx}`, String(m.fromAddr || ''));
                req.input(`toAddr${idx}`, String(m.toAddr || ''));
                req.input(`mailDate${idx}`, m.date || null);
                req.input(`flags${idx}`, String(m.flags || ''));
                req.input(`isSeen${idx}`, m.isSeen ? 1 : 0);
                req.input(`isFlagged${idx}`, m.isFlagged ? 1 : 0);
                req.input(`hasAttach${idx}`, m.hasAttach ? 1 : 0);
                req.input(`bodyPreview${idx}`, preview);
                if (includeBodyHtml) req.input(`bodyHtml${idx}`, safeHtml);
                req.input(`bodyHash${idx}`, bodyHash);

                const row = [
                    '@accIdx', "N'INBOX'", `@uid${idx}`, '@uidvalidity', `@messageId${idx}`,
                    `@inReplyTo${idx}`, `@references${idx}`, `@threadId${idx}`,
                    `@subject${idx}`, `@fromAddr${idx}`, `@toAddr${idx}`, `@mailDate${idx}`,
                    `@flags${idx}`, `@isSeen${idx}`, `@isFlagged${idx}`, `@hasAttach${idx}`,
                    `@bodyPreview${idx}`,
                ];
                if (includeBodyHtml) row.push(`@bodyHtml${idx}`);
                row.push(`@bodyHash${idx}`, '0', 'GETDATE()');
                values.push(`(${row.join(', ')})`);
            });

            const rs = await req.query(`
                INSERT INTO Tb_Mail_MessageCache WITH (ROWLOCK)
                ${insertColumns}
                VALUES ${values.join(',')}
            `);
            await tx.commit();
            const affected = Array.isArray(rs.rowsAffected) ? (parseInt(rs.rowsAffected[0], 10) || 0) : 0;
            inserted += affected > 0 ? affected : chunk.length;
            insertedUids.push(...chunk.map((m) => parseInt(m.uid, 10)).filter((uid) => Number.isFinite(uid) && uid > 0));
        } catch (e) {
            try { await tx.rollback(); } catch (_) {}
            warn('배치 INSERT 실패 [%d] uid=%d: %s — 1건씩 재시도', accountIdx, chunk[0]?.uid ?? 0, e.message);
            for (const m of chunk) {
                const safeHtml = sanitizeBodyHtml(m.bodyHtml || m.preview || m.subject || '');
                const preview = makePreview(safeHtml || m.subject || '');
                const bodyHash = makeBodyHash(`${accountIdx}:${m.uid}:${m.messageId || ''}`);
                try {
                    if (includeBodyHtml) {
                        await query`
                            INSERT INTO Tb_Mail_MessageCache WITH (ROWLOCK)
                            (account_idx, folder, uid, uidvalidity, message_id,
                             in_reply_to, [references], thread_id, subject,
                             from_address, to_address, [date], flags, is_seen, is_flagged,
                             has_attachment, body_preview, body_html, body_hash, fcm_notified, created_at)
                            VALUES (${accountIdx}, 'INBOX', ${m.uid}, ${safeUidVal},
                                    ${m.messageId}, ${m.inReplyTo || ''}, ${m.references || ''},
                                    ${m.threadId || ''}, ${m.subject}, ${m.fromAddr}, ${m.toAddr},
                                    ${m.date}, ${m.flags || ''}, ${m.isSeen ? 1 : 0},
                                    ${m.isFlagged ? 1 : 0}, ${m.hasAttach ? 1 : 0},
                                    ${preview}, ${safeHtml}, ${bodyHash}, 0, GETDATE())
                        `;
                    } else {
                        await query`
                            INSERT INTO Tb_Mail_MessageCache WITH (ROWLOCK)
                            (account_idx, folder, uid, uidvalidity, message_id,
                             in_reply_to, [references], thread_id, subject,
                             from_address, to_address, [date], flags, is_seen, is_flagged,
                             has_attachment, body_preview, body_hash, fcm_notified, created_at)
                            VALUES (${accountIdx}, 'INBOX', ${m.uid}, ${safeUidVal},
                                    ${m.messageId}, ${m.inReplyTo || ''}, ${m.references || ''},
                                    ${m.threadId || ''}, ${m.subject}, ${m.fromAddr}, ${m.toAddr},
                                    ${m.date}, ${m.flags || ''}, ${m.isSeen ? 1 : 0},
                                    ${m.isFlagged ? 1 : 0}, ${m.hasAttach ? 1 : 0},
                                    ${preview}, ${bodyHash}, 0, GETDATE())
                        `;
                    }
                    inserted++;
                    const uid = parseInt(m.uid, 10);
                    if (Number.isFinite(uid) && uid > 0) insertedUids.push(uid);
                } catch (e2) {
                    dbg('fallback INSERT 오류 [%d] uid=%d: %s', accountIdx, m.uid, e2.message);
                }
            }
        }
    }

    return { inserted, insertedUids };
}

async function loadUnreadCount(accountIdx) {
    const r = await query`
        SELECT COUNT(*) AS cnt FROM Tb_Mail_MessageCache
        WHERE account_idx = ${accountIdx} AND folder = 'INBOX'
          AND (is_seen = 0 OR is_seen IS NULL)
    `;
    return parseInt(r.recordset[0]?.cnt, 10) || 0;
}

function enqueueAccountSyncTask(accountIdx, taskFn) {
    const key  = String(accountIdx);
    const prev = accountSyncQueues.get(key) || Promise.resolve();
    const next = prev.catch(() => {}).then(() => taskFn()).catch(e => {
        err('비동기 DB 처리 오류 [%s]: %s', key, e.message);
    }).finally(() => {
        if (accountSyncQueues.get(key) === next) accountSyncQueues.delete(key);
    });
    accountSyncQueues.set(key, next);
    return next;
}

// ══════════════════════════════════════
// 문자열 정규화
// ══════════════════════════════════════
function normMessageId(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const m = raw.match(/<[^>]+>/);
    return (m ? m[0] : raw).slice(0, 500);
}

function normReferences(value) {
    if (Array.isArray(value)) {
        return value.map(normMessageId).filter(Boolean).join(' ').slice(0, 4000);
    }
    const raw = String(value || '').trim();
    if (!raw) return '';
    const matches = raw.match(/<[^>]+>/g);
    return matches ? matches.join(' ').slice(0, 4000) : raw.slice(0, 4000);
}

function buildThreadId(messageId, inReplyTo, references, subject = '') {
    const refs     = normReferences(references);
    const firstRef = (refs.match(/<[^>]+>/) || [])[0] || '';
    if (firstRef)  return firstRef.slice(0, 200);
    const mid = normMessageId(messageId);
    if (mid)       return mid.slice(0, 200);
    const irt = normMessageId(inReplyTo);
    if (irt)       return irt.slice(0, 200);
    const subj = String(subject || '').trim();
    if (subj)      return `subject:${Buffer.from(subj.toLowerCase(), 'utf8').toString('hex').slice(0, 184)}`;
    return '';
}

// ══════════════════════════════════════
// 계정 로딩 (Tb_IntProviderAccount + Tb_IntCredential)
// ══════════════════════════════════════
async function ensureIdleColumn() {
    if (_idleColumnChecked) return;
    try {
        await query`
            IF NOT EXISTS (
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'Tb_IntProviderAccount' AND COLUMN_NAME = 'idle_enabled'
            )
            BEGIN
                ALTER TABLE Tb_IntProviderAccount
                ADD idle_enabled BIT NOT NULL
                CONSTRAINT DF_Tb_IntProviderAccount_idle_enabled DEFAULT (1) WITH VALUES
            END
        `;
        _idleColumnChecked = true;
    } catch (e) {
        warn('idle_enabled 컬럼 보정 실패: %s', e.message);
    }
}

async function ensureWsTokenTable() {
    try {
        await query`
            IF OBJECT_ID(N'dbo.Tb_Mail_WsToken', N'U') IS NULL
            BEGIN
                CREATE TABLE dbo.Tb_Mail_WsToken (
                    token        NVARCHAR(64)  NOT NULL CONSTRAINT PK_Tb_Mail_WsToken PRIMARY KEY,
                    user_pk      INT           NOT NULL,
                    account_idx  INT           NULL,
                    service_code VARCHAR(30)   NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_sc DEFAULT ('shvq'),
                    tenant_id    INT           NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_tid DEFAULT (0),
                    expires_at   DATETIME      NOT NULL,
                    created_at   DATETIME      NOT NULL CONSTRAINT DF_Tb_Mail_WsToken_created DEFAULT (GETDATE())
                )
            END
        `;
    } catch (e) {
        warn('Tb_Mail_WsToken 테이블 보정 실패: %s', e.message);
    }
}

function parseRawJson(raw) {
    try { return JSON.parse(String(raw || '{}')) || {}; }
    catch (_) { return {}; }
}

async function loadAccounts() {
    await ensureIdleColumn();

    let rows;
    try {
        const r = await query`
            SELECT a.idx          AS account_idx,
                   a.user_pk,
                   a.service_code,
                   a.tenant_id,
                   a.display_name,
                   a.account_key,
                   a.raw_json
            FROM   Tb_IntProviderAccount a
            WHERE  a.provider = 'mail'
              AND  ISNULL(a.status, 'ACTIVE') = 'ACTIVE'
              AND  ISNULL(a.idle_enabled, 1) = 1
        `;
        rows = r.recordset || [];
    } catch (e) {
        err('계정 로드 실패:', e.message);
        return [];
    }

    const accounts = [];

    for (const row of rows) {
        const raw  = parseRawJson(row.raw_json);

        // credentials
        let credPass = '';
        let credHost = '';
        let credLoginId = '';

        try {
            const cr = await query`
                SELECT secret_type, secret_value_enc
                FROM   Tb_IntCredential
                WHERE  provider_account_idx = ${row.account_idx}
                  AND  ISNULL(status, 'ACTIVE') = 'ACTIVE'
            `;
            for (const c of cr.recordset || []) {
                const val = decryptSecretValue(c.secret_value_enc);
                if (c.secret_type === 'password')  credPass    = val;
                if (c.secret_type === 'host')       credHost    = val;
                if (c.secret_type === 'login_id')   credLoginId = val;
            }
        } catch (_) {}

        const host     = (credHost || raw.host     || '').trim();
        const loginId  = (credLoginId || raw.login_id || row.account_key || '').trim();
        const password = (credPass || raw.password  || '').trim();

        if (!host || !loginId || !password) {
            warn('계정 자격증명 불완전 스킵 [%d] host=%s login=%s', row.account_idx, host || '(없음)', loginId || '(없음)');
            continue;
        }

        const port   = parseInt(raw.port, 10) || 993;
        const useSsl = raw.ssl === true || raw.ssl === 1 || raw.ssl === '1' || raw.ssl === 'true';
        const email  = raw.from_email || row.account_key || '';

        accounts.push({
            account_idx:   row.account_idx,
            user_pk:       row.user_pk,
            service_code:  row.service_code,
            tenant_id:     row.tenant_id,
            email,
            imap_host:     host,
            imap_port:     port,
            imap_ssl:      useSsl,
            imap_username: loginId,
            imap_password: password,
        });
    }

    return accounts;
}

// ══════════════════════════════════════
// IMAP IDLE
// ══════════════════════════════════════
function fetchTaskKey(accountIdx, uidFrom) {
    return `${parseInt(accountIdx, 10) || 0}:${parseInt(uidFrom, 10) || 0}`;
}

function clearReconnectTimer(accountIdx) {
    const key = String(accountIdx);
    const t = reconnectTimersByAccount.get(key);
    if (!t) return;
    clearTimeout(t);
    reconnectTimersByAccount.delete(key);
}

function scheduleReconnect(account, retryCount, reason) {
    const accIdx = parseInt(account?.account_idx, 10) || 0;
    if (accIdx <= 0) return;
    clearReconnectTimer(accIdx);
    const retry = Math.max(1, parseInt(retryCount, 10) || 1);
    const delay = IMAP_RECONNECT_BACKOFF_MS[Math.min(retry - 1, IMAP_RECONNECT_BACKOFF_MS.length - 1)];

    const timer = setTimeout(async () => {
        reconnectTimersByAccount.delete(String(accIdx));
        const decision = await shouldIdleAccount(account);
        if (!decision.shouldStart) {
            dbg('재연결 스킵 [%d] reason=%s (inactive/unread=%d)', accIdx, reason, decision.unread);
            return;
        }
        await startImapIdle({ ...account, _retryCount: retry }, `reconnect:${reason}`);
    }, delay);

    reconnectTimersByAccount.set(String(accIdx), timer);
    warn('IMAP 재연결 예약 [%d] %s in %dms (retry=%d)', accIdx, reason, delay, retry);
}

async function markCronFallback(accountIdx, reason) {
    try {
        await query`
            IF EXISTS (
                SELECT 1 FROM Tb_Mail_FolderSyncState
                WHERE account_idx = ${accountIdx} AND folder = 'INBOX'
            )
            BEGIN
                UPDATE Tb_Mail_FolderSyncState
                SET last_synced_at = DATEADD(minute, -120, GETDATE())
                WHERE account_idx = ${accountIdx} AND folder = 'INBOX'
            END
            ELSE
            BEGIN
                INSERT INTO Tb_Mail_FolderSyncState (account_idx, folder, uidvalidity, last_uid, last_synced_at)
                VALUES (${accountIdx}, 'INBOX', 0, 0, DATEADD(minute, -120, GETDATE()))
            END
        `;
    } catch (e) {
        warn('cron fallback 마킹 실패 [%d]: %s', accountIdx, e.message);
    }
    warn('cron fallback 전환 [%d]: %s', accountIdx, reason);
}

function enqueueFetchTask(accountIdx, uidFrom, retry = 0, availableAt = Date.now(), source = 'unknown') {
    const acc = parseInt(accountIdx, 10) || 0;
    const uid = Math.max(1, parseInt(uidFrom, 10) || 1);
    if (acc <= 0) return;
    const key = fetchTaskKey(acc, uid);
    if (fetchPendingKeys.has(key) || fetchProcessingKeys.has(key)) {
        dbg('FETCH enqueue skip [%d] uidFrom=%d source=%s (dup)', acc, uid, source);
        return;
    }

    fetchPendingKeys.add(key);
    const retryCount = Math.max(0, parseInt(retry, 10) || 0);
    const availTs = Math.max(Date.now(), parseInt(availableAt, 10) || Date.now());
    fetchPriorityQueue.push({
        key,
        accountIdx: acc,
        uidFrom: uid,
        retry: retryCount,
        enqueuedAt: Date.now(),
        availableAt: availTs,
    });
    log('FETCH enqueue [%d] uidFrom=%d retry=%d source=%s q=%d delayMs=%d',
        acc, uid, retryCount, source, fetchPriorityQueue.length, Math.max(0, availTs - Date.now()));
}

function popNextFetchTask() {
    const now = Date.now();
    let bestIdx = -1;
    let bestScore = -Infinity;

    for (let i = 0; i < fetchPriorityQueue.length; i++) {
        const t = fetchPriorityQueue[i];
        if (!t || t.availableAt > now) continue;
        const waitingSec = Math.max(0, (now - t.enqueuedAt) / 1000);
        const score = t.uidFrom + (waitingSec * FETCH_AGING_SCORE_PER_SEC);
        if (score > bestScore) {
            bestScore = score;
            bestIdx = i;
        }
    }

    if (bestIdx < 0) return null;
    const [task] = fetchPriorityQueue.splice(bestIdx, 1);
    if (task && task.key) fetchPendingKeys.delete(task.key);
    if (task && task.key) fetchProcessingKeys.add(task.key);
    return task || null;
}

async function routeMailNotification(entry, synced, unread, insertedUids, sampleMail) {
    const account = entry?.account || {};
    const userPk = String(account.user_pk || '').trim();
    if (!userPk || synced <= 0) return;

    const dedupBaseUids = normalizeUidList(insertedUids, sampleMail?.uid || 0);
    const notifyUids = filterRecentNotifyUids(entry.accountIdx, dedupBaseUids);
    if (dedupBaseUids.length > 0 && notifyUids.length === 0) {
        log('notify dedup skip [%d] user=%s uids=%s windowMs=%d',
            entry.accountIdx, userPk, dedupBaseUids.join(','), MAIL_NOTIFY_DEDUP_WINDOW_MS);
        return;
    }
    if (dedupBaseUids.length > notifyUids.length) {
        dbg('notify dedup partial [%d] user=%s kept=%d/%d',
            entry.accountIdx, userPk, notifyUids.length, dedupBaseUids.length);
    }
    const notifyCount = notifyUids.length > 0 ? notifyUids.length : synced;

    const online = await isUserOnline(userPk);
    if (online) markUserActive(userPk);

    if (online) {
        broadcastToUser(userPk, {
            type: 'newMail',
            count: notifyCount,
            unread,
            account: account.email || '',
            account_idx: entry.accountIdx,
        });
        broadcastToUser(userPk, {
            type: 'mailSynced',
            unread,
            account_idx: entry.accountIdx,
            synced: notifyCount,
        });
        return;
    }

    if (fcmNotifier) {
        await fcmNotifier.queueMail({
            userPk: parseInt(userPk, 10) || 0,
            accountIdx: entry.accountIdx,
            accountName: account.email || `계정 ${entry.accountIdx}`,
            senderPreview: sampleMail?.fromAddr || '새 메일',
            subjectPreview: sampleMail?.subject || '메일이 도착했습니다.',
            clickUrl: '/?r=mail_inbox',
            count: notifyCount,
            uidList: notifyUids.length > 0 ? notifyUids : dedupBaseUids,
        });
    }
}

async function fetchAndPersistFromUid(entry, uidFrom) {
    const client = entry.client;
    const accIdx = entry.accountIdx;
    const account = entry.account;
    const fromUid = Math.max(1, parseInt(uidFrom, 10) || 1);

    const newMails = [];
    for await (const msg of client.fetch(`${fromUid}:*`, {
        uid: true, envelope: true, flags: true, bodyStructure: true,
    })) {
        if (msg.uid < fromUid) continue;

        const envelope = msg.envelope || {};
        const from = envelope.from?.[0] || {};
        const to = envelope.to?.[0] || {};
        const fromAddr = from.name ? `${from.name} <${from.address || ''}>` : (from.address || account.email);
        const toAddr = to.address || '';
        const subject = envelope.subject || '';
        const toKST = (d) => {
            const dt = new Date(d);
            const kst = new Date(dt.getTime() + 9 * 3600000);
            return kst.toISOString().replace('T', ' ').substring(0, 19);
        };
        const date = envelope.date ? toKST(envelope.date) : toKST(new Date());
        const messageId = normMessageId(envelope.messageId || msg.messageId || '');
        const inReplyTo = normMessageId(envelope.inReplyTo || msg.inReplyTo || '');
        const references = normReferences(envelope.references || msg.references || '');
        const threadId = buildThreadId(messageId, inReplyTo, references, subject);
        const bodyParts = resolveBodyPartKeys(msg.bodyStructure || null);
        const flags = msg.flags || new Set();
        const isSeen = flags.has('\\Seen') ? 1 : 0;
        const isFlagged = flags.has('\\Flagged') ? 1 : 0;
        let hasAttach = 0;
        if (msg.bodyStructure?.childNodes) {
            for (const p of msg.bodyStructure.childNodes) {
                if (p.disposition === 'attachment') { hasAttach = 1; break; }
            }
        }
        newMails.push({
            uid: msg.uid,
            messageId,
            subject,
            fromAddr,
            toAddr,
            date,
            isSeen,
            isFlagged,
            hasAttach,
            inReplyTo,
            references,
            threadId,
            htmlPart: bodyParts.htmlPart || '',
            textPart: bodyParts.textPart || '',
            bodyHtml: '',
            flags: isSeen ? '\\Seen' : '',
            preview: subject,
        });
    }

    if (newMails.length === 0) {
        return { fetched: 0, synced: 0, unread: unreadCacheByAccount.get(String(accIdx)) || 0, insertedUids: [] };
    }

    const maxUid = Math.max(...newMails.map((m) => parseInt(m.uid, 10) || 0));
    entry.lastUidNext = maxUid + 1;
    entry.lastEventAt = Date.now();

    return enqueueAccountSyncTask(accIdx, async () => {
        const uidvRow = await query`SELECT uidvalidity FROM Tb_Mail_FolderSyncState WHERE account_idx = ${accIdx} AND folder = 'INBOX'`;
        const uidvalidity = uidvRow.recordset[0]?.uidvalidity || 0;

        const existingSet = await fetchExistingInboxUidSet(accIdx, newMails.map((m) => m.uid));
        const insertTargets = newMails.filter((m) => !existingSet.has(parseInt(m.uid, 10)));
        if (insertTargets.length > 0) {
            await hydrateMailHtmlBodies(client, insertTargets);
        }
        const insertResult = insertTargets.length > 0
            ? await insertInboxBatch(accIdx, uidvalidity, insertTargets)
            : { inserted: 0, insertedUids: [] };

        try {
            await query`
                UPDATE Tb_Mail_FolderSyncState
                SET last_uid = ${maxUid}, last_synced_at = GETDATE()
                WHERE account_idx = ${accIdx} AND folder = 'INBOX'
            `;
        } catch (_) {}

        const unread = await loadUnreadCount(accIdx);
        unreadCacheByAccount.set(String(accIdx), unread);
        await routeMailNotification(entry, insertResult.inserted, unread, insertResult.insertedUids, insertTargets[0] || newMails[0]);

        dbg('DB 후처리 [%d] fetched=%d synced=%d unread=%d uid<=%d', accIdx, newMails.length, insertResult.inserted, unread, maxUid);
        return {
            fetched: newMails.length,
            synced: insertResult.inserted,
            unread,
            insertedUids: insertResult.insertedUids,
        };
    });
}

async function processFetchTask(task) {
    if (!task || !task.key) return;
    const entry = imapClients.get(task.accountIdx);
    if (!entry || !entry.client || !entry.client.usable) {
        fetchProcessingKeys.delete(task.key);
        return;
    }

    try {
        entry.lastEventAt = Date.now();
        log('FETCH 시작 [%d] uidFrom=%d retry=%d', task.accountIdx, task.uidFrom, task.retry || 0);
        const result = await fetchAndPersistFromUid(entry, task.uidFrom);
        log('FETCH 결과 [%d] uidFrom=%d fetched=%d synced=%d unread=%d',
            task.accountIdx,
            task.uidFrom,
            parseInt(result?.fetched, 10) || 0,
            parseInt(result?.synced, 10) || 0,
            parseInt(result?.unread, 10) || 0
        );
        fetchProcessingKeys.delete(task.key);
    } catch (e) {
        const nextRetry = (task.retry || 0) + 1;
        warn('FETCH 실패 [%d] uidFrom=%d retry=%d: %s', task.accountIdx, task.uidFrom, nextRetry, e.message);
        if (nextRetry <= FETCH_RETRY_MAX) {
            const backoff = FETCH_RETRY_BACKOFF_MS[Math.min(nextRetry - 1, FETCH_RETRY_BACKOFF_MS.length - 1)];
            fetchProcessingKeys.delete(task.key);
            enqueueFetchTask(task.accountIdx, task.uidFrom, nextRetry, Date.now() + backoff, 'retry_backoff');
            return;
        }
        fetchProcessingKeys.delete(task.key);
        await markCronFallback(task.accountIdx, `fetch_retry_exceeded(uidFrom=${task.uidFrom})`);
        const account = accountByIdx.get(String(task.accountIdx)) || entry.account;
        scheduleReconnect(account, 1, 'fetch_retry_exceeded');
    }
}

async function runFetchQueueWorker() {
    fetchWorkersRunning += 1;
    try {
        while (true) {
            const task = popNextFetchTask();
            if (!task) break;
            await processFetchTask(task);
        }
    } finally {
        fetchWorkersRunning -= 1;
    }
}

function hasReadyFetchTask() {
    const now = Date.now();
    return fetchPriorityQueue.some((task) => task && task.availableAt <= now);
}

function startFetchQueueLoop() {
    if (fetchQueueTimer) return;
    fetchQueueTimer = setInterval(() => {
        if (!hasReadyFetchTask()) return;
        while (fetchWorkersRunning < IMAP_FETCH_CONCURRENCY) {
            if (!hasReadyFetchTask()) break;
            void runFetchQueueWorker();
        }
    }, 250);
}

async function shouldIdleAccount(account) {
    const accIdx = parseInt(account?.account_idx, 10) || 0;
    const userPk = String(account?.user_pk || '').trim();
    if (accIdx <= 0) return { shouldStart: false, unread: 0, online: false, active: false, overLimit: false };

    let unread = unreadCacheByAccount.get(String(accIdx));
    if (!Number.isFinite(unread)) {
        unread = await loadUnreadCount(accIdx);
        unreadCacheByAccount.set(String(accIdx), unread);
    }

    const online = userPk ? await isUserOnline(userPk) : false;
    if (online) markUserActive(userPk);
    const active = online || isUserRecentlyActive(userPk);
    const overLimit = unread > IDLE_UNREAD_KEEP_MAX;
    const unreadEligible = unread > 0 && unread <= IDLE_UNREAD_KEEP_MAX;
    const shouldStart = (active || unreadEligible) && !overLimit;
    return { shouldStart, unread, online, active, overLimit };
}

async function stopImapIdle(accountIdx, reason = 'stop') {
    const key = String(accountIdx);
    const timer = idleStopTimersByAccount.get(key);
    if (timer) {
        clearTimeout(timer);
        idleStopTimersByAccount.delete(key);
    }
    clearReconnectTimer(accountIdx);
    await releaseIdleOwnerLock(accountIdx);

    const entry = imapClients.get(accountIdx);
    if (!entry) return;

    entry.isStopping = true;
    imapClients.delete(accountIdx);
    try { await entry.client.logout(); } catch (_) {}
    unreadCacheByAccount.delete(String(accountIdx));
    accountSyncQueues.delete(String(accountIdx));
    log('IMAP IDLE 종료: [%d] (%s)', accountIdx, reason);
}

async function startImapIdle(account, reason = 'sync') {
    const accIdx = parseInt(account?.account_idx, 10) || 0;
    if (accIdx <= 0 || imapClients.has(accIdx)) return;
    const ownerOk = await acquireIdleOwnerLock(accIdx, reason);
    if (!ownerOk) return;
    if (imapClients.size >= IMAP_GLOBAL_HARD_CAP) {
        warn('IMAP hard cap(%d) 도달: [%d] 시작 거부', IMAP_GLOBAL_HARD_CAP, accIdx);
        await markCronFallback(accIdx, 'hard_cap_reached');
        await releaseIdleOwnerLock(accIdx);
        return;
    }

    clearReconnectTimer(accIdx);
    accountByIdx.set(String(accIdx), account);

    const client = new ImapFlow({
        host: account.imap_host,
        port: account.imap_port || 993,
        secure: !!account.imap_ssl,
        auth: { user: account.imap_username, pass: account.imap_password },
        logger: false,
        tls: { rejectUnauthorized: false },
        idleTimeout: 25 * 60 * 1000,
    });

    const entry = {
        client,
        account,
        accountIdx: accIdx,
        retryCount: parseInt(account._retryCount, 10) || 0,
        lastEventAt: Date.now(),
        lastUidNext: 1,
        isStopping: false,
    };
    imapClients.set(accIdx, entry);

    client.on('error', (e) => {
        warn('IMAP 에러 [%d] %s: %s', accIdx, account.email, e.message);
        entry.lastEventAt = Date.now();
    });

    client.on('close', () => {
        const wasStopping = !!entry.isStopping;
        imapClients.delete(accIdx);
        void releaseIdleOwnerLock(accIdx);
        if (wasStopping) return;
        const nextRetry = (entry.retryCount || 0) + 1;
        scheduleReconnect(account, nextRetry, 'close');
    });

    try {
        await client.connect();
        log('IMAP IDLE 시작: [%d] %s (%s) reason=%s', accIdx, account.email, account.imap_host, reason);
        entry.retryCount = 0;

        const lock = await client.getMailboxLock('INBOX');
        try {
            const status = await client.status('INBOX', { uidNext: true, messages: true });
            entry.lastUidNext = Math.max(1, parseInt(status.uidNext, 10) || 1);
            entry.lastEventAt = Date.now();

            client.on('exists', (data) => {
                entry.lastEventAt = Date.now();
                const uidHint = entry.lastUidNext || 1;
                const existsCount = parseInt(data?.count, 10) || 0;
                log('IMAP EXISTS [%d] count=%d uidHint=%d', accIdx, existsCount, uidHint);
                enqueueFetchTask(accIdx, uidHint, 0, Date.now(), 'exists');
            });

            while (client.usable) {
                await client.idle();
                entry.lastEventAt = Date.now();
                try {
                    const poll = await client.status('INBOX', { uidNext: true, messages: true });
                    const prevUidNext = Math.max(1, parseInt(entry.lastUidNext, 10) || 1);
                    const polledUidNext = Math.max(1, parseInt(poll?.uidNext, 10) || prevUidNext);
                    if (polledUidNext > prevUidNext) {
                        const delta = polledUidNext - prevUidNext;
                        log('IDLE poll fallback [%d] uidNext %d -> %d (+%d)', accIdx, prevUidNext, polledUidNext, delta);
                        enqueueFetchTask(accIdx, prevUidNext, 0, Date.now(), 'idle_poll');
                    }
                    entry.lastUidNext = Math.max(prevUidNext, polledUidNext);
                } catch (pollErr) {
                    warn('IDLE poll 실패 [%d]: %s', accIdx, pollErr.message);
                }
            }
        } finally {
            try { lock.release(); } catch (_) {}
        }
    } catch (e) {
        imapClients.delete(accIdx);
        await releaseIdleOwnerLock(accIdx);
        const nextRetry = (entry.retryCount || 0) + 1;
        warn('IMAP 연결 실패 [%d] retry=%d: %s', accIdx, nextRetry, e.message);
        scheduleReconnect(account, nextRetry, 'connect_fail');
    }
}

function startIdleOwnerLockRenewTimer() {
    if (idleOwnerLockTimer) return;
    idleOwnerLockTimer = setInterval(async () => {
        if (idleOwnerLockRenewRunning) return;
        idleOwnerLockRenewRunning = true;
        try {
            for (const [accIdx, entry] of imapClients) {
                if (!entry || entry.isStopping) continue;
                const ok = await renewIdleOwnerLock(accIdx);
                if (ok) continue;
                warn('IDLE owner lock 상실 [%d] — 연결 종료 후 재연결 시도', accIdx);
                const account = accountByIdx.get(String(accIdx)) || entry.account;
                await stopImapIdle(accIdx, 'owner_lock_lost');
                scheduleReconnect(account, 1, 'owner_lock_lost');
            }
        } finally {
            idleOwnerLockRenewRunning = false;
        }
    }, IDLE_OWNER_LOCK_RENEW_INTERVAL_MS);
}

function startImapWatchdog() {
    if (imapWatchdogTimer) return;
    imapWatchdogTimer = setInterval(async () => {
        const now = Date.now();
        for (const [accIdx, entry] of imapClients) {
            if (!entry || entry.isStopping) continue;
            if ((now - Number(entry.lastEventAt || 0)) <= IMAP_WATCHDOG_IDLE_MS) continue;
            warn('IMAP watchdog reconnect [%d]: 10분 무이벤트', accIdx);
            const account = accountByIdx.get(String(accIdx)) || entry.account;
            await stopImapIdle(accIdx, 'watchdog');
            scheduleReconnect(account, 1, 'watchdog');
        }
    }, IMAP_WATCHDOG_CHECK_MS);
}

async function syncAccounts() {
    const accounts = await loadAccounts();
    const accountIdSet = new Set();

    for (const account of accounts) {
        const accIdx = parseInt(account.account_idx, 10) || 0;
        if (accIdx <= 0) continue;
        accountIdSet.add(accIdx);
        accountByIdx.set(String(accIdx), account);

        const decision = await shouldIdleAccount(account);
        const running = imapClients.has(accIdx);
        if (decision.overLimit) {
            await markCronFallback(accIdx, 'unread_over_limit');
        }

        if (decision.shouldStart) {
            const stopTimer = idleStopTimersByAccount.get(String(accIdx));
            if (stopTimer) {
                clearTimeout(stopTimer);
                idleStopTimersByAccount.delete(String(accIdx));
            }
            if (!running) {
                await startImapIdle(account, 'policy_start');
            }
            continue;
        }

        if (running) {
            const key = String(accIdx);
            if (!idleStopTimersByAccount.has(key)) {
                const timer = setTimeout(async () => {
                    idleStopTimersByAccount.delete(key);
                    const recheck = await shouldIdleAccount(account);
                    if (!recheck.shouldStart) {
                        await stopImapIdle(accIdx, `policy_stop(unread=${recheck.unread})`);
                    }
                }, IDLE_RELEASE_GRACE_MS);
                idleStopTimersByAccount.set(key, timer);
            }
        }
    }

    for (const [accIdx] of imapClients) {
        if (!accountIdSet.has(accIdx)) {
            await stopImapIdle(accIdx, 'account_removed');
        }
    }
}

// ══════════════════════════════════════
// 메인
// ══════════════════════════════════════
async function main() {
    log('SHVQ V2 Mail Worker 시작...');

    await connectDB();
    await ensureIdleColumn();
    await ensureWsTokenTable();
    fcmNotifier = createFcmNotifier({ getPool: connectDB, log, warn });

    await redisPublisher.connect();
    await redisSubscriber.connect();
    log('Redis 연결 완료 (%s:%d)', CONFIG.redis.host, CONFIG.redis.port);

    await startServer();
    startFetchQueueLoop();
    startImapWatchdog();
    startIdleOwnerLockRenewTimer();

    await syncAccounts();

    setInterval(() => {
        syncAccounts().catch((e) => warn('syncAccounts 오류: %s', e.message));
    }, CONFIG.accountPollInterval);
    log('Worker 준비 완료. IDLE/Queue 대기 중...');
}

// ══════════════════════════════════════
// 프로세스 이벤트
// ══════════════════════════════════════
process.on('SIGINT',  async () => { log('SIGINT 수신'); await shutdown(); });
process.on('SIGTERM', async () => { log('SIGTERM 수신'); await shutdown(); });
process.on('uncaughtException', async (e) => {
    err('미처리 예외:', e.message);
    err(e.stack);
    setTimeout(() => process.exit(1), 5000).unref();
    await shutdown().catch(() => {});
    process.exit(1);
});
process.on('unhandledRejection', async (r) => {
    err('미처리 Promise 거부:', r);
    setTimeout(() => process.exit(1), 5000).unref();
    await shutdown().catch(() => {});
    process.exit(1);
});

async function shutdown() {
    log('종료 중...');
    for (const [accIdx, entry] of imapClients) {
        try { await entry.client.logout(); } catch (_) {}
        await releaseIdleOwnerLock(accIdx);
    }
    imapClients.clear();
    fetchPriorityQueue.length = 0;
    fetchPendingKeys.clear();
    fetchProcessingKeys.clear();
    accountByIdx.clear();
    unreadCacheByAccount.clear();

    for (const t of [wsHeartbeatTimer, sseHeartbeatTimer, debounceCleanupTimer, imapWatchdogTimer, fetchQueueTimer, idleOwnerLockTimer]) {
        if (t) clearInterval(t);
    }
    for (const [, t] of reconnectTimersByAccount) clearTimeout(t);
    for (const [, t] of idleStopTimersByAccount) clearTimeout(t);
    reconnectTimersByAccount.clear();
    idleStopTimersByAccount.clear();

    for (const clientId of [...sseClientsById.keys()]) {
        try { await closeSseClient(clientId, 'shutdown'); } catch (_) {}
    }

    if (fcmNotifier) {
        try { await fcmNotifier.flushAll(); } catch (_) {}
    }

    if (wss) wss.close();
    if (httpServer) httpServer.close();
    if (dbPool) await dbPool.close();
    for (const r of [redisSubscriber, redisPublisher]) {
        try { await r.quit(); } catch (_) { r.disconnect(); }
    }

    log('Worker 종료 완료.');
    process.exit(0);
}

main().catch(e => { err('Worker 시작 실패:', e.message); process.exit(1); });
