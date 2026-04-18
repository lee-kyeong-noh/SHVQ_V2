<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';

session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params([
    'lifetime' => shvEnvInt('SESSION_LIFETIME', 7200),
    'path'     => '/',
    'domain'   => '',
    'secure'   => shvEnvBool('SESSION_SECURE_COOKIE', true),
    'httponly' => shvEnvBool('SESSION_HTTP_ONLY', true),
    'samesite' => shvEnv('SESSION_SAME_SITE', 'Lax'),
]);
session_start();

if (empty($_SESSION['auth']['user_pk'])) {
    http_response_code(401);
    echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>';
    exit;
}

$isModify = ($_GET['todo'] ?? '') === 'modify';
$modifyIdx = (int)($_GET['idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>

<!-- ══════════════════════════════════════
     사업장 등록/수정 폼 (member_branch_add.php)
     modal-body 안에 주입되는 뷰 / size: lg
     V1 add_member.php 전체 필드 이식
     ══════════════════════════════════════ -->
<div id="mbAddFormBody">

    <!-- ── 비전AI OCR ── -->
    <div class="mba-ocr-bar">
        <span class="mba-ocr-label"><i class="fa fa-magic mr-1"></i>비전AI</span>
        <select id="mba_ocr_doc" class="form-select mba-ocr-select">
            <option value="business_reg">사업자등록증</option>
            <option value="namecard">명함</option>
        </select>
        <label class="btn btn-outline btn-sm cursor-pointer">
            <i class="fa fa-paperclip mr-1"></i>파일
            <input type="file" id="mba_ocr_file" accept="image/*,application/pdf" class="hidden" onchange="mbaOcrProcess(this.files)">
        </label>
        <label class="btn btn-outline btn-sm cursor-pointer">
            <i class="fa fa-camera mr-1"></i>촬영
            <input type="file" id="mba_ocr_camera" accept="image/*" capture="environment" class="hidden" onchange="mbaOcrProcess(this.files)">
        </label>
        <span id="mbaOcrStatus" class="text-xs text-3 flex-1"></span>
    </div>

    <!-- ── 기본정보 ── -->
    <div class="hda-grid-3">

        <div class="form-group">
            <label class="form-label" for="mba_name">사업장명 <span class="required">*</span></label>
            <input type="text" id="mba_name" class="form-input"
                placeholder="사업장명을 입력하세요" maxlength="100" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="mba_member_number">고객번호</label>
            <div class="flex gap-2 items-center">
                <input type="text" id="mba_member_number" class="form-input flex-1"
                    placeholder="고객번호" maxlength="30" autocomplete="off">
            </div>
            <div id="mbaMemberNumFeedback" class="form-feedback"></div>
        </div>

        <div class="form-group">
            <label class="form-label" for="mba_status">상태</label>
            <select id="mba_status" class="form-select">
                <option value="예정">예정</option>
                <option value="운영">운영</option>
                <option value="중지">중지</option>
                <option value="종료">종료</option>
            </select>
        </div>

    </div>

    <!-- ── 대표자 · 사업자번호 · 업태 ── -->
    <div class="hda-grid-3">

        <div class="form-group">
            <label class="form-label" for="mba_ceo">대표자</label>
            <input type="text" id="mba_ceo" class="form-input"
                placeholder="대표자명" maxlength="50" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="mba_card">사업자번호</label>
            <input type="text" id="mba_card" class="form-input"
                placeholder="000-00-00000" maxlength="12" autocomplete="off"
                oninput="this.value=fmtBiz(this.value);mbaBizOnInput(this.value)">
            <div id="mbaBizFeedback" class="form-feedback"></div>
        </div>

        <div class="form-group">
            <label class="form-label" for="mba_biz_type">업태</label>
            <input type="text" id="mba_biz_type" class="form-input"
                placeholder="업태" maxlength="50" autocomplete="off">
        </div>

    </div>

    <!-- ── 업종 · 그룹 ── -->
    <div class="hda-grid-2">

        <div class="form-group">
            <label class="form-label" for="mba_biz_class">업종</label>
            <input type="text" id="mba_biz_class" class="form-input"
                placeholder="업종" maxlength="50" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="mba_group">그룹</label>
            <select id="mba_group" class="form-select">
                <option value="">미지정</option>
            </select>
        </div>

    </div>

    <!-- ── 연락처 ── -->
    <div class="form-section">
        <div class="form-section-label">연락처</div>
        <div class="hda-grid-3">

            <div class="form-group">
                <label class="form-label" for="mba_tel">전화번호</label>
                <input type="text" id="mba_tel" class="form-input"
                    placeholder="02-0000-0000" maxlength="14" autocomplete="off"
                    oninput="this.value=fmtPhone(this.value)">
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_hp">휴대폰</label>
                <input type="text" id="mba_hp" class="form-input"
                    placeholder="010-0000-0000" maxlength="13" autocomplete="off"
                    oninput="this.value=fmtPhone(this.value)">
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_fax">팩스</label>
                <input type="text" id="mba_fax" class="form-input"
                    placeholder="02-0000-0000" maxlength="14" autocomplete="off"
                    oninput="this.value=fmtPhone(this.value)">
            </div>

        </div>
        <div class="hda-grid-2">

            <div class="form-group">
                <label class="form-label" for="mba_email">이메일</label>
                <input type="text" id="mba_email" class="form-input"
                    placeholder="email@example.com" maxlength="100" autocomplete="off">
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_manager">담당자명</label>
                <input type="text" id="mba_manager" class="form-input"
                    placeholder="담당자명" maxlength="50" autocomplete="off">
            </div>

        </div>
    </div>

    <!-- ── 담당 · 계약 ── -->
    <div class="form-section">
        <div class="form-section-label">담당 · 계약</div>
        <div class="hda-grid-3">

            <div class="form-group">
                <label class="form-label">담당자</label>
                <div class="est-dd-wrap">
                    <input type="text" id="mba_emp_search" class="form-input"
                        placeholder="이름 검색..." autocomplete="off"
                        oninput="mbaEmpSearch(this.value)"
                        onfocus="mbaEmpSearch(this.value)">
                    <input type="hidden" id="mba_employee_idx" value="">
                    <div id="mbaEmpDropdown" class="est-dd"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">등록자</label>
                <input type="text" id="mba_register_name" class="form-input" readonly
                    value="" placeholder="자동 설정">
                <input type="hidden" id="mba_register_idx" value="">
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_region">권역</label>
                <select id="mba_region" class="form-select">
                    <option value="">미지정</option>
                </select>
            </div>

        </div>
        <div class="hda-grid-3">

            <div class="form-group">
                <label class="form-label" for="mba_head">본사 연결</label>
                <select id="mba_head" class="form-select">
                    <option value="">선택 (미연결)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_contract">협력계약</label>
                <select id="mba_contract" class="form-select">
                    <option value="">선택</option>
                    <option value="정식">정식</option>
                    <option value="임시">임시</option>
                    <option value="해지">해지</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_reg_date">등록일</label>
                <input type="date" id="mba_reg_date" class="form-input">
            </div>

        </div>
    </div>

    <!-- ── 사용견적 ── -->
    <div class="form-section">
        <div class="form-section-label">사용견적</div>
        <div class="form-group est-dd-wrap">
            <div class="tag-input-box" id="mbaEstimateBox"
                onclick="document.getElementById('mba_estimate_q').focus()">
                <input type="text" id="mba_estimate_q" class="tag-input-field"
                    placeholder="견적명 입력 후 Enter로 추가"
                    autocomplete="off"
                    onkeydown="mbaEstKeydown(event)"
                    oninput="mbaEstSearch(this.value)"
                    onfocus="mbaEstSearch(this.value)">
            </div>
            <div id="mbaEstDropdown" class="est-dd"></div>
        </div>
    </div>

    <!-- ── 주소 ── -->
    <div class="form-section">
        <div class="form-section-label">주소</div>
        <div class="hda-grid-2">

            <div class="form-group">
                <label class="form-label">우편번호</label>
                <div class="flex gap-2 items-center">
                    <input type="text" id="mba_zipcode" class="form-input hda-zipcode" readonly placeholder="우편번호">
                    <button type="button" class="btn btn-outline btn-sm" onclick="mbaAddrOpen()">
                        <i class="fa fa-search"></i> 주소 검색
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="mba_addr_detail">상세주소</label>
                <input type="text" id="mba_addr_detail" class="form-input"
                    placeholder="동, 호수, 층 등" maxlength="100" autocomplete="off">
            </div>

            <div class="form-group col-full">
                <label class="form-label">주소</label>
                <input type="text" id="mba_addr" class="form-input cursor-pointer" readonly
                    placeholder="위 주소 검색 버튼을 클릭하세요" onclick="mbaAddrOpen()">
            </div>

        </div>
    </div>

    <!-- ── 기타 ── -->
    <div class="form-section">
        <div class="form-section-label">기타</div>
        <div class="hda-grid-2">

            <div class="form-group">
                <label class="form-label" for="mba_birthday">생년월일</label>
                <input type="date" id="mba_birthday" class="form-input">
            </div>

            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <span class="text-xs text-3">&nbsp;</span>
            </div>

        </div>
        <div class="form-group">
            <label class="form-label" for="mba_memo">메모</label>
            <textarea id="mba_memo" class="form-input" rows="3"
                placeholder="메모 입력 (선택)" maxlength="2000"></textarea>
        </div>
    </div>

    <!-- ── 주소 검색 레이어 ── -->
    <div id="mbaAddrOverlay"
         class="fixed inset-0 z-9999 flex items-center justify-center p-4 pointer-events-none"
         style="display:none;">
        <div class="modal-box modal-sm pointer-events-auto max-h-none" id="mbaAddrBox">
            <div id="mbaAddrHeader" class="modal-header">
                <span><i class="fa fa-map-marker text-accent mr-2"></i>주소 검색</span>
                <button type="button" class="modal-close" onclick="mbaAddrClose()">×</button>
            </div>
            <div id="mbaAddrEmbed" class="w-full mba-addr-embed"></div>
        </div>
    </div>

</div>

<!-- ── 모달 폼 푸터 ── -->
<div class="modal-form-footer">
    <span id="mbAddErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="mbAddSubmitBtn" onclick="mbAddSubmit()">
        <i class="fa fa-check"></i> <?= $isModify ? '수정' : '등록' ?>
    </button>
</div>

<script>
(function(){
'use strict';

var _isModify = <?= $isModify ? 'true' : 'false' ?>;
var _modifyIdx = <?= $modifyIdx ?>;
var _bizIsDup = false;
var _bizLast  = '';
var _bizTimer = null;
var _mbaEstimates = [];
var _estimateActiveIdx = -1;
var _estimateCandidates = [];
var _mbaRequired = {};
var _empActiveIdx = -1;

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function showErr(msg){
    var el=$('mbAddErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; }
    else    { el.style.display='none'; }
}
function setLoading(on){
    var btn=$('mbAddSubmitBtn'); if(!btn) return;
    btn.disabled=on;
    btn.innerHTML=on
        ? '<span class="spinner spinner-sm mr-1"></span>저장 중...'
        : '<i class="fa fa-check"></i> '+(_isModify?'수정':'등록');
}

/* ══════════════════════════
   포맷터
   ══════════════════════════ */
window.fmtBiz = window.fmtBiz || function(v){
    var d=v.replace(/\D/g,'').slice(0,10);
    if(d.length<=3) return d;
    if(d.length<=5) return d.slice(0,3)+'-'+d.slice(3);
    return d.slice(0,3)+'-'+d.slice(3,5)+'-'+d.slice(5);
};

window.fmtPhone = window.fmtPhone || function(v){
    var d=v.replace(/\D/g,'').slice(0,11);
    if(d.startsWith('02')){
        if(d.length<=2) return d;
        if(d.length<=6) return d.slice(0,2)+'-'+d.slice(2);
        if(d.length<=9) return d.slice(0,2)+'-'+d.slice(2,5)+'-'+d.slice(5);
        return d.slice(0,2)+'-'+d.slice(2,6)+'-'+d.slice(6,10);
    } else {
        if(d.length<=3) return d;
        if(d.length<=6) return d.slice(0,3)+'-'+d.slice(3);
        if(d.length<=10) return d.slice(0,3)+'-'+d.slice(3,6)+'-'+d.slice(6);
        return d.slice(0,3)+'-'+d.slice(3,7)+'-'+d.slice(7,11);
    }
};

/* ══════════════════════════
   필수항목 동적 적용
   ══════════════════════════ */
function applyRequired(req){
    _mbaRequired = req || {};
    var labelMap = {
        'name':'mba_name','card_number':'mba_card','ceo_name':'mba_ceo',
        'business_type':'mba_biz_type','business_class':'mba_biz_class',
        'tel':'mba_tel','hp':'mba_hp','email':'mba_email',
        'address':'mba_addr','employee_idx':'mba_emp_search'
    };
    for(var key in labelMap){
        var el=$(labelMap[key]);
        if(!el) continue;
        var label = el.closest('.form-group');
        if(!label) continue;
        var lbl = label.querySelector('.form-label');
        if(!lbl) continue;
        var existing = lbl.querySelector('.required');
        if(req[key]===1 && !existing){
            var span = document.createElement('span');
            span.className='required';
            span.textContent='*';
            lbl.appendChild(span);
        } else if(req[key]!==1 && existing){
            existing.remove();
        }
    }
}

SHV.api.get('dist_process/saas/Member.php',{todo:'get_required'})
    .then(function(res){ if(res.ok) applyRequired(res.data.required||res.data); });

/* ══════════════════════════
   사업자번호 중복체크
   ══════════════════════════ */
function mbaBizSetState(state, data){
    var fb=$('mbaBizFeedback'); if(!fb) return;
    _bizIsDup = (state==='dup');
    if(state==='loading'){
        fb.className='form-feedback fb-loading';
        fb.innerHTML='<span class="spinner spinner-sm mr-1"></span>중복 확인 중...';
    } else if(state==='dup'){
        fb.className='form-feedback fb-error';
        fb.innerHTML='<i class="fa fa-exclamation-circle mr-1"></i>이미 등록된 사업자번호입니다.'
            +(data?' <button class="btn btn-ghost btn-sm text-xs hda-biz-view-btn" onclick="mbaBizShowDup()">기존 정보 보기</button>':'');
        if(data) fb._dupData=data;
    } else if(state==='ok'){
        fb.className='form-feedback fb-success';
        fb.innerHTML='<i class="fa fa-check-circle mr-1"></i>사용 가능한 사업자번호';
    } else {
        fb.className='form-feedback';
        fb.innerHTML='';
    }
}

function mbaBizCheck(digits){
    var params = {todo:'check_dup',card_number:digits};
    if(_isModify) params.exclude_idx = _modifyIdx;
    SHV.api.get('dist_process/saas/Member.php',params)
        .then(function(res){
            if(res.exists) mbaBizSetState('dup',res.data);
            else mbaBizSetState('ok');
        })
        .catch(function(){ mbaBizSetState('reset'); });
}

window.mbaBizOnInput = function(v){
    var raw=v.replace(/\D/g,'');
    if(raw.length<10){ mbaBizSetState('reset'); _bizLast=''; return; }
    if(raw===_bizLast) return;
    _bizLast=raw;
    clearTimeout(_bizTimer);
    mbaBizSetState('loading');
    _bizTimer=setTimeout(function(){ mbaBizCheck(raw); },300);
};

window.mbaBizShowDup = function(){
    var fb=$('mbaBizFeedback');
    var d=fb&&fb._dupData; if(!d) return;
    var html='<table class="w-full border-collapse text-sm text-1">'
        +'<tr><th class="biz-th">사업장명</th><td class="biz-td">'+escH(d.name||'')+'</td></tr>'
        +'<tr><th class="biz-th">대표자</th><td class="biz-td">'+escH(d.ceo||'')+'</td></tr>'
        +'<tr><th class="biz-th">전화</th><td class="biz-td">'+escH(d.tel||'')+'</td></tr>'
        +'<tr><th class="biz-th">주소</th><td class="biz-td">'+escH(d.address||'')+'</td></tr>'
        +'</table>';
    if(SHV.subModal) SHV.subModal.openHtml(html,'기존 사업장 정보','sm');
};

/* ══════════════════════════
   담당자 검색 드롭다운
   ══════════════════════════ */
var _empList = [];
window.mbaEmpSearch = function(q){
    q=(q||'').trim().toLowerCase();
    var dd=$('mbaEmpDropdown'); if(!dd) return;
    _empActiveIdx=-1;
    if(!_empList.length){
        dd.style.display='none';
        return;
    }
    var matched = _empList.filter(function(e){
        return !q || e.name.toLowerCase().indexOf(q)>-1;
    });
    if(!matched.length){ dd.style.display='none'; dd.innerHTML=''; return; }
    dd.innerHTML=matched.map(function(e){
        return '<div class="est-dd-item" data-val="'+e.idx+'" data-name="'+escH(e.name)+'"'
            +' onmousedown="event.preventDefault();mbaEmpPick('+e.idx+',\''+escH(e.name).replace(/'/g,"\\'")+'\')"'
            +'>'+escH(e.name)+(e.dept?' <span class="text-3 text-xs">'+escH(e.dept)+'</span>':'')+'</div>';
    }).join('');
    dd.style.display='block';
};

window.mbaEmpPick = function(idx, name){
    $('mba_employee_idx').value = idx;
    $('mba_emp_search').value = name;
    var dd=$('mbaEmpDropdown'); if(dd) dd.style.display='none';
};

document.addEventListener('click',function(e){
    if(!e.target.closest('#mba_emp_search')&&!e.target.closest('#mbaEmpDropdown')){
        var dd=$('mbaEmpDropdown'); if(dd) dd.style.display='none';
    }
},true);

/* ══════════════════════════
   사용견적 태그 (본사등록과 동일 패턴)
   ══════════════════════════ */
function mbaEstRender(){
    var box=$('mbaEstimateBox'); if(!box) return;
    box.querySelectorAll('.tag-chip').forEach(function(c){ c.remove(); });
    var input=$('mba_estimate_q');
    _mbaEstimates.forEach(function(v,i){
        var chip=document.createElement('span');
        chip.className='tag-chip';
        chip.innerHTML=escH(v.name||v)+'<span class="tag-chip-remove" onclick="mbaEstRemove('+i+')">×</span>';
        box.insertBefore(chip,input);
    });
}

window.mbaEstRemove=function(i){ _mbaEstimates.splice(i,1); mbaEstRender(); };

window.mbaEstAddObj=function(id,name){
    if(!name) return;
    if(_mbaEstimates.find(function(v){return (v.name||v)===name;})) return;
    _mbaEstimates.push({id:String(id), name:name});
    mbaEstRender();
    var q=$('mba_estimate_q'); if(q) q.value='';
    var dd=$('mbaEstDropdown'); if(dd) dd.style.display='none';
};

window.mbaEstKeydown=function(e){
    var dd=$('mbaEstDropdown'); if(!dd) return;
    var items=dd.querySelectorAll('.est-dd-item');
    if(e.key==='ArrowDown'){
        e.preventDefault();
        _estimateActiveIdx=Math.min(_estimateActiveIdx+1, items.length-1);
        mbaEstHighlight(items);
    } else if(e.key==='ArrowUp'){
        e.preventDefault();
        _estimateActiveIdx=Math.max(_estimateActiveIdx-1, -1);
        mbaEstHighlight(items);
    } else if(e.key==='Enter'){
        e.preventDefault();
        var active=dd.querySelector('.est-dd-active');
        if(active){ mbaEstAddObj(active.dataset.id||active.dataset.val, active.dataset.val); }
        else { var val=(e.target.value||'').trim(); if(val){ mbaEstAddObj(val,val); } }
    } else if(e.key==='Escape'){
        dd.style.display='none'; _estimateActiveIdx=-1;
    } else if(e.key==='Backspace'&&!e.target.value&&_mbaEstimates.length){
        _mbaEstimates.pop(); mbaEstRender();
    }
};

function mbaEstHighlight(items){
    items.forEach(function(el,i){
        el.classList.toggle('est-dd-active', i===_estimateActiveIdx);
    });
    if(_estimateActiveIdx>=0 && items[_estimateActiveIdx]){
        items[_estimateActiveIdx].scrollIntoView({block:'nearest'});
    }
}

/* 견적 후보 로드 */
(function(){
    SHV.api.get('dist_process/saas/Material.php',{todo:'tab_list'})
        .then(function(res){
            if(!res.ok) return;
            var list = res.data.data||res.data||[];
            _estimateCandidates = list.map(function(t){ return {idx:t.idx, name:t.name}; });
        });
})();

window.mbaEstSearch=function(q){
    q=(q||'').trim();
    var dd=$('mbaEstDropdown'); if(!dd) return;
    _estimateActiveIdx=-1;
    var matched=_estimateCandidates.filter(function(v){
        var name=v.name||v;
        return (!q||name.toLowerCase().indexOf(q.toLowerCase())>-1) && !_mbaEstimates.find(function(e){return (e.name||e)===name;});
    });
    if(!matched.length){ dd.style.display='none'; dd.innerHTML=''; return; }
    dd.innerHTML=matched.map(function(v){
        var name=v.name||v;
        var id=v.idx||v.id||name;
        return '<div class="est-dd-item" data-val="'+escH(name)+'" data-id="'+escH(String(id))+'"'
            +' onmousedown="event.preventDefault();mbaEstAddObj(\''+escH(String(id))+'\',\''+escH(name)+'\')"'
            +'>'+escH(name)+'</div>';
    }).join('');
    dd.style.display='block';
};

document.addEventListener('click',function(e){
    if(!e.target.closest('#mbaEstimateBox')&&!e.target.closest('#mbaEstDropdown')){
        var dd=$('mbaEstDropdown'); if(dd) dd.style.display='none';
    }
},true);

/* ══════════════════════════
   초기 데이터 로드 (본사 + 그룹 + 담당자 + 권역 + 등록자)
   ══════════════════════════ */
function loadInitData(){
    var p1 = SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'list',limit:500})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('mba_head'); if(!sel) return;
            (res.data.data||[]).forEach(function(h){
                var opt=document.createElement('option');
                opt.value=h.idx;
                opt.textContent=h.name+(h.ceo?' ('+h.ceo+')':'');
                sel.appendChild(opt);
            });
        });

    /* 그룹 목록 — MemberGroup API (없으면 스킵) */
    var p2 = SHV.api.get('dist_process/saas/Member.php',{todo:'group_list'})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('mba_group'); if(!sel) return;
            (res.data.data||res.data||[]).forEach(function(g){
                var opt=document.createElement('option');
                opt.value=g.idx;
                opt.textContent=g.name;
                sel.appendChild(opt);
            });
        }).catch(function(){});

    /* 담당자 목록 — Employee API */
    var p3 = SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list'})
        .then(function(res){
            if(!res.ok) return;
            _empList = (res.data.data||res.data||[]).map(function(e){
                return {idx:e.idx, name:e.name, dept:e.work||e.department||''};
            });
            /* 등록자 설정 */
            var myIdx = res.data.my_employee_idx || 0;
            if(myIdx && !_isModify){
                $('mba_register_idx').value = myIdx;
                var me = _empList.find(function(e){ return e.idx === myIdx; });
                if(me) $('mba_register_name').value = me.name;
            }
        }).catch(function(){});

    /* 권역 목록 */
    var p4 = SHV.api.get('dist_process/saas/Member.php',{todo:'region_list'})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('mba_region'); if(!sel) return;
            var regions = res.data.data||res.data||[];
            if(Array.isArray(regions)){
                regions.forEach(function(r){
                    if(!r.on) return;
                    var opt=document.createElement('option');
                    opt.value=r.idx||r.key;
                    opt.textContent=r.name;
                    sel.appendChild(opt);
                });
            } else {
                for(var k in regions){
                    if(!regions[k].on) continue;
                    var opt=document.createElement('option');
                    opt.value=k;
                    opt.textContent=regions[k].name;
                    sel.appendChild(opt);
                }
            }
        }).catch(function(){});

    /* 등록일 기본값: 오늘 */
    var regDate=$('mba_reg_date');
    if(regDate && !_isModify) regDate.value = new Date().toISOString().slice(0,10);

    return Promise.all([p1,p2,p3,p4]);
}

loadInitData().then(function(){ if(_isModify) loadModifyData(); });

/* ══════════════════════════
   주소 검색
   ══════════════════════════ */
var _addrDragX=0, _addrDragY=0, _addrDragging=false, _addrDragSX, _addrDragSY;

window.mbaAddrOpen = function(){
    if(typeof daum==='undefined' || !daum.Postcode){
        if(SHV&&SHV.toast) SHV.toast.warn('주소 검색 서비스를 불러오는 중입니다.');
        return;
    }
    var overlay=$('mbaAddrOverlay');
    var embedEl=$('mbaAddrEmbed');
    var box=$('mbaAddrBox');
    if(!overlay||!embedEl||!box) return;

    _addrDragX=0; _addrDragY=0;
    box.style.transform='';
    embedEl.innerHTML='';
    overlay.style.display='flex';

    new daum.Postcode({
        oncomplete: function(data){
            var zip  = data.zonecode   || '';
            var addr = data.roadAddress || data.jibunAddress || '';
            var zipEl=$('mba_zipcode'), addrEl=$('mba_addr'), detailEl=$('mba_addr_detail');
            if(zipEl)    zipEl.value  = zip;
            if(addrEl)   addrEl.value = addr;
            if(detailEl){ detailEl.value=''; }
            mbaAddrClose();
            setTimeout(function(){ if(detailEl) detailEl.focus(); }, 80);
        },
        width: '100%',
        height: '420px'
    }).embed(embedEl, { autoClose: true });

    var header=$('mbaAddrHeader');
    if(header && !header._dragBound){
        header._dragBound=true;
        header.addEventListener('mousedown',function(e){
            if(e.button!==0) return;
            _addrDragging=true;
            _addrDragSX=e.clientX-_addrDragX;
            _addrDragSY=e.clientY-_addrDragY;
            document.body.style.userSelect='none';
            e.preventDefault();
        });
        document.addEventListener('mousemove',function(e){
            if(!_addrDragging) return;
            _addrDragX=e.clientX-_addrDragSX;
            _addrDragY=e.clientY-_addrDragSY;
            box.style.transform='translate('+_addrDragX+'px,'+_addrDragY+'px)';
        });
        document.addEventListener('mouseup',function(){
            _addrDragging=false;
            document.body.style.userSelect='';
        });
    }
};

window.mbaAddrClose = function(){
    var overlay=$('mbaAddrOverlay');
    if(overlay) overlay.style.display='none';
    var embedEl=$('mbaAddrEmbed');
    if(embedEl) embedEl.innerHTML='';
};

/* ══════════════════════════
   수정 모드 — 기존 데이터 로드
   ══════════════════════════ */
function loadModifyData(){
    if(!_isModify||!_modifyIdx) return;
    SHV.api.get('dist_process/saas/Member.php',{todo:'detail',idx:_modifyIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data;
            if($('mba_name'))            $('mba_name').value=d.name||'';
            if($('mba_member_number'))   $('mba_member_number').value=d.member_number||'';
            if($('mba_status'))          $('mba_status').value=d.member_status||d.status||'예정';
            if($('mba_ceo'))             $('mba_ceo').value=d.ceo||d.ceo_name||'';
            if($('mba_card'))            $('mba_card').value=d.card_number||'';
            if($('mba_biz_type'))        $('mba_biz_type').value=d.business_type||d.biztype||'';
            if($('mba_biz_class'))       $('mba_biz_class').value=d.business_class||d.bizclass||'';
            if($('mba_tel'))             $('mba_tel').value=d.tel||'';
            if($('mba_hp'))              $('mba_hp').value=d.hp||'';
            if($('mba_fax'))             $('mba_fax').value=d.fax||'';
            if($('mba_email'))           $('mba_email').value=d.email||'';
            if($('mba_manager'))         $('mba_manager').value=d.manager_name||'';
            if($('mba_contract'))        $('mba_contract').value=d.cooperation_contract||'';
            if($('mba_zipcode'))         $('mba_zipcode').value=d.zipcode||d.zip_code||'';
            if($('mba_addr'))            $('mba_addr').value=d.address||'';
            if($('mba_addr_detail'))     $('mba_addr_detail').value=d.address_detail||'';
            if($('mba_memo'))            $('mba_memo').value=d.memo||'';
            if($('mba_birthday'))        $('mba_birthday').value=d.birthday?d.birthday.substring(0,10):'';
            if($('mba_reg_date')&&d.registered_date) $('mba_reg_date').value=d.registered_date.substring(0,10);
            if(d.head_idx && $('mba_head'))       $('mba_head').value=d.head_idx;
            if(d.group_idx && $('mba_group'))      $('mba_group').value=d.group_idx;
            if(d.region_idx && $('mba_region'))    $('mba_region').value=d.region_idx;
            if(d.employee_idx){
                $('mba_employee_idx').value=d.employee_idx;
                var emp=_empList.find(function(e){return e.idx==d.employee_idx;});
                if(emp) $('mba_emp_search').value=emp.name;
            }
            if(d.reg_employee_idx || d.register_idx){
                var regIdx = d.reg_employee_idx||d.register_idx;
                $('mba_register_idx').value=regIdx;
                var regEmp=_empList.find(function(e){return e.idx==regIdx;});
                if(regEmp) $('mba_register_name').value=regEmp.name;
            }
            /* 사용견적 복원 */
            var ue = d.used_estimate || d.use_estimate_idxs || '';
            if(ue){
                try {
                    var arr = JSON.parse(ue);
                    if(Array.isArray(arr)){ _mbaEstimates=arr; mbaEstRender(); }
                } catch(e){
                    if(typeof ue==='string' && ue.indexOf(',')>-1){
                        ue.split(',').forEach(function(id){
                            id=id.trim(); if(!id) return;
                            var c=_estimateCandidates.find(function(v){return String(v.idx)===id;});
                            _mbaEstimates.push({id:id, name:c?c.name:id});
                        });
                        mbaEstRender();
                    }
                }
            }
        });
}

/* ══════════════════════════
   등록/수정 제출
   ══════════════════════════ */
window.mbAddSubmit = function(){
    showErr('');
    /* 필수항목 검증 */
    var fieldMap = {
        'name':['mba_name','사업장명'],'card_number':['mba_card','사업자번호'],
        'ceo_name':['mba_ceo','대표자'],'business_type':['mba_biz_type','업태'],
        'business_class':['mba_biz_class','업종'],'tel':['mba_tel','전화번호'],
        'hp':['mba_hp','휴대폰'],'email':['mba_email','이메일'],
        'address':['mba_addr','주소'],'employee_idx':['mba_employee_idx','담당자']
    };
    /* 사업장명은 항상 필수 */
    var name=(($('mba_name')||{}).value||'').trim();
    if(!name){ showErr('사업장명은 필수 입력 항목입니다.'); if($('mba_name')) $('mba_name').focus(); return; }
    /* 동적 필수항목 검증 */
    for(var key in _mbaRequired){
        if(_mbaRequired[key]!==1) continue;
        if(key==='name') continue;
        var fInfo=fieldMap[key]; if(!fInfo) continue;
        var el=$(fInfo[0]);
        if(el && !(el.value||'').trim()){
            showErr(fInfo[1]+'은(를) 입력하세요.');
            if(el.type!=='hidden') el.focus();
            return;
        }
    }
    if(_bizIsDup){ showErr('중복된 사업자번호입니다. 확인 후 진행해주세요.'); return; }
    setLoading(true);

    var fd=new FormData();
    fd.append('todo',                 _isModify ? 'update' : 'insert');
    if(_isModify) fd.append('idx',    _modifyIdx);
    fd.append('name',                 name);
    fd.append('member_number',        ($('mba_member_number')||{}).value||'');
    fd.append('member_status',        ($('mba_status')||{}).value||'예정');
    fd.append('ceo',                  ($('mba_ceo')||{}).value||'');
    fd.append('card_number',          ($('mba_card')||{}).value||'');
    fd.append('business_type',        ($('mba_biz_type')||{}).value||'');
    fd.append('business_class',       ($('mba_biz_class')||{}).value||'');
    fd.append('group_idx',            ($('mba_group')||{}).value||'');
    fd.append('tel',                  ($('mba_tel')||{}).value||'');
    fd.append('hp',                   ($('mba_hp')||{}).value||'');
    fd.append('fax',                  ($('mba_fax')||{}).value||'');
    fd.append('email',                ($('mba_email')||{}).value||'');
    fd.append('manager_name',         ($('mba_manager')||{}).value||'');
    fd.append('employee_idx',         ($('mba_employee_idx')||{}).value||'');
    fd.append('reg_employee_idx',     ($('mba_register_idx')||{}).value||'');
    fd.append('region_idx',           ($('mba_region')||{}).value||'');
    fd.append('head_idx',             ($('mba_head')||{}).value||'');
    fd.append('cooperation_contract', ($('mba_contract')||{}).value||'');
    fd.append('used_estimate',        JSON.stringify(_mbaEstimates));
    fd.append('registered_date',      ($('mba_reg_date')||{}).value||'');
    fd.append('birthday',             ($('mba_birthday')||{}).value||'');
    fd.append('zipcode',              ($('mba_zipcode')||{}).value||'');
    fd.append('address',              ($('mba_addr')||{}).value||'');
    fd.append('address_detail',       ($('mba_addr_detail')||{}).value||'');
    fd.append('memo',                 ($('mba_memo')||{}).value||'');

    SHV.api.upload('dist_process/saas/Member.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){
                SHV.modal.close();
                if(typeof mbReload==='function') mbReload();
                if(SHV.toast) SHV.toast.success(_isModify?'사업장이 수정되었습니다.':'사업장이 등록되었습니다.');
            } else {
                showErr(res.message||(_isModify?'수정에 실패하였습니다.':'등록에 실패하였습니다.'));
            }
        })
        .catch(function(){ setLoading(false); showErr('네트워크 오류가 발생하였습니다.'); });
};

/* ══════════════════════════
   키보드 필드 탐색
   ══════════════════════════ */
var _navFields = ['mba_name','mba_member_number','mba_status','mba_ceo','mba_card','mba_biz_type',
                  'mba_biz_class','mba_group','mba_tel','mba_hp','mba_fax','mba_email','mba_manager',
                  'mba_emp_search','mba_region','mba_head','mba_contract','mba_reg_date',
                  'mba_addr_detail','mba_birthday','mba_memo'];

document.getElementById('mbAddFormBody').addEventListener('keydown',function(e){
    var id=e.target.id;
    if(!id||_navFields.indexOf(id)===-1) return;
    if(e.key==='Enter' && !e.shiftKey && !e.ctrlKey && e.target.tagName!=='TEXTAREA'){
        e.preventDefault();
        /* 사업자번호 Enter → 즉시 중복체크 */
        if(id==='mba_card'){
            var raw=($('mba_card')||{value:''}).value.replace(/\D/g,'');
            if(raw.length===10 && raw!==_bizLast){ clearTimeout(_bizTimer); _bizLast=raw; mbaBizSetState('loading'); mbaBizCheck(raw); }
        }
        var idx=_navFields.indexOf(id);
        for(var i=idx+1;i<_navFields.length;i++){
            var el=$(_navFields[i]);
            if(el&&!el.disabled&&!el.readOnly){ el.focus(); return; }
        }
        var btn=$('mbAddSubmitBtn'); if(btn) btn.focus();
    }
    if((e.ctrlKey||e.metaKey) && e.key==='Enter'){
        e.preventDefault();
        mbAddSubmit();
    }
});

/* ══════════════════════════
   비전AI OCR
   ══════════════════════════ */
window.mbaOcrProcess = function(files){
    if(!files||!files[0]) return;
    var file=files[0];
    var status=$('mbaOcrStatus'); if(!status) return;

    var allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    if(allowed.indexOf(file.type)===-1){
        if(SHV.toast) SHV.toast.warn('이미지(JPG,PNG) 또는 PDF만 가능합니다');
        return;
    }
    if(file.size>10*1024*1024){
        if(SHV.toast) SHV.toast.warn('10MB 이하 파일만 가능합니다');
        return;
    }

    status.innerHTML='<span class="spinner spinner-sm mr-1"></span>비전AI 분석 중...';
    var docType=($('mba_ocr_doc')||{value:'business_reg'}).value;

    var fd=new FormData();
    fd.append('todo','ocr_scan');
    fd.append('doc_type',docType);
    fd.append('image',file);

    SHV.api.upload('dist_process/saas/OCR.php',fd)
        .then(function(res){
            /* 파일 input 초기화 */
            var fi=$('mba_ocr_file'); if(fi) fi.value='';
            var ci=$('mba_ocr_camera'); if(ci) ci.value='';

            if(res.ok && res.data && res.data.fields){
                var f=res.data.fields;
                var map={
                    'name':'mba_name','card_number':'mba_card','ceo':'mba_ceo','ceo_name':'mba_ceo',
                    'business_type':'mba_biz_type','business_class':'mba_biz_class',
                    'tel':'mba_tel','hp':'mba_hp','fax':'mba_fax','email':'mba_email',
                    'address':'mba_addr','zipcode':'mba_zipcode'
                };
                for(var key in map){
                    if(f[key]){
                        var el=$(map[key]);
                        if(el) el.value=f[key].trim();
                    }
                }
                /* 사업자번호 자동포맷 + 중복체크 트리거 */
                var cardEl=$('mba_card');
                if(cardEl && cardEl.value) cardEl.dispatchEvent(new Event('input'));

                var engine=res.data.engine||'AI';
                status.innerHTML='<i class="fa fa-check-circle text-accent mr-1"></i>'+escH(engine)+' 인식 완료';
                if(SHV.toast) SHV.toast.success('자동입력 완료! 내용을 확인하세요.');
            } else {
                status.innerHTML='<i class="fa fa-exclamation-triangle text-danger mr-1"></i>'+escH(res.message||'인식 실패');
                if(SHV.toast) SHV.toast.error(res.message||'OCR 실패');
            }
        })
        .catch(function(){
            var fi=$('mba_ocr_file'); if(fi) fi.value='';
            var ci=$('mba_ocr_camera'); if(ci) ci.value='';
            status.innerHTML='<i class="fa fa-exclamation-triangle text-danger mr-1"></i>서버 오류';
            if(SHV.toast) SHV.toast.error('OCR 서버 오류');
        });
};

/* 자동 포커스 */
setTimeout(function(){ var n=$('mba_name'); if(n) n.focus(); },80);

})();
</script>
