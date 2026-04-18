/* ══════════════════════════════════════
   SHV.chat — 공용 코멘트 모듈
   카카오톡 스타일 채팅 UI
   사용법: SHV.chat.init('containerId', 'Tb_Members', 123)
          SHV.chat.init('containerId', 'Tb_PjtPlan', 456, 'plan-456')
   ══════════════════════════════════════ */
(function(SHV){
'use strict';

SHV.chat = SHV.chat || {};

var _uidCounter = 0;
var _instances = {};
var _delBtn = null;

function escH(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function getUser(){ return SHV._user || { id:'', name:'', pk:0, photo:'' }; }

/* ══════════════════════════
   init — 채팅 UI 생성 + 로드
   ══════════════════════════ */
SHV.chat.init = function(containerId, toTable, toIdx, pjtKey){
    var el = document.getElementById(containerId);
    if(!el) return;
    _uidCounter++;
    var uid = 'sc' + _uidCounter;
    var inst = { uid:uid, el:el, toTable:toTable, toIdx:toIdx, pjtKey:pjtKey||'' };
    _instances[uid] = inst;

    el.innerHTML = '<div class="shv-chat" id="sc_'+uid+'">'
        +'<div class="sc-msgs" id="scMsgs_'+uid+'"></div>'
        +'<div class="sc-bar">'
        +'<input type="file" id="scFile_'+uid+'" class="hidden" multiple onchange="SHV.chat._fileSend(\''+uid+'\',this)">'
        +'<button class="sc-bar-btn sc-bar-attach" onclick="document.getElementById(\'scFile_'+uid+'\').click()" title="파일 첨부"><i class="fa fa-paperclip"></i></button>'
        +'<input type="text" id="scInput_'+uid+'" class="sc-bar-input" placeholder="메시지 입력..." maxlength="2000"'
        +' onkeydown="SHV.chat._keydown(event,\''+uid+'\')">'
        +'<button class="sc-bar-btn sc-bar-send" onclick="SHV.chat._send(\''+uid+'\')"><i class="fa fa-paper-plane"></i></button>'
        +'<button class="sc-bar-btn sc-bar-refresh" onclick="SHV.chat._load(\''+uid+'\')" title="새로고침"><i class="fa fa-refresh"></i></button>'
        +'</div></div>';

    /* 드래그앤드롭 */
    var msgs = document.getElementById('scMsgs_'+uid);
    msgs.addEventListener('dragover', function(e){ e.preventDefault(); msgs.classList.add('sc-drag-over'); });
    msgs.addEventListener('dragleave', function(){ msgs.classList.remove('sc-drag-over'); });
    msgs.addEventListener('drop', function(e){
        e.preventDefault(); msgs.classList.remove('sc-drag-over');
        if(e.dataTransfer.files.length){
            for(var i=0; i<e.dataTransfer.files.length; i++) _sendFile(uid, e.dataTransfer.files[i]);
        }
    });

    /* 붙여넣기 이미지 */
    var inp = document.getElementById('scInput_'+uid);
    if(inp) inp.addEventListener('paste', function(e){
        var items = e.clipboardData && e.clipboardData.items;
        if(!items) return;
        for(var i=0; i<items.length; i++){
            if(items[i].type.indexOf('image') !== -1){
                e.preventDefault();
                var f = items[i].getAsFile();
                if(f) _sendFile(uid, f);
                return;
            }
        }
    });

    _load(uid);
};

/* ══════════════════════════
   load — 메시지 목록 로드
   ══════════════════════════ */
function _load(uid){
    var inst = _instances[uid]; if(!inst) return;
    var params = { todo:'list', to_table:inst.toTable, to_idx:inst.toIdx, limit:500 };
    if(inst.pjtKey) params.pjt_key = inst.pjtKey;

    SHV.api.get('dist_process/saas/Comment.php', params)
        .then(function(res){
            if(!res.ok) return;
            var rows = res.data.data || [];
            _renderAll(uid, rows);
        })
        .catch(function(){});
}
SHV.chat._load = _load;

/* ══════════════════════════
   renderAll — 전체 메시지 렌더링 (1분 그룹핑 + 날짜 구분)
   ══════════════════════════ */
function _renderAll(uid, rows){
    var area = document.getElementById('scMsgs_'+uid);
    if(!area) return;
    var user = getUser();
    if(!rows.length){
        area.innerHTML = '<div class="sc-empty"><i class="fa fa-comment-o"></i><p>메시지가 없습니다</p></div>';
        return;
    }

    var html = '';
    var lastDate = '';
    for(var i=0; i<rows.length; i++){
        var m = rows[i], prev = rows[i-1]||null, next = rows[i+1]||null;
        var mDate = _getDate(m);
        if(mDate && mDate !== lastDate){ html += _dateBar(mDate); lastDate = mDate; }

        var mMin = _getMin(m), pMin = prev ? _getMin(prev) : '';
        var pDate = prev ? _getDate(prev) : '';
        var nMin = next ? _getMin(next) : '', nDate = next ? _getDate(next) : '';

        var isFirst = !prev || prev.user_id !== m.user_id || pMin !== mMin || pDate !== mDate;
        var isLast = !next || next.user_id !== m.user_id || nMin !== mMin || nDate !== mDate;

        html += _renderMsg(m, user.id, isFirst, isLast);
    }
    area.innerHTML = html;
    area.scrollTop = area.scrollHeight;
}

/* ══════════════════════════
   renderMsg — 개별 메시지 렌더링
   ══════════════════════════ */
function _renderMsg(m, myId, isFirst, isLast){
    var isMe = m.user_id === myId;
    var photoUrl = isMe ? '' : (m.photo ? 'https://img.shv.kr/employee/'+m.photo : '');
    var dt = m.datetime || (m.date ? m.date+' '+m.time : m.time) || '';
    var timeStr = _fmtTime(dt);
    var firstCls = isFirst ? ' sc-row-first' : '';

    if(isMe){
        var h = '<div class="sc-row sc-me'+firstCls+'" data-uid="'+escH(m.user_id)+'">';
        h += '<div class="sc-wrap-me">';
        h += '<div class="sc-inline">';
        if(isLast) h += '<span class="sc-time">'+timeStr+'</span>';
        h += _bubbleContent(m, 'sc-bub sc-bub-me'+(isFirst?' sc-tail-me':''));
        h += '</div></div></div>';
        return h;
    }

    var h = '<div class="sc-row sc-other'+firstCls+'" data-uid="'+escH(m.user_id)+'">';
    if(isFirst){
        if(photoUrl){
            h += '<img src="'+escH(photoUrl)+'" class="sc-avatar" onerror="this.outerHTML=\'<div class=\\\'sc-avatar-fallback\\\'><i class=\\\'fa fa-user\\\'></i></div>\'">';
        } else {
            h += '<div class="sc-avatar-fallback"><i class="fa fa-user"></i></div>';
        }
    } else {
        h += '<div class="sc-avatar-spacer"></div>';
    }
    h += '<div class="sc-wrap-other">';
    if(isFirst) h += '<span class="sc-name">'+escH(m.user_name||'')+'</span>';
    h += '<div class="sc-inline">';
    h += _bubbleContent(m, 'sc-bub sc-bub-other'+(isFirst?' sc-tail-other':''));
    if(isLast) h += '<span class="sc-time">'+timeStr+'</span>';
    h += '</div></div></div>';
    return h;
}

/* ══════════════════════════
   bubbleContent — 텍스트/이미지/파일 분기
   ══════════════════════════ */
function _bubbleContent(m, cls){
    var h = '';
    if(m.msg_type === 'image'){
        try {
            var fi = JSON.parse(m.comment);
            h += '<div class="'+cls+' sc-bub-img" onclick="SHV.chat._msgClick(event,'+m.idx+')" ondblclick="SHV.chat._dblClick(\'uploads/comment/'+escH(fi.server)+'\',\''+escH(fi.name||'')+'\')">'
                +'<img src="uploads/comment/'+escH(fi.server)+'" alt="'+escH(fi.name||'')+'" class="sc-img-thumb">'
                +'</div>';
        } catch(e){ h += '<div class="'+cls+'" onclick="SHV.chat._msgClick(event,'+m.idx+')">'+escH(m.comment||'')+'</div>'; }
    } else if(m.msg_type === 'file'){
        try {
            var fi2 = JSON.parse(m.comment);
            var sz = fi2.size < 1024*1024 ? (fi2.size/1024).toFixed(1)+'KB' : (fi2.size/1024/1024).toFixed(1)+'MB';
            h += '<div class="'+cls+' sc-bub-file" onclick="SHV.chat._msgClick(event,'+m.idx+')" ondblclick="SHV.chat._dblClick(\'uploads/comment/'+escH(fi2.server)+'\',\''+escH(fi2.name||'')+'\')">'
                +'<div class="sc-file-info"><i class="fa fa-file-o"></i><div><div class="sc-file-name">'+escH(fi2.name||'')+'</div><div class="sc-file-size">'+sz+'</div></div></div></div>';
        } catch(e){ h += '<div class="'+cls+'" onclick="SHV.chat._msgClick(event,'+m.idx+')">'+escH(m.comment||'')+'</div>'; }
    } else {
        h += '<div class="'+cls+'" onclick="SHV.chat._msgClick(event,'+m.idx+')">'+escH(m.comment||'').replace(/\n/g,'<br>')+'</div>';
    }
    return h;
}

/* ══════════════════════════
   send — 텍스트 전송 (낙관적 업데이트)
   ══════════════════════════ */
function _send(uid){
    var inst = _instances[uid]; if(!inst) return;
    var inp = document.getElementById('scInput_'+uid);
    var msg = (inp.value||'').trim();
    if(!msg) return;
    inp.value = '';

    var area = document.getElementById('scMsgs_'+uid);
    var user = getUser();
    var now = new Date();
    var dt = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')
        +' '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');

    /* 날짜 구분바 */
    var todayStr = dt.substring(0,10);
    var allBars = area.querySelectorAll('[data-scdate]');
    var hasToday = false;
    for(var b=0; b<allBars.length; b++){ if(allBars[b].getAttribute('data-scdate')===todayStr){ hasToday=true; break; } }
    if(!hasToday) area.insertAdjacentHTML('beforeend', _dateBar(todayStr));

    /* 그룹핑 */
    var lastRow = area.querySelector('.sc-row:last-child');
    var isFirst = true;
    var nowTime = _fmtTime(dt);
    if(lastRow && lastRow.classList.contains('sc-me')){
        var lastTime = lastRow.querySelector('.sc-time');
        if(lastTime && lastTime.textContent === nowTime){ isFirst=false; lastTime.remove(); }
    }

    area.insertAdjacentHTML('beforeend', _renderMsg({
        user_id:user.id, user_name:user.name, comment:msg, msg_type:'text', datetime:dt, idx:0
    }, user.id, isFirst, true));
    area.scrollTop = area.scrollHeight;

    /* API 전송 */
    var postData = { todo:'insert', to_table:inst.toTable, to_idx:inst.toIdx, comment:msg, msg_type:'text' };
    if(inst.pjtKey) postData.pjt_key = inst.pjtKey;
    SHV.api.post('dist_process/saas/Comment.php', postData)
        .then(function(res){ if(!res.ok && SHV.toast) SHV.toast.error(res.message||'전송 실패'); })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}
SHV.chat._send = _send;

/* ══════════════════════════
   keydown — Enter 전송 + IME 처리
   ══════════════════════════ */
SHV.chat._keydown = function(e, uid){
    if(e.key === 'Enter' && !e.isComposing){
        e.preventDefault();
        _send(uid);
    }
};

/* ══════════════════════════
   파일 전송
   ══════════════════════════ */
function _sendFile(uid, file){
    var inst = _instances[uid]; if(!inst) return;
    var fd = new FormData();
    fd.append('todo','insert');
    fd.append('to_table', inst.toTable);
    fd.append('to_idx', inst.toIdx);
    fd.append('comment','');
    fd.append('file', file);
    if(inst.pjtKey) fd.append('pjt_key', inst.pjtKey);

    SHV.api.upload('dist_process/saas/Comment.php', fd)
        .then(function(res){
            if(!res.ok){ if(SHV.toast) SHV.toast.error(res.message||'파일 전송 실패'); }
            else { _load(uid); }
        })
        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
}

SHV.chat._fileSend = function(uid, inputEl){
    if(!inputEl.files.length) return;
    for(var i=0; i<inputEl.files.length; i++) _sendFile(uid, inputEl.files[i]);
    inputEl.value = '';
};

/* ══════════════════════════
   메시지 클릭 → 삭제 버튼
   ══════════════════════════ */
SHV.chat._msgClick = function(e, idx){
    e.stopPropagation();
    if(_delBtn){ _delBtn.remove(); _delBtn=null; }
    if(!idx) return;
    var bub = e.currentTarget;
    var btn = document.createElement('button');
    btn.className = 'sc-del-btn';
    btn.innerHTML = '<i class="fa fa-trash"></i>';
    btn.onclick = function(ev){
        ev.stopPropagation();
        if(SHV.confirm){
            SHV.confirm({
                title:'삭제', message:'이 메시지를 삭제하시겠습니까?',
                type:'danger', confirmText:'삭제',
                onConfirm:function(){
                    SHV.api.post('dist_process/saas/Comment.php', {todo:'delete', idx:idx})
                        .then(function(res){
                            if(res.ok){
                                var row = bub.closest('.sc-row');
                                if(row) row.remove();
                                if(SHV.toast) SHV.toast.success('삭제');
                            } else {
                                if(SHV.toast) SHV.toast.error(res.message||'삭제 실패');
                            }
                        })
                        .catch(function(){ if(SHV.toast) SHV.toast.error('네트워크 오류'); });
                }
            });
        }
    };
    bub.appendChild(btn);
    _delBtn = btn;
};

/* 바깥 클릭 시 삭제 버튼 제거 */
document.addEventListener('click', function(){ if(_delBtn){ _delBtn.remove(); _delBtn=null; } });

/* ══════════════════════════
   더블클릭 → 이미지 모달 / 파일 다운로드
   ══════════════════════════ */
SHV.chat._dblClick = function(url, name){
    if(_delBtn){ _delBtn.remove(); _delBtn=null; }
    var ext = (name||url).split('.').pop().toLowerCase();
    var isImg = ['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) !== -1;

    if(isImg){
        var ov = document.createElement('div');
        ov.className = 'sc-img-modal';
        ov.onclick = function(e){ if(e.target===ov) ov.remove(); };
        var img = document.createElement('img');
        img.src = url;
        img.className = 'sc-img-modal-img';
        ov.appendChild(img);
        var bar = document.createElement('div');
        bar.className = 'sc-img-modal-bar';
        var fname = document.createElement('span');
        fname.className = 'sc-img-modal-name';
        fname.textContent = name||'';
        bar.appendChild(fname);
        var dl = document.createElement('button');
        dl.className = 'sc-img-modal-dl';
        dl.innerHTML = '<i class="fa fa-download mr-1"></i>다운로드';
        dl.onclick = function(){ var a=document.createElement('a'); a.href=url; a.download=name||''; a.click(); };
        bar.appendChild(dl);
        var cl = document.createElement('button');
        cl.className = 'sc-img-modal-close';
        cl.innerHTML = '<i class="fa fa-times"></i>';
        cl.onclick = function(){ ov.remove(); };
        bar.appendChild(cl);
        ov.appendChild(bar);
        document.body.appendChild(ov);
    } else {
        var a = document.createElement('a');
        a.href = url; a.download = name||''; a.click();
    }
};

/* ══════════════════════════
   유틸리티
   ══════════════════════════ */
function _getMin(m){ return (m.datetime||m.time||'').substring(0,16); }
function _getDate(m){ return (m.datetime||'').substring(0,10); }

function _dateBar(dateStr){
    var days = ['일','월','화','수','목','금','토'];
    var p = dateStr.split('-');
    if(p.length<3) return '';
    var d = new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    var label = p[0]+'년 '+parseInt(p[1])+'월 '+parseInt(p[2])+'일 '+days[d.getDay()]+'요일';
    return '<div class="sc-date-bar" data-scdate="'+dateStr+'"><span>'+label+'</span></div>';
}

function _fmtTime(dt){
    var p = dt.match(/(\d{1,2}):(\d{2})\s*$/);
    if(!p) return '';
    var h = parseInt(p[1]), mm = p[2];
    var ap = h<12 ? '오전' : '오후';
    var h12 = h%12; if(h12===0) h12=12;
    return ap+' '+h12+':'+mm;
}

})(window.SHV = window.SHV || {});
