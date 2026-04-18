/* ========================================
   배관배선 통계 (wire_stats)
   구분·용도별 대수구간 기존단가 대비 증감률
   ======================================== */
'use strict';

(function () {
    var API_URL = 'dist_process/saas/WireCheck.php';

    /* 대수구간 정의 */
    var RANGES = [
        { label: '1~5',   min: 1,  max: 5 },
        { label: '6~10',  min: 6,  max: 10 },
        { label: '11~15', min: 11, max: 15 },
        { label: '16~20', min: 16, max: 20 },
        { label: '21~25', min: 21, max: 25 },
        { label: '26~30', min: 26, max: 30 },
        { label: '31~35', min: 31, max: 35 },
        { label: '36~40', min: 36, max: 40 },
        { label: '41~45', min: 41, max: 45 },
        { label: '46~50', min: 46, max: 50 },
        { label: '51~',   min: 51, max: 99999 }
    ];

    var DIVISIONS = ['NI', 'RM'];
    var REGIONS = ['수도권', '지방'];
    var USAGES = ['아파트', '오피스텔', '상가', '호텔', '주상복합'];

    /* 계수 (wire_check.js 와 동일) */
    function calcK() { return 1.00; }
    function calcL(div, usage, units) {
        return (div === 'RM' && usage === '아파트' && units >= 31) ? 0.95 : 1.00;
    }
    function luByRegion(region) { return region === '수도권' ? 440 : 480; }

    function getRangeIdx(units) {
        for (var i = 0; i < RANGES.length; i++) {
            if (units >= RANGES[i].min && units <= RANGES[i].max) return i;
        }
        return -1;
    }

    /* 행 하나의 통계값 계산 — 노무비 증감, GAP%, 총합 증감 */
    function calcMetrics(r, settings) {
        var maxDist  = parseFloat(r.max_dist) || 0;
        var minDist  = parseFloat(r.min_dist) || 0;
        var basement = parseFloat(r.basement) || 0;
        var units    = parseFloat(r.units) || 1;
        var wiring   = parseFloat(r.wiring) || 0.9;
        var division = r.division || 'NI';
        /* 배선배수 보정: RM=0.8/1.6, NI=0.9/1.8 */
        if (division === 'RM') { if (wiring === 0.9) wiring = 0.8; else if (wiring === 1.8) wiring = 1.6; }
        else { if (wiring === 0.8) wiring = 0.9; else if (wiring === 1.6) wiring = 1.8; }
        var estimate = parseFloat(r.estimate) || 0;
        var usage    = r.usage || '아파트';
        var region   = r.region || '지방';
        var mu       = settings.mat_unit || 400;

        var sr = settings.surcharge || 1.05;
        var il = settings.inline_m || 10;
        var fr = settings.fire_room || 10;

        var avg  = (maxDist + minDist) / 2;
        var vert = basement * 3;
        var K = calcK(division, usage);
        var L = calcL(division, usage, units);
        var lu = luByRegion(region);

        var calc  = (avg * sr * K + vert + il + fr) * L;
        var labor = calc * lu * wiring;
        var mat   = calc * mu;
        var total = labor + mat;

        var estUnit = (estimate > 0 && units > 0) ? estimate / units : 0;

        /* 1) 노무비 증감률: (신규노무비 - 기존노무비) / 기존노무비 */
        var sLabor = estUnit > 0 ? estUnit * 440 : 0;
        var laborRate = (sLabor > 0 && labor > 0) ? (labor - sLabor) / sLabor : null;

        /* 2) GAP%: (산출계 - 견적수량대당) / 산출계 */
        var gapP = (estUnit > 0 && calc > 0) ? (calc - estUnit) / calc : null;

        /* 3) 총합 증감률: (메인총합 - 서브총합) / 서브총합 */
        var sMat   = estUnit > 0 ? estUnit * 320 : 0;
        var sTotal = sLabor + sMat;
        var totalRate = (sTotal > 0 && total > 0) ? (total - sTotal) / sTotal : null;

        return { laborRate: laborRate, gapP: gapP, totalRate: totalRate };
    }

    /* 데이터 로드 & 테이블 구성 */
    function load() {
        SHV.api.get(API_URL, { todo: 'list' }).then(function (res) {
            if (!res || !res.ok) {
                document.getElementById('wsInfo').textContent = '데이터 로드 실패';
                return;
            }
            var rows = res.data || [];
            if (rows.length === 0) {
                document.getElementById('wsInfo').textContent = '저장된 데이터가 없습니다.';
                renderEmpty('wsTbody', DIVISIONS, 2);
                renderEmpty('wsTbodyGap', DIVISIONS, 2);
                renderEmpty('wsTbodyTotal', DIVISIONS, 2);
                updateHeaders([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
                return;
            }

            var bucketLabor = {}, bucketGap = {}, bucketTotal = {};
            var rangeCounts = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
            var divCounts = {}, divUsageCounts = {};
            var total = 0;
            rows.forEach(function (r) {
                if (!r.site || !r.division || !r.usage) return;
                var units = parseFloat(r.units) || 1;
                var ri = getRangeIdx(units);
                if (ri < 0) return;
                rangeCounts[ri]++;
                var div = r.division;
                var usg = r.usage;
                var reg = r.region || '지방';
                divCounts[div] = (divCounts[div] || 0) + 1;
                divUsageCounts[div + '|' + usg] = (divUsageCounts[div + '|' + usg] || 0) + 1;
                var settings = {
                    surcharge: parseFloat(r.surcharge) || 1.05,
                    inline_m:  parseFloat(r.inline_m) || 10,
                    fire_room: parseFloat(r.fire_room) || 10,
                    mat_unit:  parseFloat(r.mat_unit) || 400
                };
                var m = calcMetrics(r, settings);

                /* 노무비: 구분+용도+대수+지역 */
                if (m.laborRate !== null) {
                    var lk = div + '|' + usg + '|' + ri + '|' + reg;
                    if (!bucketLabor[lk]) bucketLabor[lk] = [];
                    bucketLabor[lk].push(m.laborRate);
                }
                /* GAP·총합: 구분+용도+대수+지역 */
                var dk = div + '|' + usg + '|' + ri + '|' + reg;
                if (m.gapP !== null) {
                    if (!bucketGap[dk]) bucketGap[dk] = [];
                    bucketGap[dk].push(m.gapP);
                }
                if (m.totalRate !== null) {
                    if (!bucketTotal[dk]) bucketTotal[dk] = [];
                    bucketTotal[dk].push(m.totalRate);
                }
                total++;
            });

            document.getElementById('wsInfo').textContent = '총 ' + rows.length + '건 분석 (' + total + '건 유효)';
            renderLabor('wsTbody', bucketLabor, divCounts, divUsageCounts);
            renderLabor('wsTbodyGap', bucketGap, divCounts, divUsageCounts);
            renderLabor('wsTbodyTotal', bucketTotal, divCounts, divUsageCounts);
            updateHeaders(rangeCounts);
        }).catch(function () {
            document.getElementById('wsInfo').textContent = '데이터 로드 오류';
        });
    }

    /* 노무비 전용 — 수도권/지방 서브컬럼 (7구간×2=14열) */
    function renderLabor(tbodyId, bucket, divCounts, divUsageCounts) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        var html = '';

        DIVISIONS.forEach(function (div) {
            var first = true;
            var gc = (divCounts && divCounts[div]) || 0;
            USAGES.forEach(function (usg) {
                var uc = (divUsageCounts && divUsageCounts[div + '|' + usg]) || 0;
                html += '<tr>';
                if (first) {
                    html += '<td class="ws-td-div" rowspan="' + USAGES.length + '">' + div
                         + (gc > 0 ? '<span class="ws-td-cnt">' + gc + '건</span>' : '') + '</td>';
                    first = false;
                }
                html += '<td class="ws-td-usage">' + usg
                     + (uc > 0 ? '<span class="ws-td-cnt">' + uc + '</span>' : '') + '</td>';
                for (var ri = 0; ri < RANGES.length; ri++) {
                    REGIONS.forEach(function (reg) {
                        var key = div + '|' + usg + '|' + ri + '|' + reg;
                        var vals = bucket[key];
                        if (!vals || vals.length === 0) {
                            html += '<td class="ws-td-val ws-val-empty">—</td>';
                        } else {
                            var avg = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
                            var pct = (avg * 100).toFixed(1);
                            var cls = avg > 0 ? 'ws-val-pos' : avg < 0 ? 'ws-val-neg' : 'ws-val-zero';
                            var sign = avg > 0 ? '+' : '';
                            html += '<td class="ws-td-val ' + cls + '" title="' + vals.length + '건 평균">';
                            html += sign + pct + '%';
                            html += '<span class="ws-val-cnt">(' + vals.length + ')</span>';
                            html += '</td>';
                        }
                    });
                }
                html += '</tr>';
            });
        });

        tbody.innerHTML = html;
    }

    function updateHeaders(counts) {
        document.querySelectorAll('.ws-table').forEach(function (table) {
            /* 노무비 테이블: .ws-th-range 가 7개 (수도권/지방 상위) */
            var rangeHeaders = table.querySelectorAll('.ws-th-range');
            if (rangeHeaders.length > 0) {
                rangeHeaders.forEach(function (th, i) {
                    var old = th.querySelector('.ws-th-cnt');
                    if (old) old.remove();
                    var span = document.createElement('span');
                    span.className = 'ws-th-cnt';
                    span.textContent = counts[i] > 0 ? '(' + counts[i] + '건)' : '';
                    th.appendChild(span);
                });
                return;
            }
            /* GAP·총합: 마지막 thead 행의 th */
            var ths = table.querySelectorAll('thead tr:last-child th');
            ths.forEach(function (th, i) {
                var old = th.querySelector('.ws-th-cnt');
                if (old) old.remove();
                var span = document.createElement('span');
                span.className = 'ws-th-cnt';
                span.textContent = counts[i] > 0 ? '(' + counts[i] + '건)' : '';
                th.appendChild(span);
            });
        });
    }

    function renderEmpty(tbodyId, groups, colsPerRange) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        var cols = colsPerRange || 1;
        var html = '';
        groups.forEach(function (grp) {
            var first = true;
            USAGES.forEach(function (usg) {
                html += '<tr>';
                if (first) {
                    html += '<td class="ws-td-div" rowspan="' + USAGES.length + '">' + grp + '</td>';
                    first = false;
                }
                html += '<td class="ws-td-usage">' + usg + '</td>';
                for (var ri = 0; ri < RANGES.length * cols; ri++) {
                    html += '<td class="ws-td-val ws-val-empty">—</td>';
                }
                html += '</tr>';
            });
        });
        tbody.innerHTML = html;
    }

    window.wsRefresh = load;
    window.wsInit = load;
})();
