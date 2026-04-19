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

$isModify    = ($_GET['todo'] ?? '') === 'modify';
$modifyIdx   = (int)($_GET['idx'] ?? 0);
$siteIdx     = (int)($_GET['site_idx'] ?? 0);
$memberIdx   = (int)($_GET['member_idx'] ?? 0);
$isPjtMode   = (($_GET['pjt_mode'] ?? '') === '1');
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="eaFormBody" class="ea-form">
    <div class="ea-form-header">
        <div class="ea-form-header-left">
            <i class="fa fa-list-alt"></i>
            <span>견적항목</span>
            <span class="ea-form-header-badge" id="eaCartBadge">0</span>
        </div>
        <div class="ea-form-header-right">
            <span class="ea-form-header-site" id="eaSiteName"></span>
            <span class="ea-form-header-pjt" id="eaPjtTags">
                <span class="ea-skeleton ea-skeleton-tag" data-pending="pjt">대기중</span>
            </span>
        </div>
    </div>
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label" for="ea_title">견적명 <span class="required">*</span></label>
            <input type="text" id="ea_title" class="form-input" placeholder="견적명" maxlength="200" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="ea_no">견적번호</label>
            <input type="text" id="ea_no" class="form-input" placeholder="자동생성" maxlength="60" autocomplete="off"<?= $isModify ? ' readonly' : '' ?>>
        </div>
        <div class="form-group">
            <label class="form-label" for="ea_date">견적일</label>
            <input type="date" id="ea_date" class="form-input">
        </div>
    </div>
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="ea_status">상태</label>
            <select id="ea_status" class="form-select">
                <option value="DRAFT">작성중</option><option value="SUBMITTED">제출</option>
                <option value="APPROVED">승인</option><option value="REJECTED">반려</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="ea_memo">비고</label>
            <input type="text" id="ea_memo" class="form-input" placeholder="비고" maxlength="500" autocomplete="off">
        </div>
    </div>
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label">작성자</label>
            <select id="ea_employee" class="form-select"><option value="">선택</option></select>
        </div>
        <div class="form-group">
            <label class="form-label">수주금액</label>
            <input type="text" id="ea_order_amount" class="form-input text-right" placeholder="0" autocomplete="off" oninput="this._manualEdit=true;eaFmtMoney(this);eaCalcIncrease()">
        </div>
        <div class="form-group">
            <label class="form-label">매입 / 순증</label>
            <div class="flex gap-2">
                <input type="text" id="ea_cost_total" class="form-input text-right flex-1" readonly placeholder="매입">
                <input type="text" id="ea_increase" class="form-input text-right flex-1" readonly placeholder="순증">
            </div>
        </div>
    </div>

    <div class="form-section">
        <!-- 8번: 구버전 매핑 요약 (수정 모드에서 데이터 도착 후 표시) -->
        <div class="ea-legacy-summary hidden" id="eaLegacySummary">
            <i class="fa fa-refresh"></i>
            <span>구버전 품목 매핑</span>
            <span class="ea-legacy-count is-completed" id="eaLegacyCompleted">완료 0</span>
            <span class="ea-legacy-count is-pending"   id="eaLegacyPending">대기 0</span>
            <span class="ea-legacy-count is-failed"    id="eaLegacyFailed">실패 0</span>
        </div>
        <div class="ea-section-head">
            <span class="form-section-label m-0">품목</span>
            <div class="flex gap-2">
                <button type="button" class="btn btn-glass-primary btn-sm" onclick="eaOpenPick()"><i class="fa fa-search mr-1"></i>품목 검색</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="eaAddManual()"><i class="fa fa-plus mr-1"></i>수동 입력</button>
            </div>
        </div>
        <div class="ea-cart-wrap">
            <table class="tbl" id="eaCartTbl">
                <thead><tr>
                    <th class="th-center ea-th-no">No</th><th>품목명</th><th>규격</th><th>PJT</th><th>단위</th>
                    <th class="text-right ea-th-price">단가</th><th class="text-right ea-th-price">매입</th>
                    <th class="text-right ea-th-qty">수량</th><th class="text-right ea-th-total">합계</th><th class="ea-th-del"></th>
                </tr></thead>
                <tbody id="eaCartBody"><tr><td colspan="10" class="text-center p-6 text-3">품목을 추가하세요</td></tr></tbody>
            </table>
        </div>
        <div class="ea-total-bar">
            <span class="ea-total-label">합계(권고액)</span>
            <span class="ea-total-value" id="eaTotalAmt">0원</span>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-label">파일 첨부</div>
        <div class="flex gap-2 mb-2"><button type="button" class="btn btn-outline btn-sm" onclick="eaUploadFile()"><i class="fa fa-upload mr-1"></i>파일 추가</button></div>
        <div id="eaExistFiles" class="text-xs mb-1"></div>
        <div id="eaNewFiles" class="text-xs"></div>
    </div>
</div>

<div class="modal-form-footer">
    <span id="eaErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep hidden"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="eaSubmitBtn" onclick="eaSubmit()">
        <i class="fa fa-check"></i> <?= $isModify ? '수정' : '등록' ?>
    </button>
</div>

<script>
(function(){
'use strict';
var _isModify=<?=$isModify?'true':'false'?>,_modifyIdx=<?=$modifyIdx?>,_siteIdx=<?=$siteIdx?>,_memberIdx=<?=$memberIdx?>,_isPjtMode=<?=$isPjtMode?'true':'false'?>;
var _cart=[],_cartId=0,_pjtAttrs=[],_pjtAttrColors={},_pendingFiles=[];
var _siteName='',_pjtTabs=[],_legacyMappings={},_catBadges={};

/* ══════════════════════════════════════
   백엔드 어댑터 (Codex API 응답 대기용)
   교체 포인트: TODO명 / 파라미터명 / 응답키
   ══════════════════════════════════════ */
var EST_API={
    EST_DETAIL:           {endpoint:'dist_process/saas/Site.php',     todo:'est_detail'},
    EST_FILE_LIST:        {endpoint:'dist_process/saas/Site.php',     todo:'est_file_list'},
    EST_TAB_LIST:         {endpoint:'dist_process/saas/Site.php',     todo:'est_tab_list'},               /* codex 확정 */
    EST_LEGACY_MAP:       {endpoint:'dist_process/saas/Site.php',     todo:'est_legacy_mapping_status'},  /* codex 확정 */
    EST_CATEGORY_BADGE:   {endpoint:'dist_process/saas/Site.php',     todo:'est_category_badges'},        /* codex 확정 */
    ITEM_PROP_MASTER:     {endpoint:'dist_process/saas/Material.php', todo:'item_property_master'},       /* codex 확정 (10번) */
    SITE_DETAIL:          {endpoint:'dist_process/saas/Site.php',     todo:'detail'}                      /* site_view.php 가 사용하는 todo */
};
function adapterLog(tag,api,code,msg){try{console.warn('[EST_ADAPTER]',tag,api&&api.todo,code,msg||'');}catch(_){}}
/* 미구현/미지원 todo는 폴백으로 빈 데이터 — UI 가 깨지지 않도록 */
function safeFetch(api,params,fallback){
    return SHV.api.get(api.endpoint,Object.assign({todo:api.todo},params||{})).then(function(r){
        if(!r||!r.ok){adapterLog('UNAVAILABLE',api,r&&r.code,r&&r.message);return Object.assign({_pending:true},fallback||{});}
        return r.data||r;
    }).catch(function(e){adapterLog('NETWORK_ERR',api,0,e&&e.message);return Object.assign({_pending:true},fallback||{});});
}
/* 응답 키 alias 정규화 */
function normalizeEstDetail(d){if(!d)return{};var e=d.estimate||d;return{
    title:    e.estimate_title||e.title||e.name||'',
    no:       e.estimate_no||e.no||'',
    date:     (e.estimate_date||e.est_date||e.created_at||'').substring(0,10),
    status:   e.estimate_status||e.status||'DRAFT',
    memo:     e.memo||'',
    employee: e.employee_idx||'',
    order:    parseInt(e.order_amount||0,10),
    items:    d.items||e.items||[],
    site_idx: e.site_idx||0,
    member_idx:e.member_idx||0
};}
/* submit payload 가드 — 미정의/null 자동 drop */
function dropEmpty(obj){var o={};for(var k in obj){if(obj[k]!==undefined&&obj[k]!==null&&obj[k]!=='')o[k]=obj[k];}return o;}

function $(id){return document.getElementById(id);}
function escH(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
function safeColor(v){var s=String(v||'').trim();return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(s)?s:'#6b7280';}
function safeUrl(v){
    var s=String(v||'').trim();
    if(!s)return'';
    if(/^(javascript|data|vbscript):/i.test(s))return'';
    if(/^https?:\/\//i.test(s)||/^\//.test(s)||/^\.\.?\//.test(s)||/^[a-zA-Z0-9_\-./]+(?:\?[^<>\s]*)?(?:#[^<>\s]*)?$/.test(s))return s;
    return'';
}
function fmtNum(v){var n=parseInt(v,10);return isNaN(n)||n===0?'':n.toLocaleString();}
function showErr(m){var e=$('eaErrMsg');if(!e)return;if(m){e.textContent=m;e.classList.remove('hidden');}else{e.textContent='';e.classList.add('hidden');}}
function setLoading(on){var b=$('eaSubmitBtn');if(!b)return;b.disabled=on;b.innerHTML=on?'<span class="spinner spinner-sm mr-1"></span>저장 중...':'<i class="fa fa-check"></i> '+(_isModify?'수정':'등록');}
window.eaFmtMoney=function(el){var r=el.value.replace(/[^\d]/g,'');var n=parseInt(r,10);el.value=isNaN(n)?'':n.toLocaleString();};

/* ── PJT 속성+색상 ── */
function loadPjtAttrs(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'hogi_list',member_idx:_memberIdx||0}).then(function(res){
        if(!res.ok)return;_pjtAttrs=res.data.pjt_attrs||[];
        var colors=res.data.pjt_attr_colors||{};
        _pjtAttrs.forEach(function(a){var name=typeof a==='string'?a:(a.name||a.attr_name||'');_pjtAttrColors[name]=colors[name]||a.color||'#6b7280';});
    }).catch(function(){});
}
/* 6번: 글로벌 속성 마스터 우선, 없으면 기존 _pjtAttrColors fallback */
/* manual.php 6종 hardcode fallback — API 실패 시에도 표시 보장 */
var _propMasterFallback={
    2:{name:'HDEL(표준)',color:'#FF0000'},
    3:{name:'HDEL(비표준_S)',color:'#FFA500'},
    4:{name:'HDEL(비표준_기술)',color:'#FFFF00'},
    5:{name:'HDEL(MOD)',color:'#00FF00'},
    6:{name:'HDEL(JQPR)',color:'#0000FF'}
};
var _propMaster={};
function loadItemPropertyMaster(){
    safeFetch(EST_API.ITEM_PROP_MASTER,{},{properties:[]}).then(function(d){
        if(d._pending)return;
        var props=d.properties||d.data||[];
        _propMaster={};
        (Array.isArray(props)?props:[]).forEach(function(p){
            var k=parseInt(p.key,10);if(isNaN(k))return;
            _propMaster[k]={name:p.name||'',color:p.color||'#6b7280'};
        });
        try{window.__EST_PROP_MASTER=_propMaster;}catch(_){}
        renderCart();
    });
}
/* est_pick.php 가 fallback 도 사용하도록 노출 */
try{window.__EST_PROP_FALLBACK=_propMasterFallback;}catch(_){}
function getPropInfo(a){
    if(a==null||a===''||a==='0'||a==='없음')return null;
    var k=parseInt(a,10);
    if(!isNaN(k)){
        if(_propMaster[k])return _propMaster[k];
        if(_propMasterFallback[k])return _propMasterFallback[k]; /* fallback */
    }
    for(var key in _propMaster){if(_propMaster[key].name===a)return _propMaster[key];}
    for(var fk in _propMasterFallback){if(_propMasterFallback[fk].name===a)return _propMasterFallback[fk];}
    return null;
}
function attrBadge(a){
    var p=getPropInfo(a);
    if(p){var c=safeColor(p.color||'#6b7280');return'<span class="ea-attr-badge" style="--ac:'+c+'">'+escH(p.name||a)+'</span>';}
    if(!a||a==='0'||a==='없음')return'';
    var c2=safeColor(_pjtAttrColors[a]||'#6b7280');
    return'<span class="ea-attr-badge" style="--ac:'+c2+'">'+escH(a)+'</span>';
}
function attrSelect(cid,cur){var h='<select class="form-select form-select-sm ea-attr-sel" onchange="eaCartAttr('+cid+',this.value)"><option value="">-</option>';_pjtAttrs.forEach(function(a){var n=typeof a==='string'?a:(a.name||a.attr_name||'');h+='<option value="'+escH(n)+'"'+(n===cur?' selected':'')+'>'+escH(n)+'</option>';});return h+'</select>';}

/* ── 품목 검색 트리 팝업 열기 ── */
window.eaOpenPick = function () {
    if (!SHV || !SHV.subModal) { if (SHV && SHV.toast) SHV.toast.warn('서브 모달이 지원되지 않습니다.'); return; }
    var url = 'views/saas/fms/est_pick.php?member_idx=' + (_memberIdx || 0) + '&site_idx=' + (_siteIdx || 0);
    SHV.subModal.openHtml(
        '<div class="text-center p-8 text-3"><span class="spinner spinner-md mr-2"></span>로딩 중...</div>',
        '품목 검색', 'lg'
    );
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
            if (r.status === 401) { window.location.href = 'login.php'; return ''; }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function (html) { if (html) SHV.subModal.openHtml(html, '품목 검색', 'lg'); })
        .catch(function () {
            SHV.subModal.openHtml(
                '<div class="text-center p-8 text-danger">불러오기 실패</div>',
                '품목 검색', 'lg'
            );
        });
};

/* ── 트리 팝업에서 호출 가능한 자식 자동 로딩 헬퍼 (window 노출) ── */
window.eaLoadChildren = function (productIdx) { loadChildren(productIdx); };

/* ── 자식 품목 (loadChildren) — codex 확인: parent_idx 컬럼 없으므로 component_list 사용 ── */
function loadChildren(pi){
    SHV.api.get('dist_process/saas/Material.php',{todo:'component_list',item_idx:pi}).then(function(r){
        if(!r.ok)return;
        var ch=r.data.data||r.data||[];
        var par=_cart.find(function(c){return c.product_idx===pi&&!c.is_child;});
        if(!par)return;
        /* 같은 부모의 기존 자식 제거 — 중복 누적 방지 */
        _cart=_cart.filter(function(c){return!(c.is_child&&c.parent_id===par.id);});
        ch.forEach(function(c){
            _cartId++;
            _cart.push({id:_cartId,product_idx:c.idx||0,name:c.name||c.item_name||'',standard:c.standard||'',unit:c.unit||'',price:parseInt(c.price||c.sale_price||0,10),cost:parseInt(c.cost||c.purchase_price||0,10),qty:par.qty,attribute:c.attribute||par.attribute||'',is_split:0,manual:false,is_child:true,parent_id:par.id,follow_mode:parseInt(c.follow_mode||1,10)});
        });
        renderCart();
    }).catch(function(){});
}

/* ── check_qty_limit ── */
function checkQtyLimit(item,cb){var attr=item.attribute||'';if(!attr||attr==='0'||attr==='없음'||!_siteIdx){cb({allowed:true});return;}
var ci=_cart.filter(function(c){return!c.is_child;}).map(function(c){return{item_idx:c.product_idx,attribute:c.attribute,qty:c.qty};});
SHV.api.get('dist_process/saas/Project.php',{todo:'check_qty_limit',site_idx:_siteIdx,item_idx:item.product_idx,attribute:attr,add_qty:item.qty,cart_items:JSON.stringify(ci),exclude_estimate_idx:_modifyIdx||0}).then(function(r){cb(r.ok?r.data:{allowed:true});}).catch(function(){cb({allowed:true});});}

/* ── 카트 관리 ── window 노출: est_pick.php 가 호출 */
window.eaAddToCart=function(item){function doAdd(chk){
/* 9번: check_qty_limit mode 배지 표시 (codex 응답 mode = 총수량/품목갯수/달성률) */
if(chk&&!chk.allowed){var modeStr=chk.mode?'['+chk.mode+' 모드] ':'';if(SHV.toast)SHV.toast.warn(modeStr+(chk.msg||'수량 제한 초과'));return;}
if(chk&&chk.mode&&chk.warn){var ws=chk.mode?'['+chk.mode+'] ':'';if(SHV.toast)SHV.toast.info(ws+chk.warn);}
var sp=item.is_split||(chk&&chk.force_split?1:0);if(item.product_idx&&!sp&&!item.is_child){var ex=_cart.find(function(c){return c.product_idx===item.product_idx&&!c.manual&&!c.is_child&&c.attribute===(item.attribute||'');});if(ex){ex.qty+=item.qty||1;renderCart();return;}}
_cartId++;_cart.push({id:_cartId,product_idx:item.product_idx||0,name:item.name||'',standard:item.standard||'',unit:item.unit||'',price:parseInt(item.price,10)||0,cost:parseInt(item.cost||0,10),qty:parseInt(item.qty,10)||1,attribute:item.attribute||'',cat_name:item.cat_name||'',memo:item.memo||'',is_split:sp,manual:!!item.manual,is_child:false,parent_id:0,follow_mode:0});renderCart();}
if(item.attribute&&item.attribute!=='0'&&item.attribute!=='없음')checkQtyLimit(item,doAdd);else doAdd({allowed:true});};
window.eaAddManual=function(){window.eaAddToCart({name:'',standard:'',unit:'EA',price:0,cost:0,qty:1,attribute:'',manual:true});};
window.eaCartQty=function(id,val){var it=_cart.find(function(c){return c.id===id;});if(!it)return;var old=it.qty;it.qty=Math.max(1,parseInt(val,10)||1);if(!it.is_child){var ratio=it.qty/Math.max(1,old);_cart.forEach(function(c){if(c.is_child&&c.parent_id===it.id&&c.follow_mode===1)c.qty=Math.max(1,Math.round(c.qty*ratio));});}renderCart();};
window.eaCartPrice=function(id,v){var c=_cart.find(function(x){return x.id===id;});if(c){c.price=parseInt(String(v).replace(/,/g,''),10)||0;renderCart();}};
window.eaCartCost=function(id,v){var c=_cart.find(function(x){return x.id===id;});if(c){c.cost=parseInt(String(v).replace(/,/g,''),10)||0;renderCart();}};
window.eaCartName=function(id,v){var c=_cart.find(function(x){return x.id===id;});if(c)c.name=v;};
window.eaCartStd=function(id,v){var c=_cart.find(function(x){return x.id===id;});if(c)c.standard=v;};
window.eaCartAttr=function(id,v){var c=_cart.find(function(x){return x.id===id;});if(!c)return;c.attribute=v;
/* 부모 attribute 변경 시 follow_mode=1 자식도 동기화 */
if(!c.is_child){_cart.forEach(function(ch){if(ch.is_child&&ch.parent_id===c.id&&ch.follow_mode===1)ch.attribute=v;});}
renderCart();};
window.eaCartRemove=function(id){_cart=_cart.filter(function(c){return c.id!==id&&c.parent_id!==id;});renderCart();};

/* ── 카트 렌더링 ── */
function renderCart(){var body=$('eaCartBody');if(!body)return;
/* 1번: 헤더 카운트 배지 업데이트 (자식 제외) */
var bd=$('eaCartBadge');if(bd){bd.textContent=_cart.filter(function(c){return!c.is_child;}).length;}
if(!_cart.length){body.innerHTML='<tr><td colspan="10" class="text-center p-6 text-3">품목을 추가하세요</td></tr>';updateTotal();return;}
var cpm={},ccm={},cnn={};_cart.forEach(function(c){if(!c.is_child)return;if(!cpm[c.parent_id])cpm[c.parent_id]=0;if(!ccm[c.parent_id])ccm[c.parent_id]=0;if(!cnn[c.parent_id])cnn[c.parent_id]=[];cpm[c.parent_id]+=c.qty*c.price;ccm[c.parent_id]+=c.qty*c.cost;cnn[c.parent_id].push(c.name);});
var h='',rn=0;_cart.forEach(function(c){if(c.is_child)return;rn++;var lp=(c.qty*c.price)+(cpm[c.id]||0);var lc=(c.qty*c.cost)+(ccm[c.id]||0);var sp=c.is_split?'<span class="ea-split-badge">분할</span>':'';var ci=cnn[c.id]?'<div class="text-xs text-3 mt-1"><i class="fa fa-puzzle-piece mr-1"></i>구성: '+cnn[c.id].map(escH).join(', ')+'</div>':'';
h+='<tr data-cart-id="'+c.id+'"><td class="td-no">'+rn+sp+'</td>'
+'<td>'+(c.manual?'<input type="text" class="form-input form-input-sm" value="'+escH(c.name)+'" onchange="eaCartName('+c.id+',this.value)" placeholder="품목명">':'<b class="text-1">'+escH(c.name)+'</b>'+legacyBadge(c.origin_idx)+(c.cat_name?'<span class="ea-cat-tag">'+escH(c.cat_name)+'</span>':'')+ci+(c.memo?'<div class="text-xs ea-memo-line"><i class="fa fa-info-circle mr-1"></i>'+escH(c.memo)+'</div>':''))+'</td>'
+'<td>'+(c.manual?'<input type="text" class="form-input form-input-sm" value="'+escH(c.standard)+'" onchange="eaCartStd('+c.id+',this.value)" placeholder="규격">':'<span class="text-3">'+escH(c.standard)+'</span>')+'</td>'
+'<td>'+(_pjtAttrs.length?attrSelect(c.id,c.attribute):attrBadge(c.attribute))+'</td>'
+'<td class="text-3">'+escH(c.unit)+'</td>'
+'<td class="text-right">'+(c.manual?'<input type="text" class="form-input form-input-sm text-right ea-input-price" value="'+fmtNum(c.price)+'" oninput="eaCartPrice('+c.id+',this.value)">':fmtNum(c.price))+'</td>'
+'<td class="text-right">'+(c.manual?'<input type="text" class="form-input form-input-sm text-right ea-input-price" value="'+fmtNum(c.cost)+'" oninput="eaCartCost('+c.id+',this.value)">':(fmtNum(lc)||'-'))+'</td>'
+'<td class="text-right"><input type="number" class="form-input form-input-sm text-right ea-input-qty" value="'+c.qty+'" min="1" onchange="eaCartQty('+c.id+',this.value)"></td>'
+'<td class="text-right font-semibold">'+fmtNum(lp)+'</td>'
+'<td class="text-center"><button class="btn btn-ghost btn-sm text-danger" onclick="eaCartRemove('+c.id+')"><i class="fa fa-times"></i></button></td></tr>';});
body.innerHTML=h;updateTotal();}

function updateTotal(){var t=0,ct=0;_cart.forEach(function(c){t+=c.qty*c.price;ct+=c.qty*c.cost;});var e=$('eaTotalAmt');if(e)e.textContent=t.toLocaleString()+'원';var ce=$('ea_cost_total');if(ce)ce.value=ct.toLocaleString();
/* 수주금액 자동 동기화 (수동 수정 전까지) */
var oa=$('ea_order_amount');if(oa&&!oa._manualEdit&&t>0){oa.value=t.toLocaleString();}
eaCalcIncrease();
/* 5번: 견적명 자동 생성 (V1 autoEstName) */
autoEstName();}

/* 5번: V1 autoEstName — 첫 품목명 + " 외 N건" */
function autoEstName(){
    var el=$('ea_title');if(!el||el._userEdited)return;
    var mains=_cart.filter(function(c){return!c.is_child;});
    if(!mains.length)return;
    var first='';for(var i=0;i<mains.length;i++){if(mains[i].name){first=mains[i].name;break;}}
    if(!first)return;
    var extra=mains.length>1?' 외 '+(mains.length-1)+'건':'';
    el.value=first+extra;
}
window.eaCalcIncrease=function(){var o=parseInt((($('ea_order_amount')||{}).value||'').replace(/[^\d]/g,''),10)||0;var c=parseInt((($('ea_cost_total')||{}).value||'').replace(/[^\d]/g,''),10)||0;var e=$('ea_increase');if(e)e.value=(o-c).toLocaleString();};

/* ── 파일 ── */
window.eaUploadFile=function(){var inp=document.createElement('input');inp.type='file';inp.multiple=true;inp.onchange=function(){for(var i=0;i<inp.files.length;i++)_pendingFiles.push(inp.files[i]);renderNewFiles();};inp.click();};
function renderNewFiles(){var e=$('eaNewFiles');if(!e)return;if(!_pendingFiles.length){e.innerHTML='';return;}e.innerHTML=_pendingFiles.map(function(f,i){return'<div class="flex items-center gap-2 mb-1"><i class="fa fa-file-o text-3"></i><span>'+escH(f.name)+'</span><button class="btn btn-ghost btn-sm text-danger" onclick="eaRemovePending('+i+')"><i class="fa fa-times"></i></button></div>';}).join('');}
window.eaRemovePending=function(i){_pendingFiles.splice(i,1);renderNewFiles();};
function loadExistFiles(){if(!_isModify||!_modifyIdx)return;SHV.api.get('dist_process/saas/Site.php',{todo:'est_file_list',estimate_idx:_modifyIdx}).then(function(r){if(!r.ok)return;var fl=r.data.data||r.data||[];var e=$('eaExistFiles');if(!e||!fl.length)return;e.innerHTML=fl.map(function(f){var u=safeUrl(f.url||f.file_url||'');return'<div class="flex items-center gap-2 mb-1"><i class="fa fa-file-o text-3"></i><span>'+escH(f.original_name||f.filename||'')+'</span>'+(u?'<a href="'+escH(u)+'" target="_blank" rel="noopener noreferrer" class="text-accent text-xs">다운로드</a>':'')+'</div>';}).join('');}).catch(function(){});}

/* ── 8번: 구버전 매핑 상태 로드 (codex est_legacy_mapping_status) ── */
function loadLegacyMapping(){if(!_isModify||!_modifyIdx)return;
    safeFetch(EST_API.EST_LEGACY_MAP,{estimate_idx:_modifyIdx},{data:[],summary:{}}).then(function(d){
        if(d._pending)return;
        _legacyMappings={};
        (d.data||[]).forEach(function(m){_legacyMappings[parseInt(m.estimate_item_idx,10)||0]={status:m.mapping_status||'',reason:m.mapping_reason||''};});
        var s=d.summary||{};var sum=parseInt(s.total||0,10);
        if(sum>0){
            var box=$('eaLegacySummary');if(box)box.classList.remove('hidden');
            var ec=$('eaLegacyCompleted');if(ec)ec.textContent='완료 '+(s.completed||0);
            var ep=$('eaLegacyPending');  if(ep)ep.textContent='대기 '+(s.pending||0);
            var ef=$('eaLegacyFailed');   if(ef)ef.textContent='실패 '+(s.failed||0);
        }
        renderCart(); /* 배지 다시 그리기 */
    });
}
function legacyBadge(originIdx){
    var m=_legacyMappings[originIdx];if(!m||!m.status)return'';
    var cls=m.status==='완료'?'is-completed':(m.status==='실패'?'is-failed':'is-pending');
    return'<span class="ea-mapping-badge '+cls+'" title="'+escH(m.reason||'')+'">'+escH(m.status)+'</span>';
}

/* ── 수정 모드 ── */
function loadModifyData(){if(!_isModify||!_modifyIdx)return;SHV.api.get('dist_process/saas/Site.php',{todo:'est_detail',estimate_idx:_modifyIdx}).then(function(res){if(!res.ok)return;var d=res.data.estimate||res.data;
/* 핫픽스 #1: 저장된 견적명을 autoEstName 이 덮어쓰지 않도록 _userEdited=true 강제 */
if($('ea_title')){var _t=$('ea_title');_t.value=d.estimate_title||d.title||d.name||'';_t._userEdited=true;}
if($('ea_no'))$('ea_no').value=d.estimate_no||'';if($('ea_date'))$('ea_date').value=(d.estimate_date||d.est_date||d.created_at||'').substring(0,10);if($('ea_status'))$('ea_status').value=d.estimate_status||d.status||'DRAFT';if($('ea_memo'))$('ea_memo').value=d.memo||'';if($('ea_employee'))$('ea_employee').value=d.employee_idx||'';if($('ea_order_amount')){var oa=parseInt(d.order_amount||0,10);$('ea_order_amount').value=oa?oa.toLocaleString():'';if(oa)$('ea_order_amount')._manualEdit=true;}
/* 핫픽스 #2: site_idx/member_idx 가 비동기로 채워진 후 헤더 재로드 */
var _hadSite=!!_siteIdx,_hadMember=!!_memberIdx;
if(!_siteIdx&&d.site_idx)_siteIdx=d.site_idx;if(!_memberIdx&&d.member_idx)_memberIdx=d.member_idx;
if((!_hadSite&&_siteIdx)||(!_hadMember&&_memberIdx))loadHeaderInfo();
/* codex 확인: Tb_EstimateItem 에 parent 키 없음 → V1 패턴(sort 순서로 직전 본체에 묶기) */
var items=res.data.items||d.items||[];var lastParentId=0;
items.forEach(function(it){
    _cartId++;
    var isChild=!!parseInt(it.is_child||0,10);
    if(!isChild)lastParentId=_cartId;
    _cart.push({
        id:_cartId,
        product_idx:it.product_idx||it.item_idx||0,
        name:it.name||it.item_name||'',
        standard:it.standard||it.spec||'',
        unit:it.unit||'',
        price:parseInt(it.sale_price||it.price||0,10),
        cost:parseInt(it.cost||it.purchase_price||0,10),
        qty:parseInt(it.qty||it.quantity||1,10),
        attribute:it.attribute||'',
        is_split:isChild?0:parseInt(it.is_split||0,10),
        manual:false,
        is_child:isChild,
        parent_id:isChild?lastParentId:0,
        follow_mode:isChild?parseInt(it.follow_mode||1,10):0,
        origin_idx:it.idx
    });
});
renderCart();});}

/* ── 제출 ── */
window.eaSubmit=function(){showErr('');var title=(($('ea_title')||{}).value||'').trim();if(!title){showErr('견적명은 필수입니다.');if($('ea_title'))$('ea_title').focus();return;}if(!_cart.length){showErr('품목을 1개 이상 추가하세요.');return;}setLoading(true);
var products=_cart.map(function(c){return{item_idx:c.product_idx||0,name:c.name,standard:c.standard,unit:c.unit,sale_price:c.price,cost:c.cost,qty:c.qty,attribute:c.attribute||'',is_split:c.is_split||0,is_child:c.is_child?1:0,parent_cart_id:c.parent_id||0};});
var fd=new FormData();var ep=_isPjtMode?'dist_process/saas/Project.php':'dist_process/saas/Site.php';
fd.append('todo',_isPjtMode?'pjt_plan_save_items':(_isModify?'update_est':'insert_est'));if(_isModify)fd.append('estimate_idx',_modifyIdx);
fd.append('site_idx',_siteIdx);fd.append('member_idx',_memberIdx);fd.append('estimate_title',title);
/* codex 확인: update_est 가 estimate_no 받으면 그대로 update — 수정 모드에서는 전송 제외 (immutable 보호) */
if(!_isModify)fd.append('estimate_no',($('ea_no')||{}).value||'');
fd.append('estimate_date',($('ea_date')||{}).value||'');fd.append('estimate_status',($('ea_status')||{}).value||'DRAFT');fd.append('memo',($('ea_memo')||{}).value||'');fd.append('employee_idx',($('ea_employee')||{}).value||'');fd.append('order_amount',(($('ea_order_amount')||{}).value||'').replace(/[^\d]/g,''));
/* codex 백엔드 추가: cost_total, increase_amount 저장 */
fd.append('cost_total',(($('ea_cost_total')||{}).value||'').replace(/[^\d]/g,'')||'0');
fd.append('increase_amount',(($('ea_increase')||{}).value||'').replace(/[^\d-]/g,'')||'0');
fd.append('items',JSON.stringify(products));_pendingFiles.forEach(function(f){fd.append('est_files[]',f);});
SHV.api.upload(ep,fd).then(function(res){setLoading(false);if(res.ok){SHV.modal.close();if(SHV.toast)SHV.toast.success(_isModify?'견적이 수정되었습니다.':'견적이 등록되었습니다.');}else{showErr(res.message||'저장에 실패하였습니다.');}}).catch(function(){setLoading(false);showErr('네트워크 오류가 발생하였습니다.');});};

/* ── 작성자 DD ── */
function loadEstEmployees(){SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list',limit:200}).then(function(r){if(!r.ok)return;var s=$('ea_employee');if(!s)return;(r.data.data||r.data||[]).forEach(function(e){var o=document.createElement('option');o.value=e.idx;o.textContent=e.name+(e.team?' ('+e.team+')':'');s.appendChild(o);});}).catch(function(){});}

/* ── 1번: 헤더 정보 로드 (사이트명 + PJT 태그) ── */
function loadHeaderInfo(){
    if(_siteIdx){
        /* site_view.php 가 사용하는 Site.php?todo=detail — 사이트명 + 사업장명 동시 반환 */
        safeFetch(EST_API.SITE_DETAIL,{site_idx:_siteIdx},{}).then(function(d){
            var data=d.data||d;
            _siteName=data.site_display_name||data.site_name||data.name||data.member_name||'';
            var el=$('eaSiteName');if(el)el.textContent=_siteName;
        });
    }
    if(_siteIdx){
        /* codex 응답: data.data[] (탭 배열) + data.selected_idxs[] + data.selected_names[] */
        safeFetch(EST_API.EST_TAB_LIST,{site_idx:_siteIdx},{data:[],selected_idxs:[],selected_names:[]}).then(function(d){
            if(d._pending)return; /* 백엔드 미구현 → 대기중 skeleton 유지 */
            var allTabs=d.data||d.tabs||[];
            var selIdxs=d.selected_idxs||[];
            /* selected 가 있으면 그것만, 없으면 전체 표시 */
            _pjtTabs=selIdxs.length?allTabs.filter(function(t){return selIdxs.indexOf(parseInt(t.idx,10))>-1||t.selected;}):allTabs;
            renderPjtTags();
        });
    }
}
function renderPjtTags(){
    var box=$('eaPjtTags');if(!box)return;
    if(!_pjtTabs.length){box.innerHTML='';return;}
    box.innerHTML=_pjtTabs.map(function(t){return'<span class="ea-pjt-tag">'+escH(t.name||'')+'</span>';}).join('');
}

/* ── 초기화 ── */
/* 5번: 견적명 사용자 편집 플래그 */
var _eaTitleEl=$('ea_title');if(_eaTitleEl){_eaTitleEl.addEventListener('input',function(){this._userEdited=true;});}
loadHeaderInfo();loadPjtAttrs();loadEstEmployees();loadItemPropertyMaster();
if(_isModify){loadModifyData();loadExistFiles();loadLegacyMapping();}
if(!$('ea_date').value)$('ea_date').value=new Date().toISOString().slice(0,10);
})();
</script>
