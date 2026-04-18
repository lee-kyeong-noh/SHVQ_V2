<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
session_name(shvEnv('SESSION_NAME', 'SHVQSESSID'));
session_set_cookie_params(['lifetime'=>shvEnvInt('SESSION_LIFETIME',7200),'path'=>'/','domain'=>'','secure'=>shvEnvBool('SESSION_SECURE_COOKIE',true),'httponly'=>shvEnvBool('SESSION_HTTP_ONLY',true),'samesite'=>shvEnv('SESSION_SAME_SITE','Lax')]);
session_start();
if (empty($_SESSION['auth']['user_pk'])) { http_response_code(401); echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>'; exit; }
$headIdx = (int)($_GET['head_idx'] ?? 0);
if ($headIdx <= 0) { echo '<p class="text-center p-8 text-danger">잘못된 접근</p>'; exit; }
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="hsBody">
    <div class="hs-grid">

        <!-- ── 좌측: 조직도 설정 + 사업장 구조 ── -->
        <div>
            <div class="hs-section">
                <div class="hs-section-title"><i class="fa fa-sitemap text-accent mr-2"></i>조직도 설정</div>
                <div class="hda-grid-2">
                    <div class="form-group">
                        <label class="form-label">본사구조</label>
                        <select id="hs_structure" class="form-select">
                            <option value="">선택</option>
                            <option value="단일">단일</option>
                            <option value="지사">지사</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">유형</label>
                        <select id="hs_type" class="form-select">
                            <option value="">선택</option>
                            <option value="법인">법인</option>
                            <option value="개인">개인</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">계약유형</label>
                        <select id="hs_contract" class="form-select">
                            <option value="">없음</option>
                            <option value="단일">단일</option>
                            <option value="협력">협력</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">주요사업</label>
                        <input type="text" id="hs_main_biz" class="form-input" placeholder="주요사업" maxlength="120">
                    </div>
                </div>
            </div>

            <div class="hs-section">
                <div class="hs-section-title"><i class="fa fa-building-o text-accent mr-2"></i>사업장 구조</div>
                <div id="hsBranchList">
                    <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
                </div>
            </div>
        </div>

        <!-- ── 우측: 폴더 관리 + 직급/직책 ── -->
        <div>
            <div class="hs-section">
                <div class="hs-section-title">
                    <span><i class="fa fa-folder-open text-accent mr-2"></i>조직도 폴더 관리</span>
                    <button class="btn btn-ghost btn-sm text-xs" onclick="hsAddFolder(0)"><i class="fa fa-plus mr-1"></i>추가</button>
                </div>
                <div id="hsFolderList">
                    <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
                </div>
            </div>

            <div class="hs-section">
                <div class="hs-section-title"><i class="fa fa-id-badge text-accent mr-2"></i>직급/직책 옵션</div>
                <p class="text-xs text-3 mb-2">순서대로 조직도에 표시됩니다.</p>
                <div class="hda-grid-2">
                    <div>
                        <div class="text-xs font-semibold text-2 mb-1">직급 (상위→하위)</div>
                        <div id="hsGradeList" class="hs-opt-list"></div>
                        <div class="flex gap-1 mt-1">
                            <input type="text" id="hsGradeInput" class="form-input flex-1" placeholder="직급명" maxlength="20" onkeydown="if(event.key==='Enter')hsOptAdd('grade')">
                            <button class="btn btn-ghost btn-sm text-xs" onclick="hsOptAdd('grade')"><i class="fa fa-plus"></i></button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-2 mb-1">직책 (상위→하위)</div>
                        <div id="hsTitleList" class="hs-opt-list"></div>
                        <div class="flex gap-1 mt-1">
                            <input type="text" id="hsTitleInput" class="form-input flex-1" placeholder="직책명" maxlength="20" onkeydown="if(event.key==='Enter')hsOptAdd('title')">
                            <button class="btn btn-ghost btn-sm text-xs" onclick="hsOptAdd('title')"><i class="fa fa-plus"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hs-section">
                <div class="form-group">
                    <label class="form-label">메모</label>
                    <textarea id="hs_memo" class="form-input" rows="3" placeholder="메모" maxlength="2000"></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── 모달 푸터 ── -->
<div class="modal-form-footer">
    <span id="hsErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="hsSaveBtn" onclick="hsSave()">
        <i class="fa fa-save mr-1"></i>저장
    </button>
</div>

<script>
(function(){
'use strict';
var _headIdx = <?= $headIdx ?>;
var _grades = [];
var _titles = [];
var _branches = [];
var _folders = [];

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

/* ── 데이터 로드 ── */
function loadSettings(){
    SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'detail',idx:_headIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data;
            if($('hs_structure')) $('hs_structure').value=d.head_structure||'';
            if($('hs_type')) $('hs_type').value=d.head_type||'';
            if($('hs_contract')) $('hs_contract').value=d.contract_type||'';
            if($('hs_main_biz')) $('hs_main_biz').value=d.main_business||'';
            if($('hs_memo')) $('hs_memo').value=d.memo||'';
            _grades = (d.grade_options||'매니저,책임,수석,기사,기원,기장').split(',').map(function(s){return s.trim();}).filter(Boolean);
            _titles = (d.title_options||'없음,팀장,지사장,담당').split(',').map(function(s){return s.trim();}).filter(Boolean);
            renderOpts('grade');
            renderOpts('title');
        });

    SHV.api.get('dist_process/saas/Member.php',{todo:'list',head_idx:_headIdx,limit:500})
        .then(function(res){
            if(!res.ok) return;
            _branches = res.data.data||[];
            renderBranches();
        });

    SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'org_folder_list',head_idx:_headIdx})
        .then(function(res){
            if(!res.ok) return;
            _folders = res.data.data||res.data||[];
            renderFolders();
        });
}

/* ── 사업장 구조 렌더링 ── */
function renderBranches(){
    var el=$('hsBranchList'); if(!el) return;
    if(!_branches.length){
        el.innerHTML='<div class="text-center p-4 text-3 text-xs">연결된 사업장 없음</div>';
        return;
    }
    var html='';
    _branches.forEach(function(b,i){
        var isP=(b.member_status||b.status||'')==='예정';
        var ls=b.link_status||'연결';
        html+='<div class="hs-branch-item">'
            +'<span class="hs-branch-icon '+(isP?'hs-planned':'hs-normal')+'"><i class="fa fa-'+(isP?'clock-o':'building-o')+'"></i></span>'
            +'<div class="flex-1 min-w-0">'
            +'<div class="text-sm font-semibold text-1 truncate">'+escH(b.name)+'</div>'
            +'<div class="text-xs text-3">'+(isP?'예정':'사업장')+' · '+ls+(b.site_count?' · 현장 '+b.site_count:'')+'</div>'
            +'</div>'
            +'<div class="flex gap-1">'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="hsViewBranch('+b.idx+')" title="상세보기"><i class="fa fa-eye"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="hsMoveB('+b.idx+',\'up\')" title="위로"><i class="fa fa-arrow-up"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="hsMoveB('+b.idx+',\'down\')" title="아래로"><i class="fa fa-arrow-down"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="hsUnlink('+b.idx+',\''+escH(b.name).replace(/'/g,"\\'")+'\')" title="연결해제"><i class="fa fa-unlink"></i></button>'
            +'</div></div>';
    });
    el.innerHTML=html;
}

/* ── 사업장 순서 이동 ── */
window.hsMoveB=function(idx,dir){
    var pos=-1;
    _branches.forEach(function(b,i){if(parseInt(b.idx,10)===idx) pos=i;});
    if(pos<0) return;
    if(dir==='up'&&pos===0) return;
    if(dir==='down'&&pos===_branches.length-1) return;
    var swap=dir==='up'?pos-1:pos+1;
    var tmp=_branches[pos]; _branches[pos]=_branches[swap]; _branches[swap]=tmp;
    renderBranches();
    var order=_branches.map(function(b){return parseInt(b.idx,10);});
    SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'reorder_branches',head_idx:_headIdx,order:JSON.stringify(order)})
        .then(function(res){ if(res.ok&&SHV.toast) SHV.toast.success('순서 변경됨'); });
};

/* ── 폴더 렌더링 ── */
function renderFolders(){
    var el=$('hsFolderList'); if(!el) return;
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
        if(depth<2) html+='<button class="btn btn-ghost btn-sm text-xs" onclick="hsAddFolder('+n.idx+')" title="하위추가"><i class="fa fa-plus"></i></button>';
        html+='<button class="btn btn-ghost btn-sm text-xs" onclick="hsRenameFolder('+n.idx+',\''+escH(n.name).replace(/'/g,"\\'")+'\')" title="이름변경"><i class="fa fa-pencil"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="hsDeleteFolder('+n.idx+')" title="삭제"><i class="fa fa-times"></i></button>'
            +'</div></div>';
        if(n.children&&n.children.length) html+=renderFolderTree(n.children,depth+1);
    });
    return html;
}

/* ── 폴더 CRUD ── */
window.hsAddFolder=function(parentIdx){
    if(!SHV.prompt) return;
    SHV.prompt('폴더 추가','폴더명을 입력하세요',function(name){
        SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_insert',head_idx:_headIdx,parent_idx:parentIdx,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('추가됨'); reloadFolders(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
            });
    });
};
window.hsRenameFolder=function(idx,old){
    if(!SHV.prompt) return;
    SHV.prompt('폴더 이름 수정','폴더명',function(name){
        SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_update',idx:idx,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('변경됨'); reloadFolders(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
            });
    },old);
};
window.hsDeleteFolder=function(idx){
    if(SHV.confirm){
        SHV.confirm({title:'폴더 삭제',message:'삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_delete',idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('삭제됨'); reloadFolders(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
                    });
            }
        });
    }
};

/* ── 사업장 상세보기 ── */
window.hsViewBranch=function(memberIdx){
    SHV.modal.close();
    if(SHV&&SHV.router) SHV.router.navigate('member_branch_view',{member_idx:memberIdx});
};

/* ── 사업장 연결해제 ── */
window.hsUnlink=function(memberIdx,name){
    if(SHV.confirm){
        SHV.confirm({
            title:'연결 해제',
            message:'<b>'+escH(name)+'</b> 사업장의 본사 연결을 해제하시겠습니까?',
            type:'danger',
            confirmText:'해제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/Member.php',{todo:'unlink_head',member_idx:memberIdx})
                    .then(function(res){
                        if(res.ok){
                            if(SHV.toast) SHV.toast.success(res.message||'연결이 해제되었습니다.');
                            _branches=_branches.filter(function(b){return parseInt(b.idx,10)!==memberIdx;});
                            renderBranches();
                        } else {
                            if(SHV.toast) SHV.toast.error(res.message||'해제 실패');
                        }
                    })
                    .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
            }
        });
    }
};

function reloadFolders(){
    SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'org_folder_list',head_idx:_headIdx})
        .then(function(res){ _folders=res.ok?(res.data.data||res.data||[]):[]; renderFolders(); });
}

/* ── 직급/직책 옵션 ── */
function renderOpts(type){
    var arr=type==='grade'?_grades:_titles;
    var el=$(type==='grade'?'hsGradeList':'hsTitleList'); if(!el) return;
    if(!arr.length){ el.innerHTML='<div class="text-xs text-3 p-2">항목 없음</div>'; return; }
    var html='';
    arr.forEach(function(v,i){
        html+='<div class="hs-opt-item">'
            +'<span class="text-xs text-3 mr-1">'+(i+1)+'</span>'
            +'<span class="text-sm font-semibold text-1 flex-1">'+escH(v)+'</span>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="hsOptMove(\''+type+'\','+i+',-1)"><i class="fa fa-arrow-up"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs" onclick="hsOptMove(\''+type+'\','+i+',1)"><i class="fa fa-arrow-down"></i></button>'
            +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="hsOptDel(\''+type+'\','+i+')"><i class="fa fa-times"></i></button>'
            +'</div>';
    });
    el.innerHTML=html;
}

window.hsOptAdd=function(type){
    var input=$(type==='grade'?'hsGradeInput':'hsTitleInput');
    var val=input.value.trim(); if(!val) return;
    var arr=type==='grade'?_grades:_titles;
    if(arr.indexOf(val)>-1){ if(SHV.toast) SHV.toast.warn('이미 존재'); return; }
    arr.push(val); input.value='';
    renderOpts(type);
};
window.hsOptDel=function(type,i){
    var arr=type==='grade'?_grades:_titles;
    arr.splice(i,1); renderOpts(type);
};
window.hsOptMove=function(type,i,dir){
    var arr=type==='grade'?_grades:_titles;
    var ni=i+dir; if(ni<0||ni>=arr.length) return;
    var tmp=arr[i]; arr[i]=arr[ni]; arr[ni]=tmp;
    renderOpts(type);
};

/* ── 설정 저장 ── */
window.hsSave=function(){
    var fd=new FormData();
    fd.append('todo','update_settings');
    fd.append('idx',_headIdx);
    fd.append('head_structure',($('hs_structure')||{}).value||'');
    fd.append('head_type',($('hs_type')||{}).value||'');
    fd.append('contract_type',($('hs_contract')||{}).value||'');
    fd.append('main_business',($('hs_main_biz')||{}).value||'');
    fd.append('grade_options',_grades.join(','));
    fd.append('title_options',_titles.join(','));
    fd.append('memo',($('hs_memo')||{}).value||'');

    SHV.api.upload('dist_process/saas/HeadOffice.php',fd)
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success('설정이 저장되었습니다.');
                SHV.modal.close();
            } else {
                var err=$('hsErrMsg'); if(err){err.textContent=res.message||'저장 실패';err.style.display='block';}
            }
        })
        .catch(function(){ var err=$('hsErrMsg'); if(err){err.textContent='네트워크 오류';err.style.display='block';} });
};

/* ── 초기 로드 ── */
loadSettings();
})();
</script>
