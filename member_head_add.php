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
    echo '<p class="text-center p-8 text-danger">인증이 필요합니다.</p>';
    exit;
}
?>
<!-- 다음 우편번호 SDK (modal body 안에서 로드) -->
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>

<!-- ══════════════════════════════════════
     본사 등록 폼 (member_head_add.php)
     modal-body 안에 주입되는 뷰 / size: lg
     ══════════════════════════════════════ -->
<div id="hdAddFormBody">

    <!-- ── 기본정보 ── -->
    <div class="hda-grid-3">

        <div class="form-group">
            <label class="form-label" for="hda_name">본사명 <span class="required">*</span></label>
            <input type="text" id="hda_name" class="form-input"
                placeholder="본사명을 입력하세요" maxlength="100" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_type">유형</label>
            <select id="hda_type" class="form-select">
                <option value="">선택</option>
                <option value="법인">법인</option>
                <option value="개인">개인</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_structure">본사구조 <span class="required">*</span></label>
            <select id="hda_structure" class="form-select">
                <option value="">선택</option>
                <option value="단일">단일</option>
                <option value="지사">지사</option>
            </select>
        </div>

    </div>

    <!-- ── 대표자 · 사업자번호 · 업태 ── -->
    <div class="hda-grid-3">

        <div class="form-group">
            <label class="form-label" for="hda_ceo">대표자</label>
            <input type="text" id="hda_ceo" class="form-input"
                placeholder="대표자명" maxlength="50" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_card">사업자번호</label>
            <input type="text" id="hda_card" class="form-input"
                placeholder="000-00-00000" maxlength="12" autocomplete="off"
                oninput="this.value=fmtBiz(this.value);hdaBizOnInput(this.value)">
            <div id="hdaBizFeedback" class="form-feedback"></div>
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_biz_type">업태</label>
            <input type="text" id="hda_biz_type" class="form-input"
                placeholder="업태" maxlength="50" autocomplete="off">
        </div>

    </div>

    <!-- ── 업종 · 법인등록번호 · 주요사업 ── -->
    <div class="hda-grid-3">

        <div class="form-group">
            <label class="form-label" for="hda_biz_class">업종</label>
            <input type="text" id="hda_biz_class" class="form-input"
                placeholder="업종" maxlength="50" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_identity" id="hda_identity_label">법인등록번호</label>
            <input type="text" id="hda_identity" class="form-input"
                placeholder="000000-0000000" maxlength="14" autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_main_biz">주요사업</label>
            <input type="text" id="hda_main_biz" class="form-input"
                placeholder="주요사업 내용" maxlength="120" autocomplete="off">
        </div>

    </div>

    <!-- ── 대표전화 · 이메일 ── -->
    <div class="hda-grid-2">

        <div class="form-group">
            <label class="form-label" for="hda_tel">대표전화</label>
            <input type="text" id="hda_tel" class="form-input"
                placeholder="02-0000-0000" maxlength="14" autocomplete="off"
                oninput="this.value=fmtPhone(this.value)">
        </div>

        <div class="form-group">
            <label class="form-label" for="hda_email">이메일</label>
            <input type="text" id="hda_email" class="form-input"
                placeholder="email@example.com" maxlength="100" autocomplete="off">
        </div>

    </div>

    <!-- ── 계약 · 담당 ── -->
    <div class="form-section">
        <div class="form-section-label">계약 · 담당</div>
        <div class="hda-grid-2">

            <div class="form-group">
                <label class="form-label" for="hda_contract_type">계약</label>
                <select id="hda_contract_type" class="form-select">
                    <option value="">없음</option>
                    <option value="단일">단일</option>
                    <option value="협력">협력</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="hda_emp">담당자</label>
                <input type="text" id="hda_emp" class="form-input"
                    placeholder="담당자명" maxlength="50" autocomplete="off">
            </div>

            <div class="form-group">
                <label class="form-label" for="hda_contract">협력계약</label>
                <select id="hda_contract" class="form-select">
                    <option value="">선택</option>
                    <option value="정식">정식</option>
                    <option value="임시">임시</option>
                    <option value="해지">해지</option>
                </select>
            </div>

            <!-- 사용견적 — 태그 추가 방식 (full) -->
            <div class="form-group est-dd-wrap col-full">
                <label class="form-label">사용견적</label>
                <div class="tag-input-box" id="hdaEstimateBox"
                    onclick="document.getElementById('hda_estimate_q').focus()">
                    <input type="text" id="hda_estimate_q" class="tag-input-field"
                        placeholder="견적명 입력 후 Enter로 추가"
                        autocomplete="off"
                        onkeydown="hdaEstimateKeydown(event)"
                        oninput="hdaEstimateSearch(this.value)"
                        onfocus="hdaEstimateSearch(this.value)">
                </div>
                <div id="hdaEstimateDropdown" class="est-dd"></div>
            </div>

        </div>
    </div>

    <!-- ── 주소 ── -->
    <div class="form-section">
        <div class="form-section-label">주소</div>
        <div class="hda-grid-2">

            <!-- 우편번호 + 검색버튼 -->
            <div class="form-group">
                <label class="form-label">우편번호</label>
                <div class="flex gap-2 items-center">
                    <input type="text" id="hda_zipcode" class="form-input hda-zipcode" readonly
                        placeholder="우편번호">
                    <button type="button" class="btn btn-outline btn-sm" onclick="hdaAddrOpen()">
                        <i class="fa fa-search"></i> 주소 검색
                    </button>
                </div>
            </div>

            <!-- 상세주소 -->
            <div class="form-group">
                <label class="form-label" for="hda_addr_detail">상세주소</label>
                <input type="text" id="hda_addr_detail" class="form-input"
                    placeholder="동, 호수, 층 등 상세 입력" maxlength="100" autocomplete="off">
            </div>

            <!-- 주소 (full) -->
            <div class="form-group col-full">
                <label class="form-label">주소</label>
                <input type="text" id="hda_addr" class="form-input cursor-pointer" readonly
                    placeholder="위 주소 검색 버튼을 클릭하세요"
                    onclick="hdaAddrOpen()">
            </div>

        </div>
    </div>

    <!-- ── 주소 검색 미니 모달 (본사 등록 모달 위에 뜨는 레이어) ── -->
    <div id="hdaAddrOverlay"
         class="fixed inset-0 z-9999 flex items-center justify-center p-4 pointer-events-none"
         style="display:none;">
        <!-- modal-box 클래스 → 다크/라이트 자동 적용 -->
        <div class="modal-box modal-sm pointer-events-auto max-h-none" id="hdaAddrBox">
            <!-- modal-header 클래스 → 다크/라이트 자동 적용 -->
            <div id="hdaAddrHeader" class="modal-header">
                <span><i class="fa fa-map-marker text-accent mr-2"></i>주소 검색</span>
                <button type="button" class="modal-close" onclick="hdaAddrClose()">×</button>
            </div>
            <!-- 카카오 embed 컨테이너 -->
            <div id="hdaAddrEmbed" class="w-full hda-addr-embed"></div>
        </div>
    </div>

    <!-- ── 첨부파일 ── -->
    <div class="form-section">
        <div class="form-section-label">첨부파일</div>

        <input type="file" id="hda_file_input" multiple
            accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.hwp"
            class="hidden"
            onchange="hdaFilesAdd(this.files); this.value='';">
        <input type="file" id="hda_camera_input"
            accept="image/*" capture="environment"
            class="hidden"
            onchange="hdaFilesAdd(this.files); this.value='';">

        <div class="file-box" id="hdaFileBox"
            ondragover="event.preventDefault(); this.classList.add('drag-over');"
            ondragleave="this.classList.remove('drag-over');"
            ondrop="event.preventDefault(); this.classList.remove('drag-over'); hdaFilesAdd(event.dataTransfer.files);">

            <div class="file-header">
                <span>첨부파일 <small class="opacity-70">(최대 10MB · 드래그 가능)</small></span>
                <div class="flex gap-2 items-center">
                    <label class="file-add-btn" onclick="document.getElementById('hda_file_input').click()">
                        <i class="fa fa-plus mr-1"></i>파일 추가
                    </label>
                    <label class="file-add-btn" onclick="document.getElementById('hda_camera_input').click()" title="카메라 촬영">
                        <i class="fa fa-camera"></i>
                    </label>
                </div>
            </div>

            <div id="hdaFileList" class="file-list"></div>
        </div>
    </div>

</div><!-- /#hdAddFormBody -->

<!-- ── 모달 폼 푸터 ── -->
<div class="modal-form-footer">
    <span id="hdAddErrMsg"
        class="flex-1 text-xs text-danger min-w-0 break-keep"
        style="display:none;"></span>
    <button type="button" class="btn btn-ghost btn-sm"
        onclick="SHV.modal.close()">취소</button>
    <button type="button" class="btn btn-glass-primary btn-sm"
        id="hdAddSubmitBtn" onclick="hdAddSubmit()">
        <i class="fa fa-check"></i> 등록
    </button>
</div>

<script>
(function(){
'use strict';

var _hdFiles          = [];
var _hdEstimates      = []; /* [{id,name}] 객체 배열 */
var _estimateActiveIdx = -1;

function $(id){ return document.getElementById(id); }
function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function showErr(msg){
    var el=$('hdAddErrMsg'); if(!el) return;
    if(msg){ el.textContent=msg; el.style.display='block'; }
    else    { el.style.display='none'; }
}
function setLoading(on){
    var btn=$('hdAddSubmitBtn'); if(!btn) return;
    btn.disabled=on;
    btn.innerHTML=on
        ? '<span class="spinner spinner-sm mr-1"></span>저장 중...'
        : '<i class="fa fa-check"></i> 등록';
}

/* ══════════════════════════
   자동 하이픈 포맷터
   ══════════════════════════ */
window.fmtBiz = function(v){
    var d=v.replace(/\D/g,'').slice(0,10);
    if(d.length<=3) return d;
    if(d.length<=5) return d.slice(0,3)+'-'+d.slice(3);
    return d.slice(0,3)+'-'+d.slice(3,5)+'-'+d.slice(5);
};

window.fmtPhone = function(v){
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
   주소 검색 — 커스텀 글래스 모달 + 카카오 embed
   ══════════════════════════ */
var _addrDragX=0, _addrDragY=0, _addrDragging=false, _addrDragSX, _addrDragSY;

window.hdaAddrOpen = function(){
    if(typeof daum==='undefined' || !daum.Postcode){
        if(SHV && SHV.toast) SHV.toast.warn('주소 검색 서비스를 불러오는 중입니다.');
        return;
    }
    var overlay=$('hdaAddrOverlay');
    var embedEl=$('hdaAddrEmbed');
    var box=$('hdaAddrBox');
    if(!overlay||!embedEl||!box) return;

    /* 위치 초기화 */
    _addrDragX=0; _addrDragY=0;
    box.style.transform='';

    /* embed 초기화 후 삽입 */
    embedEl.innerHTML='';
    overlay.style.display='flex';

    new daum.Postcode({
        oncomplete: function(data){
            var zip  = data.zonecode   || '';
            var addr = data.roadAddress || data.jibunAddress || '';
            var zipEl=$('hda_zipcode'), addrEl=$('hda_addr'), detailEl=$('hda_addr_detail');
            if(zipEl)    zipEl.value  = zip;
            if(addrEl)   addrEl.value = addr;
            if(detailEl){ detailEl.value=''; }
            hdaAddrClose();
            setTimeout(function(){ if(detailEl) detailEl.focus(); }, 80);
        },
        width:  '100%',
        height: '420px'
    }).embed(embedEl, { autoClose: true });

    /* 헤더 드래그 */
    var header=$('hdaAddrHeader');
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

window.hdaAddrClose = function(){
    var overlay=$('hdaAddrOverlay');
    if(overlay) overlay.style.display='none';
    var embedEl=$('hdaAddrEmbed');
    if(embedEl) embedEl.innerHTML='';
};

/* ══════════════════════════
   사용견적 — 태그
   ══════════════════════════ */
function hdaEstimateRender(){
    var box=$('hdaEstimateBox'); if(!box) return;
    box.querySelectorAll('.tag-chip').forEach(function(c){ c.remove(); });
    var input=$('hda_estimate_q');
    _hdEstimates.forEach(function(v,i){
        var chip=document.createElement('span');
        chip.className='tag-chip';
        chip.innerHTML=escH(v.name||v)+'<span class="tag-chip-remove" onclick="hdaEstimateRemove('+i+')">×</span>';
        box.insertBefore(chip,input);
    });
}

window.hdaEstimateRemove=function(i){ _hdEstimates.splice(i,1); hdaEstimateRender(); };

window.hdaEstimateAddObj=function(id,name){
    if(!name) return;
    if(_hdEstimates.find(function(v){return (v.name||v)===name;})) return;
    _hdEstimates.push({id:String(id), name:name});
    hdaEstimateRender();
    var q=$('hda_estimate_q'); if(q) q.value='';
    var dd=$('hdaEstimateDropdown'); if(dd) dd.style.display='none';
};

function hdaEstimateAdd(val){
    val=(val||'').trim();
    if(!val) return;
    if(_hdEstimates.find(function(v){return (v.name||v)===val;})) return;
    _hdEstimates.push({id:val, name:val});
    hdaEstimateRender();
    var q=$('hda_estimate_q'); if(q) q.value='';
    var dd=$('hdaEstimateDropdown'); if(dd) dd.style.display='none';
}

window.hdaEstimateKeydown=function(e){
    var dd=$('hdaEstimateDropdown'); if(!dd) return;
    var items=dd.querySelectorAll('.est-dd-item');
    if(e.key==='ArrowDown'){
        e.preventDefault();
        _estimateActiveIdx=Math.min(_estimateActiveIdx+1, items.length-1);
        hdaEstimateHighlight(items);
    } else if(e.key==='ArrowUp'){
        e.preventDefault();
        _estimateActiveIdx=Math.max(_estimateActiveIdx-1, -1);
        hdaEstimateHighlight(items);
    } else if(e.key==='Enter'){
        e.preventDefault();
        var active=dd.querySelector('.est-dd-active');
        if(active){ hdaEstimateAddObj(active.dataset.id||active.dataset.val, active.dataset.val); }
        else       { hdaEstimateAdd(e.target.value); }
    } else if(e.key==='Escape'){
        dd.style.display='none'; _estimateActiveIdx=-1;
    } else if(e.key==='Backspace'&&!e.target.value&&_hdEstimates.length){
        _hdEstimates.pop();hdaEstimateRender();
    }
};

function hdaEstimateHighlight(items){
    items.forEach(function(el,i){
        el.classList.toggle('est-dd-active', i===_estimateActiveIdx);
    });
    if(_estimateActiveIdx>=0 && items[_estimateActiveIdx]){
        items[_estimateActiveIdx].scrollIntoView({block:'nearest'});
    }
}

/* 사용견적 후보 — Material.php tab_list API에서 동적 로드 */
var _estimateCandidates=[];
(function(){
    SHV.api.get('dist_process/saas/Material.php',{todo:'tab_list'})
        .then(function(res){
            if(!res.ok) return;
            var list = res.data.data||res.data||[];
            _estimateCandidates = list.map(function(t){ return {idx:t.idx, name:t.name}; });
        });
})();

window.hdaEstimateSearch=function(q){
    q=(q||'').trim();
    var dd=$('hdaEstimateDropdown'); if(!dd) return;
    _estimateActiveIdx=-1;
    var matched=_estimateCandidates.filter(function(v){
        var name = v.name||v;
        return (!q||name.toLowerCase().indexOf(q.toLowerCase())>-1) && !_hdEstimates.find(function(e){return (e.name||e)===name;});
    });
    if(!matched.length){ dd.style.display='none'; dd.innerHTML=''; return; }
    dd.innerHTML=matched.map(function(v){
        var name = v.name||v;
        var id = v.idx||v.id||name;
        return '<div class="est-dd-item" data-val="'+escH(name)+'" data-id="'+escH(String(id))+'"'
            +' onmousedown="event.preventDefault();hdaEstimateAddObj(\''+escH(String(id))+'\',\''+escH(name)+'\')"'
            +' onmouseover="_estimateActiveIdx=Array.prototype.indexOf.call(this.parentNode.children,this);hdaEstimateHighlight(document.getElementById(\'hdaEstimateDropdown\').querySelectorAll(\'.est-dd-item\'))">'
            +escH(name)+'</div>';
    }).join('');
    dd.style.display='block';
};

document.addEventListener('click',function(e){
    if(!e.target.closest('#hdaEstimateBox')&&!e.target.closest('#hdaEstimateDropdown')){
        var dd=$('hdaEstimateDropdown'); if(dd) dd.style.display='none';
    }
},true);

/* ══════════════════════════
   첨부파일
   ══════════════════════════ */
window.hdaFilesAdd=function(fileList){
    Array.from(fileList).forEach(function(f){
        var dup=_hdFiles.some(function(x){ return x.name===f.name&&x.size===f.size; });
        if(!dup) _hdFiles.push(f);
    });
    hdaRenderFiles();
};
window.hdaFileRemove=function(i){ _hdFiles.splice(i,1); hdaRenderFiles(); };

var MAX_FILE_SIZE = 10 * 1024 * 1024; /* 10MB */

function hdaFileIconCls(f){
    if(f.type.startsWith('image/'))      return 'fa-file-image-o';
    if(f.type.includes('pdf'))           return 'fa-file-pdf-o';
    if(f.name.match(/\.xlsx?$/i))        return 'fa-file-excel-o';
    if(f.name.match(/\.docx?$/i))        return 'fa-file-word-o';
    if(f.name.match(/\.hwp$/i))          return 'fa-file-text-o';
    return 'fa-file-o';
}

function hdaFmtSize(bytes){
    return bytes >= 1024*1024
        ? (bytes/1024/1024).toFixed(1) + 'MB'
        : Math.round(bytes/1024) + 'KB';
}

function hdaRenderFiles(){
    var list=$('hdaFileList');
    var box=$('hdaFileBox');
    if(!list) return;

    if(!_hdFiles.length){
        list.innerHTML='';
        if(box) box.classList.remove('has-files');
        return;
    }
    if(box) box.classList.add('has-files');

    list.innerHTML=_hdFiles.map(function(f,i){
        var over = f.size > MAX_FILE_SIZE;
        var size = hdaFmtSize(f.size);
        var icon = hdaFileIconCls(f);
        return '<div class="file-row'+(over?' file-over':'')+'">'
            +'<div class="file-left">'
            +'<span class="file-row-icon"><i class="fa '+icon+'"></i></span>'
            +'<span class="file-name" title="'+escH(f.name)+'">'+escH(f.name)+'</span>'
            +'<span class="file-size">'+size+'</span>'
            +'</div>'
            +'<div class="flex items-center gap-2">'
            +'<span class="file-status '+(over?'error':'ok')+'">'+(over?'10MB 초과':'✔')+'</span>'
            +'<button type="button" class="file-remove" onclick="hdaFileRemove('+i+')" title="제거">✕</button>'
            +'</div>'
            +'</div>';
    }).join('');
}

/* ══════════════════════════
   등록 제출
   ══════════════════════════ */
/* 유형 변경 시 법인등록번호/주민번호 라벨 전환 */
var typeEl=$('hda_type');
if(typeEl) typeEl.addEventListener('change', function(){
    var lbl=$('hda_identity_label');
    if(lbl) lbl.textContent = this.value==='개인' ? '주민번호' : '법인등록번호';
});

window.hdAddSubmit=function(){
    showErr('');
    var name=(($('hda_name')||{}).value||'').trim();
    if(!name){ showErr('본사명은 필수 입력 항목입니다.'); if($('hda_name')) $('hda_name').focus(); return; }
    var structure=(($('hda_structure')||{}).value||'').trim();
    if(!structure){ showErr('본사구조는 필수 항목입니다.'); if($('hda_structure')) $('hda_structure').focus(); return; }
    if(_bizIsDup){ showErr('중복된 사업자번호입니다. 확인 후 진행해주세요.'); return; }
    var overFiles=_hdFiles.filter(function(f){ return f.size>MAX_FILE_SIZE; });
    if(overFiles.length){ showErr(overFiles.length+'개 파일이 10MB를 초과합니다. 초과 파일은 제외됩니다.'); }
    setLoading(true);

    var fd=new FormData();
    fd.append('todo',                 'insert');
    fd.append('name',                 name);
    fd.append('head_type',            ($('hda_type')||{}).value||'');
    fd.append('head_structure',       structure);
    fd.append('ceo',                  ($('hda_ceo')||{}).value||'');
    fd.append('card_number',          ($('hda_card')||{}).value||'');
    fd.append('business_type',        ($('hda_biz_type')||{}).value||'');
    fd.append('business_class',       ($('hda_biz_class')||{}).value||'');
    fd.append('identity_number',      ($('hda_identity')||{}).value||'');
    fd.append('main_business',        ($('hda_main_biz')||{}).value||'');
    fd.append('tel',                  ($('hda_tel')||{}).value||'');
    fd.append('email',                ($('hda_email')||{}).value||'');
    fd.append('contract_type',        ($('hda_contract_type')||{}).value||'');
    fd.append('employee_name',        ($('hda_emp')||{}).value||'');
    fd.append('cooperation_contract', ($('hda_contract')||{}).value||'');
    fd.append('used_estimate',        JSON.stringify(_hdEstimates));
    fd.append('zipcode',              ($('hda_zipcode')||{}).value||'');
    fd.append('address',              ($('hda_addr')||{}).value||'');
    fd.append('address_detail',       ($('hda_addr_detail')||{}).value||'');
    _hdFiles.filter(function(f){ return f.size<=MAX_FILE_SIZE; })
            .forEach(function(f){ fd.append('attach[]',f,f.name); });

    SHV.api.upload('dist_process/saas/HeadOffice.php',fd)
        .then(function(res){
            setLoading(false);
            if(res.ok){
                SHV.modal.close();
                if(typeof hdReload==='function') hdReload();
                if(SHV.toast) SHV.toast.success('본사가 등록되었습니다.');
            } else {
                showErr(res.message||'등록에 실패하였습니다.');
            }
        })
        .catch(function(){ setLoading(false); showErr('네트워크 오류가 발생하였습니다.'); });
};

/* ══════════════════════════
   키보드 필드 탐색
   Enter  → 다음 필드
   Shift+Enter → 이전 필드
   Ctrl+Enter  → 저장
   ══════════════════════════ */
var _navFields = ['hda_name','hda_type','hda_structure','hda_ceo','hda_card','hda_biz_type',
                  'hda_biz_class','hda_identity','hda_main_biz','hda_tel','hda_email',
                  'hda_contract_type','hda_emp','hda_contract','hda_addr_detail'];

function hdaNavNext(currentId){
    var idx = _navFields.indexOf(currentId);
    if(idx === -1) return;
    /* 사업자번호 Enter → 즉시 중복체크 (debounce 무시) */
    if(currentId === 'hda_card'){
        var raw = ($('hda_card')||{value:''}).value.replace(/\D/g,'');
        if(raw.length === 10 && raw !== _bizLast){ clearTimeout(_bizTimer); _bizLast=raw; hdaBizSetState('loading'); hdaBizCheck(raw); }
    }
    for(var i = idx+1; i < _navFields.length; i++){
        var el = $(_navFields[i]);
        if(el && !el.disabled && !el.readOnly){ el.focus(); return; }
    }
    /* 마지막 필드 → 저장 버튼 포커스 */
    var btn = $('hdAddSubmitBtn'); if(btn) btn.focus();
}

function hdaNavPrev(currentId){
    var idx = _navFields.indexOf(currentId);
    if(idx <= 0) return;
    for(var i = idx-1; i >= 0; i--){
        var el = $(_navFields[i]);
        if(el && !el.disabled && !el.readOnly){ el.focus(); return; }
    }
}

_navFields.forEach(function(id){
    var el = $(id); if(!el) return;
    el.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
            e.preventDefault();
            if(e.ctrlKey || e.metaKey){ hdAddSubmit(); return; }
            if(e.shiftKey){ hdaNavPrev(id); return; }
            hdaNavNext(id);
        }
    });
});

/* 저장 버튼에서도 Enter = 제출 */
(function(){ var b=$('hdAddSubmitBtn'); if(b) b.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); hdAddSubmit(); } }); })();

/* ══════════════════════════
   blur 검증 — 필수 필드
   ══════════════════════════ */
(function(){
    var name = $('hda_name'); if(!name) return;
    name.addEventListener('blur', function(){
        if(!this.value.trim()){
            this.classList.add('error');
        } else {
            this.classList.remove('error');
            showErr('');
        }
    });
    name.addEventListener('input', function(){
        if(this.value.trim()) this.classList.remove('error');
    });
})();

/* ══════════════════════════
   사업자번호 중복체크
   10자리 완성 → 300ms debounce → API 호출
   ChatGPT: HeadOffice.php?todo=check_dup&card_number=XXX
   ══════════════════════════ */
var _bizTimer   = null;
var _bizLast    = '';
var _bizIsDup   = false;   /* 중복 여부 (true면 제출 차단) */
var _bizChecked = false;   /* 체크 완료 여부 */

function hdaBizSetState(state, msg, existData){
    var input    = $('hda_card');
    var feedback = $('hdaBizFeedback');
    var submitBtn= $('hdAddSubmitBtn');
    if(!input || !feedback) return;

    /* 상태 초기화 */
    input.classList.remove('error','success');
    feedback.className = 'form-feedback';
    feedback.innerHTML = '';

    if(state === 'loading'){
        feedback.classList.add('fb-loading');
        feedback.innerHTML = '<span class="spinner spinner-sm"></span> 확인 중...';
        if(submitBtn) submitBtn.disabled = true;

    } else if(state === 'dup'){
        _bizIsDup = true; _bizChecked = true;
        input.classList.add('error');
        feedback.classList.add('fb-error');
        feedback.innerHTML = '<i class="fa fa-times-circle"></i> 이미 등록된 사업자번호입니다.'
            + (existData
                ? ' <button type="button" class="btn btn-outline btn-sm hda-biz-view-btn" onclick="hdaBizViewExist()">기존 정보 보기</button>'
                : '');
        if(submitBtn){ submitBtn.disabled = true; }
        /* 기존 데이터 저장 */
        window._hdaBizExistData = existData || null;

    } else if(state === 'ok'){
        _bizIsDup = false; _bizChecked = true;
        input.classList.add('success');
        feedback.classList.add('fb-success');
        feedback.innerHTML = '<i class="fa fa-check-circle"></i> 사용 가능한 사업자번호입니다.';
        if(submitBtn){ submitBtn.disabled = false; }

    } else { /* reset */
        _bizIsDup = false; _bizChecked = false;
        if(submitBtn){ submitBtn.disabled = false; }
    }
}

window.hdaBizViewExist = function(){
    var d = window._hdaBizExistData;
    if(!d) return;
    /* ChatGPT가 상세 뷰 HTML 제공 시 교체 */
    var html = '<div class="p-4">'
        + '<table class="w-full border-collapse text-sm text-1">'
        + (d.name     ? '<tr><th class="biz-th whitespace-nowrap">본사명</th><td class="biz-td">'+escH(d.name)+'</td></tr>' : '')
        + (d.ceo      ? '<tr><th class="biz-th">대표자</th><td class="biz-td">'+escH(d.ceo)+'</td></tr>' : '')
        + (d.tel      ? '<tr><th class="biz-th">전화번호</th><td class="biz-td">'+escH(d.tel)+'</td></tr>' : '')
        + (d.address  ? '<tr><th class="biz-th">주소</th><td class="biz-td">'+escH(d.address)+'</td></tr>' : '')
        + '</table>'
        + '<div class="mt-3 text-right">'
        + '<button class="btn btn-ghost btn-sm" onclick="SHV.subModal.close()">닫기</button>'
        + '</div></div>';
    SHV.subModal.openHtml(html, '기존 등록 정보', 'sm');
};

window.hdaBizOnInput = function(formatted){
    var raw = formatted.replace(/\D/g,'');
    /* 10자리 미만 → 상태 초기화 */
    if(raw.length < 10){ hdaBizSetState('reset'); _bizLast=''; return; }
    /* 동일 번호 재확인 방지 */
    if(raw === _bizLast) return;
    clearTimeout(_bizTimer);
    hdaBizSetState('loading');
    _bizTimer = setTimeout(function(){
        _bizLast = raw;
        hdaBizCheck(raw);
    }, 300);
};

function hdaBizCheck(no){
    SHV.api.get('dist_process/saas/HeadOffice.php?todo=check_dup&card_number='+encodeURIComponent(no))
        .then(function(res){
            if(!res) { hdaBizSetState('reset'); return; }
            if(res.exists){
                hdaBizSetState('dup', null, res.data || null);
            } else {
                hdaBizSetState('ok');
            }
        })
        .catch(function(){
            /* API 오류 시 차단하지 않음 — 서버에서 최종 검증 */
            hdaBizSetState('reset');
        });
}

/* 자동 포커스 */
setTimeout(function(){ var n=$('hda_name'); if(n) n.focus(); },80);

})();
</script>
