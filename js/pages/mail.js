/* ========================================
   SHVQ V2 — Mail Module (공통)
   모든 메일 페이지에서 공유하는 API·유틸리티
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

SHV.mail = (function () {

    var API_URL = 'dist_process/saas/Mail.php';

    /* ── API 호출 ── */
    function api(todo, params, method) {
        params = params || {};
        params.todo = todo;
        if (method === 'POST') {
            return SHV.api.post(API_URL, params);
        }
        return SHV.api.get(API_URL, params);
    }

    function apiPost(todo, params) { return api(todo, params, 'POST'); }
    function apiGet(todo, params)  { return api(todo, params, 'GET'); }

    /* ── 폴더 목록 ── */
    function loadFolders(accountIdx) {
        var p = {};
        if (accountIdx) p.account_idx = accountIdx;
        return apiGet('folder_list', p);
    }

    /* ── 메일 목록 ── */
    function loadMailList(opts) {
        var p = {
            folder:  opts.folder || 'INBOX',
            page:    opts.page || 1,
            limit:   opts.limit || 20,
        };
        if (opts.account_idx) p.account_idx = opts.account_idx;
        if (opts.search)      p.search = opts.search;
        if (opts.unread_only) p.unread_only = '1';
        return apiGet('mail_list', p);
    }

    /* ── 메일 상세 ── */
    function loadMailDetail(uid, folder, accountIdx) {
        var p = { uid: uid, folder: folder || 'INBOX' };
        if (accountIdx) p.account_idx = accountIdx;
        return apiGet('mail_detail', p);
    }

    /* ── 읽음 처리 ── */
    function markRead(uids, folder) {
        return apiPost('mail_mark_read', {
            uids: Array.isArray(uids) ? uids.join(',') : uids,
            folder: folder || 'INBOX'
        });
    }

    /* ── 삭제 ── */
    function deleteMail(uids, folder) {
        return apiPost('mail_delete', {
            uids: Array.isArray(uids) ? uids.join(',') : uids,
            folder: folder || 'INBOX'
        });
    }

    /* ── 메일 발송 ── */
    function sendMail(formData) {
        formData.append('todo', 'mail_send');
        return SHV.api.upload(API_URL, formData);
    }

    /* ── 임시저장 ── */
    function saveDraft(params) {
        return apiPost('mail_draft_save', params);
    }

    function loadDraftList(opts) {
        var p = { page: opts.page || 1, limit: opts.limit || 20 };
        if (opts.account_idx) p.account_idx = opts.account_idx;
        return apiGet('mail_draft_list', p);
    }

    function deleteDraft(ids) {
        return apiPost('mail_draft_delete', {
            ids: Array.isArray(ids) ? ids.join(',') : ids
        });
    }

    /* ── 계정 ── */
    function loadAccounts() {
        return apiGet('account_list');
    }

    function saveAccount(params) {
        return apiPost('account_save', params);
    }

    function deleteAccount(idx) {
        return apiPost('account_delete', { idx: idx });
    }

    function testAccount(params) {
        return apiPost('account_test', params);
    }

    /* ── 유틸: 날짜 포맷 ── */
    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var target = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diff = today.getTime() - target.getTime();
        var h = d.getHours();
        var m = String(d.getMinutes()).padStart(2, '0');
        var ampm = h < 12 ? '오전' : '오후';
        var h12 = h % 12 || 12;

        if (diff === 0) return ampm + ' ' + h12 + ':' + m;
        if (diff === 86400000) return '어제';
        return (d.getMonth() + 1) + '/' + d.getDate();
    }

    /* ── 유틸: 이름 첫 글자 ── */
    function initial(name) {
        return name ? name.charAt(0) : '?';
    }

    /* ── 유틸: 체크된 항목 수집 ── */
    function getChecked(container, selector) {
        var result = [];
        container.querySelectorAll(selector || '.mail-item-check input:checked').forEach(function (cb) {
            var item = cb.closest('[data-uid]') || cb.closest('[data-id]');
            if (item) result.push(item.dataset.uid || item.dataset.id);
        });
        return result;
    }

    /* ── 모듈 초기화 (IndexedDB + Push 등록) ── */
    function initModules() {
        // IndexedDB 열기
        if (SHV.mail.idb) {
            SHV.mail.idb.open().catch(function (err) {
                console.warn('[mail] IndexedDB 초기화 실패:', err);
            });
        }

        // FCM Push 등록
        if (SHV.mail.push) {
            SHV.mail.push.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    SHV.mail.push.init();
                }
            });
        }
    }

    /* ── 메일 페이지 진입 시 실시간 연결 ── */
    function connectRealtime(ctx, section, onEvent) {
        if (SHV.mail.realtime) {
            SHV.mail.realtime.connect(ctx, section, onEvent);
        }
    }

    /* ── 메일 페이지 이탈 시 실시간 해제 ── */
    function disconnectRealtime(section) {
        if (SHV.mail.realtime) {
            if (section) { SHV.mail.realtime.unregister(section); }
            else { SHV.mail.realtime.disconnect(); }
        }
    }

    /* ── 캐시된 본문 조회 (cache.js 위임) ── */
    function loadMailDetailCached(uid, folder, accountIdx, serverBodyHash) {
        if (SHV.mail.cache) {
            return SHV.mail.cache.getBody(accountIdx, folder, uid, serverBodyHash);
        }
        // fallback: 캐시 모듈 없으면 직접 서버 호출
        return loadMailDetail(uid, folder, accountIdx).then(function (res) {
            return { bodyHtml: res.data.body_html || '', bodyText: res.data.body_text || '', attachments: res.data.attachments || [], fromCache: false };
        });
    }

    /* ── 검색 (search.js 위임) ── */
    function searchMail(accountIdx, folder, query, opts) {
        if (SHV.mail.search) {
            return SHV.mail.search.search(accountIdx, folder, query, opts);
        }
        // fallback: 서버 검색만
        return loadMailList(Object.assign({ account_idx: accountIdx, folder: folder, search: query }, opts || {}))
            .then(function (res) {
                var list = res && res.data && res.data.list || [];
                return { local: [], server: list, merged: list, localOnly: 0 };
            });
    }

    return {
        api: api, apiPost: apiPost, apiGet: apiGet,
        loadFolders: loadFolders,
        loadMailList: loadMailList,
        loadMailDetail: loadMailDetail,
        loadMailDetailCached: loadMailDetailCached,
        markRead: markRead,
        deleteMail: deleteMail,
        sendMail: sendMail,
        saveDraft: saveDraft,
        loadDraftList: loadDraftList,
        deleteDraft: deleteDraft,
        loadAccounts: loadAccounts,
        saveAccount: saveAccount,
        deleteAccount: deleteAccount,
        testAccount: testAccount,
        searchMail: searchMail,
        initModules: initModules,
        connectRealtime: connectRealtime,
        disconnectRealtime: disconnectRealtime,
        formatDate: formatDate,
        initial: initial,
        getChecked: getChecked
    };

})();
