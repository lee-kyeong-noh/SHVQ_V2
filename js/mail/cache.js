/* ========================================
   SHVQ V2 — Mail Body Cache Module
   설계서 v3.2 §3.2 기준
   - IndexedDB 기반 body 캐시
   - bodyHash 비교로 무효화
   - LRU eviction (500MB 상한)
   - SHV.mail.idb (indexeddb.js) 의존
   ======================================== */
'use strict';

window.SHV = window.SHV || {};
SHV.mail  = SHV.mail || {};

SHV.mail.cache = (function () {

    var idb = SHV.mail.idb;

    /* ══════════════════════════════════════
       1. Body 조회 (캐시 우선)
       ══════════════════════════════════════ */

    /**
     * 메일 본문 가져오기 — IndexedDB HIT 시 즉시 반환, MISS 시 서버 호출
     * @param {number} accountIdx
     * @param {string} folder
     * @param {number} uid
     * @param {string} [serverBodyHash] — 서버 MessageCache의 body_hash (목록에서 전달)
     * @returns {Promise<{bodyHtml, bodyText, attachments, fromCache}>}
     */
    function getBody(accountIdx, folder, uid, serverBodyHash) {
        var key = idb.cacheKey(accountIdx, folder, uid);

        return idb.getBody(accountIdx, folder, uid).then(function (cached) {
            // HIT + hash 일치 → 즉시 반환
            if (cached && (!serverBodyHash || cached.bodyHash === serverBodyHash)) {
                idb.touchBody(key);
                return _result(cached, true);
            }

            // MISS 또는 hash 불일치 → 서버 요청
            return _fetchFromServer(accountIdx, folder, uid).then(function (data) {
                // IndexedDB 저장 (비동기, 렌더링 블로킹 X)
                _saveToCache(accountIdx, folder, uid, data);
                return _result(data, false);
            });
        });
    }

    function _result(data, fromCache) {
        return {
            bodyHtml:    data.bodyHtml    || data.body_html || '',
            bodyText:    data.bodyText    || data.body_text || '',
            attachments: data.attachments || [],
            bodyHash:    data.bodyHash    || data.body_hash || '',
            fromCache:   fromCache
        };
    }

    /* ══════════════════════════════════════
       2. 서버에서 본문 가져오기
       ══════════════════════════════════════ */

    function _fetchFromServer(accountIdx, folder, uid) {
        return SHV.mail.loadMailDetail(uid, folder, accountIdx).then(function (res) {
            if (!res || (!res.success && !res.ok)) {
                return Promise.reject(new Error(res && res.message || '메일 상세 조회 실패'));
            }
            return res.data || res;
        });
    }

    /* ══════════════════════════════════════
       3. IndexedDB에 저장 + 토큰화
       ══════════════════════════════════════ */

    function _saveToCache(accountIdx, folder, uid, data) {
        var key = idb.cacheKey(accountIdx, folder, uid);
        var bodyHtml = data.bodyHtml || data.body_html || '';
        var bodyText = data.bodyText || data.body_text || '';

        var record = {
            cacheKey:    key,
            accountIdx:  accountIdx,
            folder:      folder,
            uid:         uid,
            bodyHtml:    bodyHtml,
            bodyText:    bodyText,
            bodyHash:    data.bodyHash || data.body_hash || '',
            attachments: data.attachments || [],
            sizeBytes:   (bodyHtml.length + bodyText.length) * 2
        };

        idb.putBody(record).then(function () {
            // 검색 토큰화 (search.js 로드 여부 체크)
            if (SHV.mail.search && SHV.mail.search.indexBody) {
                SHV.mail.search.indexBody(key, accountIdx, bodyHtml || bodyText);
            }
        }).catch(function (err) {
            console.warn('[mail.cache] 저장 실패:', err);
        });
    }

    /* ══════════════════════════════════════
       4. 캐시 상태 확인
       ══════════════════════════════════════ */

    /**
     * 특정 메일의 캐시 여부 확인 (bodyHash 비교 포함)
     * @returns {Promise<'hit'|'stale'|'miss'>}
     */
    function checkStatus(accountIdx, folder, uid, serverBodyHash) {
        return idb.getBody(accountIdx, folder, uid).then(function (cached) {
            if (!cached) return 'miss';
            if (!serverBodyHash || cached.bodyHash === serverBodyHash) return 'hit';
            return 'stale';
        });
    }

    /* ══════════════════════════════════════
       5. 헤더 캐시 (stale-while-revalidate)
       ══════════════════════════════════════ */

    /**
     * 헤더 목록 가져오기 — IndexedDB 먼저, 서버 응답으로 갱신
     * @param {number} accountIdx
     * @param {string} folder
     * @param {object} opts — { page, limit, search, ... }
     * @param {function} onCached — IndexedDB 결과 즉시 콜백 (stale-while-revalidate)
     * @returns {Promise<Array>} 서버 최신 결과
     */
    function getHeaderList(accountIdx, folder, opts, onCached) {
        // 1. IndexedDB 캐시 먼저 (있으면 즉시 콜백)
        if (typeof onCached === 'function') {
            idb.getHeaders(accountIdx, folder, { limit: (opts && opts.limit) || 20 })
                .then(function (cached) {
                    if (cached && cached.length > 0) {
                        onCached(cached);
                    }
                })
                .catch(function () { /* 무시 — 서버 결과로 커버 */ });
        }

        // 2. 서버 요청 (항상)
        return SHV.mail.loadMailList(Object.assign({
            folder:      folder,
            account_idx: accountIdx
        }, opts || {})).then(function (res) {
            if (!res || !res.success) return [];

            var list = res.data && res.data.list || res.list || [];

            // 3. IndexedDB 헤더 갱신 (비동기)
            _syncHeaders(accountIdx, folder, list);

            return list;
        });
    }

    function _syncHeaders(accountIdx, folder, serverList) {
        if (!serverList || !serverList.length) return;

        var records = serverList.map(function (item) {
            return {
                cacheKey:    idb.cacheKey(accountIdx, folder, item.uid),
                accountIdx:  accountIdx,
                folder:      folder,
                uid:         item.uid,
                subject:     item.subject || '',
                from:        item.from_address || item.from || '',
                to:          item.to_address || item.to || '',
                date:        item.date || '',
                isSeen:      item.is_seen,
                isFlagged:   item.is_flagged,
                hasAttach:   item.has_attachment,
                preview:     item.body_preview || '',
                bodyHash:    item.body_hash || '',
                messageId:   item.message_id || ''
            };
        });

        idb.putHeaders(records).catch(function (err) {
            console.warn('[mail.cache] 헤더 저장 실패:', err);
        });
    }

    /* ══════════════════════════════════════
       6. 캐시 관리
       ══════════════════════════════════════ */

    function clearAccount(accountIdx) {
        return idb.clearAccount(accountIdx);
    }

    function clearAll() {
        return idb.clearAll();
    }

    function getStats() {
        return idb.getStats();
    }

    /* ══════════════════════════════════════
       Public API
       ══════════════════════════════════════ */

    return {
        getBody:       getBody,
        checkStatus:   checkStatus,
        getHeaderList: getHeaderList,
        clearAccount:  clearAccount,
        clearAll:      clearAll,
        getStats:      getStats
    };

})();
