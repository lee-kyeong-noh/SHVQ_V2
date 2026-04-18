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

/* 예정사업장 라우트 분기: ?r=member_planned → 상태 '예정' 고정 필터 */
$isPlanned = ($_GET['r'] ?? '') === 'member_planned';
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<section data-page="member_branch" data-title="<?= $isPlanned ? '예정사업장' : '사업장관리' ?>">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1"><?= $isPlanned ? '예정사업장' : '사업장관리' ?></h2>
        <p class="text-xs text-3 mt-1 m-0"><?= $isPlanned ? '예정 상태 사업장 목록' : '사업장 목록 조회 및 관리' ?></p>
    </div>
    <div class="flex items-center gap-2">
        <span id="mbNewBadge" class="badge badge-accent text-xs hidden"><b>N</b> <span id="mbNewCnt">0</span></span>
        <span id="mbTotal" class="text-sm text-3 font-medium">-</span>
        <button class="btn btn-glass-primary btn-sm" onclick="mbOpenAdd()">등록</button>
    </div>
</div>

<!-- ── 검색 카드 ── -->
<div class="card flex-shrink-0 py-3 px-4">
    <div class="flex items-center gap-2 flex-wrap">
        <div class="shv-search flex-1 max-w-400">
            <i class="fa fa-search shv-search-icon"></i>
            <input type="text" id="mbSearch"
                placeholder="사업장명, 대표자, 사업자번호, 주소..."
                onkeydown="if(event.key==='Enter')mbReload()"
                oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value)">
            <span class="shv-search-clear" onclick="document.getElementById('mbSearch').value='';this.closest('.shv-search').classList.remove('has-value');mbReload();">✕</span>
        </div>
        <button class="btn btn-outline btn-sm" id="btnMbDetail" onclick="mbToggleDetail()">
            <i class="fa fa-sliders"></i> 컬럼 필터
        </button>
        <button class="btn btn-ghost btn-sm" onclick="mbReload()" title="새로고침">
            <i class="fa fa-refresh"></i>
        </button>
    </div>
</div>

<!-- ── PC 테이블 ── -->
<div class="card mb-pc-only card-fill">
    <div id="mbTableWrap" class="tbl-scroll">
        <table id="mbTable" class="tbl tbl-sticky-header">
            <colgroup>
                <col><col><col><col><col><col><col><col><col><col><col><col>
            </colgroup>
            <thead>
                <tr>
                    <th class="th-center">No</th>
                    <th>사업장명</th>
                    <th>본사</th>
                    <th>그룹</th>
                    <th>대표자</th>
                    <th>사업자번호</th>
                    <th>담당자</th>
                    <th>현장</th>
                    <th>상태</th>
                    <th>전화번호</th>
                    <th>주소</th>
                    <th>등록일</th>
                </tr>
            </thead>
            <tbody id="mbBody"></tbody>
        </table>
        <div id="mbSentinel" class="h-px"></div>
    </div>
    <div id="mbLoading" class="loading-row">
        <span class="spinner spinner-sm align-middle mr-1"></span>
        <span class="text-sm text-3">불러오는 중...</span>
    </div>
    <div id="mbPagingBar" class="paging-bar">
        <span id="mbPageInfo" class="paging-info"></span>
        <button id="mbLoadMoreBtn" class="btn btn-ghost btn-sm text-xs hidden" onclick="mbLoadMore()">
            <i class="fa fa-chevron-down"></i> 더 불러오기
        </button>
    </div>
</div>

<!-- ── 모바일 카드 ── -->
<div id="mbMobileWrap" class="mb-mobile-only flex-1 overflow-y-auto">
    <div class="sticky top-0 bg-content pb-2 z-10">
        <div class="flex gap-2">
            <div class="shv-search flex-1">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="mbMobileSearch" placeholder="사업장명, 대표자, 주소..."
                    oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value);mbMobileFilter(this.value)">
                <span class="shv-search-clear" onclick="document.getElementById('mbMobileSearch').value='';this.closest('.shv-search').classList.remove('has-value');mbMobileFilter('');">✕</span>
            </div>
            <button class="btn btn-outline btn-sm" onclick="mbOpenFilterSheet()">
                <i class="fa fa-sliders"></i>
            </button>
        </div>
        <div class="mt-2 text-xs text-3">
            총 <b id="mbMobileCnt" class="text-accent">0</b>건
        </div>
    </div>
    <div id="mbMobileList"></div>
</div>

<!-- ── 모바일 상세 바텀시트 ── -->
<div id="mbDetailSheet" class="sheet-backdrop" onclick="mbCloseSheet('mbDetailSheet')">
    <div onclick="event.stopPropagation()" class="sheet mb-sheet-animated">
        <div class="sheet-grip"><div class="sheet-handle"></div></div>
        <div id="mbSheetHead" class="sheet-head"></div>
        <div id="mbSheetBody" class="sheet-body"></div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="mbSheetGo()">
                <i class="fa fa-external-link"></i> 상세보기
            </button>
        </div>
    </div>
</div>

<!-- ── 모바일 필터 바텀시트 ── -->
<div id="mbFilterSheet" class="sheet-backdrop" onclick="mbCloseSheet('mbFilterSheet')">
    <div onclick="event.stopPropagation()" class="sheet">
        <div class="sheet-grip py-3 px-4 pb-2 flex-shrink-0">
            <div class="sheet-handle"></div>
            <div class="flex items-center justify-between">
                <span class="text-md font-bold text-1">상세 필터</span>
                <button class="btn btn-ghost btn-sm" onclick="mbMobileResetFilter()">초기화</button>
            </div>
        </div>
        <div class="sheet-filter-body">
            <div class="flex flex-col gap-3">
                <div class="form-group"><label class="form-label">사업장명</label><input type="text" id="mmb_name" class="form-input" placeholder="사업장명"></div>
                <div class="form-group"><label class="form-label">대표자</label><input type="text" id="mmb_ceo" class="form-input" placeholder="대표자"></div>
                <div class="form-group"><label class="form-label">사업자번호</label><input type="text" id="mmb_card" class="form-input" placeholder="사업자번호"></div>
                <div class="form-group"><label class="form-label">주소</label><input type="text" id="mmb_addr" class="form-input" placeholder="주소"></div>
                <div class="form-group">
                    <label class="form-label">상태</label>
                    <div class="flex gap-2">
                        <button class="btn btn-ghost btn-sm mmb-chip flex-1 border" data-val="예정" onclick="this.classList.toggle('mmb-chip-on')">예정</button>
                        <button class="btn btn-ghost btn-sm mmb-chip flex-1 border" data-val="운영" onclick="this.classList.toggle('mmb-chip-on')">운영</button>
                        <button class="btn btn-ghost btn-sm mmb-chip flex-1 border" data-val="중지" onclick="this.classList.toggle('mmb-chip-on')">중지</button>
                        <button class="btn btn-ghost btn-sm mmb-chip flex-1 border" data-val="종료" onclick="this.classList.toggle('mmb-chip-on')">종료</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="mbMobileApplyFilter()">
                <i class="fa fa-search"></i> 검색
            </button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';

var _page=1, _limit=100, _loading=false, _hasMore=true, _allData=[], _detailParams={}, _sheetIdx=null, _observer=null, _total=0;
var _isPlanned = <?= $isPlanned ? 'true' : 'false' ?>;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }

/* ── 상태 배지 HTML ── */
function statusBadge(s){
    if(!s) return '';
    return '<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>';
}

/* ── 스켈레톤 ── */
function showSkeleton(){
    var body=$('mbBody'); if(!body) return;
    var tbl=body.closest('table');
    var colCount=12;
    if(tbl){ var fr=tbl.querySelector('thead tr'); if(fr) colCount=fr.querySelectorAll('th').length; }
    var html='';
    for(var i=0;i<9;i++){
        html+='<tr class="tbl-skeleton-row">';
        for(var j=0;j<colCount;j++){
            html+='<td><div class="skeleton-cell mx-auto"></div></td>';
        }
        html+='</tr>';
    }
    body.innerHTML=html;
}

/* ── 컬럼 필터 토글 ── */
window.mbToggleDetail = function(){
    var btn=$('btnMbDetail');
    var filterRow=document.querySelector('#mbTable .tbl-filter-row');
    var open=filterRow&&filterRow.classList.contains('filter-visible');
    if(filterRow) filterRow.classList.toggle('filter-visible',!open);
    if(btn){ btn.classList.toggle('btn-primary',!open); btn.classList.toggle('btn-outline',open); }
    if(open){ var tbl=$('mbTable'); if(tbl&&tbl._shvFilterReset) tbl._shvFilterReset(); }
};

/* ── URL 파라미터 파싱 ── */
function getUrlParam(key){
    var params = new URLSearchParams(window.location.search);
    return params.get(key) || '';
}
var _empFilter = getUrlParam('employee');
var _empIdxFilter = getUrlParam('employee_idx');

/* ── 파라미터 빌드 ── */
function buildParams(page){
    var p={todo:'list',p:page,limit:_limit};
    var s=$('mbSearch'); if(s&&s.value.trim()) p.search=s.value.trim();
    if(_isPlanned) p.member_status='예정';
    if(_empFilter) p.employee=_empFilter;
    if(_empIdxFilter) p.employee_idx=_empIdxFilter;
    Object.assign(p,_detailParams);
    return p;
}

/* ── 데이터 로드 ── */
function loadData(page, append){
    if(_loading) return;
    _loading=true;
    var ld=$('mbLoading'); if(ld) ld.style.display='block';

    SHV.api.get('dist_process/saas/Member.php', buildParams(page))
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

            var totalEl=$('mbTotal'); if(totalEl) totalEl.textContent=total.toLocaleString()+'건';
            var mCnt=$('mbMobileCnt'); if(mCnt) mCnt.textContent=total.toLocaleString();

            /* 이번달 신규 배지 */
            var nc=res.data.new_count||0;
            var nbEl=$('mbNewBadge'), ncEl=$('mbNewCnt');
            if(nbEl&&nc>0){ ncEl.textContent=nc; nbEl.classList.remove('hidden'); }
            else if(nbEl){ nbEl.classList.add('hidden'); }

            var pgInfo=$('mbPageInfo');
            if(pgInfo) pgInfo.textContent=_allData.length.toLocaleString()+'건 / 총 '+total.toLocaleString()+'건';
            var moreBtn=$('mbLoadMoreBtn');
            if(moreBtn) moreBtn.style.display=_hasMore?'':'none';

            if(window.innerWidth<=768){ renderCards(rows,append); }
            else { renderRows(rows,append,append?(_page-1)*_limit:0); }

            if(_hasMore) observeSentinel();
        })
        .catch(function(){ _loading=false; if(ld) ld.style.display='none'; if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 전체 재로드 ── */
window.mbReload = function(){
    _page=1; _hasMore=true; _allData=[];
    var b=$('mbBody');
    if(b){
        var tbl=b.closest('table');
        if(tbl && tbl._shvFilterReset) tbl._shvFilterReset();
        b.innerHTML='';
        showSkeleton();
    }
    var ml=$('mbMobileList'); if(ml) ml.innerHTML='';
    disconnectObserver();
    loadData(1,false);
};

/* ── 더 불러오기 ── */
window.mbLoadMore = function(){
    if(_hasMore && !_loading){ _page++; loadData(_page,true); }
};

/* ── PC 테이블 렌더링 ── */
function renderRows(rows, append, startIdx){
    var body=$('mbBody'); if(!body) return;
    if(!append){
        body.innerHTML=''; startIdx=0;
        var tbl=body.closest('table');
        if(tbl && window.shvTblSort && !tbl._shvSortInit) { shvTblSort(tbl); }
        if(tbl && window.shvTblSelect && !tbl._shvSelInit) {
            shvTblSelect(tbl, {
                actions: [
                    { key:'export', label:'CSV 내보내기', icon:'fa-download' },
                    { key:'delete', label:'선택 삭제', icon:'fa-trash' }
                ],
                onAction: function(action, rows){ mbBulkAction(action, rows); }
            });
        }
        if(tbl && window.shvTblFilter && !tbl._shvFilterInit) {
            shvTblFilter(tbl, { skip: [1] });
        }
    }
    if(!rows.length && !append){
        var tbl2=body.closest('table');
        var colCount=tbl2&&tbl2.querySelector('thead tr')?tbl2.querySelector('thead tr').querySelectorAll('th').length:12;
        body.innerHTML='<tr><td colspan="'+colCount+'" class="text-center p-12">'
            +'<div class="text-4xl opacity-50"><i class="fa fa-map-marker"></i></div>'
            +'<p class="text-3 mt-2">등록된 사업장이 없습니다</p></td></tr>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var no=startIdx+i+1;
        var status = r.member_status || r.status || '';

        /* 본사 셀: 연결됨→링크, 미연결→연결 버튼 */
        var headCell = '';
        if(r.head_name){
            headCell = '<span class="mb-head-link" onclick="event.stopPropagation();mbGoHead('+r.head_idx+')">'+escH(r.head_name)+'</span>';
        } else if(!r.head_idx || parseInt(r.head_idx)===0){
            headCell = '<button class="btn-link-head" onclick="event.stopPropagation();mbLinkHead('+r.idx+',\''+escH(r.name||'').replace(/'/g,"\\'")+'\')"><i class="fa fa-link"></i> 본사연결</button>';
        }

        html+='<tr class="cursor-pointer" onclick="mbGo('+r.idx+')"'
            +' data-idx="'+r.idx+'" data-name="'+escH(r.name||'')+'">'
            +'<td class="td-no">'+no+'</td>'
            +'<td><b class="text-1">'+escH(r.name)+'</b></td>'
            +'<td>'+headCell+'</td>'
            +'<td>'+(r.group_name?'<span class="badge badge-ghost">'+escH(r.group_name)+'</span>':'')+'</td>'
            +'<td>'+escH(r.ceo||'')+'</td>'
            +'<td class="text-xs">'+escH(r.card_number||'')+'</td>'
            +'<td>'+escH(r.employee_name||'')+'</td>'
            +'<td class="td-no">'+(parseInt(r.site_count)||0)+'</td>'
            +'<td>'+statusBadge(status)+'</td>'
            +'<td class="whitespace-nowrap">'+escH(r.tel||r.hp||'')+'</td>'
            +'<td class="truncate max-w-200" title="'+escH(r.address||'')+'">'+escH(r.address||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(r.registered_date||r.regdate||r.created_at)+'</td>'
            +'</tr>';
    });
    body.insertAdjacentHTML('beforeend',html);
}

/* ── 모바일 카드 렌더링 ── */
function renderCards(rows, append){
    var list=$('mbMobileList'); if(!list) return;
    if(!append) list.innerHTML='';
    if(!rows.length && !append){
        list.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-map-marker"></i></div><p class="text-3 mt-2">등록된 사업장이 없습니다</p></div>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var di=_allData.length-rows.length+i;
        var srch=((r.name||'')+' '+(r.ceo||'')+' '+(r.address||'')+' '+(r.tel||'')).toLowerCase();
        var status = r.member_status || r.status || '';

        html+='<div class="mb-mc" data-search="'+escH(srch)+'" onclick="mbShowSheet('+di+')">'
            +'<div class="flex justify-between items-start gap-2">'
            +'<div class="flex-1 min-w-0">'
            +'<div class="font-semibold text-1 text-md">'+escH(r.name)+'</div>'
            +(r.head_name?'<span class="text-xs text-3 mt-px"><i class="fa fa-building-o mr-1"></i>'+escH(r.head_name)+'</span>':'')
            +'</div>'
            +'<div class="flex items-center gap-2">'
            +statusBadge(status)
            +'<span class="text-xs text-3 whitespace-nowrap">'+fmtDate(r.registered_date||r.regdate||r.created_at)+'</span>'
            +'</div>'
            +'</div>'
            +'<div class="flex flex-wrap gap-2 mt-2">'
            +(r.ceo?'<span class="text-xs text-2"><i class="fa fa-user text-3 mr-1"></i>'+escH(r.ceo)+'</span>':'')
            +(r.tel?'<span class="text-xs text-2"><i class="fa fa-phone text-3 mr-1"></i>'+escH(r.tel)+'</span>':'')
            +(r.employee_name?'<span class="text-xs text-2"><i class="fa fa-briefcase text-3 mr-1"></i>'+escH(r.employee_name)+'</span>':'')
            +(parseInt(r.site_count)?'<span class="text-xs text-2"><i class="fa fa-building text-3 mr-1"></i>현장 '+parseInt(r.site_count)+'</span>':'')
            +'</div>'
            +(r.address?'<div class="text-xs text-3 mt-1 truncate"><i class="fa fa-map-marker mr-1"></i>'+escH(r.address)+'</div>':'')
            +'</div>';
    });
    list.insertAdjacentHTML('beforeend',html);
}

/* ── 시트 열기/닫기 ── */
window.mbOpenFilterSheet = function(){ var el=$('mbFilterSheet'); if(el) el.style.display='block'; };
window.mbCloseSheet = function(id){ var el=$(id); if(el) el.style.display='none'; };

/* ── 모바일 카드 필터 ── */
window.mbMobileFilter = function(q){
    var list=$('mbMobileList'); if(!list) return;
    q=(q||'').toLowerCase().trim();
    var cnt=0;
    list.querySelectorAll('.mb-mc').forEach(function(c){
        var m=!q||(c.dataset.search||'').indexOf(q)>-1;
        c.style.display=m?'':'none'; if(m)cnt++;
    });
    var el=$('mbMobileCnt'); if(el) el.textContent=cnt.toLocaleString();
};

/* ── 모바일 바텀시트 ── */
window.mbShowSheet = function(di){
    var d=_allData[di]; if(!d) return;
    _sheetIdx=d.idx;
    var status = d.member_status || d.status || '';
    $('mbSheetHead').innerHTML='<div class="text-lg font-bold text-1">'+escH(d.name)+'</div>'
        +'<div class="mt-1 flex gap-2">'+statusBadge(status)
        +(d.head_name?'<span class="text-xs text-3"><i class="fa fa-building-o mr-1"></i>'+escH(d.head_name)+'</span>':'')
        +'</div>';
    var fields=[['대표자',d.ceo],['사업자번호',d.card_number],['전화번호',d.tel],['협력계약',d.cooperation_contract],['등록일',fmtDate(d.registered_date||d.regdate||d.created_at)]];
    var html='';
    fields.forEach(function(f){ if(f[1]) html+='<div class="mb-sr"><span class="mb-sl">'+escH(f[0])+'</span><span class="mb-sv">'+escH(f[1])+'</span></div>'; });
    if(d.address){
        html+='<div class="mb-sr"><span class="mb-sl">주소</span><div class="flex-1">'
            +'<div class="mb-sv mb-2">'+escH(d.address+(d.address_detail?' '+d.address_detail:''))+'</div>'
            +'<button class="btn btn-outline btn-sm w-full" data-copy="'+escH(d.address||'')+'" onclick="event.stopPropagation();var a=this.getAttribute(\'data-copy\');navigator.clipboard&&navigator.clipboard.writeText(a).then(function(){if(SHV.toast)SHV.toast.success(\'주소 복사됨\')})"><i class="fa fa-copy"></i> 복사</button>'
            +'</div></div>';
    }
    $('mbSheetBody').innerHTML=html;
    var el=$('mbDetailSheet'); if(el) el.style.display='block';
};
window.mbSheetGo = function(){
    mbCloseSheet('mbDetailSheet');
    if(_sheetIdx) mbGo(_sheetIdx);
};

/* ── 모바일 필터 ── */
window.mbMobileApplyFilter = function(){
    _detailParams={};
    var n=$('mmb_name'); if(n&&n.value.trim()) _detailParams.ds_name=n.value.trim();
    var c=$('mmb_ceo'); if(c&&c.value.trim()) _detailParams.ds_ceo=c.value.trim();
    var cd=$('mmb_card'); if(cd&&cd.value.trim()) _detailParams.ds_card=cd.value.trim();
    var a=$('mmb_addr'); if(a&&a.value.trim()) _detailParams.ds_addr=a.value.trim();
    document.querySelectorAll('.mmb-chip.mmb-chip-on').forEach(function(ch){ _detailParams.member_status=ch.dataset.val; });
    mbCloseSheet('mbFilterSheet');
    mbReload();
};
window.mbMobileResetFilter = function(){
    ['mmb_name','mmb_ceo','mmb_card','mmb_addr'].forEach(function(id){ var el=$(id);if(el)el.value=''; });
    document.querySelectorAll('.mmb-chip').forEach(function(c){ c.classList.remove('mmb-chip-on'); });
};

/* ── 상세 이동 ── */
window.mbGo = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('member_branch_view',{member_idx:idx});
};

/* ── 본사 상세 이동 ── */
window.mbGoHead = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('head_view',{head_idx:idx});
};

/* ── 등록 ── */
window.mbOpenAdd = function(){
    SHV.modal.open('views/saas/fms/member_branch_add.php', '사업장 등록', 'lg');
};

/* ── 본사 연결 모달 ── */
window.mbLinkHead = function(memberIdx, memberName){
    SHV.modal.open('views/saas/fms/link_head.php?member_idx='+memberIdx, '본사 연결 — '+memberName, 'md');
    SHV.modal.onClose(function(){ mbReload(); });
};

/* ── Bulk Action ── */
window.mbBulkAction = function(action, rows){
    if(!rows.length){ if(SHV.toast) SHV.toast.warn('선택된 항목이 없습니다.'); return; }

    if(action==='export'){
        var idxSet={};
        rows.forEach(function(r){ if(r.id) idxSet[r.id]=true; });
        var toExp=_allData.filter(function(d){ return idxSet[String(d.idx)]; });
        var header='사업장명,본사,그룹,대표자,사업자번호,담당자,현장수,상태,전화번호,주소,등록일\n';
        var csvBody=toExp.map(function(d){
            return [d.name,d.head_name||'',d.group_name||'',d.ceo,d.card_number,d.employee_name||'',d.site_count||0,d.member_status||d.status||'',d.tel||d.hp||'',d.address,fmtDate(d.registered_date||d.regdate||d.created_at)]
                .map(function(v){ return '"'+String(v||'').replace(/"/g,'""')+'"'; })
                .join(',');
        }).join('\n');
        var blob=new Blob(['\uFEFF'+header+csvBody],{type:'text/csv;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download='사업장목록_'+new Date().toISOString().slice(0,10)+'.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        if(SHV.toast) SHV.toast.success(toExp.length+'건 CSV 내보내기 완료');
    }

    if(action==='delete'){
        var idList=rows.map(function(r){ return r.id; }).filter(Boolean);
        if(!idList.length){ if(SHV.toast) SHV.toast.warn('삭제할 항목이 없습니다.'); return; }
        var names=_allData
            .filter(function(d){ return idList.indexOf(String(d.idx))>-1; })
            .map(function(d){ return d.name; }).join(', ');

        if(window.SHV && SHV.confirm){
            SHV.confirm({
                title: '사업장 삭제',
                message: '선택한 '+idList.length+'건을 삭제하시겠습니까?\n\n'+names,
                type: 'danger',
                confirmText: '삭제',
                onConfirm: function(){ doDelete(idList); }
            });
        } else {
            doDelete(idList);
        }
    }
};

function doDelete(idList){
    SHV.api.post('dist_process/saas/Member.php', { todo:'member_delete', idx_list: idList.join(',') })
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(idList.length+'건 삭제 완료');
                mbReload();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'삭제 실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 무한스크롤 ── */
function observeSentinel(){
    var s=$('mbSentinel'); if(!s||!_hasMore||_observer) return;
    _observer=new IntersectionObserver(function(entries){
        if(entries[0].isIntersecting&&_hasMore&&!_loading){ _page++; loadData(_page,true); }
    },{root:$('mbTableWrap'),rootMargin:'100px',threshold:0});
    _observer.observe(s);
}
function disconnectObserver(){ if(_observer){_observer.disconnect();_observer=null;} }

/* ── 초기 로드 ── */
showSkeleton();
loadData(1,false);
})();
</script>
</section>
