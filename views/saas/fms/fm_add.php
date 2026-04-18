<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>'; exit; }
$memberIdx = (int)($_GET['member_idx'] ?? 0);
$isModify  = ($_GET['todo'] ?? '') === 'modify';
$modifyIdx = (int)($_GET['idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<div id="fmaFormBody">
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="fma_name">성명 <span class="required">*</span></label>
            <input type="text" id="fma_name" class="form-input" placeholder="성명" maxlength="30" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="fma_passwd">비밀번호 <?= $isModify ? '<small class="text-3">(수정시만)</small>' : '' ?></label>
            <input type="password" id="fma_passwd" class="form-input" placeholder="비밀번호" maxlength="20" autocomplete="new-password">
        </div>
    </div>
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="fma_sosok">소속/부서</label>
            <input type="text" id="fma_sosok" class="form-input" placeholder="소속/부서" maxlength="100" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="fma_part">직급/직책</label>
            <input type="text" id="fma_part" class="form-input" placeholder="직급/직책" maxlength="30" autocomplete="off">
        </div>
    </div>
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="fma_hp">연락처</label>
            <input type="text" id="fma_hp" class="form-input" placeholder="010-0000-0000" maxlength="13" autocomplete="off"
                oninput="this.value=fmtPhone(this.value)">
        </div>
        <div class="form-group">
            <label class="form-label" for="fma_email">이메일</label>
            <input type="text" id="fma_email" class="form-input" placeholder="email" maxlength="100" autocomplete="off">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="fma_comment">비고</label>
        <input type="text" id="fma_comment" class="form-input" placeholder="비고" maxlength="30" autocomplete="off">
    </div>
</div>
<div class="modal-form-footer">
    <span id="fmaErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="fmaSubmitBtn" onclick="fmaSubmit()">
        <i class="fa fa-check"></i> <?= $isModify ? '수정' : '등록' ?>
    </button>
</div>
<script>
(function(){
'use strict';
var _memberIdx=<?=$memberIdx?>, _isModify=<?=$isModify?'true':'false'?>, _modifyIdx=<?=$modifyIdx?>;
function $(id){return document.getElementById(id);}
function showErr(m){var e=$('fmaErrMsg');if(!e)return;if(m){e.textContent=m;e.style.display='block';}else{e.style.display='none';}}
function setLoading(on){var b=$('fmaSubmitBtn');if(!b)return;b.disabled=on;b.innerHTML=on?'<span class="spinner spinner-sm mr-1"></span>저장 중...':'<i class="fa fa-check"></i> <?=$isModify?"수정":"등록"?>';}
window.fmtPhone=window.fmtPhone||function(v){var d=v.replace(/\D/g,'').slice(0,11);if(d.startsWith('02')){if(d.length<=2)return d;if(d.length<=6)return d.slice(0,2)+'-'+d.slice(2);if(d.length<=9)return d.slice(0,2)+'-'+d.slice(2,5)+'-'+d.slice(5);return d.slice(0,2)+'-'+d.slice(2,6)+'-'+d.slice(6,10);}else{if(d.length<=3)return d;if(d.length<=6)return d.slice(0,3)+'-'+d.slice(3);if(d.length<=10)return d.slice(0,3)+'-'+d.slice(3,6)+'-'+d.slice(6);return d.slice(0,3)+'-'+d.slice(3,7)+'-'+d.slice(7,11);}};

function loadModifyData(){
    if(!_isModify||!_modifyIdx) return;
    SHV.api.get('dist_process/saas/FieldManager.php',{todo:'detail',idx:_modifyIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data;
            if($('fma_name'))    $('fma_name').value=d.name||'';
            if($('fma_sosok'))   $('fma_sosok').value=d.sosok||'';
            if($('fma_part'))    $('fma_part').value=d.part||'';
            if($('fma_hp'))      $('fma_hp').value=d.hp||'';
            if($('fma_email'))   $('fma_email').value=d.email||'';
            if($('fma_comment')) $('fma_comment').value=d.comment||'';
        });
}

window.fmaSubmit=function(){
    showErr('');
    var name=(($('fma_name')||{}).value||'').trim();
    if(!name){showErr('성명은 필수 항목입니다.');if($('fma_name'))$('fma_name').focus();return;}
    setLoading(true);
    var fd=new FormData();
    fd.append('todo',_isModify?'update':'insert');
    if(_isModify) fd.append('idx',_modifyIdx);
    fd.append('member_idx',_memberIdx);
    fd.append('name',name);
    fd.append('passwd',($('fma_passwd')||{}).value||'');
    fd.append('sosok',($('fma_sosok')||{}).value||'');
    fd.append('part',($('fma_part')||{}).value||'');
    fd.append('hp',($('fma_hp')||{}).value||'');
    fd.append('email',($('fma_email')||{}).value||'');
    fd.append('comment',($('fma_comment')||{}).value||'');
    SHV.api.upload('dist_process/saas/FieldManager.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){SHV.modal.close();if(SHV.toast)SHV.toast.success(_isModify?'현장소장이 수정되었습니다.':'현장소장이 등록되었습니다.');}
            else{showErr(res.message||'처리에 실패하였습니다.');}
        })
        .catch(function(){setLoading(false);showErr('네트워크 오류');});
};

if(_isModify) setTimeout(loadModifyData,200);
})();
</script>
