<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>'; exit; }
$memberIdx = (int)($_GET['member_idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="lhBody">
    <p class="text-sm text-2 mb-3 p-3 card">
        <i class="fa fa-building-o text-accent mr-1"></i>사업장을 연결할 본사를 선택하세요.
    </p>

    <input type="text" id="lhSearch" class="form-input mb-3" placeholder="본사명, 코드, 대표자 검색..." oninput="lhFilter()" autofocus>

    <div id="lhList" class="lh-list">
        <div class="text-center p-8 text-3">로딩 중...</div>
    </div>

    <div class="flex justify-between mt-3">
        <button class="btn btn-glass-primary btn-sm" onclick="lhCreateNew()"><i class="fa fa-plus mr-1"></i>본사 새로 생성</button>
    </div>
</div>

<script>
(function(){
'use strict';
var _memberIdx = <?= $memberIdx ?>;
var _heads = [];

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function loadHeads(){
    SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'list',limit:500})
        .then(function(res){
            if(!res.ok) return;
            _heads = res.data.data||[];
            renderList(_heads);
        });
}

function renderList(rows){
    var el=$('lhList'); if(!el) return;
    if(!rows.length){
        el.innerHTML='<div class="text-center p-8 text-3"><i class="fa fa-inbox text-4xl opacity-30"></i><p class="mt-2">등록된 본사가 없습니다</p></div>';
        return;
    }
    var html='';
    rows.forEach(function(h){
        var search = ((h.name||'')+(h.head_number||'')+(h.ceo||'')).toLowerCase();
        html+='<div class="lh-item" data-search="'+escH(search)+'">'
            +'<div class="lh-icon"><i class="fa fa-building"></i></div>'
            +'<div class="flex-1 min-w-0">'
            +'<div class="text-sm font-bold text-1">'+escH(h.name)
            +(h.head_number?' <span class="text-xs text-3">'+escH(h.head_number)+'</span>':'')
            +(h.head_structure?' <span class="dv-pill">'+escH(h.head_structure)+'</span>':'')
            +'</div>'
            +'<div class="text-xs text-3 mt-px">'
            +(h.ceo?'대표: '+escH(h.ceo):'')
            +(h.card_number?' · '+escH(h.card_number):'')
            +'</div></div>'
            +'<button class="btn btn-glass-primary btn-sm" onclick="lhLink('+h.idx+')"><i class="fa fa-link mr-1"></i>연결</button>'
            +'</div>';
    });
    el.innerHTML=html;
}

window.lhFilter=function(){
    var q=($('lhSearch')||{value:''}).value.toLowerCase().trim();
    document.querySelectorAll('.lh-item').forEach(function(el){
        el.classList.toggle('hidden', q && (el.dataset.search||'').indexOf(q)===-1);
    });
};

window.lhLink=function(headIdx){
    SHV.api.post('dist_process/saas/Member.php',{todo:'link_head',member_idx:_memberIdx,head_idx:headIdx})
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(res.message||'본사에 연결되었습니다.');
                SHV.modal.close();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'연결 실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

window.lhCreateNew=function(){
    var el=$('lhBody'); if(!el) return;
    el.innerHTML='<div class="p-4">'
        +'<h3 class="text-md font-bold text-1 mb-3"><i class="fa fa-plus-circle text-accent mr-2"></i>본사 생성 방법</h3>'
        +'<div class="flex flex-col gap-3">'
        +'<div class="card p-4 cursor-pointer" onclick="lhCreateFromMember()" onmouseover="this.classList.add(\'border-accent\')" onmouseout="this.classList.remove(\'border-accent\')">'
        +'<div class="text-sm font-bold text-1"><i class="fa fa-copy text-accent mr-2"></i>현재 사업장 정보로 생성</div>'
        +'<div class="text-xs text-3 mt-1">사업장명, 사업자번호, 대표자, 주소 등을 본사 정보로 복사합니다</div>'
        +'</div>'
        +'<div class="card p-4 cursor-pointer" onclick="lhCreateManual()" onmouseover="this.classList.add(\'border-accent\')" onmouseout="this.classList.remove(\'border-accent\')">'
        +'<div class="text-sm font-bold text-1"><i class="fa fa-file-o text-accent mr-2"></i>새로 입력하여 생성</div>'
        +'<div class="text-xs text-3 mt-1">본사 등록 폼을 열어 직접 입력합니다</div>'
        +'</div>'
        +'</div>'
        +'<div class="mt-3 text-right"><button class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button></div>'
        +'</div>';
};

window.lhCreateFromMember=function(){
    SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'create_head_from_member',member_idx:_memberIdx})
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(res.message||'본사가 생성되었습니다.');
                SHV.modal.close();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'생성 실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

window.lhCreateManual=function(){
    SHV.modal.open('member_head_add.php?link_member='+_memberIdx, '본사 등록', 'lg');
};

loadHeads();
})();
</script>
