'use strict';

const fs = require('fs');

let firebaseAdmin = null;
try {
    // optional dependency: 미설치 환경에서도 worker 전체는 살아있도록 보호
    firebaseAdmin = require('firebase-admin');
} catch (_) {
    firebaseAdmin = null;
}

const INVALID_TOKEN_CODES = new Set([
    'messaging/registration-token-not-registered',
    'messaging/invalid-registration-token',
]);

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, ms | 0)));
}

function toInt(value, fallback = 0) {
    const n = parseInt(value, 10);
    return Number.isFinite(n) ? n : fallback;
}

function sanitizeText(value, maxLen = 200) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, maxLen);
}

function createFcmNotifier(options = {}) {
    const getPool = typeof options.getPool === 'function' ? options.getPool : null;
    const log = typeof options.log === 'function' ? options.log : () => {};
    const warn = typeof options.warn === 'function' ? options.warn : () => {};

    const debounceMs = toInt(process.env.FCM_DEBOUNCE_MS, 5000);
    const maxWindowMs = toInt(process.env.FCM_DEBOUNCE_MAX_MS, 60000);
    const retryDelayMs = toInt(process.env.FCM_RETRY_DELAY_MS, 1500);

    const pendingByKey = new Map();
    let isFirebaseReady = false;
    let firebaseReadyChecked = false;
    let warnNoAdminOnce = false;

    function ensureFirebaseReady() {
        if (firebaseReadyChecked) {
            return isFirebaseReady;
        }
        firebaseReadyChecked = true;

        if (!firebaseAdmin) {
            if (!warnNoAdminOnce) {
                warnNoAdminOnce = true;
                warn('firebase-admin 미설치: FCM 전송은 비활성 상태입니다.');
            }
            isFirebaseReady = false;
            return false;
        }

        try {
            if (Array.isArray(firebaseAdmin.apps) && firebaseAdmin.apps.length > 0) {
                isFirebaseReady = true;
                return true;
            }

            const svcJson = String(process.env.FCM_SERVICE_ACCOUNT_JSON || '').trim();
            if (svcJson) {
                const cert = JSON.parse(svcJson);
                firebaseAdmin.initializeApp({
                    credential: firebaseAdmin.credential.cert(cert),
                });
                isFirebaseReady = true;
                return true;
            }

            const svcPath = String(process.env.FCM_SERVICE_ACCOUNT_PATH || '').trim();
            if (svcPath) {
                const cert = JSON.parse(fs.readFileSync(svcPath, 'utf8'));
                firebaseAdmin.initializeApp({
                    credential: firebaseAdmin.credential.cert(cert),
                });
                isFirebaseReady = true;
                return true;
            }

            firebaseAdmin.initializeApp({
                credential: firebaseAdmin.credential.applicationDefault(),
            });
            isFirebaseReady = true;
            return true;
        } catch (e) {
            warn('FCM 초기화 실패: %s', e.message);
            isFirebaseReady = false;
            return false;
        }
    }

    async function getMessaging() {
        if (!ensureFirebaseReady()) {
            return null;
        }
        return firebaseAdmin.messaging();
    }

    async function queryTokens(userPk) {
        if (!getPool) {
            return [];
        }
        const pool = await getPool();
        const req = pool.request();
        req.input('userPk', toInt(userPk, 0));
        const rs = await req.query(`
            SELECT token, device_type
            FROM Tb_Mail_FcmToken
            WHERE user_pk = @userPk
        `);
        return Array.isArray(rs.recordset) ? rs.recordset : [];
    }

    async function deleteInvalidToken(token) {
        if (!getPool || !token) {
            return;
        }
        const pool = await getPool();
        const req = pool.request();
        req.input('token', String(token));
        await req.query(`
            DELETE FROM Tb_Mail_FcmToken
            WHERE token = @token
        `);
    }

    async function markNotified(accountIdx, uidList) {
        const uids = Array.isArray(uidList) ? uidList.map((v) => toInt(v, 0)).filter((v) => v > 0) : [];
        if (!getPool || toInt(accountIdx, 0) <= 0 || uids.length === 0) {
            return;
        }
        const pool = await getPool();
        const req = pool.request();
        req.input('accountIdx', toInt(accountIdx, 0));
        const placeholders = [];
        for (let i = 0; i < uids.length; i++) {
            req.input(`uid${i}`, uids[i]);
            placeholders.push(`@uid${i}`);
        }
        await req.query(`
            UPDATE Tb_Mail_MessageCache
            SET fcm_notified = 1
            WHERE account_idx = @accountIdx
              AND uid IN (${placeholders.join(',')})
        `);
    }

    async function sendToToken(message, token) {
        const messaging = await getMessaging();
        if (!messaging) {
            return false;
        }
        try {
            await messaging.send(message);
            return true;
        } catch (e) {
            const code = String(e && e.code ? e.code : '');
            if (INVALID_TOKEN_CODES.has(code)) {
                try {
                    await deleteInvalidToken(token);
                } catch (deleteErr) {
                    warn('FCM invalid token 삭제 실패: %s', deleteErr.message);
                }
                return false;
            }

            // 일시적 오류 1회 재시도
            await sleep(retryDelayMs);
            try {
                await messaging.send(message);
                return true;
            } catch (retryErr) {
                const retryCode = String(retryErr && retryErr.code ? retryErr.code : '');
                if (INVALID_TOKEN_CODES.has(retryCode)) {
                    try {
                        await deleteInvalidToken(token);
                    } catch (deleteErr2) {
                        warn('FCM invalid token 삭제 실패: %s', deleteErr2.message);
                    }
                }
                warn('FCM 전송 실패(token=%s): %s', String(token).slice(0, 12) + '...', retryErr.message);
                return false;
            }
        }
    }

    function buildImmediatePayload(entry) {
        const sender = sanitizeText(entry.senderPreview || '새 메일');
        const subject = sanitizeText(entry.subjectPreview || '메일이 도착했습니다');
        return {
            title: sender,
            body: subject,
        };
    }

    function buildBatchPayload(entry) {
        const accountName = sanitizeText(entry.accountName || `계정 ${entry.accountIdx}`, 60);
        const count = Math.max(1, toInt(entry.pendingCount, 0));
        return {
            title: `[${accountName}] 새 메일 ${count}건`,
            body: '메일함에서 확인해주세요.',
        };
    }

    async function deliver(entry, payload) {
        const userPk = toInt(entry.userPk, 0);
        if (userPk <= 0) {
            return false;
        }
        let tokens = [];
        try {
            tokens = await queryTokens(userPk);
        } catch (e) {
            warn('FCM 토큰 조회 실패(user_pk=%d): %s', userPk, e.message);
            return false;
        }
        if (!tokens.length) {
            return false;
        }

        const messageBase = {
            notification: {
                title: sanitizeText(payload.title || '새 메일'),
                body: sanitizeText(payload.body || '메일이 도착했습니다.'),
            },
            data: {
                type: 'mail',
                account_idx: String(toInt(entry.accountIdx, 0)),
                click_url: String(entry.clickUrl || '/?r=mail_inbox'),
            },
            webpush: {
                fcmOptions: {
                    link: String(entry.clickUrl || '/?r=mail_inbox'),
                },
            },
            android: {
                priority: 'high',
                ttl: 60 * 60 * 1000,
            },
            apns: {
                headers: { 'apns-priority': '10' },
            },
        };

        let sent = 0;
        for (const row of tokens) {
            const token = String(row && row.token ? row.token : '').trim();
            if (!token) {
                continue;
            }
            const ok = await sendToToken({ ...messageBase, token }, token);
            if (ok) {
                sent++;
            }
        }

        if (sent > 0) {
            try {
                await markNotified(entry.accountIdx, [...entry.uidSet]);
            } catch (e) {
                warn('fcm_notified 업데이트 실패(account=%d): %s', toInt(entry.accountIdx, 0), e.message);
            }
            return true;
        }
        return false;
    }

    function clearTimer(entry) {
        if (entry && entry.timer) {
            clearTimeout(entry.timer);
            entry.timer = null;
        }
    }

    function scheduleFlush(entry, key) {
        clearTimer(entry);
        const now = Date.now();
        const maxAt = entry.windowStartedAt + maxWindowMs;
        const waitMs = Math.max(0, Math.min(debounceMs, maxAt - now));
        entry.timer = setTimeout(() => {
            entry.timer = null;
            void flushKey(key, 'timer');
        }, waitMs);
    }

    async function flushKey(key, reason) {
        const entry = pendingByKey.get(key);
        if (!entry) {
            return;
        }
        clearTimer(entry);

        // 첫 메일만 온 경우 즉시 알림으로 처리됐고 추가 건이 없으면 종료
        if (entry.pendingCount <= 0) {
            pendingByKey.delete(key);
            return;
        }

        const payload = buildBatchPayload(entry);
        const ok = await deliver(entry, payload);
        if (!ok) {
            // 실패 시 fcm_notified=0 유지 → cron fallback 경로 유지
            warn('FCM 묶음 전송 실패(user=%d, account=%d, reason=%s)', toInt(entry.userPk, 0), toInt(entry.accountIdx, 0), reason);
        }
        pendingByKey.delete(key);
    }

    async function queueMail(event = {}) {
        const userPk = toInt(event.userPk, 0);
        const accountIdx = toInt(event.accountIdx, 0);
        if (userPk <= 0 || accountIdx <= 0) {
            return;
        }

        const key = `${userPk}:${accountIdx}`;
        const now = Date.now();
        let entry = pendingByKey.get(key);
        if (!entry) {
            entry = {
                key,
                userPk,
                accountIdx,
                accountName: sanitizeText(event.accountName || ''),
                senderPreview: sanitizeText(event.senderPreview || ''),
                subjectPreview: sanitizeText(event.subjectPreview || ''),
                clickUrl: sanitizeText(event.clickUrl || '/?r=mail_inbox', 500),
                windowStartedAt: now,
                lastAt: now,
                pendingCount: 0,
                uidSet: new Set(),
                timer: null,
            };
            pendingByKey.set(key, entry);
        }

        const uidList = Array.isArray(event.uidList) ? event.uidList : [];
        uidList.forEach((uid) => {
            const n = toInt(uid, 0);
            if (n > 0) {
                entry.uidSet.add(n);
            }
        });
        entry.lastAt = now;

        const incomingCount = Math.max(1, toInt(event.count, 1));
        if (entry.pendingCount === 0 && entry.windowStartedAt === now) {
            // 첫 이벤트: 즉시 1통 전송
            const immediatePayload = buildImmediatePayload(entry);
            const immediateOk = await deliver(entry, immediatePayload);
            if (!immediateOk) {
                warn('FCM 즉시 전송 실패(user=%d, account=%d)', userPk, accountIdx);
            }
            entry.pendingCount += Math.max(0, incomingCount - 1);
        } else {
            entry.pendingCount += incomingCount;
        }

        if (now - entry.windowStartedAt >= maxWindowMs) {
            await flushKey(key, 'max_window');
            return;
        }

        scheduleFlush(entry, key);
        log('FCM debounce enqueue: user=%d account=%d pending=%d', userPk, accountIdx, entry.pendingCount);
    }

    async function flushAll() {
        const keys = [...pendingByKey.keys()];
        for (const key of keys) {
            await flushKey(key, 'shutdown');
        }
    }

    function getPendingCount() {
        return pendingByKey.size;
    }

    return {
        queueMail,
        flushAll,
        getPendingCount,
    };
}

module.exports = {
    createFcmNotifier,
};

