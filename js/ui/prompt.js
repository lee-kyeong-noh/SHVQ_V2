/* ══════════════════════════════════════
   SHVQ V2 — 커스텀 Prompt 모달 (prompt.js)
   alert/confirm/prompt 금지 규칙 대체
   SHV.prompt(title, placeholder, callback, defaultVal)
   ══════════════════════════════════════ */
'use strict';
window.SHV = window.SHV || {};

(function(SHV){

SHV.prompt = function(title, placeholder, callback, defaultVal){
    var overlay = document.createElement('div');
    overlay.className = 'shv-prompt-overlay';

    var box = document.createElement('div');
    box.className = 'shv-prompt-box';

    box.innerHTML = '<div class="shv-prompt-title">' + escH(title || '입력') + '</div>'
        + '<input type="text" class="shv-prompt-input" id="shvPromptInput" placeholder="' + escH(placeholder || '') + '" value="' + escH(defaultVal || '') + '" autocomplete="off">'
        + '<div class="shv-prompt-btns">'
        + '<button class="shv-prompt-cancel" id="shvPromptCancel">취소</button>'
        + '<button class="shv-prompt-ok" id="shvPromptOk"><i class="fa fa-check mr-1"></i>확인</button>'
        + '</div>';

    overlay.appendChild(box);
    document.body.appendChild(overlay);

    var input = document.getElementById('shvPromptInput');
    var btnOk = document.getElementById('shvPromptOk');
    var btnCancel = document.getElementById('shvPromptCancel');

    function close(val){
        overlay.remove();
        if(typeof callback === 'function' && val !== null && val !== undefined){
            callback(val);
        }
    }

    btnOk.addEventListener('click', function(){
        var val = input.value.trim();
        if(!val) { input.focus(); return; }
        close(val);
    });

    btnCancel.addEventListener('click', function(){ close(null); });

    overlay.addEventListener('click', function(e){
        if(e.target === overlay) close(null);
    });

    input.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
            e.preventDefault();
            var val = input.value.trim();
            if(!val) return;
            close(val);
        }
        if(e.key === 'Escape') close(null);
    });

    setTimeout(function(){ input.focus(); input.select(); }, 50);
};

function escH(s){
    var d = document.createElement('div');
    d.textContent = String(s || '');
    return d.innerHTML;
}

})(window.SHV);
