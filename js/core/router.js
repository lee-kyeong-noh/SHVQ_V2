/* ========================================
   SHVQ V2 — SPA Router  (v2.1)
   URL: ?r=routename  (system= 브라우저 URL 노출 없음)
   ROUTE_MAP 에서 l0/system 결정
   미등록 라우트 → DOM 사이드바에서 l0 자동 추론
   ======================================== */
'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    var _contentEl    = null;
    var _currentRoute = null;
    var _onLoadCbs    = [];

    /* ──────────────────────────────────────────────────────────────
       L0 → system 매핑 (DOM 추론 시 사용)
       sidebar id 규칙: side + 첫글자대문자(l0) ex) sideFms, sideFacility
    ────────────────────────────────────────────────────────────── */
    var L0_SYSTEM = {
        fms:      'fms',
        estimate: 'estimate',
        pms:      'pms',
        bms:      'bms',
        ctm:      'ctm',
        grp:      'groupware',
        mail:     'mail',
        mat:      'mat',
        cad:      'cad',
        facility: 'facility',
        api:      'api',
        manage:   'groupware',
    };

    /* ──────────────────────────────────────────────────────────────
       ROUTE_MAP: route → { l0, system }
       index.php 사이드바 href="?r=..." 기준 전수 등록
    ────────────────────────────────────────────────────────────── */
    var ROUTE_MAP = {

        /* ── 대시보드 ── */
        'dashboard':             { l0: 'fms',      system: 'fms' },

        /* ────────── FMS ────────── */
        /* 고객관리 */
        'member_head':           { l0: 'fms',      system: 'fms' },
        'head_view':             { l0: 'fms',      system: 'fms', file: 'views/saas/fms/head_view.php' },
        'member_branch':         { l0: 'fms',      system: 'fms', file: 'views/saas/fms/member_branch.php' },
        'member_planned':        { l0: 'fms',      system: 'fms', file: 'views/saas/fms/member_branch.php' },
        'member_branch_view':    { l0: 'fms',      system: 'fms', file: 'views/saas/fms/member_branch_view.php' },
        'member_settings':       { l0: 'fms',      system: 'fms', file: 'views/saas/manage/member_settings.php' },
        'site_new':              { l0: 'fms',      system: 'fms', file: 'views/saas/fms/site_list.php' },
        'site_my':               { l0: 'fms',      system: 'fms', file: 'views/saas/fms/site_list.php' },
        'site_view':             { l0: 'fms',      system: 'fms', file: 'views/saas/fms/site_view.php' },
        'site_settings':         { l0: 'fms',      system: 'fms', file: 'views/saas/fms/site_settings.php' },
        /* PJT */
        'project_dashboard':     { l0: 'fms',      system: 'fms' },
        'pjt_todo':              { l0: 'fms',      system: 'fms' },
        'pjt_calendar_v2':       { l0: 'fms',      system: 'fms' },
        'project_map':           { l0: 'fms',      system: 'fms' },
        'project_main':          { l0: 'fms',      system: 'fms' },
        'project_schedule':      { l0: 'fms',      system: 'fms' },
        /* 업무활동 */
        'calendar_end':          { l0: 'fms',      system: 'fms' },
        'calendar_log':          { l0: 'fms',      system: 'fms' },
        'walkdownList':          { l0: 'fms',      system: 'fms' },
        /* 업무보고 */
        'task_report_my':        { l0: 'fms',      system: 'fms' },
        'task_report_recv':      { l0: 'fms',      system: 'fms' },
        'task_report_all':       { l0: 'fms',      system: 'fms' },
        'task_activity_stats':   { l0: 'fms',      system: 'fms' },
        /* SRM */
        'site_srm':              { l0: 'fms',      system: 'fms' },
        'srm_order':             { l0: 'fms',      system: 'fms' },
        'employee':              { l0: 'fms',      system: 'fms' },
        /* 기술지원 */
        'technicalSupportList':  { l0: 'fms',      system: 'fms' },
        'technicalSupportBest':  { l0: 'fms',      system: 'fms' },
        'technicalEducation':    { l0: 'fms',      system: 'fms' },
        /* 안전관리 */
        'safetycostList':        { l0: 'fms',      system: 'fms' },
        'agreeBoardList':        { l0: 'fms',      system: 'fms' },
        'accessSafetyList':      { l0: 'fms',      system: 'fms' },
        /* ────────── 예정 ────────── */
        /* 배관배선 */
        'wire_check':            { l0: 'estimate',  system: 'estimate', file: 'views/saas/fms/wire_check.php' },
        'wire_stats':            { l0: 'estimate',  system: 'estimate', file: 'views/saas/fms/wire_stats.php' },

        /* ────────── PMS ────────── */
        /* 견적관리 */
        'budgetlist':            { l0: 'pms',      system: 'pms' },
        'quotationStatus_quote': { l0: 'pms',      system: 'pms' },
        'quotationStatus_order': { l0: 'pms',      system: 'pms' },
        'quotationStatus_fail':  { l0: 'pms',      system: 'pms' },
        'calcList':              { l0: 'pms',      system: 'pms' },
        /* 일정 */
        'schedule':              { l0: 'pms',      system: 'pms' },
        /* 회의게시판 */
        'meetingList':           { l0: 'pms',      system: 'pms' },
        'meetingList_partner':   { l0: 'pms',      system: 'pms' },
        'meetingList_me':        { l0: 'pms',      system: 'pms' },

        /* ────────── BMS ────────── */
        /* 구매관리 */
        'company':               { l0: 'bms',      system: 'bms' },
        'material_purchase_new': { l0: 'bms',      system: 'bms' },
        'material_contract_new': { l0: 'bms',      system: 'bms' },
        'material_sales_new':    { l0: 'bms',      system: 'bms' },
        /* 수주관리 */
        'order_status':          { l0: 'bms',      system: 'bms' },
        'order_balance':         { l0: 'bms',      system: 'bms' },
        'order_register':        { l0: 'bms',      system: 'bms' },
        /* 매출관리 */
        'sales_status':          { l0: 'bms',      system: 'bms' },
        'sales_register':        { l0: 'bms',      system: 'bms' },
        'sales_tax':             { l0: 'bms',      system: 'bms' },
        'sales_unmatched':       { l0: 'bms',      system: 'bms' },
        /* 수금관리 */
        'collect_status':        { l0: 'bms',      system: 'bms' },
        'collect_unpaid':        { l0: 'bms',      system: 'bms' },
        'collect_unclaimed':     { l0: 'bms',      system: 'bms' },
        'collect_register':      { l0: 'bms',      system: 'bms' },
        /* 급여관리 */
        'work_employee_pay_new': { l0: 'bms',      system: 'bms' },
        /* 비용관리 */
        'expense_my_new':        { l0: 'bms',      system: 'bms' },
        'expense_manage_new':    { l0: 'bms',      system: 'bms' },
        'expense_company_new':   { l0: 'bms',      system: 'bms' },
        'expense_all_new':       { l0: 'bms',      system: 'bms' },
        /* 자금관리 */
        'accountList':           { l0: 'bms',      system: 'bms' },
        'account_request':       { l0: 'bms',      system: 'bms' },
        'account_balance':       { l0: 'bms',      system: 'bms' },
        'resolution':            { l0: 'bms',      system: 'bms' },
        /* 자산관리 */
        'assetList':             { l0: 'bms',      system: 'bms' },
        'carAccidentList':       { l0: 'bms',      system: 'bms' },

        /* ────────── CTM ────────── */
        'ctm_main':              { l0: 'ctm',      system: 'ctm' },

        /* ────────── GRP ────────── */
        /* Home */
        'emp':               { l0: 'grp', system: 'groupware', file: 'views/saas/grp/emp.php' },
        'chat':              { l0: 'grp', system: 'groupware', file: 'views/saas/grp/chat.php' },
        /* 주소록 */
        'org_chart':         { l0: 'grp', system: 'groupware', file: 'views/saas/grp/org_chart.php' },
        'org_chart_card':    { l0: 'grp', system: 'groupware', file: 'views/saas/grp/org_chart_card.php' },
        'emp_detail':        { l0: 'grp', system: 'groupware', file: 'views/saas/grp/emp_detail.php' },
        'org_chart_settings':{ l0: 'grp', system: 'groupware', file: 'views/saas/grp/org_chart_settings.php' },
        /* HR */
        'work_overtime':     { l0: 'grp', system: 'groupware', file: 'views/saas/grp/work_overtime.php' },
        'attitude':          { l0: 'grp', system: 'groupware', file: 'views/saas/grp/attitude.php' },
        'holiday':           { l0: 'grp', system: 'groupware', file: 'views/saas/grp/holiday.php' },
        /* 전자결재 */
        'approval_req':      { l0: 'grp', system: 'groupware', file: 'views/saas/grp/approval_req.php' },
        'approval_write':    { l0: 'grp', system: 'groupware', file: 'views/saas/grp/approval_write.php' },
        'approval_done':     { l0: 'grp', system: 'groupware', file: 'views/saas/grp/approval_done.php' },
        /* 문서함 */
        'doc_all':           { l0: 'grp', system: 'groupware', file: 'views/saas/grp/doc_all.php' },
        'approval_official': { l0: 'grp', system: 'groupware', file: 'views/saas/grp/approval_official.php' },

        /* ────────── MAIL ────────── */
        /* 웹메일 */
        'mail_inbox':            { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        'mail_sent':             { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        'mail_drafts':           { l0: 'mail',     system: 'mail', file: 'views/saas/mail/drafts.php' },
        'mail_compose':          { l0: 'mail',     system: 'mail', file: 'views/saas/mail/compose.php' },
        'mail_spam':             { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        'mail_archive':          { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        'mail_duplicate':        { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        'mail_trash':            { l0: 'mail',     system: 'mail', file: 'views/saas/mail/list.php' },
        /* MAIL설정 */
        'mail_account_settings': { l0: 'mail',     system: 'mail', file: 'views/saas/mail/account.php' },
        'mail_admin_settings':   { l0: 'mail',     system: 'mail', file: 'views/saas/mail/settings.php' },

        /* ────────── MAT ────────── */
        'material_list':         { l0: 'mat', system: 'mat', file: 'views/saas/mat/list.php' },
        'mat_view':              { l0: 'mat', system: 'mat', file: 'views/saas/mat/view.php' },
        'material_takelist':     { l0: 'mat', system: 'mat', file: 'views/saas/mat/takelist.php' },
        'stock_status':          { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_status.php' },
        'stock_in':              { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_in.php' },
        'stock_out':             { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_out.php' },
        'stock_transfer':        { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_transfer.php' },
        'stock_adjust':          { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_adjust.php' },
        'stock_log':             { l0: 'mat', system: 'mat', file: 'views/saas/mat/stock_log.php' },
        'mat_settings':          { l0: 'mat', system: 'mat', file: 'views/saas/mat/settings.php' },

        /* ────────── CAD ────────── */
        'smartcad':              { l0: 'cad',      system: 'cad' },

        /* ────────── 시설(facility) — CCTV + IoT 통합 ────────── */
        'cctv_viewer':           { l0: 'facility', system: 'facility', file: 'views/saas/facility/cctv_viewer.php' },
        'onvif':                 { l0: 'facility', system: 'facility', file: 'views/saas/facility/onvif.php' },
        'iot':                   { l0: 'facility', system: 'facility', file: 'views/saas/facility/iot.php' },

        /* ────────── API/도구 ────────── */
        'elevator_api':          { l0: 'api',      system: 'api' },
        'naratender':            { l0: 'api',      system: 'api' },
        'apt_bid':               { l0: 'api',      system: 'api' },
        'qr_scanner':            { l0: 'api',      system: 'api' },
        'doc_viewer':            { l0: 'api',      system: 'api' },
        'short_url':             { l0: 'api',      system: 'api' },
        'ws_monitor':            { l0: 'api',      system: 'api' },

        /* ────────── 관리 ────────── */
        'my_settings':           { l0: 'manage',   system: 'groupware' },
        'settings':              { l0: 'manage',   system: 'groupware' },
        'devlog':                { l0: 'manage',   system: 'groupware' },
        'manual':                { l0: 'manage',   system: 'groupware', file: 'views/saas/manage/manual.php' },
        'trash':                 { l0: 'manage',   system: 'groupware' },
        'auth_audit':            { l0: 'manage',   system: 'groupware', file: 'views/saas/manage/auth_audit.php' },
    };

    var DEFAULT_ROUTE = 'dashboard';
    var DEFAULT_L0    = 'fms';

    SHV.pages  = SHV.pages  || {};

    /* ──────────────────────────────────────────────────────────────
       resolveMeta(route) — ROUTE_MAP 우선, 미등록 시 DOM 사이드바 추론
    ────────────────────────────────────────────────────────────── */
    function resolveMeta(route) {
        if (ROUTE_MAP[route]) {
            return ROUTE_MAP[route];
        }

        /* DOM 추론: .side-item[href="?r=route"] → 소속 side-section → l0 */
        try {
            var anchor = document.querySelector('.side-item[href="?r=' + route + '"]');
            if (anchor) {
                var section = anchor.closest('[id^="side"]');
                if (section) {
                    /* sideFms → fms, sideFacility → facility, sideManage → manage */
                    var l0 = section.id.replace(/^side/, '').toLowerCase();
                    var system = L0_SYSTEM[l0] || 'fms';
                    return { l0: l0, system: system };
                }
            }
        } catch (e) { /* DOM 미준비 상황 무시 */ }

        return { l0: DEFAULT_L0, system: L0_SYSTEM[DEFAULT_L0] };
    }

    SHV.router = {
        /* ──────────────────────────
           init
        ────────────────────────── */
        init: function (contentSelector) {
            _contentEl = typeof contentSelector === 'string'
                ? document.querySelector(contentSelector)
                : contentSelector;

            window.addEventListener('popstate', function () {
                SHV.router._loadFromUrl();
            });

            this._loadFromUrl();
        },

        /* ──────────────────────────
           navigate(route [, params])
           URL: ?r=route
        ────────────────────────── */
        navigate: function (route, params) {
            var url = '?r=' + encodeURIComponent(route);
            if (params) {
                Object.keys(params).forEach(function (k) {
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                });
            }
            history.pushState({ r: route }, '', url);
            this._load(route, params);
        },

        /* ──────────────────────────
           current() → { route, l0, system }
        ────────────────────────── */
        current: function () {
            var p     = new URLSearchParams(window.location.search);
            var route = p.get('r') || DEFAULT_ROUTE;
            var meta  = resolveMeta(route);
            return { route: route, l0: meta.l0, system: meta.system };
        },

        resolveL0: function (route) {
            return resolveMeta(route).l0;
        },

        onLoad: function (callback) {
            if (typeof callback === 'function') _onLoadCbs.push(callback);
        },

        /* ── private ── */
        _loadFromUrl: function () {
            var p = new URLSearchParams(window.location.search);
            var route = p.get('r') || DEFAULT_ROUTE;

            /* 모달 history.back() 등으로 동일 라우트 popstate 시 재로드 방지 */
            if (route === _currentRoute && !history.state?.forceReload) {
                var params = {};
                p.forEach(function (val, key) { if (key !== 'r') params[key] = val; });
                if (Object.keys(params).length === 0) return;
            }

            /* 새로고침 시 답장/전달 상태면 즉시 받은편지함으로 (깜빡임 없이) */
            if (route === 'mail_compose' && (p.get('reply_mode') || p.get('source_uid'))) {
                route = 'mail_inbox';
                history.replaceState({ r: route }, '', '?r=' + route);
                this._load(route);
                return;
            }

            var params = {};
            p.forEach(function (val, key) {
                if (key !== 'r') params[key] = val;
            });
            this._load(route, Object.keys(params).length ? params : undefined);
        },

        _load: function (route, params) {
            if (!_contentEl) return;

            if (_currentRoute && SHV.pages[_currentRoute] &&
                typeof SHV.pages[_currentRoute].destroy === 'function') {
                SHV.pages[_currentRoute].destroy();
            }

            var meta     = resolveMeta(route);
            var file     = meta.file || (route.indexOf('.php') < 0 ? route + '.php' : route);
            /* PHP에는 system=, r= 내부 전달 (브라우저 URL에는 없음) */
            var fetchUrl = file + '?system=' + encodeURIComponent(meta.system)
                + '&r=' + encodeURIComponent(route);
            if (params) {
                Object.keys(params).forEach(function (k) {
                    fetchUrl += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                });
            }

            fetch(fetchUrl, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (res.status === 401) { window.location.href = 'login.php'; return ''; }
                return res.text();
            })
            .then(function (html) {
                if (!html) return;

                _contentEl.style.opacity = '0';
                _contentEl.innerHTML = html;
                _currentRoute = route.replace('.php', '');

                var titleEl = _contentEl.querySelector('[data-title]');
                document.title = 'SHV_' + (titleEl ? titleEl.dataset.title : (_currentRoute || 'Portal'));

                SHV.router._executeScripts(_contentEl);

                function _reveal() {
                    if (SHV.pages[_currentRoute] &&
                        typeof SHV.pages[_currentRoute].init === 'function') {
                        SHV.pages[_currentRoute].init();
                    }
                    _contentEl.style.transition = 'opacity 0.12s ease';
                    _contentEl.style.opacity = '';
                    /* transition 정리 — layout shift 방지 */
                    setTimeout(function () { _contentEl.style.transition = ''; }, 150);

                    var info = { route: route, l0: meta.l0, system: meta.system };
                    _onLoadCbs.forEach(function (cb) { cb(info); });
                }

                /* 페이지 전용 CSS 링크 로드 대기 */
                var pageLinks = Array.prototype.slice.call(
                    _contentEl.querySelectorAll('link[rel="stylesheet"]')
                ).filter(function (l) { return !l.sheet; });

                if (pageLinks.length === 0) {
                    _reveal();
                } else {
                    var _loaded = 0;
                    var _safetyTimer = setTimeout(function () { _reveal(); }, 1500);
                    pageLinks.forEach(function (link) {
                        function _done() {
                            _loaded++;
                            if (_loaded >= pageLinks.length) {
                                clearTimeout(_safetyTimer);
                                _reveal();
                            }
                        }
                        link.addEventListener('load',  _done);
                        link.addEventListener('error', _done);
                    });
                }
            })
            .catch(function () {
                if (SHV.toast) SHV.toast.error('페이지를 불러올 수 없습니다.');
            });
        },

        _executeScripts: function (container) {
            container.querySelectorAll('script').forEach(function (old) {
                var s = document.createElement('script');
                if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
                old.parentNode.replaceChild(s, old);
            });
        }
    };

})(window.SHV);
