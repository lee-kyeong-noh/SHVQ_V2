/* SHV WebCAD - UI (Save/Load) */

/* _CAD_API, _CAD_INIT injected by index.php */
var _A = window._CAD_API || 'index.php?api=';
var _I = window._CAD_INIT || [];
var _C = '';
window._cadUiState = window._cadUiState || {
  bound: false,
  modalDrag: null,
  floatDrag: null,
  panelResize: null
};

/** 삭제 확인 모달 (빨간 버튼) — _cadModal 팩토리 사용 */
function cadConfirm(message, onConfirm, onCancel){
  _cadModal({ message: message, okText: '삭제', okStyle: 'danger', onOk: onConfirm, onCancel: onCancel });
}

function _getSiteNo(){
  var el = document.getElementById('siteNo');
  var v = el ? el.textContent.trim() : '';
  return (v && v !== '미연결' && v !== '-') ? v : '';
}

function _AS(){
  var s = _getSiteNo();
  return _A + (s ? '&site_no=' + encodeURIComponent(s) : '');
}

function _cadBindOnce(el, key, eventName, handler, options){
  if(!el) return;
  var flag = 'cadBound' + key;
  if(el.dataset && el.dataset[flag] === '1') return;
  el.addEventListener(eventName, handler, options);
  if(el.dataset) el.dataset[flag] = '1';
}

function ppR(l){
  var el = document.getElementById('ppList');
  el.querySelectorAll('.ppItem').forEach(function(x){ x.remove(); });
  document.getElementById('ppEmpty').style.display = l.length ? 'none' : 'block';

  var blank = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
  l.forEach(function(d){
    var div = document.createElement('div');
    div.className = 'ppItem';
    div.innerHTML = '<img class="ppThumb" src="' + _esc(d.thumbnail || blank) + '" alt=""><div class="ppInfo"><div class="ppTitle">' + _esc(d.title) + '</div><div class="ppDate">' + _esc(d.updated_at) + '</div></div><button class="ppDel" data-id="' + _esc(d.id) + '">X</button>';
    div.addEventListener('click', function(e){
      if(e.target.classList.contains('ppDel')) return;
      ppL(d.id);
    });
    div.querySelector('.ppDel').addEventListener('click', function(e){
      e.stopPropagation();
      cadConfirm('도면을 삭제하시겠습니까?', function(){
        fetch(_AS() + 'delete&id=' + d.id).then(function(){ div.remove(); });
      });
    });
    el.appendChild(div);
  });
}

function ppL(id){
  fetch(_AS() + 'load&id=' + id)
    .then(function(r){ return r.json(); })
    .then(function(r){
      var d = r.drawing || (r.data && r.data.drawing);
      if(d && d.objects) objects = d.objects;
      if(d && d.layers) layers = d.layers;
      if(d && d.scale) scale = d.scale;
      if(d && d.unit) unit = d.unit;
      _C = r.id || (r.data && r.data.id) || '';
      document.getElementById('ppOverlay').style.display = 'none';
      renderLayerList();
      fitAll();
      updateStatus();
    });
}

function ppS(t){
  var th = canvas.toDataURL('image/png', 0.3);
  var sn = _getSiteNo();
  fetch(_AS() + 'save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      id: _C,
      title: t,
      drawing: {objects: objects, layers: layers, scale: scale, unit: unit},
      thumbnail: th,
      site_no: sn
    })
  }).then(function(r){ return r.json(); }).then(function(r){
    if(r.ok){
      _C = r.id;
      if(typeof clearUnsaved === 'function') clearUnsaved();
      if(typeof exportToServer === 'function') exportToServer(_C);
      fetch(_AS() + 'list').then(function(x){ return x.json(); }).then(function(listRes){
        if(listRes.ok) ppR(listRes.data);
      });
    }
  });
}

function _oSD(){
  document.getElementById('sdOverlay').style.display = 'flex';
  var i = document.getElementById('sdTitleInp');
  i.focus();
  i.select();
}

function _cSD(){
  document.getElementById('sdOverlay').style.display = 'none';
}

window.saveJSON = function(s){
  if(s === true) ppS(_C ? 'auto' : 'untitled');
  else _oSD();
};

window.openFile = function(){
  fetch(_AS() + 'list').then(function(r){ return r.json(); }).then(function(r){
    if(r.ok) ppR(r.data);
  });
  document.getElementById('ppOverlay').style.display = 'flex';
};

/* 모달 닫힐 때 위치 초기화 */
var _origCloseModal = window.closeModal;
if(_origCloseModal && !_origCloseModal._cadWrapped){
  window.closeModal = function(id){
    var overlay = document.getElementById(id);
    if(overlay){
      var modal = overlay.querySelector('.modal');
      if(modal){
        modal.style.position = '';
        modal.style.left = '';
        modal.style.top = '';
        modal.style.margin = '';
      }
    }
    _origCloseModal(id);
  };
  window.closeModal._cadWrapped = true;
}

/* 패널 팝아웃/도킹 */
function popoutPanel(type){
  if(type === 'comment'){
    var commentSrc = document.getElementById('commentContent');
    var commentWrap = document.getElementById('floatComment');
    var commentBody = document.getElementById('floatCommentBody');
    commentBody.innerHTML = '';
    commentBody.appendChild(commentSrc);
    commentWrap.style.display = 'flex';
    commentWrap.style.left = 'auto';
    commentWrap.style.right = '260px';
    commentWrap.style.top = '100px';
  } else if(type === 'version'){
    var versionSrc = document.getElementById('versionContent');
    var versionWrap = document.getElementById('floatVersion');
    var versionBody = document.getElementById('floatVersionBody');
    versionBody.innerHTML = '';
    versionBody.appendChild(versionSrc);
    versionWrap.style.display = 'flex';
    versionWrap.style.left = 'auto';
    versionWrap.style.right = '260px';
    versionWrap.style.top = '420px';
  }
}

function dockPanel(type){
  if(type === 'comment'){
    var commentSrc = document.getElementById('commentContent');
    var commentTarget = document.querySelector('#tab_saves .propGroup:first-of-type');
    if(!commentTarget) return;
    commentTarget.appendChild(commentSrc);
    document.getElementById('floatComment').style.display = 'none';
  } else if(type === 'version'){
    var versionSrc = document.getElementById('versionContent');
    var versionTarget = document.querySelector('#tab_saves .propGroup:last-of-type');
    if(!versionTarget) return;
    versionTarget.appendChild(versionSrc);
    document.getElementById('floatVersion').style.display = 'none';
  }
}

_cadBindOnce(document.getElementById('btnSdOk'), 'BtnSdOk', 'click', function(){
  var t = document.getElementById('sdTitleInp').value.trim() || 'untitled';
  var fmt = (document.getElementById('sdFormatSel') || {}).value || 'dwg';
  _cSD();
  if(fmt === 'dwg'){
    if(typeof saveDWG === 'function') saveDWG(t);
    else notify('DWG 저장 미지원', 'danger');
  } else if(fmt === 'dxf'){
    if(typeof saveDXF === 'function') saveDXF();
    else notify('DXF 저장 미지원', 'danger');
  } else {
    ppS(t);
  }
});

_cadBindOnce(document.getElementById('btnSdCancel'), 'BtnSdCancel', 'click', _cSD);
_cadBindOnce(document.getElementById('sdTitleInp'), 'SdTitleInp', 'keydown', function(e){
  if(e.key === 'Enter') document.getElementById('btnSdOk').click();
  if(e.key === 'Escape') _cSD();
});
_cadBindOnce(document.getElementById('btnPPNew'), 'BtnPPNew', 'click', function(){
  _C = '';
  objects = [];
  selectedIds = new Set();
  if(typeof initLayers === 'function') initLayers();
  document.getElementById('ppOverlay').style.display = 'none';
  if(typeof render === 'function') render();
  if(typeof updateStatus === 'function') updateStatus();
});
_cadBindOnce(document.getElementById('btnPPClose'), 'BtnPPClose', 'click', function(){
  document.getElementById('ppOverlay').style.display = 'none';
});

var _dwgInp = document.getElementById('dwgFileInput');
_cadBindOnce(_dwgInp, 'DwgInput', 'change', function(){
  if(this.files[0] && typeof importDWG === 'function'){
    importDWG(this.files[0]);
    document.getElementById('ppOverlay').style.display = 'none';
  }
  this.value = '';
});

var _btnDwg = document.getElementById('btnPPDwg');
_cadBindOnce(_btnDwg, 'BtnDwg', 'click', function(){
  if(_dwgInp){
    _dwgInp.value = '';
    _dwgInp.click();
  }
});


if(!window._cadUiState.bound){
  window._cadUiState.bound = true;

  document.addEventListener('keydown', function(e){
    if((e.ctrlKey || e.metaKey) && e.code === 'KeyS'){
      e.preventDefault();
      e.stopImmediatePropagation();
      _oSD();
    }
  }, true);

  document.addEventListener('click', function(e){
    var setBtn = e.target.closest('.setSideItem');
    if(setBtn){
      document.querySelectorAll('.setSideItem').forEach(function(b){ b.classList.remove('active'); });
      document.querySelectorAll('.setTabContent').forEach(function(c){ c.classList.remove('active'); });
      setBtn.classList.add('active');
      var setTarget = document.getElementById(setBtn.getAttribute('data-set-tab'));
      if(setTarget) setTarget.classList.add('active');
      return;
    }

    var ribBtn = e.target.closest('.ribTabBtn');
    if(ribBtn){
      document.querySelectorAll('.ribTabBtn').forEach(function(b){ b.classList.remove('active'); });
      document.querySelectorAll('.ribTabContent').forEach(function(c){ c.classList.remove('active'); });
      ribBtn.classList.add('active');
      var ribTarget = document.getElementById(ribBtn.getAttribute('data-ribtab'));
      if(ribTarget) ribTarget.classList.add('active');
    }
  });

  document.addEventListener('mousedown', function(e){
    var modalTitle = e.target.closest('.modalTitle');
    if(modalTitle){
      if(e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT') return;
      var modal = modalTitle.closest('.modal');
      if(!modal) return;
      var rect = modal.getBoundingClientRect();
      if(!modal.style.position || modal.style.position === ''){
        modal.style.position = 'fixed';
        modal.style.left = rect.left + 'px';
        modal.style.top = rect.top + 'px';
        modal.style.margin = '0';
      }
      window._cadUiState.modalDrag = {
        modal: modal,
        startX: e.clientX,
        startY: e.clientY,
        origX: parseInt(modal.style.left, 10) || rect.left,
        origY: parseInt(modal.style.top, 10) || rect.top
      };
      e.preventDefault();
      return;
    }

    var floatHead = e.target.closest('#floatCommentHead, #floatVersionHead');
    if(floatHead){
      if(e.target.tagName === 'BUTTON') return;
      var win = floatHead.parentElement;
      var fr = win.getBoundingClientRect();
      win.style.right = 'auto';
      window._cadUiState.floatDrag = {
        win: win,
        startX: e.clientX,
        startY: e.clientY,
        origX: fr.left,
        origY: fr.top
      };
      e.preventDefault();
      return;
    }

    var resizeHandle = e.target.closest('#rightPanelResize');
    if(resizeHandle){
      var panel = document.getElementById('rightPanel');
      if(!panel) return;
      window._cadUiState.panelResize = {
        panel: panel,
        startX: e.clientX,
        startW: panel.offsetWidth
      };
      document.body.style.cursor = 'col-resize';
      e.preventDefault();
    }
  });

  document.addEventListener('mousemove', function(e){
    var modalDrag = window._cadUiState.modalDrag;
    if(modalDrag){
      modalDrag.modal.style.left = (modalDrag.origX + e.clientX - modalDrag.startX) + 'px';
      modalDrag.modal.style.top = (modalDrag.origY + e.clientY - modalDrag.startY) + 'px';
    }

    var floatDrag = window._cadUiState.floatDrag;
    if(floatDrag){
      floatDrag.win.style.left = (floatDrag.origX + e.clientX - floatDrag.startX) + 'px';
      floatDrag.win.style.top = (floatDrag.origY + e.clientY - floatDrag.startY) + 'px';
    }

    var panelResize = window._cadUiState.panelResize;
    if(panelResize){
      var newW = panelResize.startW - (e.clientX - panelResize.startX);
      if(newW < 150) newW = 150;
      if(newW > 500) newW = 500;
      panelResize.panel.style.width = newW + 'px';
    }
  });

  document.addEventListener('mouseup', function(){
    window._cadUiState.modalDrag = null;
    window._cadUiState.floatDrag = null;
    if(window._cadUiState.panelResize){
      window._cadUiState.panelResize = null;
      document.body.style.cursor = '';
    }
  });
}
