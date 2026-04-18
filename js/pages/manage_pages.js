'use strict';

window.SHV = window.SHV || {};

(function (SHV) {
    function q(section, selector) {
        return section.querySelector(selector);
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

    function badge(code) {
        var c = String(code || '').toUpperCase();
        if (c === 'OK') {
            return '<span class="badge badge-success">OK</span>';
        }
        if (c.indexOf('DENY') === 0) {
            return '<span class="badge badge-danger">' + text(c) + '</span>';
        }
        return '<span class="badge badge-warn">' + text(c || '-') + '</span>';
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

    function createAuthAuditPage() {
        var state = {
            page: 1,
            limit: 20,
            pages: 1,
            total: 0,
            items: []
        };

        function readFilters(section) {
            return {
                login_id: q(section, '[data-audit-login-id]') ? q(section, '[data-audit-login-id]').value.trim() : '',
                action_key: q(section, '[data-audit-action-key]') ? q(section, '[data-audit-action-key]').value.trim() : '',
                result_code: q(section, '[data-audit-result-code]') ? q(section, '[data-audit-result-code]').value.trim() : '',
                from_at: q(section, '[data-audit-from-at]') ? q(section, '[data-audit-from-at]').value.trim() : '',
                to_at: q(section, '[data-audit-to-at]') ? q(section, '[data-audit-to-at]').value.trim() : ''
            };
        }

        function applyDefaultDates(section) {
            var from = q(section, '[data-audit-from-at]');
            var to = q(section, '[data-audit-to-at]');
            var now = new Date();
            var before = new Date(now.getTime() - (1000 * 60 * 60 * 24 * 7));
            var today = now.toISOString().slice(0, 10);
            var fromDay = before.toISOString().slice(0, 10);
            if (from && !from.value) {
                from.value = fromDay;
            }
            if (to && !to.value) {
                to.value = today;
            }
        }

        function render(section) {
            var body = q(section, '[data-audit-list-body]');
            if (!body) {
                return;
            }

            if (state.items.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="tbl-empty">조회 결과가 없습니다.</td></tr>';
            } else {
                body.innerHTML = state.items.map(function (item) {
                    return ''
                        + '<tr>'
                        + '  <td class="tbl-cell">' + toInt(item.idx, 0) + '</td>'
                        + '  <td class="tbl-cell">' + text(formatDate(item.created_at || '')) + '</td>'
                        + '  <td class="tbl-cell">' + text(item.action_key || '-') + '</td>'
                        + '  <td class="tbl-cell">' + text(item.login_id || '-') + '</td>'
                        + '  <td class="tbl-cell">' + badge(item.result_code || '') + '</td>'
                        + '  <td class="tbl-cell">' + text(item.client_ip || '-') + '</td>'
                        + '  <td class="tbl-cell">' + text(item.message || '-') + '</td>'
                        + '</tr>';
                }).join('');
            }

            var summary = q(section, '[data-audit-summary]');
            if (summary) {
                summary.textContent = '총 ' + state.total + '건';
            }
            var pageText = q(section, '[data-audit-page-text]');
            if (pageText) {
                pageText.textContent = state.page + ' / ' + state.pages;
            }
        }

        function fetchList(section, page) {
            var api = section.dataset.auditApi || 'dist_process/saas/AuthAudit.php';
            state.page = Math.max(1, toInt(page, 1));
            state.limit = toInt(q(section, '[data-audit-limit]') && q(section, '[data-audit-limit]').value, 20);
            var filters = readFilters(section);

            var params = {
                todo: 'list',
                page: state.page,
                limit: state.limit,
                login_id: filters.login_id,
                action_key: filters.action_key,
                result_code: filters.result_code,
                from_at: filters.from_at,
                to_at: filters.to_at
            };

            return SHV.api.get(api, params).then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.message) || 'AuthAudit list failed');
                }
                var data = res.data || {};
                state.items = Array.isArray(data.items) ? data.items : [];

                var pg = data.pagination || {};
                state.page = toInt(pg.page, state.page);
                state.limit = toInt(pg.limit, state.limit);
                state.total = toInt(pg.total, 0);
                state.pages = Math.max(1, toInt(pg.total_pages, 1));
                render(section);
            });
        }

        function bindEvents(section) {
            section.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-action]');
                if (!btn) {
                    return;
                }

                var action = btn.getAttribute('data-action');
                if (action === 'audit-search') {
                    fetchList(section, 1).catch(function (err) {
                        if (SHV.toast) {
                            SHV.toast.error(err.message || '감사로그 조회 실패');
                        }
                    });
                    return;
                }
                if (action === 'audit-reset') {
                    if (q(section, '[data-audit-login-id]')) {
                        q(section, '[data-audit-login-id]').value = '';
                    }
                    if (q(section, '[data-audit-action-key]')) {
                        q(section, '[data-audit-action-key]').value = '';
                    }
                    if (q(section, '[data-audit-result-code]')) {
                        q(section, '[data-audit-result-code]').value = '';
                    }
                    applyDefaultDates(section);
                    fetchList(section, 1);
                    return;
                }
                if (action === 'audit-prev-page') {
                    if (state.page > 1) {
                        fetchList(section, state.page - 1);
                    }
                    return;
                }
                if (action === 'audit-next-page') {
                    if (state.page < state.pages) {
                        fetchList(section, state.page + 1);
                    }
                }
            });

            var limit = q(section, '[data-audit-limit]');
            if (limit) {
                limit.addEventListener('change', function () {
                    fetchList(section, 1);
                });
            }

            ['[data-audit-login-id]', '[data-audit-action-key]'].forEach(function (selector) {
                var el = q(section, selector);
                if (!el) {
                    return;
                }
                el.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        fetchList(section, 1);
                    }
                });
            });
        }

        function init() {
            var section = document.querySelector('[data-page="auth-audit"]');
            if (!section) {
                return;
            }
            applyDefaultDates(section);
            bindEvents(section);
            fetchList(section, 1).catch(function (err) {
                if (SHV.toast) {
                    SHV.toast.error(err.message || '감사로그 초기 조회 실패');
                }
            });
        }

        return {
            init: init,
            destroy: function () {}
        };
    }

    SHV.pages = SHV.pages || {};
    SHV.pages.auth_audit = createAuthAuditPage();
})(window.SHV);
