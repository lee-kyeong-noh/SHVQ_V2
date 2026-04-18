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

$siteIdx = (int)($_GET['site_idx'] ?? 0);
if ($siteIdx <= 0) {
    echo '<div class="empty-state"><p class="empty-message">잘못된 접근입니다.</p></div>';
    exit;
}
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<link rel="stylesheet" href="css/v2/detail-view.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/detail-view.css')?:'1' ?>">
<section data-page="site_view" data-title="현장 상세">

<!-- ── 헤더 카드 ── -->
<div class="dv-header" id="svHeader">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-3">
            <button class="btn btn-ghost btn-sm" onclick="svBack()" title="목록으로"><i class="fa fa-arrow-left"></i></button>
            <i class="fa fa-map-marker text-accent"></i>
            <span class="dv-title" id="svTitle">로딩 중...</span>
            <span id="svBadges"></span>
        </div>
        <div class="flex items-center gap-2">
            <button class="btn btn-outline btn-sm" onclick="svCopyLink()"><i class="fa fa-link mr-1"></i>링크 복사</button>
            <button class="btn btn-outline btn-sm" onclick="svSettings()"><i class="fa fa-cog mr-1"></i>설정</button>
            <button class="btn btn-glass-primary btn-sm" onclick="svEdit()"><i class="fa fa-pencil mr-1"></i>수정</button>
            <button class="btn btn-ghost btn-sm text-danger" onclick="svDelete()"><i class="fa fa-trash"></i></button>
        </div>
    </div>
    <div class="dv-sub-pills" id="svPills"></div>
</div>

<!-- ── 요약 카드 (KPI) ── -->
<div class="dv-summary" id="svSummary">
    <div class="dv-sum-card"><div class="dv-sum-label">견적</div><div class="dv-sum-value" id="svSumEst">-</div><div class="dv-sum-sub">등록 건수</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">수주액</div><div class="dv-sum-value" id="svSumAmt">-</div><div class="dv-sum-sub">총 공급가액</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">수금</div><div class="dv-sum-value" id="svSumBill">-</div><div class="dv-sum-sub">수금 현황</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">총수량</div><div class="dv-sum-value" id="svSumQty">-</div><div class="dv-sum-sub">품목 수량</div></div>
</div>

<!-- ── 탭 바 ── -->
<div class="card flex-shrink-0">
    <div class="tab-bar sv-tab-bar-scroll" id="svTabBar">
        <button class="tab-item tab-active" data-tab="svTabInfo" onclick="svTabSwitch(this)"><i class="fa fa-info-circle mr-1"></i>기본정보</button>
        <button class="tab-item" data-tab="svTabEst" onclick="svTabSwitch(this)">견적 <span class="tab-badge" id="svEstCnt">0</span></button>
        <button class="tab-item" data-tab="svTabBill" onclick="svTabSwitch(this)">수금 <span class="tab-badge" id="svBillCnt">0</span></button>
        <button class="tab-item" data-tab="svTabContact" onclick="svTabSwitch(this)">연락처 <span class="tab-badge" id="svContactCnt">0</span></button>
        <button class="tab-item" data-tab="svTabFloor" onclick="svTabSwitch(this)">도면</button>
        <button class="tab-item" data-tab="svTabAttach" onclick="svTabSwitch(this)">첨부</button>
        <button class="tab-item" data-tab="svTabSub" onclick="svTabSwitch(this)">도급</button>
        <button class="tab-item" data-tab="svTabAccess" onclick="svTabSwitch(this)">출입</button>
        <button class="tab-item" data-tab="svTabMail" onclick="svTabSwitch(this)">메일</button>
        <button class="tab-item" data-tab="svTabFm" onclick="svTabSwitch(this)">현장소장</button>
        <button class="tab-item" data-tab="svTabMemo" onclick="svTabSwitch(this)">특기사항</button>
        <button class="tab-item" data-tab="svTabCad" onclick="svTabSwitch(this)">CAD</button>
        <button class="tab-item" data-tab="svTabPjt" onclick="svTabSwitch(this)">PJT</button>
    </div>
</div>

<!-- ── 기본정보 탭 ── -->
<div class="card card-fill" id="svTabInfo">
    <div class="dv-info-body" id="svInfoBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 견적 탭 ── -->
<div class="card card-fill hidden" id="svTabEst">
    <div class="flex items-center justify-between px-4 py-3 flex-wrap gap-2">
        <div class="flex gap-2 items-center flex-1">
            <input type="text" id="svEstSearch" class="form-input flex-1 max-w-300" placeholder="견적번호 검색..." oninput="svFilterTable('svEstTbl',this.value)">
            <select id="svEstFilter" class="form-select form-select-sm st-filter-dd" onchange="svFilterEstStatus()">
                <option value="">상태 전체</option><option value="DRAFT">작성중</option><option value="SUBMITTED">제출</option><option value="APPROVED">승인</option><option value="REJECTED">반려</option>
            </select>
        </div>
        <div class="flex gap-1">
            <button class="btn btn-ghost btn-sm" onclick="svToggleEstGroup()" title="속성별 그룹" id="svEstGroupBtn"><i class="fa fa-object-group"></i></button>
            <button class="btn btn-ghost btn-sm" onclick="svExportEst()" title="CSV"><i class="fa fa-download"></i></button>
            <button class="btn btn-glass-primary btn-sm" onclick="svAddEst()"><i class="fa fa-plus mr-1"></i>견적 등록</button>
        </div>
    </div>
    <div id="svEstBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 수금 탭 ── -->
<div class="card card-fill hidden" id="svTabBill">
    <div class="flex items-center justify-between px-4 py-3 flex-wrap gap-2">
        <span class="text-sm font-semibold text-1">수금 현황</span>
        <div class="flex items-center gap-2">
            <select id="svBillFilter" class="form-select form-select-sm st-filter-dd" onchange="svFilterBills()">
                <option value="">전체</option>
                <option value="청구">청구</option>
                <option value="입금">입금</option>
                <option value="완료">완료</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="svExportBill()" title="CSV 내보내기"><i class="fa fa-download"></i></button>
            <button class="btn btn-glass-primary btn-sm" onclick="svAddBill()"><i class="fa fa-plus mr-1"></i>수금 등록</button>
        </div>
    </div>
    <div id="svBillSumBar" class="hidden px-4 pb-3"></div>
    <div id="svBillBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 연락처 탭 ── -->
<div class="card card-fill hidden" id="svTabContact">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="svContactSearch" class="form-input flex-1 max-w-300" placeholder="이름, 직급, 연락처 검색..." oninput="svFilterTable('svContactTbl',this.value)">
        <button class="btn btn-glass-primary btn-sm ml-2" onclick="svAddContact()"><i class="fa fa-plus mr-1"></i>연락처 등록</button>
    </div>
    <div id="svContactBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 도면 탭 ── -->
<div class="card card-fill hidden" id="svTabFloor">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">도면 목록</span>
        <button class="btn btn-glass-primary btn-sm" onclick="svAddFloor()"><i class="fa fa-upload mr-1"></i>도면 등록</button>
    </div>
    <div id="svFloorBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 첨부 탭 ── -->
<div class="card card-fill hidden" id="svTabAttach">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">첨부파일</span>
        <button class="btn btn-glass-primary btn-sm" onclick="svUploadAttach()"><i class="fa fa-upload mr-1"></i>파일 업로드</button>
    </div>
    <div id="svAttachBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 도급 탭 ── -->
<div class="card card-fill hidden" id="svTabSub">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">도급 현황</span>
        <button class="btn btn-glass-primary btn-sm" onclick="svAddSub()"><i class="fa fa-plus mr-1"></i>도급 등록</button>
    </div>
    <div id="svSubBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 출입 탭 ── -->
<div class="card card-fill hidden" id="svTabAccess">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">출입 기록</span>
        <button class="btn btn-glass-primary btn-sm" onclick="svAddAccess()"><i class="fa fa-plus mr-1"></i>출입 등록</button>
    </div>
    <div id="svAccessBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 메일 탭 ── -->
<div class="card card-fill hidden" id="svTabMail">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">메일</span>
        <button class="btn btn-glass-primary btn-sm" onclick="svComposeMail()"><i class="fa fa-pencil-square-o mr-1"></i>메일 작성</button>
    </div>
    <div id="svMailBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 현장소장 탭 ── -->
<div class="card card-fill hidden" id="svTabFm">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">현장소장</span>
    </div>
    <div id="svFmBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 특기사항 탭 (SHV.chat) ── -->
<div class="card card-fill hidden" id="svTabMemo"><div id="svMemoChat" class="sc-container"></div></div>

<!-- ── CAD 탭 ── -->
<div class="card card-fill hidden" id="svTabCad"><div class="p-4"><div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-object-group"></i></div><p class="text-3 mt-2">CAD 도면 기능 준비 중입니다.</p><a href="?r=smartcad" class="btn btn-outline btn-sm mt-2"><i class="fa fa-external-link mr-1"></i>SmartCAD 열기</a></div></div></div>

<!-- ── PJT 탭 ── -->
<div class="card card-fill hidden" id="svTabPjt"><div id="svPjtBody" class="p-4"><div class="text-center p-8 text-3">준비 중입니다.</div></div></div>

<script>
(function(){
'use strict';

var _siteIdx = <?= $siteIdx ?>;
var _data = null;
var _loaded = {};

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)||n===0?'':n.toLocaleString(); }
function fmtMoney(v){ var n=parseInt(v,10); return isNaN(n)?'-':n.toLocaleString()+'원'; }
function statusBadge(s){ return s?'<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>':''; }

/* ── 탭 전환 (지연 로딩) ── */
var _tabs = ['svTabInfo','svTabEst','svTabBill','svTabContact','svTabFloor','svTabAttach','svTabSub','svTabAccess','svTabMail','svTabFm','svTabMemo','svTabCad','svTabPjt'];

window.svTabSwitch = function(btn){
    document.querySelectorAll('#svTabBar .tab-item').forEach(function(t){ t.classList.remove('tab-active'); });
    btn.classList.add('tab-active');
    var target = btn.dataset.tab;
    _tabs.forEach(function(id){ var el=$(id); if(el) el.classList.toggle('hidden', id!==target); });
    try{ sessionStorage.setItem('sv_tab_'+_siteIdx, target); }catch(e){}
    if(!_loaded[target] && _data){
        _loaded[target]=true;
        if(target==='svTabEst')     loadEstimates();
        if(target==='svTabBill')    loadBills();
        if(target==='svTabContact') loadContacts();
        if(target==='svTabFloor')   loadFloorPlans();
        if(target==='svTabAttach')  loadAttachments();
        if(target==='svTabSub')     loadSubcontracts();
        if(target==='svTabAccess')  loadAccessLogs();
        if(target==='svTabMail')    loadMails();
        if(target==='svTabFm')      loadFieldManager();
        if(target==='svTabMemo')    initChat();
        if(target==='svTabPjt')     initPjt();
    }
};

/* ── 현장 상세 로드 ── */
function loadDetail(){
    SHV.api.get('dist_process/saas/Site.php', {todo:'detail', site_idx:_siteIdx})
        .then(function(res){
            if(!res.ok){ if(SHV.toast) SHV.toast.error(res.message||'현장 조회 실패'); return; }
            _data = res.data.site || res.data;
            renderHeader(_data);
            renderSummary(_data, res.data);
            renderInfo(_data);
            _loaded.svTabInfo=true;
            var estCount = res.data.estimate_count || (res.data.estimates||[]).length;
            var c=$('svEstCnt'); if(c) c.textContent=estCount;
            /* 마지막 탭 복원 (_data 로드 완료 후) */
            try{
                var savedTab=sessionStorage.getItem('sv_tab_'+_siteIdx);
                if(savedTab && _tabs.indexOf(savedTab)>0){
                    var tabBtn=document.querySelector('#svTabBar [data-tab="'+savedTab+'"]');
                    if(tabBtn) tabBtn.click();
                }
            }catch(e){}
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 헤더 ── */
function renderHeader(d){
    var name = d.site_display_name||d.name||d.site_name||'';
    var status = d.site_status||d.status||'';
    var t=$('svTitle'); if(t) t.textContent=name||'현장 상세';
    var b=$('svBadges'); if(b) b.innerHTML=statusBadge(status);
    var pills=[];
    if(d.member_name) pills.push('<span class="dv-pill cursor-pointer" onclick="svGoMember('+d.member_idx+')"><i class="fa fa-building-o mr-1"></i>'+escH(d.member_name)+'</span>');
    if(d.site_number) pills.push('<span class="dv-pill"><i class="fa fa-hashtag mr-1"></i>'+escH(d.site_number)+'</span>');
    if(d.employee_name||d.manager_name) pills.push('<span class="dv-pill"><i class="fa fa-user mr-1"></i>'+escH(d.employee_name||d.manager_name)+'</span>');
    if(d.target_team) pills.push('<span class="dv-pill"><i class="fa fa-users mr-1"></i>'+escH(d.target_team)+'</span>');
    var p=$('svPills'); if(p) p.innerHTML=pills.join('');
}

/* ── 요약 카드 ── */
function renderSummary(d, full){
    var el;
    el=$('svSumEst');  if(el) el.innerHTML=fmtNum(full.estimate_count||(full.estimates||[]).length)||'0';
    el=$('svSumAmt');  if(el) el.innerHTML=full.total_supply_amount?fmtMoney(full.total_supply_amount):'-';
    el=$('svSumBill'); if(el) el.innerHTML=fmtNum(full.bill_count)||'0';
    el=$('svSumQty');  if(el) el.innerHTML=fmtNum(d.external_employee||d.total_qty)||'-';
}

/* ══════════════════════════
   인라인 편집 설정
   ══════════════════════════ */
var _editCfg = {
    site_name:    {type:'text'},
    site_number:  {type:'text'},
    site_status:  {type:'select', options:['예정','진행','중지','완료','마감']},
    construction: {type:'text'},
    employee_name:{type:'search', apiField:'employee_idx'},
    target_team:  {type:'text'},
    manager_tel:  {type:'text'},
    total_qty:    {type:'text'},
    start_date:   {type:'date'},
    end_date:     {type:'date'},
    warranty_period:{type:'text'},
    memo:         {type:'textarea'}
};

function editAttr(field, cls){
    if(!_editCfg[field]) return cls?(' class="dv-row-value '+cls+'"'):' class="dv-row-value"';
    return ' class="dv-row-value dv-editable'+(cls?' '+cls:'')+'" data-field="'+field+'"';
}

/* ── 기본정보 (dv-row-grid + 인라인 편집) ── */
function renderInfo(d){
    var html='';

    /* 기본 정보 */
    html+='<div class="dv-info-section"><div class="dv-section-title">기본 정보</div><div class="dv-row-grid">';
    html+='<div class="dv-row-item"><span class="dv-row-label">현장명</span><span'+editAttr('site_name','dv-primary')+'>'+escH(d.site_display_name||d.name||d.site_name||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">현장번호</span><span'+editAttr('site_number')+'>'+escH(d.site_number||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">상태</span><span'+editAttr('site_status')+'>'+escH(d.site_status||d.status||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">사업장</span><span class="dv-row-value">'+(d.member_name?escH(d.member_name)+(d.member_idx?' <a onclick="svGoMember('+d.member_idx+')">→</a>':''):'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">건설사</span><span'+editAttr('construction')+'>'+escH(d.construction||'-')+'</span></div>';
    html+='</div></div>';

    /* 담당자 */
    html+='<div class="dv-info-section"><div class="dv-section-title">담당자</div><div class="dv-row-grid">';
    html+='<div class="dv-row-item"><span class="dv-row-label">담당자</span><span'+editAttr('employee_name')+'>'+escH(d.employee_name||d.manager_name||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">담당부서</span><span'+editAttr('target_team')+'>'+escH(d.target_team||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">전화번호</span><span'+editAttr('manager_tel')+'>'+escH(d.manager_tel||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">부담당</span><span class="dv-row-value">'+escH(d.employee1_name||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">발주담당</span><span class="dv-row-value">'+escH(d.phonebook_name||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">담당권역</span><span class="dv-row-value">'+escH(d.region||'-')+'</span></div>';
    html+='</div></div>';

    /* 공사 정보 */
    html+='<div class="dv-info-section"><div class="dv-section-title">공사 정보</div><div class="dv-row-grid">';
    html+='<div class="dv-row-item"><span class="dv-row-label">총수량</span><span'+editAttr('total_qty')+'>'+(fmtNum(d.external_employee||d.total_qty)||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">착공일</span><span'+editAttr('start_date')+'>'+(fmtDate(d.construction_date||d.start_date)||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">준공일</span><span'+editAttr('end_date')+'>'+(fmtDate(d.completion_date||d.end_date)||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">보증기간</span><span'+editAttr('warranty_period')+'>'+(d.warranty_period?d.warranty_period+'개월':'-')+'</span></div>';
    html+='</div></div>';

    /* 주소 */
    var addr=(d.address||'')+(d.address_detail?' '+d.address_detail:'');
    if(addr){
        html+='<div class="dv-info-section"><div class="dv-section-title">주소</div>'
            +'<div class="dv-addr-compact">'
            +(d.zipcode?'<span class="dv-zip">'+escH(d.zipcode)+'</span>':'')
            +'<span class="dv-addr-text">'+escH(addr)+'</span>'
            +'<button class="btn btn-outline btn-sm" onclick="svCopyAddr()"><i class="fa fa-copy"></i></button>'
            +'<button class="btn btn-outline btn-sm" onclick="svOpenMap()"><i class="fa fa-map"></i></button>'
            +'<button class="btn btn-outline btn-sm" onclick="svToggleMapIframe()"><i class="fa fa-map-o"></i> 지도</button>'
            +'</div>'
            +'<div id="svMapIframe" class="hidden mt-2"><iframe class="sv-map-iframe" src=""></iframe></div>'
            +'</div>';
    }

    /* 부가 정보 */
    html+='<div class="dv-info-section"><div class="dv-section-title">부가 정보</div><div class="dv-row-grid">';
    html+='<div class="dv-row-item"><span class="dv-row-label">등록일</span><span class="dv-row-value">'+(fmtDate(d.registered_date||d.regdate||d.created_at)||'-')+'</span></div>';
    html+='<div class="dv-row-item"><span class="dv-row-label">메모</span><span'+editAttr('memo')+'>'+escH(d.memo||'-')+'</span></div>';
    html+='</div></div>';

    var body=$('svInfoBody');
    if(body) body.innerHTML=html||'<div class="text-center p-8 text-3">정보가 없습니다.</div>';
}

/* ══════════════════════════
   인라인 편집 엔진 (member_branch_view 패턴)
   ══════════════════════════ */
function svInline(el){
    if(el.querySelector('.dv-edit-input,.dv-edit-select')) return;
    var field=el.dataset.field;
    var cfg=_editCfg[field]; if(!cfg) return;
    var rawVal=_data[field]||'';
    if(rawVal==='-') rawVal='';
    var origHtml=el.innerHTML;
    var input;

    if(cfg.type==='select'){
        input=document.createElement('select');
        input.className='dv-edit-select';
        (cfg.options||[]).forEach(function(o){
            var opt=document.createElement('option');
            opt.value=o; opt.textContent=o||'(없음)';
            if(o===rawVal) opt.selected=true;
            input.appendChild(opt);
        });
    } else if(cfg.type==='textarea'){
        input=document.createElement('textarea');
        input.className='dv-edit-input dv-edit-textarea';
        input.value=rawVal; input.rows=2;
    } else if(cfg.type==='date'){
        input=document.createElement('input');
        input.type='date'; input.className='dv-edit-input';
        input.value=fmtDate(rawVal);
    } else if(cfg.type==='search'){
        input=document.createElement('input');
        input.type='text'; input.className='dv-edit-input';
        input.value=rawVal; input.placeholder='이름 검색...';
        var dd=document.createElement('div');
        dd.className='dv-search-dropdown hidden';
        var timer=null;
        input.addEventListener('input',function(){
            clearTimeout(timer);
            var q=input.value.trim();
            if(q.length<1){ dd.classList.add('hidden'); return; }
            timer=setTimeout(function(){
                SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list'})
                    .then(function(res){
                        if(!res.ok) return;
                        var list=(res.data.data||res.data||[]).filter(function(e){ return (e.name||'').toLowerCase().indexOf(q.toLowerCase())>-1; });
                        if(!list.length){ dd.classList.add('hidden'); return; }
                        dd.innerHTML='';
                        list.slice(0,10).forEach(function(emp){
                            var item=document.createElement('div');
                            item.className='dv-search-item';
                            item.textContent=emp.name+(emp.team?' ('+emp.team+')':'');
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
                    }).catch(function(){ dd.classList.add('hidden'); });
            },300);
        });
        var wrap=document.createElement('div');
        wrap.className='dv-edit-search-wrap';
        wrap.appendChild(input);
        wrap.appendChild(dd);
        el.innerHTML='';
        el.appendChild(wrap);
        input.focus();
        setupSaveCancel(input, el, field, cfg, rawVal, origHtml);
        return;
    } else {
        input=document.createElement('input');
        input.type='text'; input.className='dv-edit-input';
        input.value=rawVal;
    }

    el.innerHTML='';
    el.appendChild(input);
    input.focus();
    if(input.select) input.select();
    setupSaveCancel(input, el, field, cfg, rawVal, origHtml);
}

function setupSaveCancel(input, el, field, cfg, rawVal, origHtml){
    var saving=false;
    function save(){
        if(saving) return;
        var newVal=input.value.trim();
        if(newVal===rawVal){ cancel(); return; }
        saving=true;
        el.classList.add('dv-saving');

        var postData={todo:'update', idx:_siteIdx};
        if(cfg.type==='search' && input._empIdx){
            postData[cfg.apiField||field]=input._empIdx;
            postData.employee_name=newVal;
        } else {
            postData[field]=newVal;
        }

        SHV.api.post('dist_process/saas/Site.php', postData)
            .then(function(res){
                el.classList.remove('dv-saving');
                if(res.ok){
                    _data[field]=cfg.type==='search'?newVal:newVal;
                    if(res.data&&res.data.item) Object.assign(_data, res.data.item);
                    el.innerHTML=newVal?escH(newVal):'<span class="text-3">-</span>';
                    el.classList.add('dv-saved');
                    setTimeout(function(){ el.classList.remove('dv-saved'); },800);
                    renderHeader(_data);
                } else {
                    el.innerHTML=origHtml;
                    if(SHV.toast) SHV.toast.error(res.message||'수정 실패');
                }
                saving=false;
            })
            .catch(function(){
                el.classList.remove('dv-saving');
                el.innerHTML=origHtml;
                if(SHV.toast) SHV.toast.error('네트워크 오류');
                saving=false;
            });
    }
    function cancel(){ el.innerHTML=origHtml; }
    input.addEventListener('keydown',function(e){
        if(e.key==='Enter'&&cfg.type!=='textarea'){ e.preventDefault(); save(); }
        if(e.key==='Enter'&&cfg.type==='textarea'&&(e.ctrlKey||e.metaKey)){ e.preventDefault(); save(); }
        if(e.key==='Escape'){ e.preventDefault(); cancel(); }
    });
    input.addEventListener('blur',function(){ if(!saving) setTimeout(save,100); });
}

/* 이벤트 위임: 인라인 편집 */
document.addEventListener('click',function(e){
    var el=e.target.closest('.dv-editable[data-field]');
    if(el&&el.closest('[data-page="site_view"]')){
        e.preventDefault(); e.stopPropagation();
        svInline(el);
    }
});

/* ── 견적 (지연 로딩) ── */
function loadEstimates(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'est_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            if(!res.ok) return;
            var rows=res.data.data||res.data||[];
            var c=$('svEstCnt'); if(c) c.textContent=rows.length;
            var body=$('svEstBody'); if(!body) return;
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-file-text-o"></i></div><p class="text-3 mt-2">등록된 견적이 없습니다</p></div>'; return; }

            if(_estGroupMode){
                /* 속성별 그룹 뷰 */
                var groups={};
                rows.forEach(function(r){
                    var attr=r.attribute||r.estimate_attribute||'미분류';
                    if(!groups[attr]) groups[attr]=[];
                    groups[attr].push(r);
                });
                var h='';
                Object.keys(groups).forEach(function(attr){
                    var gRows=groups[attr];
                    var gTotal=0; gRows.forEach(function(r){ gTotal+=parseInt(r.total_amount||r.amount||0,10); });
                    h+='<div class="dv-info-section"><div class="dv-section-title">'+escH(attr)+' <span class="text-xs text-3 ml-2">('+gRows.length+'건, '+fmtNum(gTotal)+'원)</span></div>';
                    h+='<table class="tbl"><thead><tr><th class="th-center">No</th><th>견적번호</th><th>상태</th><th class="text-right">합계</th><th>등록일</th></tr></thead><tbody>';
                    gRows.forEach(function(r,i){
                        var gt=r.type==='group'?'<span class="badge-status badge-status-그룹">그룹</span> ':'';
                        h+='<tr class="cursor-pointer" onclick="svViewEst('+r.idx+')"><td class="td-no">'+(i+1)+'</td><td>'+gt+escH(r.estimate_no||r.name||'')+'</td><td>'+statusBadge(r.estimate_status||r.status||'')+'</td><td class="text-right font-semibold">'+fmtNum(r.total_amount||r.amount)+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td></tr>';
                    });
                    h+='</tbody></table></div>';
                });
                body.innerHTML=h;
                return;
            }

            var h='<table class="tbl" id="svEstTbl"><thead><tr><th class="th-center">No</th><th>견적번호</th><th>상태</th><th class="text-right">공급가액</th><th class="text-right">합계</th><th>등록일</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                var typeBadge=r.type==='group'?'<span class="badge-status badge-status-그룹">그룹</span> ':'';
                h+='<tr class="cursor-pointer" onclick="svViewEst('+r.idx+')"><td class="td-no">'+(i+1)+'</td><td>'+typeBadge+escH(r.estimate_no||r.name||'')+'</td><td>'+statusBadge(r.estimate_status||r.status||'')+'</td><td class="text-right">'+fmtNum(r.supply_amount||r.amount)+'</td><td class="text-right font-semibold">'+fmtNum(r.total_amount||r.amount)+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td><td class="text-center whitespace-nowrap" onclick="event.stopPropagation()"><button class="btn btn-ghost btn-sm" onclick="svCopyEst('+r.idx+')" title="복사"><i class="fa fa-copy"></i></button><button class="btn btn-ghost btn-sm text-success" onclick="svApproveEst('+r.idx+',\'APPROVED\')" title="승인"><i class="fa fa-check"></i></button><button class="btn btn-ghost btn-sm" onclick="svEstPdf('+r.idx+')" title="PDF"><i class="fa fa-file-pdf-o"></i></button><button class="btn btn-ghost btn-sm text-danger" onclick="svDelEst('+r.idx+')" title="삭제"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
            var tbl=document.getElementById('svEstTbl');
            if(tbl&&window.shvTblSort&&!tbl._shvSortInit) shvTblSort(tbl);
        }).catch(function(){});
}

/* ── 수금 (지연 로딩) ── */
function loadBills(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'bill_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            if(!res.ok){ $('svBillBody').innerHTML='<div class="text-center p-8 text-3">수금 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            var c=$('svBillCnt'); if(c) c.textContent=rows.length;
            if(res.data.summary){
                var s=res.data.summary, bar=$('svBillSumBar');
                if(bar){ bar.classList.remove('hidden'); bar.innerHTML='<div class="flex gap-3 flex-wrap text-sm"><span class="font-semibold">대금 <b class="text-accent">'+fmtMoney(s.total_price||0)+'</b></span><span>기성금 <b>'+fmtMoney(s.progress_amount||0)+'</b></span><span>수금 <b class="text-success">'+fmtMoney(s.collected_amount||0)+'</b></span><span>잔금 <b class="text-danger">'+fmtMoney(s.remaining_amount||0)+'</b></span></div>'; }
            }
            var body=$('svBillBody'); if(!body) return;
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-krw"></i></div><p class="text-3 mt-2">등록된 수금이 없습니다</p></div>'; return; }
            var h='<table class="tbl" id="svBillTbl"><thead><tr><th class="th-center">No</th><th>구분</th><th class="text-right">금액</th><th>상태</th><th>날짜</th><th>비고</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){ h+='<tr><td class="td-no">'+(i+1)+'</td><td>'+escH(r.bill_type||r.type||'')+'</td><td class="text-right font-semibold">'+fmtNum(r.amount)+'</td><td>'+statusBadge(r.bill_status||r.status||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.bill_date||r.created_at)+'</td><td class="text-3">'+escH(r.memo||'')+'</td><td class="text-center whitespace-nowrap"><button class="btn btn-ghost btn-sm" onclick="svEditBill('+r.idx+')" title="수정"><i class="fa fa-pencil"></i></button><button class="btn btn-ghost btn-sm text-accent" onclick="svDepositBill('+r.idx+')" title="입금처리"><i class="fa fa-krw"></i></button><button class="btn btn-ghost btn-sm text-danger" onclick="svDelBill('+r.idx+')" title="삭제"><i class="fa fa-trash"></i></button></td></tr>'; });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svBillBody').innerHTML='<div class="text-center p-8 text-3">수금 데이터를 불러올 수 없습니다.</div>'; });
}

/* ── 연락처 (지연 로딩) ── */
function loadContacts(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'contact_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            if(!res.ok){ $('svContactBody').innerHTML='<div class="text-center p-8 text-3">연락처 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            var c=$('svContactCnt'); if(c) c.textContent=rows.length;
            var body=$('svContactBody'); if(!body) return;
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-address-book-o"></i></div><p class="text-3 mt-2">등록된 연락처가 없습니다</p></div>'; return; }
            var h='<table class="tbl" id="svContactTbl"><thead><tr><th class="th-center">No</th><th>이름</th><th>직급</th><th>전화번호</th><th>이메일</th><th>비고</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                var hidden=r.is_hidden==='1'||r.is_hidden===1;
                var ph=r.phone||r.tel||'';var em=r.email||'';
                h+='<tr'+(hidden?' class="opacity-40"':'')+'><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.name||'')+'</b></td><td class="text-3">'+escH(r.position||r.rank||'')+'</td><td>'+(ph?'<a href="tel:'+escH(ph)+'" class="text-accent">'+escH(ph)+'</a>':'')+'</td><td class="text-3">'+(em?'<a href="mailto:'+escH(em)+'" class="text-accent">'+escH(em)+'</a>':'')+'</td><td class="text-3">'+escH(r.memo||'')+'</td><td class="text-center whitespace-nowrap"><button class="btn btn-ghost btn-sm" onclick="svToggleContactHidden('+(r.idx||0)+','+(hidden?'false':'true')+')" title="'+(hidden?'숨김 해제':'숨기기')+'"><i class="fa '+(hidden?'fa-eye':'fa-eye-slash')+' text-3"></i></button><button class="btn btn-ghost btn-sm" onclick="svEditContact('+(r.idx||0)+')" title="수정"><i class="fa fa-pencil text-3"></i></button><button class="btn btn-ghost btn-sm text-danger" onclick="svDelContact('+(r.idx||0)+')" title="삭제"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svContactBody').innerHTML='<div class="text-center p-8 text-3">연락처 데이터를 불러올 수 없습니다.</div>'; });
}

/* ── 도면 (지연 로딩) ── */
function loadFloorPlans(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'floor_plan_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            var body=$('svFloorBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">도면 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-file-image-o"></i></div><p class="text-3 mt-2">등록된 도면이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>도면명</th><th>파일명</th><th>등록일</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.title||r.name||r.floor_name||'')+'</b></td><td class="text-3">'+escH(r.filename||r.file_name||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td><td class="text-center"><button class="btn btn-ghost btn-sm text-danger" onclick="svDelFloor('+r.idx+')"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svFloorBody').innerHTML='<div class="text-center p-8 text-3">도면 로드 실패</div>'; });
}

/* ── 첨부파일 (지연 로딩) ── */
function loadAttachments(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'attach_list',site_idx:_siteIdx,category:'site_attach',limit:200})
        .then(function(res){
            var body=$('svAttachBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">첨부 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-paperclip"></i></div><p class="text-3 mt-2">등록된 첨부파일이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>파일명</th><th class="text-right">크기</th><th>등록일</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                var url=r.url||r.file_url||'';
                h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.original_name||r.filename||r.file_name||'')+'</b></td><td class="text-right text-3">'+escH(r.file_size||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td><td class="text-center">'+(url?'<a href="'+escH(url)+'" target="_blank" class="btn btn-ghost btn-sm"><i class="fa fa-download"></i></a>':'')+'<button class="btn btn-ghost btn-sm text-danger" onclick="svDelAttach('+(r.idx||r.file_idx||0)+')"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svAttachBody').innerHTML='<div class="text-center p-8 text-3">첨부 로드 실패</div>'; });
}

/* ── 도급 (지연 로딩) ── */
function loadSubcontracts(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'subcontract_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            var body=$('svSubBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">도급 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-handshake-o"></i></div><p class="text-3 mt-2">등록된 도급이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>업체명</th><th>공종</th><th class="text-right">계약금액</th><th>상태</th><th>등록일</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr data-cn="'+escH(r.company_name||r.name||'')+'" data-wt="'+escH(r.work_type||r.category||'')+'" data-ca="'+(r.contract_amount||r.amount||'')+'" data-mm="'+escH(r.memo||'')+'"><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.company_name||r.name||'')+'</b></td><td class="text-3">'+escH(r.work_type||r.category||'')+'</td><td class="text-right">'+fmtNum(r.contract_amount||r.amount)+'</td><td>'+statusBadge(r.status||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td><td class="text-center whitespace-nowrap"><button class="btn btn-ghost btn-sm" onclick="svEditSub('+r.idx+',this)" title="수정"><i class="fa fa-pencil"></i></button><button class="btn btn-ghost btn-sm text-danger" onclick="svDelSub('+r.idx+')" title="삭제"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svSubBody').innerHTML='<div class="text-center p-8 text-3">도급 로드 실패</div>'; });
}

/* ── 출입 기록 (지연 로딩) ── */
function loadAccessLogs(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'access_log_list',site_idx:_siteIdx,limit:200})
        .then(function(res){
            var body=$('svAccessBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">출입 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-sign-in"></i></div><p class="text-3 mt-2">출입 기록이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>이름</th><th>유형</th><th>입장시간</th><th>퇴장시간</th><th>비고</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.person_name||r.name||'')+'</b></td><td class="text-3">'+escH(r.access_type||r.type||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.in_time||r.check_in)+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.out_time||r.check_out)+'</td><td class="text-3">'+escH(r.memo||'')+'</td><td class="text-center"><button class="btn btn-ghost btn-sm text-danger" onclick="svDelAccess('+r.idx+')"><i class="fa fa-trash"></i></button></td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svAccessBody').innerHTML='<div class="text-center p-8 text-3">출입 로드 실패</div>'; });
}

/* ── 메일 (지연 로딩) ── */
function loadMails(){
    SHV.api.get('dist_process/saas/Mail.php',{todo:'list',site_idx:_siteIdx,limit:50})
        .then(function(res){
            var body=$('svMailBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">메일 데이터를 불러올 수 없습니다.</div>'; return; }
            var rows=res.data.data||res.data||[];
            if(!rows.length){ body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-envelope-o"></i></div><p class="text-3 mt-2">현장 관련 메일이 없습니다</p></div>'; return; }
            var h='<table class="tbl"><thead><tr><th class="th-center">No</th><th>제목</th><th>발신자</th><th>날짜</th></tr></thead><tbody>';
            rows.forEach(function(r,i){
                h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(r.subject||r.title||'')+'</b></td><td class="text-3">'+escH(r.from_name||r.sender||'')+'</td><td class="text-3 whitespace-nowrap">'+fmtDate(r.mail_date||r.created_at)+'</td></tr>';
            });
            h+='</tbody></table>';
            body.innerHTML=h;
        }).catch(function(){ $('svMailBody').innerHTML='<div class="text-center p-8 text-3">메일 로드 실패</div>'; });
}

/* ── 현장소장 (지연 로딩) ── */
function loadFieldManager(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'detail',site_idx:_siteIdx})
        .then(function(res){
            var body=$('svFmBody'); if(!body) return;
            if(!res.ok){ body.innerHTML='<div class="text-center p-8 text-3">현장소장 정보를 불러올 수 없습니다.</div>'; return; }
            var d=res.data.site||res.data;
            var managers=res.data.field_managers||d.field_managers||[];
            if(!managers.length && !d.field_manager_name){
                body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-user-circle-o"></i></div><p class="text-3 mt-2">등록된 현장소장이 없습니다</p></div>';
                return;
            }
            var h='<div class="dv-info-body">';
            if(d.field_manager_name){
                h+='<div class="dv-info-section"><div class="dv-section-title">현장소장</div><div class="dv-row-grid">';
                h+='<div class="dv-row-item"><span class="dv-row-label">이름</span><span class="dv-row-value dv-primary">'+escH(d.field_manager_name)+'</span></div>';
                if(d.field_manager_tel) h+='<div class="dv-row-item"><span class="dv-row-label">연락처</span><span class="dv-row-value">'+escH(d.field_manager_tel)+'</span></div>';
                if(d.field_manager_email) h+='<div class="dv-row-item"><span class="dv-row-label">이메일</span><span class="dv-row-value">'+escH(d.field_manager_email)+'</span></div>';
                h+='</div></div>';
            }
            if(managers.length){
                h+='<div class="dv-info-section"><div class="dv-section-title">현장소장 목록 ('+managers.length+'명)</div>';
                h+='<table class="tbl"><thead><tr><th class="th-center">No</th><th>이름</th><th>연락처</th><th>이메일</th><th>비고</th></tr></thead><tbody>';
                managers.forEach(function(m,i){
                    h+='<tr><td class="td-no">'+(i+1)+'</td><td><b class="text-1">'+escH(m.name||'')+'</b></td><td>'+escH(m.tel||m.phone||'')+'</td><td class="text-3">'+escH(m.email||'')+'</td><td class="text-3">'+escH(m.memo||'')+'</td></tr>';
                });
                h+='</tbody></table></div>';
            }
            h+='</div>';
            body.innerHTML=h;
        }).catch(function(){ $('svFmBody').innerHTML='<div class="text-center p-8 text-3">현장소장 로드 실패</div>'; });
}

/* ── 특기사항 (SHV.chat) ── */
function initChat(){
    if(window.SHV&&SHV.chat&&SHV.chat.init) SHV.chat.init('svMemoChat','Tb_Site',_siteIdx);
    else $('svMemoChat').innerHTML='<div class="text-center p-8 text-3">채팅 모듈 로딩 중...</div>';
}

/* ── PJT (SHV.pjt) ── */
function initPjt(){
    if(window.SHV&&SHV.pjt&&SHV.pjt.init) SHV.pjt.init('svPjtBody',_siteIdx);
    else $('svPjtBody').innerHTML='<div class="text-center p-8 text-3">PJT 모듈 로딩 중...</div>';
}

/* ── 수금 상태 필터 ── */
window.svFilterBills = function(){
    var tbl=document.getElementById('svBillTbl'); if(!tbl) return;
    var f=($('svBillFilter')||{}).value||'';
    tbl.querySelectorAll('tbody tr').forEach(function(tr){
        if(!f){ tr.style.display=''; return; }
        tr.style.display=(tr.textContent.indexOf(f)>-1)?'':'none';
    });
};

/* ── 첨부파일 업로드 ── */
window.svUploadAttach = function(){
    var inp=document.createElement('input');
    inp.type='file'; inp.multiple=true;
    inp.accept='.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.csv,.hwp';
    inp.onchange=function(){
        if(!inp.files.length) return;
        var fd=new FormData();
        fd.append('todo','upload_attach');
        fd.append('site_idx',_siteIdx);
        fd.append('category','site_attach');
        for(var i=0;i<inp.files.length;i++) fd.append('files[]',inp.files[i]);
        SHV.api.upload('dist_process/saas/Site.php',fd)
            .then(function(res){
                if(res.ok){
                    if(SHV.toast) SHV.toast.success('파일이 업로드되었습니다.');
                    _loaded.svTabAttach=false;
                    loadAttachments();
                } else {
                    if(SHV.toast) SHV.toast.error(res.message||'업로드 실패');
                }
            })
            .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
    };
    inp.click();
};

/* ══════════════════════════
   공용 삭제 확인 + API 호출
   ══════════════════════════ */
function svConfirmDel(title, todo, idx, tabKey, reloadFn){
    SHV.confirm({ title:title, message:'삭제하시겠습니까?', type:'danger', confirmText:'삭제',
        onConfirm:function(){
            SHV.api.post('dist_process/saas/Site.php',{todo:todo,idx:idx,site_idx:_siteIdx})
                .then(function(res){
                    if(res.ok){ if(SHV.toast) SHV.toast.success('삭제됨'); if(tabKey) _loaded[tabKey]=false; if(reloadFn) reloadFn(); }
                    else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
        }
    });
}

/* ══════════════════════════
   공용 CSV 내보내기
   ══════════════════════════ */
function svExportCsv(tblId, header, filename, colRange, minCells){
    var tbl=document.getElementById(tblId); if(!tbl){ if(SHV.toast) SHV.toast.warn('테이블이 없습니다.'); return; }
    var csv='\uFEFF'+header+'\n';
    tbl.querySelectorAll('tbody tr').forEach(function(tr){
        var cells=tr.querySelectorAll('td');
        if(cells.length<minCells) return;
        csv+=colRange.map(function(i){ return '"'+(cells[i]?cells[i].textContent:'').replace(/"/g,'""')+'"'; }).join(',')+'\n';
    });
    var a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    a.download=filename+'_'+new Date().toISOString().slice(0,10)+'.csv';
    a.click(); URL.revokeObjectURL(a.href);
    if(SHV.toast) SHV.toast.success('CSV 내보내기 완료');
}

window.svDelAttach = function(idx){ svConfirmDel('첨부파일 삭제','delete_attach',idx,'svTabAttach',loadAttachments); };

/* ── 연락처 숨김 토글 ── */
window.svToggleContactHidden = function(idx, hide){
    SHV.api.post('dist_process/saas/Site.php',{todo:'toggle_contact_hidden',idx:idx,site_idx:_siteIdx,is_hidden:hide?1:0})
        .then(function(res){
            if(res.ok){ if(SHV.toast) SHV.toast.success(hide?'숨김 처리됨':'숨김 해제됨'); _loaded.svTabContact=false; loadContacts(); }
            else { if(SHV.toast) SHV.toast.error(res.message||'처리 실패'); }
        }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

/* ── 도면 등록/삭제 ── */
window.svAddFloor = function(){
    var inp=document.createElement('input');
    inp.type='file'; inp.multiple=true;
    inp.accept='.pdf,.dwg,.dxf,.jpg,.jpeg,.png';
    inp.onchange=function(){
        if(!inp.files.length) return;
        var fd=new FormData();
        fd.append('todo','insert_floor_plan');
        fd.append('site_idx',_siteIdx);
        for(var i=0;i<inp.files.length;i++) fd.append('files[]',inp.files[i]);
        SHV.api.upload('dist_process/saas/Site.php',fd)
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('도면이 등록되었습니다.'); _loaded.svTabFloor=false; loadFloorPlans(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'등록 실패'); }
            }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
    };
    inp.click();
};
window.svDelFloor = function(idx){ svConfirmDel('도면 삭제','delete_floor_plan',idx,'svTabFloor',loadFloorPlans); };

/* ── 도급 등록/삭제 ── */
window.svAddSub = function(){
    svQuickForm('도급 등록',[
        {key:'company_name',label:'업체명',required:true},
        {key:'work_type',label:'공종'},
        {key:'contract_amount',label:'계약금액',type:'number'},
        {key:'memo',label:'비고'}
    ], function(data){
        data.todo='insert_subcontract';
        data.site_idx=_siteIdx;
        SHV.api.post('dist_process/saas/Site.php',data)
            .then(function(res){ if(res.ok){ if(SHV.toast) SHV.toast.success('도급이 등록되었습니다.'); _loaded.svTabSub=false; loadSubcontracts(); } else { if(SHV.toast) SHV.toast.error(res.message||'등록 실패'); }})
            .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
    });
};
window.svDelSub = function(idx){ svConfirmDel('도급 삭제','delete_subcontract',idx,'svTabSub',loadSubcontracts); };

/* ── 출입 등록/삭제 ── */
window.svAddAccess = function(){
    svQuickForm('출입 등록',[
        {key:'person_name',label:'이름',required:true},
        {key:'access_type',label:'유형'},
        {key:'in_time',label:'입장시간',type:'datetime-local'},
        {key:'memo',label:'비고'}
    ], function(data){
        data.todo='insert_access_log';
        data.site_idx=_siteIdx;
        SHV.api.post('dist_process/saas/Site.php',data)
            .then(function(res){ if(res.ok){ if(SHV.toast) SHV.toast.success('출입이 등록되었습니다.'); _loaded.svTabAccess=false; loadAccessLogs(); } else { if(SHV.toast) SHV.toast.error(res.message||'등록 실패'); }})
            .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
    });
};
window.svDelAccess = function(idx){ svConfirmDel('출입 삭제','delete_access_log',idx,'svTabAccess',loadAccessLogs); };

/* ── 간이 다중 필드 폼 모달 ── */
function svQuickForm(title, fields, onSubmit){
    var overlay=document.createElement('div');
    overlay.className='shv-prompt-overlay';
    var box=document.createElement('div');
    box.className='shv-prompt-box';
    var h='<div class="shv-prompt-title">'+escH(title)+'</div>';
    fields.forEach(function(f){
        var type=f.type||'text';
        h+='<div class="form-group mb-2">';
        h+='<label class="form-label text-xs">'+escH(f.label)+(f.required?' <span class="required">*</span>':'')+'</label>';
        h+='<input type="'+type+'" class="form-input form-input-sm" data-key="'+escH(f.key)+'"'+(f.required?' required':'')+' placeholder="'+escH(f.label)+'" value="'+escH(f.value||'')+'" autocomplete="off">';
        h+='</div>';
    });
    h+='<div class="shv-prompt-btns"><button class="shv-prompt-cancel" data-act="cancel">취소</button><button class="shv-prompt-ok" data-act="ok"><i class="fa fa-check mr-1"></i>확인</button></div>';
    box.innerHTML=h;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    var firstInput=box.querySelector('input'); if(firstInput) setTimeout(function(){ firstInput.focus(); },50);
    function close(){ overlay.remove(); }
    function submit(){
        var data={};
        var valid=true;
        box.querySelectorAll('input[data-key]').forEach(function(inp){
            var val=inp.value.trim();
            if(inp.required&&!val){ inp.focus(); valid=false; }
            data[inp.dataset.key]=val;
        });
        if(!valid) return;
        close();
        if(typeof onSubmit==='function') onSubmit(data);
    }
    box.querySelector('[data-act="ok"]').addEventListener('click',submit);
    box.querySelector('[data-act="cancel"]').addEventListener('click',close);
    overlay.addEventListener('click',function(e){ if(e.target===overlay) close(); });
    box.addEventListener('keydown',function(e){
        if(e.key==='Enter'){ e.preventDefault(); submit(); }
        if(e.key==='Escape') close();
    });
}

/* ── 테이블 검색 필터 ── */
window.svFilterTable = function(tblId, q){
    var tbl=document.getElementById(tblId); if(!tbl) return;
    q=(q||'').toLowerCase().trim();
    tbl.querySelectorAll('tbody tr').forEach(function(tr){ tr.style.display=(!q||tr.textContent.toLowerCase().indexOf(q)>-1)?'':'none'; });
};

/* ── 네비게이션 ── */
window.svBack = function(){ if(SHV&&SHV.router) SHV.router.navigate('site_new'); };
window.svGoMember = function(idx){ if(SHV&&SHV.router) SHV.router.navigate('member_branch_view',{member_idx:idx}); };
window.svCopyLink = function(){ var url=location.origin+location.pathname+'?r=site_view&site_idx='+_siteIdx; if(navigator.clipboard) navigator.clipboard.writeText(url).then(function(){ if(SHV.toast) SHV.toast.success('링크가 복사되었습니다.'); }); };
window.svCopyAddr = function(){ if(!_data) return; var a=(_data.address||'')+(_data.address_detail?' '+_data.address_detail:''); if(navigator.clipboard) navigator.clipboard.writeText(a).then(function(){ if(SHV.toast) SHV.toast.success('주소가 복사되었습니다.'); }); };
window.svOpenMap = function(){ if(_data&&_data.address) window.open('https://map.kakao.com/?q='+encodeURIComponent(_data.address),'_blank'); };
window.svToggleMapIframe = function(){
    var wrap=$('svMapIframe'); if(!wrap||!_data||!_data.address) return;
    var isHidden=wrap.classList.contains('hidden');
    wrap.classList.toggle('hidden');
    if(isHidden){
        var iframe=wrap.querySelector('iframe');
        if(iframe) iframe.src='https://map.kakao.com/?q='+encodeURIComponent(_data.address);
    }
};

/* ── 수정 ── */
window.svEdit = function(){ if(!_data) return; SHV.modal.open('views/saas/fms/site_add.php?todo=modify&idx='+_siteIdx,'현장 수정','lg'); SHV.modal.onClose(function(){ _data=null; _loaded={}; loadDetail(); }); };
window.svSettings = function(){ SHV.modal.open('views/saas/fms/site_settings.php?site_idx='+_siteIdx,'현장 설정','lg'); };

/* ── 삭제 ── */
window.svDelete = function(){
    if(!_data) return;
    SHV.confirm({ title:'현장 삭제', message:'\''+escH(_data.site_display_name||_data.name||_data.site_name||'')+'\' 현장을 삭제하시겠습니까?', type:'danger', confirmText:'삭제',
        onConfirm:function(){ SHV.api.post('dist_process/saas/Site.php',{todo:'delete',idx:_siteIdx}).then(function(res){ if(res.ok){ if(SHV.toast) SHV.toast.success('현장이 삭제되었습니다.'); svBack(); } else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }}).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); }); }
    });
};

/* ── 견적 등록/상세/삭제/복사/승인/재계산/PDF ── */
window.svAddEst = function(){ SHV.modal.open('views/saas/fms/est_add.php?site_idx='+_siteIdx+'&member_idx='+(_data.member_idx||0),'견적 등록','xl'); SHV.modal.onClose(function(){ _loaded.svTabEst=false; loadEstimates(); }); };
window.svViewEst = function(idx){ SHV.modal.open('views/saas/fms/est_add.php?todo=modify&idx='+idx+'&site_idx='+_siteIdx+'&member_idx='+(_data.member_idx||0),'견적 상세','xl'); SHV.modal.onClose(function(){ _loaded.svTabEst=false; loadEstimates(); }); };
window.svDelEst = function(idx){ svConfirmDel('견적 삭제','delete_estimate',idx,'svTabEst',function(){ loadEstimates(); loadDetail(); }); };
window.svCopyEst = function(idx){
    SHV.confirm({title:'견적 복사',message:'이 견적을 복사하시겠습니까?',confirmText:'복사',
        onConfirm:function(){
            SHV.api.post('dist_process/saas/Site.php',{todo:'copy_est',estimate_idx:idx,site_idx:_siteIdx})
                .then(function(r){if(r.ok){if(SHV.toast)SHV.toast.success('견적 복사됨');_loaded.svTabEst=false;loadEstimates();loadDetail();}else{if(SHV.toast)SHV.toast.error(r.message||'복사 실패');}})
                .catch(function(){if(SHV.toast)SHV.toast.error('네트워크 오류');});
        }
    });
};
window.svApproveEst = function(idx,status){
    SHV.api.post('dist_process/saas/Site.php',{todo:'approve_est',estimate_idx:idx,site_idx:_siteIdx,status:status})
        .then(function(r){if(r.ok){if(SHV.toast)SHV.toast.success(status==='APPROVED'?'승인됨':'반려됨');_loaded.svTabEst=false;loadEstimates();}else{if(SHV.toast)SHV.toast.error(r.message||'처리 실패');}})
        .catch(function(){if(SHV.toast)SHV.toast.error('네트워크 오류');});
};
window.svRecalcEst = function(idx){
    SHV.api.post('dist_process/saas/Site.php',{todo:'recalc_est',estimate_idx:idx,site_idx:_siteIdx})
        .then(function(r){if(r.ok){if(SHV.toast)SHV.toast.success('재계산 완료');_loaded.svTabEst=false;loadEstimates();}else{if(SHV.toast)SHV.toast.error(r.message||'실패');}})
        .catch(function(){if(SHV.toast)SHV.toast.error('네트워크 오류');});
};
window.svEstPdf = function(idx){
    window.open('dist_process/saas/Site.php?todo=est_pdf_data&estimate_idx='+idx+'&site_idx='+_siteIdx,'_blank');
};
window.svExportEst = function(){ svExportCsv('svEstTbl','견적번호,상태,공급가액,합계,등록일','견적목록',[1,2,3,4,5],6); };
var _estGroupMode=false;
window.svToggleEstGroup = function(){
    _estGroupMode=!_estGroupMode;
    var btn=$('svEstGroupBtn');
    if(btn) btn.classList.toggle('btn-primary',_estGroupMode);
    _loaded.svTabEst=false;
    loadEstimates();
};
window.svFilterEstStatus = function(){
    var tbl=document.getElementById('svEstTbl');if(!tbl)return;
    var f=($('svEstFilter')||{}).value||'';
    tbl.querySelectorAll('tbody tr').forEach(function(tr){if(!f){tr.style.display='';return;}tr.style.display=(tr.textContent.indexOf(f)>-1)?'':'none';});
};

/* ── 수금 등록/수정/입금/삭제 ── */
window.svAddBill = function(){ SHV.modal.open('views/saas/fms/bill_add.php?site_idx='+_siteIdx,'수금 등록','lg'); SHV.modal.onClose(function(){ _loaded.svTabBill=false; loadBills(); }); };
window.svEditBill = function(idx){
    SHV.modal.open('views/saas/fms/bill_add.php?mode=edit&idx='+idx+'&site_idx='+_siteIdx,'수금 수정','lg');
    SHV.modal.onClose(function(){ _loaded.svTabBill=false; loadBills(); });
};
window.svDepositBill = function(idx){
    SHV.modal.open('views/saas/fms/bill_add.php?mode=deposit&idx='+idx+'&site_idx='+_siteIdx,'입금 처리','lg');
    SHV.modal.onClose(function(){ _loaded.svTabBill=false; loadBills(); });
};
window.svDelBill = function(idx){ svConfirmDel('수금 삭제','delete_bill',idx,'svTabBill',loadBills); };
window.svExportBill = function(){ svExportCsv('svBillTbl','구분,금액,상태,날짜,비고','수금목록',[1,2,3,4,5],6); };

/* ── 연락처 등록/수정/삭제 ── */
window.svAddContact = function(){ SHV.modal.open('views/saas/fms/pb_add.php?site_idx='+_siteIdx,'연락처 등록','md'); SHV.modal.onClose(function(){ _loaded.svTabContact=false; loadContacts(); }); };
window.svEditContact = function(idx){ SHV.modal.open('views/saas/fms/pb_add.php?todo=modify&idx='+idx+'&site_idx='+_siteIdx,'연락처 수정','md'); SHV.modal.onClose(function(){ _loaded.svTabContact=false; loadContacts(); }); };
window.svDelContact = function(idx){ svConfirmDel('연락처 삭제','delete_contact',idx,'svTabContact',loadContacts); };

/* ── 도급 수정 (기존 데이터 로드 후 폼 표시) ── */
window.svEditSub = function(idx,btn){
    var tr=btn?btn.closest('tr'):null;
    var defs={company_name:tr?tr.dataset.cn:'',work_type:tr?tr.dataset.wt:'',contract_amount:tr?tr.dataset.ca:'',memo:tr?tr.dataset.mm:''};
    var fields=[
        {key:'company_name',label:'업체명',required:true,value:defs.company_name},
        {key:'work_type',label:'공종',value:defs.work_type},
        {key:'contract_amount',label:'계약금액',type:'number',value:defs.contract_amount},
        {key:'memo',label:'비고',value:defs.memo}
    ];
    svQuickForm('도급 수정',fields, function(data){
        data.todo='update_subcontract'; data.idx=idx; data.site_idx=_siteIdx;
        SHV.api.post('dist_process/saas/Site.php',data)
            .then(function(r){if(r.ok){if(SHV.toast)SHV.toast.success('수정됨');_loaded.svTabSub=false;loadSubcontracts();}else{if(SHV.toast)SHV.toast.error(r.message||'실패');}})
            .catch(function(){if(SHV.toast)SHV.toast.error('네트워크 오류');});
    });
};

/* ── 메일 작성 ── */
window.svComposeMail = function(){
    if(SHV.modal) SHV.modal.open('views/saas/mail/compose.php?site_idx='+_siteIdx,'메일 작성','lg');
};

/* ── 초기 로드 ── */
loadDetail();
})();
</script>
</section>
