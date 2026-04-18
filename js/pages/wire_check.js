/* ========================================
   배관배선 검토 (wire_check)
   입력: 행 클릭 → SHV.modal.openHtml
   테이블: 결과값 표시 전용
   ======================================== */
'use strict';

(function () {
    var rowSeq = 0;

    /* ── 글로벌 설정 ── */
    function gNum(id) { return parseFloat(document.getElementById(id)?.value) || 0; }

    /* ── 계수 ── */
    function calcK() { return 1.00; }
    function calcL(div, usage, units) {
        return (div === 'RM' && usage === '아파트' && units >= 31) ? 0.95 : 1.00;
    }
    function luByRegion(region) { return region === '수도권' ? 440 : 480; }

    /* ── 행 데이터 ── */
    var DEFAULTS = {
        site:'', dtype:'', region:'', division:'', usage:'',
        maxDist:'', minDist:'', basement:'0', units:'1', wiring:'0.9', estimate:'', memo:''
    };
    function setD(tr, d) {
        Object.keys(DEFAULTS).forEach(function(key){
            tr.dataset[key] = (d[key] !== undefined && d[key] !== null && d[key] !== '') ? d[key] : DEFAULTS[key];
        });
        /* 구분에 따라 배선배수 자동 보정: NI=0.9/1.8, RM=0.8/1.6 */
        var w = parseFloat(tr.dataset.wiring);
        if (tr.dataset.division === 'RM') {
            if (w === 0.9) tr.dataset.wiring = '0.8';
            else if (w === 1.8) tr.dataset.wiring = '1.6';
        } else {
            if (w === 0.8) tr.dataset.wiring = '0.9';
            else if (w === 1.6) tr.dataset.wiring = '1.8';
        }
    }
    function getD(tr) {
        return {
            site: tr.dataset.site || '',
            dtype: tr.dataset.dtype || '',
            region: tr.dataset.region || '지방',
            division: tr.dataset.division || 'NI',
            usage: tr.dataset.usage || '아파트',
            maxDist: parseFloat(tr.dataset.maxDist) || 0,
            minDist: parseFloat(tr.dataset.minDist) || 0,
            basement: parseFloat(tr.dataset.basement) || 0,
            units: parseFloat(tr.dataset.units) || 1,
            wiring: parseFloat(tr.dataset.wiring) || 0.9,
            estimate: parseFloat(tr.dataset.estimate) || 0,
            memo: tr.dataset.memo || ''
        };
    }

    /* ── 단일 행 계산 + DOM 갱신 ── */
    function calcRow(tr) {
        var d  = getD(tr);
        var sr = gNum('wcSurcharge'), il = gNum('wcInline'), fr = gNum('wcFireRoom'), mu = gNum('wcMatUnit');

        var avg  = (d.maxDist + d.minDist) / 2;
        var vert = d.basement * 3;
        var K = calcK(d.division, d.usage);
        var L = calcL(d.division, d.usage, d.units);
        var lu = luByRegion(d.region);
        var calc  = (avg * sr * K + vert + il + fr) * L;
        var labor = calc * lu * d.wiring;
        var mat   = calc * mu;
        var total = labor + mat;
        /* estimate = 견적총수량(전체), 대당견적 = estimate / units */
        var estUnit = (d.estimate > 0 && d.units > 0) ? d.estimate / d.units : 0;
        var gap  = estUnit > 0 ? calc - estUnit : null;
        var gapP = (estUnit > 0 && calc > 0) ? gap / calc : null;

        s(tr, '.wc-d-site', d.site || '—');
        s(tr, '.wc-d-dtype', d.dtype || '—');
        s(tr, '.wc-d-region', d.region);
        s(tr, '.wc-d-division', d.division);
        s(tr, '.wc-d-usage', d.usage);
        s(tr, '.wc-d-max', d.maxDist || '—');
        s(tr, '.wc-d-min', d.minDist || '—');
        s(tr, '.wc-d-avg', avg > 0 ? fn(avg, 1) : '—');
        s(tr, '.wc-d-basement', d.basement || '—');
        s(tr, '.wc-d-vert', d.basement ? fn(vert, 0) : '—');
        s(tr, '.wc-d-units', d.units || '—');
        s(tr, '.wc-d-wiring', d.wiring || '—');
        s(tr, '.wc-d-lu', fm(lu));
        s(tr, '.wc-d-calc', calc > 0 ? fn(calc, 1) : '—');
        s(tr, '.wc-d-labor', labor > 0 ? fm(labor) : '—');
        s(tr, '.wc-d-mat', mat > 0 ? fm(mat) : '—');
        s(tr, '.wc-d-total', total > 0 ? fm(total) : '—');
        s(tr, '.wc-d-estimate', estUnit > 0 ? fn(estUnit, 1) : '—');
        s(tr, '.wc-d-memo', d.memo || '—');

        var gE = tr.querySelector('.wc-d-gap'), gP = tr.querySelector('.wc-d-gapP');
        if (gE) {
            if (gap === null) {
                gE.textContent = '—'; gP.textContent = '—';
                gE.className = 'wc-d-gap wc-gap-zero';
                gP.className = 'wc-d-gapP wc-gap-zero';
            } else {
                var c = gap >= 0 ? 'wc-gap-pos' : 'wc-gap-neg';
                gE.textContent = fn(gap, 1); gP.textContent = fp(gapP);
                gE.className = 'wc-d-gap ' + c;
                gP.className = 'wc-d-gapP ' + c;
            }
        }
        /* 서브행: 견적수량(대당) × 노무440 · 자재320 */
        var sub = tr._subRow;
        if (sub) {
            var sLabor = estUnit > 0 ? estUnit * 440 : 0;
            var sMat   = estUnit > 0 ? estUnit * 320 : 0;
            var sTotal = sLabor + sMat;
            var sDiff = total - sTotal;
            var sGapP = (sTotal > 0 && total > 0) ? sDiff / sTotal : null;
            s(sub, '.wc-s-labor', sLabor > 0 ? fm(sLabor) : '—');
            s(sub, '.wc-s-mat',   sMat > 0 ? fm(sMat) : '—');
            s(sub, '.wc-s-total', sTotal > 0 ? fm(sTotal) : '—');
            /* 증감 · 차액 · GAP% */
            var sSign = sub.querySelector('.wc-s-sign');
            var sDiffEl = sub.querySelector('.wc-s-diff');
            var sgP = sub.querySelector('.wc-s-gapP');
            if (sSign && sDiffEl && sgP) {
                if (sTotal <= 0 || total <= 0) {
                    sSign.textContent = '—'; sDiffEl.textContent = '—'; sgP.textContent = '—';
                    sSign.className = 'wc-s-sign wc-gap-zero';
                    sDiffEl.className = 'wc-s-diff wc-gap-zero';
                    sgP.className = 'wc-s-gapP wc-gap-zero';
                } else {
                    var c = sDiff >= 0 ? 'wc-gap-pos' : 'wc-gap-neg';
                    sSign.textContent = sDiff > 0 ? '▲' : sDiff < 0 ? '▼' : '—';
                    sSign.className = 'wc-s-sign ' + c;
                    sDiffEl.textContent = fm(Math.abs(sDiff));
                    sDiffEl.className = 'wc-s-diff ' + c;
                    sgP.textContent = fp(Math.abs(sGapP));
                    sgP.className = 'wc-s-gapP ' + c;
                }
            }
            /* 노무비 % (서브노무비 / 메인노무비) */
            var slpEl = sub.querySelector('.wc-s-laborP');
            if (slpEl) {
                if (sLabor > 0 && labor > 0) {
                    var lRate = (labor - sLabor) / sLabor;
                    var lc = lRate >= 0 ? 'wc-gap-pos' : 'wc-gap-neg';
                    slpEl.textContent = (lRate >= 0 ? '+' : '') + (lRate * 100).toFixed(1) + '%';
                    slpEl.className = 'wc-s-laborP ' + lc;
                } else {
                    slpEl.textContent = '—';
                    slpEl.className = 'wc-s-laborP wc-gap-zero';
                }
            }
        }

        return { labor: labor, mat: mat, total: total };
    }

    /* ── 전체 합계 ── */
    function calcAll() {
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        var sL = 0, sM = 0, sT = 0;
        rows.forEach(function (r) { var x = calcRow(r); sL += x.labor; sM += x.mat; sT += x.total; });
        s(document, '#wcSumLabor', sT > 0 ? fm(sL) : '—');
        s(document, '#wcSumMat', sT > 0 ? fm(sM) : '—');
        s(document, '#wcSumTotal', sT > 0 ? fm(sT) : '—');
        var el = document.getElementById('wcRowCount');
        if (el) el.innerHTML = '총 <b>' + rows.length + '</b>건';
        var emp = document.getElementById('wcEmpty');
        var tot = document.getElementById('wcTotalRow');
        if (emp) emp.style.display = rows.length === 0 ? '' : 'none';
        if (tot) tot.style.display = rows.length === 0 ? 'none' : '';
        refreshNos();
    }

    function refreshNos() {
        document.querySelectorAll('#wcTbody tr.wc-data-row').forEach(function (r, i) {
            var el = r.querySelector('.wc-no'); if (el) el.textContent = i + 1;
        });
    }

    /* ── 행 생성 (표시전용) ── */
    function makeRow(data) {
        data = data || {};
        rowSeq++;
        var seq = rowSeq;

        var tr = document.createElement('tr');
        tr.className = 'wc-data-row';
        tr.dataset.seq = seq;
        setD(tr, data);

        tr.innerHTML = [
            '<td class="wc-td-no"><span class="wc-no"></span>',
            '<button class="wc-btn-del" onclick="event.stopPropagation();wcDel(this)" title="삭제"><i class="fa fa-times"></i></button></td>',
            td('wc-d-site wc-click'),   td('wc-d-dtype wc-click'),
            td('wc-d-region wc-click'), td('wc-d-division wc-click'), td('wc-d-usage wc-click'),
            td('wc-d-max wc-click'),    td('wc-d-min wc-click'),
            td('wc-d-avg wc-calc'),
            td('wc-d-basement wc-click'), td('wc-d-vert wc-calc'),
            td('wc-d-units wc-click'),  td('wc-d-wiring wc-click'),
            td('wc-d-lu wc-calc'),
            td('wc-d-calc wc-calc-primary'),
            td('wc-d-labor wc-calc'),   td('wc-d-mat wc-calc'),     td('wc-d-total wc-calc-primary'),
            td('wc-d-estimate wc-click'), td('wc-d-gap wc-gap-zero'), td('wc-d-gapP wc-gap-zero'),
            td('wc-d-memo wc-click')
        ].join('');

        tr.addEventListener('click', function (e) {
            if (e.target.closest('.wc-btn-del')) return;
            wcEdit(tr);
        });

        /* 서브행: 노무440 자재320 기준 */
        var sub = document.createElement('tr');
        sub.className = 'wc-sub-row';
        sub.dataset.parentSeq = seq;
        sub.innerHTML = '<td colspan="13" class="wc-sub-line"></td>'
            + '<td class="wc-sub-line text-right" colspan="2"><b>기존단가</b> <span class="wc-sub-tag">견적 노무440·자재320</span></td>'
            + '<td class="wc-sub-line"><span class="wc-s-labor">—</span></td>'
            + '<td class="wc-sub-line"><span class="wc-s-mat">—</span></td>'
            + '<td class="wc-sub-line"><b class="wc-s-total">—</b></td>'
            + '<td class="wc-sub-line"><span class="wc-s-sign wc-gap-zero">—</span></td>'
            + '<td class="wc-sub-line"><span class="wc-s-diff wc-gap-zero">—</span></td>'
            + '<td class="wc-sub-line"><span class="wc-s-gapP wc-gap-zero">—</span></td>'
            + '<td class="wc-sub-line"><span class="wc-s-laborP wc-gap-zero">—</span></td>';
        tr._subRow = sub;

        return tr;
    }

    function td(cls) { return '<td><span class="' + cls + '">—</span></td>'; }

    /* ══════════════════════════════════
       편집 모달 (SHV.modal.openHtml)
    ══════════════════════════════════ */
    function openEditModal(seq, d) {
        var isRM = d.division === 'RM';
        var w1 = isRM ? '0.8' : '0.9';
        var w2 = isRM ? '1.6' : '1.8';
        var wSel = (d.wiring == 0.8 || d.wiring == 0.9) ? w1
                 : (d.wiring == 1.6 || d.wiring == 1.8) ? w2 : 'custom';
        var showCustom = wSel === 'custom' ? '' : 'display:none;';

        var h = '<div class="wc-mf">'
        + '<div class="wc-mf-grid">'
        + fg('현장번호',     '<input id="wm_site" class="form-control" type="text" value="' + esc(d.site) + '" placeholder="예) N26808" onblur="wcCheckSiteDup(\'' + seq + '\')">')
        + fg('도면 Type',    opt('wm_dtype', [['선택',''],['수기','수기'],['CAD','CAD']], d.dtype))
        + fg('지역',         opt('wm_region', [['선택',''],['지방','지방'],['수도권','수도권']], d.region))
        + fg('구분',         opt('wm_division', [['선택',''],['NI','NI'],['RM','RM']], d.division, 'wcDivChange()'))
        + fg('건물용도',     opt('wm_usage', [['선택',''],['아파트','아파트'],['오피스텔','오피스텔'],['상가','상가'],['호텔','호텔'],['주상복합','주상복합']], d.usage))
        + fg('최장거리 (m)', inp('wm_max', 'number', d.maxDist || '', '0'))
        + fg('최단거리 (m)', inp('wm_min', 'number', d.minDist || '', '0'))
        + fg('지하층수',     opt('wm_basement', basementOpts(), String(d.basement || '0')))
        + fg('총대수',        '<input id="wm_units" class="form-control" type="number" value="' + esc(d.units || '1') + '" placeholder="1" oninput="wcCalcEst()">')
        + fg('배선배수',     opt('wm_wSel', [['1배선 (' + w1 + ')',w1],['2배선 (' + w2 + ')',w2],['직접입력','custom']], wSel, 'wcWiringSel()')
                             + '<input id="wm_wiring" class="form-control wc-mf-mt" type="number" min="0.1" step="0.1" value="' + (d.wiring || w1) + '" style="' + showCustom + '">')
        + fg('견적총수량',   '<input id="wm_totalEst" class="form-control" type="number" value="' + esc(d.estimate || '') + '" placeholder="전체 견적수량 입력" oninput="wcCalcEst()">')
        + fg('견적수량(대당)', '<input id="wm_estimate" class="form-control" type="number" value="" placeholder="자동: 견적총수량÷대수" readonly style="background:var(--bg-2);cursor:default;">')
        + fg('메모',         inp('wm_memo', 'text', d.memo, '비고'))
        + '</div>'
        + '<div class="wc-mf-foot">'
        + '<button class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>'
        + '<button class="btn btn-primary btn-sm" onclick="wcSave(\'' + seq + '\')">저장</button>'
        + '</div></div>';

        SHV.modal.openHtml(h, '배선 입력', 'md');
        setTimeout(wcCalcEst, 0);
    }

    window.wcEdit = function (tr) {
        openEditModal(tr.dataset.seq, getD(tr));
    };

    /* 견적총수량 → 견적수량(대당) 자동계산 */
    window.wcCalcEst = function () {
        var total = parseFloat(document.getElementById('wm_totalEst')?.value) || 0;
        var units = parseFloat(document.getElementById('wm_units')?.value) || 1;
        var est   = document.getElementById('wm_estimate');
        if (est) est.value = total > 0 ? (total / units).toFixed(1) : '';
    };

    /* 현장번호 중복 체크 (blur 시) */
    window.wcCheckSiteDup = function (seq) {
        var el = document.getElementById('wm_site');
        if (!el) return;
        var val = el.value.trim();
        if (!val) return;
        var dup = false;
        document.querySelectorAll('#wcTbody tr.wc-data-row').forEach(function (r) {
            if (r.dataset.seq !== seq && (r.dataset.site || '').trim() === val) dup = true;
        });
        if (dup) {
            if (SHV.toast) SHV.toast.warn('현장번호 "' + val + '"이(가) 이미 존재합니다.');
            el.style.borderColor = 'var(--danger)';
            el.focus();
        } else {
            el.style.borderColor = '';
        }
    };

    /* 배선배수 select 변경 */
    window.wcWiringSel = function () {
        var sel = document.getElementById('wm_wSel');
        var inp = document.getElementById('wm_wiring');
        if (!sel || !inp) return;
        if (sel.value === 'custom') { inp.style.display = ''; inp.focus(); }
        else { inp.value = sel.value; inp.style.display = 'none'; }
    };

    /* 구분(NI/RM) 변경 → 배선배수 자동 전환 */
    window.wcDivChange = function () {
        var div = (document.getElementById('wm_division')?.value || '');
        var wSel = document.getElementById('wm_wSel');
        if (!wSel || !div) return;
        var isRM = div === 'RM';
        var w1 = isRM ? '0.8' : '0.9';
        var w2 = isRM ? '1.6' : '1.8';
        wSel.options[0].text = '1배선 (' + w1 + ')'; wSel.options[0].value = w1;
        wSel.options[1].text = '2배선 (' + w2 + ')'; wSel.options[1].value = w2;
        if (wSel.value !== 'custom') wcWiringSel();
    };

    /* 모달 저장 */
    window.wcSave = function (seq) {
        var tr = document.querySelector('#wcTbody tr[data-seq="' + seq + '"]');
        var isNew = !tr;

        /* 필수항목 검증 */
        var reqFields = [
            ['wm_site',     '현장번호'],
            ['wm_dtype',    '도면타입'],
            ['wm_region',   '지역'],
            ['wm_division', '구분'],
            ['wm_usage',    '건물용도'],
            ['wm_max',      '최장거리'],
            ['wm_min',      '최단거리'],
            ['wm_basement', '지하층수'],
            ['wm_units',    '총대수'],
            ['wm_totalEst', '견적총수량']
        ];
        var zeroAllowed = { 'wm_basement': true };
        for (var i = 0; i < reqFields.length; i++) {
            var el = document.getElementById(reqFields[i][0]);
            var val = el ? (el.value || '').trim() : '';
            if (!val || (!zeroAllowed[reqFields[i][0]] && val === '0')) {
                if (SHV.toast) SHV.toast.warn(reqFields[i][1] + ' 항목을 입력해주세요.');
                if (el) el.focus();
                return;
            }
        }

        /* 현장번호 중복 검사 */
        var siteVal = (v('wm_site') || '').trim();
        if (siteVal) {
            var dup = false;
            document.querySelectorAll('#wcTbody tr.wc-data-row').forEach(function (r) {
                if (r.dataset.seq !== seq && (r.dataset.site || '').trim() === siteVal) dup = true;
            });
            if (dup) {
                if (SHV.toast) SHV.toast.warn('현장번호 "' + siteVal + '"이(가) 이미 존재합니다.');
                return;
            }
        }

        var wSelV = document.getElementById('wm_wSel')?.value;
        var wVal  = wSelV === 'custom' ? (document.getElementById('wm_wiring')?.value || '0.9') : wSelV;

        var rowData = {
            site:     siteVal,           dtype:    v('wm_dtype'),
            region:   v('wm_region'),
            division: v('wm_division'), usage:    v('wm_usage'),
            maxDist:  v('wm_max'),      minDist:  v('wm_min'),
            basement: v('wm_basement'), units:    v('wm_units'),
            wiring:   wVal,             estimate: v('wm_totalEst'),
            memo:     v('wm_memo')
        };

        if (isNew) {
            /* 신규: 저장 확정 시점에 DOM 행 생성 */
            var tbody = document.getElementById('wcTbody');
            tr = makeRow(rowData);
            tbody.appendChild(tr);
            if (tr._subRow) tbody.appendChild(tr._subRow);
        } else {
            setD(tr, rowData);
        }

        SHV.modal.close();
        calcAll();
        wcSaveDB();
    };

    function v(id) { return document.getElementById(id)?.value || ''; }

    /* ── 공개 API ── */
    window.wcAdd = function (data) {
        var tbody = document.getElementById('wcTbody');
        if (!tbody) return;
        if (data) {
            /* 엑셀/데이터 import: 바로 DOM 행 생성 */
            var tr = makeRow(data);
            tbody.appendChild(tr);
            if (tr._subRow) tbody.appendChild(tr._subRow);
            calcAll();
        } else {
            /* 신규: 모달만 열고, 저장 확정 시 DOM 생성 (빈 행 방지) */
            openEditModal('__new__', {
                site:'', dtype:'', region:'', division:'', usage:'',
                maxDist:0, minDist:0, basement:0, units:1, wiring:0.9, estimate:0, memo:''
            });
        }
    };
    window.wcDel = function (btn) {
        var tr = btn.closest('tr');
        if (tr && tr._subRow) tr._subRow.remove();
        if (tr) tr.remove();
        calcAll();
        /* 삭제 후 DB 자동 저장 */
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        if (rows.length > 0) {
            wcSaveDB();
        } else if (_currentGroupId > 0) {
            /* 전부 삭제된 경우 DB 그룹도 삭제 */
            var gid = _currentGroupId;
            SHV.api.post(API_URL, { todo: 'delete', group_id: gid }).then(function (res) {
                if (res && res.ok) {
                    _currentGroupId = 0;
                    if (SHV.toast) SHV.toast.success('그룹 #' + gid + ' 삭제 완료');
                }
            }).catch(function (err) {
                console.error('[WC] group delete error:', err);
                if (SHV.toast) SHV.toast.error('삭제 중 오류가 발생했습니다.');
            });
        }
    };
    window.wcReset = function () {
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        if (rows.length === 0) return;
        var h = '<div class="wc-mf" style="text-align:center;padding:var(--sp-6) var(--sp-4);">'
            + '<p style="margin:0 0 var(--sp-4);">전체 ' + rows.length + '건을 초기화하시겠습니까?</p>'
            + '<div class="wc-mf-foot">'
            + '<button class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>'
            + '<button class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="wcDoReset()">초기화</button>'
            + '</div></div>';
        SHV.modal.openHtml(h, '초기화 확인', 'alert');
    };
    window.wcDoReset = function () {
        var gid = _currentGroupId;
        var tbody = document.getElementById('wcTbody');
        if (tbody) tbody.innerHTML = '';
        rowSeq = 0; _currentGroupId = 0;
        calcAll();
        SHV.modal.close();
        /* DB에서도 그룹 삭제 */
        if (gid > 0) {
            SHV.api.post(API_URL, { todo: 'delete', group_id: gid }).then(function (res) {
                if (res && res.ok && SHV.toast) SHV.toast.success('초기화 완료');
            }).catch(function (err) {
                console.error('[WC] reset delete error:', err);
            });
        }
    };
    window.wcSettingChange = calcAll;

    /* ── 검색 필터 ── */
    window.wcFilter = function () {
        var q = (document.getElementById('wcSearch')?.value || '').trim().toLowerCase();
        var divF = (document.getElementById('wcFilterDiv')?.value || '');
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        var visible = 0;
        rows.forEach(function (r) {
            var d = r.dataset;
            var text = [d.site, d.dtype, d.region, d.division, d.usage, d.units, d.memo].join(' ').toLowerCase();
            var matchText = !q || text.indexOf(q) >= 0;
            var matchDiv = !divF || d.division === divF;
            var show = matchText && matchDiv;
            r.style.display = show ? '' : 'none';
            if (r._subRow) r._subRow.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        var el = document.getElementById('wcRowCount');
        if (el) {
            if (q || divF) {
                el.innerHTML = '<b>' + visible + '</b>/' + rows.length + '건';
            } else {
                el.innerHTML = '총 <b>' + rows.length + '</b>건';
            }
        }
    };

    /* ── 정렬 ── */
    var _sortKey = '', _sortAsc = true;
    var SORT_VAL = {
        site: function(d){ return d.site; },
        dtype: function(d){ return d.dtype; },
        region: function(d){ return d.region; },
        division: function(d){ return d.division; },
        usage: function(d){ return d.usage; },
        maxDist: function(d){ return d.maxDist; },
        minDist: function(d){ return d.minDist; },
        avg: function(d){ return (d.maxDist + d.minDist) / 2; },
        basement: function(d){ return d.basement; },
        vert: function(d){ return d.basement * 3; },
        units: function(d){ return d.units; },
        wiring: function(d){ return d.wiring; },
        calc: function(d, tr){ return numVal(tr, '.wc-d-calc'); },
        labor: function(d, tr){ return numVal(tr, '.wc-d-labor'); },
        mat: function(d, tr){ return numVal(tr, '.wc-d-mat'); },
        total: function(d, tr){ return numVal(tr, '.wc-d-total'); },
        estimate: function(d){ return d.estimate > 0 && d.units > 0 ? d.estimate / d.units : 0; },
        gap: function(d, tr){ return numVal(tr, '.wc-d-gap'); },
        gapP: function(d, tr){ var t = (tr.querySelector('.wc-d-gapP')?.textContent || '').replace('%',''); return parseFloat(t) || 0; }
    };
    function numVal(tr, sel) { return parseFloat((tr.querySelector(sel)?.textContent || '').replace(/,/g, '')) || 0; }

    window.wcSort = function (key) {
        if (_sortKey === key) { _sortAsc = !_sortAsc; }
        else { _sortKey = key; _sortAsc = true; }

        var tbody = document.getElementById('wcTbody');
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.wc-data-row'));
        if (rows.length === 0) return;

        var getter = SORT_VAL[key];
        if (!getter) return;

        rows.sort(function (a, b) {
            var va = getter(getD(a), a);
            var vb = getter(getD(b), b);
            if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb || '').toLowerCase(); }
            var cmp = va < vb ? -1 : va > vb ? 1 : 0;
            return _sortAsc ? cmp : -cmp;
        });

        rows.forEach(function (tr) {
            tbody.appendChild(tr);
            if (tr._subRow) tbody.appendChild(tr._subRow);
        });
        refreshNos();

        /* 헤더 표시 갱신 */
        document.querySelectorAll('.wc-sortable').forEach(function (th) {
            th.classList.remove('wc-sort-asc', 'wc-sort-desc');
            if (th.dataset.sort === key) th.classList.add(_sortAsc ? 'wc-sort-asc' : 'wc-sort-desc');
        });
    };

    /* thead 클릭 이벤트 위임 */
    document.addEventListener('click', function (e) {
        var th = e.target.closest('.wc-sortable[data-sort]');
        if (th) wcSort(th.dataset.sort);
    });

    window.wcInit = function () {
        calcAll();
        /* 최신 그룹 자동 로드 */
        var url = 'dist_process/saas/WireCheck.php';
        SHV.api.get(url, { todo: 'list' }).then(function (res) {
            if (!res || !res.ok) return;
            var rows = res.data || [];
            if (rows.length === 0) return;
            var first = rows[0];
            if (first.surcharge) document.getElementById('wcSurcharge').value = first.surcharge;
            if (first.inline_m) document.getElementById('wcInline').value = first.inline_m;
            if (first.fire_room) document.getElementById('wcFireRoom').value = first.fire_room;
            if (first.mat_unit) document.getElementById('wcMatUnit').value = first.mat_unit;
            var tbody = document.getElementById('wcTbody');
            if (!tbody) return;
            tbody.innerHTML = '';
            rowSeq = 0;
            rows.forEach(function (r) {
                var tr = makeRow({
                    site: r.site, dtype: r.dtype,
                    region: r.region, division: r.division, usage: r.usage,
                    maxDist: r.max_dist, minDist: r.min_dist,
                    basement: r.basement, units: r.units, wiring: r.wiring,
                    estimate: r.estimate, memo: r.memo
                });
                tbody.appendChild(tr);
                if (tr._subRow) tbody.appendChild(tr._subRow);
            });
            _currentGroupId = first.group_id || 0;
            calcAll();
        }).catch(function () {});
    };

    /* ══════════════════════════════════
       DB 저장 / 불러오기 / 삭제
    ══════════════════════════════════ */
    var API_URL = 'dist_process/saas/WireCheck.php';
    var _currentGroupId = 0;
    var _saving = false;
    var _saveQueued = false;

    /* 저장 (mutex: 동시 호출 방지, 큐잉) */
    window.wcSaveDB = function () {
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        if (rows.length === 0) {
            if (SHV.toast) SHV.toast.warn('저장할 데이터가 없습니다.');
            return;
        }
        if (_saving) { _saveQueued = true; return; }
        _saving = true;

        var arr = [];
        rows.forEach(function (r) {
            var d = getD(r);
            if (!d.site) return; /* 현장번호 없는 빈 행 제외 */
            arr.push({
                site: d.site, dtype: d.dtype,
                region: d.region, division: d.division, usage: d.usage,
                max_dist: d.maxDist, min_dist: d.minDist,
                basement: d.basement, units: d.units, wiring: d.wiring,
                estimate: d.estimate || null, memo: d.memo || null
            });
        });
        if (arr.length === 0) {
            _saving = false;
            if (SHV.toast) SHV.toast.warn('저장할 데이터가 없습니다.');
            return;
        }
        var payload = {
            todo: 'save',
            rows: JSON.stringify(arr),
            surcharge: gNum('wcSurcharge'),
            inline_m: gNum('wcInline'),
            fire_room: gNum('wcFireRoom'),
            mat_unit: gNum('wcMatUnit')
        };
        if (_currentGroupId > 0) payload.group_id = _currentGroupId;

        SHV.api.post(API_URL, payload).then(function (res) {
            _saving = false;
            if (res && res.ok) {
                _currentGroupId = res.data.group_id;
                if (SHV.toast) SHV.toast.success('저장 완료 (그룹 #' + _currentGroupId + ', ' + res.data.count + '건)');
            } else if (SHV.toast) {
                SHV.toast.error('저장 실패: ' + ((res && res.message) || '서버 오류'));
            }
            if (_saveQueued) { _saveQueued = false; wcSaveDB(); }
        }).catch(function (err) {
            _saving = false;
            console.error('[WC] DB save error:', err);
            if (SHV.toast) SHV.toast.error('저장 중 오류가 발생했습니다.');
            if (_saveQueued) { _saveQueued = false; wcSaveDB(); }
        });
    };

    /* 그룹 목록 모달 */
    window.wcLoadList = function () {
        SHV.api.get(API_URL, { todo: 'groups' }).then(function (res) {
            if (!res || !res.ok) {
                if (SHV.toast) SHV.toast.error('목록 조회 실패');
                return;
            }
            var groups = res.data || [];
            if (groups.length === 0) {
                if (SHV.toast) SHV.toast.info('저장된 데이터가 없습니다.');
                return;
            }
            var h = '<div class="wc-mf"><div style="padding:0 var(--sp-4);">';
            h += '<table class="tbl" style="width:100%;font-size:var(--text-xs);">';
            h += '<thead><tr><th>그룹</th><th>현장수</th><th>작성자</th><th>저장일</th><th></th></tr></thead><tbody>';
            groups.forEach(function (g) {
                h += '<tr>';
                h += '<td class="text-center">#' + g.group_id + '</td>';
                h += '<td class="text-center">' + g.site_count + '건</td>';
                h += '<td class="text-center">' + esc(g.created_by_name) + '</td>';
                h += '<td class="text-center">' + (g.created_at || '').slice(0, 16) + '</td>';
                h += '<td class="text-center">';
                h += '<button class="btn btn-primary btn-sm" onclick="wcLoadGroup(' + g.group_id + ')">불러오기</button> ';
                h += '<button class="btn btn-ghost btn-sm" onclick="wcDeleteGroup(' + g.group_id + ')" style="color:var(--danger);"><i class="fa fa-trash-o"></i></button>';
                h += '</td></tr>';
            });
            h += '</tbody></table></div></div>';
            SHV.modal.openHtml(h, '저장 목록', 'lg');
        }).catch(function (err) {
            console.error('[WC] groups error:', err);
            if (SHV.toast) SHV.toast.error('목록 조회 중 오류가 발생했습니다.');
        });
    };

    /* 그룹 불러오기 */
    window.wcLoadGroup = function (groupId) {
        SHV.api.get(API_URL, { todo: 'list', group_id: groupId }).then(function (res) {
            if (!res || !res.ok) return;
            var rows = res.data || [];
            if (rows.length === 0) {
                if (SHV.toast) SHV.toast.warn('데이터가 비어있습니다.');
                return;
            }
            /* 글로벌 설정 복원 (첫 행 기준) */
            var first = rows[0];
            if (first.surcharge) document.getElementById('wcSurcharge').value = first.surcharge;
            if (first.inline_m) document.getElementById('wcInline').value = first.inline_m;
            if (first.fire_room) document.getElementById('wcFireRoom').value = first.fire_room;
            if (first.mat_unit) document.getElementById('wcMatUnit').value = first.mat_unit;

            /* 행 초기화 후 데이터 로드 */
            var tbody = document.getElementById('wcTbody');
            tbody.innerHTML = '';
            rowSeq = 0;
            rows.forEach(function (r) {
                var tr = makeRow({
                    site: r.site, dtype: r.dtype,
                    region: r.region, division: r.division, usage: r.usage,
                    maxDist: r.max_dist, minDist: r.min_dist,
                    basement: r.basement, units: r.units, wiring: r.wiring,
                    estimate: r.estimate, memo: r.memo
                });
                tbody.appendChild(tr);
                if (tr._subRow) tbody.appendChild(tr._subRow);
            });
            _currentGroupId = groupId;
            calcAll();
            SHV.modal.close();
            if (SHV.toast) SHV.toast.success('그룹 #' + groupId + ' 불러오기 완료 (' + rows.length + '건)');
        }).catch(function (err) {
            console.error('[WC] load group error:', err);
            if (SHV.toast) SHV.toast.error('불러오기 중 오류가 발생했습니다.');
        });
    };

    /* 그룹 삭제 */
    window.wcDeleteGroup = function (groupId) {
        var h = '<div class="wc-mf" style="text-align:center;padding:var(--sp-6) var(--sp-4);">'
            + '<p style="margin:0 0 var(--sp-4);">그룹 #' + groupId + ' 을(를) 삭제하시겠습니까?</p>'
            + '<div class="wc-mf-foot">'
            + '<button class="btn btn-ghost btn-sm" onclick="SHV.modal.close();wcLoadList()">취소</button>'
            + '<button class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="wcDoDelete(' + groupId + ')">삭제</button>'
            + '</div></div>';
        SHV.modal.openHtml(h, '삭제 확인', 'alert');
    };
    window.wcDoDelete = function (groupId) {
        SHV.api.post(API_URL, { todo: 'delete', group_id: groupId }).then(function (res) {
            if (res && res.ok) {
                if (_currentGroupId === groupId) _currentGroupId = 0;
                if (SHV.toast) SHV.toast.success('삭제 완료');
                SHV.modal.close();
                wcLoadList();
            }
        }).catch(function (err) {
            console.error('[WC] delete error:', err);
            if (SHV.toast) SHV.toast.error('삭제 중 오류가 발생했습니다.');
        });
    };

    /* ── 엑셀 업로드 (대량) ── */
    var _xlsxLoaded = false;
    function loadXLSX(cb) {
        if (_xlsxLoaded && window.XLSX) { cb(); return; }
        var sc = document.createElement('script');
        sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
        sc.onload = function () { _xlsxLoaded = true; cb(); };
        sc.onerror = function () {
            if (SHV.toast) SHV.toast.error('엑셀 라이브러리 로드 실패');
        };
        document.head.appendChild(sc);
    }

    /* 컬럼 매핑: 엑셀 헤더 → 내부 키 */
    var COL_MAP = {
        '현장번호': 'site', '현장': 'site',
        '도면type': 'dtype', '도면': 'dtype', '도면타입': 'dtype',
        '지역': 'region',
        '구분': 'division',
        '건물용도': 'usage', '용도': 'usage',
        '최장거리': 'maxDist', '최장': 'maxDist', '최장(m)': 'maxDist',
        '최단거리': 'minDist', '최단': 'minDist', '최단(m)': 'minDist',
        '지하층수': 'basement', '지하층': 'basement', '지하': 'basement',
        '대수': 'units', '총대수': 'units',
        '배선배수': 'wiring', '배선': 'wiring',
        '견적총수량': 'estimate', '견적수량': 'estimate', '견적': 'estimate',
        '메모': 'memo', '비고': 'memo'
    };

    function mapHeaders(headers) {
        var map = {};
        headers.forEach(function (h, i) {
            var key = String(h || '').trim().toLowerCase().replace(/\s+/g, '');
            Object.keys(COL_MAP).forEach(function (k) {
                if (k.toLowerCase().replace(/\s+/g, '') === key) map[i] = COL_MAP[k];
            });
        });
        return map;
    }

    window.wcExcelUpload = function () {
        var h = '<div class="wc-mf" style="padding:var(--sp-4);">'
            + '<div style="padding:0 var(--sp-4);">'
            + '<p class="text-sm text-2" style="margin:0 0 var(--sp-3);">엑셀 파일(.xlsx, .xls, .csv)을 선택하면 자동으로 행이 추가됩니다.</p>'
            + '<p class="text-xs text-3" style="margin:0 0 var(--sp-3);">필수 컬럼: <b>현장번호, 최장거리, 최단거리, 총대수</b><br>'
            + '인식 컬럼: 현장번호, 도면Type, 지역, 구분, 건물용도, 최장거리, 최단거리, 지하층수, 총대수, 배선배수, 견적총수량, 메모</p>'
            + '<div style="border:2px dashed var(--border);border-radius:var(--radius-md);padding:var(--sp-6);text-align:center;cursor:pointer;" '
            + 'onclick="document.getElementById(\'wcFileInput\').click()" id="wcDropZone">'
            + '<i class="fa fa-file-excel-o" style="font-size:32px;color:var(--accent);"></i>'
            + '<p class="text-sm text-3" style="margin:var(--sp-2) 0 0;">클릭하여 파일 선택 또는 드래그&드롭</p>'
            + '</div>'
            + '<input type="file" id="wcFileInput" accept=".xlsx,.xls,.csv" style="display:none;" onchange="wcParseFile(this)">'
            + '<div id="wcUploadPreview" style="display:none;margin-top:var(--sp-3);"></div>'
            + '</div></div>';
        SHV.modal.openHtml(h, '엑셀 업로드', 'lg');

        /* 드래그&드롭 */
        setTimeout(function () {
            var dz = document.getElementById('wcDropZone');
            if (!dz) return;
            dz.addEventListener('dragover', function (e) {
                e.preventDefault(); dz.style.borderColor = 'var(--accent)'; dz.style.background = 'var(--accent-10)';
            });
            dz.addEventListener('dragleave', function () {
                dz.style.borderColor = 'var(--border)'; dz.style.background = '';
            });
            dz.addEventListener('drop', function (e) {
                e.preventDefault();
                dz.style.borderColor = 'var(--border)'; dz.style.background = '';
                if (e.dataTransfer.files.length > 0) {
                    var fi = document.getElementById('wcFileInput');
                    if (fi) { fi.files = e.dataTransfer.files; wcParseFile(fi); }
                }
            });
        }, 100);
    };

    var _parsedRows = [];

    window.wcParseFile = function (input) {
        var file = input.files[0];
        if (!file) return;
        loadXLSX(function () {
            var reader = new FileReader();
            reader.onload = function (e) {
                try {
                    var wb = XLSX.read(e.target.result, { type: 'array' });
                    var ws = wb.Sheets[wb.SheetNames[0]];
                    var json = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
                    if (json.length < 2) {
                        if (SHV.toast) SHV.toast.warn('데이터가 없습니다. 첫 행은 헤더여야 합니다.');
                        return;
                    }
                    var hMap = mapHeaders(json[0]);
                    if (Object.keys(hMap).length === 0) {
                        if (SHV.toast) SHV.toast.error('인식 가능한 컬럼 헤더가 없습니다.');
                        return;
                    }
                    _parsedRows = [];
                    for (var r = 1; r < json.length; r++) {
                        var row = json[r];
                        var obj = {};
                        var hasData = false;
                        Object.keys(hMap).forEach(function (ci) {
                            var val = row[parseInt(ci)];
                            if (val !== '' && val !== null && val !== undefined) hasData = true;
                            obj[hMap[ci]] = val;
                        });
                        if (hasData) _parsedRows.push(obj);
                    }
                    if (_parsedRows.length === 0) {
                        if (SHV.toast) SHV.toast.warn('유효한 데이터 행이 없습니다.');
                        return;
                    }
                    /* 미리보기 */
                    var prev = document.getElementById('wcUploadPreview');
                    if (prev) {
                        var ph = '<p class="text-sm" style="margin:0 0 var(--sp-2);"><b>' + _parsedRows.length + '건</b> 인식됨 (파일: ' + esc(file.name) + ')</p>';
                        ph += '<div style="max-height:200px;overflow:auto;">';
                        ph += '<table class="tbl" style="width:100%;font-size:10px;">';
                        ph += '<thead><tr>';
                        var cols = Object.values(hMap);
                        var colLabels = { site:'현장번호', dtype:'도면', region:'지역', division:'구분', usage:'용도', maxDist:'최장', minDist:'최단', basement:'지하층', units:'총대수', wiring:'배선', estimate:'견적총수량', memo:'메모' };
                        cols.forEach(function (c) { ph += '<th>' + (colLabels[c] || c) + '</th>'; });
                        ph += '</tr></thead><tbody>';
                        var showCount = Math.min(_parsedRows.length, 10);
                        for (var i = 0; i < showCount; i++) {
                            ph += '<tr>';
                            cols.forEach(function (c) { ph += '<td class="text-center">' + esc(_parsedRows[i][c] || '') + '</td>'; });
                            ph += '</tr>';
                        }
                        if (_parsedRows.length > 10) ph += '<tr><td colspan="' + cols.length + '" class="text-center text-3">... 외 ' + (_parsedRows.length - 10) + '건</td></tr>';
                        ph += '</tbody></table></div>';
                        ph += '<div style="display:flex;justify-content:flex-end;gap:var(--sp-2);margin-top:var(--sp-3);">';
                        ph += '<label style="display:flex;align-items:center;gap:4px;font-size:var(--text-xs);color:var(--text-2);"><input type="checkbox" id="wcUploadClear"> 기존 행 초기화 후 추가</label>';
                        ph += '<button class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>';
                        ph += '<button class="btn btn-primary btn-sm" onclick="wcApplyExcel()"><i class="fa fa-check"></i> ' + _parsedRows.length + '건 추가</button>';
                        ph += '</div>';
                        prev.style.display = '';
                        prev.innerHTML = ph;
                    }
                } catch (err) {
                    console.error('[WC] Excel parse error:', err);
                    if (SHV.toast) SHV.toast.error('파일 파싱 실패: ' + err.message);
                }
            };
            reader.readAsArrayBuffer(file);
        });
    };

    window.wcApplyExcel = function () {
        if (_parsedRows.length === 0) return;
        var clearFirst = document.getElementById('wcUploadClear')?.checked;
        var tbody = document.getElementById('wcTbody');
        if (!tbody) return;

        if (clearFirst) {
            tbody.innerHTML = '';
            rowSeq = 0;
            _currentGroupId = 0;
        }

        var added = 0;
        _parsedRows.forEach(function (r) {
            var tr = makeRow({
                site:     String(r.site || ''),
                dtype:    String(r.dtype || '수기'),
                region:   String(r.region || '지방'),
                division: String(r.division || 'NI'),
                usage:    String(r.usage || '아파트'),
                maxDist:  parseFloat(r.maxDist) || 0,
                minDist:  parseFloat(r.minDist) || 0,
                basement: parseInt(r.basement) || 0,
                units:    parseInt(r.units) || 1,
                wiring:   parseFloat(r.wiring) || 0.9,
                estimate: parseFloat(r.estimate) || 0,
                memo:     String(r.memo || '')
            });
            tbody.appendChild(tr);
            if (tr._subRow) tbody.appendChild(tr._subRow);
            added++;
        });

        _parsedRows = [];
        calcAll();
        SHV.modal.close();
        if (SHV.toast) SHV.toast.success(added + '건 엑셀 업로드 완료');
    };

    /* ── CSV 내보내기 ── */
    window.wcCSV = function () {
        var rows = document.querySelectorAll('#wcTbody tr.wc-data-row');
        var hd = ['현장번호','도면Type','지역','구분','건물용도',
                   '최장거리','최단거리','평균거리','지하층수','수직거리',
                   '총대수','배선배수','노무단가','산출계',
                   '노무비','자재비','총합','견적수량','GAP 수량','GAP %','메모'];
        var lines = [hd.join(',')];
        rows.forEach(function (r) {
            var d = getD(r);
            lines.push([
                q(d.site), q(d.dtype), q(d.region), q(d.division), q(d.usage),
                d.maxDist, d.minDist, t(r,'.wc-d-avg'), d.basement, t(r,'.wc-d-vert'),
                d.units, d.wiring, t(r,'.wc-d-lu'),
                t(r,'.wc-d-calc'), n(r,'.wc-d-labor'), n(r,'.wc-d-mat'), n(r,'.wc-d-total'),
                d.estimate, t(r,'.wc-d-gap'), t(r,'.wc-d-gapP'), q(d.memo)
            ].join(','));
        });
        var blob = new Blob(['\uFEFF' + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = '배관배선_검토_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    };

    /* ── 유틸 ── */
    function s(ctx, sel, val) { var el = ctx.querySelector(sel); if (el) el.textContent = val; }
    function fn(v, d) { return isNaN(v) ? '—' : v.toFixed(d); }
    function fm(v)    { return isNaN(v) ? '—' : Math.round(v).toLocaleString('ko-KR'); }
    function fp(v)    { return isNaN(v) ? '—' : (v * 100).toFixed(0) + '%'; }
    function esc(x)   { return String(x||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
    function q(x)     { return '"' + String(x||'').replace(/"/g,'""') + '"'; }
    function t(r, sel) { return r.querySelector(sel)?.textContent || ''; }
    function n(r, sel) { return (r.querySelector(sel)?.textContent || '').replace(/,/g, ''); }

    function basementOpts() {
        var arr = [['없음','0']];
        for (var i = 1; i <= 20; i++) arr.push(['B' + i + ' (' + i + '층)', String(i)]);
        return arr;
    }

    function inp(id, type, val, ph) {
        return '<input id="' + id + '" class="form-control" type="' + type + '" value="' + esc(val) + '" placeholder="' + esc(ph) + '">';
    }
    function opt(id, pairs, sel, oc) {
        var html = '<select id="' + id + '" class="form-control"' + (oc ? ' onchange="' + oc + '"' : '') + '>';
        pairs.forEach(function (p) {
            html += '<option value="' + esc(p[1]) + '"' + (p[1] === sel ? ' selected' : '') + '>' + esc(p[0]) + '</option>';
        });
        return html + '</select>';
    }
    function fg(label, inner) {
        return '<div class="wc-mf-item"><label class="form-label">' + label + '</label>' + inner + '</div>';
    }

})();
