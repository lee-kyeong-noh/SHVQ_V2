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

/* 내담당현장 라우트 분기: ?employee=me */
$isMySite = ($_GET['employee'] ?? '') === 'me';
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<section data-page="site_list" data-title="<?= $isMySite ? '내담당현장' : '현장조회' ?>">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1"><?= $isMySite ? '내담당현장' : '현장조회' ?></h2>
        <p class="text-xs text-3 mt-1 m-0"><?= $isMySite ? '내가 담당하는 현장 목록' : '현장 목록 조회 및 관리' ?></p>
    </div>
    <div class="flex items-center gap-2">
        <span id="stTotal" class="text-sm text-3 font-medium">-</span>
        <button class="btn btn-glass-primary btn-sm" onclick="stOpenAdd()">등록</button>
    </div>
</div>

<!-- ── 검색 카드 ── -->
<div class="card flex-shrink-0 py-3 px-4">
    <div class="flex items-center gap-2 flex-wrap">
        <div class="shv-search flex-1 max-w-400">
            <i class="fa fa-search shv-search-icon"></i>
            <input type="text" id="stSearch"
                placeholder="현장명, 사업장명, 현장번호, 주소..."
                onkeydown="if(event.key==='Enter')stReload()"
                oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value)">
            <span class="shv-search-clear" onclick="document.getElementById('stSearch').value='';this.closest('.shv-search').classList.remove('has-value');stReload();">✕</span>
        </div>
        <!-- 상태 필터 -->
        <select id="stFilterStatus" class="form-select form-select-sm st-filter-dd" onchange="stReload()">
            <option value="">상태 전체</option>
            <option value="예정">예정</option>
            <option value="진행">진행</option>
            <option value="중지">중지</option>
            <option value="완료">완료</option>
            <option value="마감">마감</option>
        </select>
        <!-- 담당자 필터 -->
        <select id="stFilterEmp" class="form-select form-select-sm st-filter-dd" onchange="stReload()">
            <option value="">담당자 전체</option>
            <option value="me">내 담당</option>
        </select>
        <!-- 수량 필터 -->
        <select id="stFilterQty" class="form-select form-select-sm st-filter-dd" onchange="stReload()">
            <option value="">수량 전체</option>
            <option value="1-10">1~10개</option>
            <option value="11+">11개 이상</option>
        </select>
        <!-- 기간 필터 -->
        <select id="stFilterDateType" class="form-select form-select-sm st-filter-dd" onchange="stReload()">
            <option value="">기간 전체</option>
            <option value="construction_date">착공일</option>
            <option value="completion_date">준공일</option>
            <option value="registered_date">등록일</option>
        </select>
        <input type="date" id="stFilterDateS" class="form-input form-input-sm st-filter-date hidden" onchange="stReload()">
        <input type="date" id="stFilterDateE" class="form-input form-input-sm st-filter-date hidden" onchange="stReload()">
        <button class="btn btn-outline btn-sm" id="btnStDetail" onclick="stToggleDetail()">
            <i class="fa fa-sliders"></i> 컬럼 필터
        </button>
        <button class="btn btn-ghost btn-sm" onclick="stReload()" title="새로고침">
            <i class="fa fa-refresh"></i>
        </button>
    </div>
</div>

<!-- ── PC 테이블 ── -->
<div class="card st-pc-only card-fill">
    <div id="stTableWrap" class="tbl-scroll">
        <table id="stTable" class="tbl tbl-sticky-header">
            <colgroup>
                <col><col><col><col><col><col><col><col><col><col><col><col><col><col>
            </colgroup>
            <thead>
                <tr>
                    <th class="th-center">No</th>
                    <th>유형</th>
                    <th>현장명</th>
                    <th>사업장</th>
                    <th>현장번호</th>
                    <th>담당자</th>
                    <th>담당부서</th>
                    <th class="text-right">총수량</th>
                    <th>건설사</th>
                    <th>발주담당</th>
                    <th>착공일</th>
                    <th>준공일</th>
                    <th>상태</th>
                    <th>등록일</th>
                </tr>
            </thead>
            <tbody id="stBody"></tbody>
        </table>
        <div id="stSentinel" class="h-px"></div>
    </div>
    <div id="stLoading" class="loading-row">
        <span class="spinner spinner-sm align-middle mr-1"></span>
        <span class="text-sm text-3">불러오는 중...</span>
    </div>
    <div id="stPagingBar" class="paging-bar">
        <span id="stPageInfo" class="paging-info"></span>
        <button id="stLoadMoreBtn" class="btn btn-ghost btn-sm text-xs hidden" onclick="stLoadMore()">
            <i class="fa fa-chevron-down"></i> 더 불러오기
        </button>
    </div>
</div>

<!-- ── 모바일 카드 ── -->
<div id="stMobileWrap" class="st-mobile-only flex-1 overflow-y-auto">
    <div class="sticky top-0 bg-content pb-2 z-10">
        <div class="flex gap-2">
            <div class="shv-search flex-1">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="stMobileSearch" placeholder="현장명, 사업장명, 주소..."
                    oninput="this.closest('.shv-search').classList.toggle('has-value',!!this.value);stMobileFilter(this.value)">
                <span class="shv-search-clear" onclick="document.getElementById('stMobileSearch').value='';this.closest('.shv-search').classList.remove('has-value');stMobileFilter('');">✕</span>
            </div>
            <button class="btn btn-outline btn-sm" onclick="stOpenFilterSheet()">
                <i class="fa fa-sliders"></i>
            </button>
        </div>
        <div class="mt-2 text-xs text-3">
            총 <b id="stMobileCnt" class="text-accent">0</b>건
        </div>
    </div>
    <div id="stMobileList"></div>
</div>

<!-- ── 모바일 상세 바텀시트 ── -->
<div id="stDetailSheet" class="sheet-backdrop" onclick="stCloseSheet('stDetailSheet')">
    <div onclick="event.stopPropagation()" class="sheet st-sheet-animated">
        <div class="sheet-grip"><div class="sheet-handle"></div></div>
        <div id="stSheetHead" class="sheet-head"></div>
        <div id="stSheetBody" class="sheet-body"></div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="stSheetGo()">
                <i class="fa fa-external-link"></i> 상세보기
            </button>
        </div>
    </div>
</div>

<!-- ── 모바일 필터 바텀시트 ── -->
<div id="stFilterSheet" class="sheet-backdrop" onclick="stCloseSheet('stFilterSheet')">
    <div onclick="event.stopPropagation()" class="sheet">
        <div class="sheet-grip py-3 px-4 pb-2 flex-shrink-0">
            <div class="sheet-handle"></div>
            <div class="flex items-center justify-between">
                <span class="text-md font-bold text-1">상세 필터</span>
                <button class="btn btn-ghost btn-sm" onclick="stMobileResetFilter()">초기화</button>
            </div>
        </div>
        <div class="sheet-filter-body">
            <div class="flex flex-col gap-3">
                <div class="form-group">
                    <label class="form-label">상태</label>
                    <div class="flex gap-2 flex-wrap">
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-val="예정" onclick="this.classList.toggle('mst-chip-on')">예정</button>
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-val="진행" onclick="this.classList.toggle('mst-chip-on')">진행</button>
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-val="중지" onclick="this.classList.toggle('mst-chip-on')">중지</button>
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-val="완료" onclick="this.classList.toggle('mst-chip-on')">완료</button>
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-val="마감" onclick="this.classList.toggle('mst-chip-on')">마감</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">담당자</label>
                    <div class="flex gap-2">
                        <button class="btn btn-ghost btn-sm mst-chip flex-1 border" data-key="employee" data-val="me" onclick="this.classList.toggle('mst-chip-on')">내 담당</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="sheet-foot">
            <button class="btn btn-primary w-full" onclick="stMobileApplyFilter()">
                <i class="fa fa-search"></i> 검색
            </button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';

var _page=1, _limit=100, _loading=false, _hasMore=true, _allData=[], _detailParams={}, _sheetIdx=null, _observer=null, _total=0;
var _isMySite = <?= $isMySite ? 'true' : 'false' ?>;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function $(id){ return document.getElementById(id); }
function fmtDate(s){ return s?String(s).substring(0,10):''; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)||n===0?'':n.toLocaleString(); }

function statusBadge(s){
    if(!s) return '';
    return '<span class="badge-status badge-status-'+escH(s)+'">'+escH(s)+'</span>';
}

/* ── 내담당현장 초기값 ── */
if(_isMySite){
    var empDD=$('stFilterEmp');
    if(empDD) empDD.value='me';
}

/* ── 기간 필터 날짜 토글 ── */
var dtType=$('stFilterDateType');
if(dtType){
    dtType.addEventListener('change',function(){
        var show=!!this.value;
        var ds=$('stFilterDateS'), de=$('stFilterDateE');
        if(ds) ds.classList.toggle('hidden',!show);
        if(de) de.classList.toggle('hidden',!show);
    });
}

/* ── 스켈레톤 ── */
function showSkeleton(){
    var body=$('stBody'); if(!body) return;
    var colCount=14;
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
window.stToggleDetail = function(){
    var btn=$('btnStDetail');
    var filterRow=document.querySelector('#stTable .tbl-filter-row');
    var open=filterRow&&filterRow.classList.contains('filter-visible');
    if(filterRow) filterRow.classList.toggle('filter-visible',!open);
    if(btn){ btn.classList.toggle('btn-primary',!open); btn.classList.toggle('btn-outline',open); }
    if(open){ var tbl=$('stTable'); if(tbl&&tbl._shvFilterReset) tbl._shvFilterReset(); }
};

/* ── 파라미터 빌드 ── */
function buildParams(page){
    var p={todo:'list',p:page,limit:_limit};
    var s=$('stSearch'); if(s&&s.value.trim()) p.search=s.value.trim();

    /* 상태 필터 */
    var fs=$('stFilterStatus'); if(fs&&fs.value) p.site_status=fs.value;

    /* 담당자 필터 */
    var fe=$('stFilterEmp'); if(fe&&fe.value) p.employee=fe.value;

    /* 수량 필터 */
    var fq=$('stFilterQty'); if(fq&&fq.value) p.qty_range=fq.value;

    /* 기간 필터 */
    var fdt=$('stFilterDateType');
    if(fdt&&fdt.value){
        p.date_type=fdt.value;
        var ds=$('stFilterDateS'), de=$('stFilterDateE');
        if(ds&&ds.value) p.date_s=ds.value;
        if(de&&de.value) p.date_e=de.value;
    }

    Object.assign(p,_detailParams);
    return p;
}

/* ── 데이터 로드 ── */
function loadData(page, append){
    if(_loading) return;
    _loading=true;
    var ld=$('stLoading'); if(ld) ld.style.display='block';

    SHV.api.get('dist_process/saas/Site.php', buildParams(page))
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

            var totalEl=$('stTotal'); if(totalEl) totalEl.textContent=total.toLocaleString()+'건';
            var mCnt=$('stMobileCnt'); if(mCnt) mCnt.textContent=total.toLocaleString();

            var pgInfo=$('stPageInfo');
            if(pgInfo) pgInfo.textContent=_allData.length.toLocaleString()+'건 / 총 '+total.toLocaleString()+'건';
            var moreBtn=$('stLoadMoreBtn');
            if(moreBtn) moreBtn.style.display=_hasMore?'':'none';

            if(window.innerWidth<=768){ renderCards(rows,append); }
            else { renderRows(rows,append,append?(_page-1)*_limit:0); }

            if(_hasMore) observeSentinel();
        })
        .catch(function(){ _loading=false; if(ld) ld.style.display='none'; if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 전체 재로드 ── */
window.stReload = function(){
    _page=1; _hasMore=true; _allData=[];
    var b=$('stBody');
    if(b){
        var tbl=b.closest('table');
        if(tbl && tbl._shvFilterReset) tbl._shvFilterReset();
        b.innerHTML='';
        showSkeleton();
    }
    var ml=$('stMobileList'); if(ml) ml.innerHTML='';
    disconnectObserver();
    loadData(1,false);
};

/* ── 더 불러오기 ── */
window.stLoadMore = function(){
    if(_hasMore && !_loading){ _page++; loadData(_page,true); }
};

/* ── PC 테이블 렌더링 ── */
function renderRows(rows, append, startIdx){
    var body=$('stBody'); if(!body) return;
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
                onAction: function(action, rows){ stBulkAction(action, rows); }
            });
        }
        if(tbl && window.shvTblFilter && !tbl._shvFilterInit) {
            shvTblFilter(tbl, { skip: [1] });
        }
    }
    if(!rows.length && !append){
        var tbl2=body.closest('table');
        var colCount=tbl2&&tbl2.querySelector('thead tr')?tbl2.querySelector('thead tr').querySelectorAll('th').length:14;
        body.innerHTML='<tr><td colspan="'+colCount+'" class="text-center p-12">'
            +'<div class="text-4xl opacity-50"><i class="fa fa-map-marker"></i></div>'
            +'<p class="text-3 mt-2">등록된 현장이 없습니다</p></td></tr>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var no=startIdx+i+1;
        var name = r.site_display_name || r.name || r.site_name || '';
        var status = r.site_status || r.status || '';
        var cDate = r.construction_date || r.start_date || '';
        var eDate = r.completion_date || r.end_date || '';
        var date = r.registered_date || r.regdate || r.created_at || '';

        html+='<tr class="cursor-pointer" onclick="stGo('+r.idx+')"'
            +' data-idx="'+r.idx+'">'
            +'<td class="td-no">'+no+'</td>'
            +'<td class="text-3">'+escH(r.group_name||'')+'</td>'
            +'<td><b class="text-1">'+escH(name)+'</b></td>'
            +'<td class="text-3">'+escH(r.member_name||'')+'</td>'
            +'<td class="text-3">'+escH(r.site_number||'')+'</td>'
            +'<td>'+escH(r.employee_name||r.manager_name||'')+'</td>'
            +'<td class="text-3">'+escH(r.target_team||'')+'</td>'
            +'<td class="text-right">'+fmtNum(r.external_employee||r.total_qty)+'</td>'
            +'<td class="text-3">'+escH(r.construction||'')+'</td>'
            +'<td class="text-3">'+escH(r.phonebook_name||'')+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(cDate)+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(eDate)+'</td>'
            +'<td>'+statusBadge(status)+'</td>'
            +'<td class="text-3 whitespace-nowrap">'+fmtDate(date)+'</td>'
            +'</tr>';
    });
    body.insertAdjacentHTML('beforeend',html);
}

/* ── 모바일 카드 렌더링 ── */
function renderCards(rows, append){
    var list=$('stMobileList'); if(!list) return;
    if(!append) list.innerHTML='';
    if(!rows.length && !append){
        list.innerHTML='<div class="text-center p-8"><div class="text-4xl opacity-30"><i class="fa fa-map-marker"></i></div><p class="text-3 mt-2">등록된 현장이 없습니다</p></div>';
        return;
    }
    var html='';
    rows.forEach(function(r,i){
        var di=_allData.length-rows.length+i;
        var name = r.site_display_name || r.name || r.site_name || '';
        var status = r.site_status || r.status || '';
        var date = r.registered_date || r.regdate || r.created_at || '';
        var emp = r.employee_name || r.manager_name || '';
        var srch=((name)+' '+(r.member_name||'')+' '+(r.address||'')+' '+(r.site_number||'')+' '+emp).toLowerCase();

        html+='<div class="mb-mc" data-search="'+escH(srch)+'" onclick="stShowSheet('+di+')">'
            +'<div class="flex justify-between items-start gap-2">'
            +'<div class="flex-1 min-w-0">'
            +'<div class="font-semibold text-1 text-md">'+escH(name)+'</div>'
            +(r.member_name?'<span class="text-xs text-3 mt-px"><i class="fa fa-building-o mr-1"></i>'+escH(r.member_name)+'</span>':'')
            +'</div>'
            +'<div class="flex items-center gap-2">'
            +statusBadge(status)
            +'</div>'
            +'</div>'
            +'<div class="flex gap-3 mt-1 text-xs text-3">'
            +(emp?'<span><i class="fa fa-user mr-1"></i>'+escH(emp)+'</span>':'')
            +(r.site_number?'<span>'+escH(r.site_number)+'</span>':'')
            +'<span class="whitespace-nowrap">'+fmtDate(date)+'</span>'
            +'</div>'
            +(r.address?'<div class="text-xs text-3 mt-1 truncate"><i class="fa fa-map-marker mr-1"></i>'+escH(r.address)+'</div>':'')
            +'</div>';
    });
    list.insertAdjacentHTML('beforeend',html);
}

/* ── 시트 열기/닫기 ── */
window.stOpenFilterSheet = function(){ var el=$('stFilterSheet'); if(el) el.style.display='block'; };
window.stCloseSheet = function(id){ var el=$(id); if(el) el.style.display='none'; };

/* ── 모바일 카드 필터 ── */
window.stMobileFilter = function(q){
    var list=$('stMobileList'); if(!list) return;
    q=(q||'').toLowerCase().trim();
    var cnt=0;
    list.querySelectorAll('.mb-mc').forEach(function(c){
        var m=!q||(c.dataset.search||'').indexOf(q)>-1;
        c.style.display=m?'':'none'; if(m)cnt++;
    });
    var el=$('stMobileCnt'); if(el) el.textContent=cnt.toLocaleString();
};

/* ── 모바일 바텀시트 ── */
window.stShowSheet = function(di){
    var d=_allData[di]; if(!d) return;
    _sheetIdx=d.idx;
    var name = d.site_display_name || d.name || d.site_name || '';
    var status = d.site_status || d.status || '';
    var date = d.registered_date || d.regdate || d.created_at || '';
    var emp = d.employee_name || d.manager_name || '';

    $('stSheetHead').innerHTML='<div class="text-lg font-bold text-1">'+escH(name)+'</div>'
        +'<div class="mt-1 flex gap-2 flex-wrap">'+statusBadge(status)
        +(d.member_name?'<span class="text-xs text-3"><i class="fa fa-building-o mr-1"></i>'+escH(d.member_name)+'</span>':'')
        +(emp?'<span class="text-xs text-3"><i class="fa fa-user mr-1"></i>'+escH(emp)+'</span>':'')
        +'</div>';
    var fields=[
        ['현장번호',d.site_number],
        ['담당자',emp],
        ['담당부서',d.target_team],
        ['총수량',fmtNum(d.external_employee||d.total_qty)],
        ['건설사',d.construction],
        ['착공일',fmtDate(d.construction_date||d.start_date)],
        ['준공일',fmtDate(d.completion_date||d.end_date)],
        ['등록일',fmtDate(date)]
    ];
    var html='';
    fields.forEach(function(f){ if(f[1]) html+='<div class="mb-sr"><span class="mb-sl">'+escH(f[0])+'</span><span class="mb-sv">'+escH(f[1])+'</span></div>'; });
    if(d.address){
        html+='<div class="mb-sr"><span class="mb-sl">주소</span><div class="flex-1">'
            +'<div class="mb-sv mb-2">'+escH(d.address)+'</div>'
            +'<button class="btn btn-outline btn-sm w-full" data-copy="'+escH(d.address||'')+'" onclick="event.stopPropagation();var a=this.getAttribute(\'data-copy\');navigator.clipboard&&navigator.clipboard.writeText(a).then(function(){if(SHV.toast)SHV.toast.success(\'주소 복사됨\')})"><i class="fa fa-copy"></i> 복사</button>'
            +'</div></div>';
    }
    $('stSheetBody').innerHTML=html;
    var el=$('stDetailSheet'); if(el) el.style.display='block';
};
window.stSheetGo = function(){
    stCloseSheet('stDetailSheet');
    if(_sheetIdx) stGo(_sheetIdx);
};

/* ── 모바일 필터 ── */
window.stMobileApplyFilter = function(){
    _detailParams={};
    var statuses=[];
    document.querySelectorAll('#stFilterSheet .mst-chip.mst-chip-on').forEach(function(ch){
        if(ch.dataset.key==='employee') _detailParams.employee=ch.dataset.val;
        else statuses.push(ch.dataset.val);
    });
    if(statuses.length===1) _detailParams.site_status=statuses[0];
    stCloseSheet('stFilterSheet');
    stReload();
};
window.stMobileResetFilter = function(){
    document.querySelectorAll('#stFilterSheet .mst-chip').forEach(function(c){ c.classList.remove('mst-chip-on'); });
};

/* ── 상세 이동 ── */
window.stGo = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('site_view',{site_idx:idx});
};

/* ── 사업장 이동 ── */
window.stGoMember = function(idx){
    if(SHV&&SHV.router) SHV.router.navigate('member_branch_view',{member_idx:idx});
};

/* ── 등록 ── */
window.stOpenAdd = function(){
    SHV.modal.open('views/saas/fms/site_add.php', '현장 등록', 'lg');
    SHV.modal.onClose(function(){ stReload(); });
};

/* ── Bulk Action ── */
window.stBulkAction = function(action, rows){
    if(!rows.length){ if(SHV.toast) SHV.toast.warn('선택된 항목이 없습니다.'); return; }

    if(action==='export'){
        var idxSet={};
        rows.forEach(function(r){ if(r.id) idxSet[r.id]=true; });
        var toExp=_allData.filter(function(d){ return idxSet[String(d.idx)]; });
        var header='유형,현장명,사업장,현장번호,담당자,담당부서,총수량,건설사,발주담당,착공일,준공일,상태,주소,등록일\n';
        var csvBody=toExp.map(function(d){
            var name=d.site_display_name||d.name||d.site_name||'';
            var cDate=d.construction_date||d.start_date||'';
            var eDate=d.completion_date||d.end_date||'';
            return [d.group_name||'',name,d.member_name||'',d.site_number||'',d.employee_name||d.manager_name||'',
                d.target_team||'',d.external_employee||d.total_qty||'',d.construction||'',d.phonebook_name||'',
                fmtDate(cDate),fmtDate(eDate),d.site_status||d.status||'',
                d.address||'',fmtDate(d.registered_date||d.regdate||d.created_at)]
                .map(function(v){ return '"'+String(v||'').replace(/"/g,'""')+'"'; })
                .join(',');
        }).join('\n');
        var blob=new Blob(['\uFEFF'+header+csvBody],{type:'text/csv;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download='현장목록_'+new Date().toISOString().slice(0,10)+'.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        if(SHV.toast) SHV.toast.success(toExp.length+'건 CSV 내보내기 완료');
    }

    if(action==='delete'){
        var idList=rows.map(function(r){ return r.id; }).filter(Boolean);
        if(!idList.length){ if(SHV.toast) SHV.toast.warn('삭제할 항목이 없습니다.'); return; }

        if(window.SHV && SHV.confirm){
            SHV.confirm({
                title: '현장 삭제',
                message: '선택한 '+idList.length+'건을 삭제하시겠습니까?',
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
    SHV.api.post('dist_process/saas/Site.php', { todo:'delete', idx_list: idList.join(',') })
        .then(function(res){
            if(res.ok){
                if(SHV.toast) SHV.toast.success(idList.length+'건 삭제 완료');
                stReload();
            } else {
                if(SHV.toast) SHV.toast.error(res.message||'삭제 실패');
            }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

/* ── 무한스크롤 ── */
function observeSentinel(){
    var s=$('stSentinel'); if(!s||!_hasMore||_observer) return;
    _observer=new IntersectionObserver(function(entries){
        if(entries[0].isIntersecting&&_hasMore&&!_loading){ _page++; loadData(_page,true); }
    },{root:$('stTableWrap'),rootMargin:'100px',threshold:0});
    _observer.observe(s);
}
function disconnectObserver(){ if(_observer){_observer.disconnect();_observer=null;} }

/* ── 초기 로드 ── */
showSkeleton();
loadData(1,false);
})();
</script>
</section>
