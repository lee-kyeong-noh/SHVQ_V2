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
<div id="pbaFormBody">
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="pba_name">이름 <span class="required">*</span></label>
            <input type="text" id="pba_name" class="form-input" placeholder="이름" maxlength="50" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pba_hp">연락처</label>
            <input type="text" id="pba_hp" class="form-input" placeholder="010-0000-0000" maxlength="13" autocomplete="off"
                oninput="this.value=fmtPhone(this.value)">
        </div>
    </div>
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="pba_email">이메일</label>
            <input type="text" id="pba_email" class="form-input" placeholder="email" maxlength="100" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pba_tel">전화번호</label>
            <input type="text" id="pba_tel" class="form-input" placeholder="02-0000-0000" maxlength="14" autocomplete="off"
                oninput="this.value=fmtPhone(this.value)">
        </div>
    </div>
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="pba_department">부서</label>
            <input type="text" id="pba_department" class="form-input" placeholder="부서" maxlength="50" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pba_position">직책</label>
            <input type="text" id="pba_position" class="form-input" placeholder="직책" maxlength="50" autocomplete="off">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="pba_memo">비고</label>
        <input type="text" id="pba_memo" class="form-input" placeholder="비고" maxlength="200" autocomplete="off">
    </div>
</div>
<div class="modal-form-footer">
    <span id="pbaErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="pbaSubmitBtn" onclick="pbaSubmit()">
        <i class="fa fa-check"></i> <?= $isModify ? '수정' : '등록' ?>
    </button>
</div>
<script>
(function(){
'use strict';
var _memberIdx=<?=$memberIdx?>, _isModify=<?=$isModify?'true':'false'?>, _modifyIdx=<?=$modifyIdx?>;
function $(id){return document.getElementById(id);}
function showErr(m){var e=$('pbaErrMsg');if(!e)return;if(m){e.textContent=m;e.style.display='block';}else{e.style.display='none';}}
function setLoading(on){var b=$('pbaSubmitBtn');if(!b)return;b.disabled=on;b.innerHTML=on?'<span class="spinner spinner-sm mr-1"></span>저장 중...':'<i class="fa fa-check"></i> <?=$isModify?"수정":"등록"?>';}
window.fmtPhone=window.fmtPhone||function(v){var d=v.replace(/\D/g,'').slice(0,11);if(d.startsWith('02')){if(d.length<=2)return d;if(d.length<=6)return d.slice(0,2)+'-'+d.slice(2);if(d.length<=9)return d.slice(0,2)+'-'+d.slice(2,5)+'-'+d.slice(5);return d.slice(0,2)+'-'+d.slice(2,6)+'-'+d.slice(6,10);}else{if(d.length<=3)return d;if(d.length<=6)return d.slice(0,3)+'-'+d.slice(3);if(d.length<=10)return d.slice(0,3)+'-'+d.slice(3,6)+'-'+d.slice(6);return d.slice(0,3)+'-'+d.slice(3,7)+'-'+d.slice(7,11);}};

function loadModifyData(){
    if(!_isModify||!_modifyIdx) return;
    SHV.api.get('dist_process/saas/PhoneBook.php',{todo:'detail',idx:_modifyIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data;
            if($('pba_name'))       $('pba_name').value=d.name||'';
            if($('pba_hp'))         $('pba_hp').value=d.hp||'';
            if($('pba_email'))      $('pba_email').value=d.email||'';
            if($('pba_tel'))        $('pba_tel').value=d.tel||'';
            if($('pba_department')) $('pba_department').value=d.department||'';
            if($('pba_position'))   $('pba_position').value=d.position||'';
            if($('pba_memo'))       $('pba_memo').value=d.memo||d.comment||'';
        });
}

window.pbaSubmit=function(){
    showErr('');
    var name=(($('pba_name')||{}).value||'').trim();
    if(!name){showErr('이름은 필수 항목입니다.');if($('pba_name'))$('pba_name').focus();return;}
    setLoading(true);
    var fd=new FormData();
    fd.append('todo',_isModify?'update':'insert');
    if(_isModify) fd.append('idx',_modifyIdx);
    fd.append('member_idx',_memberIdx);
    fd.append('name',name);
    fd.append('hp',($('pba_hp')||{}).value||'');
    fd.append('email',($('pba_email')||{}).value||'');
    fd.append('tel',($('pba_tel')||{}).value||'');
    fd.append('department',($('pba_department')||{}).value||'');
    fd.append('position',($('pba_position')||{}).value||'');
    fd.append('memo',($('pba_memo')||{}).value||'');
    SHV.api.upload('dist_process/saas/PhoneBook.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){SHV.modal.close();if(SHV.toast)SHV.toast.success(_isModify?'연락처가 수정되었습니다.':'연락처가 등록되었습니다.');}
            else{showErr(res.message||'처리에 실패하였습니다.');}
        })
        .catch(function(){setLoading(false);showErr('네트워크 오류');});
};

if(_isModify) setTimeout(loadModifyData,200);
})();
</script>
