/* ══════════════════════════════════════
   SHVQ V2 — 조직도 공통 모듈 (org_chart.js)
   head_view + member_branch_view 공용
   SHV.orgChart.init({ headIdx, memberIdx, container })
   ══════════════════════════════════════ */
'use strict';
window.SHV = window.SHV || {};

(function(SHV){

var _cfg = { headIdx:0, memberIdx:0, container:'' };
var _contacts = [];
var _folders = [];
var _members = [];
var _gradeOpts = [];
var _titleOpts = [];
var _subTab = 'unassigned';
var _editMode = false;
var _ocEditCell = null;
var _scale = 1;
var _vpKey = '';

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }

/* ══════════════════════════
   init
   ══════════════════════════ */
SHV.orgChart = {
    init: function(cfg){
        _cfg = cfg || {};
        _vpKey = 'ocVp_'+(_cfg.headIdx||_cfg.memberIdx);
        var el = typeof _cfg.container === 'string' ? document.querySelector(_cfg.container) : _cfg.container;
        if(!el) return;
        el.innerHTML = buildShell();
        loadData();
    },
    refresh: function(){
        _contacts=[]; _folders=[]; _members=[];
        loadData();
    }
};

/* ── 쉘 HTML ── */
function buildShell(){
    return '<div class="oc-wrap">'
        +'<div class="oc-sub-tabs">'
        +'<button class="oc-sub-tab oc-sub-active" onclick="SHV._ocTab(\'unassigned\')"><i class="fa fa-inbox mr-1"></i>미배치 <span class="oc-sub-cnt" id="ocCntU">0</span></button>'
        +'<button class="oc-sub-tab" onclick="SHV._ocTab(\'tree\')"><i class="fa fa-share-alt mr-1"></i>조직도</button>'
        +'<button class="oc-sub-tab" onclick="SHV._ocTab(\'table\')"><i class="fa fa-list mr-1"></i>테이블</button>'
        +'<button class="oc-sub-tab" onclick="SHV._ocTab(\'hidden\')"><i class="fa fa-eye-slash mr-1"></i>숨김 <span class="oc-sub-cnt" id="ocCntH">0</span></button>'
        +'</div>'
        +'<div class="oc-toolbar">'
        +'<input type="text" id="ocSearch" class="form-input flex-1" placeholder="이름, 직급, 직책, 전화 검색..." oninput="SHV._ocFilter()">'
        +'<select id="ocFStatus" class="form-select oc-filter-sel" onchange="SHV._ocFilter()"><option value="">상태:전체</option><option value="재직중">재직중</option><option value="이직">이직</option><option value="퇴사">퇴사</option></select>'
        +'<select id="ocFGrade" class="form-select oc-filter-sel" onchange="SHV._ocFilter()"></select>'
        +'<select id="ocFTitle" class="form-select oc-filter-sel" onchange="SHV._ocFilter()"></select>'
        +'<span class="text-xs text-3" id="ocCount"></span>'
        +'<button class="btn btn-ghost btn-sm text-xs" id="ocEditBtn" onclick="SHV._ocToggleEdit()"><i class="fa fa-pencil mr-1"></i>수정</button>'
        +'<div id="ocZoomCtrl" class="oc-zoom-ctrl hidden">'
        +'<button class="btn btn-ghost btn-sm text-xs" onclick="SHV._ocZoom(-0.1)">－</button>'
        +'<span class="text-xs text-3" id="ocZoomLabel">100%</span>'
        +'<button class="btn btn-ghost btn-sm text-xs" onclick="SHV._ocZoom(0.1)">＋</button>'
        +'<button class="btn btn-ghost btn-sm text-xs" onclick="SHV._ocZoomFit()"><i class="fa fa-compress"></i></button>'
        +'</div>'
        +'</div>'
        +'<div id="ocViewUnassigned" class="oc-view"></div>'
        +'<div id="ocViewTree" class="oc-view oc-tree-wrap hidden"></div>'
        +'<div id="ocViewTable" class="oc-view hidden"></div>'
        +'<div id="ocViewHidden" class="oc-view hidden"></div>'
        +'</div>';
}

/* ── 서브탭 전환 ── */
SHV._ocTab = function(tab){
    _subTab = tab;
    document.querySelectorAll('.oc-sub-tab').forEach(function(t){ t.classList.remove('oc-sub-active'); });
    document.querySelectorAll('.oc-sub-tab').forEach(function(t){
        if(t.getAttribute('onclick').indexOf("'"+tab+"'")>-1) t.classList.add('oc-sub-active');
    });
    var views = {unassigned:'Unassigned',tree:'Tree',table:'Table',hidden:'Hidden'};
    Object.keys(views).forEach(function(k){
        var el=$('ocView'+views[k]); if(el) el.classList.toggle('hidden', k!==tab);
    });
    var zc=$('ocZoomCtrl'); if(zc) zc.classList.toggle('hidden', tab!=='tree');
    if(tab==='tree') setTimeout(function(){ if(!restoreViewport()) SHV._ocZoomFit(); },80);
    render();
};

/* ── 데이터 로드 ── */
function loadData(){
    var promises = [];
    if(_cfg.memberIdx){
        promises.push(SHV.api.get('dist_process/saas/PhoneBook.php',{todo:'list',member_idx:_cfg.memberIdx,limit:1000}).then(function(r){return r.ok?r.data.data||[]:[];}));
    } else if(_cfg.headIdx){
        promises.push(SHV.api.get('dist_process/saas/Member.php',{todo:'list',head_idx:_cfg.headIdx,limit:500}).then(function(r){
            if(!r.ok) return [];
            _members = r.data.data||[];
            return Promise.all(_members.map(function(m){
                return SHV.api.get('dist_process/saas/PhoneBook.php',{todo:'list',member_idx:m.idx,limit:1000}).then(function(r2){
                    var rows=r2.ok?r2.data.data||[]:[];
                    rows.forEach(function(c){c._branch=m.name;c._member_idx=m.idx;});
                    return rows;
                });
            })).then(function(arrs){ var a=[]; arrs.forEach(function(x){a=a.concat(x);}); return a; });
        }));
    }
    if(_cfg.headIdx){
        promises.push(SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'org_folder_list',head_idx:_cfg.headIdx}).then(function(r){return r.ok?(r.data.data||r.data||[]):[];}));
        promises.push(SHV.api.get('dist_process/saas/HeadOffice.php',{todo:'detail',idx:_cfg.headIdx}).then(function(r){
            if(!r.ok) return {};
            var d=r.data;
            _gradeOpts=(d.grade_options||'매니저,책임,수석,기사,기원,기장').split(',').map(function(s){return s.trim();}).filter(Boolean);
            _titleOpts=(d.title_options||'없음,팀장,지사장,담당').split(',').map(function(s){return s.trim();}).filter(Boolean);
            populateFilterSelects();
            return d;
        }));
    } else { promises.push(Promise.resolve([])); promises.push(Promise.resolve({})); }

    Promise.all(promises).then(function(results){
        _contacts=results[0]||[];
        _folders=Array.isArray(results[1])?results[1]:[];
        render();
    });
}

function populateFilterSelects(){
    var gSel=$('ocFGrade');
    if(gSel){ gSel.innerHTML='<option value="">직급:전체</option>'; _gradeOpts.forEach(function(v){ gSel.innerHTML+='<option value="'+escH(v)+'">'+escH(v)+'</option>'; }); }
    var tSel=$('ocFTitle');
    if(tSel){ tSel.innerHTML='<option value="">직책:전체</option>'; _titleOpts.forEach(function(v){ tSel.innerHTML+='<option value="'+escH(v)+'">'+escH(v)+'</option>'; }); }
}

/* ── 렌더링 분기 ── */
function render(){
    var visible=_contacts.filter(function(c){return !parseInt(c.is_hidden||0,10);});
    var hidden=_contacts.filter(function(c){return parseInt(c.is_hidden||0,10)===1;});
    var unassigned=visible.filter(function(c){return !parseInt(c.branch_folder_idx||0,10);});

    var uCnt=$('ocCntU'); if(uCnt) uCnt.textContent=unassigned.length;
    var hCnt=$('ocCntH'); if(hCnt) hCnt.textContent=hidden.length;

    renderUnassigned(unassigned);
    renderTree(visible);
    renderTable(visible);
    renderHidden(hidden);
    SHV._ocFilter();
}

/* ── 상태 배지 ── */
function statusPill(st){
    var cls={'재직중':'badge-status-진행','이직':'badge-status-예정','퇴사':'badge-status-중지'};
    return '<span class="badge-status '+(cls[st]||'badge-status-예정')+'">'+escH(st)+'</span>';
}

/* ══════════════════════════
   미배치 + 테이블 공통
   ══════════════════════════ */
function renderUnassigned(rows){
    var el=$('ocViewUnassigned'); if(!el) return;
    if(!rows.length){ el.innerHTML='<div class="text-center p-8 text-3"><i class="fa fa-check-circle text-4xl opacity-30"></i><p class="mt-2">미배치 인원이 없습니다</p></div>'; return; }
    el.innerHTML=buildContactTable(rows, true);
}

function renderTable(rows){
    var el=$('ocViewTable'); if(!el) return;
    if(!rows.length){ el.innerHTML='<div class="text-center p-8 text-3"><i class="fa fa-list text-4xl opacity-30"></i><p class="mt-2">연락처가 없습니다</p></div>'; return; }
    el.innerHTML=buildContactTable(rows, false);
}

function buildContactTable(rows, showBranch){
    var cols='<th>이름</th>';
    if(showBranch && _cfg.headIdx) cols='<th>사업장</th>'+cols;
    cols+='<th>직급</th><th>직책</th><th>전화</th><th>이메일</th><th>상태</th><th>주업무</th><th></th>';
    var html='<table class="tbl oc-tbl"><thead><tr>'+cols+'</tr></thead><tbody>';
    rows.forEach(function(c){
        var st=c.work_status||'재직중';
        var sd=((c.name||'')+(c.job_grade||'')+(c.job_title||'')+(c.hp||'')+(c.email||'')+(c._branch||'')+(c.main_work||'')).toLowerCase();
        html+='<tr class="oc-row" data-search="'+escH(sd)+'" data-status="'+escH(st)+'" data-grade="'+escH(c.job_grade||'')+'" data-title="'+escH(c.job_title||'')+'">';
        if(showBranch && _cfg.headIdx) html+='<td>'+escH(c._branch||c.member_name||'')+'</td>';
        html+='<td><b class="text-accent cursor-pointer" onclick="SHV._ocShowDetail('+c.idx+')">'+escH(c.name||'')+'</b></td>';
        html+=editCell(c,'job_grade')+editCell(c,'job_title');
        html+='<td class="oc-edit-cell whitespace-nowrap" data-idx="'+c.idx+'" data-field="hp" data-val="'+escH(c.hp||'')+'">'+(c.hp?'<a href="tel:'+escH(c.hp)+'" class="text-accent" onclick="event.stopPropagation()">'+escH(c.hp)+'</a>':'')+'</td>';
        html+=editCell(c,'email')+editCell(c,'work_status',statusPill(st))+editCell(c,'main_work');
        html+='<td class="whitespace-nowrap oc-del-cell hidden"><button class="btn btn-ghost btn-sm text-xs" onclick="SHV._ocDelete('+c.idx+')"><i class="fa fa-trash text-danger"></i></button></td>';
        html+='</tr>';
    });
    html+='</tbody></table>';
    return html;
}

function editCell(c,field,display){
    var val=c[field]||'';
    return '<td class="oc-edit-cell" data-idx="'+c.idx+'" data-field="'+field+'" data-val="'+escH(val)+'">'+(display||escH(val))+'</td>';
}

/* ══════════════════════════
   트리 서브탭 (폴더+연락처+DnD+줌)
   ══════════════════════════ */
function renderTree(visible){
    var el=$('ocViewTree'); if(!el) return;
    if(!_cfg.headIdx || !_members.length){
        el.innerHTML='<div class="text-center p-8 text-3"><i class="fa fa-share-alt text-4xl opacity-30"></i><p class="mt-2">사업장 정보 없음</p></div>';
        return;
    }

    /* 폴더 트리 빌드 */
    var fTree = buildFolderTree(_folders, 0);

    var html='<div id="ocDiagram" class="oc-diagram">';
    html+='<div class="hv-org-node hv-org-root"><i class="fa fa-building mr-2"></i>'+escH('본사')+'</div>';
    html+='<div class="hv-org-line"></div>';

    /* 폴더 → 사업장 매핑 */
    var unassignedMembers = _members.filter(function(m){ return !parseInt(m.org_folder_idx||0,10); });
    var items = [];
    fTree.forEach(function(f){ items.push({type:'folder',data:f}); });
    unassignedMembers.forEach(function(m){ items.push({type:'member',data:m}); });

    if(items.length){
        html+='<div class="hv-org-children">';
        items.forEach(function(it){
            if(it.type==='folder') html+=renderFolderNode(it.data, visible);
            else html+=renderMemberNode(it.data, visible);
        });
        html+='</div>';
    }
    html+='</div>';
    el.innerHTML=html;

    /* 줌 적용 */
    applyZoom();
    setupTreeEvents(el);
}

function buildFolderTree(folders, parentIdx){
    var r=[];
    folders.forEach(function(f){
        if(parseInt(f.parent_idx||0,10)===parentIdx){
            f.children=buildFolderTree(folders, parseInt(f.idx,10));
            r.push(f);
        }
    });
    return r;
}

function renderFolderNode(f, visible){
    var fIdx=parseInt(f.idx,10);
    var assignedMembers=_members.filter(function(m){return parseInt(m.org_folder_idx||0,10)===fIdx;});
    var html='<div class="hv-org-child"><div class="hv-org-line-h"></div>';
    html+='<div class="oc-folder-drop" data-folder="'+fIdx+'">';
    html+='<div class="hv-org-node oc-folder-node"><i class="fa fa-folder mr-1"></i>'+escH(f.name);
    if(_editMode){
        html+=' <button class="oc-node-btn" onclick="event.stopPropagation();SHV._ocRenameFolder('+fIdx+',\''+escH(f.name).replace(/'/g,"\\'")+'\')" title="이름변경"><i class="fa fa-pencil"></i></button>';
        html+=' <button class="oc-node-btn oc-node-btn-danger" onclick="event.stopPropagation();SHV._ocDeleteFolder('+fIdx+')" title="삭제"><i class="fa fa-times"></i></button>';
    }
    html+='</div></div>';

    /* 하위 폴더 + 배치된 사업장 */
    var subItems=[];
    (f.children||[]).forEach(function(c){subItems.push({type:'folder',data:c});});
    assignedMembers.forEach(function(m){subItems.push({type:'member',data:m});});

    if(subItems.length){
        html+='<div class="hv-org-line hv-org-line-sm"></div>';
        html+='<div class="hv-org-children">';
        subItems.forEach(function(it){
            if(it.type==='folder') html+=renderFolderNode(it.data, visible);
            else html+=renderMemberNode(it.data, visible);
        });
        html+='</div>';
    }
    html+='</div>';
    return html;
}

function renderMemberNode(m, visible){
    var isP=(m.member_status||m.status||'')==='예정';
    var mContacts=visible.filter(function(c){return parseInt(c._member_idx||c.member_idx||0,10)===parseInt(m.idx,10);});
    var html='<div class="hv-org-child"><div class="hv-org-line-h"></div>';
    html+='<div class="hv-org-node '+(isP?'hv-org-planned':'hv-org-branch')+'">';
    html+=escH(m.name)+'<div class="hv-org-sub">'+(isP?'예정':'사업장');
    if(mContacts.length) html+=' · '+mContacts.length+'명';
    html+='</div></div>';

    if(mContacts.length){
        html+='<div class="hv-org-line hv-org-line-sm"></div>';
        html+='<div class="oc-contact-cards">';
        mContacts.forEach(function(c){
            var sub=[];
            if(c.job_grade) sub.push(c.job_grade);
            if(c.job_title&&c.job_title!=='없음') sub.push(c.job_title);
            html+='<div class="oc-contact-card" draggable="'+(_editMode?'true':'false')+'" data-contact="'+c.idx+'" onclick="SHV._ocShowDetail('+c.idx+')">';
            html+='<div class="oc-cc-name"><i class="fa fa-user text-accent mr-1"></i>'+escH(c.name)+'</div>';
            if(sub.length) html+='<div class="oc-cc-sub">'+escH(sub.join(' / '))+'</div>';
            html+='</div>';
        });
        html+='</div>';
    }
    html+='</div>';
    return html;
}

/* ── 트리 이벤트 (DnD + 줌/패닝) ── */
var _dragContactIdx=null;
var _panning=false, _panSX=0, _panSY=0, _panSL=0, _panST=0;
var _docListenersBound=false;

function setupTreeEvents(wrap){
    /* DnD */
    wrap.addEventListener('dragstart', function(e){
        var card=e.target.closest('.oc-contact-card');
        if(!card||!_editMode){e.preventDefault();return;}
        _dragContactIdx=parseInt(card.dataset.contact,10);
        e.dataTransfer.effectAllowed='move';
        setTimeout(function(){card.classList.add('oc-dragging');},0);
        document.querySelectorAll('.oc-folder-drop').forEach(function(el){el.classList.add('oc-drop-highlight');});
    });
    wrap.addEventListener('dragend', function(e){
        var card=e.target.closest('.oc-contact-card');
        if(card) card.classList.remove('oc-dragging');
        _dragContactIdx=null;
        document.querySelectorAll('.oc-folder-drop').forEach(function(el){el.classList.remove('oc-drop-highlight','oc-drop-over');});
    });
    wrap.addEventListener('dragover', function(e){
        var drop=e.target.closest('.oc-folder-drop');
        if(drop){e.preventDefault();drop.classList.add('oc-drop-over');}
    });
    wrap.addEventListener('dragleave', function(e){
        var drop=e.target.closest('.oc-folder-drop');
        if(drop) drop.classList.remove('oc-drop-over');
    });
    wrap.addEventListener('drop', function(e){
        var drop=e.target.closest('.oc-folder-drop');
        if(!drop||_dragContactIdx===null) return;
        e.preventDefault();
        drop.classList.remove('oc-drop-over');
        var folderIdx=parseInt(drop.dataset.folder,10);
        SHV.api.post('dist_process/saas/Member.php',{todo:'assign_contact_folder',contact_idx:_dragContactIdx,folder_idx:folderIdx})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('이동 완료'); saveViewport(); SHV.orgChart.refresh(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'이동 실패'); }
            });
        _dragContactIdx=null;
    });

    /* 휠 줌 */
    wrap.addEventListener('wheel', function(e){
        if(wrap.querySelector('#ocDiagram')===null) return;
        e.preventDefault();
        var factor=e.deltaY<0?1.12:0.89;
        _scale=Math.max(0.1,Math.min(3,Math.round(_scale*factor*100)/100));
        applyZoom();
    },{passive:false});

    /* 중간버튼 패닝 */
    wrap.addEventListener('mousedown', function(e){
        if(e.button!==1) return;
        e.preventDefault();
        _panning=true; _panSX=e.clientX; _panSY=e.clientY;
        _panSL=wrap.scrollLeft; _panST=wrap.scrollTop;
        wrap.classList.add('oc-grabbing');
    });
    if (!_docListenersBound) {
        _docListenersBound = true;
        document.addEventListener('mousemove', function(e){
            if(!_panning) return;
            var w=$('ocViewTree'); if(!w) return;
            w.scrollLeft=_panSL-(e.clientX-_panSX);
            w.scrollTop=_panST-(e.clientY-_panSY);
        });
        document.addEventListener('mouseup', function(){
            if(!_panning) return;
            _panning=false;
            var w=$('ocViewTree'); if(w) w.classList.remove('oc-grabbing');
        });
    }
}

/* ── 줌 제어 ── */
function applyZoom(){
    var d=$('ocDiagram');
    var lbl=$('ocZoomLabel');
    if(d) d.style.zoom=_scale;
    if(lbl) lbl.textContent=Math.round(_scale*100)+'%';
}

SHV._ocZoom=function(delta){
    _scale=Math.max(0.1,Math.min(3,Math.round((_scale+delta)*100)/100));
    applyZoom();
};

SHV._ocZoomFit=function(){
    var wrap=$('ocViewTree');
    var d=$('ocDiagram');
    if(!wrap||!d) return;
    d.style.zoom=1;
    var dw=d.offsetWidth;
    var cw=wrap.clientWidth-80;
    _scale=dw>cw?Math.max(0.1,Math.round((cw/dw)*100)/100):1;
    applyZoom();
    setTimeout(function(){
        wrap.scrollLeft=Math.max(0,(wrap.scrollWidth-wrap.clientWidth)/2);
        wrap.scrollTop=0;
    },30);
};

function saveViewport(){
    var w=$('ocViewTree');
    sessionStorage.setItem(_vpKey,JSON.stringify({s:_scale,sl:w?w.scrollLeft:0,st:w?w.scrollTop:0}));
}
function restoreViewport(){
    var v=sessionStorage.getItem(_vpKey);
    if(!v) return false;
    sessionStorage.removeItem(_vpKey);
    try{
        var p=JSON.parse(v);
        _scale=p.s; applyZoom();
        var w=$('ocViewTree');
        if(w){w.scrollLeft=p.sl||0;w.scrollTop=p.st||0;}
        return true;
    }catch(e){return false;}
}

/* ══════════════════════════
   폴더 CRUD
   ══════════════════════════ */
SHV._ocAddFolder=function(parentIdx){
    if(!SHV.prompt){ return; }
    SHV.prompt('폴더 추가', '폴더명을 입력하세요', function(name){
        SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_insert',head_idx:_cfg.headIdx,parent_idx:parentIdx||0,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('폴더 추가됨'); saveViewport(); SHV.orgChart.refresh(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'추가 실패'); }
            });
    });
};

SHV._ocRenameFolder=function(idx,oldName){
    if(!SHV.prompt){ return; }
    SHV.prompt('폴더 이름 수정', '폴더명을 입력하세요', function(name){
        SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_update',idx:idx,name:name})
            .then(function(res){
                if(res.ok){ if(SHV.toast) SHV.toast.success('이름 변경됨'); saveViewport(); SHV.orgChart.refresh(); }
                else { if(SHV.toast) SHV.toast.error(res.message||'변경 실패'); }
            });
    }, oldName);
};

SHV._ocDeleteFolder=function(idx){
    if(SHV.confirm){
        SHV.confirm({title:'폴더 삭제',message:'이 폴더를 삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/HeadOffice.php',{todo:'org_folder_delete',idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('폴더 삭제됨'); saveViewport(); SHV.orgChart.refresh(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                    });
            }
        });
    }
};

/* ══════════════════════════
   숨김
   ══════════════════════════ */
function renderHidden(rows){
    var el=$('ocViewHidden'); if(!el) return;
    if(!rows.length){ el.innerHTML='<div class="text-center p-8 text-3"><i class="fa fa-eye text-4xl opacity-30"></i><p class="mt-2">숨김 연락처가 없습니다</p></div>'; return; }
    var html='<div class="oc-warn-bar"><i class="fa fa-eye-slash mr-1"></i>숨김 처리된 연락처는 조직도, 검색 등에서 표시되지 않습니다.</div>';
    html+='<table class="tbl oc-tbl"><thead><tr>';
    if(_cfg.headIdx) html+='<th>사업장</th>';
    html+='<th>이름</th><th>직급</th><th>직책</th><th>전화</th><th>이메일</th><th>상태</th><th>관리</th></tr></thead><tbody>';
    rows.forEach(function(c){
        var st=c.work_status||'재직중';
        html+='<tr>';
        if(_cfg.headIdx) html+='<td>'+escH(c._branch||c.member_name||'')+'</td>';
        html+='<td><b class="text-1">'+escH(c.name||'')+'</b></td>';
        html+='<td>'+escH(c.job_grade||'')+'</td><td>'+escH(c.job_title||'')+'</td>';
        html+='<td class="whitespace-nowrap">'+(c.hp?'<a href="tel:'+escH(c.hp)+'" class="text-accent">'+escH(c.hp)+'</a>':'')+'</td>';
        html+='<td>'+escH(c.email||'')+'</td><td>'+statusPill(st)+'</td>';
        html+='<td><button class="btn btn-glass-primary btn-sm text-xs" onclick="SHV._ocToggleHidden('+c.idx+',0)"><i class="fa fa-eye mr-1"></i>해제</button></td>';
        html+='</tr>';
    });
    html+='</tbody></table>';
    el.innerHTML=html;
}

/* ══════════════════════════
   검색+필터
   ══════════════════════════ */
SHV._ocFilter=function(){
    var q=($('ocSearch')||{value:''}).value.toLowerCase().trim();
    var fS=($('ocFStatus')||{value:''}).value;
    var fG=($('ocFGrade')||{value:''}).value;
    var fT=($('ocFTitle')||{value:''}).value;
    var cnt=0,total=0;
    document.querySelectorAll('.oc-row').forEach(function(tr){
        total++;
        var show=true;
        if(q&&(tr.dataset.search||'').indexOf(q)===-1) show=false;
        if(fS&&tr.dataset.status!==fS) show=false;
        if(fG&&tr.dataset.grade!==fG) show=false;
        if(fT&&tr.dataset.title!==fT) show=false;
        tr.style.display=show?'':'none';
        if(show) cnt++;
    });
    var cntEl=$('ocCount'); if(cntEl) cntEl.textContent=cnt+'/'+total;
};

/* ══════════════════════════
   수정 모드
   ══════════════════════════ */
SHV._ocToggleEdit=function(){
    _editMode=!_editMode;
    var btn=$('ocEditBtn');
    if(btn){
        btn.classList.toggle('btn-primary',_editMode);
        btn.classList.toggle('btn-ghost',!_editMode);
        btn.innerHTML=_editMode?'<i class="fa fa-check mr-1"></i>완료':'<i class="fa fa-pencil mr-1"></i>수정';
    }
    document.querySelectorAll('.oc-del-cell').forEach(function(td){td.classList.toggle('hidden',!_editMode);});
    document.querySelectorAll('.oc-edit-cell').forEach(function(td){
        if(_editMode){ td.classList.add('cursor-pointer'); td.onclick=function(){ocCellClick(td);}; }
        else { td.classList.remove('cursor-pointer'); td.onclick=null; }
    });
    document.querySelectorAll('.oc-contact-card').forEach(function(c){ c.setAttribute('draggable',_editMode?'true':'false'); });
    if(!_editMode){ saveViewport(); SHV.orgChart.refresh(); }
};

/* ── 인라인 셀 수정 ── */
function ocCellClick(td){
    if(_ocEditCell===td) return;
    if(_ocEditCell) ocCellRestore(_ocEditCell);
    _ocEditCell=td;
    var field=td.dataset.field, val=td.dataset.val||'', idx=td.dataset.idx;
    td._orig=td.innerHTML;

    var opts=null;
    if(field==='job_grade') opts=_gradeOpts;
    else if(field==='job_title') opts=_titleOpts;
    else if(field==='work_status') opts=['재직중','이직','퇴사'];

    if(opts){
        var html='<select class="form-select oc-inline-input" onchange="SHV._ocInlineSave(this)" data-idx="'+idx+'" data-field="'+field+'">';
        html+='<option value="">-</option>';
        opts.forEach(function(v){html+='<option value="'+escH(v)+'"'+(v===val?' selected':'')+'>'+escH(v)+'</option>';});
        html+='</select>';
        td.innerHTML=html;
        td.querySelector('select').focus();
    } else {
        td.innerHTML='<input type="text" class="form-input oc-inline-input" value="'+escH(val)+'" data-idx="'+idx+'" data-field="'+field+'" onkeydown="if(event.key===\'Enter\')SHV._ocInlineSave(this);if(event.key===\'Escape\')SHV._ocCellRestore()">';
        td.querySelector('input').focus();
        td.querySelector('input').select();
    }
}

function ocCellRestore(td){
    if(!td) return;
    if(td._orig!==undefined){td.innerHTML=td._orig;delete td._orig;}
    _ocEditCell=null;
}
SHV._ocCellRestore=function(){ocCellRestore(_ocEditCell);};

SHV._ocInlineSave=function(el){
    var idx=el.dataset.idx, field=el.dataset.field, val=el.value, td=el.closest('.oc-edit-cell');
    SHV.api.post('dist_process/saas/Member.php',{todo:'inline_update_contact',contact_idx:idx,field:field,value:val})
        .then(function(res){
            if(res.ok){
                td.dataset.val=val;
                if(field==='work_status') td.innerHTML=statusPill(val);
                else if(field==='hp'&&val) td.innerHTML='<a href="tel:'+escH(val)+'" class="text-accent" onclick="event.stopPropagation()">'+escH(val)+'</a>';
                else td.innerHTML=escH(val);
                td._orig=td.innerHTML; _ocEditCell=null;
                if(SHV.toast) SHV.toast.success('저장됨');
                _contacts.forEach(function(c){if(String(c.idx)===String(idx)) c[field]=val;});
            } else { if(SHV.toast) SHV.toast.error(res.message||'저장 실패'); }
        });
};

/* ── 상세 팝오버 ── */
SHV._ocShowDetail=function(idx){
    var c=null;
    _contacts.forEach(function(item){if(String(item.idx)===String(idx)) c=item;});
    if(!c) return;
    var html='<div class="oc-detail-card">';
    html+='<div class="oc-detail-header"><div class="oc-detail-avatar">'+escH((c.name||'').substring(0,1))+'</div><div class="oc-detail-name">'+escH(c.name)+'</div></div>';
    html+='<div class="oc-detail-rows">';
    [['직급',c.job_grade],['직책',c.job_title],['전화',c.hp],['이메일',c.email],['상태',c.work_status||'재직중'],['주업무',c.main_work]].forEach(function(r){
        html+='<div class="oc-detail-row"><span class="oc-detail-label">'+r[0]+'</span><span class="oc-detail-value">'+escH(r[1]||'-')+'</span></div>';
    });
    html+='</div><div class="oc-detail-actions">';
    html+='<button class="btn btn-ghost btn-sm text-xs" onclick="SHV._ocToggleHidden('+idx+',1)"><i class="fa fa-eye-slash mr-1"></i>숨김</button>';
    html+='<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="SHV._ocDelete('+idx+')"><i class="fa fa-trash mr-1"></i>삭제</button>';
    html+='</div></div>';
    if(SHV.subModal) SHV.subModal.openHtml(html,'연락처 상세','sm');
};

/* ── 숨김/삭제 ── */
SHV._ocToggleHidden=function(idx,hidden){
    if(SHV.confirm){
        SHV.confirm({title:hidden?'연락처 숨김':'숨김 해제',message:hidden?'숨김 처리하시겠습니까?':'숨김을 해제하시겠습니까?',type:hidden?'warn':'primary',confirmText:hidden?'숨김':'해제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/PhoneBook.php',{todo:'toggle_hidden',idx:idx,is_hidden:hidden})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success(res.message||'처리 완료'); if(SHV.subModal&&SHV.subModal.isOpen()) SHV.subModal.close(); SHV.orgChart.refresh(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'실패'); }
                    });
            }
        });
    }
};

SHV._ocDelete=function(idx){
    if(SHV.confirm){
        SHV.confirm({title:'연락처 삭제',message:'삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){
                SHV.api.post('dist_process/saas/PhoneBook.php',{todo:'delete',idx:idx})
                    .then(function(res){
                        if(res.ok){ if(SHV.toast) SHV.toast.success('삭제됨'); if(SHV.subModal&&SHV.subModal.isOpen()) SHV.subModal.close(); SHV.orgChart.refresh(); }
                        else { if(SHV.toast) SHV.toast.error(res.message||'삭제 실패'); }
                    });
            }
        });
    }
};

/* ── 외부 클릭 ── */
document.addEventListener('click',function(e){
    if(_editMode&&_ocEditCell&&!e.target.closest('.oc-edit-cell')&&!e.target.closest('#ocEditBtn')) ocCellRestore(_ocEditCell);
});

})(window.SHV);
