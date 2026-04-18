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
$_cssV = @filemtime(__DIR__.'/../../../css/v2/pages/wire_stats.css') ?: '1';
$_jsV  = @filemtime(__DIR__.'/../../../js/pages/wire_stats.js') ?: '1';

/* 대수구간 정의 (JS RANGES 와 동기화) */
$ranges = [
    ['5대',  '1~5'],
    ['10대', '6~10'],
    ['15대', '11~15'],
    ['20대', '16~20'],
    ['25대', '21~25'],
    ['30대', '26~30'],
    ['35대', '31~35'],
    ['40대', '36~40'],
    ['45대', '41~45'],
    ['50대', '46~50'],
    ['50대↑','51~'],
];
$rangeCount = count($ranges);
$colTotal   = $rangeCount * 2;
$regRow     = str_repeat('<th class="ws-th-reg">수도권</th><th class="ws-th-reg">지방</th>' . "\n                    ", $rangeCount);
?>
<link rel="stylesheet" href="css/v2/pages/wire_stats.css?v=<?= $_cssV ?>">
<script src="js/pages/wire_stats.js?v=<?= $_jsV ?>" defer></script>

<section data-page="wire_stats" data-title="배관배선 통계">

<div class="page-header">
    <h2 class="page-title">배관배선 통계</h2>
    <p class="page-desc">구분·용도별 대수구간 통계</p>
</div>

<div class="ws-toolbar-top">
    <span class="text-xs text-3" id="wsInfo">데이터 로딩 중...</span>
    <button class="btn btn-outline btn-sm" onclick="wsRefresh()"><i class="fa fa-refresh"></i> 새로고침</button>
</div>

<?php
function wsTableHead(string $title, int $colTotal, array $ranges): string {
    $rangeRow = '';
    foreach ($ranges as $r) {
        $rangeRow .= '<th colspan="2" class="ws-th-range">' . $r[0] . '<br><span class="ws-th-sub">' . $r[1] . '</span></th>' . "\n                    ";
    }
    $regRow = str_repeat('<th class="ws-th-reg">수도권</th><th class="ws-th-reg">지방</th>' . "\n                    ", count($ranges));
    return '<thead>
                <tr>
                    <th rowspan="3" class="ws-th-fixed">구분</th>
                    <th rowspan="3" class="ws-th-fixed ws-th-usage">용도</th>
                    <th colspan="' . $colTotal . '">' . $title . '</th>
                </tr>
                <tr>
                    ' . trim($rangeRow) . '
                </tr>
                <tr>
                    ' . trim($regRow) . '
                </tr>
            </thead>';
}
?>

<!-- ① 노무비 증감률 -->
<div class="card ws-card">
    <div class="ws-card-header">
        <span class="ws-card-title"><i class="fa fa-line-chart"></i> 노무비 증감률</span>
        <span class="ws-card-desc">(신규노무비 - 기존노무비) / 기존노무비</span>
    </div>
    <div class="ws-table-wrap">
        <table class="ws-table ws-table-labor">
            <?= wsTableHead('대수 구간별 노무비 증감률 (%)', $colTotal, $ranges) ?>
            <tbody id="wsTbody"></tbody>
        </table>
    </div>
</div>

<!-- ② GAP % (산출계 vs 견적수량) -->
<div class="card ws-card">
    <div class="ws-card-header">
        <span class="ws-card-title"><i class="fa fa-exchange"></i> GAP % (산출계 vs 견적수량)</span>
        <span class="ws-card-desc">(산출계 - 견적수량대당) / 산출계</span>
    </div>
    <div class="ws-table-wrap">
        <table class="ws-table ws-table-gap">
            <?= wsTableHead('대수 구간별 GAP (%)', $colTotal, $ranges) ?>
            <tbody id="wsTbodyGap"></tbody>
        </table>
    </div>
</div>

<!-- ③ 총합 증감률 (메인총합 vs 기존단가총합) -->
<div class="card ws-card">
    <div class="ws-card-header">
        <span class="ws-card-title"><i class="fa fa-bar-chart"></i> 총합 증감률</span>
        <span class="ws-card-desc">(신규총합 - 기존단가총합) / 기존단가총합 · 기존: 노무440·자재320</span>
    </div>
    <div class="ws-table-wrap">
        <table class="ws-table ws-table-total">
            <?= wsTableHead('대수 구간별 총합 증감률 (%)', $colTotal, $ranges) ?>
            <tbody id="wsTbodyTotal"></tbody>
        </table>
    </div>
</div>

<div class="ws-legend">
    <span class="ws-leg-item"><span class="ws-leg-box ws-leg-pos"></span> 증가 (+)</span>
    <span class="ws-leg-item"><span class="ws-leg-box ws-leg-neg"></span> 감소 (-)</span>
    <span class="ws-leg-item"><span class="ws-leg-box ws-leg-zero"></span> 데이터 없음</span>
</div>

<script>(function _wsi(){ if(typeof wsInit==='function') wsInit(); else setTimeout(_wsi,50); })();</script>
</section>
