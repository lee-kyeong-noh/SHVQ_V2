/* ══════════════════════════════════════
   PJT 모듈 (js/pages/pjt.js)
   현장 상세 > PJT 탭 전용
   SHV.pjt.init(containerId, siteIdx)
   ══════════════════════════════════════ */
(function(){
'use strict';

if(!window.SHV) window.SHV={};

var _containerId, _siteIdx;
var _subTab = 'stat'; /* stat | hogi | item | legacy */
var _loaded = {};

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)||n===0?'':n.toLocaleString(); }
function statusBadge(s){ return s?'<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>':''; }

function api(todo, params){
    var p=Object.assign({todo:todo, site_idx:_siteIdx}, params||{});
    return SHV.api.get('dist_process/saas/Project.php', p);
}

function apiPost(todo, params){
    var p=Object.assign({todo:todo, site_idx:_siteIdx}, params||{});
    return SHV.api.post('dist_process/saas/Project.php', p);
}

/* ══════════════════════════
   초기화
   ══════════════════════════ */
SHV.pjt = {
    init: function(containerId, siteIdx){
        _containerId = containerId;
        _siteIdx = siteIdx;
        _loaded = {};
        renderShell();
        switchSub('stat');
    }
};

/* ── 뼈대 렌더링 ── */
function renderShell(){
    var c=$(_containerId); if(!c) return;
    c.innerHTML=''
        +'<div class="pjt-sub-bar" id="pjtSubBar">'
        +'<button class="pjt-sub-btn pjt-sub-active" data-sub="stat" onclick="SHV.pjt.switchSub(\'stat\')">PJT 현황</button>'
        +'<button class="pjt-sub-btn" data-sub="hogi" onclick="SHV.pjt.switchSub(\'hogi\')">호기 그룹</button>'
        +'<button class="pjt-sub-btn" data-sub="item" onclick="SHV.pjt.switchSub(\'item\')">PJT 품목</button>'
        +'<button class="pjt-sub-btn" data-sub="legacy" onclick="SHV.pjt.switchSub(\'legacy\')">기존 프로젝트</button>'
        +'</div>'
        +'<div id="pjtSubStat" class="pjt-sub-body"><div class="text-center p-6 text-3">로딩 중...</div></div>'
        +'<div id="pjtSubHogi" class="pjt-sub-body hidden"><div class="text-center p-6 text-3">로딩 중...</div></div>'
        +'<div id="pjtSubItem" class="pjt-sub-body hidden"><div class="text-center p-6 text-3">로딩 중...</div></div>'
        +'<div id="pjtSubLegacy" class="pjt-sub-body hidden"><div class="text-center p-6 text-3">로딩 중...</div></div>';
}

/* ── 서브탭 전환 ── */
function switchSub(key){
    _subTab = key;
    var bar=$('pjtSubBar');
    if(bar) bar.querySelectorAll('.pjt-sub-btn').forEach(function(b){
        b.classList.toggle('pjt-sub-active', b.dataset.sub===key);
    });
    ['stat','hogi','item','legacy'].forEach(function(k){
        var el=$('pjtSub'+k.charAt(0).toUpperCase()+k.slice(1));
        if(el) el.classList.toggle('hidden', k!==key);
    });
    if(!_loaded[key]){
        _loaded[key]=true;
        if(key==='stat')   loadStat();
        if(key==='hogi')   loadHogi();
        if(key==='item')   loadItems();
        if(key==='legacy') loadLegacy();
    }
}
SHV.pjt.switchSub = switchSub;

/* ══════════════════════════
   PJT 현황 서브탭
   ══════════════════════════ */
function loadStat(){
    api('pjt_stat_list',{limit:200})
        .then(function(res){
            var body=$('pjtSubStat'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-6 text-3">PJT 현황을 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-th-list"></i></div><p class="text-3 mt-2">등록된 PJT가 없습니다</p><button class="btn btn-glass-primary btn-sm mt-3" onclick="SHV.pjt.addPlan()"><i class="fa fa-plus mr-1"></i>PJT 예정 등록</button></div>'; return; }
            var h='<div class="flex justify-end mb-2"><button class="btn btn-glass-primary btn-sm" onclick="SHV.pjt.addPlan()"><i class="fa fa-plus mr-1"></i>PJT 예정 등록</button></div>';
            h+='<table class="tbl" id="pjtStatTbl"><thead><tr><th class="th-center">No</th><th>PJT명</th><th>속성</th><th>단계</th><th class="text-right">수량</th><th>상태</th><th>등록일</th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr class="cursor-pointer" onclick="SHV.pjt.viewSurvey('+r.idx+')">'
                    +'<td class="td-no">'+(i+1)+'</td>'
                    +'<td><b class="text-1">'+escH(r.pjt_name||r.name||'')+'</b></td>'
                    +'<td class="text-3">'+escH(r.attribute||r.pjt_attr||'')+'</td>'
                    +'<td>'+statusBadge(r.phase||r.step||'')+'</td>'
                    +'<td class="text-right">'+fmtNum(r.qty||r.quantity)+'</td>'
                    +'<td>'+statusBadge(r.status||'')+'</td>'
                    +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td>'
                    +'</tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('pjtSubStat').innerHTML='<div class="text-center p-6 text-3">네트워크 오류</div>'; });
}

/* ══════════════════════════
   호기 그룹 서브탭
   ══════════════════════════ */
function loadHogi(){
    api('hogi_list',{limit:200})
        .then(function(res){
            var body=$('pjtSubHogi'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-6 text-3">호기 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-cubes"></i></div><p class="text-3 mt-2">등록된 호기가 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>호기명</th><th>속성</th><th>옵션값</th><th class="text-right">수량</th><th>상태</th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr class="cursor-pointer" onclick="SHV.pjt.viewHogi('+r.idx+')">'
                    +'<td class="td-no">'+(i+1)+'</td>'
                    +'<td><b class="text-1">'+escH(r.hogi_name||r.name||'')+'</b></td>'
                    +'<td class="text-3">'+escH(r.pjt_attr||r.attribute||'')+'</td>'
                    +'<td class="text-3">'+escH(r.option_val||'')+'</td>'
                    +'<td class="text-right">'+fmtNum(r.qty||r.quantity)+'</td>'
                    +'<td>'+statusBadge(r.status||'')+'</td>'
                    +'</tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('pjtSubHogi').innerHTML='<div class="text-center p-6 text-3">네트워크 오류</div>'; });
}

/* ══════════════════════════
   PJT 품목 서브탭
   ══════════════════════════ */
function loadItems(){
    api('pjt_item_list',{limit:500})
        .then(function(res){
            var body=$('pjtSubItem'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-6 text-3">PJT 품목을 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-cube"></i></div><p class="text-3 mt-2">등록된 PJT 품목이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>품목명</th><th>규격</th><th>단위</th><th class="text-right">수량</th><th class="text-right">단가</th><th>속성</th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr><td class="td-no">'+(i+1)+'</td>'
                    +'<td><b class="text-1">'+escH(r.item_name||r.name||'')+'</b></td>'
                    +'<td class="text-3">'+escH(r.standard||r.spec||'')+'</td>'
                    +'<td class="text-3">'+escH(r.unit||'')+'</td>'
                    +'<td class="text-right">'+fmtNum(r.qty||r.quantity)+'</td>'
                    +'<td class="text-right">'+fmtNum(r.price||r.sale_price)+'</td>'
                    +'<td class="text-3">'+escH(r.attribute||'')+'</td>'
                    +'</tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('pjtSubItem').innerHTML='<div class="text-center p-6 text-3">네트워크 오류</div>'; });
}

/* ══════════════════════════
   기존 프로젝트 서브탭 (레거시)
   ══════════════════════════ */
function loadLegacy(){
    api('list',{limit:200})
        .then(function(res){
            var body=$('pjtSubLegacy'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-6 text-3">기존 프로젝트를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-folder-open-o"></i></div><p class="text-3 mt-2">기존 프로젝트가 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>프로젝트명</th><th>상태</th><th>등록일</th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr><td class="td-no">'+(i+1)+'</td>'
                    +'<td><b class="text-1">'+escH(r.project_name||r.name||'')+'</b></td>'
                    +'<td>'+statusBadge(r.status||'')+'</td>'
                    +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td>'
                    +'</tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('pjtSubLegacy').innerHTML='<div class="text-center p-6 text-3">네트워크 오류</div>'; });
}

/* ══════════════════════════
   모달 열기
   ══════════════════════════ */
SHV.pjt.addPlan = function(){
    SHV.modal.open('views/saas/fms/pjt_plan_add.php?site_idx='+_siteIdx,'PJT 예정 등록','lg');
    SHV.modal.onClose(function(){ _loaded.stat=false; loadStat(); });
};

SHV.pjt.viewHogi = function(idx){
    SHV.modal.open('views/saas/fms/pjt_hogi_view.php?idx='+idx+'&site_idx='+_siteIdx,'호기 상세','lg');
};

SHV.pjt.viewSurvey = function(idx){
    SHV.modal.open('views/saas/fms/pjt_survey_view.php?idx='+idx+'&site_idx='+_siteIdx,'PJT 단계 상세','xl');
};

})();
