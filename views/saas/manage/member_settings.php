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
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<section data-page="member_settings" data-title="고객관리 설정">

<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1">고객관리 설정</h2>
        <p class="text-xs text-3 mt-1 m-0">등록 필수항목, 권역 관리</p>
    </div>
</div>

<!-- ── 등록 필수항목 ── -->
<div class="card p-4 mb-3">
    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-bold text-1"><i class="fa fa-list-alt text-accent mr-2"></i>고객 등록 필수항목</div>
        <button class="btn btn-glass-primary btn-sm" onclick="msSaveRequired()"><i class="fa fa-save mr-1"></i>저장</button>
    </div>
    <p class="text-xs text-3 mb-3">ON 상태인 항목은 사업장 등록 시 필수 입력됩니다.</p>
    <div id="msReqList" class="flex flex-col gap-2">
        <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
    </div>
</div>

<!-- ── 권역 관리 ── -->
<div class="card p-4 mb-3">
    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-bold text-1"><i class="fa fa-globe text-accent mr-2"></i>권역 관리</div>
        <div class="flex gap-2">
            <button class="btn btn-outline btn-sm" onclick="msRegionAdd()"><i class="fa fa-plus mr-1"></i>추가</button>
            <button class="btn btn-glass-primary btn-sm" onclick="msRegionSave()"><i class="fa fa-save mr-1"></i>저장</button>
        </div>
    </div>
    <div id="msRegionList" class="flex flex-col gap-1">
        <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
    </div>
</div>

<!-- ── PJT 속성 관리 ── -->
<div class="card p-4 mb-3">
    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-bold text-1"><i class="fa fa-tags text-accent mr-2"></i>PJT 속성 관리</div>
        <div class="flex gap-2">
            <button class="btn btn-outline btn-sm" onclick="msPjtAdd()"><i class="fa fa-plus mr-1"></i>추가</button>
            <button class="btn btn-glass-primary btn-sm" onclick="msPjtSave()"><i class="fa fa-save mr-1"></i>저장</button>
        </div>
    </div>
    <p class="text-xs text-3 mb-3">품목관리에서 사용하는 PJT 속성 목록입니다.</p>
    <div id="msPjtList" class="flex flex-col gap-1">
        <div class="text-center p-4 text-3 text-xs">로딩 중...</div>
    </div>
</div>

<script>
(function(){
'use strict';

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

var _required = {};
var _regions = [];

/* ══════════════════════════════════════
   필수항목
   ══════════════════════════════════════ */
var reqLabels = {
    'name':'고객명','card_number':'사업자번호','ceo_name':'대표자명',
    'business_type':'업태','business_class':'업종','tel':'전화번호',
    'hp':'휴대폰','email':'이메일','address':'주소','employee_idx':'담당자'
};

function loadRequired(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'get_required'})
        .then(function(res){
            if(!res.ok) return;
            _required = res.data.required||res.data||{};
            renderRequired();
        });
}

function renderRequired(){
    var el=$('msReqList'); if(!el) return;
    var html='';
    for(var key in reqLabels){
        var on = _required[key]===1;
        html+='<div class="flex items-center justify-between py-2 border-b border-border">'
            +'<span class="text-sm text-1">'+escH(reqLabels[key])+'</span>'
            +'<label class="flex items-center gap-2 cursor-pointer">'
            +'<input type="checkbox" class="ms-req-chk" data-key="'+key+'"'+(on?' checked':'')+'>'
            +'<span class="text-xs font-bold '+(on?'text-accent':'text-3')+'" data-status>'+( on?'ON':'OFF')+'</span>'
            +'</label></div>';
    }
    el.innerHTML=html;
    /* 스위치 이벤트 */
    el.querySelectorAll('.ms-req-chk').forEach(function(chk){
        chk.addEventListener('change',function(){
            var s=this.closest('label').querySelector('[data-status]');
            if(this.checked){ s.textContent='ON'; s.className='text-xs font-bold text-accent'; }
            else { s.textContent='OFF'; s.className='text-xs font-bold text-3'; }
        });
    });
}

window.msSaveRequired=function(){
    var data={};
    document.querySelectorAll('.ms-req-chk').forEach(function(chk){
        data[chk.dataset.key]=chk.checked?1:0;
    });
    SHV.api.post('dist_process/saas/Member.php',{todo:'save_member_required',data:JSON.stringify(data)})
        .then(function(res){
            if(res.ok){ if(SHV.toast) SHV.toast.success('필수항목이 저장되었습니다.'); }
            else { if(SHV.toast) SHV.toast.error(res.message||'저장 실패'); }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

/* ══════════════════════════════════════
   권역 관리
   ══════════════════════════════════════ */
function loadRegions(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'region_list'})
        .then(function(res){
            if(!res.ok) return;
            _regions = res.data.data||[];
            renderRegions();
        });
}

function renderRegions(){
    var el=$('msRegionList'); if(!el) return;
    if(!_regions.length){
        el.innerHTML='<div class="text-center p-4 text-3 text-xs">권역을 추가하세요</div>';
        return;
    }
    var html='';
    _regions.forEach(function(r,i){
        var on = r.on===1;
        html+='<div class="flex items-center gap-3 py-2 border-b border-border" data-region-idx="'+(r.idx||r.key)+'">'
            +'<span class="text-xs text-3 font-bold" style="width:24px;">'+(i+1)+'</span>'
            +'<input type="text" class="form-input flex-1 ms-region-name" value="'+escH(r.name)+'" maxlength="30">'
            +'<label class="flex items-center gap-2 cursor-pointer">'
            +'<input type="checkbox" class="ms-region-chk"'+(on?' checked':'')+'>'
            +'<span class="text-xs font-bold '+(on?'text-accent':'text-3')+'" data-status>'+(on?'ON':'OFF')+'</span>'
            +'</label>'
            +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="msRegionDelete(this)"><i class="fa fa-trash"></i></button>'
            +'</div>';
    });
    el.innerHTML=html;
    el.querySelectorAll('.ms-region-chk').forEach(function(chk){
        chk.addEventListener('change',function(){
            var s=this.closest('label').querySelector('[data-status]');
            if(this.checked){ s.textContent='ON'; s.className='text-xs font-bold text-accent'; }
            else { s.textContent='OFF'; s.className='text-xs font-bold text-3'; }
        });
    });
}

window.msRegionAdd=function(){
    var el=$('msRegionList');
    var html='<div class="flex items-center gap-3 py-2 border-b border-border" data-region-idx="new">'
        +'<span class="text-xs text-3 font-bold" style="width:24px;">new</span>'
        +'<input type="text" class="form-input flex-1 ms-region-name" value="" placeholder="권역명 입력" maxlength="30">'
        +'<label class="flex items-center gap-2 cursor-pointer">'
        +'<input type="checkbox" class="ms-region-chk" checked>'
        +'<span class="text-xs font-bold text-accent" data-status>ON</span>'
        +'</label>'
        +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="msRegionDelete(this)"><i class="fa fa-trash"></i></button>'
        +'</div>';
    el.insertAdjacentHTML('beforeend',html);
    var newChk=el.querySelector('[data-region-idx="new"]:last-child .ms-region-chk');
    if(newChk) newChk.addEventListener('change',function(){
        var s=this.closest('label').querySelector('[data-status]');
        if(this.checked){ s.textContent='ON'; s.className='text-xs font-bold text-accent'; }
        else { s.textContent='OFF'; s.className='text-xs font-bold text-3'; }
    });
    var nameInput=el.querySelector('[data-region-idx="new"]:last-child .ms-region-name');
    if(nameInput) nameInput.focus();
};

window.msRegionDelete=function(btn){
    if(SHV.confirm){
        SHV.confirm({title:'권역 삭제',message:'삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){ btn.closest('[data-region-idx]').remove(); }
        });
    } else { btn.closest('[data-region-idx]').remove(); }
};

window.msRegionSave=function(){
    var rows=document.querySelectorAll('#msRegionList [data-region-idx]');
    var regions={};
    var nextKey=1;
    rows.forEach(function(row){
        var k=parseInt(row.dataset.regionIdx);
        if(!isNaN(k)&&k>=nextKey) nextKey=k+1;
    });
    rows.forEach(function(row){
        var key=row.dataset.regionIdx;
        var name=(row.querySelector('.ms-region-name').value||'').trim();
        if(!name) return;
        var on=row.querySelector('.ms-region-chk').checked?1:0;
        var numKey;
        if(key==='new'||isNaN(parseInt(key))){ numKey=nextKey++; }
        else { numKey=parseInt(key); }
        regions[numKey]={name:name,on:on};
    });
    SHV.api.post('dist_process/saas/Member.php',{todo:'save_member_regions',regions:JSON.stringify(regions)})
        .then(function(res){
            if(res.ok){ if(SHV.toast) SHV.toast.success('권역이 저장되었습니다.'); loadRegions(); }
            else { if(SHV.toast) SHV.toast.error(res.message||'저장 실패'); }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

/* ══════════════════════════════════════
   PJT 속성 관리
   ══════════════════════════════════════ */
var _pjtProps = [];
var _pjtColors = [];

function loadPjtProps(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'pjt_property_list'})
        .then(function(res){
            if(!res.ok) return;
            _pjtProps = res.data.properties||res.data.data||[];
            _pjtColors = res.data.colors||{};
            renderPjtProps();
        }).catch(function(){ renderPjtProps(); });
}

function renderPjtProps(){
    var el=$('msPjtList'); if(!el) return;
    if(!_pjtProps.length&&!Object.keys(_pjtProps).length){
        el.innerHTML='<div class="text-center p-4 text-3 text-xs">속성을 추가하세요</div>';
        return;
    }
    var html='';
    var keys = Array.isArray(_pjtProps) ? _pjtProps : Object.keys(_pjtProps).map(function(k){return{key:k,name:_pjtProps[k]};});

    if(!Array.isArray(keys)||!keys.length){
        /* 객체 형태 {0:'없음',1:'표준A',...} */
        for(var k in _pjtProps){
            keys.push({key:k, name:_pjtProps[k]});
        }
    }

    keys.forEach(function(item,i){
        var key = item.key||item.idx||i;
        var name = item.name||item||'';
        var color = _pjtColors[key]||'#94a3b8';
        html+='<div class="flex items-center gap-3 py-2 border-b border-border" data-pjt-key="'+escH(String(key))+'">'
            +'<span class="text-xs text-3 font-bold" style="width:24px;">'+escH(String(key))+'</span>'
            +'<input type="text" class="form-input flex-1 ms-pjt-name" value="'+escH(name)+'" maxlength="30" '+(String(key)==='0'?'disabled':'')+'>'
            +'<input type="color" class="ms-pjt-color" value="'+escH(color)+'" style="width:36px;height:28px;border:1px solid var(--border);border-radius:4px;cursor:pointer;padding:0;" '+(String(key)==='0'?'disabled':'')+'>'
            +'<span class="ms-pjt-preview" style="display:inline-block;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;color:#fff;background:'+escH(color)+';">'+escH(name)+'</span>'
            +(String(key)!=='0'?'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="msPjtDelete(this)"><i class="fa fa-trash"></i></button>':'')
            +'</div>';
    });
    el.innerHTML=html;
    /* 실시간 미리보기 */
    el.querySelectorAll('[data-pjt-key]').forEach(function(row){
        var nameInput=row.querySelector('.ms-pjt-name');
        var colorInput=row.querySelector('.ms-pjt-color');
        var preview=row.querySelector('.ms-pjt-preview');
        if(nameInput&&preview) nameInput.addEventListener('input',function(){ preview.textContent=this.value||'없음'; });
        if(colorInput&&preview) colorInput.addEventListener('input',function(){ preview.style.background=this.value; });
    });
}

window.msPjtAdd=function(){
    var el=$('msPjtList');
    var html='<div class="flex items-center gap-3 py-2 border-b border-border" data-pjt-key="new">'
        +'<span class="text-xs text-3 font-bold" style="width:24px;">new</span>'
        +'<input type="text" class="form-input flex-1 ms-pjt-name" value="" placeholder="속성명 입력" maxlength="30">'
        +'<input type="color" class="ms-pjt-color" value="#3b82f6" style="width:36px;height:28px;border:1px solid var(--border);border-radius:4px;cursor:pointer;padding:0;">'
        +'<span class="ms-pjt-preview" style="display:inline-block;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;color:#fff;background:#3b82f6;">새 속성</span>'
        +'<button class="btn btn-ghost btn-sm text-xs text-danger" onclick="msPjtDelete(this)"><i class="fa fa-trash"></i></button>'
        +'</div>';
    el.insertAdjacentHTML('beforeend',html);
    var newRow=el.querySelector('[data-pjt-key="new"]:last-child');
    if(newRow){
        var ni=newRow.querySelector('.ms-pjt-name');
        var ci=newRow.querySelector('.ms-pjt-color');
        var pv=newRow.querySelector('.ms-pjt-preview');
        if(ni&&pv) ni.addEventListener('input',function(){ pv.textContent=this.value||'없음'; });
        if(ci&&pv) ci.addEventListener('input',function(){ pv.style.background=this.value; });
        if(ni) ni.focus();
    }
};

window.msPjtDelete=function(btn){
    if(SHV.confirm){
        SHV.confirm({title:'속성 삭제',message:'삭제하시겠습니까?',type:'danger',confirmText:'삭제',
            onConfirm:function(){ btn.closest('[data-pjt-key]').remove(); }
        });
    } else { btn.closest('[data-pjt-key]').remove(); }
};

window.msPjtSave=function(){
    var rows=document.querySelectorAll('#msPjtList [data-pjt-key]');
    var prop={};
    var colors={};
    var nextKey=10;
    rows.forEach(function(row){
        var k=parseInt(row.dataset.pjtKey);
        if(!isNaN(k)&&k>=nextKey) nextKey=k+1;
    });
    rows.forEach(function(row){
        var key=row.dataset.pjtKey;
        var name=(row.querySelector('.ms-pjt-name').value||'').trim();
        var color=row.querySelector('.ms-pjt-color').value||'#94a3b8';
        var numKey;
        if(key==='new'||isNaN(parseInt(key))){ numKey=nextKey++; }
        else { numKey=parseInt(key); }
        prop[numKey]=name;
        colors[numKey]=color;
    });
    SHV.api.post('dist_process/saas/Member.php',{todo:'save_item_property',property:JSON.stringify(prop),colors:JSON.stringify(colors)})
        .then(function(res){
            if(res.ok){ if(SHV.toast) SHV.toast.success('PJT 속성이 저장되었습니다.'); loadPjtProps(); }
            else { if(SHV.toast) SHV.toast.error(res.message||'저장 실패'); }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
};

/* ── 초기 로드 ── */
loadRequired();
loadRegions();
loadPjtProps();
})();
</script>
</section>
