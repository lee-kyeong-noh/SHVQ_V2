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

$memberIdx = (int)($_GET['member_idx'] ?? 0);
if ($memberIdx <= 0) {
    echo '<div class="empty-state"><p class="empty-message">잘못된 접근입니다.</p></div>';
    exit;
}
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<link rel="stylesheet" href="css/v2/detail-view.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/detail-view.css')?:'1' ?>">
<section data-page="member_branch_view" data-title="사업장 상세">

<!-- ── 헤더 카드 ── -->
<div class="dv-header" id="mvHeader">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="btn btn-ghost btn-sm" onclick="mvBack()" title="목록으로"><i class="fa fa-arrow-left"></i></button>
            <i class="fa fa-map-marker text-accent"></i>
            <span class="dv-title" id="mvTitle">로딩 중...</span>
            <span id="mvBadges"></span>
        </div>
        <div class="flex items-center gap-2">
            <button class="btn btn-outline btn-sm" onclick="mvCopyLink()"><i class="fa fa-link mr-1"></i>링크 복사</button>
            <button class="btn btn-outline btn-sm" onclick="mvSettings()"><i class="fa fa-cog mr-1"></i>설정</button>
            <button class="btn btn-glass-primary btn-sm" onclick="mvEdit()"><i class="fa fa-pencil mr-1"></i>수정</button>
            <button class="btn btn-ghost btn-sm text-danger" onclick="mvDelete()"><i class="fa fa-trash"></i></button>
        </div>
    </div>
    <div class="dv-sub-pills" id="mvPills"></div>
</div>

<!-- ── 요약 카드 ── -->
<div class="dv-summary" id="mvSummary">
    <div class="dv-sum-card"><div class="dv-sum-label">연결현장</div><div class="dv-sum-value" id="mvSumSite">-</div><div class="dv-sum-sub">사업장 소속 현장</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">연락처</div><div class="dv-sum-value" id="mvSumContact">-</div><div class="dv-sum-sub">등록된 연락처</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">현장소장</div><div class="dv-sum-value" id="mvSumFm">-</div><div class="dv-sum-sub">배정된 현장소장</div></div>
    <div class="dv-sum-card"><div class="dv-sum-label">견적</div><div class="dv-sum-value" id="mvSumEst">-</div><div class="dv-sum-sub">견적 현황</div></div>
</div>

<!-- ── 탭 ── -->
<div class="card flex-shrink-0">
    <div class="tab-bar" id="mvTabBar">
        <button class="tab-item tab-active" data-tab="mvTabInfo" onclick="mvTabSwitch(this)"><i class="fa fa-info-circle mr-1"></i>기본정보</button>
        <button class="tab-item" data-tab="mvTabSite" onclick="mvTabSwitch(this)">
            <i class="fa fa-building mr-1"></i>연결현장 <span class="tab-badge" id="mvSiteCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabContact" onclick="mvTabSwitch(this)">
            <i class="fa fa-address-book-o mr-1"></i>연락처 <span class="tab-badge" id="mvContactCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabFm" onclick="mvTabSwitch(this)">
            <i class="fa fa-user-circle-o mr-1"></i>현장소장 <span class="tab-badge" id="mvFmCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabMemo" onclick="mvTabSwitch(this)"><i class="fa fa-bookmark mr-1"></i>특기사항</button>
        <button class="tab-item" data-tab="mvTabEst" onclick="mvTabSwitch(this)">
            <i class="fa fa-calculator mr-1"></i>견적현황 <span class="tab-badge" id="mvEstCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabBill" onclick="mvTabSwitch(this)">
            <i class="fa fa-krw mr-1"></i>수금현황 <span class="tab-badge" id="mvBillCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabMail" onclick="mvTabSwitch(this)">
            <i class="fa fa-envelope mr-1"></i>메일 <span class="tab-badge" id="mvMailCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabFile" onclick="mvTabSwitch(this)">
            <i class="fa fa-paperclip mr-1"></i>첨부 <span class="tab-badge" id="mvFileCnt">0</span>
        </button>
        <button class="tab-item" data-tab="mvTabOrg" onclick="mvTabSwitch(this)"><i class="fa fa-sitemap mr-1"></i>조직도</button>
    </div>
</div>

<!-- ── 기본정보 탭 ── -->
<div class="card card-fill" id="mvTabInfo">
    <div class="dv-info-body" id="mvInfoBody">
        <div class="text-center p-8 text-3">로딩 중...</div>
    </div>
</div>

<!-- ── 연결현장 탭 ── -->
<div class="card card-fill hidden" id="mvTabSite">
    <div class="flex items-center gap-2 flex-wrap px-4 py-3">
        <input type="text" id="mvSiteSearch" class="form-input flex-1" placeholder="현장명, 주소 검색..."
            oninput="mvSiteFilter()">
        <button id="mvSiteClosedBtn" class="btn btn-outline btn-sm text-xs" onclick="mvSiteToggleClosed()">
            <i class="fa fa-archive mr-1"></i>마감 포함
        </button>
        <button id="mvSiteDetailBtn" class="btn btn-ghost btn-sm text-xs" onclick="mvSiteToggleDetail()">
            <i class="fa fa-sliders mr-1"></i>상세
        </button>
        <span class="text-xs text-3" id="mvSiteInfo"></span>
    </div>
    <div id="mvSiteDetailPanel" class="hidden px-4 py-2" style="background:var(--accent-10);border-bottom:1px solid var(--border);">
        <div class="flex flex-wrap gap-2 items-end">
            <div class="flex flex-col gap-1"><label class="text-xs text-3 font-semibold">상태</label>
                <select id="sf_status" class="form-select text-xs" onchange="mvSiteFilter()">
                    <option value="">전체</option><option value="0">진행전</option><option value="1">진행</option><option value="2">준공</option><option value="3">마감</option>
                </select>
            </div>
            <div class="flex flex-col gap-1"><label class="text-xs text-3 font-semibold">담당자</label>
                <input type="text" id="sf_emp" class="form-input text-xs" placeholder="담당자명" oninput="mvSiteFilter()">
            </div>
            <div class="flex flex-col gap-1"><label class="text-xs text-3 font-semibold">담당부서</label>
                <input type="text" id="sf_team" class="form-input text-xs" placeholder="부서명" oninput="mvSiteFilter()">
            </div>
            <button class="btn btn-ghost btn-sm text-xs" onclick="mvSiteResetFilter()"><i class="fa fa-refresh mr-1"></i>초기화</button>
        </div>
    </div>
    <div id="mvSiteBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 연락처 탭 ── -->
<div class="card card-fill hidden" id="mvTabContact">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="mvContactSearch" class="form-input flex-1" placeholder="이름, 전화번호, 이메일 검색..."
            oninput="mvFilterTable('mvContactTbl',this.value)">
        <div class="flex items-center gap-2 ml-2">
            <span class="text-xs text-3" id="mvContactInfo"></span>
            <button class="btn btn-ghost btn-sm text-xs" id="mvContactHiddenBtn" onclick="mvContactToggleHidden()" title="숨김 연락처">
                <i class="fa fa-eye-slash mr-1"></i>숨김
            </button>
            <button class="btn btn-glass-primary btn-sm" onclick="mvPbAdd()"><i class="fa fa-plus mr-1"></i>등록</button>
        </div>
    </div>
    <div id="mvContactBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 현장소장 탭 ── -->
<div class="card card-fill hidden" id="mvTabFm">
    <div class="flex items-center justify-between px-4 py-3">
        <span class="text-sm font-semibold text-1">현장소장 목록</span>
        <button class="btn btn-glass-primary btn-sm" onclick="mvFmAdd()"><i class="fa fa-plus mr-1"></i>등록</button>
    </div>
    <div id="mvFmBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 특기사항 탭 (SHV.chat 공용 모듈) ── -->
<div class="card card-fill hidden" id="mvTabMemo">
    <div id="mvMemoChat" class="p-4"></div>
</div>

<!-- ── 견적현황 탭 ── -->
<div class="card card-fill hidden" id="mvTabEst">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="mvEstSearch" class="form-input flex-1" placeholder="현장명, 견적명 검색..."
            oninput="mvFilterTable('mvEstTbl',this.value)">
        <span class="text-xs text-3 ml-2" id="mvEstInfo"></span>
    </div>
    <div id="mvEstBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 수금현황 탭 ── -->
<div class="card card-fill hidden" id="mvTabBill">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="mvBillSearch" class="form-input flex-1" placeholder="견적명, 담당자 검색..."
            oninput="mvFilterTable('mvBillTbl',this.value)">
        <span class="text-xs text-3 ml-2" id="mvBillInfo"></span>
    </div>
    <div id="mvBillBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 메일 탭 ── -->
<div class="card card-fill hidden" id="mvTabMail">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="mvMailSearch" class="form-input flex-1" placeholder="제목, 발신자, 수신자 검색..."
            oninput="mvFilterTable('mvMailTbl',this.value)">
        <span class="text-xs text-3 ml-2" id="mvMailInfo"></span>
    </div>
    <div id="mvMailBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 첨부 탭 ── -->
<div class="card card-fill hidden" id="mvTabFile">
    <div class="flex items-center justify-between px-4 py-3">
        <input type="text" id="mvFileSearch" class="form-input flex-1" placeholder="파일명 검색..."
            oninput="mvFilterTable('mvFileTbl',this.value)">
        <span class="text-xs text-3 ml-2" id="mvFileInfo"></span>
    </div>
    <div id="mvFileBody"><div class="text-center p-8 text-3">로딩 중...</div></div>
</div>

<!-- ── 조직도 탭 ── -->
<div class="card card-fill hidden" id="mvTabOrg">
    <div id="mvOrgBody"><div class="text-center p-8 text-3"><i class="fa fa-sitemap text-4xl opacity-30"></i><p class="mt-2">조직도 준비 중</p></div></div>
</div>

<script>
(function(){
'use strict';

var _memberIdx = <?= $memberIdx ?>;
var _data = null;
var _tabKey = 'mvTab_'+_memberIdx;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtBizNo(s){ var n=String(s||'').replace(/\D/g,''); if(n.length===10) return n.slice(0,3)+'-'+n.slice(3,5)+'-'+n.slice(5); return s||''; }
function fmtPhone(s){
    var n=String(s||'').replace(/[^0-9]/g,'');
    if(n.length===11) return n.replace(/(\d{3})(\d{4})(\d{4})/,'$1-$2-$3');
    if(n.length===10) return n.replace(/(\d{3})(\d{3})(\d{4})/,'$1-$2-$3');
    if(n.length===9)  return n.replace(/(\d{2})(\d{3})(\d{4})/,'$1-$2-$3');
    return s||'';
}
function statusBadge(s){
    if(!s) return '';
    return '<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>';
}

/* rowItem 헬퍼 (detail-view.css 패턴) */
function rowItem(label, value, cls, span, editOpts){
    var empty = '<span class="text-3">-</span>';
    var spanCls = span ? ' dv-span-'+span : '';
    var editAttr = '';
    if(editOpts && editOpts.field){
        editAttr = ' class="dv-row-value dv-editable'+(cls?' '+cls:'')+'" data-field="'+editOpts.field+'"';
    } else {
        editAttr = ' class="dv-row-value'+(cls?' '+cls:'')+'"';
    }
    return '<div class="dv-row-item'+spanCls+'">'
        +'<span class="dv-row-label">'+escH(label)+'</span>'
        +'<span'+editAttr+'>'+(value?escH(value):empty)+'</span>'
        +'</div>';
}

/* ══════════════════════════════════════
   인라인 편집 시스템 (head_view.php 패턴)
   ══════════════════════════════════════ */
var _editCfg = {
    name:                 {type:'text'},
    ceo:                  {type:'text'},
    card_number:          {type:'text', format:fmtBizNo, mono:true},
    business_type:        {type:'text'},
    business_class:       {type:'text'},
    tel:                  {type:'text', format:fmtPhone},
    hp:                   {type:'text', format:fmtPhone},
    email:                {type:'text'},
    cooperation_contract: {type:'select', options:['','정식','임시','해지']},
    manager_name:         {type:'text'},
    memo:                 {type:'textarea'},
    employee_name:        {type:'search', apiField:'employee_idx'}
};

function mvInline(el){
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
        var dd = document.createElement('div');
        dd.className = 'dv-search-dropdown hidden';
        var timer = null;
        input.addEventListener('input', function(){
            clearTimeout(timer);
            var q = input.value.trim();
            if(q.length<1){ dd.classList.add('hidden'); return; }
            timer = setTimeout(function(){
                SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list'})
                    .then(function(res){
                        if(!res.ok) return;
                        var list = (res.data.data||[]).filter(function(e){ return e.name.toLowerCase().indexOf(q.toLowerCase())>-1; });
                        if(!list.length){ dd.classList.add('hidden'); return; }
                        dd.innerHTML='';
                        list.slice(0,10).forEach(function(emp){
                            var item=document.createElement('div');
                            item.className='dv-search-item';
                            item.textContent=emp.name+(emp.work?' ('+emp.work+')':'');
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
                    })
                    .catch(function(){ dd.classList.add('hidden'); });
            },300);
        });
        el.style.position='relative';
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

        var postData = {todo:'update', idx:_memberIdx};
        if(cfg.type==='search' && input._empIdx){
            postData[cfg.apiField||field] = input._empIdx;
            postData.employee_name = newVal;
        } else {
            postData[field] = apiVal;
        }

        SHV.api.post('dist_process/saas/Member.php', postData)
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
    input.addEventListener('blur',function(){ if(!saving) setTimeout(save,100); });
}

function syncHeader(){ renderHeader(_data); }

/* 이벤트 위임: 인라인 편집 */
document.addEventListener('click',function(e){
    var el=e.target.closest('.dv-editable[data-field]');
    if(el&&el.closest('[data-page="member_branch_view"]')){
        e.preventDefault(); e.stopPropagation();
        mvInline(el);
    }
});

/* ── 탭 전환 + sessionStorage ── */
var _tabs = ['mvTabInfo','mvTabSite','mvTabContact','mvTabFm','mvTabMemo','mvTabEst','mvTabBill','mvTabMail','mvTabFile','mvTabOrg'];

window.mvTabSwitch = function(btn){
    document.querySelectorAll('#mvTabBar .tab-item').forEach(function(t){ t.classList.remove('tab-active'); });
    btn.classList.add('tab-active');
    var target = btn.dataset.tab;
    _tabs.forEach(function(id){ var el=$(id); if(el) el.classList.toggle('hidden', id!==target); });
    sessionStorage.setItem(_tabKey, target);
    if(target==='mvTabSite') loadSites();
    if(target==='mvTabContact') loadContacts();
    if(target==='mvTabFm') loadFieldManagers();
    if(target==='mvTabMemo' && !window._mvChatInit){ window._mvChatInit=true; SHV.chat.init('mvMemoChat','Tb_Members',_memberIdx); }
    if(target==='mvTabEst') loadEstimates();
    if(target==='mvTabBill') loadBills();
    if(target==='mvTabMail') loadMails();
    if(target==='mvTabFile') loadFiles();
    if(target==='mvTabOrg') loadOrgChart();
};

function restoreTab(){
    var saved = sessionStorage.getItem(_tabKey);
    if(!saved) return;
    var btns = document.querySelectorAll('#mvTabBar .tab-item');
    for(var i=0;i<btns.length;i++){ if(btns[i].dataset.tab===saved){ mvTabSwitch(btns[i]); break; } }
}

/* ── 사업장 상세 로드 ── */
function loadDetail(){
    SHV.api.get('dist_process/saas/Member.php', {todo:'detail', idx:_memberIdx})
        .then(function(res){
            if(!res.ok){ if(SHV.toast) SHV.toast.error(res.message||'사업장 조회 실패'); return; }
            _data = res.data;
            renderHeader(_data);
            renderInfo(_data);
            loadSummaryCounts();
            restoreTab();
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 요약 카운트 로드 ── */
function loadSummaryCounts(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'list',member_idx:_memberIdx,limit:1})
        .then(function(res){ if(res.ok){ var t=res.data.total||0; $('mvSumSite').textContent=t; $('mvSiteCnt').textContent=t; } }).catch(function(){});
    SHV.api.get('dist_process/saas/PhoneBook.php',{todo:'list',member_idx:_memberIdx,limit:1})
        .then(function(res){ if(res.ok){ var t=res.data.total||0; $('mvSumContact').textContent=t; $('mvContactCnt').textContent=t; } }).catch(function(){});
    SHV.api.get('dist_process/saas/FieldManager.php',{todo:'list',member_idx:_memberIdx,limit:1})
        .then(function(res){ if(res.ok){ var t=res.data.total||0; $('mvSumFm').textContent=t; $('mvFmCnt').textContent=t; } }).catch(function(){});
}

/* ── 헤더 렌더링 ── */
function renderHeader(d){
    var title=$('mvTitle');
    if(title) title.innerHTML='<span class="dv-editable" data-field="name">'+escH(d.name||'사업장 상세')+'</span>';

    var status = d.member_status || d.status || '';
    var badges='';
    if(status) badges+=statusBadge(status);
    if(d.group_name) badges+='<span class="dv-pill">'+escH(d.group_name)+'</span>';
    var badgeEl=$('mvBadges'); if(badgeEl) badgeEl.innerHTML=badges;

    var pills='';
    if(d.head_name&&d.head_idx) pills+='<span class="dv-pill cursor-pointer" onclick="mvGoHead('+d.head_idx+')"><i class="fa fa-building-o mr-1 text-3"></i>'+escH(d.head_name)+'</span>';
    pills+='<span class="dv-pill"><i class="fa fa-user mr-1 text-3"></i><span class="dv-editable" data-field="ceo">'+escH(d.ceo||'-')+'</span></span>';
    pills+='<span class="dv-pill"><i class="fa fa-phone mr-1 text-3"></i><span class="dv-editable" data-field="tel">'+escH(d.tel||'-')+'</span></span>';
    if(d.employee_name) pills+='<span class="dv-pill"><i class="fa fa-briefcase mr-1 text-3"></i><span class="dv-editable" data-field="employee_name">'+escH(d.employee_name)+'</span></span>';
    var pillEl=$('mvPills'); if(pillEl) pillEl.innerHTML=pills;
}

/* ── 기본정보 렌더링 ── */
function renderInfo(d){
    var empty = '<span class="text-3">-</span>';
    var html = '';

    /* 주소 */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-addr-compact">';
    html+='<i class="fa fa-map-marker text-accent"></i>';
    html+='<span class="dv-addr-text">';
    if(d.zipcode) html+='<span class="dv-zip">'+escH(d.zipcode)+'</span> ';
    html+=escH(d.address||'-');
    if(d.address_detail) html+=' '+escH(d.address_detail);
    html+='</span>';
    if(d.address){
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="event.stopPropagation();mvShowMap(\''+escH(d.address||'').replace(/'/g,"\\'")+'\')" title="지도"><i class="fa fa-map-marker mr-1"></i>지도</button>';
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="event.stopPropagation();navigator.clipboard.writeText(\''+escH(d.address||'').replace(/'/g,"\\'")+'\').then(function(){if(SHV.toast)SHV.toast.success(\'주소 복사됨\')})" title="복사"><i class="fa fa-copy mr-1"></i>복사</button>';
    }
    html+='</div></div>';

    /* 기본 정보 */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-section-title">기본 정보</div>';
    html+='<div class="dv-row-grid">';
    html+=rowItem('사업장명', d.name, '', 2, {field:'name'});
    html+=rowItem('사업자번호', d.card_number?fmtBizNo(d.card_number):'', 'dv-mono', 2, {field:'card_number'});
    html+=rowItem('대표자', d.ceo, '', 2, {field:'ceo'});
    html+=rowItem('고객번호', d.member_number, '', 2);
    html+=rowItem('업태', d.business_type||d.biztype, '', 2, {field:'business_type'});
    html+=rowItem('업종', d.business_class||d.bizclass, '', 2, {field:'business_class'});
    html+='</div></div>';

    /* 연락처 정보 */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-section-title">연락처</div>';
    html+='<div class="dv-row-grid">';
    html+=rowItem('전화', d.tel, '', 2, {field:'tel'});
    html+=rowItem('휴대폰', d.hp, '', 2, {field:'hp'});
    html+=rowItem('팩스', d.fax, '', 2);
    html+=rowItem('이메일', d.email, '', 2, {field:'email'});
    html+='</div></div>';

    /* 담당 · 계약 */
    html+='<div class="dv-info-section">';
    html+='<div class="dv-section-title">담당 · 계약</div>';
    html+='<div class="dv-row-grid">';
    html+=rowItem('담당자', d.employee_name, '', 2, {field:'employee_name'});
    html+=rowItem('담당자명', d.manager_name, '', 2, {field:'manager_name'});
    html+=rowItem('협력계약', d.cooperation_contract, '', 2, {field:'cooperation_contract'});
    html+=rowItem('권역', d.region||d.region_name, '', 2);
    html+=rowItem('등록일', fmtDate(d.registered_date||d.regdate||d.created_at), '', 2);
    html+=rowItem('등록자', d.reg_emp_name||d.register_name, '', 2);
    html+='</div></div>';

    /* 본사 연결 */
    if(d.head_name){
        html+='<div class="dv-info-section">';
        html+='<div class="dv-section-title">본사 연결</div>';
        html+='<div class="dv-row-grid">';
        html+='<div class="dv-row-item dv-span-3"><span class="dv-row-label">본사</span><span class="dv-row-value"><a class="mb-head-link" onclick="mvGoHead('+d.head_idx+')">'+escH(d.head_name)+'</a></span></div>';
        html+=rowItem('연결상태', d.link_status, '', 3);
        html+='</div></div>';
    }

    /* 사용견적 */
    var ueHtml = '';
    try {
        var ue = d.used_estimate || d.use_estimate_idxs || '';
        if(ue){
            var arr = typeof ue==='string' ? JSON.parse(ue) : ue;
            if(Array.isArray(arr)){
                arr.forEach(function(u){ ueHtml+='<span class="dv-ue-badge">'+escH(u.name||u)+'</span>'; });
            }
        }
    } catch(e){}
    if(ueHtml){
        html+='<div class="dv-info-section">';
        html+='<div class="dv-section-title">사용견적</div>';
        html+='<div class="p-3">'+ueHtml+'</div>';
        html+='</div>';
    }

    /* 메모 */
    if(d.memo){
        html+='<div class="dv-info-section dv-section-highlight">';
        html+='<div class="dv-section-title">메모</div>';
        html+='<div class="dv-desc-value dv-editable" data-field="memo">'+escH(d.memo)+'</div>';
        html+='</div>';
    }

    var body=$('mvInfoBody');
    if(body) body.innerHTML=html||'<div class="text-center p-8 text-3">정보가 없습니다.</div>';
}

/* ══════════════════════════════════════
   연결현장 탭 (마감토글 + 상세필터 + 총수량)
   ══════════════════════════════════════ */
var _siteLoaded = false;
var _siteAllRows = [];
var _siteIncludeClosed = false;

function loadSites(){
    if(_siteLoaded) return;
    _siteLoaded = true;

    SHV.api.get('dist_process/saas/Site.php', {todo:'list', member_idx:_memberIdx, limit:500, include_closed:1})
        .then(function(res){
            if(!res.ok){ $('mvSiteBody').innerHTML='<div class="text-center p-8 text-3">현장 조회 실패</div>'; return; }
            _siteAllRows = res.data.data||[];
            mvSiteFilter();
        })
        .catch(function(){ $('mvSiteBody').innerHTML='<div class="text-center p-8 text-3">네트워크 오류</div>'; });
}

window.mvSiteToggleClosed = function(){
    _siteIncludeClosed = !_siteIncludeClosed;
    var btn=$('mvSiteClosedBtn');
    if(btn){
        btn.classList.toggle('btn-primary', _siteIncludeClosed);
        btn.classList.toggle('btn-outline', !_siteIncludeClosed);
    }
    mvSiteFilter();
};

window.mvSiteToggleDetail = function(){
    var panel=$('mvSiteDetailPanel');
    if(panel) panel.classList.toggle('hidden');
    var btn=$('mvSiteDetailBtn');
    if(btn) btn.classList.toggle('btn-primary');
};

window.mvSiteResetFilter = function(){
    var s=$('sf_status'); if(s) s.value='';
    var e=$('sf_emp'); if(e) e.value='';
    var t=$('sf_team'); if(t) t.value='';
    mvSiteFilter();
};

window.mvSiteFilter = function(){
    var q = ($('mvSiteSearch')||{value:''}).value.toLowerCase().trim();
    var sfStatus = ($('sf_status')||{value:''}).value;
    var sfEmp = ($('sf_emp')||{value:''}).value.toLowerCase().trim();
    var sfTeam = ($('sf_team')||{value:''}).value.toLowerCase().trim();

    var filtered = _siteAllRows.filter(function(r){
        var status = r.deadline_status||r.site_status||r.status||'';
        /* 마감 제외 (deadline_status=3) */
        if(!_siteIncludeClosed && String(status)==='3') return false;
        /* 상세필터 */
        if(sfStatus && String(status)!==sfStatus) return false;
        if(sfEmp){
            var empName = (r.employee_name||r.emp_name||'').toLowerCase();
            if(empName.indexOf(sfEmp)===-1) return false;
        }
        if(sfTeam){
            var team = (r.target_team||r.department||'').toLowerCase();
            if(team.indexOf(sfTeam)===-1) return false;
        }
        /* 검색 */
        if(q){
            var text = ((r.name||'')+(r.site_name||'')+(r.address||'')+(r.employee_name||'')).toLowerCase();
            if(text.indexOf(q)===-1) return false;
        }
        return true;
    });

    $('mvSiteInfo').textContent = filtered.length+'건'+(filtered.length!==_siteAllRows.length?' / 전체 '+_siteAllRows.length+'건':'');
    renderSites(filtered);
};

function renderSites(rows){
    var body=$('mvSiteBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-building"></i></div><p class="text-3 mt-2">등록된 현장이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvSiteTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>현장명</th><th>상태</th><th>담당자</th><th>담당부서</th><th>총수량</th><th>주소</th><th>착공일</th><th>완공일</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        var name = r.site_display_name || r.name || r.site_name || '';
        var status = r.site_status || r.status || '';
        var dsStatus = r.deadline_status||'';
        var statusLabel = {0:'진행전',1:'진행',2:'준공',3:'마감'}[dsStatus]||status;
        html+='<tr class="cursor-pointer" onclick="mvGoSite('+r.idx+')">'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td><b class="text-1">'+escH(name)+'</b></td>'
            +'<td>'+statusBadge(statusLabel)+'</td>'
            +'<td>'+escH(r.employee_name||r.emp_name||'')+'</td>'
            +'<td>'+escH(r.target_team||r.department||'')+'</td>'
            +'<td class="td-no">'+(r.total_qty||r.total_quantity||'')+'</td>'
            +'<td class="truncate max-w-200" title="'+escH(r.address||'')+'">'+escH(r.address||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.construction_date)+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.completion_date)+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   연락처 탭 (숨김토글 + 폴더 표시)
   ══════════════════════════════════════ */
var _contactLoaded = false;
var _contactShowHidden = false;

function loadContacts(){
    if(_contactLoaded) return;
    _contactLoaded = true;

    SHV.api.get('dist_process/saas/PhoneBook.php', {todo:'list', member_idx:_memberIdx, limit:500, include_hidden:1})
        .then(function(res){
            if(!res.ok){ $('mvContactBody').innerHTML='<div class="text-center p-8 text-3">연락처 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||0;
            $('mvContactInfo').textContent=total+'건';
            window._contactAllRows = rows;
            renderContacts(rows);
        })
        .catch(function(){ $('mvContactBody').innerHTML='<div class="text-center p-8 text-3">네트워크 오류</div>'; });
}

window.mvContactToggleHidden = function(){
    _contactShowHidden = !_contactShowHidden;
    var btn=$('mvContactHiddenBtn');
    if(btn){
        btn.classList.toggle('btn-primary', _contactShowHidden);
        btn.classList.toggle('btn-ghost', !_contactShowHidden);
    }
    if(window._contactAllRows) renderContacts(window._contactAllRows);
};

function renderContacts(rows){
    var body=$('mvContactBody'); if(!body) return;
    var filtered = _contactShowHidden ? rows : rows.filter(function(r){ return !parseInt(r.is_hidden||0); });
    if(!filtered.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-address-book-o"></i></div><p class="text-3 mt-2">등록된 연락처가 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvContactTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>이름</th><th>소속/부서</th><th>직급/직책</th><th>연락처</th><th>이메일</th><th>폴더</th><th>비고</th><th></th>'
        +'</tr></thead><tbody>';
    filtered.forEach(function(r,i){
        var hidden = parseInt(r.is_hidden||0);
        html+='<tr'+(hidden?' class="opacity-50"':'')+'>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td><b class="text-1">'+escH(r.name||'')+'</b></td>'
            +'<td>'+escH(r.sosok||'')+'</td>'
            +'<td>'+escH((r.job_grade||'')+(r.job_title&&r.job_title!=='없음'?' / '+r.job_title:''))+'</td>'
            +'<td class="whitespace-nowrap">'+(r.hp?'<a href="tel:'+escH(r.hp)+'" class="text-accent">'+escH(r.hp)+'</a>':'')+'</td>'
            +'<td>'+escH(r.email||'')+'</td>'
            +'<td class="text-xs">'+escH(r.folder_name||'')+'</td>'
            +'<td>'+escH(r.comment||r.memo||'')+'</td>'
            +'<td class="whitespace-nowrap">'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="mvPbToggleHide('+r.idx+','+(hidden?0:1)+')" title="'+(hidden?'숨김 해제':'숨김')+'"><i class="fa fa-eye'+(hidden?'':'-slash')+'"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="mvPbDelete('+r.idx+')"><i class="fa fa-trash text-danger"></i></button>'
            +'</td></tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

window.mvPbToggleHide = function(idx, hidden){
    SHV.api.post('dist_process/saas/PhoneBook.php', {todo:'toggle_hidden', idx:idx, is_hidden:hidden})
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(hidden?'숨김 처리됨':'숨김 해제됨');
                _contactLoaded=false; loadContacts();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

/* ══════════════════════════════════════
   현장소장 탭
   ══════════════════════════════════════ */
var _fmLoaded = false;

function loadFieldManagers(){
    if(_fmLoaded) return;
    _fmLoaded = true;

    SHV.api.get('dist_process/saas/FieldManager.php', {todo:'list', member_idx:_memberIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('mvFmBody').innerHTML='<div class="text-center p-8 text-3">현장소장 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||0;
            $('mvFmCnt').textContent=total;
            renderFieldManagers(rows);
        })
        .catch(function(){ $('mvFmBody').innerHTML='<div class="text-center p-8 text-3">네트워크 오류</div>'; });
}

function renderFieldManagers(rows){
    var body=$('mvFmBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-user-circle-o"></i></div><p class="text-3 mt-2">등록된 현장소장이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl"><thead><tr>'
        +'<th class="th-center">No</th><th>성명</th><th>소속/부서</th><th>직급/직책</th><th>연락처</th><th>이메일</th><th>비고</th><th></th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        html+='<tr>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td><b class="text-1">'+escH(r.name||'')+'</b></td>'
            +'<td>'+escH(r.sosok||'')+'</td>'
            +'<td>'+escH(r.part||'')+'</td>'
            +'<td class="whitespace-nowrap">'+(r.hp?'<a href="tel:'+escH(r.hp)+'" class="text-accent">'+escH(r.hp)+'</a>':'')+'</td>'
            +'<td>'+escH(r.email||'')+'</td>'
            +'<td>'+escH(r.comment||'')+'</td>'
            +'<td class="whitespace-nowrap">'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="mvFmDelete('+r.idx+')"><i class="fa fa-trash text-danger"></i></button>'
            +'</td></tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   견적현황 탭
   ══════════════════════════════════════ */
var _estLoaded = false;

function loadEstimates(){
    if(_estLoaded) return;
    _estLoaded = true;

    SHV.api.get('dist_process/saas/Site.php', {todo:'est_list', member_idx:_memberIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('mvEstBody').innerHTML='<div class="text-center p-8 text-3">견적 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||rows.length;
            $('mvEstInfo').textContent=total+'건';
            $('mvEstCnt').textContent=total;
            $('mvSumEst').textContent=total;
            renderEstimates(rows);
        })
        .catch(function(){ $('mvEstBody').innerHTML='<div class="text-center p-8 text-3">네트워크 오류</div>'; });
}

function renderEstimates(rows){
    var body=$('mvEstBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-calculator"></i></div><p class="text-3 mt-2">등록된 견적이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvEstTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>견적명</th><th>현장명</th><th>수주액</th><th>진행상태</th><th>등록일</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        html+='<tr>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td><b class="text-1">'+escH(r.est_name||r.name||'')+'</b></td>'
            +'<td>'+escH(r.site_name||'')+'</td>'
            +'<td class="whitespace-nowrap">'+(r.total_amount?Number(r.total_amount).toLocaleString()+'원':'')+'</td>'
            +'<td>'+statusBadge(r.est_status||r.status||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.registered_date||r.regdate||r.created_at)+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   수금현황 탭
   ══════════════════════════════════════ */
var _billLoaded = false;

function loadBills(){
    if(_billLoaded) return;
    _billLoaded = true;

    SHV.api.get('dist_process/saas/Member.php', {todo:'bill_list', member_idx:_memberIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('mvBillBody').innerHTML='<div class="text-center p-8 text-3">수금 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||rows.length;
            $('mvBillInfo').textContent=total+'건';
            $('mvBillCnt').textContent=total;
            renderBills(rows);
        })
        .catch(function(){ $('mvBillBody').innerHTML='<div class="text-center p-8 text-3">API 준비 중</div>'; });
}

function renderBills(rows){
    var body=$('mvBillBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-krw"></i></div><p class="text-3 mt-2">수금 내역이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvBillTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>견적명</th><th>회차</th><th>청구금액</th><th>입금액</th><th>발행일</th><th>입금일</th><th>상태</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        html+='<tr>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td><b class="text-1">'+escH(r.est_name||r.name||'')+'</b></td>'
            +'<td class="td-no">'+(r.bill_no||r.seq||'')+'</td>'
            +'<td class="whitespace-nowrap">'+(r.bill_amount?Number(r.bill_amount).toLocaleString()+'원':'')+'</td>'
            +'<td class="whitespace-nowrap">'+(r.paid_amount?Number(r.paid_amount).toLocaleString()+'원':'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.bill_date)+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.paid_date)+'</td>'
            +'<td>'+statusBadge(r.bill_status||r.status||'')+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   테이블 검색 필터 공용
   ══════════════════════════════════════ */
window.mvFilterTable = function(tblId, q){
    var tbl=document.getElementById(tblId); if(!tbl) return;
    q=(q||'').toLowerCase().trim();
    var rows=tbl.querySelectorAll('tbody tr');
    var cnt=0;
    rows.forEach(function(tr){
        var text=tr.textContent.toLowerCase();
        var match=!q||text.indexOf(q)>-1;
        tr.style.display=match?'':'none';
        if(match) cnt++;
    });
};

/* ══════════════════════════════════════
   네비게이션 + 액션
   ══════════════════════════════════════ */
window.mvBack = function(){
    if(SHV&&SHV.router) SHV.router.navigate('member_branch');
};
window.mvGoSite = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('site_view',{site_idx:idx});
};
window.mvGoHead = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('head_view',{head_idx:idx});
};

window.mvCopyLink = function(){
    var url=window.location.origin+window.location.pathname+'?r=member_branch_view&member_idx='+_memberIdx;
    navigator.clipboard.writeText(url).then(function(){ if(SHV.toast) SHV.toast.success('링크가 복사되었습니다.'); });
};

window.mvSettings = function(){
    SHV.modal.open('views/saas/fms/branch_settings.php?member_idx='+_memberIdx, '사업장 설정', 'lg');
    SHV.modal.onClose(function(){ _data=null; _siteLoaded=false; _contactLoaded=false; _fmLoaded=false; _estLoaded=false; _billLoaded=false; _mailLoaded=false; _fileLoaded=false; _orgLoaded=false; loadDetail(); });
};

window.mvEdit = function(){
    if(!_data) return;
    SHV.modal.open('views/saas/fms/member_branch_add.php?todo=modify&idx='+_memberIdx, '사업장 수정', 'lg');
    SHV.modal.onClose(function(){ _data=null; loadDetail(); });
};

window.mvDelete = function(){
    if(!_data) return;
    if(window.SHV && SHV.confirm){
        SHV.confirm({
            title: '사업장 삭제',
            message: '\''+escH(_data.name)+'\' 사업장을 삭제하시겠습니까?',
            type: 'danger',
            confirmText: '삭제',
            onConfirm: function(){
                SHV.api.post('dist_process/saas/Member.php', {todo:'member_delete', idx:_memberIdx})
                    .then(function(res){
                        if(res.ok){
                            if(SHV.toast) SHV.toast.success('사업장이 삭제되었습니다.');
                            mvBack();
                        } else {
                            if(SHV.toast) SHV.toast.error(res.message||'삭제 실패');
                        }
                    })
                    .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
            }
        });
    }
};

/* ── 지도보기 ── */
window.mvShowMap = function(addr){
    if(!addr) return;
    var encoded = encodeURIComponent(addr);
    var html='<div class="shv-map-overlay" onclick="if(event.target===this)this.remove()">'
        +'<div class="shv-map-box">'
        +'<div class="shv-map-header">'
        +'<span class="shv-map-addr">'+escH(addr)+'</span>'
        +'<a href="https://map.kakao.com/?q='+encoded+'" target="_blank" class="shv-map-link shv-map-kakao">카카오맵</a>'
        +'<a href="https://map.naver.com/v5/search/'+encoded+'" target="_blank" class="shv-map-link shv-map-naver">네이버</a>'
        +'<button class="shv-map-close" onclick="this.closest(\'.shv-map-overlay\').remove()">×</button>'
        +'</div>'
        +'<iframe class="shv-map-iframe" src="https://map.kakao.com/?q='+encoded+'"></iframe>'
        +'</div></div>';
    document.body.insertAdjacentHTML('beforeend',html);
};

/* ── 연락처 등록/삭제 ── */
window.mvPbAdd = function(){
    SHV.modal.open('views/saas/fms/pb_add.php?member_idx='+_memberIdx, '연락처 등록', 'md');
    SHV.modal.onClose(function(){ _contactLoaded=false; loadContacts(); });
};
window.mvPbDelete = function(idx){
    if(window.SHV && SHV.confirm){
        SHV.confirm({
            title: '연락처 삭제', message: '선택한 연락처를 삭제하시겠습니까?',
            type: 'danger', confirmText: '삭제',
            onConfirm: function(){
                SHV.api.post('dist_process/saas/PhoneBook.php', {todo:'delete', idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('연락처가 삭제되었습니다.'); _contactLoaded=false; loadContacts(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                    }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
            }
        });
    }
};

/* ── 현장소장 등록/삭제 ── */
window.mvFmAdd = function(){
    SHV.modal.open('views/saas/fms/fm_add.php?member_idx='+_memberIdx, '현장소장 등록', 'md');
    SHV.modal.onClose(function(){ _fmLoaded=false; loadFieldManagers(); });
};
window.mvFmDelete = function(idx){
    if(window.SHV && SHV.confirm){
        SHV.confirm({
            title: '현장소장 삭제', message: '선택한 현장소장을 삭제하시겠습니까?',
            type: 'danger', confirmText: '삭제',
            onConfirm: function(){
                SHV.api.post('dist_process/saas/FieldManager.php', {todo:'delete', idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('현장소장이 삭제되었습니다.'); _fmLoaded=false; loadFieldManagers(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                    }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
            }
        });
    }
};

/* ══════════════════════════════════════
   메일 탭
   ══════════════════════════════════════ */
var _mailLoaded = false;

function loadMails(){
    if(_mailLoaded) return;
    _mailLoaded = true;

    SHV.api.get('dist_process/saas/Member.php', {todo:'mail_list', member_idx:_memberIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('mvMailBody').innerHTML='<div class="text-center p-8 text-3">메일 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||rows.length;
            $('mvMailInfo').textContent=total+'건';
            $('mvMailCnt').textContent=total;
            renderMails(rows);
        })
        .catch(function(){ $('mvMailBody').innerHTML='<div class="text-center p-8 text-3">API 준비 중</div>'; });
}

function renderMails(rows){
    var body=$('mvMailBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-envelope-o"></i></div><p class="text-3 mt-2">메일 내역이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvMailTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>현장</th><th>제목</th><th>발신자</th><th>수신자</th><th>담당자</th><th>일시</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        html+='<tr>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td>'+escH(r.site_name||'')+'</td>'
            +'<td><b class="text-1">'+escH(r.subject||r.title||'')+'</b></td>'
            +'<td>'+escH(r.from_name||r.sender||'')+'</td>'
            +'<td>'+escH(r.to_name||r.receiver||'')+'</td>'
            +'<td>'+escH(r.employee_name||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.send_date||r.created_at||r.regdate)+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   첨부 탭
   ══════════════════════════════════════ */
var _fileLoaded = false;

function loadFiles(){
    if(_fileLoaded) return;
    _fileLoaded = true;

    SHV.api.get('dist_process/saas/Member.php', {todo:'attach_list', member_idx:_memberIdx, limit:500})
        .then(function(res){
            if(!res.ok){ $('mvFileBody').innerHTML='<div class="text-center p-8 text-3">첨부파일 조회 실패</div>'; return; }
            var rows = res.data.data||[];
            var total = res.data.total||rows.length;
            $('mvFileInfo').textContent=total+'건';
            $('mvFileCnt').textContent=total;
            renderFiles(rows);
        })
        .catch(function(){ $('mvFileBody').innerHTML='<div class="text-center p-8 text-3">API 준비 중</div>'; });
}

function renderFiles(rows){
    var body=$('mvFileBody'); if(!body) return;
    if(!rows.length){
        body.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-paperclip"></i></div><p class="text-3 mt-2">첨부파일이 없습니다</p></div>';
        return;
    }
    var html='<div class="tbl-scroll"><table class="tbl" id="mvFileTbl"><thead><tr>'
        +'<th class="th-center">No</th><th>파일명</th><th>항목</th><th>작성자</th><th>등록일</th><th>메모</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(r,i){
        var fname = r.file_name||r.original_name||r.name||'';
        var url = r.file_url||r.url||'';
        html+='<tr>'
            +'<td class="td-no">'+(i+1)+'</td>'
            +'<td>'+(url?'<a href="'+escH(url)+'" target="_blank" class="text-accent">'+escH(fname)+'</a>':escH(fname))+'</td>'
            +'<td>'+escH(r.category||r.subject||'')+'</td>'
            +'<td>'+escH(r.writer_name||r.employee_name||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.created_at||r.regdate)+'</td>'
            +'<td>'+escH(r.memo||'')+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   조직도 탭
   ══════════════════════════════════════ */
var _orgLoaded = false;

function loadOrgChart(){
    if(_orgLoaded) return;
    _orgLoaded = true;

    /* 본사 정보 + 연락처 + 하부조직 폴더 로드 */
    var headIdx = _data ? (_data.head_idx||0) : 0;

    Promise.all([
        SHV.api.get('dist_process/saas/PhoneBook.php', {todo:'list', member_idx:_memberIdx, limit:500}),
        SHV.api.get('dist_process/saas/Member.php', {todo:'branch_folder_list', member_idx:_memberIdx}),
        headIdx ? SHV.api.get('dist_process/saas/HeadOffice.php', {todo:'detail', idx:headIdx}) : Promise.resolve({ok:false})
    ])
    .then(function(results){
        var contacts = results[0].ok ? (results[0].data.data||[]) : [];
        var folders = results[1].ok ? (results[1].data.data||results[1].data||[]) : [];
        var head = results[2].ok ? results[2].data : null;
        renderOrgChart(contacts, folders, head);
    })
    .catch(function(){ $('mvOrgBody').innerHTML='<div class="text-center p-8 text-3">조직도 로드 실패</div>'; });
}

function renderOrgChart(contacts, folders, head){
    var body=$('mvOrgBody'); if(!body) return;
    var html='';

    /* 트리 뷰 / 테이블 뷰 전환 */
    html+='<div class="flex items-center gap-2 px-4 py-3 border-b border-border">';
    html+='<input type="text" id="orgSearch" class="form-input flex-1" placeholder="이름, 소속, 직위, 전화 검색..." oninput="mvOrgFilter()">';
    html+='<span class="text-xs text-3" id="orgCount">'+contacts.length+'명</span>';
    html+='<div class="flex gap-1">';
    html+='<button class="btn btn-sm btn-primary" id="orgBtnTree" onclick="mvOrgView(\'tree\')"><i class="fa fa-share-alt"></i></button>';
    html+='<button class="btn btn-sm btn-ghost" id="orgBtnTable" onclick="mvOrgView(\'table\')"><i class="fa fa-list"></i></button>';
    html+='</div></div>';

    /* 트리 뷰 */
    html+='<div id="orgTreeView" class="p-4" style="overflow-x:auto;">';
    html+='<div class="flex flex-col items-center">';

    /* 본사 노드 */
    if(head){
        html+='<div class="hv-org-node hv-org-root cursor-pointer" onclick="mvGoHead('+head.idx+')"><i class="fa fa-building mr-1"></i>'+escH(head.name)+'</div>';
        html+='<div class="hv-org-line"></div>';
    }

    /* 현재 사업장 노드 */
    html+='<div class="hv-org-node hv-org-branch" style="border-color:var(--accent);background:var(--accent-10);">'+escH(_data?_data.name:'')+'<div class="hv-org-sub">현재 사업장</div></div>';

    /* 하부조직 폴더 → 연락처 배치 */
    if(folders.length){
        html+='<div class="hv-org-line"></div>';
        var tree = _buildTree(folders, 0);
        html+='<div class="hv-org-children">';
        tree.forEach(function(f){
            html+=_renderOrgFolder(f, contacts);
        });
        html+='</div>';
    }

    /* 미배치 연락처 */
    var unassigned = contacts.filter(function(c){ return !c.branch_folder_idx || parseInt(c.branch_folder_idx)===0; });
    if(unassigned.length){
        html+='<div class="hv-org-line"></div>';
        html+='<div class="oc-contact-cards">';
        unassigned.forEach(function(c){
            html+='<div class="oc-contact-card" data-contact="'+c.idx+'" onclick="mvOrgDetail('+c.idx+')">'
                +'<div class="oc-cc-name">'+escH(c.name)+'</div>'
                +(c.job_title&&c.job_title!=='없음'?'<div class="oc-cc-sub">'+escH(c.job_title)+'</div>':'')
                +'</div>';
        });
        html+='</div>';
    }

    html+='</div></div>';

    /* 테이블 뷰 (숨김) */
    html+='<div id="orgTableView" class="hidden p-4">';
    if(!contacts.length){
        html+='<div class="text-center p-8 text-3">등록된 연락처가 없습니다</div>';
    } else {
        html+='<div class="tbl-scroll"><table class="tbl" id="orgTbl"><thead><tr>'
            +'<th>이름</th><th>직급</th><th>직책</th><th>전화</th><th>이메일</th><th>상태</th><th>주업무</th>'
            +'</tr></thead><tbody>';
        contacts.forEach(function(c){
            var stColor = {'재직중':'#10b981','이직':'#f59e0b','퇴사':'#ef4444'};
            var st = c.work_status||'재직중';
            html+='<tr class="cursor-pointer" onclick="mvOrgDetail('+c.idx+')">'
                +'<td class="font-semibold text-accent">'+escH(c.name)+'</td>'
                +'<td>'+escH(c.job_grade||'')+'</td>'
                +'<td>'+escH(c.job_title||'')+'</td>'
                +'<td class="whitespace-nowrap">'+(c.hp?'<a href="tel:'+escH(c.hp)+'" class="text-accent" onclick="event.stopPropagation()">'+escH(c.hp)+'</a>':'')+'</td>'
                +'<td>'+escH(c.email||'')+'</td>'
                +'<td><span style="color:'+(stColor[st]||'#999')+';font-weight:600;">'+escH(st)+'</span></td>'
                +'<td>'+escH(c.main_work||'')+'</td>'
                +'</tr>';
        });
        html+='</tbody></table></div>';
    }
    html+='</div>';

    body.innerHTML=html;

    /* 연락처 데이터 저장 (검색용) */
    window._orgContacts = contacts;
}

function _buildTree(list, pid){
    var r=[];
    list.forEach(function(f){
        if(parseInt(f.parent_idx||0)===pid){
            f.children=_buildTree(list, parseInt(f.idx));
            r.push(f);
        }
    });
    return r;
}

function _renderOrgFolder(n, allContacts){
    var fIdx = parseInt(n.idx);
    var folderContacts = allContacts.filter(function(c){ return parseInt(c.branch_folder_idx||0)===fIdx; });
    var html='<div class="hv-org-child">';
    html+='<div class="hv-org-line-h"></div>';
    html+='<div class="hv-org-node hv-org-branch">'+escH(n.name)+'</div>';

    if(folderContacts.length){
        html+='<div class="hv-org-line-h" style="height:6px;"></div>';
        html+='<div class="oc-contact-cards">';
        folderContacts.forEach(function(c){
            html+='<div class="oc-contact-card" data-contact="'+c.idx+'" onclick="mvOrgDetail('+c.idx+')">'
                +'<div class="oc-cc-name">'+escH(c.name)+'</div>'
                +(c.job_title&&c.job_title!=='없음'?'<div class="oc-cc-sub">'+escH(c.job_title)+'</div>':'')
                +'</div>';
        });
        html+='</div>';
    }

    if(n.children&&n.children.length){
        html+='<div class="hv-org-line-h"></div>';
        html+='<div class="hv-org-children">';
        n.children.forEach(function(child){ html+=_renderOrgFolder(child, allContacts); });
        html+='</div>';
    }

    html+='</div>';
    return html;
}

/* 조직도 뷰 전환 */
window.mvOrgView = function(mode){
    var tree=$('orgTreeView'), tbl=$('orgTableView');
    var btnT=$('orgBtnTree'), btnL=$('orgBtnTable');
    if(mode==='tree'){
        if(tree) tree.classList.remove('hidden');
        if(tbl) tbl.classList.add('hidden');
        if(btnT){ btnT.classList.add('btn-primary'); btnT.classList.remove('btn-ghost'); }
        if(btnL){ btnL.classList.remove('btn-primary'); btnL.classList.add('btn-ghost'); }
    } else {
        if(tree) tree.classList.add('hidden');
        if(tbl) tbl.classList.remove('hidden');
        if(btnT){ btnT.classList.remove('btn-primary'); btnT.classList.add('btn-ghost'); }
        if(btnL){ btnL.classList.add('btn-primary'); btnL.classList.remove('btn-ghost'); }
    }
};

/* 조직도 검색 */
window.mvOrgFilter = function(){
    var q=($('orgSearch')||{value:''}).value.toLowerCase().trim();
    var cnt=0;
    /* 트리뷰 카드 필터 */
    document.querySelectorAll('.oc-contact-card[data-contact]').forEach(function(card){
        var c=null;
        if(window._orgContacts){
            window._orgContacts.forEach(function(item){ if(String(item.idx)===String(card.dataset.contact)) c=item; });
        }
        var show=true;
        if(q && c){
            var text=((c.name||'')+(c.sosok||'')+(c.part||'')+(c.hp||'')+(c.email||'')+(c.job_grade||'')+(c.job_title||'')+(c.main_work||'')).toLowerCase();
            if(text.indexOf(q)===-1) show=false;
        }
        card.style.display=show?'':'none';
        if(show) cnt++;
    });
    /* 테이블뷰 행 필터 */
    var tbl=$('orgTbl');
    if(tbl){
        tbl.querySelectorAll('tbody tr').forEach(function(tr){
            var text=tr.textContent.toLowerCase();
            var match=!q||text.indexOf(q)>-1;
            tr.style.display=match?'':'none';
        });
    }
    var cntEl=$('orgCount'); if(cntEl) cntEl.textContent=cnt+'명';
};

/* 조직도 연락처 상세 (서브모달) */
window.mvOrgDetail = function(idx){
    if(!window._orgContacts) return;
    var c=null;
    window._orgContacts.forEach(function(item){ if(item.idx==idx) c=item; });
    if(!c) return;
    var html='<div class="oc-detail-card">'
        +'<div class="oc-detail-header">'
        +'<div class="oc-detail-avatar">'+escH((c.name||'?').substring(0,1))+'</div>'
        +'<div class="oc-detail-name">'+escH(c.name)+'</div>'
        +'</div>'
        +'<div class="oc-detail-rows">';
    var rows=[['소속',c.sosok],['직위',c.part],['직급',c.job_grade],['직책',c.job_title],['전화',c.hp],['이메일',c.email],['상태',c.work_status],['주업무',c.main_work]];
    rows.forEach(function(r){
        if(!r[1]||r[1]==='없음') return;
        html+='<div class="oc-detail-row"><span class="oc-detail-label">'+escH(r[0])+'</span><span class="oc-detail-value">'+escH(r[1])+'</span></div>';
    });
    html+='</div></div>';
    if(SHV.subModal) SHV.subModal.openHtml(html, escH(c.name), 'sm');
};

/* ── 초기 로드 ── */
loadDetail();
})();
</script>
</section>
