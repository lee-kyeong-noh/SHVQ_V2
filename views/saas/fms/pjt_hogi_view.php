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

$hogiIdx = (int)($_GET['idx'] ?? 0);
$siteIdx = (int)($_GET['site_idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<link rel="stylesheet" href="css/v2/detail-view.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/detail-view.css')?:'1' ?>">

<div id="phBody" class="p-4">
    <div class="text-center p-8 text-3">로딩 중...</div>
</div>

<script>
(function(){
'use strict';

var _hogiIdx = <?= $hogiIdx ?>;
var _siteIdx = <?= $siteIdx ?>;

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)||n===0?'':n.toLocaleString(); }
function statusBadge(s){ return s?'<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>':''; }

function loadDetail(){
    SHV.api.get('dist_process/saas/Project.php',{todo:'hogi_detail',idx:_hogiIdx,site_idx:_siteIdx})
        .then(function(res){
            var body=$('phBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-danger">호기 정보를 불러올 수 없습니다.</div>'; return; }
            var d=res.data.hogi||res.data||{};
            renderHogi(d, res.data);
        })
        .catch(function(){ $('phBody').innerHTML='<div class="text-center p-8 text-danger">네트워크 오류</div>'; });
}

function renderHogi(d, full){
    var body=$('phBody'); if(!body) return;

    /* 헤더 */
    var h='<div class="dv-header mb-3">'
        +'<div class="flex items-center gap-3">'
        +'<i class="fa fa-cube text-accent"></i>'
        +'<span class="dv-title">'+escH(d.hogi_name||d.name||'호기 상세')+'</span>'
        +statusBadge(d.status||'')
        +'</div>'
        +'<div class="dv-sub-pills mt-2">'
        +(d.pjt_attr?'<span class="dv-pill">'+escH(d.pjt_attr)+'</span>':'')
        +(d.option_val?'<span class="dv-pill">'+escH(d.option_val)+'</span>':'')
        +(d.char_match?'<span class="dv-pill">'+escH(d.char_match)+'</span>':'')
        +'</div>'
        +'</div>';

    /* 기본정보 */
    h+='<div class="dv-info-body">';
    h+='<div class="dv-info-section"><div class="dv-section-title">기본 정보</div><div class="dv-row-grid">';
    var fields=[
        ['호기명', d.hogi_name||d.name||''],
        ['속성', d.pjt_attr||d.attribute||''],
        ['옵션값', d.option_val||''],
        ['수량', fmtNum(d.qty||d.quantity)],
        ['상태', d.status||''],
        ['등록일', fmtDate(d.created_at||d.regdate)]
    ];
    fields.forEach(function(f){
        if(!f[1]) return;
        h+='<div class="dv-row-item"><span class="dv-row-label">'+escH(f[0])+'</span><span class="dv-row-value">'+escH(f[1])+'</span></div>';
    });
    h+='</div></div>';

    /* 연결 품목 */
    var items=full.items||d.items||[];
    if(items.length){
        h+='<div class="dv-info-section"><div class="dv-section-title">연결 품목 ('+items.length+'건)</div>';
        h+='<table class="tbl"><thead><tr><th class="th-center">No</th><th>품목명</th><th>규격</th><th class="text-right">수량</th><th class="text-right">단가</th></tr></thead><tbody>';
        items.forEach(function(it,i){
            h+='<tr><td class="td-no">'+(i+1)+'</td><td>'+escH(it.item_name||it.name||'')+'</td><td class="text-3">'+escH(it.standard||it.spec||'')+'</td><td class="text-right">'+fmtNum(it.qty||it.quantity)+'</td><td class="text-right">'+fmtNum(it.price||it.sale_price)+'</td></tr>';
        });
        h+='</tbody></table></div>';
    }

    /* 단계 이력 */
    var phases=full.phases||d.phases||[];
    if(phases.length){
        h+='<div class="dv-info-section"><div class="dv-section-title">단계 이력</div>';
        h+='<table class="tbl"><thead><tr><th class="th-center">No</th><th>단계</th><th>상태</th><th>담당자</th><th>날짜</th></tr></thead><tbody>';
        phases.forEach(function(p,i){
            h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(p.phase_name||p.step||'')+'</b></td><td>'+statusBadge(p.status||'')+'</td><td class="text-3">'+escH(p.employee_name||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(p.completed_at||p.date||p.created_at)+'</td></tr>';
        });
        h+='</tbody></table></div>';
    }

    h+='</div>';
    body.innerHTML=h;
}

loadDetail();
})();
</script>
