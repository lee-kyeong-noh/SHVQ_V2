<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div>';
    exit;
}
$_cssV = @filemtime(__DIR__.'/../../../css/v2/pages/wire_check.css') ?: '1';
$_jsV  = @filemtime(__DIR__.'/../../../js/pages/wire_check.js') ?: '1';
?>
<link rel="stylesheet" href="css/v2/pages/wire_check.css?v=<?= $_cssV ?>">
<script src="js/pages/wire_check.js?v=<?= $_jsV ?>" defer></script>

<section data-page="wire_check" data-title="배관배선 검토">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1">배관배선 검토</h2>
        <p class="text-xs text-3 mt-1 m-0">본설 배관·배선 물량 자동 산출 · 행 클릭 시 입력</p>
    </div>
    <div class="flex items-center gap-2">
        <select id="wcFilterDiv" class="wc-filter-sel" onchange="wcFilter()">
            <option value="">구분 전체</option>
            <option value="NI">NI</option>
            <option value="RM">RM</option>
        </select>
        <div class="wc-search-wrap">
            <i class="fa fa-search wc-search-icon"></i>
            <input id="wcSearch" class="wc-search-input" type="text" placeholder="검색 (현장·용도·총수량·메모)" oninput="wcFilter()">
        </div>
        <button class="btn btn-glass-primary btn-sm" onclick="wcAdd()"><i class="fa fa-plus"></i> 행 추가</button>
        <button class="btn btn-outline btn-sm" onclick="wcExcelUpload()"><i class="fa fa-upload"></i> 엑셀 업로드</button>
        <button class="btn btn-outline btn-sm" onclick="wcCSV()"><i class="fa fa-download"></i> CSV</button>
        <button class="btn btn-ghost btn-sm" onclick="wcReset()"><i class="fa fa-trash-o"></i></button>
    </div>
</div>

<!-- ── 글로벌 고정값 ── -->
<div class="card flex-shrink-0 py-2 px-4">
    <div class="wc-settings">
        <span class="wc-settings-label"><i class="fa fa-cog"></i> 고정값</span>
        <div class="wc-settings-group">
            <div class="wc-settings-item">
                <label>할증율</label>
                <input id="wcSurcharge" type="number" value="1.05" step="0.01" oninput="wcSettingChange()">
            </div>
            <div class="wc-settings-item">
                <label>입선(m)</label>
                <input id="wcInline" type="number" value="10" step="1" oninput="wcSettingChange()">
            </div>
            <div class="wc-settings-item">
                <label>방재실(m)</label>
                <input id="wcFireRoom" type="number" value="10" step="1" oninput="wcSettingChange()">
            </div>
            <div class="wc-settings-item">
                <label>자재단가</label>
                <input id="wcMatUnit" type="number" value="400" step="10" style="width:72px;" oninput="wcSettingChange()">
            </div>
        </div>
        <span class="wc-settings-hint">배선: NI<b>0.9</b> RM<b>0.8</b> | L: RM아파트 31대↑<b>95%</b> | 노무: 수도권<b>440</b> 지방<b>480</b>원/m</span>
    </div>
</div>

<!-- ── 메인 테이블 ── -->
<div class="card card-fill flex flex-col" style="min-height:0;padding:0;">
    <div class="wc-table-wrap">
        <table class="wc-table">
            <colgroup>
                <col style="width:40px">
                <col style="width:96px"><col style="width:64px">
                <col style="width:56px"><col style="width:48px"><col style="width:56px">
                <col style="width:64px"><col style="width:64px"><col style="width:64px">
                <col style="width:48px"><col style="width:52px">
                <col style="width:44px"><col style="width:52px">
                <col style="width:56px">
                <col style="width:76px">
                <col style="width:80px"><col style="width:80px"><col style="width:88px">
                <col style="width:64px"><col style="width:64px"><col style="width:52px">
                <col style="width:80px">
            </colgroup>
            <thead>
                <tr>
                    <th></th>
                    <th class="wc-sortable" data-sort="site">현장번호</th><th class="wc-sortable" data-sort="dtype">도면</th>
                    <th class="wc-sortable" data-sort="region">지역</th><th class="wc-sortable" data-sort="division">구분</th><th class="wc-sortable" data-sort="usage">용도</th>
                    <th class="wc-sortable" data-sort="maxDist">최장(m)</th><th class="wc-sortable" data-sort="minDist">최단(m)</th><th class="wc-th-calc wc-sortable" data-sort="avg">평균</th>
                    <th class="wc-sortable" data-sort="basement">지하층</th><th class="wc-th-calc wc-sortable" data-sort="vert">수직</th>
                    <th class="wc-sortable" data-sort="units">총대수</th><th class="wc-sortable" data-sort="wiring">배선</th>
                    <th class="wc-th-calc">노무단가</th>
                    <th class="wc-th-calc wc-th-primary wc-sortable" data-sort="calc">산출계</th>
                    <th class="wc-th-calc wc-sortable" data-sort="labor">노무비</th><th class="wc-th-calc wc-sortable" data-sort="mat">자재비</th><th class="wc-th-calc wc-th-primary wc-sortable" data-sort="total">총합</th>
                    <th class="wc-th-review wc-sortable" data-sort="estimate">견적수량</th><th class="wc-th-review wc-sortable" data-sort="gap">GAP 수량</th><th class="wc-th-review wc-sortable" data-sort="gapP">GAP %</th>
                    <th>메모</th>
                </tr>
            </thead>
            <tbody id="wcTbody"></tbody>
            <tbody>
                <tr id="wcEmpty">
                    <td colspan="22">
                        <div class="wc-empty">
                            <div class="wc-empty-icon"><i class="fa fa-table"></i></div>
                            <p class="wc-empty-text">'행 추가' 버튼을 눌러 현장을 입력하세요</p>
                            <button class="btn btn-glass-primary btn-sm" onclick="wcAdd()"><i class="fa fa-plus"></i> 행 추가</button>
                        </div>
                    </td>
                </tr>
                <tr id="wcTotalRow" style="display:none;" class="wc-row-total">
                    <td colspan="15" class="text-right pr-3 text-xs text-3">합계</td>
                    <td class="wc-calc"><span id="wcSumLabor">—</span></td>
                    <td class="wc-calc"><span id="wcSumMat">—</span></td>
                    <td class="wc-calc wc-calc-primary"><span id="wcSumTotal">—</span></td>
                    <td colspan="4"></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="wc-footer-bar">
        <span id="wcRowCount" class="wc-row-count">총 <b>0</b>건</span>
    </div>
</div>

</section>

<script>(function _wci(){ if(typeof wcInit==='function') wcInit(); else setTimeout(_wci,50); })();</script>
