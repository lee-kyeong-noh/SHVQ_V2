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

$modeRaw   = $_GET['mode'] ?? 'new';
$mode      = in_array($modeRaw, ['new','edit','deposit'], true) ? $modeRaw : 'new';
$billIdx   = (int)($_GET['idx'] ?? 0);
$siteIdx   = (int)($_GET['site_idx'] ?? 0);
$modeLabel = ['new'=>'수금 등록','edit'=>'수금 수정','deposit'=>'입금 처리'][$mode];
?>
<link rel="stylesheet" href="css/v2/pages/fms.css?v=<?= @filemtime(__DIR__.'/../../../css/v2/pages/fms.css')?:'1' ?>">

<div id="baFormBody" class="p-4">

    <!-- ── 견적 연결 ── -->
    <div class="hda-grid-2 mb-3">
        <div class="form-group">
            <label class="form-label">견적 연결</label>
            <select id="ba_estimate" class="form-select" onchange="baEstChange()">
                <option value="">선택 (미연결)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">견적금액</label>
            <input type="text" id="ba_est_amount" class="form-input text-right" readonly placeholder="-">
        </div>
    </div>

    <!-- ── 기본 정보 ── -->
    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label">회차</label>
            <input type="text" id="ba_number" class="form-input" readonly placeholder="자동">
        </div>
        <div class="form-group">
            <label class="form-label" for="ba_amount"><?= $mode === 'deposit' ? '입금액' : '청구금액' ?> <span class="required">*</span></label>
            <input type="text" id="ba_amount" class="form-input text-right" placeholder="0" autocomplete="off"
                oninput="baFmtAmount(this)">
        </div>
        <div class="form-group">
            <label class="form-label" for="ba_status">상태</label>
            <select id="ba_status" class="form-select">
                <option value="1">청구</option>
                <option value="2">입금</option>
                <option value="3">완료</option>
                <option value="4">미수</option>
            </select>
        </div>
    </div>

    <div class="hda-grid-3">
        <div class="form-group">
            <label class="form-label" for="ba_bring_date">청구일</label>
            <input type="date" id="ba_bring_date" class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label" for="ba_end_date">청구마감일</label>
            <input type="date" id="ba_end_date" class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label" for="ba_deposit_date"><?= $mode === 'deposit' ? '입금일' : '입금예정일' ?></label>
            <input type="date" id="ba_deposit_date" class="form-input">
        </div>
    </div>

    <div class="hda-grid-2">
        <div class="form-group">
            <label class="form-label">작성자</label>
            <select id="ba_employee" class="form-select">
                <option value="">선택</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="ba_insert_date">작성일</label>
            <input type="date" id="ba_insert_date" class="form-input">
        </div>
    </div>

<?php if($mode === 'deposit'): ?>
    <!-- ── 입금 상세 (deposit 모드 전용) ── -->
    <div class="form-section">
        <div class="form-section-label">입금 상세</div>
        <div class="hda-grid-2">
            <div class="form-group">
                <label class="form-label">입금방식</label>
                <select id="ba_deposit_type" class="form-select">
                    <option value="1">통장</option>
                    <option value="2">현금</option>
                    <option value="3">어음</option>
                    <option value="4">상계</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">입금자명</label>
                <input type="text" id="ba_depositor" class="form-input" placeholder="입금자명" maxlength="50" autocomplete="off">
            </div>
        </div>
        <div class="hda-grid-2">
            <div class="form-group">
                <label class="form-label">통장별칭</label>
                <select id="ba_account" class="form-select"><option value="">선택</option></select>
            </div>
            <div class="form-group"></div>
        </div>
    </div>
<?php endif; ?>

    <!-- ── 비고 ── -->
    <div class="form-group">
        <label class="form-label" for="ba_memo">비고</label>
        <textarea id="ba_memo" class="form-input" rows="2" placeholder="비고" maxlength="2000"></textarea>
    </div>

    <!-- ── 첨부파일 ── -->
    <div class="form-section">
        <div class="form-section-label">첨부파일</div>
        <div class="flex gap-2 mb-2">
            <button type="button" class="btn btn-outline btn-sm" onclick="baUploadFile()"><i class="fa fa-upload mr-1"></i>파일 추가</button>
        </div>
        <div id="baFileList" class="text-xs text-3"></div>
    </div>

    <!-- ── 코멘트 (edit/deposit 모드만) ── -->
    <div class="form-section<?= $mode === 'new' ? ' hidden' : '' ?>">
        <div id="baCommentList" class="mb-2 text-xs"></div>
        <div class="flex gap-2">
            <input type="text" id="baCommentInput" class="form-input form-input-sm flex-1" placeholder="코멘트 입력..."
                onkeydown="if(event.key==='Enter'){event.preventDefault();baAddComment();}">
            <button type="button" class="btn btn-outline btn-sm" onclick="baAddComment()"><i class="fa fa-paper-plane"></i></button>
        </div>
    </div>

</div>

<!-- ── 모달 푸터 ── -->
<div class="modal-form-footer">
    <span id="baErrMsg" class="flex-1 text-xs text-danger min-w-0 break-keep" style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm" id="baSubmitBtn" onclick="baSubmit()">
        <i class="fa fa-check"></i> <?= $modeLabel ?>
    </button>
</div>

<script>
(function(){
'use strict';

var _mode    = '<?= $mode ?>';
var _billIdx = <?= $billIdx ?>;
var _siteIdx = <?= $siteIdx ?>;
var _pendingFiles = [];

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function fmtNum(v){ var n=parseInt(v,10); return isNaN(n)?'':n.toLocaleString(); }

function showErr(msg){
    var el=$('baErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; } else { el.style.display='none'; }
}
function setLoading(on){
    var btn=$('baSubmitBtn'); if(!btn) return;
    btn.disabled=on;
    var label={new:'수금 등록',edit:'수금 수정',deposit:'입금 처리'}[_mode]||'저장';
    btn.innerHTML=on?'<span class="spinner spinner-sm mr-1"></span>저장 중...':'<i class="fa fa-check"></i> '+label;
}

window.baFmtAmount = function(el){
    var raw=el.value.replace(/[^\d]/g,'');
    var n=parseInt(raw,10);
    el.value=isNaN(n)?'':n.toLocaleString();
};

/* ══════════════════════════
   견적 목록 로드
   ══════════════════════════ */
function loadEstimates(){
    return SHV.api.get('dist_process/saas/Site.php',{todo:'est_list',site_idx:_siteIdx,limit:100})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('ba_estimate'); if(!sel) return;
            (res.data.data||[]).forEach(function(e){
                var opt=document.createElement('option');
                opt.value=e.idx;
                opt.textContent=(e.estimate_no||'')+(e.estimate_title?' - '+e.estimate_title:'');
                opt.dataset.amount=e.total_amount||e.supply_amount||0;
                sel.appendChild(opt);
            });
        }).catch(function(){});
}

window.baEstChange = function(){
    var sel=$('ba_estimate');
    var amtEl=$('ba_est_amount');
    if(!sel||!amtEl) return;
    var opt=sel.options[sel.selectedIndex];
    amtEl.value=opt&&opt.dataset.amount?fmtNum(opt.dataset.amount):'';
};

/* ══════════════════════════
   작성자 로드
   ══════════════════════════ */
function loadEmployees(){
    SHV.api.get('dist_process/saas/Member.php',{todo:'employee_list',limit:200})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('ba_employee'); if(!sel) return;
            (res.data.data||res.data||[]).forEach(function(e){
                var opt=document.createElement('option');
                opt.value=e.idx;
                opt.textContent=e.name+(e.team?' ('+e.team+')':'');
                sel.appendChild(opt);
            });
        }).catch(function(){});
}

/* ── 통장 자산 로드 ── */
function loadAssets(){
    SHV.api.get('dist_process/saas/Site.php',{todo:'asset_list',site_idx:_siteIdx,limit:100})
        .then(function(res){
            if(!res.ok) return;
            var sel=$('ba_account'); if(!sel) return;
            (res.data.options||res.data.data||[]).forEach(function(a){
                var opt=document.createElement('option');
                opt.value=a.value||a.idx;
                opt.textContent=a.label||a.name||a.account_name||'';
                sel.appendChild(opt);
            });
        }).catch(function(){});
}

/* ══════════════════════════
   수정/입금 모드 데이터 로드
   ══════════════════════════ */
function loadData(){
    if(_mode==='new'||!_billIdx) return;
    SHV.api.get('dist_process/saas/Site.php',{todo:'bill_detail',bill_idx:_billIdx,site_idx:_siteIdx})
        .then(function(res){
            if(!res.ok) return;
            var b=res.data.bill||res.data||{};
            if($('ba_amount')){
                var amt=parseInt(b.amount||0,10);
                $('ba_amount').value=isNaN(amt)?'':amt.toLocaleString();
            }
            if($('ba_number'))       $('ba_number').value=b.bill_number||b.seq||'';
            if($('ba_status'))       $('ba_status').value=b.bill_status||b.status||'1';
            if($('ba_bring_date'))   $('ba_bring_date').value=(b.bill_date||b.bring_date||'').substring(0,10);
            if($('ba_end_date'))     $('ba_end_date').value=(b.end_date||'').substring(0,10);
            if($('ba_deposit_date')) $('ba_deposit_date').value=(b.deposit_date||'').substring(0,10);
            if($('ba_insert_date'))  $('ba_insert_date').value=(b.insert_date||'').substring(0,10);
            if($('ba_employee'))     $('ba_employee').value=b.employee_idx||'';
            if($('ba_estimate'))     $('ba_estimate').value=b.estimate_idx||'';
            if($('ba_memo'))         $('ba_memo').value=b.memo||'';
            baEstChange();
            loadComments();
        });
}

/* ══════════════════════════
   첨부파일
   ══════════════════════════ */
window.baUploadFile = function(){
    var inp=document.createElement('input');
    inp.type='file'; inp.multiple=true;
    inp.onchange=function(){
        for(var i=0;i<inp.files.length;i++) _pendingFiles.push(inp.files[i]);
        renderFileList();
    };
    inp.click();
};

function renderFileList(){
    var el=$('baFileList'); if(!el) return;
    if(!_pendingFiles.length){ el.innerHTML='<span class="text-3">첨부파일 없음</span>'; return; }
    el.innerHTML=_pendingFiles.map(function(f,i){
        return '<div class="flex items-center gap-2 mb-1"><span>'+escH(f.name)+'</span><button class="btn btn-ghost btn-sm text-danger" onclick="_baPendingRemove('+i+')"><i class="fa fa-times"></i></button></div>';
    }).join('');
}

window._baPendingRemove = function(i){
    _pendingFiles.splice(i,1);
    renderFileList();
};

/* ══════════════════════════
   코멘트
   ══════════════════════════ */
function loadComments(){
    if(!_billIdx) return;
    SHV.api.get('dist_process/saas/Site.php',{todo:'bill_comment_list',bill_idx:_billIdx,site_idx:_siteIdx})
        .then(function(res){
            if(!res.ok) return;
            var rows=res.data.data||res.data||[];
            var el=$('baCommentList'); if(!el) return;
            if(!rows.length){ el.innerHTML='<span class="text-3">코멘트 없음</span>'; return; }
            el.innerHTML=rows.map(function(c){
                return '<div class="mb-1"><b class="text-1">'+escH(c.author||c.employee_name||'')+'</b> <span class="text-3">'+escH(c.created_at||'').substring(0,16)+'</span><div>'+escH(c.content||c.comment||'')+'</div></div>';
            }).join('');
        }).catch(function(){});
}

window.baAddComment = function(){
    var inp=$('baCommentInput'); if(!inp||!inp.value.trim()||!_billIdx) return;
    SHV.api.post('dist_process/saas/Site.php',{todo:'insert_bill_comment',bill_idx:_billIdx,site_idx:_siteIdx,content:inp.value.trim()})
        .then(function(res){
            if(res.ok){ inp.value=''; loadComments(); }
            else { if(SHV.toast) SHV.toast.error(res.message||'등록 실패'); }
        }).catch(function(){});
};

/* ══════════════════════════
   제출
   ══════════════════════════ */
window.baSubmit = function(){
    showErr('');
    var rawAmt=($('ba_amount')||{}).value||'';
    var amount=parseInt(rawAmt.replace(/[^\d]/g,''),10);
    if(isNaN(amount)||amount<=0){ showErr('금액을 입력하세요.'); if($('ba_amount')) $('ba_amount').focus(); return; }

    setLoading(true);

    var todo;
    if(_mode==='deposit') todo='deposit_bill';
    else if(_mode==='edit') todo='update_bill';
    else todo='insert_bill';

    var fd=new FormData();
    fd.append('todo', todo);
    fd.append('site_idx', _siteIdx);
    if(_billIdx) fd.append('bill_idx', _billIdx);
    fd.append('estimate_idx', ($('ba_estimate')||{}).value||'');
    fd.append('amount', amount);
    fd.append('bill_status', ($('ba_status')||{}).value||'1');
    fd.append('bring_date', ($('ba_bring_date')||{}).value||'');
    fd.append('end_date', ($('ba_end_date')||{}).value||'');
    fd.append('deposit_date', ($('ba_deposit_date')||{}).value||'');
    fd.append('insert_date', ($('ba_insert_date')||{}).value||'');
    fd.append('employee_idx', ($('ba_employee')||{}).value||'');
    fd.append('memo', ($('ba_memo')||{}).value||'');
    if(_mode==='deposit'){
        fd.append('deposit_type', ($('ba_deposit_type')||{}).value||'');
        fd.append('depositor_name', ($('ba_depositor')||{}).value||'');
        fd.append('account_idx', ($('ba_account')||{}).value||'');
    }
    _pendingFiles.forEach(function(f){ fd.append('files[]',f); });

    SHV.api.upload('dist_process/saas/Site.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){
                SHV.modal.close();
                var msg={new:'수금이 등록되었습니다.',edit:'수금이 수정되었습니다.',deposit:'입금 처리되었습니다.'}[_mode];
                if(SHV.toast) SHV.toast.success(msg);
            } else {
                showErr(res.message||'저장 실패');
            }
        })
        .catch(function(){ setLoading(false); showErr('네트워크 오류'); });
};

/* ── 초기화 ── */
if(!$('ba_bring_date').value) $('ba_bring_date').value=new Date().toISOString().slice(0,10);
if(!$('ba_insert_date').value) $('ba_insert_date').value=new Date().toISOString().slice(0,10);
if(_mode==='deposit' && $('ba_status')) $('ba_status').value='2';
loadEstimates().then(function(){ loadData(); });
loadEmployees();
if(_mode==='deposit') loadAssets();
renderFileList();
})();
</script>
