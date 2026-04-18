<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>'; exit; }
$memberIdx = (int)($_GET['member_idx'] ?? 0);
if ($memberIdx <= 0) { echo '<p class="text-center p-8 text-danger">잘못된 접근</p>'; exit; }
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="bsBody">

    <!-- ── 본사 연결 + 상태 ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-building"></i> 본사 연결</div>
        <div class="hda-grid-2">
            <div class="bs-row">
                <div class="bs-label">본사</div>
                <div class="bs-value" id="bsHeadInfo">로딩 중...</div>
            </div>
            <div class="bs-row">
                <div class="bs-label">연결상태</div>
                <div class="bs-value">
                    <select id="bs_link_status" class="form-select">
                        <option value="요청">요청</option>
                        <option value="연결">연결</option>
                        <option value="중단">중단</option>
                    </select>
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-label">사업장상태</div>
                <div class="bs-value">
                    <select id="bs_member_status" class="form-select">
                        <option value="예정">예정</option>
                        <option value="운영">운영</option>
                        <option value="중지">중지</option>
                        <option value="종료">종료</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 담당자 설정 ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-users"></i> 담당자 설정</div>
        <div class="hda-grid-2" id="bsEmpGrid">
            <div class="bs-row"><div class="bs-label">담당자</div><div class="bs-value bs-emp-wrap">
                <input type="text" id="bs_emp_search" class="form-input" placeholder="이름 검색..." autocomplete="off"
                    oninput="bsEmpFilter('emp')" onfocus="bsEmpFilter('emp')">
                <input type="hidden" id="bs_employee_idx" value="">
                <div id="bs_emp_dd" class="bs-emp-dd"></div>
            </div></div>
            <div class="bs-row"><div class="bs-label">부담당</div><div class="bs-value bs-emp-wrap">
                <input type="text" id="bs_emp1_search" class="form-input" placeholder="이름 검색..." autocomplete="off"
                    oninput="bsEmpFilter('emp1')" onfocus="bsEmpFilter('emp1')">
                <input type="hidden" id="bs_employee1_idx" value="">
                <div id="bs_emp1_dd" class="bs-emp-dd"></div>
            </div></div>
            <div class="bs-row"><div class="bs-label">현장담당</div><div class="bs-value bs-emp-wrap">
                <input type="text" id="bs_site_emp_search" class="form-input" placeholder="이름 검색..." autocomplete="off"
                    oninput="bsEmpFilter('site_emp')" onfocus="bsEmpFilter('site_emp')">
                <input type="hidden" id="bs_site_employee_idx" value="">
                <div id="bs_site_emp_dd" class="bs-emp-dd"></div>
            </div></div>
            <div class="bs-row"><div class="bs-label">예산담당</div><div class="bs-value bs-emp-wrap">
                <input type="text" id="bs_budget_emp_search" class="form-input" placeholder="이름 검색..." autocomplete="off"
                    oninput="bsEmpFilter('budget_emp')" onfocus="bsEmpFilter('budget_emp')">
                <input type="hidden" id="bs_budget_employee_idx" value="">
                <div id="bs_budget_emp_dd" class="bs-emp-dd"></div>
            </div></div>
            <div class="bs-row"><div class="bs-label">등록자</div><div class="bs-value">
                <input type="text" id="bs_reg_name" class="form-input" readonly placeholder="자동 설정">
                <input type="hidden" id="bs_reg_employee_idx" value="">
            </div></div>
        </div>
    </div>

    <!-- ── 사용견적 ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-calculator"></i> 사용견적</div>
        <p class="bs-desc">사용할 품목 탭을 다중 선택할 수 있습니다.</p>
        <div class="bs-multi-wrap">
            <div class="bs-multi-selected" id="bsEstSelected"></div>
            <div id="bsEstCheckList" class="hs-opt-list"></div>
        </div>
    </div>

    <!-- ── 품목옵션 ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-cubes"></i> 품목옵션</div>
        <p class="bs-desc">이 사업장에 해당하는 품목옵션을 선택하세요.</p>
        <div class="bs-multi-wrap">
            <div class="bs-multi-selected" id="bsRegionSelected"></div>
            <div id="bsRegionCheckList" class="hs-opt-list"></div>
        </div>
    </div>

    <!-- ── PJT예정 담당자 설정 ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-tasks"></i> PJT예정 담당자 설정</div>
        <p class="bs-desc">PJT예정 등록 시 각 단계에 기본 배정될 담당자와 마감일자를 설정합니다.</p>
        <div class="tbl-scroll">
            <table class="tbl text-xs" id="bsPjtPlanTable">
                <thead><tr>
                    <th class="th-center bs-ppe-step-th">단계</th>
                    <th>담당자</th>
                    <th class="bs-ppe-dlb-th">마감기준</th>
                    <th class="bs-ppe-dld-th">일수</th>
                </tr></thead>
                <tbody id="bsPjtPlanBody"></tbody>
            </table>
        </div>
    </div>

    <!-- ── 호기정보 (PJT 모듈) ── -->
    <div class="bs-section">
        <div class="bs-section-title"><i class="fa fa-cubes"></i> 호기정보 (PJT 모듈)</div>
        <div class="flex items-center gap-3 mb-2">
            <button type="button" id="bsHogiToggle" class="btn btn-sm" onclick="bsToggleHogi()">OFF</button>
            <span class="text-xs text-3">현장 견적의 PJT 속성별 호기 관리</span>
        </div>
        <div id="bsHogiArea" class="hidden">
            <table class="tbl text-xs" id="bsHogiTable">
                <thead><tr>
                    <th>PJT속성</th>
                    <th>옵션값</th>
                    <th class="th-center bs-hogi-match-th">매칭문자</th>
                    <th class="th-center bs-hogi-del-th"></th>
                </tr></thead>
                <tbody id="bsHogiBody"></tbody>
            </table>
            <button type="button" class="btn btn-ghost btn-sm text-xs mt-2" onclick="bsHogiAddRow()"><i class="fa fa-plus mr-1"></i>추가</button>
        </div>
    </div>

    <!-- ── 사업장 구조 (형제사업장 순서) ── -->
    <div class="bs-section" id="bsBranchTreeSection">
        <div class="bs-section-title"><i class="fa fa-building-o"></i> 사업장 구조</div>
        <div id="bsBranchTree">
            <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
        </div>
    </div>

    <!-- ── 하부조직 폴더 ── -->
    <div class="bs-section">
        <div class="bs-section-title">
            <span><i class="fa fa-folder-open"></i> 하부조직 폴더</span>
            <button class="btn btn-ghost btn-sm text-xs" onclick="bsFolderAdd(0)"><i class="fa fa-plus mr-1"></i>추가</button>
        </div>
        <div id="bsFolderList">
            <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
        </div>
    </div>

    <!-- ── 메모 ── -->
    <div class="bs-section">
        <div class="form-group">
            <label class="form-label">메모</label>
            <textarea id="bs_memo" class="form-input" rows="3" placeholder="메모" maxlength="2000"></textarea>
        </div>
    </div>

</div>

<!-- ── 모달 푸터 ── -->
<div class="modal-form-footer">
    <span id="bsErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="bsSaveBtn" onclick="bsSave()">
        <i class="fa fa-save mr-1"></i>저장
    </button>
</div>

<script>
(function(){
'use strict';
var _memberIdx = <?= $memberIdx ?>;
var _empList = [];
var _folders = [];
var _estTabs = [];
var _selectedEstIdxs = [];

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

/* ══════════════════════════════════════
   초기 데이터 로드
   ══════════════════════════════════════ */
function loadSettings(){
    /* 사업장 상세 */
    SHV.api.get('dist_process/saas/Member.php',{todo:'detail',idx:_memberIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data;
            if($('bs_link_status')) $('bs_link_status').value=d.link_status||'연결';
            if($('bs_member_status')) $('bs_member_status').value=d.member_status||d.status||'운영';
            if($('bs_memo')) $('bs_memo').value=d.memo||'';
            /* 본사 정보 */
            var headHtml = d.head_name
                ? '<span class="text-sm font-semibold text-1"><i class="fa fa-building-o text-accent mr-1"></i>'+escH(d.head_name)+'</span>'
                : '<span class="text-xs text-3"><i class="fa fa-unlink mr-1"></i>미연결</span>';
            if($('bsHeadInfo')) $('bsHeadInfo').innerHTML=headHtml;
            /* 담당자 매칭 */
            setEmpValue('emp', d.employee_idx, d.employee_name);
            setEmpValue('emp1', d.employee1_idx, d.employee1_name);
            setEmpValue('site_emp', d.site_employee_idx, d.site_emp_name);
            setEmpValue('budget_emp', d.budget_employee_idx, d.budget_emp_name);
            /* 등록자 */
            if(d.reg_employee_idx){
                $('bs_reg_employee_idx').value=d.reg_employee_idx;
                var regName='';
                _empList.forEach(function(e){ if(e.idx==d.reg_employee_idx) regName=e.name; });
                if($('bs_reg_name')) $('bs_reg_name').value=regName||d.reg_emp_name||'';
            }
            /* 사용견적 복원 */
            var ueIdxs = (d.use_estimate_idxs||'').split(',').filter(Boolean);
            _selectedEstIdxs = ueIdxs;
            renderEstChecks();
            /* 품목옵션 복원 */
            _selectedRegionIdxs = (d.region_options||'').split(',').filter(Boolean);
            renderRegionChecks();
            /* PJT예정 담당자 복원 */
            try { _pjtPlanEmp = JSON.parse(d.pjt_plan_emp||'{}'); } catch(e){ _pjtPlanEmp={}; }
            renderPjtPlan();
        });

    /* 담당자 목록 */
    SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list'})
        .then(function(res){
            if(!res.ok) return;
            _empList = res.data.data||[];
        });

    /* 견적 탭 목록 */
    SHV.api.get('dist_process/saas/Material.php',{todo:'tab_list'})
        .then(function(res){
            if(!res.ok) return;
            _estTabs = (res.data.data||res.data||[]).map(function(t){return{idx:String(t.idx),name:t.name};});
            renderEstChecks();
        });

    /* 품목옵션 목록 */
    loadRegionOptions();

    /* 하부조직 폴더 */
    loadFolders();

    /* 사업장 구조 (형제사업장) */
    loadSiblings();
}

function setEmpValue(prefix, idx, name){
    idx = parseInt(idx)||0;
    if($('bs_'+prefix.replace('emp','employee')+'_idx')){
        $('bs_'+prefix.replace('emp','employee')+'_idx').value = idx;
    }
    /* 담당자는 employee_idx, 부담당은 employee1_idx... 매핑 */
    var hiddenMap = {'emp':'bs_employee_idx','emp1':'bs_employee1_idx','site_emp':'bs_site_employee_idx','budget_emp':'bs_budget_employee_idx'};
    var h = $(hiddenMap[prefix]); if(h) h.value = idx;
    var s = $('bs_'+prefix+'_search'); if(s) s.value = name||'';
}

/* ══════════════════════════════════════
   담당자 검색 드롭다운
   ══════════════════════════════════════ */
window.bsEmpFilter = function(prefix){
    var search = $('bs_'+prefix+'_search');
    var dd = $('bs_'+prefix+'_dd');
    if(!search||!dd) return;
    var q = (search.value||'').trim().toLowerCase();
    var matched = _empList.filter(function(e){ return !q || e.name.toLowerCase().indexOf(q)>-1; });
    if(!matched.length){ dd.style.display='none'; dd.innerHTML=''; return; }
    dd.innerHTML = '<div class="bs-emp-dd-item" onmousedown="event.preventDefault();bsEmpPick(\''+prefix+'\',0,\'\')">미지정</div>'
        + matched.slice(0,15).map(function(e){
            return '<div class="bs-emp-dd-item" onmousedown="event.preventDefault();bsEmpPick(\''+prefix+'\','+e.idx+',\''+escH(e.name).replace(/'/g,"\\'")+'\')">'+escH(e.name)+(e.work?' <span class="text-3 text-xs">'+escH(e.work)+'</span>':'')+'</div>';
        }).join('');
    dd.style.display='block';
};

window.bsEmpPick = function(prefix, idx, name){
    var hiddenMap = {'emp':'bs_employee_idx','emp1':'bs_employee1_idx','site_emp':'bs_site_employee_idx','budget_emp':'bs_budget_employee_idx'};
    var h = $(hiddenMap[prefix]); if(h) h.value = idx;
    var s = $('bs_'+prefix+'_search'); if(s) s.value = name;
    var dd = $('bs_'+prefix+'_dd'); if(dd) dd.style.display='none';
};

document.addEventListener('click',function(e){
    if(!e.target.closest('.bs-emp-wrap')){
        document.querySelectorAll('.bs-emp-dd').forEach(function(dd){ dd.style.display='none'; });
    }
},true);

/* ══════════════════════════════════════
   사용견적 체크리스트
   ══════════════════════════════════════ */
function renderEstChecks(){
    var el=$('bsEstCheckList'); if(!el) return;
    if(!_estTabs.length){ el.innerHTML='<div class="text-xs text-3 p-2">품목 탭 없음</div>'; return; }
    var html='';
    _estTabs.forEach(function(t){
        var checked = _selectedEstIdxs.indexOf(t.idx)>-1;
        html+='<div class="hs-opt-item">'
            +'<label class="flex items-center gap-2 cursor-pointer flex-1">'
            +'<input type="checkbox" class="bs-est-chk" value="'+escH(t.idx)+'"'+(checked?' checked':'')+'>'
            +'<span class="text-sm text-1">'+escH(t.name)+'</span>'
            +'</label></div>';
    });
    el.innerHTML=html;
    renderEstTags();
}

function renderEstTags(){
    var el=$('bsEstSelected'); if(!el) return;
    var checks = document.querySelectorAll('.bs-est-chk:checked');
    if(!checks.length){ el.innerHTML='<span class="text-xs text-3">선택된 견적이 없습니다</span>'; return; }
    var html='';
    checks.forEach(function(cb){
        var tab = _estTabs.find(function(t){return t.idx===cb.value;});
        if(tab) html+='<span class="bs-multi-tag">'+escH(tab.name)+'<button type="button" onclick="bsEstUncheck(\''+escH(tab.idx)+'\')"><i class="fa fa-times"></i></button></span>';
    });
    el.innerHTML=html;
}

window.bsEstUncheck=function(idx){
    var cb=document.querySelector('.bs-est-chk[value="'+idx+'"]');
    if(cb){ cb.checked=false; renderEstTags(); }
};

document.addEventListener('change',function(e){
    if(e.target.classList.contains('bs-est-chk')) renderEstTags();
});

/* ══════════════════════════════════════
   하부조직 폴더 CRUD
   ══════════════════════════════════════ */
function loadFolders(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'branch_folder_list',member_idx:_memberIdx})
        .then(function(res){
            _folders = res.ok?(res.data.data||res.data||[]):[];
            renderFolders();
        })
        .catch(function(){ _folders=[]; renderFolders(); });
}

function renderFolders(){
    var el=$('bsFolderList'); if(!el) return;
    if(!_folders.length){
        el.innerHTML='<div class="text-center p-4 text-3 text-xs">폴더를 추가하세요</div>';
        return;
    }
    var tree=buildTree(_folders,0);
    el.innerHTML=renderFolderTree(tree,0);
}

function buildTree(list,pid){
    var r=[];
    list.forEach(function(f){
        if(parseInt(f.parent_idx||0,10)===pid){
            f.children=buildTree(list,parseInt(f.idx,10));
            r.push(f);
        }
    });
    return r;
}

function renderFolderTree(nodes,depth){
    var html='';
    nodes.forEach(function(n){
        html+='<div class="hs-folder-item hs-folder-d'+depth+'" data-idx="'+n.idx+'">'
            +'<div class="flex items-center gap-2 flex-1">'
            +'<i class="fa fa-folder text-accent"></i>'
            +'<span class="text-sm font-semibold text-1">'+escH(n.name)+'</span>'
            +'</div>'
            +'<div class="flex gap-1">';
        if(depth<2) html+='<button class="btn btn-ghost btn-sm text-xs" onclick="bsFolderAdd('+n.idx+')" title="하위추가"><i class="fa fa-plus"></i></button>';
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="bsFolderRename('+n.idx+',\''+escH(n.name).replace(/'/g,"\\'")+'\')" title="이름변경"><i class="fa fa-pencil"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="bsFolderDelete('+n.idx+')" title="삭제"><i class="fa fa-times"></i></button>'
            +'</div></div>';
        if(n.children&&n.children.length) html+=renderFolderTree(n.children,depth+1);
    });
    return html;
}

window.bsFolderAdd=function(parentIdx){
    if(!SHV.prompt) return;
    SHV.prompt('하부조직 추가','조직명을 입력하세요',function(name){
        SHV.api.post('dist_process/saas/Member.php',{todo:'branch_folder_insert',member_idx:_memberIdx,parent_idx:parentIdx,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('추가됨'); loadFolders(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
            });
    });
};
window.bsFolderRename=function(idx,old){
    if(!SHV.prompt) return;
    SHV.prompt('하부조직 수정','조직명',function(name){
        SHV.api.post('dist_process/saas/Member.php',{todo:'branch_folder_update',idx:idx,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('변경됨'); loadFolders(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
            });
    },old);
};
window.bsFolderDelete=function(idx){
    if(SHV.confirm){
        SHV.confirm({title:'폴더 삭제',message:'삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/Member.php',{todo:'branch_folder_delete',idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('삭제됨'); loadFolders(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
                    });
            }
        });
    }
};

/* ══════════════════════════════════════
   품목옵션 체크리스트
   ══════════════════════════════════════ */
var _regionOpts = [];
var _selectedRegionIdxs = [];

function loadRegionOptions(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'region_list'})
        .then(function(res){
            if(!res.ok) return;
            _regionOpts = res.data.data||[];
            renderRegionChecks();
        }).catch(function(){});
}

function renderRegionChecks(){
    var el=$('bsRegionCheckList'); if(!el) return;
    if(!_regionOpts.length){ el.innerHTML='<div class="text-xs text-3 p-2">품목옵션 없음</div>'; return; }
    var html='<div class="hs-opt-item"><label class="flex items-center gap-2 cursor-pointer flex-1">'
        +'<input type="checkbox" class="bs-region-chk" value="0"'+ (_selectedRegionIdxs.indexOf('0')>-1?' checked':'') +'>'
        +'<span class="text-sm text-1 font-semibold">전체</span></label></div>';
    _regionOpts.forEach(function(r){
        if(!r.on) return;
        var checked = _selectedRegionIdxs.indexOf(String(r.idx||r.key))>-1;
        html+='<div class="hs-opt-item"><label class="flex items-center gap-2 cursor-pointer flex-1">'
            +'<input type="checkbox" class="bs-region-chk" value="'+escH(String(r.idx||r.key))+'"'+(checked?' checked':'')+'>'
            +'<span class="text-sm text-1">'+escH(r.name)+'</span></label></div>';
    });
    el.innerHTML=html;
    renderRegionTags();
}

function renderRegionTags(){
    var el=$('bsRegionSelected'); if(!el) return;
    var checks=document.querySelectorAll('.bs-region-chk:checked');
    if(!checks.length){ el.innerHTML='<span class="text-xs text-3">선택된 옵션 없음</span>'; return; }
    var html='';
    checks.forEach(function(cb){
        var name = cb.value==='0'?'전체':(_regionOpts.find(function(r){return String(r.idx||r.key)===cb.value;})||{}).name||cb.value;
        html+='<span class="bs-multi-tag">'+escH(name)+'<button type="button" onclick="bsRegionUncheck(\''+escH(cb.value)+'\')"><i class="fa fa-times"></i></button></span>';
    });
    el.innerHTML=html;
}

window.bsRegionUncheck=function(val){
    var cb=document.querySelector('.bs-region-chk[value="'+val+'"]');
    if(cb){ cb.checked=false; renderRegionTags(); }
};

document.addEventListener('change',function(e){
    if(e.target.classList.contains('bs-region-chk')) renderRegionTags();
});

/* ══════════════════════════════════════
   PJT예정 담당자 설정 (6단계)
   ══════════════════════════════════════ */
var _pjtPhaseLabels = ['실사','견적','계약','시공','청구','수금'];
var _pjtPlanEmp = {};
var _dlBaseOpts = {'':'선택','before_construction':'착공전','after_construction':'착공후','before_completion':'준공전','after_completion':'준공후'};

function renderPjtPlan(){
    var body=$('bsPjtPlanBody'); if(!body) return;
    var html='';
    for(var i=0; i<6; i++){
        var no=String(i);
        var d=_pjtPlanEmp[no]||{emp:0,dl_base:'',dl_days:0};
        var empIdx=parseInt(d.emp)||0;
        var empName='';
        _empList.forEach(function(e){ if(e.idx===empIdx) empName=e.name; });
        html+='<tr>'
            +'<td class="td-no font-semibold">'+no+' = '+_pjtPhaseLabels[i]+'</td>'
            +'<td><div class="bs-emp-wrap">'
            +'<input type="text" id="bs_ppe_'+no+'_search" class="form-input" value="'+escH(empName)+'" placeholder="담당자 검색..." autocomplete="off"'
            +' oninput="bsEmpFilter(\'ppe_'+no+'\')" onfocus="bsEmpFilter(\'ppe_'+no+'\')">'
            +'<input type="hidden" id="bs_ppe_'+no+'_idx" value="'+empIdx+'">'
            +'<div id="bs_ppe_'+no+'_dd" class="bs-emp-dd"></div>'
            +'</div></td>'
            +'<td><select id="bs_ppe_dlb_'+no+'" class="form-select text-xs">';
        for(var dk in _dlBaseOpts){
            html+='<option value="'+dk+'"'+(d.dl_base===dk?' selected':'')+'>'+_dlBaseOpts[dk]+'</option>';
        }
        html+='</select></td>'
            +'<td><input type="number" id="bs_ppe_dld_'+no+'" class="form-input text-xs" value="'+(d.dl_days||'')+'" placeholder="0" min="0" max="365" class="bs-ppe-dld-input"></td>'
            +'</tr>';
    }
    body.innerHTML=html;
}

/* ══════════════════════════════════════
   사업장 구조 트리 (형제사업장 순서변경)
   ══════════════════════════════════════ */
var _siblings = [];

function loadSiblings(){
    var headIdx=0;
    SHV.api.get('dist_process/saas/Member.php',{todo:'detail',idx:_memberIdx})
        .then(function(res){
            if(!res.ok) return;
            headIdx = res.data.head_idx||0;
            if(!headIdx){ $('bsBranchTreeSection').classList.add('hidden'); return; }
            return SHV.api.get('dist_process/saas/Member.php',{todo:'list',head_idx:headIdx,limit:500});
        })
        .then(function(res){
            if(!res||!res.ok) return;
            _siblings = res.data.data||[];
            renderSiblings();
        }).catch(function(){});
}

function renderSiblings(){
    var el=$('bsBranchTree'); if(!el) return;
    if(!_siblings.length){ el.innerHTML='<div class="text-center p-4 text-3 text-xs">형제사업장 없음</div>'; return; }
    var html='';
    _siblings.forEach(function(b,i){
        var isMe = parseInt(b.idx)===_memberIdx;
        var status = b.member_status||b.status||'';
        html+='<div class="hs-branch-item'+(isMe?' border-l-2 border-accent':'')+'"">'
            +'<span class="hs-branch-icon '+(status==='예정'?'hs-planned':'hs-normal')+'"><i class="fa fa-'+(status==='예정'?'clock-o':'building-o')+'"></i></span>'
            +'<div class="flex-1 min-w-0">'
            +'<div class="text-sm font-semibold text-1 truncate">'+escH(b.name)+(isMe?' <span class="text-accent text-xs">(현재)</span>':'')+'</div>'
            +'<div class="text-xs text-3">'+escH(status)+(b.site_count?' · 현장 '+b.site_count:'')+'</div>'
            +'</div>'
            +'<div class="flex gap-1">'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="bsSibMove('+b.idx+',\'up\')" title="위로"><i class="fa fa-arrow-up"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="bsSibMove('+b.idx+',\'down\')" title="아래로"><i class="fa fa-arrow-down"></i></button>'
            +'</div></div>';
    });
    el.innerHTML=html;
}

window.bsSibMove=function(idx,dir){
    var pos=-1;
    _siblings.forEach(function(b,i){if(parseInt(b.idx)===idx) pos=i;});
    if(pos<0) return;
    if(dir==='up'&&pos===0) return;
    if(dir==='down'&&pos===_siblings.length-1) return;
    var swap=dir==='up'?pos-1:pos+1;
    var tmp=_siblings[pos]; _siblings[pos]=_siblings[swap]; _siblings[swap]=tmp;
    renderSiblings();
    var order=_siblings.map(function(b){return parseInt(b.idx);});
    SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'reorder_branches',head_idx:_siblings[0].head_idx||0,order:JSON.stringify(order)})
        .then(function(res){ if(res.ok&&SHV.toast) SHV.toast.success('순서 변경됨'); })
        .catch(function(){});
};

/* ══════════════════════════════════════
   호기정보 (PJT 모듈)
   ══════════════════════════════════════ */
var _hogiEnabled=false;
var _hogiList=[];
var _pjtAttrOptions=[];

function loadHogiData(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'hogi_list',member_idx:_memberIdx})
        .then(function(res){
            if(!res.ok) return;
            _hogiEnabled=!!(res.data.hogi_enabled||false);
            _hogiList=res.data.data||res.data.list||[];
            _pjtAttrOptions=res.data.pjt_attrs||[];
            renderHogiToggle();
            renderHogiTable();
        }).catch(function(){});
}

function renderHogiToggle(){
    var btn=$('bsHogiToggle');
    var area=$('bsHogiArea');
    if(btn){
        btn.textContent=_hogiEnabled?'ON':'OFF';
        btn.className='btn btn-sm '+(_hogiEnabled?'btn-glass-primary':'btn-outline');
    }
    if(area) area.classList.toggle('hidden',!_hogiEnabled);
}

window.bsToggleHogi=function(){
    _hogiEnabled=!_hogiEnabled;
    renderHogiToggle();
};

function renderHogiTable(){
    var body=$('bsHogiBody'); if(!body) return;
    if(!_hogiList.length){
        body.innerHTML='<tr><td colspan="4" class="text-center p-3 text-3 text-xs">호기 항목이 없습니다</td></tr>';
        return;
    }
    var h='';
    _hogiList.forEach(function(hg,i){
        h+='<tr data-idx="'+i+'">'
            +'<td class="p-1"><select class="form-select form-select-sm bs-hogi-pjt">';
        h+='<option value="">선택</option>';
        _pjtAttrOptions.forEach(function(a){
            var val=a.name||a.attr_name||a;
            h+='<option value="'+escH(typeof val==='string'?val:'')+'"'+(val===(hg.pjt_attr||'')?' selected':'')+'>'+escH(typeof val==='string'?val:'')+'</option>';
        });
        h+='</select></td>'
            +'<td class="p-1"><input type="text" class="form-input form-input-sm bs-hogi-opt" value="'+escH(hg.option_val||'')+'" placeholder="옵션값" maxlength="50"></td>'
            +'<td class="p-1 text-center"><input type="text" class="form-input form-input-sm bs-hogi-match text-center" value="'+escH(hg.char_match||'')+'" maxlength="5" placeholder="매칭"></td>'
            +'<td class="p-1 text-center"><button class="btn btn-ghost btn-sm text-danger" onclick="bsHogiRemove('+i+')"><i class="fa fa-times"></i></button></td>'
            +'</tr>';
    });
    body.innerHTML=h;
}

window.bsHogiAddRow=function(){
    _hogiList.push({pjt_attr:'',option_val:'',char_match:'',sort_order:_hogiList.length+1});
    renderHogiTable();
};

window.bsHogiRemove=function(i){
    _hogiList.splice(i,1);
    renderHogiTable();
};

/* ══════════════════════════════════════
   전체 저장
   ══════════════════════════════════════ */
window.bsSave=function(){
    var fd=new FormData();
    fd.append('todo','update');
    fd.append('idx',_memberIdx);
    fd.append('member_status',($('bs_member_status')||{}).value||'');
    fd.append('link_status',($('bs_link_status')||{}).value||'');
    fd.append('memo',($('bs_memo')||{}).value||'');
    fd.append('employee_idx',($('bs_employee_idx')||{}).value||'');
    fd.append('employee1_idx',($('bs_employee1_idx')||{}).value||'');
    fd.append('site_employee_idx',($('bs_site_employee_idx')||{}).value||'');
    fd.append('budget_employee_idx',($('bs_budget_employee_idx')||{}).value||'');
    fd.append('reg_employee_idx',($('bs_reg_employee_idx')||{}).value||'');
    /* 사용견적 IDX */
    var estIdxs=[];
    document.querySelectorAll('.bs-est-chk:checked').forEach(function(cb){ estIdxs.push(cb.value); });
    fd.append('use_estimate_idxs',estIdxs.join(','));
    /* 품목옵션 */
    var regionVals=[];
    document.querySelectorAll('.bs-region-chk:checked').forEach(function(cb){ regionVals.push(cb.value); });
    fd.append('region_options',regionVals.join(','));
    /* PJT예정 담당자 */
    var ppeData={};
    for(var i=0;i<6;i++){
        var no=String(i);
        ppeData[no]={
            emp:parseInt(($('bs_ppe_'+no+'_idx')||{value:0}).value)||0,
            dl_base:($('bs_ppe_dlb_'+no)||{value:''}).value,
            dl_days:parseInt(($('bs_ppe_dld_'+no)||{value:0}).value)||0
        };
    }
    fd.append('pjt_plan_emp',JSON.stringify(ppeData));
    /* 호기정보 */
    fd.append('hogi_enabled',_hogiEnabled?1:0);
    var hogiArr=[];
    document.querySelectorAll('#bsHogiBody tr').forEach(function(r,i){
        var pjtSel=r.querySelector('.bs-hogi-pjt');
        var optSel=r.querySelector('.bs-hogi-opt');
        var matchInp=r.querySelector('.bs-hogi-match');
        if(pjtSel) hogiArr.push({
            pjt_attr: pjtSel.value||'',
            option_val: optSel?optSel.value:'',
            char_match: matchInp?matchInp.value:'',
            sort_order: i+1
        });
    });
    fd.append('hogi_list',JSON.stringify(hogiArr));

    SHV.api.upload('dist_process/saas/Member.php',fd)
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success('설정이 저장되었습니다.');
                SHV.modal.close();
            } else {
                var err=$('bsErrMsg'); if(err){err.textContent=res.message||'저장 실패';err.style.display='block';}
            }
        })
        .catch(function(){ var err=$('bsErrMsg'); if(err){err.textContent='네트워크 오류';err.style.display='block';} });
};

/* ── 초기 로드 ── */
loadSettings();
loadHogiData();
})();
</script>
