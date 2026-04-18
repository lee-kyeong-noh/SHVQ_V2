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

$siteIdx   = (int)($_GET['site_idx'] ?? 0);
$memberIdx = (int)($_GET['member_idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="ppFormBody" class="p-4">

    <!-- ── 기본 정보 ── -->
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label" for="pp_type">PJT 유형 <span class="required">*</span></label>
            <select id="pp_type" class="form-select" onchange="ppTypeChange()">
                <option value="">선택</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="pp_name">PJT명 <span class="required">*</span></label>
            <input type="text" id="pp_name" class="form-input" placeholder="PJT명" maxlength="200" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pp_attr">속성</label>
            <select id="pp_attr" class="form-select">
                <option value="">선택</option>
            </select>
        </div>
    </div>

    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label" for="pp_start">시작일</label>
            <input type="date" id="pp_start" class="form-input" onchange="ppCalcDuration()">
        </div>
        <div class="form-group">
            <label class="form-label" for="pp_end">종료일</label>
            <input type="date" id="pp_end" class="form-input" onchange="ppCalcDuration()">
        </div>
        <div class="form-group">
            <label class="form-label">기간</label>
            <input type="text" id="pp_duration" class="form-input" readonly placeholder="자동 계산">
        </div>
    </div>

    <!-- ── 단계 구성 ── -->
    <div class="form-section">
        <div class="form-section-label">단계 구성</div>
        <div id="ppPhaseList" class="flex flex-col gap-2">
            <div class="text-center p-4 text-3">로딩 중...</div>
        </div>
    </div>

    <!-- ── 메모 ── -->
    <div class="form-group">
        <label class="form-label" for="pp_memo">비고</label>
        <textarea id="pp_memo" class="form-input" rows="2" placeholder="비고" maxlength="2000"></textarea>
    </div>

</div>

<!-- ── 모달 푸터 ── -->
<div class="modal-form-footer">
    <span id="ppErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="ppSubmitBtn" onclick="ppSubmit()">
        <i class="fa fa-check"></i> PJT 등록
    </button>
</div>

<script>
(function(){
'use strict';

var _siteIdx   = <?= $siteIdx ?>;
var _memberIdx = <?= $memberIdx ?>;
var _phases    = [];
var _pjtTypes  = [];
var _pjtAttrs  = [];

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function showErr(msg){
    var el=$('ppErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; } else { el.style.display='none'; }
}
function setLoading(on){
    var btn=$('ppSubmitBtn'); if(!btn) return;
    btn.disabled=on;
    btn.innerHTML=on?'<span class="spinner spinner-sm mr-1"></span>등록 중...':'<i class="fa fa-check"></i> PJT 등록';
}

/* ══════════════════════════
   초기 데이터 로드
   ══════════════════════════ */
function loadInitData(){
    /* PJT 유형 목록 (V1 config에서) */
    SHV.api.get('dist_process/saas/Project.php',{todo:'pjt_type_list',site_idx:_siteIdx})
        .then(function(res){
            if(!res.ok) return;
            _pjtTypes=res.data.data||res.data||[];
            var sel=$('pp_type');
            if(!sel) return;
            _pjtTypes.forEach(function(t){
                var opt=document.createElement('option');
                opt.value=t.idx||t.type_code||t.name;
                opt.textContent=t.name||t.type_name||'';
                sel.appendChild(opt);
            });
        }).catch(function(){});

    /* PJT 속성 목록 */
    SHV.api.get('dist_process/saas/Project.php',{todo:'pjt_attr_list',site_idx:_siteIdx,member_idx:_memberIdx})
        .then(function(res){
            if(!res.ok) return;
            _pjtAttrs=res.data.data||res.data||[];
            var sel=$('pp_attr');
            if(!sel) return;
            _pjtAttrs.forEach(function(a){
                var opt=document.createElement('option');
                opt.value=a.idx||a.attr_name||a.name;
                opt.textContent=a.name||a.attr_name||'';
                sel.appendChild(opt);
            });
        }).catch(function(){});

    /* 기본 단계 (6단계) */
    _phases=[
        {name:'실사',    active:true, emp:'', deadline:''},
        {name:'견적',    active:true, emp:'', deadline:''},
        {name:'계약',    active:true, emp:'', deadline:''},
        {name:'착공준비', active:true, emp:'', deadline:''},
        {name:'시공',    active:true, emp:'', deadline:''},
        {name:'수금',    active:true, emp:'', deadline:''}
    ];
    renderPhases();
}

/* ══════════════════════════
   단계 렌더링
   ══════════════════════════ */
function renderPhases(){
    var list=$('ppPhaseList'); if(!list) return;
    var h='';
    _phases.forEach(function(p,i){
        var dis=p.active?'':' disabled';
        h+='<div class="pp-phase-row" data-idx="'+i+'">'
            +'<div class="flex items-center gap-1">'
            +'<button class="btn btn-ghost btn-sm text-3 pp-move-btn" onclick="ppMovePhase('+i+',-1)"'+(i===0?' disabled':'')+' title="위로"><i class="fa fa-chevron-up"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-3 pp-move-btn" onclick="ppMovePhase('+i+',1)"'+(i===_phases.length-1?' disabled':'')+' title="아래로"><i class="fa fa-chevron-down"></i></button>'
            +'</div>'
            +'<label class="flex items-center gap-2 flex-1">'
            +'<input type="checkbox" class="ss-tab-check" data-idx="'+i+'"'+(p.active?' checked':'')+' onchange="ppTogglePhase('+i+',this.checked)">'
            +'<span class="text-sm font-semibold text-1">'+escH(p.name)+'</span>'
            +'</label>'
            +'<input type="text" class="form-input form-input-sm pp-phase-emp" value="'+escH(p.emp||'')+'" placeholder="담당자" onchange="ppPhaseEmp('+i+',this.value)"'+dis+'>'
            +'<input type="date" class="form-input form-input-sm pp-phase-date" value="'+escH(p.deadline)+'" onchange="ppPhaseDeadline('+i+',this.value)"'+dis+'>'
            +'</div>';
    });
    list.innerHTML=h;
}

window.ppTogglePhase = function(i, on){
    if(_phases[i]) _phases[i].active=on;
    renderPhases();
};

window.ppPhaseDeadline = function(i, val){
    if(_phases[i]) _phases[i].deadline=val;
};

window.ppPhaseEmp = function(i, val){
    if(_phases[i]) _phases[i].emp=val;
};

window.ppMovePhase = function(i, dir){
    var j=i+dir;
    if(j<0||j>=_phases.length) return;
    var tmp=_phases[i];
    _phases[i]=_phases[j];
    _phases[j]=tmp;
    renderPhases();
};

/* ── PJT 유형 변경 → 자동 이름 ── */
window.ppTypeChange = function(){
    var sel=$('pp_type');
    if(!sel||!sel.value) return;
    var label=sel.options[sel.selectedIndex].textContent||'';
    SHV.api.get('dist_process/saas/Project.php',{todo:'pjt_name_seq',site_idx:_siteIdx,type:sel.value})
        .then(function(res){
            if(res.ok && res.data.name){
                var nameEl=$('pp_name');
                if(nameEl && !nameEl.value.trim()) nameEl.value=res.data.name;
            }
        }).catch(function(){});
};

/* ── 기간 자동 계산 ── */
window.ppCalcDuration = function(){
    var s=$('pp_start'), e=$('pp_end'), d=$('pp_duration');
    if(!s||!e||!d||!s.value||!e.value){ if(d) d.value=''; return; }
    var ms=new Date(e.value)-new Date(s.value);
    var days=Math.ceil(ms/86400000);
    d.value=days>0?days+'일':'';
};

/* ══════════════════════════
   제출
   ══════════════════════════ */
window.ppSubmit = function(){
    showErr('');
    var type=($('pp_type')||{}).value||'';
    var name=(($('pp_name')||{}).value||'').trim();
    if(!name){ showErr('PJT명은 필수입니다.'); if($('pp_name')) $('pp_name').focus(); return; }

    var activePhases=_phases.filter(function(p){ return p.active; });
    if(!activePhases.length){ showErr('최소 1개 단계를 활성화하세요.'); return; }

    setLoading(true);

    var phaseDetails=_phases.map(function(p,i){
        return {
            phase_name: p.name,
            is_active: p.active?1:0,
            emp_name: p.emp||'',
            deadline: p.deadline,
            sort_order: i+1
        };
    });

    SHV.api.post('dist_process/saas/Project.php',{
        todo: 'pjt_plan_insert',
        site_idx: _siteIdx,
        member_idx: _memberIdx,
        pjt_name: name,
        pjt_type: type,
        pjt_attr: ($('pp_attr')||{}).value||'',
        start_date: ($('pp_start')||{}).value||'',
        end_date: ($('pp_end')||{}).value||'',
        memo: ($('pp_memo')||{}).value||'',
        phase_details: JSON.stringify(phaseDetails)
    })
    .then(function(res){
        setLoading(false);
        if(res.ok){
            SHV.modal.close();
            if(SHV.toast) SHV.toast.success('PJT 예정이 등록되었습니다.');
        } else {
            showErr(res.message||'등록 실패');
        }
    })
    .catch(function(){ setLoading(false); showErr('네트워크 오류'); });
};

/* ── 초기화 ── */
loadInitData();
})();
</script>
