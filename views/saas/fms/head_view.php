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

$headIdx = (int)($_GET['head_idx'] ?? 0);
if ($headIdx <= 0) {
    echo '<div class="empty-state"><p class="empty-message">잘못된 접근입니다.</p></div>';
    exit;
}
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<link rel="stylesheet" href="css/v2/detail-view.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/detail-view.css')?:'1' ?>">
<section data-page="head_view" data-title="본사 상세">

<!-- ── 헤더 카드 ── -->
<div class="dv-header" id="hvHeader">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="btn btn-ghost btn-sm" onclick="hvBack()" title="목록으로"><i class="fa fa-arrow-left"></i></button>
            <i class="fa fa-building text-accent"></i>
            <span class="dv-title" id="hvTitle">로딩 중...</span>
            <span id="hvBadges"></span>
        </div>
        <div class="flex items-center gap-2">
            <button class="btn btn-outline btn-sm" onclick="hvCopyLink()"><i class="fa fa-link mr-1"></i>링크 복사</button>
            <button class="btn btn-outline btn-sm" onclick="hvSettings()"><i class="fa fa-cog mr-1"></i>설정</button>
            <button class="btn btn-glass-primary btn-sm" onclick="hvEdit()"><i class="fa fa-pencil mr-1"></i>수정</button>
            <button class="btn btn-ghost btn-sm text-danger" onclick="hvDelete()"><i class="fa fa-trash"></i></button>
        </div>
    </div>
    <div class="dv-sub-pills" id="hvPills"></div>
</div>

<!-- ── 요약 카드 ── -->
<div class="dv-summary" id="hvSummary">
    <div class="dv-sum-card"><div class="dv-sum-label">연결사업장</div><div class="dv-sum-value" id="hvSumBranch">-</div><div class="dv-sum-sub" id="hvSumBranchSub">로딩 중</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">예정사업장</div><div class="dv-sum-value" id="hvSumPlanned">-</div><div class="dv-sum-sub" id="hvSumPlannedSub">로딩 중</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">전체 현장</div><div class="dv-sum-value" id="hvSumSite">-</div><div class="dv-sum-sub">사업장 소속 현장 합계</div></div>
</div>

<!-- ── 탭 ── -->
<div class="card flex-shrink-0">
    <div class="tab-bar" id="hvTabBar">
        <button class="tab-item tab-active" data-tab="hvTabInfo" onclick="hvTabSwitch(this)"><i class="fa fa-info-circle mr-1"></i>기본정보</button>
        <button class="tab-item" data-tab="hvTabBranch" onclick="hvTabSwitch(this)">
            <i class="fa fa-building-o mr-1"></i>연결사업장 <span class="tab-badge" id="hvBranchCnt">0</span>
        </button>
        <button class="tab-item" data-tab="hvTabPlanned" onclick="hvTabSwitch(this)">
            <i class="fa fa-clock-o mr-1"></i>예정사업장 <span class="tab-badge" id="hvPlannedCnt">0</span>
        </button>
        <button class="tab-item" data-tab="hvTabTree" onclick="hvTabSwitch(this)"><i class="fa fa-sitemap mr-1"></i>사업장현황</button>
        <button class="tab-item" data-tab="hvTabOrg" onclick="hvTabSwitch(this)"><i class="fa fa-share-alt mr-1"></i>조직도</button>
        <button class="tab-item" data-tab="hvTabMemo" onclick="hvTabSwitch(this)"><i class="fa fa-bookmark mr-1"></i>특기사항</button>
    </div>
</div>

<!-- ── 기본정보 탭 ── -->
<div class="card card-fill" id="hvTabInfo">
    <div class="dv-info-body" id="hvInfoBody">
        <div class="text-center p-8 text-3">로딩 중...</div>
    </div>
</div>

<!-- ── 연결사업장 탭 ── -->
<div class="card card-fill hidden" id="hvTabBranch">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="hvBranchSearch" class="form-input flex-1" placeholder="사업장명, 사업자번호, 담당자, 주소 검색..."
            oninput="hvFilterTable('hvBranchTbl',this.value)">
        <div class="flex items-center gap-2 ml-2">
            <span class="text-xs text-3" id="hvBranchInfo"></span>
            <button class="btn btn-glass-primary btn-sm" onclick="hvAddBranch()"><i class="fa fa-plus mr-1"></i>사업장 추가</button>
        </div>
    </div>
    <div id="hvBranchBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 예정사업장 탭 ── -->
<div class="card card-fill hidden" id="hvTabPlanned">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="hvPlannedSearch" class="form-input flex-1" placeholder="사업장명, 사업자번호, 담당자 검색..."
            oninput="hvFilterTable('hvPlannedTbl',this.value)">
        <span class="text-xs text-3 ml-2" id="hvPlannedInfo"></span>
    </div>
    <div id="hvPlannedBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 사업장현황 탭 (트리 다이어그램) ── -->
<div class="card card-fill hidden" id="hvTabTree">
    <div id="hvTreeBody" class="p-4"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 조직도 탭 (4서브탭) ── -->
<div class="card card-fill hidden" id="hvTabOrg">
    <div id="hvOrgBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 특기사항 탭 ── -->
<div class="card card-fill hidden" id="hvTabMemo">
    <div class="text-center p-8 text-3">
        <i class="fa fa-bookmark text-4xl opacity-30"></i>
        <p class="mt-2">특기사항 없음</p>
    </div>
</div>

<script>
(function(){
'use strict';

var _headIdx = <?= $headIdx ?>;
var _data = null;
var _tabKey = 'hvTab_'+_headIdx;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtCorpNo(s){ return s?String(s).replace(/(\d{6})(\d{7})/, '$1-$2'):''; }
function fmtBizNo(s){ return s?String(s).replace(/(\d{3})(\d{2})(\d{5})/, '$1-$2-$3'):''; }
function stripDash(s){ return String(s||'').replace(/-/g,''); }
function fmtPhone(s){
    var n=String(s||'').replace(/[^0-9]/g,'');
    if(n.length===11) return n.replace(/(\d{3})(\d{4})(\d{4})/,'$1-$2-$3');
    if(n.length===10) return n.replace(/(\d{3})(\d{3})(\d{4})/,'$1-$2-$3');
    if(n.length===9)  return n.replace(/(\d{2})(\d{3})(\d{4})/,'$1-$2-$3');
    return s;
}
function statusBadge(s){
    if(!s) return '';
    return '<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>';
}

/* ══════════════════════════════════════
   인라인 편집 시스템
   ══════════════════════════════════════ */
var _editCfg = {
    business_type:        {type:'text'},
    business_class:       {type:'text'},
    email:                {type:'text'},
    cooperation_contract: {type:'select', options:['','협력','계약','기타']},
    group_name:           {type:'text'},
    identity_number:      {type:'text', format:fmtCorpNo, unformat:stripDash, mono:true},
    card_number:          {type:'text', format:fmtBizNo, unformat:stripDash, mono:true},
    main_business:        {type:'textarea'},
    memo:                 {type:'textarea'},
    name:                 {type:'text'},
    ceo:                  {type:'text'},
    tel:                  {type:'text', format:fmtPhone},
    head_type:            {type:'select', options:['법인','개인']},
    head_structure:       {type:'text'},
    contract_type:        {type:'text'},
    employee_name:        {type:'search', apiField:'employee_idx'}
};

function hvInline(el){
    if(el.querySelector('.dv-edit-input,.dv-edit-select')) return;
    var field = el.dataset.field;
    var cfg = _editCfg[field];
    if(!cfg) return;

    var rawVal = _data[field]||'';
    var origHtml = el.innerHTML;
    var input;

    if(cfg.type==='select'){
        input = document.createElement('select');
        input.className = 'dv-edit-select';
        (cfg.options||[]).forEach(function(o){
            var opt=document.createElement('option');
            opt.value=o; opt.textContent=o||'(없음)';
            if(o===rawVal) opt.selected=true;
            input.appendChild(opt);
        });
    } else if(cfg.type==='textarea'){
        input = document.createElement('textarea');
        input.className = 'dv-edit-input dv-edit-textarea';
        input.value = rawVal;
        input.rows = 2;
    } else if(cfg.type==='search'){
        input = document.createElement('input');
        input.type = 'text';
        input.className = 'dv-edit-input';
        input.value = rawVal;
        input.placeholder = '이름 검색...';
        /* 담당자 검색 드롭다운 */
        var dd = document.createElement('div');
        dd.className = 'dv-search-dropdown hidden';
        var timer = null;
        input.addEventListener('input', function(){
            clearTimeout(timer);
            var q = input.value.trim();
            if(q.length<1){ dd.classList.add('hidden'); return; }
            timer = setTimeout(function(){
                SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'employee_search',q:q,head_idx:_headIdx})
                    .then(function(res){
                        if(!res.ok||!res.data||!res.data.length){ dd.classList.add('hidden'); return; }
                        dd.innerHTML='';
                        res.data.forEach(function(emp){
                            var item=document.createElement('div');
                            item.className='dv-search-item';
                            item.textContent=emp.name+(emp.position?' ('+emp.position+')':'');
                            item.addEventListener('mousedown',function(e){
                                e.preventDefault();
                                input.value=emp.name;
                                input._empIdx=emp.idx;
                                dd.classList.add('hidden');
                                input.blur();
                            });
                            dd.appendChild(item);
                        });
                        dd.classList.remove('hidden');
                    });
            },300);
        });
        el.classList.add('dv-edit-search-wrap');
        setTimeout(function(){ el.appendChild(dd); },0);
    } else {
        input = document.createElement('input');
        input.type = 'text';
        input.className = 'dv-edit-input'+(cfg.mono?' dv-edit-mono':'');
        input.value = rawVal;
    }

    el.innerHTML = '';
    el.appendChild(input);
    input.focus();
    if(input.select) input.select();

    var saving = false;

    function save(){
        if(saving) return;
        var newVal = input.value.trim();
        var apiVal = cfg.unformat ? cfg.unformat(newVal) : newVal;
        var oldRaw = cfg.unformat ? cfg.unformat(rawVal) : rawVal;
        if(apiVal===oldRaw){ cancel(); return; }
        saving = true;
        el.classList.add('dv-saving');

        var postData = {todo:'update', idx:_headIdx};
        if(cfg.type==='search' && input._empIdx){
            postData[cfg.apiField||field] = input._empIdx;
            postData.employee_name = newVal;
        } else {
            postData[field] = apiVal;
        }

        SHV.api.post('dist_process/saas/HeadOffice.php', postData)
            .then(function(res){
                el.classList.remove('dv-saving');
                if(res.ok){
                    _data[field] = cfg.type==='search'?newVal:apiVal;
                    if(res.data&&res.data.item) Object.assign(_data, res.data.item);
                    var dispVal = cfg.format ? cfg.format(apiVal) : (cfg.type==='search'?newVal:apiVal);
                    el.innerHTML = dispVal ? escH(dispVal) : '<span class="text-3">-</span>';
                    el.classList.add('dv-saved');
                    setTimeout(function(){ el.classList.remove('dv-saved'); },800);
                    syncHeader();
                } else {
                    el.innerHTML = origHtml;
                    if(SHV.toast) SHV.toast.error(res.message||'수정 실패');
                }
                saving=false;
            })
            .catch(function(){
                el.classList.remove('dv-saving');
                el.innerHTML = origHtml;
                if(SHV.toast) SHV.toast.error('네트워크 오류');
                saving=false;
            });
    }

    function cancel(){ el.innerHTML = origHtml; }

    input.addEventListener('keydown',function(e){
        if(e.key==='Enter'&&cfg.type!=='textarea'){ e.preventDefault(); save(); }
        if(e.key==='Enter'&&cfg.type==='textarea'&&(e.ctrlKey||e.metaKey)){ e.preventDefault(); save(); }
        if(e.key==='Escape'){ e.preventDefault(); cancel(); }
    });
    input.addEventListener('blur',function(){
        if(saving) return;
        /* 검색 드롭다운 mousedown 처리 여유 시간 */
        setTimeout(function(){ if(!saving) save(); }, cfg.type==='search'?200:50);
    });
}

function syncHeader(){ renderHeader(_data); }

/* 이벤트 위임: 인라인 편집 클릭 */
document.addEventListener('click',function(e){
    var el=e.target.closest('.dv-editable[data-field]');
    if(el&&el.closest('[data-page="head_view"]')){
        e.preventDefault(); e.stopPropagation();
        hvInline(el);
    }
});

/* ── 탭 전환 + sessionStorage ── */
var _tabs = ['hvTabInfo','hvTabBranch','hvTabPlanned','hvTabTree','hvTabOrg','hvTabMemo'];

window.hvTabSwitch = function(btn){
    document.querySelectorAll('#hvTabBar .tab-item').forEach(function(t){ t.classList.remove('tab-active'); });
    btn.classList.add('tab-active');
    var target = btn.dataset.tab;
    _tabs.forEach(function(id){ var el=$(id); if(el) el.classList.toggle('hidden', id!==target); });
    sessionStorage.setItem(_tabKey, target);
    if(target==='hvTabBranch'||target==='hvTabPlanned'||target==='hvTabTree') loadBranches();
    if(target==='hvTabOrg') initOrgChart();
};

function restoreTab(){
    var saved = sessionStorage.getItem(_tabKey);
    if(!saved) return;
    var btns = document.querySelectorAll('#hvTabBar .tab-item');
    for(var i=0;i<btns.length;i++){ if(btns[i].dataset.tab===saved){ hvTabSwitch(btns[i]); break; } }
}

/* ── 본사 상세 로드 ── */
function loadDetail(){
    SHV.api.get('dist_process/saas/HeadOffice.php', {todo:'detail', idx:_headIdx})
        .then(function(res){
            if(!res.ok){ if(SHV.toast) SHV.toast.error(res.message||'본사 조회 실패'); return; }
            _data = res.data;
            renderHeader(_data);
            renderInfo(_data);
            restoreTab();
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 헤더 렌더링 (인라인 편집 지원) ── */
function renderHeader(d){
    var title=$('hvTitle');
    if(title){
        title.innerHTML='<span class="dv-editable" data-field="name">'+escH(d.name||'본사 상세')+'</span>';
    }

    var badges='';
    if(d.head_type) badges+='<span class="dv-pill dv-editable" data-field="head_type">'+escH(d.head_type)+'</span>';
    if(d.head_structure) badges+='<span class="dv-pill dv-editable" data-field="head_structure">'+escH(d.head_structure)+'</span>';
    if(d.contract_type) badges+='<span class="dv-pill dv-editable" data-field="contract_type">'+escH(d.contract_type)+'</span>';
    var badgeEl=$('hvBadges'); if(badgeEl) badgeEl.innerHTML=badges;

    var pills='';
    if(d.head_number) pills+='<span class="dv-pill"><i class="fa fa-barcode mr-1 text-3"></i>'+escH(d.head_number)+'</span>';
    if(d.ceo) pills+='<span class="dv-pill"><i class="fa fa-user mr-1 text-3"></i><span class="dv-editable" data-field="ceo">'+escH(d.ceo)+'</span></span>';
    if(d.tel) pills+='<span class="dv-pill"><i class="fa fa-phone mr-1 text-3"></i><span class="dv-editable" data-field="tel">'+escH(d.tel)+'</span></span>';
    if(d.employee_name) pills+='<span class="dv-pill"><i class="fa fa-briefcase mr-1 text-3"></i>'+escH(d.employee_name)+'</span>';
    var pillEl=$('hvPills'); if(pillEl) pillEl.innerHTML=pills;
}

/* ── 기본정보 렌더링 (그룹화: 주소 → 회사정보 → 관리정보) ── */
function renderInfo(d){
    var empty = '<span class="text-3">-</span>';
    var identityLabel = (d.head_type==='개인') ? '생년월일' : '법인등록번호';
    var html = '';

    /* ── 주소 (라인 섹션 — 클릭 시 인라인 주소 편집) ── */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-addr-compact" id="hvAddrRow">';
    html+='<i class="fa fa-map-marker text-accent"></i>';
    html+='<span class="dv-addr-text dv-editable" onclick="hvAddrEdit()">';
    if(d.zipcode) html+='<span class="dv-zip">'+escH(d.zipcode)+'</span> ';
    html+=escH(d.address||'-');
    if(d.address_detail) html+=' '+escH(d.address_detail);
    html+='</span>';
    if(d.address){
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="event.stopPropagation();hvShowMap(\''+escH(d.address||'').replace(/'/g,"\\'")+'\')" title="지도"><i class="fa fa-map-marker mr-1"></i>지도</button>';
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="event.stopPropagation();navigator.clipboard.writeText(\''+escH(d.address||'').replace(/'/g,"\\'")+'\').then(function(){if(SHV.toast)SHV.toast.success(\'주소 복사됨\')})" title="복사"><i class="fa fa-copy mr-1"></i>복사</button>';
    }
    html+='</div></div>';

    /* ── 회사 정보 (라인 섹션 + 고밀도 그리드) ── */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-section-title">회사 정보</div>';
    html+='<div class="dv-row-grid">';
    html+=rowItem('업태', d.business_type, 2, {field:'business_type'});
    html+=rowItem('업종', d.business_class, 2, {field:'business_class'});
    html+=rowItem('이메일', d.email, 2, {field:'email'});
    html+=rowItem('협력계약', d.cooperation_contract, 3, {field:'cooperation_contract'});
    html+=rowItem('그룹', d.group_name, 3, {field:'group_name'});
    html+='</div></div>';

    /* ── 식별 정보 (법인번호 + 사업자번호 묶음) ── */
    html+='<div class="dv-info-section dv-section-id">';
    html+='<div class="dv-section-title">식별 정보</div>';
    html+='<div class="dv-id-box">';
    html+='<div class="dv-id-item"><span class="dv-row-label">'+escH(identityLabel)+'</span><span class="dv-row-value dv-mono dv-editable" data-field="identity_number">'+(d.identity_number?escH(fmtCorpNo(d.identity_number)):empty)+'</span></div>';
    html+='<div class="dv-id-item"><span class="dv-row-label">사업자번호</span><span class="dv-row-value dv-mono dv-editable" data-field="card_number">'+(d.card_number?escH(fmtBizNo(d.card_number)):empty)+'</span></div>';
    html+='</div></div>';

    /* ── 주요사업 (설명형 — 하이라이트 블록) ── */
    html+='<div class="dv-info-section dv-section-highlight">';
    html+='<div class="dv-section-title">주요사업</div>';
    html+='<div class="dv-desc-value dv-editable" data-field="main_business">'+(d.main_business?escH(d.main_business):empty)+'</div>';
    html+='</div>';

    /* ── 관리 정보 (라인 섹션) ── */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-section-title">관리 정보</div>';
    html+='<div class="dv-row-grid">';
    html+=rowItem('담당자', d.employee_name, 2, {field:'employee_name'});
    html+=rowItem('등록일', fmtDate(d.registered_date), 3);
    html+=rowItem('등록자', d.reg_emp_name, 3);
    html+='</div></div>';

    /* ── 사용견적 ── */
    var ueHtml = formatEstimateBadges(d.used_estimate);
    if(ueHtml){
        html+='<div class="dv-info-section">';
        html+='<div class="dv-line-label">사용견적</div>';
        html+='<div class="dv-line-value">'+ueHtml+'</div>';
        html+='</div>';
    }

    /* ── 메모 ── */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-line-label">메모</div>';
    html+='<div class="dv-line-value"><i class="fa fa-sticky-note-o text-accent mr-1"></i><span class="dv-editable" data-field="memo">'+(d.memo?escH(d.memo):empty)+'</span></div>';
    html+='</div>';

    /* ── 첨부파일 ── */
    if(d.attachments && d.attachments.length){
        html+='<div class="dv-info-section">';
        html+='<div class="dv-line-label">첨부파일</div>';
        html+='<div class="dv-line-value">';
        d.attachments.forEach(function(f){
            html+='<span class="text-sm mr-3"><i class="fa fa-paperclip mr-1 text-3"></i>'+escH(f.file_name||'파일')+'</span>';
        });
        html+='</div></div>';
    }

    var body=$('hvInfoBody');
    if(body) body.innerHTML=html;
}

function rowItem(label, value, priority, opts){
    if(typeof opts==='boolean') opts={mono:opts};
    opts=opts||{};
    var empty='<span class="text-3">-</span>';
    var cls='dv-row-value';
    if(priority===1) cls+=' dv-primary';
    else if(priority===3) cls+=' dv-tertiary';
    if(opts.mono) cls+=' dv-mono';
    if(opts.field) cls+=' dv-editable';
    var da=opts.field?' data-field="'+opts.field+'"':'';
    var html='<div class="dv-row-item">';
    html+='<span class="dv-row-label">'+escH(label)+'</span>';
    html+='<span class="'+cls+'"'+da+'>'+(value?escH(value):empty)+'</span>';
    html+='</div>';
    return html;
}

function formatEstimateBadges(val){
    if(!val) return '';
    try{
        var arr=JSON.parse(val);
        if(Array.isArray(arr)&&arr.length){
            return arr.map(function(v){ return '<span class="dv-ue-badge">'+escH(v.name||v)+'</span>'; }).join(' ');
        }
    }catch(e){}
    if(val) return '<span class="dv-ue-badge">'+escH(val)+'</span>';
    return '';
}

/* ── 사업장 목록 로드 ── */
var _branchLoaded = false;

function loadBranches(){
    if(_branchLoaded) return;
    _branchLoaded = true;

    SHV.api.get('dist_process/saas/Member.php', {todo:'list', head_idx:_headIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('hvBranchBody').innerHTML='<div class="text-center p-8 text-3">조회 실패</div>'; return; }
            var all = res.data.data||[];
            var connected=[], planned=[], totalSites=0;
            all.forEach(function(r){
                var st = r.member_status||r.status||'';
                totalSites += parseInt(r.site_count||0,10);
                if(st==='예정') planned.push(r);
                else connected.push(r);
            });

            /* 요약 카드 업데이트 */
            var sb=$('hvSumBranch'); if(sb) sb.innerHTML=connected.length+'<small>건</small>';
            var sbs=$('hvSumBranchSub'); if(sbs) sbs.textContent='정상 연결 사업장';
            var sp=$('hvSumPlanned'); if(sp) sp.innerHTML=planned.length+'<small>건</small>';
            var sps=$('hvSumPlannedSub'); if(sps) sps.textContent='예정 상태 사업장';
            var ss=$('hvSumSite'); if(ss) ss.innerHTML=totalSites+'<small>건</small>';

            /* 탭 배지 */
            var cntEl=$('hvBranchCnt'); if(cntEl) cntEl.textContent=connected.length;
            var pCntEl=$('hvPlannedCnt'); if(pCntEl) pCntEl.textContent=planned.length;
            var bInfo=$('hvBranchInfo'); if(bInfo) bInfo.textContent=connected.length+'건';
            var pInfo=$('hvPlannedInfo'); if(pInfo) pInfo.textContent=planned.length+'건';

            renderBranchTable('hvBranchBody', 'hvBranchTbl', connected, true);
            renderBranchTable('hvPlannedBody', 'hvPlannedTbl', planned, false);
            renderOrgTree(connected, planned);
        })
        .catch(function(){ $('hvBranchBody').innerHTML='<div class="text-center p-8 text-3">네트워크 오류</div>'; });
}

function renderBranchTable(bodyId, tblId, rows, showLink){
    var body=$(bodyId); if(!body) return;
    if(!rows.length){
        var icon = bodyId.indexOf('Planned')>-1 ? 'fa-clock-o' : 'fa-map-marker';
        var msg = bodyId.indexOf('Planned')>-1 ? '예정사업장이 없습니다' : '연결된 사업장이 없습니다';
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa '+icon+'"></i></div><p class="text-3 mt-2">'+msg+'</p></div>';
        return;
    }
    var cols='<th class="th-center">No</th>';
    if(showLink) cols+='<th>연결상태</th>';
    cols+='<th>사업장명</th><th>사업자번호</th><th>담당자</th><th>현장</th><th>전화</th><th>주소</th><th>등록일</th>';
    var html='<table class="tbl" id="'+tblId+'"><thead><tr>'+cols+'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        var ls = r.link_status||'연결';
        html+='<tr class="cursor-pointer" onclick="hvGoBranch('+r.idx+')">';
        html+='<td class="td-no">'+(i+1)+'</td>';
        if(showLink) html+='<td>'+linkBadge(ls)+'</td>';
        html+='<td><b class="text-1">'+escH(r.name)+'</b></td>'
            +'<td class="text-xs">'+escH(r.card_number||'')+'</td>'
            +'<td>'+escH(r.employee_name||'')+'</td>'
            +'<td class="text-center">'+(r.site_count||0)+'</td>'
            +'<td class="whitespace-nowrap">'+escH(r.tel||'')+'</td>'
            +'<td class="truncate max-w-200" title="'+escH(r.address||'')+'">'+escH(r.address||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.registered_date||r.regdate||r.created_at)+'</td></tr>';
    });
    html+='</tbody></table>';
    body.innerHTML=html;
}

function linkBadge(ls){
    if(!ls) ls='연결';
    var cls={'요청':'badge-status-예정','연결':'badge-status-진행','중단':'badge-status-중지'};
    return '<span class="badge-status '+(cls[ls]||'badge-status-예정')+'">'+escH(ls)+'</span>';
}

/* ── 사업장현황 트리 ── */
function renderOrgTree(connected, planned){
    var body=$('hvTreeBody'); if(!body) return;
    var all = connected.concat(planned);
    if(!all.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-sitemap"></i></div><p class="text-3 mt-2">연결된 사업장이 없습니다</p></div>';
        return;
    }
    var html='<div class="hv-org-tree">';
    html+='<div class="hv-org-node hv-org-root"><i class="fa fa-building mr-2"></i>'+escH(_data?_data.name:'본사');
    if(_data&&_data.head_structure) html+=' <span class="badge-status badge-status-운영 ml-2">'+escH(_data.head_structure)+'</span>';
    html+='</div><div class="hv-org-line"></div><div class="hv-org-children">';
    all.forEach(function(r){
        var isP = (r.member_status||r.status||'')==='예정';
        html+='<div class="hv-org-child"><div class="hv-org-line-h"></div>'
            +'<div class="hv-org-node '+(isP?'hv-org-planned':'hv-org-branch')+'" onclick="hvGoBranch('+r.idx+')">'
            +escH(r.name)+'<div class="hv-org-sub">'+(isP?'예정':'사업장')
            +((r.site_count||0)>0?' · 현장 '+(r.site_count||0):'')
            +'</div></div></div>';
    });
    html+='</div></div>';
    body.innerHTML=html;
}

/* ── 지도 팝업 (구글맵 위성뷰 + 카카오/네이버 링크) ── */
window.hvShowMap = function(addr){
    if(!addr) return;
    var overlay = document.createElement('div');
    overlay.className = 'shv-map-overlay';
    var box = document.createElement('div');
    box.className = 'shv-map-box';
    var hd = document.createElement('div');
    hd.className = 'shv-map-header';
    hd.innerHTML = '<i class="fa fa-map-marker text-accent mr-2"></i>'
        +'<span class="shv-map-addr">'+escH(addr)+'</span>'
        +'<a href="https://map.kakao.com/?q='+encodeURIComponent(addr)+'" target="_blank" class="shv-map-link shv-map-kakao" onclick="event.stopPropagation()"><i class="fa fa-external-link mr-1"></i>카카오맵</a>'
        +'<a href="https://map.naver.com/v5/search/'+encodeURIComponent(addr)+'" target="_blank" class="shv-map-link shv-map-naver" onclick="event.stopPropagation()"><i class="fa fa-external-link mr-1"></i>네이버</a>'
        +'<button class="shv-map-close" onclick="this.closest(\'.shv-map-overlay\').remove()"><i class="fa fa-times"></i></button>';
    box.appendChild(hd);
    var iframe = document.createElement('iframe');
    iframe.src = 'https://maps.google.com/maps?q='+encodeURIComponent(addr)+'&t=k&z=18&output=embed';
    iframe.className = 'shv-map-iframe';
    iframe.referrerPolicy = 'no-referrer';
    iframe.allow = 'geolocation';
    box.appendChild(iframe);
    overlay.appendChild(box);
    overlay.addEventListener('click', function(e){ if(e.target===overlay) overlay.remove(); });
    document.body.appendChild(overlay);
};

/* ── 테이블 검색 ── */
window.hvFilterTable = function(tblId, q){
    var tbl=$(tblId); if(!tbl) return;
    q=(q||'').toLowerCase().trim();
    tbl.querySelectorAll('tbody tr').forEach(function(tr){
        tr.style.display=(!q||tr.textContent.toLowerCase().indexOf(q)>-1)?'':'none';
    });
};

/* ── 네비게이션 ── */
window.hvBack = function(){ if(SHV&&SHV.router) SHV.router.navigate('member_head'); };
window.hvGoBranch = function(idx){ if(SHV&&SHV.router) SHV.router.navigate('member_branch_view',{member_idx:idx}); };
window.hvCopyLink = function(){
    var url=location.origin+location.pathname+'?r=head_view&head_idx='+_headIdx;
    if(navigator.clipboard) navigator.clipboard.writeText(url).then(function(){ if(SHV.toast) SHV.toast.success('링크가 복사되었습니다.'); });
};
window.hvAddBranch = function(){
    SHV.modal.open('views/saas/fms/member_branch_add.php?head_idx='+_headIdx+'&member_status=예정', '사업장 등록', 'lg');
    SHV.modal.onClose(function(){ _branchLoaded=false; loadBranches(); });
};
window.hvSettings = function(){
    SHV.modal.open('views/saas/fms/head_settings.php?head_idx='+_headIdx, '본사 설정', 'lg');
    SHV.modal.onClose(function(){ _data=null; _branchLoaded=false; if(_ocInited){_ocInited=false;} loadDetail(); });
};
window.hvEdit = function(){
    if(!_data) return;
    SHV.modal.open('member_head_add.php?todo=modify&idx='+_headIdx, '본사 수정', 'lg');
    SHV.modal.onClose(function(){ _data=null; _branchLoaded=false; loadDetail(); });
};
window.hvDelete = function(){
    if(!_data) return;
    if(window.SHV && SHV.confirm){
        SHV.confirm({
            title:'본사 삭제', message:'\''+escH(_data.name)+'\' 본사를 삭제하시겠습니까?',
            type:'danger', confirmText:'삭제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'delete',idx:_headIdx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('본사가 삭제되었습니다.'); hvBack(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                    }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
            }
        });
    }
};

/* ── 주소 인라인 편집 (Daum Postcode 연동) ── */
window.hvAddrEdit = function(){
    var row = $('hvAddrRow');
    if(!row || row.querySelector('.dv-addr-edit-box')) return;
    var origHtml = row.innerHTML;
    var html = '<div class="dv-addr-edit-box">';
    html+='<input type="text" class="dv-edit-input dv-addr-zip-input" id="hvAeZip" value="'+escH(_data.zipcode||'')+'" placeholder="우편번호" readonly>';
    html+='<button class="dv-addr-search-btn" onclick="hvAddrSearch()"><i class="fa fa-search mr-1"></i>주소검색</button>';
    html+='<input type="text" class="dv-edit-input" id="hvAeAddr" value="'+escH(_data.address||'')+'" placeholder="주소" readonly>';
    html+='<input type="text" class="dv-edit-input" id="hvAeDetail" value="'+escH(_data.address_detail||'')+'" placeholder="상세주소">';
    html+='<button class="btn btn-glass-primary btn-sm" onclick="hvAddrSave()">저장</button>';
    html+='<button class="btn btn-ghost btn-sm" onclick="hvAddrCancel()">취소</button>';
    html+='</div>';
    row.innerHTML = html;
    row._origHtml = origHtml;
    $('hvAeDetail').focus();
};
window.hvAddrSearch = function(){
    if(typeof daum==='undefined'||!daum.Postcode){
        var s=document.createElement('script');
        s.src='//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
        s.onload=function(){ hvAddrSearchOpen(); };
        document.head.appendChild(s);
    } else { hvAddrSearchOpen(); }
};
function hvAddrSearchOpen(){
    new daum.Postcode({
        oncomplete:function(data){
            var z=$('hvAeZip'), a=$('hvAeAddr');
            if(z) z.value=data.zonecode;
            if(a) a.value=data.roadAddress||data.jibunAddress;
            var d=$('hvAeDetail'); if(d) d.focus();
        }
    }).open();
}
window.hvAddrSave = function(){
    var zip=($('hvAeZip')||{}).value||'';
    var addr=($('hvAeAddr')||{}).value||'';
    var detail=($('hvAeDetail')||{}).value||'';
    SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'update',idx:_headIdx,zipcode:zip,address:addr,address_detail:detail})
        .then(function(res){
            if(res.ok){
                _data.zipcode=zip; _data.address=addr; _data.address_detail=detail;
                renderInfo(_data);
                if(SHV.toast) SHV.toast.success('주소가 수정되었습니다.');
            } else { if(SHV.toast) SHV.toast.error(res.message||'주소 수정 실패'); }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};
window.hvAddrCancel = function(){
    var row=$('hvAddrRow');
    if(row&&row._origHtml) row.innerHTML=row._origHtml;
};

/* ── 조직도 초기화 ── */
var _ocInited = false;
function initOrgChart(){
    if(_ocInited) return;
    _ocInited = true;

    function doInit(){
        if(SHV.orgChart){
            SHV.orgChart.init({headIdx:_headIdx, memberIdx:0, container:'#hvOrgBody'});
        }
    }

    if(SHV.orgChart){
        doInit();
    } else {
        var s=document.createElement('script');
        s.src='js/pages/org_chart.js?v=20260415a';
        s.onload=doInit;
        document.head.appendChild(s);
    }
}

loadDetail();
loadBranches(); /* 요약 카드 초기 로드 */
})();
</script>
</section>
