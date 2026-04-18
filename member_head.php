<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';

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
    echo '<div class="empty-state"><p class="empty-message">인증이 필요합니다. 다시 로그인해주세요.</p></div>';
    exit;
}
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/css/v2/pages/fms.css')?:'1' ?>">
<section data-page="member_head" class="page-section">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1">본사관리</h2>
        <p class="text-xs text-3 mt-1 m-0">고객 본사 목록 조회 및 관리</p>
    </div>
    <div class="flex items-center gap-2">
        <span id="hdTotal" class="text-sm text-3 font-medium">-</span>
        <button class="btn btn-glass-primary btn-sm" onclick="hdOpenAdd()">등록</button>
    </div>
</div>

<!-- ── 검색 카드 ── -->
<div class="card flex-shrink-0 py-3 px-4">
    <div class="flex items-center gap-2 flex-wrap">
        <div class="shv-search flex-1 max-w-400">
            <i class="fa fa-search shv-search-icon"></i>
            <input type="text" id="hdSearch"
                placeholder="본사명, 대표자, 사업자번호, 주소..."
                onkeydown="if(event.key==='Enter')hdReload()"
                oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value)">
            <span class="shv-search-clear" onclick="document.getElementById('hdSearch').value='';this.closest('.shv-search').classList.remove('has-value');hdReload();">✕</span>
        </div>
        <button class="btn btn-outline btn-sm" id="btnHdDetail" onclick="hdToggleDetail()">
            <i class="fa fa-sliders"></i> 컬럼 필터
        </button>
        <button class="btn btn-ghost btn-sm" onclick="hdReload()" title="새로고침">
            <i class="fa fa-refresh"></i>
        </button>
    </div>
</div>

<!-- ── PC 테이블 ── -->
<div class="card hd-pc-only card-fill">
    <div id="hdTableWrap" class="tbl-scroll">
        <table id="hdTable" class="tbl tbl-sticky-header">
            <colgroup>
                <col class="col-no">
                <col><col><col><col><col><col><col><col><col>
                <col class="col-date">
            </colgroup>
            <thead>
                <tr>
                    <th class="th-center">No</th>
                    <th>본사명</th>
                    <th>본사구조</th>
                    <th>대표자</th>
                    <th>사업자번호</th>
                    <th>담당자</th>
                    <th>협력계약</th>
                    <th>사용견적</th>
                    <th>대표전화</th>
                    <th>주소</th>
                    <th>등록일</th>
                </tr>
            </thead>
            <tbody id="hdBody"></tbody>
        </table>
        <div id="hdSentinel" class="h-px"></div>
    </div>
    <div id="hdLoading" class="loading-row">
        <span class="spinner spinner-sm align-middle mr-1"></span>
        <span class="text-sm text-3">불러오는 중...</span>
    </div>
    <div id="hdPagingBar" class="paging-bar">
        <span id="hdPageInfo" class="paging-info"></span>
        <button id="hdLoadMoreBtn" class="btn btn-ghost btn-sm text-xs hidden" onclick="hdLoadMore()">
            <i class="fa fa-chevron-down"></i> 더 불러오기
        </button>
    </div>
</div>

<!-- ── 모바일 카드 ── -->
<div id="hdMobileWrap" class="hd-mobile-only flex-1 overflow-y-auto">
    <div class="sticky top-0 bg-content pb-2 z-10">
        <div class="flex gap-2">
            <div class="shv-search flex-1">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="hdMobileSearch" placeholder="본사명, 대표자, 주소..."
                    oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value);hdMobileFilter(this.value)">
                <span class="shv-search-clear" onclick="document.getElementById('hdMobileSearch').value='';this.closest('.shv-search').classList.remove('has-value');hdMobileFilter('');">✕</span>
            </div>
            <button class="btn btn-outline btn-sm" onclick="document.getElementById('hdFilterSheet').style.display='block'">
                <i class="fa fa-sliders"></i>
            </button>
        </div>
        <div class="mt-2 text-xs text-3">
            총 <b id="hdMobileCnt" class="text-accent">0</b>건
        </div>
    </div>
    <div id="hdMobileList"></div>
</div>

<!-- ── 모바일 상세 바텀시트 ── -->
<div id="hdDetailSheet" class="sheet-backdrop" onclick="this.style.display='none'">
    <div onclick="event.stopPropagation()" class="sheet hd-sheet-animated">
        <div class="sheet-grip">
            <div class="sheet-handle"></div>
        </div>
        <div id="hdSheetHead" class="sheet-head"></div>
        <div id="hdSheetBody" class="sheet-body"></div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="hdSheetGo()">
                <i class="fa fa-external-link"></i> 상세보기
            </button>
        </div>
    </div>
</div>

<!-- ── 모바일 필터 바텀시트 ── -->
<div id="hdFilterSheet" class="sheet-backdrop" onclick="this.style.display='none'">
    <div onclick="event.stopPropagation()" class="sheet">
        <div class="sheet-grip py-3 px-4 pb-2 flex-shrink-0">
            <div class="sheet-handle"></div>
            <div class="flex items-center justify-between">
                <span class="text-md font-bold text-1">상세 필터</span>
                <button class="btn btn-ghost btn-sm" onclick="hdMobileResetFilter()">초기화</button>
            </div>
        </div>
        <div class="sheet-filter-body">
            <div class="flex flex-col gap-3">
                <div class="form-group"><label class="form-label">본사명</label><input type="text" id="mhd_name" class="form-input" placeholder="본사명"></div>
                <div class="form-group"><label class="form-label">대표자</label><input type="text" id="mhd_ceo" class="form-input" placeholder="대표자"></div>
                <div class="form-group"><label class="form-label">사업자번호</label><input type="text" id="mhd_card" class="form-input" placeholder="사업자번호"></div>
                <div class="form-group"><label class="form-label">주소</label><input type="text" id="mhd_addr" class="form-input" placeholder="주소"></div>
                <div class="form-group">
                    <label class="form-label">유형</label>
                    <div class="flex gap-2">
                        <button class="btn btn-ghost btn-sm mhd-chip flex-1 border" data-val="법인" onclick="this.classList.toggle('mhd-chip-on')">법인</button>
                        <button class="btn btn-ghost btn-sm mhd-chip flex-1 border" data-val="개인" onclick="this.classList.toggle('mhd-chip-on')">개인</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="hdMobileApplyFilter()">
                <i class="fa fa-search"></i> 검색
            </button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';

var _page=1, _limit=100, _loading=false, _hasMore=true, _allData=[], _detailParams={}, _sheetIdx=null, _observer=null, _total=0;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }

/* ── 스켈레톤 로딩 ── */
function showSkeleton(){
    var body=$('hdBody'); if(!body) return;
    var tbl=body.closest('table');
    var colCount=11;
    if(tbl){ var fr=tbl.querySelector('thead tr'); if(fr) colCount=fr.querySelectorAll('th').length; }
    var widths=[36,100,72,65,88,65,76,100,76,160,60];
    var html='';
    for(var i=0;i<9;i++){
        html+='<tr class="tbl-skeleton-row">';
        for(var j=0;j<colCount;j++){
            var w=widths[j]||80;
            var rw=Math.round(w*(0.45+Math.random()*0.55));
            html+='<td><div class="skeleton-cell mx-auto" style="width:'+rw+'px;"></div></td>';
        }
        html+='</tr>';
    }
    body.innerHTML=html;
}

/* 컬럼 필터 토글 */
window.hdToggleDetail = function(){
    var btn=$('btnHdDetail');
    var filterRow=document.querySelector('#hdTable .tbl-filter-row');
    var open=filterRow&&filterRow.classList.contains('filter-visible');
    if(filterRow) filterRow.classList.toggle('filter-visible',!open);
    if(btn){ btn.classList.toggle('btn-primary',!open); btn.classList.toggle('btn-outline',open); }
    if(open){ var tbl=$('hdTable'); if(tbl&&tbl._shvFilterReset) tbl._shvFilterReset(); }
};

/* 상세검색 적용 */
window.hdApplyDetail = function(){
    _detailParams={};
    [['hd_name','ds_name'],['hd_ceo','ds_ceo'],['hd_card','ds_card'],
     ['hd_tel','ds_tel'],['hd_addr','ds_addr'],['hd_head_number','ds_head_number'],
     ['hd_type','ds_type'],['hd_contract','ds_contract']].forEach(function(f){
        var el=$(f[0]); if(el&&el.value.trim()) _detailParams[f[1]]=el.value.trim();
    });
    hdReload();
};

/* 상세검색 초기화 */
window.hdResetDetail = function(){
    ['hd_name','hd_ceo','hd_card','hd_tel','hd_addr','hd_head_number','hd_contract'].forEach(function(id){ var el=$(id);if(el)el.value=''; });
    var t=$('hd_type'); if(t)t.value='';
    _detailParams={};
    hdReload();
};

/* 파라미터 빌드 */
function buildParams(page){
    var p={todo:'list',p:page,limit:_limit};
    var s=$('hdSearch'); if(s&&s.value.trim()) p.search=s.value.trim();
    Object.assign(p,_detailParams);
    return p;
}

/* 데이터 로드 */
function loadData(page, append){
    if(_loading) return;
    _loading=true;
    var ld=$('hdLoading'); if(ld) ld.style.display='block';

    SHV.api.get('dist_process/saas/HeadOffice.php', buildParams(page))
        .then(function(res){
            _loading=false;
            if(ld) ld.style.display='none';
            if(!res.ok){ if(SHV.toast) SHV.toast.error(res.message||'데이터 로드 실패'); return; }

            var rows = res.data.data||[];
            var total = res.data.total||0;
            if(!append) _allData=[];
            _allData = _allData.concat(rows);
            _hasMore = rows.length >= _limit;
            _total = total;

            var totalEl=$('hdTotal'); if(totalEl) totalEl.textContent=total.toLocaleString()+'건';
            var mCnt=$('hdMobileCnt'); if(mCnt) mCnt.textContent=total.toLocaleString();

            /* 페이징 푸터 업데이트 */
            var pgInfo=$('hdPageInfo');
            if(pgInfo) pgInfo.textContent=_allData.length.toLocaleString()+'건 / 총 '+total.toLocaleString()+'건';
            var moreBtn=$('hdLoadMoreBtn');
            if(moreBtn) moreBtn.style.display=_hasMore?'':'none';

            if(window.innerWidth<=768){ renderCards(rows,append); }
            else { renderRows(rows,append,append?(_page-1)*_limit:0); }

            if(_hasMore) observeSentinel();
        })
        .catch(function(){ _loading=false; if(ld) ld.style.display='none'; if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* 전체 재로드 */
window.hdReload = function(){
    _page=1; _hasMore=true; _allData=[];
    var b=$('hdBody');
    if(b){
        var tbl=b.closest('table');
        if(tbl && tbl._shvFilterReset) tbl._shvFilterReset();
        b.innerHTML='';
        showSkeleton();
    }
    var ml=$('hdMobileList'); if(ml) ml.innerHTML='';
    disconnectObserver();
    loadData(1,false);
};

/* 더 불러오기 */
window.hdLoadMore = function(){
    if(_hasMore && !_loading){ _page++; loadData(_page,true); }
};

/* PC 테이블 렌더링 */
function renderRows(rows, append, startIdx){
    var body=$('hdBody'); if(!body) return;
    if(!append){
        body.innerHTML=''; startIdx=0;
        /* 첫 렌더 시 정렬 + 체크박스 + 컬럼 필터 초기화 (이중 바인딩 방지) */
        var tbl=body.closest('table');
        if(tbl && window.shvTblSort && !tbl._shvSortInit) { shvTblSort(tbl); }
        if(tbl && window.shvTblSelect && !tbl._shvSelInit) {
            shvTblSelect(tbl, {
                actions: [
                    { key:'export', label:'CSV 내보내기', icon:'fa-download' },
                    { key:'delete', label:'선택 삭제', icon:'fa-trash' }
                ],
                onAction: function(action, rows){ hdBulkAction(action, rows); }
            });
        }
        /* 컬럼 필터: th-check(0번)은 자동 스킵, No 행번호(1번)도 스킵 */
        if(tbl && window.shvTblFilter && !tbl._shvFilterInit) {
            shvTblFilter(tbl, { skip: [1] });
        }
    }
    if(!rows.length && !append){
        var tbl2=body.closest('table');
        var colCount=tbl2&&tbl2.querySelector('thead tr')?tbl2.querySelector('thead tr').querySelectorAll('th').length:11;
        body.innerHTML='<tr><td colspan="'+colCount+'" class="text-center p-12">'
            +'<div class="text-4xl opacity-50">🏢</div>'
            +'<p class="text-3 mt-2">등록된 본사가 없습니다</p></td></tr>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var no=startIdx+i+1;
        var ue='';
        try{
            var arr=JSON.parse(r.used_estimate||'[]');
            if(Array.isArray(arr)) arr.forEach(function(u){ ue+='<span class="badge badge-accent ml-1">'+escH(u.name||u)+'</span>'; });
            else if(r.used_estimate) ue='<span class="badge badge-accent">'+escH(r.used_estimate)+'</span>';
        }catch(e){ if(r.used_estimate) ue='<span class="badge badge-accent">'+escH(r.used_estimate)+'</span>'; }

        html+='<tr class="cursor-pointer" onclick="hdGo('+r.idx+')"'
            +' data-idx="'+r.idx+'" data-name="'+escH(r.name||'')+'"'
            +' data-ceo="'+escH(r.ceo||'')+'" data-card="'+escH(r.card_number||'')+'"'
            +' data-tel="'+escH(r.tel||'')+'" data-addr="'+escH(r.address||'')+'"'
            +' data-date="'+fmtDate(r.registered_date)+'">'
            +'<td class="td-no">'+no+'</td>'
            +'<td><b class="text-1">'+escH(r.name)+'</b></td>'
            +'<td>'+(r.head_structure?'<span class="badge badge-ghost">'+escH(r.head_structure)+'</span>':'')+'</td>'
            +'<td>'+escH(r.ceo||'')+'</td>'
            +'<td class="text-xs">'+escH(r.card_number||'')+'</td>'
            +'<td>'+escH(r.employee_name||'')+'</td>'
            +'<td>'+escH(r.cooperation_contract||'')+'</td>'
            +'<td>'+ue+'</td>'
            +'<td class="whitespace-nowrap">'+escH(r.tel||'')+'</td>'
            +'<td class="truncate max-w-200" title="'+escH(r.address||'')+'">'+escH(r.address||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.registered_date)+'</td>'
            +'</tr>';
    });
    body.insertAdjacentHTML('beforeend',html);
}

/* 모바일 카드 렌더링 */
function renderCards(rows, append){
    var list=$('hdMobileList'); if(!list) return;
    if(!append) list.innerHTML='';
    if(!rows.length && !append){
        list.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30">🏢</div><p class="text-3 mt-2">등록된 본사가 없습니다</p></div>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var di=_allData.length-rows.length+i;
        var srch=((r.name||'')+' '+(r.ceo||'')+' '+(r.address||'')+' '+(r.tel||'')).toLowerCase();
        html+='<div class="hd-mc" data-search="'+escH(srch)+'" onclick="hdShowSheet('+di+')">'
            +'<div class="flex justify-between items-start gap-2">'
            +'<div class="flex-1 min-w-0">'
            +'<div class="font-semibold text-1 text-md">'+escH(r.name)+'</div>'
            +(r.head_structure?'<span class="badge badge-ghost mt-px">'+escH(r.head_structure)+'</span>':'')
            +'</div>'
            +'<span class="text-xs text-3 whitespace-nowrap mt-px">'+fmtDate(r.registered_date)+'</span>'
            +'</div>'
            +'<div class="flex flex-wrap gap-2 mt-2">'
            +(r.ceo?'<span class="text-xs text-2"><i class="fa fa-user text-3 mr-1"></i>'+escH(r.ceo)+'</span>':'')
            +(r.tel?'<span class="text-xs text-2"><i class="fa fa-phone text-3 mr-1"></i>'+escH(r.tel)+'</span>':'')
            +(r.employee_name?'<span class="text-xs text-2"><i class="fa fa-briefcase text-3 mr-1"></i>'+escH(r.employee_name)+'</span>':'')
            +'</div>'
            +(r.address?'<div class="text-xs text-3 mt-1 truncate"><i class="fa fa-map-marker mr-1"></i>'+escH(r.address)+'</div>':'')
            +'</div>';
    });
    list.insertAdjacentHTML('beforeend',html);
}

/* 모바일 카드 클라이언트 필터 */
window.hdMobileFilter = function(q){
    var list=$('hdMobileList'); if(!list) return;
    q=(q||'').toLowerCase().trim();
    var cnt=0;
    list.querySelectorAll('.hd-mc').forEach(function(c){
        var m=!q||(c.dataset.search||'').indexOf(q)>-1;
        c.style.display=m?'':'none'; if(m)cnt++;
    });
    var el=$('hdMobileCnt'); if(el) el.textContent=cnt.toLocaleString();
};

/* 모바일 바텀시트 */
window.hdShowSheet = function(di){
    var d=_allData[di]; if(!d) return;
    _sheetIdx=d.idx;
    $('hdSheetHead').innerHTML='<div class="text-lg font-bold text-1">'+escH(d.name)+'</div>'
        +(d.head_structure?'<div class="mt-1"><span class="badge badge-ghost">'+escH(d.head_structure)+'</span></div>':'');
    var fields=[['대표자',d.ceo],['사업자번호',d.card_number],['담당자',d.employee_name],['대표전화',d.tel],['협력계약',d.cooperation_contract],['등록일',fmtDate(d.registered_date)]];
    var html='';
    fields.forEach(function(f){ if(f[1]) html+='<div class="hd-sr"><span class="hd-sl">'+escH(f[0])+'</span><span class="hd-sv">'+escH(f[1])+'</span></div>'; });
    if(d.address){
        html+='<div class="hd-sr"><span class="hd-sl">주소</span><div class="flex-1">'
            +'<div class="hd-sv mb-2">'+escH(d.address+(d.address_detail?' '+d.address_detail:''))+'</div>'
            +'<button class="btn btn-outline btn-sm w-full" data-copy="'+escH(d.address||'')+'" onclick="event.stopPropagation();var a=this.getAttribute(\'data-copy\');navigator.clipboard&&navigator.clipboard.writeText(a).then(function(){if(SHV.toast)SHV.toast.success(\'주소 복사됨\')})"><i class="fa fa-copy"></i> 복사</button>'
            +'</div></div>';
    }
    $('hdSheetBody').innerHTML=html;
    $('hdDetailSheet').style.display='block';
};
window.hdSheetGo = function(){
    $('hdDetailSheet').style.display='none';
    if(_sheetIdx) hdGo(_sheetIdx);
};

/* 모바일 필터 */
window.hdMobileApplyFilter = function(){
    _detailParams={};
    var n=$('mhd_name'); if(n&&n.value.trim()) _detailParams.ds_name=n.value.trim();
    var c=$('mhd_ceo'); if(c&&c.value.trim()) _detailParams.ds_ceo=c.value.trim();
    var cd=$('mhd_card'); if(cd&&cd.value.trim()) _detailParams.ds_card=cd.value.trim();
    var a=$('mhd_addr'); if(a&&a.value.trim()) _detailParams.ds_addr=a.value.trim();
    document.querySelectorAll('.mhd-chip.mhd-chip-on').forEach(function(ch){ _detailParams.ds_type=ch.dataset.val; });
    $('hdFilterSheet').style.display='none';
    hdReload();
};
window.hdMobileResetFilter = function(){
    ['mhd_name','mhd_ceo','mhd_card','mhd_addr'].forEach(function(id){ var el=$(id);if(el)el.value=''; });
    document.querySelectorAll('.mhd-chip').forEach(function(c){ c.classList.remove('mhd-chip-on'); });
};

/* 상세 이동 */
window.hdGo = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('head_view',{head_idx:idx});
};

/* 등록 */
window.hdOpenAdd = function(){
    SHV.modal.open('member_head_add.php', '본사 등록', 'lg');
};

/* Bulk Action 핸들러 */
window.hdBulkAction = function(action, rows){
    if(!rows.length){ if(SHV.toast) SHV.toast.warn('선택된 항목이 없습니다.'); return; }

    if(action==='export'){
        /* ── 선택 행 CSV 내보내기 ── */
        var idxSet={};
        rows.forEach(function(r){ if(r.id) idxSet[r.id]=true; });
        var toExp=_allData.filter(function(d){ return idxSet[String(d.idx)]; });
        var header='본사명,대표자,사업자번호,대표전화,주소,등록일\n';
        var csvBody=toExp.map(function(d){
            return [d.name,d.ceo,d.card_number,d.tel,d.address,fmtDate(d.registered_date)]
                .map(function(v){ return '"'+String(v||'').replace(/"/g,'""')+'"'; })
                .join(',');
        }).join('\n');
        var blob=new Blob(['\uFEFF'+header+csvBody],{type:'text/csv;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download='본사목록_'+new Date().toISOString().slice(0,10)+'.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        if(SHV.toast) SHV.toast.success(toExp.length+'건 CSV 내보내기 완료');
    }

    if(action==='delete'){
        /* ── 선택 행 삭제 (role_level >= 4 서버 검증) ── */
        var idList=rows.map(function(r){ return r.id; }).filter(Boolean);
        if(!idList.length){ if(SHV.toast) SHV.toast.warn('삭제할 항목이 없습니다.'); return; }
        var names=_allData
            .filter(function(d){ return idList.indexOf(String(d.idx))>-1; })
            .map(function(d){ return d.name; }).join(', ');

        if(window.SHV && SHV.confirm){
            SHV.confirm({
                title: '본사 삭제',
                message: '선택한 '+idList.length+'건을 삭제하시겠습니까?\n\n'+names,
                type: 'danger',
                confirmText: '삭제',
                onConfirm: function(){ doDelete(idList); }
            });
        } else {
            /* confirm 모달 미로드 시 바로 진행 */
            doDelete(idList);
        }
    }
};

function doDelete(idList){
    SHV.api.post('dist_process/saas/HeadOffice.php', { todo:'delete', idx_list: idList.join(',') })
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(idList.length+'건 삭제 완료');
                hdReload();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'삭제 실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* 무한스크롤 */
function observeSentinel(){
    var s=$('hdSentinel'); if(!s||!_hasMore||_observer) return;
    _observer=new IntersectionObserver(function(entries){
        if(entries[0].isIntersecting&&_hasMore&&!_loading){ _page++; loadData(_page,true); }
    },{root:$('hdTableWrap'),rootMargin:'100px',threshold:0});
    _observer.observe(s);
}
function disconnectObserver(){ if(_observer){_observer.disconnect();_observer=null;} }

/* 초기 스켈레톤 표시 후 로드 */
showSkeleton();
loadData(1,false);
})();
</script>
</section>
