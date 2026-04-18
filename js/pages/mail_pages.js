'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    /* ── 메일 토스트 알림 ── */
    var _toastContainer = null;
    var _toastDedup = {};  /* 5초 내 동일 토스트 방지 */
    function showMailToast(data) {
        var dedupKey = (data.account_idx || '') + '_' + (data.uid || data.subject || '');
        var now = Date.now();
        if (_toastDedup[dedupKey] && now - _toastDedup[dedupKey] < 5000) return;
        _toastDedup[dedupKey] = now;
        /* 30초 지난 키 정리 */
        Object.keys(_toastDedup).forEach(function (k) {
            if (now - _toastDedup[k] > 30000) delete _toastDedup[k];
        });
        if (!_toastContainer) {
            _toastContainer = document.createElement('div');
            _toastContainer.className = 'mail-toast';
            document.body.appendChild(_toastContainer);
        }
        var item = document.createElement('div');
        item.className = 'mail-toast-item';
        item.innerHTML =
            '<div class="mail-toast-icon"><i class="fa fa-envelope"></i></div>' +
            '<div class="mail-toast-content">' +
                '<div class="mail-toast-sender">' + _esc(data.account || data.from || '새 메일') + '</div>' +
                '<div class="mail-toast-subject">' + _esc(data.subject || '새 메일이 도착했습니다.') + '</div>' +
            '</div>' +
            '<button class="mail-toast-close">&times;</button>';

        item.querySelector('.mail-toast-close').addEventListener('click', function (e) {
            e.stopPropagation();
            _removeToast(item);
        });
        item.addEventListener('click', function () {
            _removeToast(item);
        });

        _toastContainer.appendChild(item);

        /* 10초 후 자동 닫힘 */
        setTimeout(function () { _removeToast(item); }, 10000);
    }

    function _removeToast(el) {
        if (!el || !el.parentNode) return;
        el.classList.add('is-leaving');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
    }

    function _esc(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    var LIST_ROUTE_META = {
        mail_inbox: { title: '받은편지함', folderAliases: ['INBOX', '받은편지함'] },
        mail_sent: { title: '보낸편지함', folderAliases: ['SENT', 'Sent', '보낸편지함', '보낸메일함'] },
        mail_spam: { title: '스팸메일함', folderAliases: ['SPAM', 'JUNK', 'Junk', '스팸', '스팸메일함'] },
        mail_archive: { title: '보관메일함', folderAliases: ['ARCHIVE', 'ALL MAIL', 'Archive', '보관메일함'] },
        mail_duplicate: { title: '중복메일함', folderAliases: ['DUPLICATE', '중복메일함'] },
        mail_trash: { title: '휴지통', folderAliases: ['TRASH', 'Trash', '휴지통'] }
    };

    function currentRoute() {
        var p = new URLSearchParams(window.location.search);
        return p.get('r') || 'mail_inbox';
    }

    function q(section, selector) {
        return section.querySelector(selector);
    }

    function qa(section, selector) {
        return Array.prototype.slice.call(section.querySelectorAll(selector));
    }

    function text(value) {
        var s = value == null ? '' : String(value);
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toInt(value, fallback) {
        var n = parseInt(value, 10);
        return Number.isFinite(n) ? n : fallback;
    }

    function _fmtSize(bytes) {
        if (!bytes || bytes <= 0) return '';
        if (bytes < 1024) return bytes + 'B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + 'KB';
        return (bytes / 1048576).toFixed(1) + 'MB';
    }

    function getRouteParams() {
        var params = new URLSearchParams(window.location.search);
        return {
            account_idx: toInt(params.get('account_idx'), 0),
            folder: (params.get('folder') || '').trim(),
            page: Math.max(1, toInt(params.get('page'), 1)),
            draft_id: toInt(params.get('draft_id'), 0),
            reply_mode: (params.get('reply_mode') || '').trim(),
            source_uid: toInt(params.get('source_uid'), 0),
            source_folder: (params.get('source_folder') || '').trim(),
            to: (params.get('to') || '').trim(),
            subject: (params.get('subject') || '').trim()
        };
    }

    function uniqueIntList(values) {
        var map = {};
        var list = [];
        values.forEach(function (v) {
            var n = toInt(v, 0);
            if (n <= 0 || map[n]) {
                return;
            }
            map[n] = true;
            list.push(n);
        });
        return list;
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }
        var d = new Date(value);
        if (!Number.isNaN(d.getTime())) {
            return d.toLocaleString();
        }
        return String(value);
    }

    function splitAddressList(raw) {
        if (!raw) {
            return [];
        }
        return String(raw)
            .split(/[;,\n]+/)
            .map(function (s) { return s.trim(); })
            .filter(Boolean);
    }

    function confirmDialog(options) {
        if (typeof window.shvConfirm === 'function') {
            return window.shvConfirm(options || {});
        }
        if (window.SHV && SHV.toast) {
            SHV.toast.warn('확인 모달을 불러올 수 없습니다.');
        }
        return Promise.resolve(false);
    }

    /* ─────────────────────────────────────────────────────
       showMailOnboarding(section)
       계정 미설정 상태일 때 온보딩 UI를 섹션에 주입합니다.
       헤더(.mail-header)만 남기고 나머지 콘텐츠를 숨긴 뒤
       안내 카드를 삽입합니다.
    ───────────────────────────────────────────────────── */
    function showMailOnboarding(section) {
        /* 기존 헤더 이외 콘텐츠 숨김 */
        var children = section.children;
        for (var i = 0; i < children.length; i++) {
            if (!children[i].classList.contains('mail-header')) {
                children[i].style.display = 'none';
            }
        }

        /* 온보딩 카드 생성 */
        var ob = document.createElement('div');
        ob.className = 'mail-onboarding';
        ob.innerHTML = [
            '<div class="mail-onboarding-icon"><i class="fa fa-envelope-o"></i></div>',
            '<h3 class="mail-onboarding-title">메일 계정을 먼저 설정해주세요</h3>',
            '<p class="mail-onboarding-desc">IMAP / SMTP 계정을 연결하면 웹메일을 사용할 수 있습니다.</p>',
            '<ol class="mail-onboarding-steps">',
            '  <li class="mail-onboarding-step">',
            '    <span class="mail-onboarding-step-num">1</span>',
            '    <span>계정 설정 페이지로 이동</span>',
            '  </li>',
            '  <li class="mail-onboarding-step">',
            '    <span class="mail-onboarding-step-num">2</span>',
            '    <span>IMAP / SMTP 정보 입력</span>',
            '  </li>',
            '  <li class="mail-onboarding-step">',
            '    <span class="mail-onboarding-step-num">3</span>',
            '    <span>연결 테스트 후 저장</span>',
            '  </li>',
            '</ol>',
            '<div class="mail-onboarding-actions">',
            '  <button class="btn btn-glass-primary" data-action="onboarding-go-account">',
            '    <i class="fa fa-cog"></i> 계정 설정하기',
            '  </button>',
            '</div>'
        ].join('');
        section.appendChild(ob);

        /* CTA 버튼 이벤트 */
        ob.addEventListener('click', function (e) {
            if (e.target.closest('[data-action="onboarding-go-account"]')) {
                if (SHV.router && typeof SHV.router.navigate === 'function') {
                    SHV.router.navigate('mail_account_settings');
                }
            }
        });
    }

    function getMailContext(section) {
        return {
            api: section.dataset.mailApi || 'dist_process/saas/Mail.php',
            serviceCode: section.dataset.serviceCode || 'shvq',
            tenantId: toInt(section.dataset.tenantId, 0),
            csrfToken: section.dataset.csrfToken || ''
        };
    }

    function withScope(ctx, payload) {
        var out = Object.assign({}, payload || {});
        out.service_code = ctx.serviceCode;
        out.tenant_id = ctx.tenantId;
        return out;
    }

    function renderResultBadge(code) {
        var resultCode = (code || '').toUpperCase();
        if (resultCode === 'OK') {
            return '<span class="badge badge-success">OK</span>';
        }
        if (resultCode.indexOf('DENY') === 0) {
            return '<span class="badge badge-danger">' + text(resultCode) + '</span>';
        }
        return '<span class="badge badge-warn">' + text(resultCode || 'N/A') + '</span>';
    }

    function setSyncStatus(section, textValue) {
        var box = q(section, '[data-mail-sync-status]');
        if (!box) {
            return;
        }
        var t = box.querySelector('.mail-sync-text');
        var last = box.querySelector('.mail-sync-last-time');
        if (t) {
            t.textContent = textValue;
        }
        if (last) {
            last.textContent = formatDate(new Date().toISOString());
        }
    }

    function createMailListPage(routeName) {
        var state = {
            routeName: routeName,
            accountIdx: 0,
            folder: 'INBOX',
            page: 1,
            limit: 20,
            pages: 1,
            total: 0,
            search: '',
            unreadOnly: false,
            includeDeleted: routeName === 'mail_trash',
            accounts: [],
            folders: [],
            rows: [],
            selectedUids: [],
            selectedIds: [],
            detail: null,
            loading: false,       /* 무한스크롤 로딩 중 플래그 */
            hasMore: true         /* 더 불러올 데이터 있는지 */
        };

        function clearSelection(section) {
            state.selectedUids = [];
            state.selectedIds = [];
            var checks = section.querySelectorAll('[data-row-check]');
            checks.forEach(function (el) {
                el.checked = false;
            });
        }

        function collectSelection(section) {
            var uidList = [];
            var idList = [];
            var checks = section.querySelectorAll('[data-row-check]:checked');
            checks.forEach(function (el) {
                var uid = toInt(el.getAttribute('data-uid'), 0);
                var id = toInt(el.getAttribute('data-id'), 0);
                if (uid > 0) {
                    uidList.push(uid);
                }
                if (id > 0) {
                    idList.push(id);
                }
            });
            state.selectedUids = uniqueIntList(uidList);
            state.selectedIds = uniqueIntList(idList);
        }

        function routeTitle() {
            return (LIST_ROUTE_META[state.routeName] || LIST_ROUTE_META.mail_inbox).title;
        }

        function routeFolderAliases() {
            return (LIST_ROUTE_META[state.routeName] || LIST_ROUTE_META.mail_inbox).folderAliases;
        }

        /* routeName → folder_type 매핑 */
        var ROUTE_TO_TYPE = {
            mail_inbox: 'inbox', mail_sent: 'sent', mail_spam: 'spam',
            mail_archive: 'archive', mail_trash: 'trash', mail_duplicate: 'duplicate'
        };

        function pickFolderFromList(routeParams) {
            /* 1순위: URL의 folder 파라미터 */
            if (routeParams.folder) {
                for (var k = 0; k < state.folders.length; k++) {
                    if ((state.folders[k].folder_key || '') === routeParams.folder) {
                        return routeParams.folder;
                    }
                }
            }

            /* 2순위: folder_type 매칭 (서버가 반환한 type) */
            var targetType = ROUTE_TO_TYPE[state.routeName];
            if (targetType) {
                for (var t = 0; t < state.folders.length; t++) {
                    if ((state.folders[t].folder_type || '').toLowerCase() === targetType) {
                        return state.folders[t].folder_key || state.folders[t].folder_name;
                    }
                }
            }

            /* 3순위: aliases 이름 매칭 */
            var preferred = routeFolderAliases();
            function normalizeKey(v) {
                return String(v || '').trim().toUpperCase();
            }
            for (var i = 0; i < preferred.length; i++) {
                var key = normalizeKey(preferred[i]);
                if (!key) continue;
                for (var j = 0; j < state.folders.length; j++) {
                    var item = state.folders[j];
                    var fk = normalizeKey(item.folder_key || '');
                    var fn = normalizeKey(item.folder_name || '');
                    if (fk === key || fn === key) {
                        return item.folder_key || item.folder_name || 'INBOX';
                    }
                }
            }

            /* 4순위: 첫 번째 폴더 */
            if (state.folders.length > 0) {
                return state.folders[0].folder_key || state.folders[0].folder_name || 'INBOX';
            }
            return 'INBOX';
        }

        function renderFolders(section) {
            var el = q(section, '[data-mail-folder-list]');
            if (!el) return;

            /* 현재 활성 폴더 정보만 표시 (폴더 목록은 왼쪽 사이드바) */
            var cur = state.folders.find(function (f) {
                return String(f.folder_key || f.folder_name) === String(state.folder);
            });
            if (cur) {
                var unread = toInt(cur.unread_count, 0);
                var total = toInt(cur.total_count, 0);
                el.innerHTML = '<span class="mail-folder-current-name">'
                    + text(cur.folder_name || state.folder) + '</span>'
                    + '<span class="mail-folder-current-count">' + unread + '/' + total + '</span>';
                /* 헤더 미읽음 카운트 */
                var unreadEl = q(section, '[data-mail-unread-count]');
                if (unreadEl) {
                    unreadEl.textContent = unread > 0 ? '읽지않은 메일 ' + unread + '건' : '';
                }
            } else {
                el.innerHTML = '<span class="mail-folder-current-name">' + text(state.folder) + '</span>';
            }
        }

        /* ── 사이드바 커스텀 폴더 렌더링 ── */
        var BUILT_IN_TYPES = ['inbox','sent','spam','trash','archive','draft','junk','drafts'];
        function _renderSidebarCustomFolders(folders) {
            var container = document.getElementById('sideMailCustomFolders');
            if (!container) return;

            var custom = folders.filter(function (f) {
                var t = (f.folder_type || '').toLowerCase();
                return BUILT_IN_TYPES.indexOf(t) === -1;
            });

            if (custom.length === 0) {
                container.innerHTML = '';
                return;
            }

            var html = '<div class="side-title"><i class="fa fa-folder-o"></i> 개인메일함</div>';
            custom.forEach(function (f) {
                var key = f.folder_key || f.folder_name || '';
                html += '<a class="side-item" data-custom-folder="' + text(key) + '">'
                    + '<i class="fa fa-folder-o"></i> ' + text(key)
                    + '</a>';
            });
            container.innerHTML = html;

            /* 커스텀 폴더 클릭 → 라우터로 이동 */
            container.querySelectorAll('[data-custom-folder]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    var folder = this.getAttribute('data-custom-folder');
                    if (SHV.router && SHV.router.navigate) {
                        SHV.router.navigate('mail_inbox', { folder: folder });
                    }
                });
            });
        }

        /* ── 아바타 색상 (발신자 해시 기반) ── */
        var AVATAR_COLORS = [
            '#4f8ef7','#34d399','#f59e0b','#ef4444','#a855f7',
            '#06b6d4','#ec4899','#84cc16','#f97316','#6366f1'
        ];
        function avatarColor(name) {
            var hash = 0;
            for (var i = 0; i < name.length; i++) hash = ((hash << 5) - hash) + name.charCodeAt(i);
            return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
        }

        /* ── 단일 메일 아이템 HTML 생성 ── */
        function _renderMailItem(row) {
            var id = toInt(row.id, 0);
            var uid = toInt(row.uid, 0);
            var subject = row.subject || '(제목 없음)';
            var fromRaw = row.from_address || row.from || '';
            var fromName = _extractName(fromRaw);
            var fromEmail = _extractEmail(fromRaw);
            var seen = toInt(row.is_seen, 0) === 1;
            var attach = toInt(row.has_attachment, 0) === 1;
            var preview = row.body_preview || '';
            var dateText = SHV.mail.formatDate(row.date || '');
            var initial = fromName ? fromName.charAt(0).toUpperCase() : '?';
            var color = avatarColor(fromName || fromEmail);
            var threadCount = toInt(row.thread_count, 0);
            var flagged = toInt(row.is_flagged, 0) === 1;

            return ''
                + '<div class="mail-item' + (seen ? '' : ' unread') + '" data-action="mail-row-select" data-id="' + id + '" data-uid="' + uid + '">'
                + '  <div class="mail-item-check">'
                + '    <input type="checkbox" data-row-check data-id="' + id + '" data-uid="' + uid + '" onclick="event.stopPropagation()">'
                + '  </div>'
                + '  <span class="mail-item-star' + (flagged ? ' starred' : '') + '" data-action="mail-toggle-star" data-uid="' + uid + '">'
                + '    <i class="fa fa-star' + (flagged ? '' : '-o') + '"></i>'
                + '  </span>'
                + '  <div class="mail-item-avatar" data-color="' + color + '">' + text(initial) + '</div>'
                + '  <div class="mail-item-content">'
                + '    <div class="mail-item-top">'
                + '      <span class="mail-item-sender">' + text(fromName || fromEmail || '-') + '</span>'
                + '      <div class="mail-item-meta">'
                + (threadCount > 1 ? '<span class="mail-item-thread">' + threadCount + '</span>' : '')
                + (attach ? '<i class="fa fa-paperclip mail-item-attach"></i>' : '')
                + '        <span class="mail-item-date">' + text(dateText) + '</span>'
                + '      </div>'
                + '    </div>'
                + '    <span class="mail-item-subject">' + text(subject) + '</span>'
                + '    <span class="mail-item-preview">' + text(preview.substring(0, 120)) + '</span>'
                + '  </div>'
                + '  <div class="mail-item-actions">'
                + '    <button class="mail-item-action-btn" data-action="mail-quick-read" data-id="' + id + '" data-uid="' + uid + '" title="읽음 처리"><i class="fa fa-envelope-open-o"></i></button>'
                + '    <button class="mail-item-action-btn mail-item-action-btn--danger" data-action="mail-quick-delete" data-id="' + id + '" data-uid="' + uid + '" title="삭제"><i class="fa fa-trash-o"></i></button>'
                + '  </div>'
                + '</div>';
        }

        function renderRows(section) {
            var body = q(section, '[data-mail-list-body]');
            if (!body) return;

            if (state.rows.length === 0) {
                body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-inbox"></i><p>메일이 없습니다</p></div>';
                return;
            }

            body.innerHTML = state.rows.map(function (row) {
                return _renderMailItem(row);
            }).join('');

            /* 아바타 색상 적용 */
            body.querySelectorAll('.mail-item-avatar').forEach(function (el) {
                el.style.backgroundColor = el.getAttribute('data-color') || '#4f8ef7';
                el.setAttribute('data-colored', '1');
            });
        }

        function _extractName(raw) {
            if (!raw) return '';
            var m = raw.match(/^(.+?)\s*<[^>]+>$/);
            return m ? m[1].replace(/^["']|["']$/g, '').trim() : raw.split('@')[0] || '';
        }

        function _extractEmail(raw) {
            if (!raw) return '';
            var m = raw.match(/<([^>]+)>/);
            return m ? m[1] : raw;
        }

        function renderSummary(section) {
            var summary = q(section, '[data-mail-list-summary]');
            if (summary) {
                summary.textContent = '총 ' + state.total + '건';
            }
            var pageText = q(section, '[data-mail-page-text]');
            if (pageText) {
                pageText.textContent = state.page + ' / ' + state.pages;
            }
        }

        function renderDetail(section, detail) {
            var subject = q(section, '[data-mail-detail-subject]');
            var meta = q(section, '[data-mail-detail-meta]');
            var from = q(section, '[data-mail-detail-from]');
            var to = q(section, '[data-mail-detail-to]');
            var cc = q(section, '[data-mail-detail-cc]');
            var attach = q(section, '[data-mail-detail-attach]');
            var body = q(section, '[data-mail-detail-body]');
            var avatar = q(section, '[data-mail-detail-avatar]');

            if (!detail) {
                if (subject) {
                    subject.textContent = '메일 상세';
                }
                if (meta) {
                    meta.textContent = '수신/발신 정보';
                }
                if (from) {
                    from.textContent = '-';
                }
                if (to) {
                    to.textContent = '-';
                }
                if (cc) {
                    cc.textContent = '-';
                }
                if (attach) {
                    attach.textContent = '-';
                }
                if (body) {
                    body.textContent = '내용을 선택하면 상세 본문이 표시됩니다.';
                }
                return;
            }

            if (subject) {
                subject.textContent = detail.subject || '(제목 없음)';
            }
            if (meta) {
                meta.textContent = formatDate(detail.date || '');
            }
            if (from) {
                from.textContent = detail.from_address || '-';
            }
            if (avatar) {
                var detailFromRaw = detail.from_address || '';
                var detailName = _extractName(detailFromRaw);
                var detailInitial = detailName ? detailName.charAt(0).toUpperCase() : '?';
                avatar.textContent = detailInitial;
                avatar.style.backgroundColor = avatarColor(detailName || _extractEmail(detailFromRaw));
            }
            if (to) {
                to.textContent = detail.to_address || '-';
            }
            if (cc) {
                cc.textContent = detail.cc_address || '-';
            }

            if (attach) {
                if (Array.isArray(detail.attachments) && detail.attachments.length > 0) {
                    attach.innerHTML = detail.attachments.map(function (a) {
                        var name = text(a.file_name || a.name || 'file');
                        var sz = a.size ? ' <span class="mail-attach-size">(' + _fmtSize(toInt(a.size, 0)) + ')</span>' : '';
                        return '<span class="mail-attach-item"><i class="fa fa-paperclip"></i> ' + name + sz + '</span>';
                    }).join('');
                } else if (toInt(detail.has_attachment, 0) === 1) {
                    attach.textContent = '첨부 있음';
                } else {
                    attach.textContent = '-';
                }
            }

            if (body) {
                var html = detail.body_html || '';
                if (html) {
                    /* 메일 본문: iframe으로 CSS 격리 (XSS는 서버 sanitize로 방어) */
                    body.innerHTML = '';
                    var iframe = document.createElement('iframe');
                    iframe.className = 'mail-detail-iframe';
                    body.appendChild(iframe);
                    var iDoc = iframe.contentDocument || iframe.contentWindow.document;
                    iDoc.open();
                    iDoc.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
                        + 'body{margin:0;padding:8px;background:#fff;}'
                        + '</style></head><body>' + html + '</body></html>');
                    iDoc.close();
                    /* 자동 높이 조절 — 즉시 + 이미지 로드 후 재측정 */
                    var _resizeIframe = function () {
                        try {
                            var h = iDoc.documentElement.scrollHeight || iDoc.body.scrollHeight;
                            if (h > 0) { iframe.style.height = Math.max(360, h + 20) + 'px'; }
                        } catch (e) {}
                    };
                    _resizeIframe();
                    /* 이미지 로드 완료 시 재측정 */
                    var imgs = iDoc.querySelectorAll('img');
                    if (imgs.length > 0) {
                        var loaded = 0;
                        imgs.forEach(function (img) {
                            if (img.complete) { loaded++; return; }
                            img.addEventListener('load', function () { loaded++; _resizeIframe(); });
                            img.addEventListener('error', function () { loaded++; _resizeIframe(); });
                        });
                        if (loaded === imgs.length) { _resizeIframe(); }
                        /* 안전장치: 3초 후 최종 재측정 */
                        setTimeout(_resizeIframe, 3000);
                    }
                } else {
                    body.textContent = detail.body_text || detail.body_preview || '(본문 없음)';
                }
            }
        }

        function loadAccounts(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, { todo: 'account_list' }))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_list failed');
                    }
                    var items = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                    state.accounts = items;
                    if (items.length === 0) {
                        throw new Error('등록된 메일 계정이 없습니다. 계정설정에서 먼저 등록해주세요.');
                    }

                    var rp = getRouteParams();
                    var accountIdx = rp.account_idx;
                    if (accountIdx <= 0) {
                        accountIdx = toInt(localStorage.getItem('shv_mail_last_account_idx'), 0);
                    }
                    /* user_pk 일치 계정 우선 선택 (is_primary보다 우선) */
                    if (accountIdx <= 0) {
                        var actorUserPk = toInt(section.dataset.userPk, 0);
                        if (actorUserPk > 0) {
                            var myAccount = items.find(function (x) { return toInt(x.user_pk, 0) === actorUserPk; });
                            if (myAccount) { accountIdx = toInt(myAccount.idx, 0); }
                        }
                    }
                    if (accountIdx <= 0) {
                        var primary = items.find(function (x) { return toInt(x.is_primary, 0) === 1; });
                        accountIdx = toInt(primary && primary.idx, 0);
                    }
                    if (accountIdx <= 0) {
                        accountIdx = toInt(items[0].idx, 0);
                    }

                    state.accountIdx = accountIdx;
                    localStorage.setItem('shv_mail_last_account_idx', String(accountIdx));
                    return accountIdx;
                });
        }

        function loadFolders(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, {
                todo: 'folder_list',
                account_idx: state.accountIdx
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'folder_list failed');
                }
                state.folders = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                var rp = getRouteParams();
                state.folder = pickFolderFromList(rp);
                renderFolders(section);
                _renderSidebarCustomFolders(state.folders);
                return state.folder;
            });
        }

        function syncFolder(ctx) {
            return SHV.api.post(ctx.api, withScope(ctx, {
                todo: 'mail_sync',
                account_idx: state.accountIdx,
                folder: state.folder
            }));
        }

        function loadMailList(ctx, section, page, append) {
            state.page = Math.max(1, toInt(page, 1));
            var searchInput = q(section, '[data-mail-search-input]');
            if (searchInput) {
                state.search = String(searchInput.value || '').trim();
            }
            var unreadOnly = q(section, '[data-mail-unread-only]');
            state.unreadOnly = !!(unreadOnly && unreadOnly.checked);

            /* ── stale-while-revalidate: IndexedDB 캐시 먼저 표시 (1페이지만) ── */
            if (!append && SHV.mail.idb && state.accountIdx > 0 && !state.search && state.page === 1) {
                SHV.mail.idb.getHeaders(state.accountIdx, state.folder, { limit: state.limit })
                    .then(function (cached) {
                        if (cached && cached.length > 0 && state.rows.length === 0) {
                            state.rows = cached;
                            renderRows(section);
                        }
                    })
                    .catch(function () {});
            }

            /* ── 로딩 상태 ── */
            state.loading = true;
            if (append) { _showScrollLoader(section); }

            /* ── 서버 요청 ── */
            return SHV.api.get(ctx.api, withScope(ctx, {
                todo: 'mail_list',
                account_idx: state.accountIdx,
                folder: state.folder,
                page: state.page,
                limit: state.limit,
                search: state.search,
                unread_only: state.unreadOnly ? 1 : 0,
                include_deleted: state.includeDeleted ? 1 : 0
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'mail_list failed');
                }
                var newItems = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                state.total = toInt(res.data && res.data.total, 0);
                state.page = toInt(res.data && res.data.page, state.page);
                state.pages = Math.max(1, toInt(res.data && res.data.pages, 1));
                state.hasMore = state.page < state.pages;

                if (append) {
                    /* 무한스크롤: 기존 rows에 추가 (UID 중복 제거) */
                    var existingUids = {};
                    state.rows.forEach(function (r) { if (r.uid) existingUids[r.uid] = true; });
                    var uniqueItems = newItems.filter(function (r) { return !r.uid || !existingUids[r.uid]; });
                    state.rows = state.rows.concat(uniqueItems);
                    _appendRows(section, uniqueItems);
                } else {
                    /* 일반: 교체 */
                    state.rows = newItems;
                    renderRows(section);
                }

                renderSummary(section);
                if (!append) { clearSelection(section); }

                /* ── IndexedDB 헤더 캐시 저장 ── */
                if (SHV.mail.idb && state.accountIdx > 0 && newItems.length > 0) {
                    var headerRecords = newItems.map(function (item) {
                        return {
                            cacheKey:    SHV.mail.idb.cacheKey(state.accountIdx, state.folder, item.uid),
                            accountIdx:  state.accountIdx,
                            folder:      state.folder,
                            uid:         item.uid,
                            subject:     item.subject || '',
                            from:        item.from_address || '',
                            to:          item.to_address || '',
                            date:        item.date || '',
                            isSeen:      item.is_seen,
                            isFlagged:   item.is_flagged,
                            hasAttach:   item.has_attachment,
                            preview:     item.body_preview || '',
                            bodyHash:    item.body_hash || '',
                            messageId:   item.message_id || ''
                        };
                    });
                    SHV.mail.idb.putHeaders(headerRecords).catch(function () {});
                }
            }).finally(function () {
                state.loading = false;
                _hideScrollLoader(section);
            });
        }

        /* ── 무한스크롤: 아이템 append ── */
        function _appendRows(section, newItems) {
            var body = q(section, '[data-mail-list-body]');
            if (!body) return;

            var html = newItems.map(function (row) {
                return _renderMailItem(row);
            }).join('');

            /* 로더 제거 후 append */
            var loader = body.querySelector('.mail-scroll-loader');
            if (loader) loader.remove();

            body.insertAdjacentHTML('beforeend', html);

            /* 아바타 색상 적용 (새로 추가된 것만) */
            body.querySelectorAll('.mail-item-avatar:not([data-colored])').forEach(function (el) {
                el.style.backgroundColor = el.getAttribute('data-color') || '#4f8ef7';
                el.setAttribute('data-colored', '1');
            });
        }

        function _showScrollLoader(section) {
            var body = q(section, '[data-mail-list-body]');
            if (!body || body.querySelector('.mail-scroll-loader')) return;
            body.insertAdjacentHTML('beforeend', '<div class="mail-scroll-loader"><i class="fa fa-spinner fa-spin"></i> 불러오는 중...</div>');
        }

        function _hideScrollLoader(section) {
            var body = q(section, '[data-mail-list-body]');
            if (!body) return;
            var loader = body.querySelector('.mail-scroll-loader');
            if (loader) loader.remove();
        }

        /* ── 무한스크롤: 스크롤 감지 ── */
        function _bindInfiniteScroll(ctx, section) {
            var listEl = q(section, '.mail-list');
            if (!listEl) return;

            listEl.addEventListener('scroll', function () {
                if (state.loading || !state.hasMore) return;
                /* 바닥에서 100px 이내 → 다음 페이지 로드 */
                if (listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 100) {
                    loadMailList(ctx, section, state.page + 1, true).catch(function () {});
                }
            });
        }

        function loadDetail(ctx, section, row) {
            var uid = toInt(row && row.uid, 0);
            var id = toInt(row && row.id, 0);
            if (uid <= 0 && id <= 0) {
                return Promise.resolve();
            }

            /* ── IndexedDB 캐시 우선 조회 ── */
            var serverBodyHash = row && row.body_hash || '';
            if (SHV.mail.cache && uid > 0 && state.accountIdx > 0) {
                return SHV.mail.cache.getBody(state.accountIdx, state.folder, uid, serverBodyHash)
                    .then(function (cached) {
                        /* 캐시 HIT: body 데이터를 detail에 병합 */
                        state.detail = Object.assign({}, row || {}, {
                            body_html: cached.bodyHtml,
                            body_text: cached.bodyText,
                            attachments: cached.attachments && cached.attachments.length ? cached.attachments : (row && row.attachments || [])
                        });
                        renderDetail(section, state.detail);
                    })
                    .catch(function () {
                        /* 캐시 실패 시 서버 직접 호출 (기존 로직) */
                        return _loadDetailFromServer(ctx, section, uid, id);
                    });
            }

            /* IndexedDB 미사용 시 기존 로직 */
            return _loadDetailFromServer(ctx, section, uid, id);
        }

        function _loadDetailFromServer(ctx, section, uid, id) {
            return SHV.api.get(ctx.api, withScope(ctx, {
                todo: 'mail_detail',
                account_idx: state.accountIdx,
                folder: state.folder,
                uid: uid,
                id: id
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'mail_detail failed');
                }
                state.detail = res.data || {};
                renderDetail(section, state.detail);
            });
        }

        function performMarkRead(ctx, section) {
            collectSelection(section);
            var uids = state.selectedUids.slice();
            var ids = state.selectedIds.slice();

            if (uids.length === 0 && ids.length === 0 && state.detail) {
                var duid = toInt(state.detail.uid, 0);
                var did = toInt(state.detail.id, 0);
                if (duid > 0) {
                    uids.push(duid);
                }
                if (did > 0) {
                    ids.push(did);
                }
            }

            if (uids.length === 0 && ids.length === 0) {
                if (SHV.toast) {
                    SHV.toast.warn('대상 메일을 선택해주세요.');
                }
                return;
            }

            SHV.api.post(ctx.api, withScope(ctx, {
                todo: 'mail_mark_read',
                account_idx: state.accountIdx,
                folder: state.folder,
                uid_list: uids.join(','),
                id_list: ids.join(','),
                is_read: 1,
                csrf_token: ctx.csrfToken
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'mail_mark_read failed');
                }
                if (SHV.toast) {
                    SHV.toast.success('읽음 처리되었습니다.');
                }
                return loadMailList(ctx, section, state.page);
            }).catch(function (err) {
                if (SHV.toast) {
                    SHV.toast.error(err.message || '읽음 처리에 실패했습니다.');
                }
            });
        }

        function performDelete(ctx, section) {
            collectSelection(section);
            var uids = state.selectedUids.slice();
            var ids = state.selectedIds.slice();

            if (uids.length === 0 && ids.length === 0 && state.detail) {
                var duid = toInt(state.detail.uid, 0);
                var did = toInt(state.detail.id, 0);
                if (duid > 0) {
                    uids.push(duid);
                }
                if (did > 0) {
                    ids.push(did);
                }
            }

            if (uids.length === 0 && ids.length === 0) {
                if (SHV.toast) {
                    SHV.toast.warn('삭제할 메일을 선택해주세요.');
                }
                return;
            }

            var confirmFn = confirmDialog({ title: '메일 삭제', message: '선택한 메일을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' });

            confirmFn.then(function (ok) {
                if (!ok) {
                    return;
                }
                return SHV.api.post(ctx.api, withScope(ctx, {
                    todo: 'mail_delete',
                    account_idx: state.accountIdx,
                    folder: state.folder,
                    uid_list: uids.join(','),
                    id_list: ids.join(','),
                    csrf_token: ctx.csrfToken
                })).then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'mail_delete failed');
                    }
                    if (SHV.toast) {
                        SHV.toast.success('삭제 처리되었습니다.');
                    }
                    state.detail = null;
                    renderDetail(section, null);
                    return loadMailList(ctx, section, state.page);
                });
            }).catch(function (err) {
                if (err && SHV.toast) {
                    SHV.toast.error(err.message || '삭제에 실패했습니다.');
                }
            });
        }

        function openComposeWithMode(mode) {
            if (!state.detail) {
                if (SHV.toast) {
                    SHV.toast.warn('먼저 메일을 선택해주세요.');
                }
                return;
            }
            var uid = toInt(state.detail.uid, 0);
            if (uid <= 0) {
                if (SHV.toast) {
                    SHV.toast.warn('원본 UID가 없어 답장/전달을 생성할 수 없습니다.');
                }
                return;
            }

            SHV.router.navigate('mail_compose', {
                account_idx: state.accountIdx,
                reply_mode: mode,
                source_uid: uid,
                source_folder: state.folder || 'INBOX'
            });
        }

        function bindEvents(ctx, section) {
            var headerTitle = section.querySelector('h2');
            if (headerTitle) {
                headerTitle.textContent = routeTitle();
            }

            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) {
                    return;
                }

                var action = btn.getAttribute('data-action');
                if (action === 'mail-refresh-folder' || action === 'mail-sync') {
                    var refreshBtn = q(section, '[data-refresh-btn]');
                    var refreshIcon = q(section, '[data-refresh-icon]');
                    if (refreshBtn) { refreshBtn.disabled = true; }
                    if (refreshIcon) { refreshIcon.className = 'fa fa-spinner fa-spin'; }
                    setSyncStatus(section, '동기화 중...');
                    // 1) mail_sync POST (증분 동기화)
                    // 2) folder_list 갱신
                    // 3) mail_list DB 조회
                    syncFolder(ctx)
                        .then(function (res) {
                            var synced = (res && res.data && res.data.synced) || 0;
                            setSyncStatus(section, '동기화 중... (신규 ' + synced + '건)');
                            return loadFolders(ctx, section);
                        })
                        .then(function () { return loadMailList(ctx, section, 1); })
                        .then(function () {
                            setSyncStatus(section, '동기화 완료');
                        })
                        .catch(function (err) {
                            setSyncStatus(section, '동기화 실패');
                            if (SHV.toast) {
                                SHV.toast.error(err.message || '동기화에 실패했습니다.');
                            }
                        })
                        .finally(function () {
                            if (refreshBtn) { refreshBtn.disabled = false; }
                            if (refreshIcon) { refreshIcon.className = 'fa fa-refresh'; }
                        });
                    return;
                }

                if (action === 'mail-search') {
                    var searchInput = q(section, '[data-mail-search-input]');
                    var searchQuery = searchInput ? String(searchInput.value || '').trim() : '';

                    /* IndexedDB + 서버 이중 검색 */
                    if (searchQuery && SHV.mail.searchMail && state.accountIdx > 0) {
                        SHV.mail.searchMail(state.accountIdx, state.folder, searchQuery, {
                            page: 1, limit: state.limit
                        }).then(function (result) {
                            state.rows = result.merged || [];
                            state.total = state.rows.length;
                            state.page = 1;
                            state.pages = 1;
                            state.search = searchQuery;
                            renderRows(section);
                            renderSummary(section);
                            clearSelection(section);
                            if (result.localOnly > 0 && SHV.toast) {
                                SHV.toast.info('본문 검색 ' + result.localOnly + '건 추가 (로컬 캐시)');
                            }
                        }).catch(function (err) {
                            if (SHV.toast) { SHV.toast.error(err.message || '검색에 실패했습니다.'); }
                        });
                    } else {
                        /* 검색어 없거나 모듈 미로드 시 기존 서버 검색 */
                        loadMailList(ctx, section, 1).catch(function (err) {
                            if (SHV.toast) { SHV.toast.error(err.message || '검색에 실패했습니다.'); }
                        });
                    }
                    return;
                }

                if (action === 'mail-compose-open') {
                    SHV.router.navigate('mail_compose', { account_idx: state.accountIdx });
                    return;
                }

                if (action === 'mail-prev-page') {
                    if (state.page > 1) {
                        loadMailList(ctx, section, state.page - 1);
                    }
                    return;
                }

                if (action === 'mail-next-page') {
                    if (state.page < state.pages) {
                        loadMailList(ctx, section, state.page + 1);
                    }
                    return;
                }

                if (action === 'mail-mark-read') {
                    performMarkRead(ctx, section);
                    return;
                }

                if (action === 'mail-delete') {
                    performDelete(ctx, section);
                    return;
                }

                if (action === 'mail-open-drafts') {
                    SHV.router.navigate('mail_drafts', { account_idx: state.accountIdx });
                    return;
                }

                if (action === 'mail-reply') {
                    openComposeWithMode('reply');
                    return;
                }

                if (action === 'mail-reply-all') {
                    openComposeWithMode('reply_all');
                    return;
                }

                if (action === 'mail-forward') {
                    openComposeWithMode('forward');
                    return;
                }

                /* ── 별표 토글 ── */
                if (action === 'mail-toggle-star') {
                    var starUid = toInt(btn.getAttribute('data-uid'), 0);
                    if (starUid <= 0) return;
                    var isStarred = btn.classList.contains('starred');
                    btn.classList.toggle('starred');
                    btn.querySelector('i').className = isStarred ? 'fa fa-star-o' : 'fa fa-star';
                    SHV.api.post(ctx.api, withScope(ctx, {
                        todo: 'mail_flag', account_idx: state.accountIdx,
                        folder: state.folder, uid_list: String(starUid),
                        is_flagged: isStarred ? 0 : 1, csrf_token: ctx.csrfToken
                    })).catch(function () {
                        /* 실패 시 롤백 */
                        btn.classList.toggle('starred');
                        btn.querySelector('i').className = isStarred ? 'fa fa-star' : 'fa fa-star-o';
                    });
                    return;
                }

                /* ── 스팸 이동 ── */
                if (action === 'mail-spam') {
                    collectSelection(section);
                    var spamUids = state.selectedUids.slice();
                    if (spamUids.length === 0) {
                        if (SHV.toast) SHV.toast.warn('스팸 처리할 메일을 선택해주세요.');
                        return;
                    }
                    SHV.api.post(ctx.api, withScope(ctx, {
                        todo: 'mail_spam', account_idx: state.accountIdx,
                        folder: state.folder, uid_list: spamUids.join(','),
                        csrf_token: ctx.csrfToken
                    })).then(function (res) {
                        if (!res || !res.ok) throw new Error(res && res.message || '스팸 처리 실패');
                        if (SHV.toast) SHV.toast.success('스팸 처리되었습니다.');
                        return loadMailList(ctx, section, state.page);
                    }).catch(function (err) {
                        if (SHV.toast) SHV.toast.error(err.message || '스팸 처리에 실패했습니다.');
                    });
                    return;
                }

                /* ── 전체선택 ── */
                if (action === 'mail-check-all') {
                    var allChecked = !!btn.checked;
                    section.querySelectorAll('[data-row-check]').forEach(function (cb) {
                        cb.checked = allChecked;
                    });
                    collectSelection(section);
                    return;
                }

                /* ── 본문 삭제 ── */
                if (action === 'mail-detail-delete') {
                    if (!state.detail) return;
                    var delUid = toInt(state.detail.uid, 0);
                    var delId = toInt(state.detail.id, 0);
                    if (delUid <= 0 && delId <= 0) return;
                    confirmDialog({ title: '메일 삭제', message: '이 메일을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' })
                        .then(function (ok) {
                            if (!ok) return;
                            return SHV.api.post(ctx.api, withScope(ctx, {
                                todo: 'mail_delete', account_idx: state.accountIdx,
                                folder: state.folder,
                                uid_list: delUid > 0 ? String(delUid) : '',
                                id_list: delId > 0 ? String(delId) : '',
                                csrf_token: ctx.csrfToken
                            }));
                        }).then(function (res) {
                            if (!res || !res.ok) return;
                            if (SHV.toast) SHV.toast.success('삭제되었습니다.');
                            state.detail = null;
                            renderDetail(section, null);
                            section.classList.remove('detail-view');
                            return loadMailList(ctx, section, state.page);
                        }).catch(function (err) {
                            if (err && SHV.toast) SHV.toast.error(err.message || '삭제에 실패했습니다.');
                        });
                    return;
                }

                /* ── 자동분류: 발신자 → 폴더 규칙 ── */
                if (action === 'mail-auto-classify') {
                    if (!state.detail) {
                        if (SHV.toast) SHV.toast.warn('메일을 먼저 선택해주세요.');
                        return;
                    }
                    var fromAddr = _extractEmail(state.detail.from_address || '');
                    if (!fromAddr) {
                        if (SHV.toast) SHV.toast.warn('발신자 주소를 확인할 수 없습니다.');
                        return;
                    }
                    /* 폴더 선택 모달 */
                    var folderOptions = state.folders
                        .filter(function (f) { return (f.folder_key || '') !== 'INBOX'; })
                        .map(function (f) {
                            return '<option value="' + text(f.folder_key || f.folder_name) + '">'
                                + text(f.folder_name || f.folder_key) + '</option>';
                        }).join('');

                    if (!folderOptions) {
                        if (SHV.toast) SHV.toast.warn('분류할 폴더가 없습니다.');
                        return;
                    }

                    var modalHtml = '<div class="mail-auto-classify-form">'
                        + '<p class="mail-auto-classify-desc"><b>' + text(fromAddr) + '</b> 발신 메일을 자동 분류합니다.</p>'
                        + '<select class="mail-compose-input" id="autoClassifyFolder">' + folderOptions + '</select>'
                        + '<div class="mail-compose-actions">'
                        + '  <button class="btn btn-glass-primary btn-sm" id="autoClassifySave"><i class="fa fa-check"></i> 저장</button>'
                        + '</div>'
                        + '</div>';

                    if (SHV.modal && SHV.modal.openHtml) {
                        SHV.modal.openHtml(modalHtml, '자동분류 규칙 추가', 'sm');
                        var saveBtn = document.getElementById('autoClassifySave');
                        if (saveBtn) {
                            saveBtn.addEventListener('click', function () {
                                var targetFolder = document.getElementById('autoClassifyFolder').value;
                                if (!targetFolder) return;
                                SHV.api.post(ctx.api, withScope(ctx, {
                                    todo: 'filter_save',
                                    account_idx: state.accountIdx,
                                    from_address: fromAddr,
                                    target_folder: targetFolder,
                                    csrf_token: ctx.csrfToken
                                })).then(function (res) {
                                    if (!res || !res.ok) throw new Error(res && res.message || '저장 실패');
                                    if (SHV.toast) SHV.toast.success(fromAddr + ' → ' + targetFolder + ' 자동분류 규칙이 저장되었습니다.');
                                    if (SHV.modal) SHV.modal.close();
                                }).catch(function (err) {
                                    if (SHV.toast) SHV.toast.error(err.message || '자동분류 규칙 저장에 실패했습니다.');
                                });
                            });
                        }
                    }
                    return;
                }

                if (action === 'mail-open-folder') {
                    var folder = (btn.getAttribute('data-folder') || '').trim();
                    if (!folder) {
                        return;
                    }
                    state.folder = folder;
                    state.detail = null;
                    renderDetail(section, null);
                    renderFolders(section);
                    loadMailList(ctx, section, 1);
                    return;
                }

                if (action === 'mail-back') {
                    section.classList.remove('detail-view');
                    renderDetail(section, null);
                    return;
                }

                if (action === 'mail-row-select') {
                    var rowId = toInt(btn.getAttribute('data-id'), 0);
                    var rowUid = toInt(btn.getAttribute('data-uid'), 0);
                    var row = state.rows.find(function (x) {
                        return toInt(x.uid, 0) === rowUid || toInt(x.id, 0) === rowId;
                    });
                    if (row) {
                        /* V1 스타일: 선택 아이템 활성 표시 */
                        section.querySelectorAll('.mail-item.active').forEach(function (el) { el.classList.remove('active'); });
                        btn.classList.add('active');
                        btn.classList.remove('unread');
                        section.classList.add('detail-view');

                        /* 미읽음이면 서버에 읽음 처리 */
                        if (toInt(row.is_seen, 0) === 0) {
                            row.is_seen = 1;
                            SHV.api.post(ctx.api, withScope(ctx, {
                                todo: 'mail_mark_read',
                                account_idx: state.accountIdx,
                                folder: state.folder,
                                uid_list: toInt(row.uid, 0) > 0 ? String(row.uid) : '',
                                id_list: toInt(row.id, 0) > 0 ? String(row.id) : '',
                                is_read: 1,
                                csrf_token: ctx.csrfToken
                            })).then(function () {
                                /* 폴더 카운트 갱신 */
                                loadFolders(ctx, section);
                            }).catch(function () {});
                        }

                        loadDetail(ctx, section, row).catch(function (err) {
                            section.classList.remove('detail-view');
                            if (SHV.toast) {
                                SHV.toast.error(err.message || '메일 상세 조회에 실패했습니다.');
                            }
                        });
                    }
                    return;
                }

                /* ── hover 퀵 액션: 읽음 ── */
                if (action === 'mail-quick-read') {
                    var qrUid = toInt(btn.getAttribute('data-uid'), 0);
                    var qrId  = toInt(btn.getAttribute('data-id'), 0);
                    if (qrUid <= 0 && qrId <= 0) return;
                    SHV.api.post(ctx.api, withScope(ctx, {
                        todo: 'mail_mark_read',
                        account_idx: state.accountIdx,
                        folder: state.folder,
                        uid_list: qrUid > 0 ? String(qrUid) : '',
                        id_list:  qrId  > 0 ? String(qrId)  : '',
                        is_read: 1,
                        csrf_token: ctx.csrfToken
                    })).then(function (res) {
                        if (!res || !res.ok) return;
                        var item = section.querySelector('.mail-item[data-uid="' + qrUid + '"]')
                                || section.querySelector('.mail-item[data-id="' + qrId + '"]');
                        if (item) item.classList.remove('unread');
                        if (SHV.toast) SHV.toast.success('읽음 처리되었습니다.');
                    }).catch(function () {});
                    return;
                }

                /* ── hover 퀵 액션: 삭제 ── */
                if (action === 'mail-quick-delete') {
                    var qdUid = toInt(btn.getAttribute('data-uid'), 0);
                    var qdId  = toInt(btn.getAttribute('data-id'), 0);
                    if (qdUid <= 0 && qdId <= 0) return;
                    confirmDialog({ title: '메일 삭제', message: '이 메일을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' })
                        .then(function (ok) {
                            if (!ok) return;
                            return SHV.api.post(ctx.api, withScope(ctx, {
                                todo: 'mail_delete',
                                account_idx: state.accountIdx,
                                folder: state.folder,
                                uid_list: qdUid > 0 ? String(qdUid) : '',
                                id_list:  qdId  > 0 ? String(qdId)  : '',
                                csrf_token: ctx.csrfToken
                            })).then(function (res) {
                                if (!res || !res.ok) throw new Error(res && res.message || '삭제 실패');
                                if (SHV.toast) SHV.toast.success('삭제되었습니다.');
                                return loadMailList(ctx, section, state.page);
                            });
                        }).catch(function (err) {
                            if (err && SHV.toast) SHV.toast.error(err.message || '삭제에 실패했습니다.');
                        });
                    return;
                }
            });

            var searchInput = q(section, '[data-mail-search-input]');
            if (searchInput) {
                searchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        /* mail-search 버튼과 동일한 이중검색 로직 */
                        var searchBtn = q(section, '[data-action="mail-search"]');
                        if (searchBtn) { searchBtn.click(); }
                        else { loadMailList(ctx, section, 1); }
                    }
                });
            }

            var unreadOnly = q(section, '[data-mail-unread-only]');
            if (unreadOnly) {
                unreadOnly.addEventListener('change', function () {
                    loadMailList(ctx, section, 1);
                });
            }

            section.addEventListener('change', function (event) {
                var chk = event.target.closest('[data-row-check]');
                if (!chk) {
                    return;
                }
                collectSelection(section);
            });
        }

        var _initSection = null;
        var _keydownHandler = null;

        /* ── 키보드 네비게이션: ↑↓ 이동, Enter 열기, R 답장 ── */
        function _bindKeyNav(ctx, section) {
            _keydownHandler = function (e) {
                /* 텍스트 입력 중이면 무시 */
                var tag = document.activeElement && document.activeElement.tagName.toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    var body = q(section, '[data-mail-list-body]');
                    if (!body) return;
                    var items = Array.prototype.slice.call(body.querySelectorAll('.mail-item'));
                    if (items.length === 0) return;

                    var active = body.querySelector('.mail-item.active');
                    var idx = active ? items.indexOf(active) : -1;

                    e.preventDefault();
                    var nextIdx = e.key === 'ArrowDown'
                        ? Math.min(idx < 0 ? 0 : idx + 1, items.length - 1)
                        : Math.max(idx <= 0 ? 0 : idx - 1, 0);

                    items[nextIdx].click();
                    items[nextIdx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    return;
                }

                if (e.key === 'Enter') {
                    var body = q(section, '[data-mail-list-body]');
                    if (!body) return;
                    var active = body.querySelector('.mail-item.active');
                    if (active) active.click();
                    return;
                }

                if (e.key === 'r' || e.key === 'R') {
                    if (state.detail) openComposeWithMode('reply');
                    return;
                }
            };
            document.addEventListener('keydown', _keydownHandler);
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-list"]');
            if (!section) {
                return;
            }
            _initSection = section;
            var ctx = getMailContext(section);
            var routeParams = getRouteParams();
            bindEvents(ctx, section);
            _bindInfiniteScroll(ctx, section);
            _bindKeyNav(ctx, section);
            renderDetail(section, null);

            /* ── IndexedDB + FCM 모듈 초기화 ── */
            if (SHV.mail.initModules) {
                SHV.mail.initModules();
            }

            loadAccounts(ctx, section)
                .then(function () {
                    return loadFolders(ctx, section);
                })
                .then(function () {
                    return loadMailList(ctx, section, routeParams.page || 1);
                })
                .then(function () {
                    // 첫 로드 시 DB 캐시가 비어있으면 자동 증분 동기화 후 목록 갱신
                    if (state.total === 0 && state.accountIdx > 0) {
                        setSyncStatus(section, '동기화 중...');
                        syncFolder(ctx)
                            .then(function (res) {
                                var synced = (res && res.data && res.data.synced) || 0;
                                if (synced > 0) {
                                    return loadMailList(ctx, section, 1);
                                }
                            })
                            .then(function () { setSyncStatus(section, '동기화 완료'); })
                            .catch(function (err) {
                                setSyncStatus(section, '동기화 실패');
                                if (SHV.toast) { SHV.toast.error(err.message || '메일 동기화에 실패했습니다.'); }
                            });
                    }

                    // 실시간 메일 알림 (IMAP IDLE → WS/SSE → 브라우저)
                    if (state.accountIdx > 0 && (window.WebSocket || window.EventSource) && SHV.mail.realtime) {
                        var _syncDebounce = null;
                        SHV.mail.realtime.connect(ctx, section, function (data) {
                            if (data.type === 'newMail') {
                                showMailToast(data);
                                setSyncStatus(section, '새 메일 수신');
                                if (SHV.notifications) {
                                    SHV.notifications.onWsNewMail(data);
                                }
                            }
                            if (data.type === 'visibility_resumed') {
                                loadFolders(ctx, section)
                                    .then(function () { return loadMailList(ctx, section, 1); })
                                    .catch(function () {});
                            }
                            if (data.type === 'newMail' || data.type === 'mailSynced') {
                                /* 디바운스: 연속 이벤트 시 2초 내 1회만 sync */
                                if (_syncDebounce) clearTimeout(_syncDebounce);
                                _syncDebounce = setTimeout(function () {
                                    _syncDebounce = null;
                                    syncFolder(ctx)
                                        .then(function () { return loadFolders(ctx, section); })
                                        .then(function () { return loadMailList(ctx, section, state.page || 1); })
                                        .catch(function () {});
                                }, 2000);
                            }
                        });
                    }
                })
                .catch(function (err) {
                    if ((err.message || '').indexOf('계정이 없습니다') >= 0) {
                        showMailOnboarding(section);
                        return;
                    }
                    var body = q(section, '[data-mail-list-body]');
                    if (body) {
                        body.innerHTML = '<div class="mail-detail-empty"><i class="fa fa-exclamation-circle"></i><p>' + text(err.message || '메일 데이터를 불러올 수 없습니다.') + '</p></div>';
                    }
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '메일 화면 초기화에 실패했습니다.');
                    }
                });
        }

        function destroy() {
            if (_initSection) {
                if (SHV.mail.realtime) { SHV.mail.realtime.unregister(_initSection); }
                _initSection = null;
            }
            if (_keydownHandler) {
                document.removeEventListener('keydown', _keydownHandler);
                _keydownHandler = null;
            }
        }

        return {
            init: init,
            destroy: destroy
        };
    }

    function createDraftPage() {
        var state = {
            accountIdx: 0,
            page: 1,
            pages: 1,
            limit: 20,
            total: 0,
            rows: [],
            accounts: []
        };

        function renderSummary(section) {
            var summary = q(section, '[data-draft-summary]');
            if (summary) {
                summary.textContent = '총 ' + state.total + '건';
            }
            var pageText = q(section, '[data-draft-page-text]');
            if (pageText) {
                pageText.textContent = state.page + ' / ' + state.pages;
            }
        }

        function renderRows(section) {
            var body = q(section, '[data-draft-list-body]');
            if (!body) {
                return;
            }

            if (state.rows.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="tbl-empty">임시저장 메일이 없습니다.</td></tr>';
                return;
            }

            body.innerHTML = state.rows.map(function (row) {
                var id = toInt(row.id, 0);
                return ''
                    + '<tr>'
                    + '  <td class="tbl-cell tbl-cell-center"><input type="checkbox" data-draft-check data-draft-id="' + id + '" /></td>'
                    + '  <td class="tbl-cell">' + id + '</td>'
                    + '  <td class="tbl-cell">' + text(String(row.account_idx || '-')) + '</td>'
                    + '  <td class="tbl-cell">' + text(row.subject || '(제목 없음)') + '</td>'
                    + '  <td class="tbl-cell">' + text(row.to_summary || '-') + '</td>'
                    + '  <td class="tbl-cell">' + text(formatDate(row.updated_at || row.created_at || '')) + '</td>'
                    + '  <td class="tbl-cell">'
                    + '    <button type="button" class="btn btn-ghost btn-sm" data-action="draft-edit" data-draft-id="' + id + '">수정</button> '
                    + '    <button type="button" class="btn btn-danger btn-sm" data-action="draft-delete" data-draft-id="' + id + '">삭제</button>'
                    + '  </td>'
                    + '</tr>';
            }).join('');
        }

        function loadAccounts(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, { todo: 'account_list' }))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_list failed');
                    }
                    state.accounts = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                    var select = q(section, '[data-draft-account-filter]');
                    if (!select) {
                        return;
                    }

                    var options = '<option value="">전체 계정</option>';
                    options += state.accounts.map(function (a) {
                        return '<option value="' + toInt(a.idx, 0) + '">' + text(a.display_name || a.account_key || ('#' + a.idx)) + '</option>';
                    }).join('');
                    select.innerHTML = options;

                    var rp = getRouteParams();
                    if (rp.account_idx > 0) {
                        state.accountIdx = rp.account_idx;
                        select.value = String(rp.account_idx);
                    }
                });
        }

        function loadDrafts(ctx, section, page) {
            state.page = Math.max(1, toInt(page, 1));
            return SHV.api.get(ctx.api, withScope(ctx, {
                todo: 'mail_draft_list',
                account_idx: state.accountIdx,
                page: state.page,
                limit: state.limit
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'mail_draft_list failed');
                }
                state.rows = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                state.total = toInt(res.data && res.data.total, 0);
                state.page = toInt(res.data && res.data.page, state.page);
                state.pages = Math.max(1, toInt(res.data && res.data.pages, 1));
                renderRows(section);
                renderSummary(section);
            });
        }

        function selectedDraftIds(section) {
            var checks = section.querySelectorAll('[data-draft-check]:checked');
            var ids = [];
            checks.forEach(function (c) {
                ids.push(toInt(c.getAttribute('data-draft-id'), 0));
            });
            return uniqueIntList(ids);
        }

        function deleteDraft(ctx, draftId) {
            return SHV.api.post(ctx.api, withScope(ctx, {
                todo: 'mail_draft_delete',
                draft_id: draftId,
                csrf_token: ctx.csrfToken
            })).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'mail_draft_delete failed');
                }
                return res;
            });
        }

        function bindEvents(ctx, section) {
            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) {
                    return;
                }
                var action = btn.getAttribute('data-action');

                if (action === 'draft-refresh') {
                    loadDrafts(ctx, section, state.page).catch(function (err) {
                        if (SHV.toast) {
                            SHV.toast.error(err.message || '목록 조회에 실패했습니다.');
                        }
                    });
                    return;
                }

                if (action === 'draft-open-compose') {
                    SHV.router.navigate('mail_compose', { account_idx: state.accountIdx || 0 });
                    return;
                }

                if (action === 'draft-prev-page') {
                    if (state.page > 1) {
                        loadDrafts(ctx, section, state.page - 1);
                    }
                    return;
                }

                if (action === 'draft-next-page') {
                    if (state.page < state.pages) {
                        loadDrafts(ctx, section, state.page + 1);
                    }
                    return;
                }

                if (action === 'draft-edit') {
                    var draftId = toInt(btn.getAttribute('data-draft-id'), 0);
                    var row = state.rows.find(function (x) { return toInt(x.id, 0) === draftId; });
                    if (row) {
                        sessionStorage.setItem('shv_mail_draft_edit', JSON.stringify(row));
                    }
                    SHV.router.navigate('mail_compose', {
                        draft_id: draftId,
                        account_idx: row ? toInt(row.account_idx, 0) : state.accountIdx
                    });
                    return;
                }

                if (action === 'draft-delete') {
                    var singleId = toInt(btn.getAttribute('data-draft-id'), 0);
                    if (singleId <= 0) {
                        return;
                    }

                    var confirmSingle = confirmDialog({ title: '임시저장 삭제', message: '해당 임시저장을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' });

                    confirmSingle.then(function (ok) {
                        if (!ok) {
                            return;
                        }
                        return deleteDraft(ctx, singleId)
                            .then(function () {
                                if (SHV.toast) {
                                    SHV.toast.success('삭제되었습니다.');
                                }
                                return loadDrafts(ctx, section, state.page);
                            });
                    }).catch(function (err) {
                        if (err && SHV.toast) {
                            SHV.toast.error(err.message || '삭제에 실패했습니다.');
                        }
                    });
                    return;
                }

                if (action === 'draft-delete-selected') {
                    var ids = selectedDraftIds(section);
                    if (ids.length === 0) {
                        if (SHV.toast) {
                            SHV.toast.warn('선택된 항목이 없습니다.');
                        }
                        return;
                    }

                    var confirmMulti = confirmDialog({ title: '선택 삭제', message: ids.length + '건을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' });

                    confirmMulti.then(function (ok) {
                        if (!ok) {
                            return;
                        }
                        return ids.reduce(function (p, id) {
                            return p.then(function () { return deleteDraft(ctx, id); });
                        }, Promise.resolve())
                            .then(function () {
                                if (SHV.toast) {
                                    SHV.toast.success(ids.length + '건 삭제되었습니다.');
                                }
                                return loadDrafts(ctx, section, state.page);
                            });
                    }).catch(function (err) {
                        if (err && SHV.toast) {
                            SHV.toast.error(err.message || '선택 삭제에 실패했습니다.');
                        }
                    });
                    return;
                }

                if (action === 'draft-check-all') {
                    var checked = !!btn.checked;
                    section.querySelectorAll('[data-draft-check]').forEach(function (el) {
                        el.checked = checked;
                    });
                }
            });

            var accountFilter = q(section, '[data-draft-account-filter]');
            if (accountFilter) {
                accountFilter.addEventListener('change', function () {
                    state.accountIdx = toInt(this.value, 0);
                    loadDrafts(ctx, section, 1);
                });
            }
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-drafts"]');
            if (!section) {
                return;
            }

            var ctx = getMailContext(section);
            bindEvents(ctx, section);
            loadAccounts(ctx, section)
                .then(function () { return loadDrafts(ctx, section, getRouteParams().page || 1); })
                .catch(function (err) {
                    if ((err.message || '').indexOf('계정이 없습니다') >= 0) {
                        showMailOnboarding(section);
                        return;
                    }
                    var body = q(section, '[data-draft-list-body]');
                    if (body) {
                        body.innerHTML = '<tr><td colspan="7" class="tbl-cell-error">' + text(err.message || '임시저장 목록 조회 실패') + '</td></tr>';
                    }
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '임시보관함 초기화에 실패했습니다.');
                    }
                });
        }

        return {
            init: init,
            destroy: function () {}
        };
    }

    function createComposePage() {

        /* ── TinyMCE 헬퍼 ── */
        var MCE_ID = 'mail-body';
        var MCE_SCRIPT = 'js/tinymce/tinymce.min.js';

        function getEditorContent() {
            if (typeof window.tinymce !== 'undefined' && tinymce.get(MCE_ID)) {
                return tinymce.get(MCE_ID).getContent();
            }
            var ta = document.getElementById(MCE_ID);
            return ta ? ta.value : '';
        }

        function setEditorContent(html) {
            if (typeof window.tinymce !== 'undefined' && tinymce.get(MCE_ID)) {
                tinymce.get(MCE_ID).setContent(html || '');
                return;
            }
            var ta = document.getElementById(MCE_ID);
            if (ta) { ta.value = html || ''; }
        }

        function startTinyMCE() {
            if (typeof window.tinymce === 'undefined') { return; }
            var existing = tinymce.get(MCE_ID);
            if (existing) { existing.remove(); }
            tinymce.init({
                selector: '#' + MCE_ID,
                license_key: 'gpl',
                height: 420,
                min_height: 280,
                language: 'ko_KR',
                language_url: 'js/tinymce/langs/ko_KR.js',
                skin_url: 'js/tinymce/skins/ui/oxide',
                content_css: 'js/tinymce/skins/content/default/content.min.css',
                menubar: false,
                plugins: 'link image table lists advlist autolink code fullscreen searchreplace charmap emoticons wordcount insertdatetime',
                toolbar: 'fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor removeformat | alignleft aligncenter alignright | bullist numlist outdent indent | link image table charmap emoticons | code fullscreen',
                toolbar_mode: 'wrap',
                content_style: 'body { font-family: "Noto Sans KR", -apple-system, sans-serif; font-size: 11pt; color: #1a2a40; line-height: 1.6; padding: 16px 20px; }',
                paste_postprocess: function (plugin, args) {
                    args.node.querySelectorAll('table').forEach(function (tbl) {
                        tbl.style.borderCollapse = 'collapse';
                        tbl.style.width = 'auto';
                        tbl.removeAttribute('border');
                        tbl.querySelectorAll('td, th').forEach(function (cell) {
                            cell.style.border = '1px solid #c0c0c0';
                            cell.style.padding = '4px 8px';
                        });
                    });
                }
            });
        }

        function initTinyMCE() {
            if (typeof window.tinymce !== 'undefined') {
                startTinyMCE();
                return;
            }
            var s = document.createElement('script');
            s.src = MCE_SCRIPT;
            s.onload = function () { startTinyMCE(); };
            document.head.appendChild(s);
        }

        function destroyTinyMCE() {
            if (typeof window.tinymce !== 'undefined' && tinymce.get(MCE_ID)) {
                tinymce.get(MCE_ID).remove();
            }
        }
        /* ── TinyMCE 헬퍼 끝 ── */

        var state = {
            accountIdx: 0,
            draftId: 0,
            routeParams: null,
            accounts: [],
            attachFiles: [],   /* File[] — JS가 직접 관리 */
            policy: {          /* mail_send_policy API에서 로드 (기본값: 백엔드 미응답 시 폴백) */
                maxPerFile:  10 * 1024 * 1024,
                maxTotal:    25 * 1024 * 1024,
                maxPerFileMb: 10,
                maxTotalMb:   25,
                allowedExt:  [],   /* 빈 배열 = 제한 없음 */
                allowedMime: []
            }
        };

        function formatFileSize(bytes) {
            if (bytes < 1024) { return bytes + 'B'; }
            if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(1) + 'KB'; }
            return (bytes / (1024 * 1024)).toFixed(1) + 'MB';
        }

        function totalAttachSize() {
            return state.attachFiles.reduce(function (s, f) { return s + f.size; }, 0);
        }

        function renderAttachList(section) {
            var list = q(section, '[data-mail-attach-list]');
            if (!list) { return; }
            if (state.attachFiles.length === 0) {
                list.innerHTML = '';
                return;
            }
            list.innerHTML = state.attachFiles.map(function (f, idx) {
                return ''
                    + '<li class="mail-attach-chip">'
                    + '  <i class="fa fa-paperclip mail-attach-chip-icon"></i>'
                    + '  <span class="mail-attach-chip-name">' + text(f.name) + '</span>'
                    + '  <span class="mail-attach-chip-size">' + formatFileSize(f.size) + '</span>'
                    + '  <button type="button" class="mail-attach-chip-remove"'
                    + '    data-action="attach-remove" data-attach-idx="' + idx + '" title="제거">'
                    + '    <i class="fa fa-times"></i>'
                    + '  </button>'
                    + '</li>';
            }).join('');
        }

        function addFiles(section, files) {
            var skipped = [];
            var pol = state.policy;

            for (var i = 0; i < files.length; i++) {
                var f = files[i];

                /* 확장자 검증 (allowedExt 있을 때만) */
                if (pol.allowedExt.length > 0) {
                    var dotIdx = f.name.lastIndexOf('.');
                    var ext = dotIdx >= 0 ? f.name.slice(dotIdx + 1).toLowerCase() : '';
                    var extOk = pol.allowedExt.some(function (e) {
                        return e.toLowerCase() === ext;
                    });
                    if (!extOk) {
                        skipped.push(text(f.name) + ' (허용되지 않는 형식)');
                        continue;
                    }
                }

                /* 파일당 용량 */
                if (f.size > pol.maxPerFile) {
                    skipped.push(text(f.name) + ' (' + pol.maxPerFileMb + 'MB 초과)');
                    continue;
                }

                /* 총 용량 */
                if (totalAttachSize() + f.size > pol.maxTotal) {
                    skipped.push(text(f.name) + ' (총 ' + pol.maxTotalMb + 'MB 초과)');
                    continue;
                }

                /* 중복 */
                var dup = state.attachFiles.some(function (a) { return a.name === f.name; });
                if (dup) {
                    skipped.push(text(f.name) + ' (중복)');
                    continue;
                }

                state.attachFiles.push(f);
            }
            if (skipped.length > 0 && SHV.toast) {
                SHV.toast.warn('첨부 제외: ' + skipped.join(', '));
            }
            renderAttachList(section);
        }

        function fillFormFromDraft(section, draft) {
            var form = q(section, '[data-mail-compose-form]');
            if (!form || !draft) {
                return;
            }

            state.draftId = toInt(draft.id, 0);
            if (state.draftId > 0) {
                form.setAttribute('data-draft-id', String(state.draftId));
            }

            if (toInt(draft.account_idx, 0) > 0) {
                state.accountIdx = toInt(draft.account_idx, 0);
                var select = q(section, '[data-mail-account-select]');
                if (select) {
                    select.value = String(state.accountIdx);
                }
            }

            var toInput = q(section, '[data-mail-to]');
            var ccInput = q(section, '[data-mail-cc]');
            var bccInput = q(section, '[data-mail-bcc]');
            var subjectInput = q(section, '[data-mail-subject]');
            var bodyInput = q(section, '[data-mail-body]');
            var modeInput = q(section, '[data-mail-reply-mode]');
            var uidInput = q(section, '[data-mail-source-uid]');
            var folderInput = q(section, '[data-mail-source-folder]');

            if (toInput) {
                toInput.value = Array.isArray(draft.to_list) ? draft.to_list.join(', ') : '';
            }
            if (ccInput) {
                ccInput.value = Array.isArray(draft.cc_list) ? draft.cc_list.join(', ') : '';
            }
            if (bccInput) {
                bccInput.value = Array.isArray(draft.bcc_list) ? draft.bcc_list.join(', ') : '';
            }
            if (subjectInput) {
                subjectInput.value = draft.subject || '';
            }
            setEditorContent(draft.body_html || '');
            if (modeInput) {
                modeInput.value = draft.reply_mode || '';
            }
            if (uidInput) {
                uidInput.value = String(toInt(draft.source_uid, 0));
            }
            if (folderInput) {
                folderInput.value = draft.source_folder || 'INBOX';
            }
        }

        function fillFormFromReplyPayload(section, payload) {
            if (!payload || typeof payload !== 'object') {
                return;
            }
            var toInput = q(section, '[data-mail-to]');
            var ccInput = q(section, '[data-mail-cc]');
            var bccInput = q(section, '[data-mail-bcc]');
            var subjectInput = q(section, '[data-mail-subject]');
            var bodyInput = q(section, '[data-mail-body]');
            var modeInput = q(section, '[data-mail-reply-mode]');
            var uidInput = q(section, '[data-mail-source-uid]');
            var folderInput = q(section, '[data-mail-source-folder]');

            if (toInput && Array.isArray(payload.to)) {
                toInput.value = payload.to.join(', ');
            }
            if (ccInput && Array.isArray(payload.cc)) {
                ccInput.value = payload.cc.join(', ');
            }
            if (bccInput && Array.isArray(payload.bcc)) {
                bccInput.value = payload.bcc.join(', ');
            }
            if (subjectInput && payload.subject) {
                subjectInput.value = payload.subject;
            }
            if (payload.body_html) {
                setEditorContent(payload.body_html);
            }
            if (modeInput && payload.reply_mode) {
                modeInput.value = payload.reply_mode;
            }
            if (uidInput && toInt(payload.source_uid, 0) > 0) {
                uidInput.value = String(toInt(payload.source_uid, 0));
            }
            if (folderInput && payload.source_folder) {
                folderInput.value = payload.source_folder;
            }
        }

        function loadAccounts(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, { todo: 'account_list' }))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_list failed');
                    }
                    state.accounts = Array.isArray(res.data && res.data.items) ? res.data.items : [];

                    var select = q(section, '[data-mail-account-select]');
                    if (!select) {
                        return;
                    }

                    var options = '<option value="">계정을 선택하세요</option>';
                    options += state.accounts.map(function (a) {
                        var idx = toInt(a.idx, 0);
                        var label = a.display_name || a.account_key || ('#' + idx);
                        return '<option value="' + idx + '">' + text(label) + '</option>';
                    }).join('');
                    select.innerHTML = options;

                    var rp = state.routeParams || getRouteParams();
                    var preferred = rp.account_idx;
                    if (preferred <= 0) {
                        preferred = toInt(localStorage.getItem('shv_mail_last_account_idx'), 0);
                    }
                    /* user_pk 일치 계정 우선 선택 */
                    if (preferred <= 0) {
                        var actorUserPk = toInt(section.dataset.userPk, 0);
                        if (actorUserPk > 0) {
                            var myAcc = state.accounts.find(function (x) { return toInt(x.user_pk, 0) === actorUserPk; });
                            if (myAcc) { preferred = toInt(myAcc.idx, 0); }
                        }
                    }
                    if (preferred <= 0) {
                        var primary = state.accounts.find(function (x) { return toInt(x.is_primary, 0) === 1; });
                        preferred = toInt(primary && primary.idx, 0);
                    }
                    if (preferred <= 0 && state.accounts.length > 0) {
                        preferred = toInt(state.accounts[0].idx, 0);
                    }
                    if (preferred > 0) {
                        select.value = String(preferred);
                        state.accountIdx = preferred;
                    }

                    /* 계정 1개면 선택란 숨김 */
                    var field = select.closest('.mail-compose-field');
                    if (state.accounts.length <= 1 && field) {
                        field.style.display = 'none';
                    }
                });
        }

        function loadPolicy(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, { todo: 'mail_send_policy' }))
                .then(function (res) {
                    if (!res || !res.ok || !res.data) { return; }
                    var d = res.data;
                    if (d.max_file_bytes > 0) { state.policy.maxPerFile  = d.max_file_bytes; }
                    if (d.max_total_bytes > 0) { state.policy.maxTotal    = d.max_total_bytes; }
                    if (d.max_file_mb > 0)    { state.policy.maxPerFileMb = d.max_file_mb; }
                    if (d.max_total_mb > 0)   { state.policy.maxTotalMb   = d.max_total_mb; }
                    if (Array.isArray(d.allowed_extensions) && d.allowed_extensions.length > 0) {
                        state.policy.allowedExt = d.allowed_extensions;
                    }
                    if (Array.isArray(d.allowed_mime_types) && d.allowed_mime_types.length > 0) {
                        state.policy.allowedMime = d.allowed_mime_types;
                    }

                    /* 힌트 텍스트 동적 업데이트 */
                    var hint = q(section, '[data-mail-attach-hint]');
                    if (hint) {
                        var extLabel = state.policy.allowedExt.length > 0
                            ? state.policy.allowedExt.slice(0, 6).join(', ')
                              + (state.policy.allowedExt.length > 6 ? ' 외' : '')
                            : '모든 형식';
                        hint.textContent = '또는 여기에 드래그&드롭'
                            + ' (파일당 최대 ' + state.policy.maxPerFileMb + 'MB'
                            + ' / 총 ' + state.policy.maxTotalMb + 'MB'
                            + ' / ' + extLabel + ')';
                    }
                })
                .catch(function () {
                    /* 정책 로드 실패 시 기본값 유지 — 첨부 기능은 계속 동작 */
                });
        }

        function fetchReplyTemplate(ctx, section) {
            var rp = state.routeParams;
            if (!rp || !rp.reply_mode || rp.source_uid <= 0) {
                return Promise.resolve();
            }
            if (state.accountIdx <= 0) {
                return Promise.resolve();
            }

            /* ── reply_mode가 있으면 서버 API 필수 (reply_payload 생성이 서버 로직)
                 단, IndexedDB에 body가 캐시되어 있으면 서버 응답 후 캐시도 갱신 ── */
            return SHV.api.get(ctx.api, withScope(ctx, {
                todo: 'mail_detail',
                account_idx: state.accountIdx,
                folder: rp.source_folder || 'INBOX',
                uid: rp.source_uid,
                reply_mode: rp.reply_mode
            })).then(function (res) {
                if (!res || !res.ok) {
                    return;
                }
                var replyPayload = res.data && res.data.reply_payload ? res.data.reply_payload : null;

                /* 서버 reply_payload 없으면 클라이언트에서 구성 */
                if (!replyPayload && res.data) {
                    var d = res.data;
                    var mode = rp.reply_mode;
                    var quoteHtml = '<br><br>--- 원본 메일 ---<br>'
                        + '보낸사람: ' + text(d.from_address || '') + '<br>'
                        + '날짜: ' + text(d.date || '') + '<br>'
                        + '제목: ' + text(d.subject || '') + '<br><br>'
                        + (d.body_html || d.body_text || '');
                    replyPayload = {
                        to: mode === 'forward' ? [] : [d.from_address || ''],
                        cc: mode === 'reply_all' ? (d.cc_address ? d.cc_address.split(/[;,]/) : []) : [],
                        subject: (mode === 'forward' ? 'FW: ' : 'RE: ') + (d.subject || ''),
                        body_html: quoteHtml,
                        reply_mode: mode,
                        source_uid: rp.source_uid,
                        source_folder: rp.source_folder || 'INBOX'
                    };
                }

                fillFormFromReplyPayload(section, replyPayload);

                /* 답장/전달로 가져온 본문도 IndexedDB에 캐시 */
                if (SHV.mail.cache && res.data && res.data.body_html && rp.source_uid > 0) {
                    var folder = rp.source_folder || 'INBOX';
                    var key = SHV.mail.idb.cacheKey(state.accountIdx, folder, rp.source_uid);
                    SHV.mail.idb.getBody(state.accountIdx, folder, rp.source_uid).then(function (existing) {
                        if (!existing) {
                            SHV.mail.idb.putBody({
                                cacheKey:    key,
                                accountIdx:  state.accountIdx,
                                folder:      folder,
                                uid:         rp.source_uid,
                                bodyHtml:    res.data.body_html || '',
                                bodyText:    res.data.body_text || '',
                                bodyHash:    res.data.body_hash || '',
                                attachments: res.data.attachments || [],
                                sizeBytes:   ((res.data.body_html || '').length + (res.data.body_text || '').length) * 2
                            }).then(function () {
                                if (SHV.mail.search && SHV.mail.search.indexBody) {
                                    SHV.mail.search.indexBody(key, state.accountIdx, res.data.body_html || res.data.body_text || '');
                                }
                            });
                        }
                    }).catch(function () {});
                }
            });
        }

        function readFormPayload(section) {
            var form = q(section, '[data-mail-compose-form]');
            if (!form) {
                return null;
            }

            var formData = new FormData(form);
            var select = q(section, '[data-mail-account-select]');
            state.accountIdx = toInt(select && select.value, 0);

            if (state.accountIdx <= 0) {
                throw new Error('메일 계정을 선택해주세요.');
            }

            formData.set('account_idx', String(state.accountIdx));
            formData.set('service_code', section.dataset.serviceCode || 'shvq');
            formData.set('tenant_id', String(toInt(section.dataset.tenantId, 0)));
            formData.set('csrf_token', section.dataset.csrfToken || '');
            /* TinyMCE 콘텐츠 동기화 */
            formData.set('body_html', getEditorContent());
            if (state.draftId > 0) {
                formData.set('id', String(state.draftId));
            }

            /* 첨부파일: JS가 직접 관리하는 File[] 배열을 수동으로 append */
            state.attachFiles.forEach(function (f) {
                formData.append('attach[]', f, f.name);
            });

            return formData;
        }

        function onSend(ctx, section) {
            var formData;
            try {
                formData = readFormPayload(section);
            } catch (err) {
                if (SHV.toast) {
                    SHV.toast.warn(err.message || '입력값을 확인해주세요.');
                }
                return;
            }

            var toValue = String(formData.get('to') || '').trim();
            var subject = String(formData.get('subject') || '').trim();
            if (toValue === '') {
                if (SHV.toast) {
                    SHV.toast.warn('받는사람은 필수입니다.');
                }
                return;
            }
            if (subject === '') {
                if (SHV.toast) {
                    SHV.toast.warn('제목을 입력해주세요.');
                }
                return;
            }

            /* 발송 중 버튼 비활성화 */
            var sendBtns = Array.prototype.slice.call(section.querySelectorAll('[data-action="mail-send"]'));
            sendBtns.forEach(function (b) {
                b.disabled = true;
                b.setAttribute('data-original-html', b.innerHTML);
                b.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 발송 중...';
            });
            function restoreSendBtns() {
                sendBtns.forEach(function (b) {
                    b.disabled = false;
                    var orig = b.getAttribute('data-original-html');
                    if (orig) { b.innerHTML = orig; b.removeAttribute('data-original-html'); }
                });
            }

            formData.set('todo', 'mail_send');
            SHV.api.upload(ctx.api, formData)
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'mail_send failed');
                    }
                    if (SHV.toast) {
                        SHV.toast.success('메일이 발송되었습니다.');
                    }
                    sessionStorage.removeItem('shv_mail_draft_edit');
                    SHV.router.navigate('mail_sent', { account_idx: state.accountIdx });
                })
                .catch(function (err) {
                    restoreSendBtns();
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '메일 발송에 실패했습니다.');
                    }
                });
        }

        function onSaveDraft(ctx, section) {
            var formData;
            try {
                formData = readFormPayload(section);
            } catch (err) {
                if (SHV.toast) {
                    SHV.toast.warn(err.message || '입력값을 확인해주세요.');
                }
                return;
            }

            formData.set('todo', 'mail_draft_save');
            SHV.api.upload(ctx.api, formData)
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'mail_draft_save failed');
                    }
                    var saved = res.data || {};
                    state.draftId = toInt(saved.id, state.draftId);
                    if (SHV.toast) {
                        SHV.toast.success('임시저장되었습니다.');
                    }
                })
                .catch(function (err) {
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '임시저장에 실패했습니다.');
                    }
                });
        }

        function onDiscard(section) {
            var form = q(section, '[data-mail-compose-form]');
            if (!form) {
                return;
            }
            form.reset();
            var modeInput = q(section, '[data-mail-reply-mode]');
            var uidInput = q(section, '[data-mail-source-uid]');
            var folderInput = q(section, '[data-mail-source-folder]');
            if (modeInput) { modeInput.value = ''; }
            if (uidInput)  { uidInput.value  = '0'; }
            if (folderInput) { folderInput.value = 'INBOX'; }
            state.draftId = 0;
            /* 첨부파일 목록도 초기화 */
            state.attachFiles = [];
            renderAttachList(section);
            if (SHV.toast) {
                SHV.toast.info('작성 내용을 초기화했습니다.');
            }
        }

        function bindEvents(ctx, section) {
            /* ── 클릭 이벤트 ── */
            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) { return; }
                var action = btn.getAttribute('data-action');

                if (action === 'reload-accounts') {
                    loadAccounts(ctx, section).catch(function (err) {
                        if (SHV.toast) { SHV.toast.error(err.message || '계정 조회 실패'); }
                    });
                    return;
                }
                if (action === 'mail-send') {
                    onSend(ctx, section);
                    return;
                }
                if (action === 'draft-save') {
                    onSaveDraft(ctx, section);
                    return;
                }
                if (action === 'mail-discard') {
                    onDiscard(section);
                    return;
                }
                /* 파일 선택 버튼 */
                if (action === 'attach-pick') {
                    var inp = q(section, '[data-mail-attach-input]');
                    if (inp) { inp.click(); }
                    return;
                }
                /* 첨부파일 칩 제거 */
                if (action === 'attach-remove') {
                    var idx = toInt(btn.getAttribute('data-attach-idx'), -1);
                    if (idx >= 0 && idx < state.attachFiles.length) {
                        state.attachFiles.splice(idx, 1);
                        renderAttachList(section);
                    }
                }
            });

            /* ── 파일 input change ── */
            var attachInput = q(section, '[data-mail-attach-input]');
            if (attachInput) {
                attachInput.addEventListener('change', function () {
                    if (this.files && this.files.length > 0) {
                        addFiles(section, this.files);
                    }
                    this.value = ''; /* 동일 파일 재선택 가능하도록 초기화 */
                });
            }

            /* ── 드래그&드롭 ── */
            var attachZone = q(section, '[data-mail-attach-zone]');
            if (attachZone) {
                attachZone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    attachZone.classList.add('mail-attach-zone--dragover');
                });
                attachZone.addEventListener('dragleave', function (e) {
                    if (!attachZone.contains(e.relatedTarget)) {
                        attachZone.classList.remove('mail-attach-zone--dragover');
                    }
                });
                attachZone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    attachZone.classList.remove('mail-attach-zone--dragover');
                    if (e.dataTransfer && e.dataTransfer.files.length > 0) {
                        addFiles(section, e.dataTransfer.files);
                    }
                });
            }

            /* ── 계정 선택 ── */
            var accountSelect = q(section, '[data-mail-account-select]');
            if (accountSelect) {
                accountSelect.addEventListener('change', function () {
                    state.accountIdx = toInt(this.value, 0);
                    if (state.accountIdx > 0) {
                        localStorage.setItem('shv_mail_last_account_idx', String(state.accountIdx));
                    }
                });
            }
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-compose"]');
            if (!section) {
                return;
            }
            var ctx = getMailContext(section);
            state.routeParams = getRouteParams();
            bindEvents(ctx, section);
            initTinyMCE();

            /* 계정 로드 + 첨부 정책 로드 병렬 실행 */
            Promise.all([
                loadAccounts(ctx, section),
                loadPolicy(ctx, section)   /* 실패해도 기본값으로 동작 */
            ])
                .then(function () {
                    var draftLoaded = false;
                    var draftRaw = sessionStorage.getItem('shv_mail_draft_edit');
                    if (draftRaw) {
                        try {
                            var draft = JSON.parse(draftRaw);
                            if (draft && toInt(draft.id, 0) === state.routeParams.draft_id) {
                                fillFormFromDraft(section, draft);
                                draftLoaded = true;
                            }
                        } catch (e) {
                            /* 잘못된 draft 캐시 무시 */
                        }
                    }
                    /* sessionStorage에 없으면 서버에서 조회 */
                    if (!draftLoaded && state.routeParams.draft_id > 0) {
                        SHV.api.get(ctx.api, withScope(ctx, {
                            todo: 'mail_draft_detail',
                            draft_id: state.routeParams.draft_id
                        })).then(function (res) {
                            if (res && res.ok && res.data) {
                                fillFormFromDraft(section, res.data);
                            }
                        }).catch(function () {});
                    }

                    if (state.routeParams.to) {
                        var toInput = q(section, '[data-mail-to]');
                        if (toInput && !toInput.value) {
                            toInput.value = state.routeParams.to;
                        }
                    }
                    if (state.routeParams.subject) {
                        var subjectInput = q(section, '[data-mail-subject]');
                        if (subjectInput && !subjectInput.value) {
                            subjectInput.value = state.routeParams.subject;
                        }
                    }

                    return fetchReplyTemplate(ctx, section);
                })
                .catch(function (err) {
                    if ((err.message || '').indexOf('계정이 없습니다') >= 0) {
                        showMailOnboarding(section);
                        return;
                    }
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '메일 작성 화면 초기화에 실패했습니다.');
                    }
                });
        }

        return {
            init: init,
            destroy: function () { destroyTinyMCE(); }
        };
    }

    function createAccountPage() {
        var state = {
            accounts: [],
            editingId: 0
        };

        function formFields(section) {
            return {
                editIdx: q(section, '[data-account-edit-idx]'),
                accountKey: q(section, '[data-acc-account-key]'),
                displayName: q(section, '[data-acc-display-name]'),
                host: q(section, '[data-acc-host]'),
                port: q(section, '[data-acc-port]'),
                ssl: q(section, '[data-acc-ssl]'),
                loginId: q(section, '[data-acc-login-id]'),
                password: q(section, '[data-acc-password]'),
                smtpHost: q(section, '[data-acc-smtp-host]'),
                smtpPort: q(section, '[data-acc-smtp-port]'),
                smtpSsl: q(section, '[data-acc-smtp-ssl]'),
                fromEmail: q(section, '[data-acc-from-email]'),
                fromName: q(section, '[data-acc-from-name]'),
                isPrimary: q(section, '[data-acc-is-primary]')
            };
        }

        function setForm(section, account) {
            var f = formFields(section);
            var row = account || {};
            state.editingId = toInt(row.idx, 0);
            if (f.editIdx) {
                f.editIdx.value = String(state.editingId || 0);
            }
            if (f.accountKey) {
                f.accountKey.value = row.account_key || '';
            }
            if (f.displayName) {
                f.displayName.value = row.display_name || '';
            }
            if (f.host) {
                f.host.value = row.host || '';
            }
            if (f.port) {
                f.port.value = row.port || 993;
            }
            if (f.ssl) {
                f.ssl.value = String(toInt(row.ssl, 1));
            }
            if (f.loginId) {
                f.loginId.value = row.login_id || '';
            }
            if (f.password) {
                f.password.value = '';
            }
            if (f.smtpHost) {
                f.smtpHost.value = row.smtp_host || '';
            }
            if (f.smtpPort) {
                f.smtpPort.value = row.smtp_port || 465;
            }
            if (f.smtpSsl) {
                f.smtpSsl.value = String(toInt(row.smtp_ssl, 1));
            }
            if (f.fromEmail) {
                f.fromEmail.value = row.from_email || '';
            }
            if (f.fromName) {
                f.fromName.value = row.from_name || '';
            }
            if (f.isPrimary) {
                f.isPrimary.checked = toInt(row.is_primary, 0) === 1;
            }
        }

        function getPayload(section) {
            var f = formFields(section);
            return {
                idx: state.editingId,
                account_key: f.accountKey ? f.accountKey.value.trim() : '',
                display_name: f.displayName ? f.displayName.value.trim() : '',
                host: f.host ? f.host.value.trim() : '',
                port: f.port ? f.port.value.trim() : '',
                ssl: f.ssl ? f.ssl.value : '1',
                login_id: f.loginId ? f.loginId.value.trim() : '',
                password: f.password ? f.password.value : '',
                smtp_host: f.smtpHost ? f.smtpHost.value.trim() : '',
                smtp_port: f.smtpPort ? f.smtpPort.value.trim() : '',
                smtp_ssl: f.smtpSsl ? f.smtpSsl.value : '1',
                from_email: f.fromEmail ? f.fromEmail.value.trim() : '',
                from_name: f.fromName ? f.fromName.value.trim() : '',
                is_primary: f.isPrimary && f.isPrimary.checked ? 1 : 0,
                status: 'ACTIVE'
            };
        }

        function renderRows(section) {
            var body = q(section, '[data-account-list-body]');
            if (!body) {
                return;
            }

            /* 내 계정만 표시: user_pk 기준 필터 */
            var actorUserPk = toInt(section.dataset.userPk, 0);
            var rows = actorUserPk > 0
                ? state.accounts.filter(function (a) { return toInt(a.user_pk, 0) === actorUserPk; })
                : state.accounts;

            if (rows.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="tbl-empty">등록된 계정이 없습니다.</td></tr>';
                return;
            }

            body.innerHTML = rows.map(function (a) {
                var idx = toInt(a.idx, 0);
                var status = String(a.status || 'ACTIVE').toUpperCase();
                return ''
                    + '<tr>'
                    + '  <td class="tbl-cell">' + idx + '</td>'
                    + '  <td class="tbl-cell">' + text(a.account_key || '') + '</td>'
                    + '  <td class="tbl-cell">' + text(a.display_name || '') + '</td>'
                    + '  <td class="tbl-cell">' + text(a.host || '') + '</td>'
                    + '  <td class="tbl-cell">' + renderResultBadge(status === 'ACTIVE' ? 'OK' : status) + '</td>'
                    + '  <td class="tbl-cell tbl-cell-center">' + (toInt(a.is_primary, 0) === 1 ? 'Y' : '-') + '</td>'
                    + '  <td class="tbl-cell">'
                    + '    <button type="button" class="btn btn-ghost btn-sm" data-action="account-edit" data-account-idx="' + idx + '">수정</button> '
                    + '    <button type="button" class="btn btn-ghost btn-sm" data-action="account-test-row" data-account-idx="' + idx + '">테스트</button> '
                    + '    <button type="button" class="btn btn-danger btn-sm" data-action="account-delete" data-account-idx="' + idx + '">삭제</button>'
                    + '  </td>'
                    + '</tr>';
            }).join('');
        }

        function loadAccounts(ctx, section) {
            return SHV.api.get(ctx.api, withScope(ctx, { todo: 'account_list' }))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_list failed');
                    }
                    state.accounts = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                    renderRows(section);
                });
        }

        function findMyAccount(section) {
            var actorUserPk = toInt(section.dataset.userPk, 0);
            var found = null;
            if (actorUserPk > 0) {
                found = state.accounts.find(function (x) { return toInt(x.user_pk, 0) === actorUserPk; });
            }
            if (!found) {
                found = state.accounts.find(function (x) { return toInt(x.is_primary, 0) === 1; });
            }
            if (!found && state.accounts.length > 0) {
                found = state.accounts[0];
            }
            return found || null;
        }

        function saveAccount(ctx, section) {
            var payload = getPayload(section);
            if (!payload.host || !payload.login_id) {
                if (SHV.toast) {
                    SHV.toast.warn('IMAP Host와 로그인 ID는 필수입니다.');
                }
                return;
            }
            if (!payload.password && payload.idx <= 0) {
                if (SHV.toast) {
                    SHV.toast.warn('신규 계정은 비밀번호가 필요합니다.');
                }
                return;
            }

            payload.todo = 'account_save';
            payload.csrf_token = ctx.csrfToken;

            SHV.api.post(ctx.api, withScope(ctx, payload))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_save failed');
                    }

                    /* 저장된 idx 확정: 응답 > 기존 payload.idx 순으로 — 폼은 그대로 유지 */
                    var savedIdx = toInt(res.data && res.data.idx, 0) || toInt(payload.idx, 0);
                    if (savedIdx > 0) {
                        state.editingId = savedIdx;
                        var editIdxEl = section.querySelector('[data-account-edit-idx]');
                        if (editIdxEl) { editIdxEl.value = String(savedIdx); }
                    }

                    if (SHV.toast) {
                        SHV.toast.success('계정이 저장되었습니다.');
                    }
                    /* 목록 갱신 — 폼 상태는 건드리지 않음 */
                    loadAccounts(ctx, section).catch(function () {});
                })
                .catch(function (err) {
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '계정 저장에 실패했습니다.');
                    }
                });
        }

        function testAccount(ctx, section, accountIdx) {
            var payload = accountIdx > 0 ? { account_idx: accountIdx } : getPayload(section);
            payload.todo = 'account_test';
            payload.csrf_token = ctx.csrfToken;

            SHV.api.post(ctx.api, withScope(ctx, payload))
                .then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_test failed');
                    }
                    var ok = !!(res.data && res.data.ok);
                    var health = res.data && res.data.health ? res.data.health : {};
                    if (SHV.toast) {
                        if (ok) {
                            SHV.toast.success('연결 테스트 성공');
                        } else {
                            SHV.toast.warn('연결 테스트 실패: ' + (health.message || 'DOWN'));
                        }
                    }
                })
                .catch(function (err) {
                    if (SHV.toast) {
                        SHV.toast.error(err.message || '연결 테스트 실패');
                    }
                });
        }

        function deleteAccount(ctx, section, accountIdx) {
            if (accountIdx <= 0) {
                return;
            }

            var confirmDel = confirmDialog({ title: '계정 삭제', message: '계정을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' });

            confirmDel.then(function (ok) {
                if (!ok) {
                    return;
                }
                return SHV.api.post(ctx.api, withScope(ctx, {
                    todo: 'account_delete',
                    account_idx: accountIdx,
                    csrf_token: ctx.csrfToken
                })).then(function (res) {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) || 'account_delete failed');
                    }
                    if (SHV.toast) {
                        SHV.toast.success('삭제되었습니다.');
                    }
                    setForm(section, null);
                    return loadAccounts(ctx, section);
                });
            }).catch(function (err) {
                if (err && SHV.toast) {
                    SHV.toast.error(err.message || '계정 삭제 실패');
                }
            });
        }

        /* ── 계정 수정 모달 ── */
        function openAccountModal(ctx, section, account) {
            if (!window.SHV || !SHV.modal) {
                if (SHV.toast) { SHV.toast.error('모달을 불러올 수 없습니다.'); }
                return;
            }
            var row = account || {};
            var idx = toInt(row.idx, 0);
            var title = idx > 0 ? '계정 수정' : '계정 등록';

            var presetBtns = [
                '<button type="button" class="mail-provider-btn" data-modal-provider="naver"><span class="mail-provider-logo mail-provider-logo--naver">N</span><span>네이버</span></button>',
                '<button type="button" class="mail-provider-btn" data-modal-provider="daum"><span class="mail-provider-logo mail-provider-logo--daum">D</span><span>다음</span></button>',
                '<button type="button" class="mail-provider-btn" data-modal-provider="gmail"><span class="mail-provider-logo mail-provider-logo--gmail">G</span><span>Gmail</span></button>',
                '<button type="button" class="mail-provider-btn" data-modal-provider="icloud"><span class="mail-provider-logo mail-provider-logo--icloud">☁</span><span>iCloud</span></button>',
                '<button type="button" class="mail-provider-btn" data-modal-provider="hanbiro"><span class="mail-provider-logo mail-provider-logo--hanbiro">H</span><span>한비로</span></button>',
                '<button type="button" class="mail-provider-btn" data-modal-provider="custom"><span class="mail-provider-logo mail-provider-logo--custom">···</span><span>직접입력</span></button>'
            ].join('');

            var sslSel     = '<option value="1"' + (toInt(row.ssl,      1) === 1 ? ' selected' : '') + '>사용</option><option value="0"' + (toInt(row.ssl,      1) === 0 ? ' selected' : '') + '>미사용</option>';
            var smtpSslSel = '<option value="1"' + (toInt(row.smtp_ssl, 1) === 1 ? ' selected' : '') + '>사용</option><option value="0"' + (toInt(row.smtp_ssl, 1) === 0 ? ' selected' : '') + '>미사용</option>';

            var html = [
                '<input type="hidden" id="modal-acc-idx" value="' + idx + '">',
                '<div class="mail-modal-grid">',

                /* ── 메일 서비스 (전체폭) ── */
                '  <label class="mail-compose-label mail-modal-full">메일 서비스</label>',
                '  <div class="mail-provider-btns mail-modal-full" id="modal-provider-btns">' + presetBtns + '</div>',

                /* ── 계정키 / 표시명 ── */
                '  <label class="mail-compose-label">계정키</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-key" value="' + text(row.account_key || '') + '" placeholder="mail.main">',
                '  <label class="mail-compose-label">표시명</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-display" value="' + text(row.display_name || '') + '" placeholder="홍길동 메일">',

                /* ── IMAP Host / IMAP Port ── */
                '  <label class="mail-compose-label">IMAP Host</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-host" value="' + text(row.host || '') + '" placeholder="mail.example.com">',
                '  <label class="mail-compose-label">IMAP Port</label>',
                '  <input class="mail-compose-input" type="number" id="modal-acc-port" value="' + (row.port || 993) + '">',

                /* ── SSL / 로그인 ID ── */
                '  <label class="mail-compose-label">SSL</label>',
                '  <select class="mail-compose-input" id="modal-acc-ssl">' + sslSel + '</select>',
                '  <label class="mail-compose-label">로그인 ID</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-login" value="' + text(row.login_id || '') + '" placeholder="user@domain.com">',

                /* ── 비밀번호 (절반) ── */
                '  <label class="mail-compose-label mail-modal-half">비밀번호</label>',
                '  <input class="mail-compose-input mail-modal-half" type="password" id="modal-acc-pw" placeholder="변경 시에만 입력">',

                /* ── SMTP Host / SMTP Port ── */
                '  <label class="mail-compose-label">SMTP Host</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-smtp-host" value="' + text(row.smtp_host || '') + '" placeholder="(비우면 IMAP Host)">',
                '  <label class="mail-compose-label">SMTP Port</label>',
                '  <input class="mail-compose-input" type="number" id="modal-acc-smtp-port" value="' + (row.smtp_port || 465) + '">',

                /* ── SMTP SSL / 발신 Email ── */
                '  <label class="mail-compose-label">SMTP SSL</label>',
                '  <select class="mail-compose-input" id="modal-acc-smtp-ssl">' + smtpSslSel + '</select>',
                '  <label class="mail-compose-label">발신 Email</label>',
                '  <input class="mail-compose-input" type="text" id="modal-acc-from-email" value="' + text(row.from_email || '') + '" placeholder="noreply@domain.com">',

                /* ── 발신 이름 (절반) ── */
                '  <label class="mail-compose-label mail-modal-half">발신 이름</label>',
                '  <input class="mail-compose-input mail-modal-half" type="text" id="modal-acc-from-name" value="' + text(row.from_name || '') + '" placeholder="SH Vision">',

                /* ── 하단 액션 (전체폭) ── */
                '  <div class="mail-compose-actions mail-modal-full">',
                '    <label class="mail-compose-check"><input type="checkbox" id="modal-acc-primary"' + (toInt(row.is_primary, 0) === 1 ? ' checked' : '') + '> 기본계정</label>',
                '    <div class="mail-compose-btns">',
                '      <button class="btn btn-outline btn-sm" id="modal-acc-test-btn"><i class="fa fa-plug"></i> 연결테스트</button>',
                '      <button class="btn btn-glass-primary btn-sm" id="modal-acc-save-btn"><i class="fa fa-check"></i> 저장</button>',
                '    </div>',
                '  </div>',
                '</div>'
            ].join('');

            SHV.modal.openHtml(html, title, 'lg');

            /* 모달 DOM 바인딩 */
            var body = document.getElementById('shvModalBody');
            if (!body) { return; }

            /* 프리셋 버튼 */
            var providerBtns = body.querySelectorAll('[data-modal-provider]');
            providerBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var provider = this.getAttribute('data-modal-provider');
                    var preset = MAIL_PRESETS[provider];
                    providerBtns.forEach(function (b) {
                        b.classList.toggle('is-active', b.getAttribute('data-modal-provider') === provider);
                    });
                    if (!preset) { return; }
                    var hostEl      = body.querySelector('#modal-acc-host');
                    var portEl      = body.querySelector('#modal-acc-port');
                    var sslEl       = body.querySelector('#modal-acc-ssl');
                    var smtpHostEl  = body.querySelector('#modal-acc-smtp-host');
                    var smtpPortEl  = body.querySelector('#modal-acc-smtp-port');
                    var smtpSslEl   = body.querySelector('#modal-acc-smtp-ssl');
                    var loginEl     = body.querySelector('#modal-acc-login');
                    var fromEmailEl = body.querySelector('#modal-acc-from-email');
                    if (hostEl)      { hostEl.value     = preset.host; }
                    if (portEl)      { portEl.value     = String(preset.port); }
                    if (sslEl)       { sslEl.value      = String(preset.ssl); }
                    if (smtpHostEl)  { smtpHostEl.value = preset.smtp_host; }
                    if (smtpPortEl)  { smtpPortEl.value = String(preset.smtp_port); }
                    if (smtpSslEl)   { smtpSslEl.value  = String(preset.smtp_ssl); }
                    if (loginEl   && preset.login_hint) { loginEl.placeholder   = preset.login_hint; }
                    if (fromEmailEl && preset.from_hint){ fromEmailEl.placeholder = preset.from_hint; }
                });
            });

            /* 로그인 ID → 계정키 자동 채우기 */
            var loginInput = body.querySelector('#modal-acc-login');
            var keyInput   = body.querySelector('#modal-acc-key');
            if (loginInput && keyInput) {
                loginInput.addEventListener('input', function () {
                    if (!keyInput.value.trim()) { keyInput.value = this.value.trim(); }
                });
            }

            function getModalPayload() {
                return {
                    idx:          toInt(body.querySelector('#modal-acc-idx').value, 0),
                    account_key:  (body.querySelector('#modal-acc-key').value   || '').trim(),
                    display_name: (body.querySelector('#modal-acc-display').value || '').trim(),
                    host:         (body.querySelector('#modal-acc-host').value   || '').trim(),
                    port:         (body.querySelector('#modal-acc-port').value   || '993').trim(),
                    ssl:          (body.querySelector('#modal-acc-ssl').value    || '1'),
                    login_id:     (body.querySelector('#modal-acc-login').value  || '').trim(),
                    password:     (body.querySelector('#modal-acc-pw').value     || ''),
                    smtp_host:    (body.querySelector('#modal-acc-smtp-host').value || '').trim(),
                    smtp_port:    (body.querySelector('#modal-acc-smtp-port').value || '465').trim(),
                    smtp_ssl:     (body.querySelector('#modal-acc-smtp-ssl').value  || '1'),
                    from_email:   (body.querySelector('#modal-acc-from-email').value || '').trim(),
                    from_name:    (body.querySelector('#modal-acc-from-name').value  || '').trim(),
                    is_primary:   body.querySelector('#modal-acc-primary').checked ? 1 : 0,
                    status:       'ACTIVE'
                };
            }

            /* 연결테스트 */
            var testBtn = body.querySelector('#modal-acc-test-btn');
            if (testBtn) {
                testBtn.addEventListener('click', function () {
                    var p = getModalPayload();
                    var payload = Object.assign({}, p, { todo: 'account_test', csrf_token: ctx.csrfToken });
                    SHV.api.post(ctx.api, withScope(ctx, payload))
                        .then(function (res) {
                            if (!res || !res.ok) { throw new Error((res && res.message) || 'account_test failed'); }
                            var ok = !!(res.data && res.data.ok);
                            if (SHV.toast) {
                                ok ? SHV.toast.success('연결 테스트 성공') : SHV.toast.warn('연결 테스트 실패: ' + ((res.data && res.data.health && res.data.health.message) || 'DOWN'));
                            }
                        })
                        .catch(function (err) { if (SHV.toast) { SHV.toast.error(err.message || '연결 테스트 실패'); } });
                });
            }

            /* 저장 */
            var saveBtn = body.querySelector('#modal-acc-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var p = getModalPayload();
                    if (!p.host || !p.login_id) {
                        if (SHV.toast) { SHV.toast.warn('IMAP Host와 로그인 ID는 필수입니다.'); }
                        return;
                    }
                    if (!p.password && p.idx <= 0) {
                        if (SHV.toast) { SHV.toast.warn('신규 계정은 비밀번호가 필요합니다.'); }
                        return;
                    }
                    var payload = Object.assign({}, p, { todo: 'account_save', csrf_token: ctx.csrfToken });
                    SHV.api.post(ctx.api, withScope(ctx, payload))
                        .then(function (res) {
                            if (!res || !res.ok) { throw new Error((res && res.message) || 'account_save failed'); }
                            if (SHV.toast) { SHV.toast.success('계정이 저장되었습니다.'); }
                            SHV.modal.close();
                            /* 좌측 폼 + 목록 갱신 */
                            return loadAccounts(ctx, section).then(function () {
                                var myAccount = findMyAccount(section);
                                if (myAccount) { setForm(section, myAccount); }
                            });
                        })
                        .catch(function (err) { if (SHV.toast) { SHV.toast.error(err.message || '계정 저장에 실패했습니다.'); } });
                });
            }
        }

        /* ── 메일 서비스 프리셋 ── */
        var MAIL_PRESETS = {
            naver:   { host: 'imap.naver.com',        port: 993, ssl: 1, smtp_host: 'smtp.naver.com',        smtp_port: 465, smtp_ssl: 1, login_hint: 'id@naver.com',           from_hint: 'id@naver.com' },
            daum:    { host: 'imap.daum.net',          port: 993, ssl: 1, smtp_host: 'smtp.daum.net',          smtp_port: 465, smtp_ssl: 1, login_hint: 'id@daum.net',            from_hint: 'id@daum.net' },
            gmail:   { host: 'imap.gmail.com',         port: 993, ssl: 1, smtp_host: 'smtp.gmail.com',         smtp_port: 465, smtp_ssl: 1, login_hint: 'id@gmail.com',           from_hint: 'id@gmail.com' },
            icloud:  { host: 'imap.mail.me.com',       port: 993, ssl: 1, smtp_host: 'smtp.mail.me.com',       smtp_port: 587, smtp_ssl: 1, login_hint: 'id@icloud.com',          from_hint: 'id@icloud.com' },
            hanbiro: { host: 'shvision.hanbiro.net',   port: 993, ssl: 1, smtp_host: 'shvision.hanbiro.net',   smtp_port: 465, smtp_ssl: 1, login_hint: 'user@shvision.co.kr (전체 이메일 주소)', from_hint: 'user@shv.kr' },
            custom:  null
        };

        function applyPreset(section, provider) {
            var preset = MAIL_PRESETS[provider];
            var f = formFields(section);
            /* 버튼 활성 상태 */
            var btns = section.querySelectorAll('[data-provider-btns] [data-provider]');
            btns.forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-provider') === provider);
            });
            if (!preset) { return; } /* custom: 직접입력 — 필드 유지 */
            if (f.host)     { f.host.value     = preset.host; }
            if (f.port)     { f.port.value     = String(preset.port); }
            if (f.ssl)      { f.ssl.value      = String(preset.ssl); }
            if (f.smtpHost) { f.smtpHost.value = preset.smtp_host; }
            if (f.smtpPort) { f.smtpPort.value = String(preset.smtp_port); }
            if (f.smtpSsl)  { f.smtpSsl.value  = String(preset.smtp_ssl); }
            /* 로그인 ID / 발신 Email placeholder 힌트 교체 */
            if (f.loginId   && preset.login_hint) { f.loginId.placeholder   = preset.login_hint; }
            if (f.fromEmail && preset.from_hint)  { f.fromEmail.placeholder = preset.from_hint; }
        }

        function bindEvents(ctx, section) {
            /* 로그인 ID 입력 → 계정키 자동 채우기 (비어있을 때만) */
            var loginIdInput   = q(section, '[data-acc-login-id]');
            var accountKeyInput = q(section, '[data-acc-account-key]');
            if (loginIdInput && accountKeyInput) {
                loginIdInput.addEventListener('input', function () {
                    if (!accountKeyInput.value.trim()) {
                        accountKeyInput.value = this.value.trim();
                    }
                });
            }

            /* 프리셋 버튼 */
            section.addEventListener('click', function (event) {
                var presetBtn = event.target.closest('[data-provider]');
                if (presetBtn && presetBtn.closest('[data-provider-btns]')) {
                    applyPreset(section, presetBtn.getAttribute('data-provider'));
                    return;
                }
            });

            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) {
                    return;
                }
                var action = btn.getAttribute('data-action');
                if (action === 'account-refresh') {
                    loadAccounts(ctx, section);
                    return;
                }
                if (action === 'account-save') {
                    saveAccount(ctx, section);
                    return;
                }
                if (action === 'account-reset') {
                    setForm(section, null);
                    return;
                }
                if (action === 'account-test') {
                    testAccount(ctx, section, 0);
                    return;
                }
                if (action === 'account-edit') {
                    var idx = toInt(btn.getAttribute('data-account-idx'), 0);
                    var row = state.accounts.find(function (x) { return toInt(x.idx, 0) === idx; });
                    openAccountModal(ctx, section, row || null);
                    return;
                }
                if (action === 'account-test-row') {
                    testAccount(ctx, section, toInt(btn.getAttribute('data-account-idx'), 0));
                    return;
                }
                if (action === 'account-delete') {
                    deleteAccount(ctx, section, toInt(btn.getAttribute('data-account-idx'), 0));
                }
            });
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-account"]');
            if (!section) {
                return;
            }
            var ctx = getMailContext(section);
            setForm(section, null);
            bindEvents(ctx, section);
            loadAccounts(ctx, section).then(function () {
                /* 내 계정 자동 로드: user_pk → is_primary → 첫 번째 순으로 */
                var myAccount = findMyAccount(section);
                if (myAccount) {
                    setForm(section, myAccount);
                }
            }).catch(function (err) {
                if (SHV.toast) {
                    SHV.toast.error(err.message || '계정 목록 조회 실패');
                }
            });
        }

        return {
            init: init,
            destroy: function () {}
        };
    }

    function createSettingsPage() {
        var STORAGE_KEY = 'shv_mail_settings_v1';

        function readForm(section) {
            var sound = q(section, '[data-mail-setting-sound]');
            var sendSound = q(section, '[data-mail-setting-send-sound]');
            var replyAddr = q(section, '[data-mail-setting-reply-addr]');
            var dupExpire = q(section, '[data-mail-setting-dup-expire]');
            var pushMail = q(section, '[data-mail-setting-push-mail]');

            return {
                mail_sound: sound ? String(sound.value || 'off') : 'off',
                mail_send_sound: sendSound && sendSound.checked ? '1' : '0',
                mail_reply_addr: replyAddr ? String(replyAddr.value || '').trim() : '',
                mail_dup_expire_days: dupExpire ? String(dupExpire.value || '30').trim() : '30',
                push_mail: pushMail && pushMail.checked ? '1' : '0'
            };
        }

        function applyForm(section, values) {
            if (!values || typeof values !== 'object') {
                return;
            }
            var sound = q(section, '[data-mail-setting-sound]');
            var sendSound = q(section, '[data-mail-setting-send-sound]');
            var replyAddr = q(section, '[data-mail-setting-reply-addr]');
            var dupExpire = q(section, '[data-mail-setting-dup-expire]');
            var pushMail = q(section, '[data-mail-setting-push-mail]');

            if (sound && values.mail_sound != null) {
                sound.value = String(values.mail_sound);
            }
            if (sendSound && values.mail_send_sound != null) {
                sendSound.checked = String(values.mail_send_sound) === '1';
            }
            if (replyAddr && values.mail_reply_addr != null) {
                replyAddr.value = String(values.mail_reply_addr);
            }
            if (dupExpire && values.mail_dup_expire_days != null) {
                dupExpire.value = String(values.mail_dup_expire_days);
            }
            if (pushMail && values.push_mail != null) {
                pushMail.checked = String(values.push_mail) === '1';
            }
        }

        function playPreview() {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                return;
            }
            var ctx = new AudioCtx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 740;
            gain.gain.value = 0.03;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            setTimeout(function () {
                osc.stop();
                ctx.close();
            }, 180);
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-settings"]');
            if (!section) {
                return;
            }

            var savedRaw = localStorage.getItem(STORAGE_KEY);
            if (savedRaw) {
                try {
                    applyForm(section, JSON.parse(savedRaw));
                } catch (e) {
                    // ignore invalid cache
                }
            }

            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) {
                    return;
                }
                var action = btn.getAttribute('data-action');

                if (action === 'mail-setting-preview-sound') {
                    playPreview();
                    return;
                }
                if (action === 'mail-setting-save') {
                    var values = readForm(section);
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(values));
                    if (SHV.toast) {
                        SHV.toast.success('메일 개인설정을 저장했습니다.');
                    }
                }
            });
        }

        return {
            init: init,
            destroy: function () {}
        };
    }

    /* ═══════════════════════════════════════════════════════
       관리자설정 페이지 (role >= 4 전용)
       ═══════════════════════════════════════════════════════ */
    function createAdminSettingsPage() {

        var state = { accounts: [] };

        function getCtx(section) {
            return {
                api: section.getAttribute('data-mail-api') || 'dist_process/saas/Mail.php',
                csrf: section.getAttribute('data-csrf-token') || ''
            };
        }

        function showTab(section, tabName) {
            var tabs = qa(section, '[data-settings-tab]');
            tabs.forEach(function (el) {
                el.classList.toggle('mail-settings-section--hidden', el.getAttribute('data-settings-tab') !== tabName);
            });
            var btns = qa(section, '[data-tab]');
            btns.forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tabName);
            });
        }

        function renderAccountRows(section) {
            var tabPanel = q(section, '[data-settings-tab="accounts"]');
            if (!tabPanel) { return; }
            var body = q(tabPanel, '[data-account-list-body]');
            if (!body) { return; }
            if (state.accounts.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="tbl-empty">등록된 계정이 없습니다.</td></tr>';
                return;
            }
            body.innerHTML = state.accounts.map(function (a) {
                var idx = toInt(a.idx, 0);
                var status = String(a.status || 'ACTIVE').toUpperCase();
                return '<tr>'
                    + '<td class="tbl-cell">' + idx + '</td>'
                    + '<td class="tbl-cell">' + text(a.account_key || '') + '</td>'
                    + '<td class="tbl-cell">' + text(a.display_name || '') + '</td>'
                    + '<td class="tbl-cell">' + text(a.host || '') + '</td>'
                    + '<td class="tbl-cell">' + renderResultBadge(status === 'ACTIVE' ? 'OK' : status) + '</td>'
                    + '<td class="tbl-cell tbl-cell-center">' + (toInt(a.is_primary, 0) === 1 ? 'Y' : '-') + '</td>'
                    + '<td class="tbl-cell">'
                    + '<button type="button" class="btn btn-ghost btn-sm" data-action="admin-account-test" data-account-idx="' + idx + '">테스트</button> '
                    + '<button type="button" class="btn btn-danger btn-sm" data-action="admin-account-delete" data-account-idx="' + idx + '">삭제</button>'
                    + '</td>'
                    + '</tr>';
            }).join('');
        }

        function loadAccounts(ctx, section) {
            var tabPanel = q(section, '[data-settings-tab="accounts"]');
            var body = tabPanel ? q(tabPanel, '[data-account-list-body]') : null;
            if (body) { body.innerHTML = '<tr><td colspan="7" class="tbl-empty"><i class="fa fa-spinner fa-spin"></i> 로딩 중...</td></tr>'; }
            return SHV.api.get(ctx.api, { todo: 'account_list', csrf_token: ctx.csrf })
                .then(function (res) {
                    if (!res || !res.ok) { throw new Error((res && res.message) || 'account_list failed'); }
                    state.accounts = Array.isArray(res.data && res.data.items) ? res.data.items : [];
                    renderAccountRows(section);
                })
                .catch(function (err) {
                    if (body) { body.innerHTML = '<tr><td colspan="7" class="tbl-empty tbl-empty--error">' + text(err.message || '불러오기 실패') + '</td></tr>'; }
                });
        }

        function applyPolicy(section, data) {
            if (!data || typeof data !== 'object') { return; }
            var perFile = q(section, '[data-admin-max-per-file-mb]');
            var total   = q(section, '[data-admin-max-total-mb]');
            var exts    = q(section, '[data-admin-allowed-exts]');
            if (perFile && data.max_per_file_mb != null) { perFile.value = String(data.max_per_file_mb); }
            if (total   && data.max_total_mb    != null) { total.value   = String(data.max_total_mb); }
            if (exts    && data.allowed_exts    != null) { exts.value    = String(data.allowed_exts); }
        }

        function readPolicy(section) {
            var perFile = q(section, '[data-admin-max-per-file-mb]');
            var total   = q(section, '[data-admin-max-total-mb]');
            var exts    = q(section, '[data-admin-allowed-exts]');
            return {
                max_per_file_mb: toInt(perFile && perFile.value, 10),
                max_total_mb:    toInt(total   && total.value,   25),
                allowed_exts:    String(exts && exts.value || '').trim()
            };
        }

        function loadPolicy(ctx, section) {
            return SHV.api.get(ctx.api, { todo: 'mail_send_policy', csrf_token: ctx.csrf })
                .then(function (res) {
                    if (!res || !res.ok) { return; }
                    applyPolicy(section, res.data);
                })
                .catch(function () { /* 무시 — 기본값 유지 */ });
        }

        function savePolicy(ctx, section) {
            var policy = readPolicy(section);
            var saveBtn = q(section, '[data-action="admin-settings-save"]');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.setAttribute('data-orig', saveBtn.innerHTML);
                saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 저장 중...';
            }
            function restore() {
                if (!saveBtn) { return; }
                saveBtn.disabled = false;
                var orig = saveBtn.getAttribute('data-orig');
                if (orig) { saveBtn.innerHTML = orig; saveBtn.removeAttribute('data-orig'); }
            }
            SHV.api.post(ctx.api, Object.assign({ todo: 'mail_admin_settings_save', csrf_token: ctx.csrf }, policy))
                .then(function (res) {
                    restore();
                    if (!res || !res.ok) { throw new Error((res && res.message) || '저장 실패'); }
                    if (SHV.toast) { SHV.toast.success('관리자 메일 설정을 저장했습니다.'); }
                })
                .catch(function (err) {
                    restore();
                    if (SHV.toast) { SHV.toast.error(err.message || '설정 저장에 실패했습니다.'); }
                });
        }

        function deleteAccount(ctx, section, idx) {
            confirmDialog({ title: '계정 삭제', message: '선택한 계정을 삭제하시겠습니까?', confirmText: '삭제', type: 'danger' })
                .then(function (ok) {
                    if (!ok) return;
                    return SHV.api.post(ctx.api, { todo: 'account_delete', idx: idx, csrf_token: ctx.csrf })
                        .then(function (res) {
                            if (!res || !res.ok) { throw new Error((res && res.message) || '삭제 실패'); }
                            if (SHV.toast) { SHV.toast.success('계정이 삭제됐습니다.'); }
                            loadAccounts(ctx, section);
                        });
                })
                .catch(function (err) {
                    if (err && SHV.toast) { SHV.toast.error(err.message || '삭제에 실패했습니다.'); }
                });
        }

        function testAccount(ctx, idx) {
            SHV.api.post(ctx.api, { todo: 'account_test', idx: idx, csrf_token: ctx.csrf })
                .then(function (res) {
                    if (!res || !res.ok) { throw new Error((res && res.message) || '연결 실패'); }
                    if (SHV.toast) { SHV.toast.success('IMAP 연결 성공!'); }
                })
                .catch(function (err) {
                    if (SHV.toast) { SHV.toast.error(err.message || 'IMAP 연결에 실패했습니다.'); }
                });
        }

        function init() {
            var section = document.querySelector('[data-page="saas-mail-settings"]');
            if (!section) { return; }
            var ctx = getCtx(section);

            loadPolicy(ctx, section);

            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action],[data-tab]');
                if (!btn) { return; }

                var tab = btn.getAttribute('data-tab');
                if (tab) {
                    showTab(section, tab);
                    if (tab === 'accounts') { loadAccounts(ctx, section); }
                    return;
                }

                var action = btn.getAttribute('data-action');
                if (action === 'admin-settings-reload') {
                    loadPolicy(ctx, section);
                    if (SHV.toast) { SHV.toast.info('현재 설정값을 불러왔습니다.'); }
                    return;
                }
                if (action === 'admin-settings-save') {
                    savePolicy(ctx, section);
                    return;
                }
                if (action === 'admin-account-delete') {
                    var idx = toInt(btn.getAttribute('data-account-idx'), 0);
                    if (idx > 0) { deleteAccount(ctx, section, idx); }
                    return;
                }
                if (action === 'admin-account-test') {
                    var tidx = toInt(btn.getAttribute('data-account-idx'), 0);
                    if (tidx > 0) { testAccount(ctx, tidx); }
                    return;
                }
            });
        }

        return { init: init, destroy: function () {} };
    }

    SHV.pages = SHV.pages || {};

    SHV.pages.mail_inbox = createMailListPage('mail_inbox');
    SHV.pages.mail_sent = createMailListPage('mail_sent');
    SHV.pages.mail_spam = createMailListPage('mail_spam');
    SHV.pages.mail_archive = createMailListPage('mail_archive');
    SHV.pages.mail_duplicate = createMailListPage('mail_duplicate');
    SHV.pages.mail_trash = createMailListPage('mail_trash');

    SHV.pages.mail_drafts = createDraftPage();
    SHV.pages.mail_compose = createComposePage();
    SHV.pages.mail_account_settings = createAccountPage();
    SHV.pages.mail_admin_settings = createAdminSettingsPage();
    /* createSettingsPage() — 개인설정 로직, 현재 미사용 (향후 개인설정 뷰에 연결) */
})(window.SHV);
