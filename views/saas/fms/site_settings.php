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
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="ssFormBody" class="p-4">

    <div class="manual-card mb-3">
        <div class="manual-sub-title mb-2">탭 표시 설정</div>
        <p class="text-xs text-3 mb-3">현장 상세 페이지에서 표시할 탭을 선택합니다.</p>
        <div id="ssTabList" class="flex flex-col gap-2">
            <div class="text-center p-4 text-3">로딩 중...</div>
        </div>
    </div>

    <!-- ── 현장연락처 필수항목 ── -->
    <div class="manual-card mb-3">
        <div class="manual-sub-title mb-2">현장연락처 필수항목</div>
        <p class="text-xs text-3 mb-3">연락처 등록 시 필수 입력 항목을 설정합니다.</p>
        <div id="ssContactReq" class="flex flex-col gap-2"></div>
    </div>

    <!-- ── 현장 엑셀 업로드 ── -->
    <div class="manual-card mb-3">
        <div class="manual-sub-title mb-2">현장 엑셀 업로드</div>
        <p class="text-xs text-3 mb-3">엑셀 파일로 현장을 일괄 등록합니다.</p>
        <div class="flex gap-2 mb-2">
            <button type="button" class="btn btn-outline btn-sm" onclick="ssDownloadTemplate()"><i class="fa fa-download mr-1"></i>템플릿 다운로드</button>
            <button type="button" class="btn btn-glass-primary btn-sm" onclick="ssUploadExcel()"><i class="fa fa-upload mr-1"></i>엑셀 업로드</button>
        </div>
        <div id="ssExcelPreview" class="hidden"></div>
    </div>

</div>

<!-- ── 모달 푸터 ── -->
<div class="modal-form-footer">
    <span id="ssErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="ssSubmitBtn" onclick="ssSave()">
        <i class="fa fa-check"></i> 저장
    </button>
</div>

<script>
(function(){
'use strict';

var _siteIdx = <?= $siteIdx ?>;
var _tabs = {};

var TAB_LABELS = {
    info: '기본정보',
    estimate: '견적',
    bill: '수금',
    contact: '연락처',
    floor: '도면',
    attach: '첨부',
    subcontract: '도급',
    access: '출입',
    mail: '메일',
    memo: '특기사항',
    pjt: 'PJT'
};

var TAB_ICONS = {
    info: 'fa-info-circle',
    estimate: 'fa-file-text-o',
    bill: 'fa-krw',
    contact: 'fa-address-book-o',
    floor: 'fa-file-image-o',
    attach: 'fa-paperclip',
    subcontract: 'fa-handshake-o',
    access: 'fa-sign-in',
    mail: 'fa-envelope-o',
    memo: 'fa-bookmark',
    pjt: 'fa-th-list'
};

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function showErr(msg){
    var el=$('ssErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; } else { el.style.display='none'; }
}

/* ── 설정 로드 ── */
function loadSettings(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'site_settings',site_idx:_siteIdx})
        .then(function(res){
            if(!res.ok){ $('ssTabList').innerHTML='<div class="text-center p-4 text-danger">설정 로드 실패</div>'; return; }
            _tabs=res.data.tabs||res.data||{};
            _contactReq=res.data.contact_required||{};
            renderTabs();
            renderContactReq();
        })
        .catch(function(){ $('ssTabList').innerHTML='<div class="text-center p-4 text-danger">네트워크 오류</div>'; });
}

/* ── 탭 토글 렌더링 ── */
function renderTabs(){
    var list=$('ssTabList'); if(!list) return;
    var order=['info','estimate','bill','contact','floor','attach','subcontract','access','mail','memo','pjt'];
    var h='';
    order.forEach(function(key){
        var on=(_tabs[key]===1||_tabs[key]==='1');
        var label=TAB_LABELS[key]||key;
        var icon=TAB_ICONS[key]||'fa-circle-o';
        var disabled=(key==='info');
        h+='<label class="ss-tab-row'+(disabled?' ss-tab-disabled':'')+'">'
            +'<div class="flex items-center gap-2 flex-1">'
            +'<i class="fa '+icon+' text-3"></i>'
            +'<span class="text-sm font-medium text-1">'+escH(label)+'</span>'
            +'</div>'
            +'<input type="checkbox" class="ss-tab-check" data-key="'+key+'"'+(on?' checked':'')+(disabled?' disabled':'')+'/>'
            +'</label>';
    });
    list.innerHTML=h;
}

/* ── 저장 ── */
window.ssSave = function(){
    showErr('');
    var checks=document.querySelectorAll('.ss-tab-check');
    var tabs={};
    checks.forEach(function(ch){ tabs[ch.dataset.key]=ch.checked?1:0; });
    tabs.info=1; /* 기본정보는 항상 ON */

    var btn=$('ssSubmitBtn');
    if(btn){ btn.disabled=true; btn.innerHTML='<span class="spinner spinner-sm mr-1"></span>저장 중...'; }

    /* 연락처 필수항목 */
    var cReq={};
    document.querySelectorAll('.ss-contact-chk').forEach(function(ch){ cReq[ch.dataset.key]=ch.checked?1:0; });

    SHV.api.post('dist_process/saas/Site.php',{todo:'save_site_settings',site_idx:_siteIdx,tabs:JSON.stringify(tabs),contact_required:JSON.stringify(cReq)})
        .then(function(res){
            if(btn){ btn.disabled=false; btn.innerHTML='<i class="fa fa-check"></i> 저장'; }
            if(res.ok){
                SHV.modal.close();
                if(SHV.toast) SHV.toast.success('현장 설정이 저장되었습니다.');
            } else {
                showErr(res.message||'저장 실패');
            }
        })
        .catch(function(){
            if(btn){ btn.disabled=false; btn.innerHTML='<i class="fa fa-check"></i> 저장'; }
            showErr('네트워크 오류');
        });
};

/* ══════════════════════════
   연락처 필수항목
   ══════════════════════════ */
var CONTACT_FIELDS=[
    {key:'name',label:'성명'},{key:'phone',label:'전화번호'},{key:'email',label:'이메일'},
    {key:'company',label:'소속'},{key:'position',label:'직위'},{key:'work',label:'주요업무'},{key:'memo',label:'비고'}
];
var _contactReq={};

function renderContactReq(){
    var el=$('ssContactReq'); if(!el) return;
    var h='';
    CONTACT_FIELDS.forEach(function(f){
        var on=_contactReq[f.key];
        h+='<label class="ss-tab-row">'
            +'<div class="flex items-center gap-2 flex-1"><span class="text-sm font-medium text-1">'+escH(f.label)+'</span></div>'
            +'<input type="checkbox" class="ss-tab-check ss-contact-chk" data-key="'+f.key+'"'+(on?' checked':'')+'/>'
            +'</label>';
    });
    el.innerHTML=h;
}

/* ══════════════════════════
   엑셀 업로드
   ══════════════════════════ */
window.ssDownloadTemplate = function(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'excel_template',site_idx:_siteIdx})
        .then(function(res){
            if(res.ok && res.data.url) window.open(res.data.url,'_blank');
            else if(SHV.toast) SHV.toast.warn('템플릿 준비 중');
        }).catch(function(){});
};

window.ssUploadExcel = function(){
    var inp=document.createElement('input');
    inp.type='file'; inp.accept='.xlsx,.xls,.csv';
    inp.onchange=function(){
        if(!inp.files.length) return;
        var fd=new FormData();
        fd.append('todo','excel_upload');
        fd.append('site_idx',_siteIdx);
        fd.append('file',inp.files[0]);
        SHV.api.upload('dist_process/saas/Site.php',fd)
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success((res.data.count||0)+'건 등록 완료'); }
                else { if(SHV.toast) SHV.toast.error(res.message||'업로드 실패'); }
            }).catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
    };
    inp.click();
};

/* ── 초기화 ── */
loadSettings();
})();
</script>
