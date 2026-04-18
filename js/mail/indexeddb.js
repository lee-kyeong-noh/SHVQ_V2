/* ========================================
   SHVQ V2 — Mail IndexedDB Module
   설계서 v3.2 §3.1 기준
   - DB: shvq_mail_v2, 버전 1
   - Stores: mail_body, mail_headers, search_tokens, sync_meta
   - corruption 자동 복구
   - eviction 3중 체크 (저장 후 / 시작 시 / 5분 주기)
   ======================================== */
'use strict';

window.SHV = window.SHV || {};
SHV.mail  = SHV.mail || {};

SHV.mail.idb = (function () {

    var DB_NAME    = 'shvq_mail_v2';
    var DB_VERSION = 1;
    var MAX_BYTES  = 500 * 1024 * 1024;  // 500MB
    var TRIM_TO    = 400 * 1024 * 1024;  // eviction 목표 400MB
    var EVICT_INTERVAL = 5 * 60 * 1000;  // 5분

    var _db = null;
    var _evictTimer = null;

    /* ══════════════════════════════════════
       1. DB 열기 / 스키마 생성
       ══════════════════════════════════════ */

    function _upgrade(db) {
        // mail_body: 본문 캐시
        if (!db.objectStoreNames.contains('mail_body')) {
            var body = db.createObjectStore('mail_body', { keyPath: 'cacheKey' });
            body.createIndex('by_account',    'accountIdx', { unique: false });
            body.createIndex('by_accessedAt', 'accessedAt', { unique: false });
        }

        // mail_headers: 오프라인 목록 헤더
        if (!db.objectStoreNames.contains('mail_headers')) {
            var hdr = db.createObjectStore('mail_headers', { keyPath: 'cacheKey' });
            hdr.createIndex('by_account_folder_date', ['accountIdx', 'folder', 'date'], { unique: false });
            hdr.createIndex('by_account', 'accountIdx', { unique: false });
        }

        // search_tokens: body 전문 검색
        if (!db.objectStoreNames.contains('search_tokens')) {
            var tok = db.createObjectStore('search_tokens', { autoIncrement: true });
            tok.createIndex('by_account_token', ['accountIdx', 'token'], { unique: false });
            tok.createIndex('by_cache_key', 'cacheKey', { unique: false });
        }

        // sync_meta: 동기화 상태
        if (!db.objectStoreNames.contains('sync_meta')) {
            db.createObjectStore('sync_meta', { keyPath: 'key' });
        }
    }

    /**
     * DB 열기 — corruption 시 자동 삭제 후 재생성
     */
    function open() {
        if (_db) return Promise.resolve(_db);

        return new Promise(function (resolve, reject) {
            _tryOpen(resolve, reject, false);
        });
    }

    function _tryOpen(resolve, reject, isRetry) {
        var req;
        try {
            req = indexedDB.open(DB_NAME, DB_VERSION);
        } catch (e) {
            if (!isRetry) return _recover(resolve, reject);
            return reject(e);
        }

        req.onupgradeneeded = function (e) {
            _upgrade(e.target.result);
        };

        req.onsuccess = function (e) {
            _db = e.target.result;

            _db.onversionchange = function () {
                _db.close();
                _db = null;
            };

            // 영속성 요청 (최초 1회)
            _requestPersist();

            // eviction 주기 타이머 시작
            _startEvictTimer();

            // 시작 시 eviction 1회
            _evictIfNeeded();

            resolve(_db);
        };

        req.onerror = function () {
            if (!isRetry) return _recover(resolve, reject);
            reject(req.error);
        };

        req.onblocked = function () {
            console.warn('[mail.idb] DB blocked — 다른 탭에서 사용 중');
        };
    }

    function _recover(resolve, reject) {
        console.warn('[mail.idb] DB 손상 감지, 삭제 후 재생성');
        var delReq = indexedDB.deleteDatabase(DB_NAME);
        delReq.onsuccess = function () {
            _db = null;
            _tryOpen(resolve, reject, true);
        };
        delReq.onerror = function () {
            reject(delReq.error);
        };
    }

    function _requestPersist() {
        if (navigator.storage && navigator.storage.persist) {
            navigator.storage.persist().then(function (granted) {
                if (granted) console.log('[mail.idb] 영속 저장소 확보');
            });
        }
    }

    function close() {
        if (_db) {
            _db.close();
            _db = null;
        }
        if (_evictTimer) {
            clearInterval(_evictTimer);
            _evictTimer = null;
        }
    }

    /* ══════════════════════════════════════
       2. 트랜잭션 헬퍼
       ══════════════════════════════════════ */

    function _tx(stores, mode) {
        return _db.transaction(stores, mode || 'readonly');
    }

    function _store(name, mode) {
        return _tx(name, mode).objectStore(name);
    }

    function _req(idbReq) {
        return new Promise(function (resolve, reject) {
            idbReq.onsuccess = function () { resolve(idbReq.result); };
            idbReq.onerror   = function () { reject(idbReq.error); };
        });
    }

    function _txComplete(tx) {
        return new Promise(function (resolve, reject) {
            tx.oncomplete = function () { resolve(); };
            tx.onerror    = function () { reject(tx.error); };
            tx.onabort    = function () { reject(tx.error || new Error('tx aborted')); };
        });
    }

    /* ══════════════════════════════════════
       3. cacheKey 생성
       ══════════════════════════════════════ */

    function cacheKey(accountIdx, folder, uid) {
        return accountIdx + '_' + folder + '_' + uid;
    }

    /* ══════════════════════════════════════
       4. mail_body CRUD
       ══════════════════════════════════════ */

    function getBody(accountIdx, folder, uid) {
        return open().then(function () {
            return _req(_store('mail_body').get(cacheKey(accountIdx, folder, uid)));
        });
    }

    function putBody(record) {
        var now = Date.now();
        record.cachedAt   = record.cachedAt || now;
        record.accessedAt = now;
        record.sizeBytes  = record.sizeBytes || _estimateSize(record);

        return open().then(function () {
            return _req(_store('mail_body', 'readwrite').put(record));
        }).then(function () {
            return _evictIfNeeded();
        });
    }

    function touchBody(key) {
        return open().then(function () {
            var store = _store('mail_body', 'readwrite');
            return _req(store.get(key)).then(function (rec) {
                if (rec) {
                    rec.accessedAt = Date.now();
                    return _req(store.put(rec));
                }
            });
        });
    }

    function deleteBody(key) {
        return open().then(function () {
            return _req(_store('mail_body', 'readwrite').delete(key));
        });
    }

    function deleteBodiesByAccount(accountIdx) {
        return open().then(function () {
            var tx    = _tx(['mail_body', 'search_tokens'], 'readwrite');
            var body  = tx.objectStore('mail_body');
            var idx   = body.index('by_account');
            var range = IDBKeyRange.only(accountIdx);
            var keys  = [];

            return new Promise(function (resolve, reject) {
                var cursor = idx.openCursor(range);
                cursor.onsuccess = function (e) {
                    var c = e.target.result;
                    if (c) {
                        keys.push(c.value.cacheKey);
                        c.delete();
                        c.continue();
                    } else {
                        // search_tokens도 같이 삭제
                        var tokStore = tx.objectStore('search_tokens');
                        var tokIdx   = tokStore.index('by_cache_key');
                        var done = 0;
                        if (keys.length === 0) return resolve();

                        keys.forEach(function (ck) {
                            var tokCur = tokIdx.openCursor(IDBKeyRange.only(ck));
                            tokCur.onsuccess = function (e2) {
                                var tc = e2.target.result;
                                if (tc) { tc.delete(); tc.continue(); }
                                else {
                                    done++;
                                    if (done === keys.length) resolve();
                                }
                            };
                        });
                    }
                };
                cursor.onerror = function () { reject(cursor.error); };
            }).then(function () {
                return _txComplete(tx);
            });
        });
    }

    /* ══════════════════════════════════════
       5. mail_headers CRUD
       ══════════════════════════════════════ */

    function getHeaders(accountIdx, folder, opts) {
        opts = opts || {};
        return open().then(function () {
            var store = _store('mail_headers');
            var idx   = store.index('by_account_folder_date');
            var lower = [accountIdx, folder, ''];
            var upper = [accountIdx, folder, '\uffff'];
            var range = IDBKeyRange.bound(lower, upper);
            var list  = [];

            return new Promise(function (resolve, reject) {
                var cursor = idx.openCursor(range, 'prev');  // 최신 먼저
                var count  = 0;
                var limit  = opts.limit || 200;

                cursor.onsuccess = function (e) {
                    var c = e.target.result;
                    if (c && count < limit) {
                        list.push(c.value);
                        count++;
                        c.continue();
                    } else {
                        resolve(list);
                    }
                };
                cursor.onerror = function () { reject(cursor.error); };
            });
        });
    }

    function putHeaders(records) {
        if (!records || !records.length) return Promise.resolve();

        return open().then(function () {
            var store = _store('mail_headers', 'readwrite');
            records.forEach(function (rec) {
                store.put(rec);
            });
            return _txComplete(store.transaction);
        });
    }

    function getHeadersByKeys(keys) {
        if (!keys || !keys.length) return Promise.resolve([]);

        return open().then(function () {
            var store = _store('mail_headers');
            var promises = keys.map(function (k) {
                return _req(store.get(k));
            });
            return Promise.all(promises);
        });
    }

    function deleteHeadersByAccount(accountIdx) {
        return open().then(function () {
            var store = _store('mail_headers', 'readwrite');
            var idx   = store.index('by_account');
            var range = IDBKeyRange.only(accountIdx);

            return new Promise(function (resolve, reject) {
                var cursor = idx.openCursor(range);
                cursor.onsuccess = function (e) {
                    var c = e.target.result;
                    if (c) { c.delete(); c.continue(); }
                    else resolve();
                };
                cursor.onerror = function () { reject(cursor.error); };
            });
        });
    }

    /* ══════════════════════════════════════
       6. search_tokens CRUD
       ══════════════════════════════════════ */

    function putTokens(cacheKeyStr, accountIdx, tokens) {
        if (!tokens || !tokens.length) return Promise.resolve();

        return open().then(function () {
            var store = _store('search_tokens', 'readwrite');

            // 기존 토큰 삭제 후 새로 삽입
            var idx   = store.index('by_cache_key');
            var range = IDBKeyRange.only(cacheKeyStr);

            return new Promise(function (resolve, reject) {
                var cursor = idx.openCursor(range);
                cursor.onsuccess = function (e) {
                    var c = e.target.result;
                    if (c) { c.delete(); c.continue(); }
                    else {
                        // 새 토큰 삽입
                        tokens.forEach(function (tok) {
                            store.add({
                                cacheKey:   cacheKeyStr,
                                accountIdx: accountIdx,
                                token:      tok
                            });
                        });
                        resolve();
                    }
                };
                cursor.onerror = function () { reject(cursor.error); };
            }).then(function () {
                return _txComplete(store.transaction);
            });
        });
    }

    function searchTokens(accountIdx, tokenList) {
        if (!tokenList || !tokenList.length) return Promise.resolve([]);

        return open().then(function () {
            var store = _store('search_tokens');
            var idx   = store.index('by_account_token');
            var promises = tokenList.map(function (tok) {
                var range = IDBKeyRange.only([accountIdx, tok]);
                var keys = [];
                return new Promise(function (resolve, reject) {
                    var cursor = idx.openCursor(range);
                    cursor.onsuccess = function (e) {
                        var c = e.target.result;
                        if (c) {
                            keys.push(c.value.cacheKey);
                            c.continue();
                        } else {
                            resolve(keys);
                        }
                    };
                    cursor.onerror = function () { reject(cursor.error); };
                });
            });

            return Promise.all(promises).then(function (results) {
                // AND 조건: 모든 토큰에 매칭된 cacheKey만
                if (results.length === 0) return [];
                var intersection = results[0];
                for (var i = 1; i < results.length; i++) {
                    var set = new Set(results[i]);
                    intersection = intersection.filter(function (k) { return set.has(k); });
                }
                return Array.from(new Set(intersection));
            });
        });
    }

    /* ══════════════════════════════════════
       7. sync_meta CRUD
       ══════════════════════════════════════ */

    function getMeta(key) {
        return open().then(function () {
            return _req(_store('sync_meta').get(key));
        });
    }

    function setMeta(key, value) {
        return open().then(function () {
            return _req(_store('sync_meta', 'readwrite').put({ key: key, value: value, updatedAt: Date.now() }));
        });
    }

    /* ══════════════════════════════════════
       8. Eviction (LRU, 500MB 상한)
       ══════════════════════════════════════ */

    function _estimateSize(record) {
        var s = 0;
        if (record.bodyHtml) s += record.bodyHtml.length * 2;
        if (record.bodyText) s += record.bodyText.length * 2;
        return s || 1024;
    }

    function _evictIfNeeded() {
        return open().then(function () {
            return _calcTotalSize();
        }).then(function (totalSize) {
            if (totalSize <= MAX_BYTES) return;
            console.log('[mail.idb] eviction 시작: ' + Math.round(totalSize / 1024 / 1024) + 'MB > 500MB');
            return _doEvict(totalSize);
        });
    }

    function _calcTotalSize() {
        return new Promise(function (resolve, reject) {
            var store = _store('mail_body');
            var total = 0;
            var cursor = store.openCursor();
            cursor.onsuccess = function (e) {
                var c = e.target.result;
                if (c) {
                    total += (c.value.sizeBytes || 1024);
                    c.continue();
                } else {
                    resolve(total);
                }
            };
            cursor.onerror = function () { reject(cursor.error); };
        });
    }

    function _doEvict(currentSize) {
        return new Promise(function (resolve, reject) {
            // accessedAt 오래된 순으로 정렬하여 삭제
            var store  = _store('mail_body');
            var idx    = store.index('by_accessedAt');
            var items  = [];

            var cursor = idx.openCursor();
            cursor.onsuccess = function (e) {
                var c = e.target.result;
                if (c) {
                    items.push({ key: c.value.cacheKey, size: c.value.sizeBytes || 1024 });
                    c.continue();
                } else {
                    // 삭제 대상 계산
                    var toDelete = [];
                    var freed = 0;
                    var target = currentSize - TRIM_TO;

                    for (var i = 0; i < items.length && freed < target; i++) {
                        toDelete.push(items[i].key);
                        freed += items[i].size;
                    }

                    if (toDelete.length === 0) return resolve();

                    // 삭제 실행
                    var tx2     = _tx(['mail_body', 'search_tokens'], 'readwrite');
                    var bStore  = tx2.objectStore('mail_body');
                    var tStore  = tx2.objectStore('search_tokens');
                    var tIdx    = tStore.index('by_cache_key');

                    toDelete.forEach(function (ck) {
                        bStore.delete(ck);
                        var tokCur = tIdx.openCursor(IDBKeyRange.only(ck));
                        tokCur.onsuccess = function (e2) {
                            var tc = e2.target.result;
                            if (tc) { tc.delete(); tc.continue(); }
                        };
                    });

                    _txComplete(tx2).then(function () {
                        console.log('[mail.idb] eviction 완료: ' + toDelete.length + '건 삭제, ' + Math.round(freed / 1024 / 1024) + 'MB 확보');
                        resolve();
                    }).catch(reject);
                }
            };
            cursor.onerror = function () { reject(cursor.error); };
        });
    }

    function _startEvictTimer() {
        if (_evictTimer) return;
        _evictTimer = setInterval(function () {
            _evictIfNeeded().catch(function (err) {
                console.warn('[mail.idb] 주기적 eviction 실패:', err);
            });
        }, EVICT_INTERVAL);
    }

    /* ══════════════════════════════════════
       9. 계정 데이터 전체 삭제 (로그아웃/계정 해제)
       ══════════════════════════════════════ */

    function clearAccount(accountIdx) {
        return Promise.all([
            deleteBodiesByAccount(accountIdx),
            deleteHeadersByAccount(accountIdx)
        ]);
    }

    function clearAll() {
        return open().then(function () {
            var names = ['mail_body', 'mail_headers', 'search_tokens', 'sync_meta'];
            var tx = _tx(names, 'readwrite');
            names.forEach(function (name) {
                tx.objectStore(name).clear();
            });
            return _txComplete(tx);
        });
    }

    /* ══════════════════════════════════════
       10. 스토리지 통계
       ══════════════════════════════════════ */

    function getStats() {
        return open().then(function () {
            var result = {};
            var names = ['mail_body', 'mail_headers', 'search_tokens', 'sync_meta'];
            var promises = names.map(function (name) {
                return _req(_store(name).count()).then(function (cnt) {
                    result[name] = cnt;
                });
            });
            return Promise.all(promises).then(function () {
                return _calcTotalSize().then(function (size) {
                    result.totalSizeMB = Math.round(size / 1024 / 1024 * 10) / 10;
                    return result;
                });
            });
        });
    }

    /* ══════════════════════════════════════
       Public API
       ══════════════════════════════════════ */

    return {
        open:  open,
        close: close,

        // cacheKey
        cacheKey: cacheKey,

        // mail_body
        getBody:    getBody,
        putBody:    putBody,
        touchBody:  touchBody,
        deleteBody: deleteBody,

        // mail_headers
        getHeaders:       getHeaders,
        getHeadersByKeys: getHeadersByKeys,
        putHeaders:       putHeaders,

        // search_tokens
        putTokens:    putTokens,
        searchTokens: searchTokens,

        // sync_meta
        getMeta: getMeta,
        setMeta: setMeta,

        // 관리
        clearAccount: clearAccount,
        clearAll:     clearAll,
        getStats:     getStats,
        evict:        _evictIfNeeded
    };

})();
