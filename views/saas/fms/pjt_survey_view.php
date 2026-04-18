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

$pjtIdx  = (int)($_GET['idx'] ?? 0);
$siteIdx = (int)($_GET['site_idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<link rel="stylesheet" href="css/v2/detail-view.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/detail-view.css')?:'1' ?>">

<div id="psBody" class="p-4">
    <div class="text-center p-8 text-3">로딩 중...</div>
</div>

<script>
(function(){
'use strict';

var _pjtIdx  = <?= $pjtIdx ?>;
var _siteIdx = <?= $siteIdx ?>;
var _data    = null;

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)||n===0?'':n.toLocaleString(); }
function fmtMoney(v){ var n=parseInt(v,10); return isNaN(n)?'-':n.toLocaleString()+'원'; }
function statusBadge(s){ return s?'<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>':''; }

var PHASE_ICONS = {
    '실사':'fa-search','견적':'fa-file-text-o','계약':'fa-handshake-o',
    '착공준비':'fa-wrench','시공':'fa-cogs','청구':'fa-money','수금':'fa-krw'
};

function loadDetail(){
    SHV.api.get('dist_process/saas/Project.php',{todo:'pjt_survey_detail',idx:_pjtIdx,site_idx:_siteIdx})
        .then(function(res){
            var body=$('psBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-danger">PJT 상세를 불러올 수 없습니다.</div>'; return; }
            _data=res.data.pjt||res.data||{};
            render(_data, res.data);
        })
        .catch(function(){ $('psBody').innerHTML='<div class="text-center p-8 text-danger">네트워크 오류</div>'; });
}

function render(d, full){
    var body=$('psBody'); if(!body) return;
    var h='';

    /* ── 헤더 ── */
    h+='<div class="dv-header mb-3">'
        +'<div class="flex items-center gap-3">'
        +'<i class="fa fa-th-list text-accent"></i>'
        +'<span class="dv-title">'+escH(d.pjt_name||d.name||'PJT 상세')+'</span>'
        +statusBadge(d.status||'')
        +'</div>'
        +'<div class="dv-sub-pills mt-2">'
        +(d.pjt_type?'<span class="dv-pill">'+escH(d.pjt_type)+'</span>':'')
        +(d.pjt_attr||d.attribute?'<span class="dv-pill">'+escH(d.pjt_attr||d.attribute)+'</span>':'')
        +(d.qty?'<span class="dv-pill">수량 '+escH(fmtNum(d.qty))+'</span>':'')
        +'</div>'
        +'</div>';

    /* ── 기본정보 ── */
    h+='<div class="dv-info-body">';
    h+='<div class="dv-info-section"><div class="dv-section-title">기본 정보</div><div class="dv-row-grid">';
    var infoFields=[
        ['PJT명', d.pjt_name||d.name||''],
        ['유형', d.pjt_type||''],
        ['속성', d.pjt_attr||d.attribute||''],
        ['수량', fmtNum(d.qty||d.quantity)],
        ['시작일', fmtDate(d.start_date)],
        ['종료일', fmtDate(d.end_date)],
        ['담당자', d.employee_name||''],
        ['등록일', fmtDate(d.created_at||d.regdate)]
    ];
    infoFields.forEach(function(f){
        if(!f[1]) return;
        h+='<div class="dv-row-item"><span class="dv-row-label">'+escH(f[0])+'</span><span class="dv-row-value">'+escH(f[1])+'</span></div>';
    });
    h+='</div></div>';

    /* ── 단계 타임라인 (7단계) ── */
    var phases=full.phases||d.phases||[];
    if(phases.length){
        h+='<div class="dv-info-section"><div class="dv-section-title">단계 진행</div>';
        h+='<div class="ps-timeline">';
        phases.forEach(function(p,i){
            var icon=PHASE_ICONS[p.phase_name||p.name]||'fa-circle';
            var done=(p.status==='완료'||p.status==='done'||p.is_completed);
            var current=(p.status==='진행'||p.status==='active'||p.is_current);
            var cls=done?'ps-step-done':(current?'ps-step-active':'ps-step-pending');
            h+='<div class="ps-step '+cls+'">'
                +'<div class="ps-step-icon"><i class="fa '+icon+'"></i></div>'
                +'<div class="ps-step-body">'
                +'<div class="text-sm font-semibold text-1">'+escH(p.phase_name||p.name||'')+'</div>'
                +'<div class="flex gap-2 mt-1 text-xs text-3">'
                +statusBadge(p.status||'')
                +(p.employee_name?' <span><i class="fa fa-user mr-1"></i>'+escH(p.employee_name)+'</span>':'')
                +(p.deadline?' <span>기한: '+fmtDate(p.deadline)+'</span>':'')
                +'</div>'
                +(p.completed_at?'<div class="text-xs text-3 mt-1">완료: '+fmtDate(p.completed_at)+'</div>':'')
                +'</div>'
                +'</div>';
        });
        h+='</div></div>';
    }

    /* ── 연결 품목 ── */
    var items=full.items||d.items||[];
    if(items.length){
        h+='<div class="dv-info-section"><div class="dv-section-title">연결 품목 ('+items.length+'건)</div>';
        h+='<table class="tbl"><thead><tr><th class="th-center">No</th><th>품목명</th><th>규격</th><th>단위</th><th class="text-right">수량</th><th class="text-right">단가</th><th class="text-right">합계</th></tr></thead><tbody>';
        var totalAmt=0;
        items.forEach(function(it,i){
            var line=(parseInt(it.qty||0,10))*(parseInt(it.price||it.sale_price||0,10));
            totalAmt+=line;
            h+='<tr><td class="td-no">'+(i+1)+'</td><td>'+escH(it.item_name||it.name||'')+'</td><td class="text-3">'+escH(it.standard||it.spec||'')+'</td><td class="text-3">'+escH(it.unit||'')+'</td><td class="text-right">'+fmtNum(it.qty)+'</td><td class="text-right">'+fmtNum(it.price||it.sale_price)+'</td><td class="text-right font-semibold">'+fmtNum(line)+'</td></tr>';
        });
        h+='</tbody></table>';
        h+='<div class="ea-total-bar mt-2"><span class="text-sm text-3">품목 합계</span><span class="text-lg font-bold text-accent">'+fmtMoney(totalAmt)+'</span></div>';
        h+='</div>';
    }

    h+='</div>';
    body.innerHTML=h;
}

loadDetail();
})();
</script>
