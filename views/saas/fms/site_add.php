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

$isModify = ($_GET['todo'] ?? '') === 'modify';
$modifyIdx = (int)($_GET['idx'] ?? 0);
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<input type="hidden" id="sta_lat">
<input type="hidden" id="sta_lng">

<div id="staFormBody">

    <!-- ── 3그리드: 현장명 · 현장번호 · 상태 ── -->
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label" for="sta_name">현장명 <span class="required">*</span></label>
            <input type="text" id="sta_name" class="form-input"
                placeholder="현장명을 입력하세요" maxlength="150" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_site_number">현장번호</label>
            <div class="flex gap-2 items-center">
                <input type="text" id="sta_site_number" class="form-input flex-1"
                    placeholder="현장번호" maxlength="30" autocomplete="off">
                <button type="button" class="btn btn-outline btn-sm whitespace-nowrap" onclick="staCheckDup()">중복확인</button>
            </div>
            <span id="staDupMsg" class="text-xs mt-1 hidden"></span>
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_status">상태</label>
            <select id="sta_status" class="form-select">
                <option value="예정">예정</option>
                <option value="진행">진행</option>
                <option value="중지">중지</option>
                <option value="완료">완료</option>
            </select>
        </div>
    </div>

    <!-- ── 2그리드: 사업장 · 건설사 ── -->
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="sta_member">사업장 연결</label>
            <select id="sta_member" class="form-select" onchange="staMemberChange()">
                <option value="">선택 (미연결)</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_construction">건설사</label>
            <input type="text" id="sta_construction" class="form-input"
                placeholder="건설사명" maxlength="100" autocomplete="off">
        </div>
    </div>

    <!-- ── 3그리드: 담당자(DD) · 담당부서 · 전화 ── -->
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label">담당자</label>
            <div class="sta-emp-dd-wrap">
                <input type="text" id="sta_emp_search" class="form-input"
                    placeholder="담당자 검색" autocomplete="off"
                    oninput="staEmpSearch(this.value)" onfocus="staEmpSearch(this.value)">
                <input type="hidden" id="sta_employee_idx">
                <div id="staEmpDD" class="sta-emp-dd hidden"></div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_team">담당부서</label>
            <input type="text" id="sta_team" class="form-input"
                placeholder="자동 입력" maxlength="50" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_tel">전화번호</label>
            <input type="text" id="sta_tel" class="form-input"
                placeholder="02-0000-0000" maxlength="14" autocomplete="off"
                oninput="this.value=fmtPhone(this.value)">
        </div>
    </div>

    <!-- ── 2그리드: 부담당 · 권역 ── -->
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label">부담당</label>
            <div class="sta-emp-dd-wrap">
                <input type="text" id="sta_emp1_search" class="form-input"
                    placeholder="부담당 검색" autocomplete="off"
                    oninput="staEmp1Search(this.value)" onfocus="staEmp1Search(this.value)">
                <input type="hidden" id="sta_employee1_idx">
                <div id="staEmp1DD" class="sta-emp-dd hidden"></div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="sta_region">담당권역</label>
            <input type="text" id="sta_region" class="form-input"
                placeholder="담당권역" maxlength="50" autocomplete="off">
        </div>
    </div>

    <!-- ── 2그리드: 총수량 · 보증기간 ── -->
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label" for="sta_total_qty">총수량</label>
            <input type="number" id="sta_total_qty" class="form-input"
                placeholder="0" min="0" max="999999">
        </div>

        <div class="form-group">
            <label class="form-label" for="sta_warranty">보증기간 (개월)</label>
            <input type="number" id="sta_warranty" class="form-input"
                placeholder="0" min="0" max="999">
        </div>
    </div>

    <!-- ── 발주담당 ── -->
    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label">발주담당 (PM)</label>
            <div class="sta-emp-dd-wrap">
                <input type="text" id="sta_pb_search" class="form-input"
                    placeholder="연락처 검색" autocomplete="off"
                    oninput="staPbSearch(this.value)" onfocus="staPbSearch(this.value)">
                <input type="hidden" id="sta_phonebook_idx">
                <div id="staPbDD" class="sta-emp-dd hidden"></div>
            </div>
        </div>
        <div class="form-group"></div>
    </div>

    <!-- ── 기간 ── -->
    <div class="form-section">
        <div class="form-section-label">공사기간</div>
        <div class="hda-grid-2">
            <div class="form-group">
                <label class="form-label" for="sta_start">착공일</label>
                <input type="date" id="sta_start" class="form-input">
            </div>

            <div class="form-group">
                <label class="form-label" for="sta_end">준공일</label>
                <input type="date" id="sta_end" class="form-input">
            </div>
        </div>
    </div>

    <!-- ── 주소 ── -->
    <div class="form-section">
        <div class="form-section-label">주소</div>
        <div class="hda-grid-2">
            <div class="form-group">
                <label class="form-label">우편번호</label>
                <div class="flex gap-2 items-center">
                    <input type="text" id="sta_zipcode" class="form-input hda-zipcode" readonly placeholder="우편번호">
                    <button type="button" class="btn btn-outline btn-sm" onclick="staAddrOpen()">
                        <i class="fa fa-search"></i> 주소 검색
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="sta_addr_detail">상세주소</label>
                <input type="text" id="sta_addr_detail" class="form-input"
                    placeholder="동, 호수, 층 등" maxlength="100" autocomplete="off">
            </div>

            <div class="form-group col-full">
                <label class="form-label">주소</label>
                <input type="text" id="sta_addr" class="form-input cursor-pointer" readonly
                    placeholder="위 주소 검색 버튼을 클릭하세요" onclick="staAddrOpen()">
            </div>
        </div>
    </div>

    <!-- ── 메모 ── -->
    <div class="form-section">
        <div class="form-section-label">메모</div>
        <div class="form-group">
            <textarea id="sta_memo" class="form-input" rows="3"
                placeholder="메모 입력 (선택)" maxlength="2000"></textarea>
        </div>
    </div>

    <!-- ── 주소 검색 레이어 ── -->
    <div id="staAddrOverlay"
         class="fixed inset-0 z-9999 flex items-center justify-center p-4 pointer-events-none"
         style="display:none;">
        <div class="modal-box modal-sm pointer-events-auto max-h-none" id="staAddrBox">
            <div id="staAddrHeader" class="modal-header">
                <span><i class="fa fa-map-marker text-accent mr-2"></i>주소 검색</span>
                <button type="button" class="modal-close" onclick="staAddrClose()">×</button>
            </div>
            <div id="staAddrEmbed" class="w-full mba-addr-embed"></div>
        </div>
    </div>

</div>

<!-- ── 모달 폼 푸터 ── -->
<div class="modal-form-footer">
    <span id="staErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="staSubmitBtn" onclick="staSubmit()">
        <i class="fa fa-check"></i> <?= $isModify ? '수정' : '등록' ?>
    </button>
</div>

<script>
(function(){
'use strict';

var _isModify = <?= $isModify ? 'true' : 'false' ?>;
var _modifyIdx = <?= $modifyIdx ?>;
var _empList = [];
var _dupChecked = false;

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function showErr(msg){
    var el=$('staErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; }
    else    { el.style.display='none'; }
}
function setLoading(on){
    var btn=$('staSubmitBtn'); if(!btn) return;
    btn.disabled=on;
    btn.innerHTML=on
        ? '<span class="spinner spinner-sm mr-1"></span>저장 중...'
        : '<i class="fa fa-check"></i> '+(_isModify?'수정':'등록');
}

/* ══════════════════════════
   포맷터
   ══════════════════════════ */
window.fmtPhone = window.fmtPhone || function(v){
    var d=v.replace(/\D/g,'').slice(0,11);
    if(d.startsWith('02')){
        if(d.length<=2) return d;
        if(d.length<=6) return d.slice(0,2)+'-'+d.slice(2);
        if(d.length<=9) return d.slice(0,2)+'-'+d.slice(2,5)+'-'+d.slice(5);
        return d.slice(0,2)+'-'+d.slice(2,6)+'-'+d.slice(6,10);
    } else {
        if(d.length<=3) return d;
        if(d.length<=6) return d.slice(0,3)+'-'+d.slice(3);
        if(d.length<=10) return d.slice(0,3)+'-'+d.slice(3,6)+'-'+d.slice(6);
        return d.slice(0,3)+'-'+d.slice(3,7)+'-'+d.slice(7,11);
    }
};

/* ══════════════════════════
   사업장 드롭다운 로드
   ══════════════════════════ */
function loadMemberOptions(){
    return SHV.api.get('dist_process/saas/Member.php',{todo:'list',limit:500})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('sta_member'); if(!sel) return;
            (res.data.data||[]).forEach(function(m){
                var opt=document.createElement('option');
                opt.value=m.idx;
                opt.textContent=m.name+(m.ceo?' ('+m.ceo+')':'');
                sel.appendChild(opt);
            });
        });
}

/* ══════════════════════════
   담당자 검색 드롭다운
   ══════════════════════════ */
function loadEmployees(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list',limit:200})
        .then(function(res){
            if(!res.ok) return;
            _empList=(res.data||[]).map(function(e){
                return { idx:e.idx, name:e.name, team:e.team||e.department||'' };
            });
        });
}

window.staEmpSearch = function(q){
    var dd=$('staEmpDD'); if(!dd) return;
    q=(q||'').toLowerCase().trim();
    var filtered=q?_empList.filter(function(e){
        return (e.name||'').toLowerCase().indexOf(q)>-1 || (e.team||'').toLowerCase().indexOf(q)>-1;
    }):_empList.slice(0,20);

    if(!filtered.length){ dd.classList.add('hidden'); return; }
    dd.classList.remove('hidden');
    dd.innerHTML=filtered.map(function(e){
        return '<div class="sta-emp-dd-item" data-idx="'+e.idx+'" data-name="'+escH(e.name)+'" data-team="'+escH(e.team)+'">'
            +'<b>'+escH(e.name)+'</b>'+(e.team?' <span class="text-3 text-xs">'+escH(e.team)+'</span>':'')
            +'</div>';
    }).join('');
    /* 이벤트 위임 */
    dd.onclick=function(ev){
        var item=ev.target.closest('.sta-emp-dd-item'); if(!item) return;
        staEmpSelect(parseInt(item.dataset.idx,10), item.dataset.name, item.dataset.team);
    };
};

window.staEmpSelect = function(idx, name, team){
    var si=$('sta_emp_search'), hi=$('sta_employee_idx'), ti=$('sta_team'), dd=$('staEmpDD');
    if(si) si.value=name;
    if(hi) hi.value=idx;
    if(ti && team) ti.value=team;
    if(dd) dd.classList.add('hidden');
};

/* ── 부담당 검색 (담당자와 동일 패턴) ── */
window.staEmp1Search = function(q){
    var dd=$('staEmp1DD'); if(!dd) return;
    q=(q||'').toLowerCase().trim();
    var filtered=q?_empList.filter(function(e){
        return (e.name||'').toLowerCase().indexOf(q)>-1 || (e.team||'').toLowerCase().indexOf(q)>-1;
    }):_empList.slice(0,20);
    if(!filtered.length){ dd.classList.add('hidden'); return; }
    dd.classList.remove('hidden');
    dd.innerHTML=filtered.map(function(e){
        return '<div class="sta-emp-dd-item" data-idx="'+e.idx+'" data-name="'+escH(e.name)+'">'
            +'<b>'+escH(e.name)+'</b>'+(e.team?' <span class="text-3 text-xs">'+escH(e.team)+'</span>':'')
            +'</div>';
    }).join('');
    dd.onclick=function(ev){
        var item=ev.target.closest('.sta-emp-dd-item'); if(!item) return;
        var si=$('sta_emp1_search'), hi=$('sta_employee1_idx');
        if(si) si.value=item.dataset.name;
        if(hi) hi.value=item.dataset.idx;
        dd.classList.add('hidden');
    };
};

/* 드롭다운 외부 클릭 닫기 */
document.addEventListener('click',function(e){
    ['staEmpDD','staEmp1DD','staPbDD'].forEach(function(id){
        var dd=$(id);
        if(dd && !dd.classList.contains('hidden')){
            var wrap=dd.closest('.sta-emp-dd-wrap');
            if(wrap && !wrap.contains(e.target)) dd.classList.add('hidden');
        }
    });
});

/* ══════════════════════════
   발주담당 (PhoneBook) 검색
   ══════════════════════════ */
var _pbList=[];
function loadPhoneBooks(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'contact_list',site_idx:0,limit:500})
        .then(function(res){
            if(!res.ok) return;
            _pbList=(res.data.data||[]).map(function(p){
                return { idx:p.idx, name:p.name||'', company:p.company||'', phone:p.phone||p.tel||'' };
            });
        }).catch(function(){});
}

window.staPbSearch = function(q){
    var dd=$('staPbDD'); if(!dd) return;
    q=(q||'').toLowerCase().trim();
    var filtered=q?_pbList.filter(function(p){
        return (p.name||'').toLowerCase().indexOf(q)>-1 || (p.company||'').toLowerCase().indexOf(q)>-1;
    }):_pbList.slice(0,20);
    if(!filtered.length){ dd.classList.add('hidden'); return; }
    dd.classList.remove('hidden');
    dd.innerHTML=filtered.map(function(p){
        return '<div class="sta-emp-dd-item" data-idx="'+p.idx+'" data-name="'+escH(p.name)+'">'
            +'<b>'+escH(p.name)+'</b>'+(p.company?' <span class="text-3 text-xs">'+escH(p.company)+'</span>':'')
            +'</div>';
    }).join('');
    dd.onclick=function(ev){
        var item=ev.target.closest('.sta-emp-dd-item'); if(!item) return;
        var si=$('sta_pb_search'), hi=$('sta_phonebook_idx');
        if(si) si.value=item.dataset.name;
        if(hi) hi.value=item.dataset.idx;
        dd.classList.add('hidden');
    };
};

/* ══════════════════════════
   현장번호 중복확인
   ══════════════════════════ */
window.staCheckDup = function(){
    var sn=(($('sta_site_number')||{}).value||'').trim();
    if(!sn){ if(SHV.toast) SHV.toast.warn('현장번호를 입력하세요.'); return; }
    SHV.api.get('dist_process/saas/Site.php',{todo:'search',search:sn,limit:5})
        .then(function(res){
            var msg=$('staDupMsg'); if(!msg) return;
            msg.classList.remove('hidden');
            if(!res.ok){ msg.textContent='확인 실패'; msg.className='text-xs mt-1 text-danger'; return; }
            var found=(res.data.data||[]).some(function(r){
                return (r.site_number||'')==sn && (!_isModify || r.idx!=_modifyIdx);
            });
            if(found){
                msg.textContent='이미 사용 중인 현장번호입니다.';
                msg.className='text-xs mt-1 text-danger';
                _dupChecked=false;
            } else {
                msg.textContent='사용 가능한 현장번호입니다.';
                msg.className='text-xs mt-1 text-success';
                _dupChecked=true;
            }
        });
};

/* ══════════════════════════
   주소 검색
   ══════════════════════════ */
var _addrDragX=0, _addrDragY=0, _addrDragging=false, _addrDragSX, _addrDragSY;

window.staAddrOpen = function(){
    if(typeof daum==='undefined' || !daum.Postcode){
        if(SHV&&SHV.toast) SHV.toast.warn('주소 검색 서비스를 불러오는 중입니다.');
        return;
    }
    var overlay=$('staAddrOverlay');
    var embedEl=$('staAddrEmbed');
    var box=$('staAddrBox');
    if(!overlay||!embedEl||!box) return;

    _addrDragX=0; _addrDragY=0;
    box.style.transform='';
    embedEl.innerHTML='';
    overlay.style.display='flex';

    new daum.Postcode({
        oncomplete: function(data){
            var zip  = data.zonecode   || '';
            var addr = data.roadAddress || data.jibunAddress || '';
            var zipEl=$('sta_zipcode'), addrEl=$('sta_addr'), detailEl=$('sta_addr_detail');
            if(zipEl)    zipEl.value  = zip;
            if(addrEl)   addrEl.value = addr;
            if(detailEl){ detailEl.value=''; }
            /* 지도좌표 자동 저장 (카카오 geocoder) */
            if(addr && window.kakao && kakao.maps && kakao.maps.services){
                var gc=new kakao.maps.services.Geocoder();
                gc.addressSearch(addr,function(result,status){
                    if(status===kakao.maps.services.Status.OK && result[0]){
                        var latEl=$('sta_lat'), lngEl=$('sta_lng');
                        if(latEl) latEl.value=result[0].y;
                        if(lngEl) lngEl.value=result[0].x;
                    }
                });
            }
            staAddrClose();
            setTimeout(function(){ if(detailEl) detailEl.focus(); }, 80);
        },
        width: '100%',
        height: '420px'
    }).embed(embedEl, { autoClose: true });

    var header=$('staAddrHeader');
    if(header && !header._dragBound){
        header._dragBound=true;
        header.addEventListener('mousedown',function(e){
            if(e.button!==0) return;
            _addrDragging=true;
            _addrDragSX=e.clientX-_addrDragX;
            _addrDragSY=e.clientY-_addrDragY;
            document.body.style.userSelect='none';
            e.preventDefault();
        });
        document.addEventListener('mousemove',function(e){
            if(!_addrDragging) return;
            _addrDragX=e.clientX-_addrDragSX;
            _addrDragY=e.clientY-_addrDragSY;
            box.style.transform='translate('+_addrDragX+'px,'+_addrDragY+'px)';
        });
        document.addEventListener('mouseup',function(){
            _addrDragging=false;
            document.body.style.userSelect='';
        });
    }
};

window.staAddrClose = function(){
    var overlay=$('staAddrOverlay');
    if(overlay) overlay.style.display='none';
    var embedEl=$('staAddrEmbed');
    if(embedEl) embedEl.innerHTML='';
};

/* ══════════════════════════
   수정 모드 — 기존 데이터 로드
   ══════════════════════════ */
function loadModifyData(){
    if(!_isModify||!_modifyIdx) return;
    SHV.api.get('dist_process/saas/Site.php',{todo:'detail',site_idx:_modifyIdx})
        .then(function(res){
            if(!res.ok) return;
            var d=res.data.site||res.data;
            if($('sta_name'))          $('sta_name').value=d.site_display_name||d.name||d.site_name||'';
            if($('sta_site_number'))   $('sta_site_number').value=d.site_number||'';
            if($('sta_status'))        $('sta_status').value=d.site_status||d.status||'예정';
            if($('sta_construction'))  $('sta_construction').value=d.construction||'';
            if($('sta_emp_search'))    $('sta_emp_search').value=d.employee_name||d.manager_name||'';
            if($('sta_employee_idx'))  $('sta_employee_idx').value=d.employee_idx||'';
            if($('sta_team'))          $('sta_team').value=d.target_team||'';
            if($('sta_tel'))           $('sta_tel').value=d.manager_tel||'';
            if($('sta_total_qty'))     $('sta_total_qty').value=d.external_employee||d.total_qty||'';
            if($('sta_warranty'))      $('sta_warranty').value=d.warranty_period||'';
            if($('sta_start'))         $('sta_start').value=(d.start_date||d.construction_date||'').substring(0,10);
            if($('sta_end'))           $('sta_end').value=(d.end_date||d.completion_date||'').substring(0,10);
            if($('sta_zipcode'))       $('sta_zipcode').value=d.zipcode||'';
            if($('sta_addr'))          $('sta_addr').value=d.address||'';
            if($('sta_addr_detail'))   $('sta_addr_detail').value=d.address_detail||'';
            if($('sta_memo'))          $('sta_memo').value=d.memo||'';
            if($('sta_emp1_search'))   $('sta_emp1_search').value=d.employee1_name||'';
            if($('sta_employee1_idx')) $('sta_employee1_idx').value=d.employee1_idx||'';
            if($('sta_region'))        $('sta_region').value=d.region||'';
            if($('sta_pb_search'))    $('sta_pb_search').value=d.phonebook_name||'';
            if($('sta_phonebook_idx'))$('sta_phonebook_idx').value=d.phonebook_idx||'';
            if($('sta_lat'))          $('sta_lat').value=d.latitude||d.lat||'';
            if($('sta_lng'))          $('sta_lng').value=d.longitude||d.lng||'';
            if(d.member_idx && $('sta_member')) $('sta_member').value=d.member_idx;
            _dupChecked=true; /* 수정모드는 중복체크 스킵 */
        });
}

/* ══════════════════════════
   등록/수정 제출
   ══════════════════════════ */
window.staSubmit = function(){
    showErr('');
    var name=(($('sta_name')||{}).value||'').trim();
    if(!name){ showErr('현장명은 필수 입력 항목입니다.'); if($('sta_name')) $('sta_name').focus(); return; }

    setLoading(true);

    var fd=new FormData();
    fd.append('todo',            _isModify ? 'update' : 'insert');
    if(_isModify) fd.append('idx', _modifyIdx);
    fd.append('name',            name);
    fd.append('site_number',     ($('sta_site_number')||{}).value||'');
    fd.append('site_status',     ($('sta_status')||{}).value||'예정');
    fd.append('member_idx',      ($('sta_member')||{}).value||'');
    fd.append('construction',    ($('sta_construction')||{}).value||'');
    fd.append('employee_idx',    ($('sta_employee_idx')||{}).value||'');
    fd.append('target_team',     ($('sta_team')||{}).value||'');
    fd.append('manager_tel',     ($('sta_tel')||{}).value||'');
    fd.append('total_qty',       ($('sta_total_qty')||{}).value||'');
    fd.append('warranty_period', ($('sta_warranty')||{}).value||'');
    fd.append('start_date',      ($('sta_start')||{}).value||'');
    fd.append('end_date',        ($('sta_end')||{}).value||'');
    fd.append('zipcode',         ($('sta_zipcode')||{}).value||'');
    fd.append('address',         ($('sta_addr')||{}).value||'');
    fd.append('address_detail',  ($('sta_addr_detail')||{}).value||'');
    fd.append('memo',            ($('sta_memo')||{}).value||'');
    fd.append('employee1_idx',   ($('sta_employee1_idx')||{}).value||'');
    fd.append('region',          ($('sta_region')||{}).value||'');
    fd.append('phonebook_idx',   ($('sta_phonebook_idx')||{}).value||'');
    fd.append('latitude',        ($('sta_lat')||{}).value||'');
    fd.append('longitude',       ($('sta_lng')||{}).value||'');

    SHV.api.upload('dist_process/saas/Site.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){
                SHV.modal.close();
                if(SHV.toast) SHV.toast.success(_isModify?'현장이 수정되었습니다.':'현장이 등록되었습니다.');
            } else {
                showErr(res.message||(_isModify?'수정에 실패하였습니다.':'등록에 실패하였습니다.'));
            }
        })
        .catch(function(){ setLoading(false); showErr('네트워크 오류가 발생하였습니다.'); });
};

/* ══════════════════════════
   키보드 필드 탐색
   ══════════════════════════ */
var _navFields = ['sta_name','sta_site_number','sta_status','sta_member','sta_construction',
                  'sta_emp_search','sta_team','sta_tel','sta_emp1_search','sta_region',
                  'sta_total_qty','sta_warranty','sta_pb_search','sta_start','sta_end','sta_addr_detail','sta_memo'];

document.getElementById('staFormBody').addEventListener('keydown',function(e){
    var id=e.target.id;
    if(!id||_navFields.indexOf(id)===-1) return;
    if(e.key==='Enter' && !e.shiftKey && !e.ctrlKey && e.target.tagName!=='TEXTAREA'){
        e.preventDefault();
        var idx=_navFields.indexOf(id);
        for(var i=idx+1;i<_navFields.length;i++){
            var el=$(_navFields[i]);
            if(el&&!el.disabled&&!el.readOnly){ el.focus(); return; }
        }
        var btn=$('staSubmitBtn'); if(btn) btn.focus();
    }
    if(e.ctrlKey && e.key==='Enter'){
        e.preventDefault();
        staSubmit();
    }
});

/* ── 사업장 선택 시 관련정보 자동입력 ── */
window.staMemberChange = function(){
    var sel=$('sta_member'); if(!sel||!sel.value) return;
    SHV.api.get('dist_process/saas/Member.php',{todo:'detail',member_idx:sel.value})
        .then(function(res){
            if(!res.ok) return;
            var m=res.data.member||res.data||{};
            /* 담당자 자동입력 (비어있을 때만) */
            if(m.employee_name && $('sta_emp_search') && !$('sta_emp_search').value){
                $('sta_emp_search').value=m.employee_name;
                if($('sta_employee_idx')) $('sta_employee_idx').value=m.employee_idx||'';
            }
            if(m.target_team && $('sta_team') && !$('sta_team').value) $('sta_team').value=m.target_team;
            if(m.region && $('sta_region') && !$('sta_region').value) $('sta_region').value=m.region;
        }).catch(function(){});
};

/* ── 현장번호 자동생성 (신규 등록 시) ── */
function autoSiteNumber(){
    if(_isModify) return;
    var sn=$('sta_site_number'); if(!sn||sn.value) return;
    SHV.api.get('dist_process/saas/Site.php',{todo:'search',search:'SH',limit:1})
        .then(function(res){
            var total=(res.data.total||0)+1;
            sn.value='SH'+String(total).padStart(5,'0');
        }).catch(function(){});
}

/* ── 초기화 ── */
loadMemberOptions().then(function(){
    if(_isModify) loadModifyData();
});
loadEmployees();
loadPhoneBooks();
autoSiteNumber();

})();
</script>
