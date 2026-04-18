/* ========================================
   SHVQ V2 — Mail Search Module
   설계서 v3.2 §3.3 기준
   - 토큰화: 한글 2자+ / 영문숫자 2자+
   - 이중 검색: IndexedDB (1순위) + 서버 fallback (2순위)
   - AND 조건: 모든 토큰 매칭된 cacheKey만
   - SHV.mail.idb (indexeddb.js) 의존
   ======================================== */
'use strict';

window.SHV = window.SHV || {};
SHV.mail  = SHV.mail || {};

SHV.mail.search = (function () {

    var idb = SHV.mail.idb;

    /* ══════════════════════════════════════
       1. 토큰화
       ══════════════════════════════════════ */

    /**
     * 텍스트에서 검색 토큰 추출
     * HTML 제거 → 한글 2자+ / 영문숫자 2자+ → 소문자 → 중복 제거
     */
    function tokenize(text) {
        if (!text) return [];

        var cleaned = text
            .replace(/<[^>]+>/g, '')
            .replace(/&[^;]+;/g, ' ')
            .replace(/\s+/g, ' ')
            .toLowerCase();

        var ko = cleaned.match(/[가-힣]{2,}/g) || [];
        var en = cleaned.match(/[a-z0-9]{2,}/g) || [];

        // 중복 제거
        var seen = {};
        var result = [];
        var all = ko.concat(en);
        for (var i = 0; i < all.length; i++) {
            if (!seen[all[i]]) {
                seen[all[i]] = true;
                result.push(all[i]);
            }
        }
        return result;
    }

    /* ══════════════════════════════════════
       2. Body 인덱싱 (본문 열 때 호출)
       ══════════════════════════════════════ */

    /**
     * 메일 본문을 토큰화하여 IndexedDB search_tokens에 저장
     * cache.js의 _saveToCache에서 호출
     */
    function indexBody(cacheKeyStr, accountIdx, bodyContent) {
        var tokens = tokenize(bodyContent);
        if (tokens.length === 0) return Promise.resolve();

        return idb.putTokens(cacheKeyStr, accountIdx, tokens);
    }

    /* ══════════════════════════════════════
       3. 이중 검색
       ══════════════════════════════════════ */

    /**
     * 메일 검색 — IndexedDB + 서버 병렬 실행, 결과 병합
     * @param {number} accountIdx
     * @param {string} folder
     * @param {string} query — 사용자 입력 검색어
     * @param {object} [opts] — { page, limit }
     * @returns {Promise<{local:Array, server:Array, merged:Array, localOnly:number}>}
     */
    function search(accountIdx, folder, query, opts) {
        if (!query || !query.trim()) return Promise.resolve({ local: [], server: [], merged: [], localOnly: 0 });

        opts = opts || {};
        var tokens = tokenize(query);
        if (tokens.length === 0) return Promise.resolve({ local: [], server: [], merged: [], localOnly: 0 });

        // 병렬 실행
        var localP  = _searchLocal(accountIdx, tokens);
        var serverP = _searchServer(accountIdx, folder, query, opts);

        return Promise.all([localP, serverP]).then(function (results) {
            var local  = results[0];
            var server = results[1];

            return _mergeResults(local, server);
        });
    }

    /* ══════════════════════════════════════
       4. IndexedDB 로컬 검색
       ══════════════════════════════════════ */

    function _searchLocal(accountIdx, tokens) {
        return idb.searchTokens(accountIdx, tokens).then(function (cacheKeys) {
            if (!cacheKeys || cacheKeys.length === 0) return [];

            // cacheKey에서 헤더 정보 조회 (public API 사용)
            return idb.getHeadersByKeys(cacheKeys).then(function (headers) {
                // 헤더 없는 cacheKey는 stub 생성
                var headerMap = {};
                headers.forEach(function (h) { if (h) headerMap[h.cacheKey] = h; });

                return cacheKeys.map(function (ck) {
                    return headerMap[ck] || _parseKeyToStub(ck);
                }).filter(Boolean);
            });
        }).catch(function () {
            return [];
        });
    }

    function _parseKeyToStub(cacheKey) {
        /* cacheKey 형식: accountIdx_folder_uid (folder에 _ 포함 가능) */
        var firstUnderscore = cacheKey.indexOf('_');
        var lastUnderscore = cacheKey.lastIndexOf('_');
        if (firstUnderscore < 0 || lastUnderscore <= firstUnderscore) return null;
        return {
            cacheKey:   cacheKey,
            accountIdx: parseInt(cacheKey.substring(0, firstUnderscore), 10),
            folder:     cacheKey.substring(firstUnderscore + 1, lastUnderscore),
            uid:        parseInt(cacheKey.substring(lastUnderscore + 1), 10),
            _localOnly: true
        };
    }

    /* ══════════════════════════════════════
       5. 서버 검색 (subject/from LIKE fallback)
       ══════════════════════════════════════ */

    function _searchServer(accountIdx, folder, query, opts) {
        return SHV.mail.loadMailList({
            account_idx: accountIdx,
            folder:      folder,
            search:      query,
            page:        opts.page || 1,
            limit:       opts.limit || 50
        }).then(function (res) {
            if (!res || !res.success) return [];
            return res.data && res.data.list || res.list || [];
        }).catch(function () {
            return [];
        });
    }

    /* ══════════════════════════════════════
       6. 결과 병합 (uid 중복 제거)
       ══════════════════════════════════════ */

    function _mergeResults(local, server) {
        var serverUids = {};
        var merged = [];

        // 서버 결과 먼저 (정확도 높음)
        server.forEach(function (item) {
            var uid = item.uid || item.UID;
            if (uid) serverUids[uid] = true;
            merged.push(item);
        });

        // 로컬 결과 추가 (서버에 없는 것만)
        var localOnly = 0;
        local.forEach(function (item) {
            var uid = item.uid || item.UID;
            if (uid && !serverUids[uid]) {
                item._fromLocalSearch = true;
                merged.push(item);
                localOnly++;
            }
        });

        return {
            local:     local,
            server:    server,
            merged:    merged,
            localOnly: localOnly
        };
    }

    /* ══════════════════════════════════════
       Public API
       ══════════════════════════════════════ */

    return {
        tokenize:  tokenize,
        indexBody:  indexBody,
        search:    search
    };

})();
