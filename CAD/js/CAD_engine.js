// ── XSS 방지: HTML 이스케이프 유틸리티 ──
function _esc(str){
  if(str==null) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
window._esc = _esc;

// ── CSRF: 모든 fetch에 자동 토큰 주입 ──
(function(){
  var _origFetch = window.fetch;
  window.fetch = function(url, opts){
    opts = opts || {};
    var token = window._CAD_CSRF || '';
    if(token){
      opts.headers = opts.headers || {};
      // Headers 객체면 set, 일반 객체면 직접 할당
      if(opts.headers instanceof Headers){
        if(!opts.headers.has('X-CSRF-Token')) opts.headers.set('X-CSRF-Token', token);
      } else {
        if(!opts.headers['X-CSRF-Token']) opts.headers['X-CSRF-Token'] = token;
      }
    }
    return _origFetch.call(window, url, opts);
  };
})();

// ── 이미지 캐시 맵 (bgimage 로딩 최적화)
const _imgCache = new Map();
function getCachedImg(src, callback){
  if(_imgCache.has(src)){ callback(_imgCache.get(src)); return; }
  const img = new Image();
  img.onload = ()=>{ _imgCache.set(src, img); callback(img); };
  img.src = src;
}
/* SHV WebCAD - CAD Engine */


// ═══════════════════════════════════════════
//  SHV WebCAD – Core Engine
// ═══════════════════════════════════════════

const canvas = document.getElementById('mainCanvas');
const ctx = canvas.getContext('2d');
const ovCanvas = document.getElementById('overlayCanvas');
const ovCtx = ovCanvas.getContext('2d');
const wrap = document.getElementById('canvasWrap');

// State
let objects = [];
let layers = [];
let currentLayer = 0;
let selectedIds = new Set();
let tool = 'select';
let lastTool = 'select';
let scale = 1; // 1:1
let unit = 'mm';
let gridSize = 20;
let snapSize = 10;
let showGrid = true;
let snapOn = true;
let orthoOn = false;
let dimFontSize = 12;
let arrowSize = 10;

// View transform
let viewX = 0, viewY = 0, viewZoom = 1;

// Drawing state
let isDrawing = false;
let drawStart = null;
let polyPoints = [];
let dimStep = 0, dimP1 = null, dimP2 = null;
let pendingOffset = null;
let textPos = null;
let bgImage = null, bgAlpha = 0.5, bgScaleVal = 1;

// Undo/Redo
let history = [], histIdx = -1;
const MAX_HIST = 50;
const MAX_VER = 10;
let versions = [];

// Clipboard
let clipboard = [];

// Auto-save
let autoSaveTimer = null;

// Drag/select
let dragStart = null, isDragging = false, dragOffsets = [];
let selBoxStart = null, selBoxEnd = null;

// Print region
let printMode = null, printStart = null, printEnd = null;

let objectId = 1;
function nextId(){ return objectId++ }

let dynTipText = '';
function updateDynTip(txt){ dynTipText = txt; }

/** 통합 모달 팩토리 — confirm/prompt 공용
 * @param {object} cfg - { message, okText, okStyle, input, defaultVal, onOk, onCancel }
 */
function _cadModal(cfg){
  var existing = document.getElementById('cadModalOverlay');
  if(existing) existing.remove();
  var ov = document.createElement('div');
  ov.id = 'cadModalOverlay';
  ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:100001';
  var box = document.createElement('div');
  box.style.cssText = 'min-width:300px;max-width:90vw;padding:16px;border-radius:10px;background:#111a28;border:1px solid #29435f;color:#d8e6f6;box-shadow:0 14px 28px rgba(0,0,0,0.4)';
  var txt = document.createElement('div');
  txt.textContent = cfg.message || '확인하시겠습니까?';
  txt.style.cssText = 'font-size:13px;line-height:1.5;margin-bottom:'+(cfg.input?'10':'14')+'px;';
  box.appendChild(txt);
  var inp = null;
  if(cfg.input){
    inp = document.createElement('input');
    inp.type = 'text';
    inp.value = cfg.defaultVal != null ? String(cfg.defaultVal) : '';
    inp.style.cssText = 'width:100%;padding:8px 10px;background:#0d1828;border:1px solid #29435f;border-radius:6px;color:#d0e8ff;font-size:14px;outline:none;box-sizing:border-box;margin-bottom:14px;';
    box.appendChild(inp);
  }
  var acts = document.createElement('div');
  acts.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;';
  var btnC = document.createElement('button');
  btnC.type = 'button'; btnC.textContent = '취소';
  btnC.style.cssText = 'background:#2a3a4d;border:1px solid #35516f;color:#cfe0f3;padding:6px 12px;border-radius:6px;cursor:pointer;';
  var btnO = document.createElement('button');
  btnO.type = 'button'; btnO.textContent = cfg.okText || '확인';
  var okBg = cfg.okStyle === 'danger' ? '#d54665' : '#0077cc';
  var okBd = cfg.okStyle === 'danger' ? '#e4637d' : '#1590e2';
  btnO.style.cssText = 'background:'+okBg+';border:1px solid '+okBd+';color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;';
  function close(){ ov.remove(); }
  function cancel(){ close(); if(typeof cfg.onCancel==='function') cfg.onCancel(); }
  function ok(){ var v = inp ? inp.value : undefined; close(); if(typeof cfg.onOk==='function') cfg.onOk(v); }
  btnC.addEventListener('click', cancel);
  btnO.addEventListener('click', ok);
  if(inp) inp.addEventListener('keydown', function(e){ if(e.key==='Enter') ok(); if(e.key==='Escape') cancel(); });
  ov.addEventListener('click', function(e){ if(e.target===ov) cancel(); });
  acts.appendChild(btnC); acts.appendChild(btnO);
  box.appendChild(acts); ov.appendChild(box);
  document.body.appendChild(ov);
  if(inp) setTimeout(function(){ inp.focus(); inp.select(); }, 50);
}

/** confirm 모달 (확인/취소) */
function cadAskConfirm(message, onOk, onCancel){
  _cadModal({ message: message, okText: '확인', onOk: onOk, onCancel: onCancel });
}

/** prompt 모달 (입력 + 확인/취소) */
function cadPrompt(message, defaultVal, onOk, onCancel){
  _cadModal({ message: message, input: true, defaultVal: defaultVal, onOk: onOk, onCancel: onCancel });
}

// Dynamic tip tool labels
const TOOL_HINT = {
  select:'\uC120\uD0DD',
  line:'\uC120 [\uD074\uB9AD:\uC2DC\uC791\uC810 → \uB05D\uC810]',
  wall:'\uBCBD\uCCB4 [\uD074\uB9AD:\uC2DC\uC791 → \uB05D]',
  rect:'\uC0AC\uAC01\uD615 [\uD074\uB9AD:\uC2DC\uC791 → \uB05D]',
  circle:'\uC6D0 [\uD074\uB9AD:\uC911\uC2EC → \uBC18\uC9C0\uB984]',
  polyline:'\uD3F4\uB9AC\uC120 [\uD074\uB9AD:\uC810\uCD94\uAC00 | Enter/\uC6B0\uD074\uB9AD:\uC644\uB8CC]',
  offset:'\uC624\uD504\uC14B [\uAC1D\uCCB4 \uD074\uB9AD]',
  dim:'\uCE58\uC218 [P1 → P2 → \uC704\uCE58]',
  text:'\uD14D\uC2A4\uD2B8 [\uD074\uB9AD:\uC704\uCE58]',
  annot:'\uC8FC\uC11D [\uB300\uC0C1 \uAC1D\uCCB4 \uD074\uB9AD → \uB9D0\uD48D\uC120 \uC704\uCE58 \uD074\uB9AD]',
  move:'\uC774\uB3D9 [\uAE30\uC900\uC810 → \uBAA9\uC801\uC9C0]',
  copyMove:'\uBCF5\uC0AC\uC774\uB3D9 [\uAE30\uC900\uC810 → \uBAA9\uC801\uC9C0]',
  trim:'\uD2B8\uB9BC [Enter:\uC804\uCCB4 \uACBD\uACC4 → \uC798\uB77C\uB0BC \uBD80\uBD84 \uD074\uB9AD]',
  extend:'\uC5F0\uC7A5 [\uC5F0\uC7A5\uD560 \uC120 \uD074\uB9AD]',
};
let moveBasePoint = null, moveStep = 0, scaleRefDistance = 0; // for move/copyMove/scale tool
let trimStep = 0, trimEdges = []; // 0=경계선택, 1=잘라내기
let _trimDragUndo = false;
let _trimMouseDown = false;
let _trimDragPath = []; // 드래그 궤적
let annotStep = 0, annotArrowTo = null; // for annot tool: 0=대상클릭, 1=위치클릭
let textStep = 0, textOrigin = null, textFontSize = 14, textAngle = 0; // text tool steps
let rotateStep = 0, rotateBase = null, rotateRefAngle = 0; // rotate tool
let mirrorStep = 0, mirrorP1 = null, mirrorP2 = null; // mirror tool
let cmdBuffer = ''; // for 2-char commands like CO
let cmdTimer = null;

// Default style
let curStyle = {
  color: '#ffffff',
  lineWidth: 1,
  lineDash: 'solid',
  lineCap: 'butt',
  fill: false,
  fillColor: '#ffffff',
  alpha: 1,
  wallWidth: 200
};

// ── Layers ──────────────────────────────────
const DEFAULT_LAYERS = [
  {id:0, name:'\uBCBD\uCCB4',  color:'#ffffff', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''},
  {id:1, name:'\uCC3D\uD638',  color:'#88ccff', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''},
  {id:2, name:'\uAC00\uAD6C',  color:'#ffcc88', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''},
  {id:3, name:'\uC804\uAE30',  color:'#ffff66', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''},
  {id:4, name:'\uCE58\uC218',  color:'#88ff88', visible:true, locked:false, lineWidth:0.8, lineDash:'solid', group:''},
  {id:5, name:'\uD14D\uC2A4\uD2B8',color:'#ffffff', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''},
];

function initLayers(){
  layers = DEFAULT_LAYERS.map(l=>({...l}));
  renderLayerList();
}

function renderLayerList(){
  const el = document.getElementById('layerList');
  el.innerHTML = '';

  // 그룹별 정리
  const groups = {};
  layers.forEach((l,i)=>{
    const g = l.group || '';
    if(!groups[g]) groups[g] = [];
    groups[g].push({layer:l, idx:i});
  });

  Object.keys(groups).sort().forEach(gName => {
    // 그룹 헤더
    if(gName){
      const gh = document.createElement('div');
      gh.className = 'layerGroupHeader';
      gh.innerHTML = `<span class="layerGroupName">📁 ${_esc(gName)}</span>`;
      el.appendChild(gh);
    }

    groups[gName].forEach(({layer:l, idx:i}) => {
      const d = document.createElement('div');
      d.className = 'layerItem' + (i===currentLayer?' active':'');
      d.dataset.layerIdx = i;
      d.draggable = true;

      const dashLabel = {solid:'실선',dashed:'파선',dotted:'점선',dashdot:'일점쇄선'}[l.lineDash||'solid']||'실선';

      d.innerHTML = `
        <div class="layerDrag" title="드래그로 순서 변경">⠿</div>
        <div class="layerColor" style="background:${_esc(l.color)}" data-fn="pickLayerColor" data-arg="${i}" title="색상 변경"></div>
        <div class="layerName"><span data-dblclick="renameLayer">${_esc(l.name)}</span></div>
        <span class="layerStyle" data-fn="editLayerStyle" data-arg="${i}" title="${_esc(dashLabel)} / ${l.lineWidth||1}px">━</span>
        <span class="layerIco ${l.visible?'on':''}" data-fn="toggleLayerVis" data-arg="${i}" title="표시/숨김">${l.visible?'👁':'🚫'}</span>
        <span class="layerIco ${l.locked?'on':''}" data-fn="toggleLayerLock" data-arg="${i}" title="잠금">${l.locked?'🔒':'🔓'}</span>
        <span class="layerIco" data-fn="deleteLayer" data-arg="${i}" title="삭제">🗑</span>
      `;

      d.addEventListener('click', (e)=>{
        if(e.target.dataset.fn || e.target.closest('[data-fn]') || e.target.closest('[data-dblclick]')) return;
        currentLayer=i; renderLayerList(); updateStatus();
      });

      // 드래그 정렬
      d.addEventListener('dragstart', (e)=>{
        e.dataTransfer.setData('text/plain', i);
        d.style.opacity='0.5';
      });
      d.addEventListener('dragend', ()=>{ d.style.opacity='1'; });
      d.addEventListener('dragover', (e)=>{ e.preventDefault(); d.style.borderTop='2px solid #00aaff'; });
      d.addEventListener('dragleave', ()=>{ d.style.borderTop=''; });
      d.addEventListener('drop', (e)=>{
        e.preventDefault();
        d.style.borderTop='';
        const from = parseInt(e.dataTransfer.getData('text/plain'));
        const to = i;
        if(from===to) return;
        const moved = layers.splice(from,1)[0];
        layers.splice(to,0,moved);
        if(currentLayer===from) currentLayer=to;
        else if(from<currentLayer && to>=currentLayer) currentLayer--;
        else if(from>currentLayer && to<=currentLayer) currentLayer++;
        refreshUI({layers:true});
      });

      el.appendChild(d);
    });
  });
}

function editLayerStyle(i, e){
  if(e) e.stopPropagation();
  const l = layers[i];
  const dashOpts = ['solid','dashed','dotted','dashdot'];
  // 1단계: 선 두께
  cadPrompt('선 두께 (현재: '+(l.lineWidth||1)+')', l.lineWidth||1, function(lw){
    l.lineWidth = parseFloat(lw)||1;
    // 2단계: 선 종류
    const curIdx = dashOpts.indexOf(l.lineDash||'solid');
    cadPrompt('선 종류 (0:실선 1:파선 2:점선 3:일점쇄선, 현재: '+curIdx+')', curIdx, function(dashVal){
      const di = parseInt(dashVal);
      if(di>=0 && di<dashOpts.length) l.lineDash = dashOpts[di];
      // 3단계: 그룹 이름
      cadPrompt('그룹 이름 (비우면 그룹 없음, 현재: '+(l.group||'없음')+')', l.group||'', function(grp){
        l.group = grp.trim();
        refreshUI({layers:true});
      }, function(){ refreshUI({layers:true}); });
    }, function(){ refreshUI({layers:true}); });
  });
}

function moveLayerUp(i){
  if(i<=0) return;
  [layers[i-1],layers[i]] = [layers[i],layers[i-1]];
  if(currentLayer===i) currentLayer--;
  else if(currentLayer===i-1) currentLayer++;
  refreshUI({layers:true});
}
function moveLayerDown(i){
  if(i>=layers.length-1) return;
  [layers[i],layers[i+1]] = [layers[i+1],layers[i]];
  if(currentLayer===i) currentLayer++;
  else if(currentLayer===i+1) currentLayer--;
  refreshUI({layers:true});
}

function addLayer(){
  cadPrompt('레이어 이름:', '레이어'+(layers.length+1), function(n){
    if(!n) return;
    layers.push({id:Date.now(), name:n, color:'#aaaaaa', visible:true, locked:false, lineWidth:1, lineDash:'solid', group:''});
    currentLayer = layers.length-1;
    renderLayerList();
  });
}

function toggleLayerVis(i){ layers[i].visible=!layers[i].visible; refreshUI({layers:true}); }
function toggleLayerLock(i){ layers[i].locked=!layers[i].locked; renderLayerList(); }
function deleteLayer(i){
  if(layers.length<=1){ notify('\uCD5C\uC18C 1\uAC1C \uB808\uC774\uC5B4 \uD544\uC694'); return; }
  cadAskConfirm(
    `\uB808\uC774\uC5B4 "${layers[i].name}" \uC0AD\uC81C? (\uD3EC\uD568 \uAC1D\uCCB4\uB3C4 \uC0AD\uC81C\uB429\uB2C8\uB2E4)`,
    function(){
      objects = objects.filter(o=>o.layerId!==layers[i].id);
      layers.splice(i,1);
      if(currentLayer>=layers.length) currentLayer=layers.length-1;
      refreshUI({layers:true});
    }
  );
}
function renameLayer(i, el){
  const inp = document.createElement('input');
  inp.value = layers[i].name;
  inp.className = '';
  inp.style.cssText='background:none;border:none;color:#d0e8ff;font-size:12px;width:100%;outline:none';
  el.replaceWith(inp);
  inp.focus(); inp.select();
  inp.onblur = ()=>{ layers[i].name=inp.value||layers[i].name; renderLayerList(); };
  inp.onkeydown = e=>{ if(e.key==='Enter') inp.blur(); };
}
function pickLayerColor(i,e){
  e.stopPropagation();
  const inp = document.createElement('input');
  inp.type='color'; inp.value=layers[i].color;
  inp.onchange = ()=>{ layers[i].color=inp.value; refreshUI({layers:true}); };
  inp.click();
}

// ── Resize Canvas ────────────────────────────
function resizeCanvas(){
  const w = wrap.clientWidth, h = wrap.clientHeight;
  canvas.width = w; canvas.height = h;
  ovCanvas.width = w; ovCanvas.height = h;
  render();
}

// ── 인라인 텍스트 에디터 (CAD 스타일) ─────────────
let _inlineEditor = null;
function showInlineTextEditor(screenX, screenY, fontSize, color, initialText, callback){
  removeInlineTextEditor();
  const canvas = document.getElementById('mainCanvas');
  const rect = canvas.getBoundingClientRect();
  const ta = document.createElement('textarea');
  ta.value = initialText || '';
  const scaledFont = Math.max(12, fontSize * viewZoom);
  ta.style.cssText = `position:fixed;left:${rect.left+screenX}px;top:${rect.top+screenY}px;
    font-size:${scaledFont}px;font-family:Arial,sans-serif;color:${color||'#ffffff'};
    background:rgba(0,0,0,0.85);border:1px solid #ffff00;outline:none;padding:2px 4px;
    min-width:60px;min-height:${scaledFont+8}px;resize:none;z-index:9999;
    white-space:pre;overflow:hidden;line-height:1.2;box-shadow:0 0 8px rgba(255,255,0,0.3);`;
  // 자동 크기 조절
  function autoResize(){
    ta.style.height='auto'; ta.style.width='auto';
    ta.style.height=(ta.scrollHeight)+'px';
    ta.style.width=Math.max(60,ta.scrollWidth+10)+'px';
  }
  ta.addEventListener('input', autoResize);
  ta.addEventListener('keydown', function(e){
    if(e.key==='Escape'){ removeInlineTextEditor(); e.stopPropagation(); }
    else if(e.key==='Enter' && !e.shiftKey){
      e.preventDefault(); e.stopPropagation();
      var val = ta.value.trim();
      removeInlineTextEditor();
      if(val && callback) callback(val);
    }
  });
  document.body.appendChild(ta);
  _inlineEditor = ta;
  setTimeout(()=>{ ta.focus(); autoResize(); }, 10);
}
function removeInlineTextEditor(){
  if(_inlineEditor){ _inlineEditor.remove(); _inlineEditor=null; }
}

// ── Coordinate helpers ───────────────────────
function toWorld(cx,cy){ return { x:(cx-viewX)/viewZoom, y:(cy-viewY)/viewZoom }; }
function toScreen(wx,wy){ return { x:wx*viewZoom+viewX, y:wy*viewZoom+viewY }; }

var gridSnapOn = false; // 그리드 스냅 ON/OFF (기본 OFF — 객체 스냅만 활성)
function snapPoint(wx, wy){
  if(!snapOn || !gridSnapOn) return {x:wx, y:wy};
  const g = gridSize;
  return { x: Math.round(wx/g)*g, y: Math.round(wy/g)*g };
}

function orthoPoint(base, pt){
  if(!orthoOn) return pt;
  const dx = Math.abs(pt.x-base.x), dy = Math.abs(pt.y-base.y);
  if(dx>=dy) return {x:pt.x, y:base.y};
  return {x:base.x, y:pt.y};
}

// ── Render ───────────────────────────────────
let _renderDirty=false;
let _rafId=null;
let _overlayDirty=false;
let _overlayRafId=null;
function requestRender(){
  if(_renderDirty) return;
  _renderDirty=true;
  if(_rafId) cancelAnimationFrame(_rafId);
  _rafId=requestAnimationFrame(function(){
    _rafId=null;
    _renderDirty=false;
    _doRender();
  });
}
function requestOverlay(){
  if(_overlayDirty) return;
  _overlayDirty=true;
  if(_overlayRafId) cancelAnimationFrame(_overlayRafId);
  _overlayRafId=requestAnimationFrame(function(){
    _overlayRafId=null;
    _overlayDirty=false;
    _doRenderOverlay();
  });
}
function render(){ requestRender(); }
function renderOverlay(){ requestOverlay(); }
function refreshUI(options){
  options = options || {};
  if(options.layers) renderLayerList();
  render();
  if(options.status) updateStatus();
  if(options.props && typeof syncPropsFromSelection === 'function') syncPropsFromSelection();
}
function _doRender(){
  const W=canvas.width, H=canvas.height;
  ctx.clearRect(0,0,W,H);

  // BG - \uAC80\uC815 (\uCE90\uB4DC \uC2A4\uD0C0\uC77C)
  ctx.fillStyle='#000000';
  ctx.fillRect(0,0,W,H);

  // Grid
  if(showGrid){
    const gs = gridSize * viewZoom;
    if(gs>5){
      ctx.strokeStyle='rgba(255,255,255,0.06)';
      ctx.lineWidth=1;
      const ox = ((viewX%gs)+gs)%gs, oy = ((viewY%gs)+gs)%gs;
      ctx.beginPath();
      for(let x=ox;x<W;x+=gs){ ctx.moveTo(x,0); ctx.lineTo(x,H); }
      for(let y=oy;y<H;y+=gs){ ctx.moveTo(0,y); ctx.lineTo(W,y); }
      ctx.stroke();
    }
    // Major grid
    const mg = gridSize*5*viewZoom;
    if(mg>10){
      ctx.strokeStyle='rgba(255,255,255,0.12)';
      const ox2 = ((viewX%mg)+mg)%mg, oy2 = ((viewY%mg)+mg)%mg;
      ctx.beginPath();
      for(let x=ox2;x<W;x+=mg){ ctx.moveTo(x,0); ctx.lineTo(x,H); }
      for(let y=oy2;y<H;y+=mg){ ctx.moveTo(0,y); ctx.lineTo(W,y); }
      ctx.stroke();
    }
  }

  // Background image
  if(bgImage){
    ctx.save();
    ctx.globalAlpha = bgAlpha;
    const sw = bgImage.width*bgScaleVal*viewZoom;
    const sh = bgImage.height*bgScaleVal*viewZoom;
    ctx.drawImage(bgImage, viewX, viewY, sw, sh);
    ctx.restore();
  }

  // Objects
  ctx.save();
  ctx.translate(viewX, viewY);
  ctx.scale(viewZoom, viewZoom);

  for(let i=0;i<objects.length;i++){
    const o = objects[i];
    const layer = layers.find(l=>l.id===o.layerId);
    if(layer && !layer.visible) continue;
    drawObject(ctx, o, selectedIds.has(o.id));
  }

  // Scale bar
  // Scale bar removed

  ctx.restore();

  // ── UCS 아이콘 (좌하단 고정) ──────────────────
  drawUCSIcon(ctx, canvas.width, canvas.height);

  // Overlay는 본 렌더 프레임과 같은 tick에서 즉시 갱신
  if(_overlayRafId){
    cancelAnimationFrame(_overlayRafId);
    _overlayRafId=null;
    _overlayDirty=false;
  }
  _doRenderOverlay();
}

function setLineDash(c, style){
  const s = 1/viewZoom; // 줌아웃 시 대시 패턴 최소 크기 보정
  if(style==='dashed') c.setLineDash([Math.max(10,10*s),Math.max(5,5*s)]);
  else if(style==='dotted') c.setLineDash([Math.max(2,2*s),Math.max(5,5*s)]);
  else if(style==='dashdot') c.setLineDash([Math.max(10,10*s),Math.max(4,4*s),Math.max(2,2*s),Math.max(4,4*s)]);
  else if(style==='dashdotdot') c.setLineDash([Math.max(10,10*s),Math.max(3,3*s),Math.max(2,2*s),Math.max(3,3*s),Math.max(2,2*s),Math.max(3,3*s)]);
  else c.setLineDash([]);
}

function drawObject(c, o, sel){
  const layer = layers.find(l=>l.id===o.layerId);
  const col = o.color || (layer?layer.color:'#ffffff');
  c.save();
  c.globalAlpha = o.alpha||1;
  c.strokeStyle = sel ? '#ffff00' : col;
  c.fillStyle = o.fillColor||'#ffffff';
  c.lineWidth = (o.lineWidth||1) / viewZoom;
  c.lineCap = o.lineCap||'butt';
  setLineDash(c, o.lineDash||'solid');

  if(o.type==='line'){
    c.beginPath();
    c.moveTo(o.x1,o.y1); c.lineTo(o.x2,o.y2);
    c.stroke();
  }
  else if(o.type==='wall'){
    drawWall(c, o, sel);
  }
  else if(o.type==='rect'){
    if(o.fill){ c.fillStyle=o.fillColor; c.fillRect(o.x,o.y,o.w,o.h); }
    c.strokeRect(o.x,o.y,o.w,o.h);
  }
  else if(o.type==='circle'){
    c.beginPath();
    c.arc(o.cx,o.cy,o.r,0,Math.PI*2);
    if(o.fill) c.fill();
    c.stroke();
  }
  else if(o.type==='polyline'){
    if(o.points.length<2){ c.restore(); return; }
    c.beginPath();
    c.moveTo(o.points[0].x, o.points[0].y);
    for(let i=1;i<o.points.length;i++) c.lineTo(o.points[i].x, o.points[i].y);
    if(o.closed){ c.closePath(); if(o.fill) c.fill(); }
    c.stroke();
    // vertex handles if selected
    if(sel){
      o.points.forEach(p=>{
        c.fillStyle='#ffff00';
        c.fillRect(p.x-4,p.y-4,8,8);
      });
    }
  }
  else if(o.type==='dim'){
    drawDim(c, o, sel);
  }
  else if(o.type==='text'){
    c.fillStyle = sel?'#ffff00':col;
    c.font = `${o.fontSize||14}px Noto Sans KR`;
    if(o.angle){
      c.save();
      c.translate(o.x, o.y);
      c.rotate(-o.angle);
      c.fillText(o.text||'', 0, 0);
      c.restore();
    } else {
      c.fillText(o.text||'', o.x, o.y);
    }
  }
  else if(o.type==='annot'){
    drawAnnot(c, o, sel);
  }
  else if(o.type==='table'){
    drawTable(c, o, sel);
  }
  else if(o.type==='bgimage'){
    c.globalAlpha=(o.alpha||0.5);
    const _cachedImg=_imgCache.get(o.src);
    if(_cachedImg){
      c.drawImage(_cachedImg, o.x, o.y, o.w, o.h);
    } else {
      getCachedImg(o.src, ()=>requestRender());
    }
    if(sel){
      c.globalAlpha=1;
      c.strokeStyle='#ffff00';
      c.lineWidth=1/viewZoom;
      c.setLineDash([]);
      c.strokeRect(o.x, o.y, o.w, o.h);
      // 핸들
      [[o.x,o.y],[o.x+o.w,o.y],[o.x,o.y+o.h],[o.x+o.w,o.y+o.h]].forEach(([hx,hy])=>{
        c.fillStyle='#ffff00'; c.fillRect(hx-4/viewZoom,hy-4/viewZoom,8/viewZoom,8/viewZoom);
      });
    }
  }
  else if(o.type==='block'&&o.children){
    // 블록: children 순회하며 그리기
    o.children.forEach(function(child){ drawObject(c, child, false); });
    // 선택 시 바운딩 박스 점선
    if(sel){
      var bb=getBounds(o);
      if(bb){
        c.strokeStyle='#ffff00'; c.lineWidth=1.5/viewZoom; c.setLineDash([6/viewZoom,4/viewZoom]);
        c.strokeRect(bb.x,bb.y,bb.w,bb.h);
        c.setLineDash([]);
        // 블록 이름 표시
        c.fillStyle='rgba(255,255,0,0.8)'; c.font=(11/viewZoom)+'px Noto Sans KR';
        c.fillText('⊞ '+(o.name||'블록'),bb.x,bb.y-4/viewZoom);
      }
    }
  }

  // Lock indicator
  if(o.locked){
    c.fillStyle='rgba(255,170,0,0.7)';
    c.font='10px sans-serif';
    const bb = getBounds(o);
    if(bb) c.fillText('🔒', bb.x+2, bb.y-2);
  }

  c.restore();
}

function drawWall(c, o, sel){
  const dx=o.x2-o.x1, dy=o.y2-o.y1;
  const len=Math.sqrt(dx*dx+dy*dy);
  if(len<1) return;
  const nx=-dy/len, ny=dx/len;
  const hw=(o.wallWidth||200)/2;
  c.beginPath();
  c.moveTo(o.x1+nx*hw, o.y1+ny*hw);
  c.lineTo(o.x2+nx*hw, o.y2+ny*hw);
  c.lineTo(o.x2-nx*hw, o.y2-ny*hw);
  c.lineTo(o.x1-nx*hw, o.y1-ny*hw);
  c.closePath();
  if(o.fill){ c.fillStyle=o.fillColor||'#334455'; c.fill(); }
  else{ c.fillStyle='rgba(40,70,100,0.3)'; c.fill(); }
  c.stroke();
}

function drawDim(c, o, sel){
  const col = sel?'#ffff00':(o.color||'#88ff88');
  c.strokeStyle=col; c.fillStyle=col;
  c.lineWidth=0.8;
  c.setLineDash([]);
  const fs = dimFontSize;
  const as = arrowSize;
  if(o.dimType==='linear'){
    const {x1,y1,x2,y2,offset:off=30}=o;
    const angle = Math.atan2(y2-y1,x2-x1);
    const nx=Math.sin(angle), ny=-Math.cos(angle);
    const ex1={x:x1+nx*off,y:y1+ny*off};
    const ex2={x:x2+nx*off,y:y2+ny*off};
    // Ext lines
    c.beginPath();
    c.moveTo(x1,y1); c.lineTo(ex1.x,ex1.y);
    c.moveTo(x2,y2); c.lineTo(ex2.x,ex2.y);
    c.stroke();
    // Dim line
    c.beginPath();
    c.moveTo(ex1.x,ex1.y); c.lineTo(ex2.x,ex2.y);
    c.stroke();
    // Arrows
    drawArrow(c,ex1.x,ex1.y,angle,as);
    drawArrow(c,ex2.x,ex2.y,angle+Math.PI,as);
    // Text
    const mx=(ex1.x+ex2.x)/2, my=(ex1.y+ex2.y)/2;
    const dist = Math.sqrt((x2-x1)**2+(y2-y1)**2);
    const label = o.customText!=null ? o.customText : formatDim(dist);
    c.save();
    c.translate(mx,my);
    c.rotate(angle);
    c.font=`${fs}px Share Tech Mono`;
    c.textAlign='center';
    c.fillText(label,0,-4);
    c.restore();
  }
  else if(o.dimType==='radius'){
    const {cx,cy,r}=o;
    c.beginPath(); c.moveTo(cx,cy); c.lineTo(cx+r,cy); c.stroke();
    drawArrow(c,cx+r,cy,0,as);
    const rLabel = o.customText!=null ? o.customText : 'R'+formatDim(r);
    c.font=`${fs}px Share Tech Mono`;
    c.fillText(rLabel, cx+r/2, cy-6);
  }
  else if(o.dimType==='angle'){
    const {cx,cy,r=40,a1,a2}=o;
    c.beginPath(); c.arc(cx,cy,r,a1,a2); c.stroke();
    const am=(a1+a2)/2;
    const deg = Math.abs((a2-a1)*180/Math.PI).toFixed(1);
    const aLabel = o.customText!=null ? o.customText : deg+'°';
    c.font=`${fs}px Share Tech Mono`;
    c.fillText(aLabel, cx+r*Math.cos(am)-10, cy+r*Math.sin(am)-4);
  }
}

function drawArrow(c,x,y,angle,size){
  c.save();
  c.translate(x,y); c.rotate(angle);
  c.beginPath();
  c.moveTo(0,0); c.lineTo(-size,-size/3); c.lineTo(-size,size/3); c.closePath();
  c.fill();
  c.restore();
}

function formatDim(d){
  if(unit==='m') return (d/1000*scale).toFixed(2)+'m';
  if(unit==='cm') return (d/100*scale).toFixed(1)+'cm';
  return Math.round(d*scale)+'mm';
}

function drawAnnot(c, o, sel){
  const textCol = sel?'#ffff00':(o.annotColor||o.color||'#ffff88');
  const borderCol = sel?'#ffff00':(o.bubbleBorder||o.color||'#ffff88');
  const bgCol = o.bubbleBg||'rgba(26,42,64,0.9)';
  const bw2 = o.bubbleBorderW||1;
  const alpha = o.bubbleAlpha!=null?o.bubbleAlpha:0.9;
  const fs = o.fontSize||12;
  const fontFamily = o.fontFamily||'Noto Sans KR';
  const fontWeight = o.fontWeight||'normal';
  const pad=10;

  c.font=`${fontWeight} ${fs}px ${fontFamily}`;
  const tw=c.measureText(o.text||'').width;
  const th=fs+4;

  if(o.bubble){
    const bx=o.x, by=o.y, bw=tw+pad*2, bh=th+pad*2;
    const shape=o.bubbleShape||'rounded';

    c.save();
    c.globalAlpha=alpha;

    // 꼬리
    if(o.arrowTo){
      const tailStyle=o.tailStyle||'arrow';
      const tailW=o.tailWidth||2;
      const bcx=bx+bw/2, bcy=by+bh/2;
      const ax=o.arrowTo.x, ay=o.arrowTo.y;
      const angle=Math.atan2(ay-bcy,ax-bcx);

      // 풍선 가장자리 연결점
      let ex=bcx, ey=bcy;
      if(ax<bx) ex=bx; else if(ax>bx+bw) ex=bx+bw;
      else ex=Math.max(bx+10,Math.min(bx+bw-10,ax));
      if(ay<by) ey=by; else if(ay>by+bh) ey=by+bh;
      else ey=Math.max(by+10,Math.min(by+bh-10,ay));

      c.strokeStyle=borderCol;
      c.lineWidth=tailW;

      if(tailStyle==='arrow'){
        // 선 + 화살표 머리
        c.beginPath();
        c.moveTo(ex,ey);
        c.lineTo(ax,ay);
        c.stroke();
        // 화살표 머리
        const aSize=tailW*5;
        c.save();
        c.translate(ax,ay); c.rotate(angle);
        c.beginPath();
        c.moveTo(0,0);
        c.lineTo(-aSize,-aSize/2.5);
        c.lineTo(-aSize,aSize/2.5);
        c.closePath();
        c.fillStyle=borderCol;
        c.fill();
        c.restore();
      } else if(tailStyle==='triangle'){
        const perpX=-Math.sin(angle)*6;
        const perpY=Math.cos(angle)*6;
        c.beginPath();
        c.moveTo(ex+perpX,ey+perpY);
        c.lineTo(ax,ay);
        c.lineTo(ex-perpX,ey-perpY);
        c.closePath();
        c.fillStyle=bgCol; c.fill();
        c.stroke();
      } else {
        // 선
        c.beginPath();
        c.moveTo(ex,ey);
        c.lineTo(ax,ay);
        c.stroke();
      }
    }

    // 풍선 본체
    c.fillStyle=bgCol;
    c.strokeStyle=borderCol;
    c.lineWidth=bw2;

    if(shape==='rounded'){
      roundRect(c,bx,by,bw,bh,6);
      c.fill(); c.stroke();
    } else if(shape==='rect'){
      c.fillRect(bx,by,bw,bh);
      c.strokeRect(bx,by,bw,bh);
    } else if(shape==='ellipse'){
      c.beginPath();
      c.ellipse(bx+bw/2,by+bh/2,bw/2,bh/2,0,0,Math.PI*2);
      c.fill(); c.stroke();
    }

    c.restore();

    // 텍스트
    c.fillStyle=textCol;
    c.font=`${fontWeight} ${fs}px ${fontFamily}`;
    c.fillText(o.text||'', bx+pad, by+bh-pad);
  }
  else if(o.cloud){
    drawCloud(c, o.x, o.y, o.w||100, o.h||60);
    c.fillStyle=textCol;
    c.fillText(o.text||'', o.x+pad, o.y+(o.h||60)/2);
  }
  else {
    c.fillStyle=textCol;
    c.fillText(o.text||'', o.x, o.y);
  }
}

function roundRect(c,x,y,w,h,r){
  c.beginPath();
  c.moveTo(x+r,y);
  c.lineTo(x+w-r,y); c.arcTo(x+w,y,x+w,y+r,r);
  c.lineTo(x+w,y+h-r); c.arcTo(x+w,y+h,x+w-r,y+h,r);
  c.lineTo(x+r,y+h); c.arcTo(x,y+h,x,y+h-r,r);
  c.lineTo(x,y+r); c.arcTo(x,y,x+r,y,r);
  c.closePath();
}

function drawCloud(c,x,y,w,h){
  const step=20;
  c.beginPath();
  for(let i=0;i<w;i+=step){
    c.arc(x+i+step/2,y,step/2,Math.PI,0);
  }
  for(let i=0;i<h;i+=step){
    c.arc(x+w,y+i+step/2,step/2,-Math.PI/2,Math.PI/2);
  }
  for(let i=w;i>0;i-=step){
    c.arc(x+i-step/2,y+h,step/2,0,Math.PI);
  }
  for(let i=h;i>0;i-=step){
    c.arc(x,y+i-step/2,step/2,Math.PI/2,3*Math.PI/2);
  }
  c.closePath();
  c.fill(); c.stroke();
}

function drawTable(c, o, sel){
  const {x,y,data,colW,rowH,color}=o;
  const col=sel?'#ffff00':(color||'#aaccff');
  c.strokeStyle=col; c.lineWidth=0.8;
  c.font='11px Noto Sans KR';
  data.forEach((row,ri)=>{
    let cx=x;
    row.forEach((cell,ci)=>{
      const cw=colW[ci]||80, ch=rowH[ri]||22;
      if(o.fill){ c.fillStyle='rgba(10,20,40,0.7)'; c.fillRect(cx,y+ri*ch,cw,ch); }
      c.strokeRect(cx,y+ri*ch,cw,ch);
      c.fillStyle=col;
      c.fillText(String(cell||''), cx+4, y+ri*ch+ch-5);
      cx+=cw;
    });
  });
}

// Scale bar removed

function drawUCSIcon(c, W, H){
  const ox = 44;
  const oy = H - 44;
  const len = 30;
  const sq = 5;
  c.save();
  c.lineWidth = 1.5;
  c.font = 'bold 11px monospace';
  c.textBaseline = 'middle';
  // X축 (빨강)
  c.strokeStyle='#cc3333'; c.fillStyle='#cc3333';
  c.beginPath(); c.moveTo(ox,oy); c.lineTo(ox+len,oy); c.stroke();
  c.beginPath(); c.moveTo(ox+len,oy); c.lineTo(ox+len-7,oy-3); c.lineTo(ox+len-7,oy+3); c.closePath(); c.fill();
  c.fillText('X', ox+len+4, oy);
  // Y축 (초록)
  c.strokeStyle='#33aa33'; c.fillStyle='#33aa33';
  c.beginPath(); c.moveTo(ox,oy); c.lineTo(ox,oy-len); c.stroke();
  c.beginPath(); c.moveTo(ox,oy-len); c.lineTo(ox-3,oy-len+7); c.lineTo(ox+3,oy-len+7); c.closePath(); c.fill();
  c.fillText('Y', ox-5, oy-len-8);
  // 원점 사각형
  c.strokeStyle='rgba(255,255,255,0.7)'; c.fillStyle='rgba(0,0,0,0.4)'; c.lineWidth=1;
  c.fillRect(ox-sq,oy-sq,sq*2,sq*2); c.strokeRect(ox-sq,oy-sq,sq*2,sq*2);
  c.restore();
}

// ── Overlay ──────────────────────────────────
function _needsWorldOverlay(){
  return !!(
    (selBoxStart && selBoxEnd) ||
    (isDrawing && drawStart) ||
    (tool==='polyline' && polyPoints.length>0) ||
    (tool==='dim' && dimStep===1 && dimP1 && lastMouseWorld) ||
    (tool==='trim' && _trimMouseDown && _trimDragPath.length>1) ||
    (tool==='annot' && annotStep===1 && annotArrowTo && lastMouseWorld) ||
    (tool==='text' && textOrigin && lastMouseWorld) ||
    (tool==='rotate' && rotateStep===1 && rotateBase && lastMouseWorld) ||
    (tool==='mirror' && (mirrorStep===1 || mirrorStep===2) && mirrorP1) ||
    ((tool==='move' || tool==='copyMove') && moveStep===1 && moveBasePoint && lastMouseWorld) ||
    (tool==='scale' && moveStep===1 && moveBasePoint && lastMouseWorld) ||
    (snapOn && lastSnapPoint) ||
    (_pastePreview && clipboard.length && lastMouseWorld)
  );
}

function _drawCadCursorOverlay(){
  if(!lastMouseClient) return;
  const cx=lastMouseClient.x, cy=lastMouseClient.y;
  const W=ovCanvas.width, H=ovCanvas.height;
  const halfLen = cursorCfg.sizePct>=100
      ? Math.max(W,H)
      : Math.min(W,H) * cursorCfg.sizePct / 100 / 2;
  const gap = cursorCfg.gap;
  ovCtx.save();
  ovCtx.strokeStyle = cursorCfg.color;
  ovCtx.lineWidth = cursorCfg.lineWidth;
  ovCtx.setLineDash([]);
  ovCtx.beginPath();
  // \uAC00\uB85C\uC120
  ovCtx.moveTo(Math.max(0,cx-halfLen), cy);
  ovCtx.lineTo(cx-gap, cy);
  ovCtx.moveTo(cx+gap, cy);
  ovCtx.lineTo(Math.min(W,cx+halfLen), cy);
  // \uC138\uB85C\uC120
  ovCtx.moveTo(cx, Math.max(0,cy-halfLen));
  ovCtx.lineTo(cx, cy-gap);
  ovCtx.moveTo(cx, cy+gap);
  ovCtx.lineTo(cx, Math.min(H,cy+halfLen));
  ovCtx.stroke();
  // \uC911\uC2EC \uC0AC\uAC01\uD615
  if(cursorCfg.showSquare){
    ovCtx.strokeRect(cx-4, cy-4, 8, 8);
  }
  ovCtx.restore();
}

function _doRenderOverlay(){
  ovCtx.clearRect(0,0,ovCanvas.width,ovCanvas.height);
  if(!_needsWorldOverlay()){
    _drawCadCursorOverlay();
    return;
  }
  ovCtx.save();
  ovCtx.translate(viewX,viewY);
  ovCtx.scale(viewZoom,viewZoom);

  // Selection box - Window(\uD30C\uB791) vs Crossing(\uCD08\uB85D)
  if(selBoxStart&&selBoxEnd){
    const crossing = selBoxEnd.x < selBoxStart.x; // \uC624→\uC67C = crossing
    if(crossing){
      ovCtx.strokeStyle='rgba(0,255,100,0.9)';
      ovCtx.fillStyle='rgba(0,255,100,0.04)';
      ovCtx.setLineDash([6/viewZoom,3/viewZoom]);
    } else {
      ovCtx.strokeStyle='rgba(0,150,255,0.9)';
      ovCtx.fillStyle='rgba(0,150,255,0.06)';
      ovCtx.setLineDash([]);
    }
    ovCtx.lineWidth=1/viewZoom;
    const rx=Math.min(selBoxStart.x,selBoxEnd.x);
    const ry=Math.min(selBoxStart.y,selBoxEnd.y);
    const rw=Math.abs(selBoxEnd.x-selBoxStart.x);
    const rh=Math.abs(selBoxEnd.y-selBoxStart.y);
    ovCtx.fillRect(rx,ry,rw,rh);
    ovCtx.strokeRect(rx,ry,rw,rh);
    ovCtx.setLineDash([]);
    // \uBAA8\uB4DC \uB808\uC774\uBE14
    ovCtx.fillStyle = crossing ? 'rgba(0,255,100,0.8)' : 'rgba(0,150,255,0.8)';
    ovCtx.font = `${11/viewZoom}px Noto Sans KR`;
    ovCtx.fillText(crossing ? '\uAD50\uCC28 \uC120\uD0DD' : '\uC708\uB3C4\uC6B0 \uC120\uD0DD', rx+4/viewZoom, ry-4/viewZoom);
  }

  // Drawing preview
  if(isDrawing && drawStart){
    ovCtx.strokeStyle='rgba(0,200,255,0.7)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.setLineDash([5/viewZoom,3/viewZoom]);
    const rawMp = lastMouseWorld||drawStart;
    // \uC9C1\uAD50 \uBAA8\uB4DC \uC801\uC6A9
    const mp = (orthoOn && drawStart) ? orthoPoint(drawStart, rawMp) : rawMp;

    if(tool==='line'){
      ovCtx.beginPath(); ovCtx.moveTo(drawStart.x,drawStart.y); ovCtx.lineTo(mp.x,mp.y); ovCtx.stroke();
      // \uC9C1\uAD50 \uD2B8\uB808\uC774\uC11C \uB77C\uC778
      if(orthoOn && (rawMp.x!==mp.x || rawMp.y!==mp.y)){
        ovCtx.strokeStyle='rgba(255,150,0,0.3)';
        ovCtx.setLineDash([3/viewZoom,3/viewZoom]);
        ovCtx.beginPath(); ovCtx.moveTo(mp.x,mp.y); ovCtx.lineTo(rawMp.x,rawMp.y); ovCtx.stroke();
        ovCtx.strokeStyle='rgba(0,200,255,0.7)';
      }
      showCoordLabel(mp.x,mp.y, formatDim(dist2(drawStart,mp)));
    }
    else if(tool==='wall'){
      const hw=(curStyle.wallWidth||200)/2;
      drawWallPreview(ovCtx,drawStart.x,drawStart.y,mp.x,mp.y,hw);
    }
    else if(tool==='rect'){
      ovCtx.strokeRect(drawStart.x,drawStart.y,mp.x-drawStart.x,mp.y-drawStart.y);
    }
    else if(tool==='circle'){
      const r=dist2(drawStart,mp);
      ovCtx.beginPath(); ovCtx.arc(drawStart.x,drawStart.y,r,0,Math.PI*2); ovCtx.stroke();
      showCoordLabel(mp.x,mp.y, 'R'+formatDim(r));
    }
    ovCtx.setLineDash([]);
  }

  // Polyline preview
  if(tool==='polyline' && polyPoints.length>0){
    const mp=lastMouseWorld||polyPoints[polyPoints.length-1];
    // \uC774\uBBF8 \uD655\uC815\uB41C \uC120\uBD84 (solid)
    if(polyPoints.length>1){
      ovCtx.strokeStyle='rgba(0,200,255,1)';
      ovCtx.lineWidth=(curStyle.lineWidth||1)/viewZoom*2;
      ovCtx.setLineDash([]);
      ovCtx.beginPath();
      ovCtx.moveTo(polyPoints[0].x,polyPoints[0].y);
      for(let i=1;i<polyPoints.length;i++) ovCtx.lineTo(polyPoints[i].x,polyPoints[i].y);
      ovCtx.stroke();
    }
    // \uD604\uC7AC → \uB9C8\uC6B0\uC2A4 rubber band (dash) - \uC9C1\uAD50 \uC801\uC6A9
    const rawMp2 = lastMouseWorld||polyPoints[polyPoints.length-1];
    const pmp = orthoOn ? orthoPoint(polyPoints[polyPoints.length-1], rawMp2) : rawMp2;
    ovCtx.strokeStyle='rgba(0,220,255,0.7)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.setLineDash([6/viewZoom,3/viewZoom]);
    ovCtx.beginPath();
    ovCtx.moveTo(polyPoints[polyPoints.length-1].x,polyPoints[polyPoints.length-1].y);
    ovCtx.lineTo(pmp.x,pmp.y);
    ovCtx.stroke();
    ovCtx.setLineDash([]);
    // \uAF2D\uC9D3\uC810 \uC810
    polyPoints.forEach((p,i)=>{
      ovCtx.fillStyle= i===0 ? '#00ff88' : '#00aaff';
      ovCtx.beginPath();
      ovCtx.arc(p.x,p.y,4/viewZoom,0,Math.PI*2);
      ovCtx.fill();
    });
    // \uAC70\uB9AC \uD45C\uC2DC\uB294 onMouseMove\uC5D0\uC11C \uCC98\uB9AC
  }

  // Dim preview
  if(tool==='dim'){
    ovCtx.strokeStyle='rgba(100,255,100,0.7)';
    ovCtx.lineWidth=0.8/viewZoom;
    const mp=lastMouseWorld;
    if(dimStep===1 && dimP1 && mp){
      ovCtx.setLineDash([5/viewZoom,3/viewZoom]);
      ovCtx.beginPath(); ovCtx.moveTo(dimP1.x,dimP1.y); ovCtx.lineTo(mp.x,mp.y); ovCtx.stroke();
      ovCtx.setLineDash([]);
    }
  }

  // Trim 드래그 궤적
  if(tool==='trim' && _trimMouseDown && _trimDragPath.length>1){
    ovCtx.strokeStyle='rgba(255,80,80,0.8)';
    ovCtx.lineWidth=2/viewZoom;
    ovCtx.setLineDash([]);
    ovCtx.beginPath();
    ovCtx.moveTo(_trimDragPath[0].x, _trimDragPath[0].y);
    for(let i=1;i<_trimDragPath.length;i++){
      ovCtx.lineTo(_trimDragPath[i].x, _trimDragPath[i].y);
    }
    ovCtx.stroke();
    // 드래그 끝점에 X 표시
    if(_trimDragPath.length>0){
      const lp=_trimDragPath[_trimDragPath.length-1];
      const xs=5/viewZoom;
      ovCtx.beginPath();
      ovCtx.moveTo(lp.x-xs,lp.y-xs); ovCtx.lineTo(lp.x+xs,lp.y+xs);
      ovCtx.moveTo(lp.x+xs,lp.y-xs); ovCtx.lineTo(lp.x-xs,lp.y+xs);
      ovCtx.stroke();
    }
  }

  // Annot preview (꼬리 → 마우스)
  if(tool==='annot' && annotStep===1 && annotArrowTo && lastMouseWorld){
    ovCtx.strokeStyle='rgba(255,255,100,0.6)';
    ovCtx.lineWidth=0.8/viewZoom;
    ovCtx.setLineDash([5/viewZoom,3/viewZoom]);
    ovCtx.beginPath();
    ovCtx.moveTo(annotArrowTo.x,annotArrowTo.y);
    ovCtx.lineTo(lastMouseWorld.x,lastMouseWorld.y);
    ovCtx.stroke();
    ovCtx.setLineDash([]);
  }

  // Text tool preview (높이/각도 단계)
  if(tool==='text' && textOrigin && lastMouseWorld){
    if(textStep===1){
      // 높이 미리보기: 원점에서 마우스까지 세로선 + 높이값 표시
      var dy1=Math.abs(lastMouseWorld.y-textOrigin.y);
      var dx1=Math.abs(lastMouseWorld.x-textOrigin.x);
      var previewH=Math.max(3,Math.sqrt(dx1*dx1+dy1*dy1));
      ovCtx.strokeStyle='rgba(255,255,0,0.8)';
      ovCtx.lineWidth=1/viewZoom;
      ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
      ovCtx.beginPath();
      ovCtx.moveTo(textOrigin.x,textOrigin.y);
      ovCtx.lineTo(textOrigin.x,textOrigin.y-previewH);
      ovCtx.stroke();
      ovCtx.setLineDash([]);
      // 높이값 표시
      ovCtx.fillStyle='#ffff00';
      ovCtx.font=`${11/viewZoom}px Noto Sans KR`;
      ovCtx.fillText('H='+previewH.toFixed(1), textOrigin.x+5/viewZoom, textOrigin.y-previewH/2);
      // 미리보기 텍스트
      ovCtx.save();
      ovCtx.font=`${previewH}px Arial`;
      ovCtx.fillStyle='rgba(255,255,255,0.4)';
      ovCtx.fillText('Text', textOrigin.x, textOrigin.y);
      ovCtx.restore();
    }
    if(textStep===2){
      // 각도 미리보기: 원점에서 마우스 방향 선 + 각도값 표시
      var ang=Math.atan2(-(lastMouseWorld.y-textOrigin.y), lastMouseWorld.x-textOrigin.x);
      var angDeg=ang*180/Math.PI;
      var lineLen=50/viewZoom;
      ovCtx.strokeStyle='rgba(255,255,0,0.8)';
      ovCtx.lineWidth=1/viewZoom;
      ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
      ovCtx.beginPath();
      ovCtx.moveTo(textOrigin.x,textOrigin.y);
      ovCtx.lineTo(textOrigin.x+lineLen*Math.cos(-ang), textOrigin.y+lineLen*Math.sin(-ang));
      ovCtx.stroke();
      ovCtx.setLineDash([]);
      // 각도값
      ovCtx.fillStyle='#ffff00';
      ovCtx.font=`${11/viewZoom}px Noto Sans KR`;
      ovCtx.fillText('A='+angDeg.toFixed(1)+'°', textOrigin.x+5/viewZoom, textOrigin.y-5/viewZoom);
      // 회전된 미리보기 텍스트
      ovCtx.save();
      ovCtx.translate(textOrigin.x, textOrigin.y);
      ovCtx.rotate(-ang);
      ovCtx.font=`${textFontSize}px Arial`;
      ovCtx.fillStyle='rgba(255,255,255,0.4)';
      ovCtx.fillText('Text', 0, 0);
      ovCtx.restore();
    }
  }

  // Rotate preview
  if(tool==='rotate' && rotateStep===1 && rotateBase && lastMouseWorld){
    var rAng=Math.atan2(-(lastMouseWorld.y-rotateBase.y), lastMouseWorld.x-rotateBase.x);
    if(orthoOn) rAng=Math.round(rAng/(Math.PI/2))*(Math.PI/2);
    var rCosA=Math.cos(rAng), rSinA=Math.sin(rAng);
    var rbx=rotateBase.x, rby=rotateBase.y;
    function _rrp(x,y){ return {x:rbx+(x-rbx)*rCosA-(y-rby)*rSinA, y:rby+(x-rbx)*rSinA+(y-rby)*rCosA}; }
    // 고스트 객체 그리기
    ovCtx.globalAlpha=0.5;
    ovCtx.strokeStyle='rgba(255,200,0,0.7)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
    selectedIds.forEach(id=>{
      const o=objects.find(x=>x.id===id);
      if(!o) return;
      var ghost=JSON.parse(JSON.stringify(o));
      if(ghost.type==='line'||ghost.type==='wall'){
        var gp1=_rrp(ghost.x1,ghost.y1), gp2=_rrp(ghost.x2,ghost.y2);
        ghost.x1=gp1.x; ghost.y1=gp1.y; ghost.x2=gp2.x; ghost.y2=gp2.y;
      } else if(ghost.type==='rect'){
        var gpts=[_rrp(ghost.x,ghost.y),_rrp(ghost.x+ghost.w,ghost.y),_rrp(ghost.x+ghost.w,ghost.y+ghost.h),_rrp(ghost.x,ghost.y+ghost.h)];
        ghost.type='polyline'; ghost.points=gpts; ghost.closed=true;
      } else if(ghost.type==='circle'){
        var gcp=_rrp(ghost.cx,ghost.cy);
        ghost.cx=gcp.x; ghost.cy=gcp.y;
      } else if(ghost.type==='polyline'&&ghost.points){
        ghost.points=ghost.points.map(p=>_rrp(p.x,p.y));
      } else if(ghost.type==='text'||ghost.type==='annot'){
        var gtp=_rrp(ghost.x,ghost.y);
        ghost.x=gtp.x; ghost.y=gtp.y;
        ghost.angle=(ghost.angle||0)+rAng;
      }
      drawObject(ovCtx,ghost,false);
    });
    ovCtx.globalAlpha=1.0;
    ovCtx.setLineDash([]);
    // 기준점→마우스 선
    ovCtx.strokeStyle='rgba(255,200,0,0.8)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.beginPath();
    ovCtx.moveTo(rotateBase.x,rotateBase.y);
    ovCtx.lineTo(lastMouseWorld.x,lastMouseWorld.y);
    ovCtx.stroke();
    // 기준점 십자 표시
    var rs=5/viewZoom;
    ovCtx.beginPath();
    ovCtx.moveTo(rotateBase.x-rs,rotateBase.y); ovCtx.lineTo(rotateBase.x+rs,rotateBase.y);
    ovCtx.moveTo(rotateBase.x,rotateBase.y-rs); ovCtx.lineTo(rotateBase.x,rotateBase.y+rs);
    ovCtx.stroke();
    // 각도 표시
    ovCtx.fillStyle='#ffff00';
    ovCtx.font=`${11/viewZoom}px Noto Sans KR`;
    ovCtx.fillText((rAng*180/Math.PI).toFixed(1)+'°', rotateBase.x+10/viewZoom, rotateBase.y-10/viewZoom);
  }

  // Mirror preview (step1: 두번째점 드래그, step2: Y/N 대기)
  if(tool==='mirror' && (mirrorStep===1||mirrorStep===2) && mirrorP1){
    // 대칭축 두번째 점 결정
    var _mp2x, _mp2y;
    if(mirrorStep===2 && mirrorP2){
      _mp2x=mirrorP2.x; _mp2y=mirrorP2.y;
    } else if(lastMouseWorld){
      // 직교모드: 수평/수직 스냅
      if(orthoOn){
        var _mdx=Math.abs(lastMouseWorld.x-mirrorP1.x), _mdy=Math.abs(lastMouseWorld.y-mirrorP1.y);
        if(_mdx>=_mdy){ _mp2x=lastMouseWorld.x; _mp2y=mirrorP1.y; }
        else { _mp2x=mirrorP1.x; _mp2y=lastMouseWorld.y; }
      } else {
        _mp2x=lastMouseWorld.x; _mp2y=lastMouseWorld.y;
      }
    } else { _mp2x=mirrorP1.x; _mp2y=mirrorP1.y; }
    // 대칭축 벡터
    var mAx=_mp2x-mirrorP1.x, mAy=_mp2y-mirrorP1.y;
    var mLen2=mAx*mAx+mAy*mAy;
    function _mrf(x,y){
      if(mLen2===0) return {x:x,y:y};
      var dx2=x-mirrorP1.x, dy2=y-mirrorP1.y;
      var dot2=(dx2*mAx+dy2*mAy)/mLen2;
      return {x:mirrorP1.x+2*dot2*mAx-dx2, y:mirrorP1.y+2*dot2*mAy-dy2};
    }
    // 고스트 객체
    ovCtx.globalAlpha=0.5;
    ovCtx.strokeStyle='rgba(0,200,255,0.7)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
    selectedIds.forEach(id=>{
      const o=objects.find(x=>x.id===id);
      if(!o) return;
      var ghost=JSON.parse(JSON.stringify(o));
      if(ghost.type==='line'||ghost.type==='wall'){
        var mp1=_mrf(ghost.x1,ghost.y1), mp2=_mrf(ghost.x2,ghost.y2);
        ghost.x1=mp1.x; ghost.y1=mp1.y; ghost.x2=mp2.x; ghost.y2=mp2.y;
      } else if(ghost.type==='rect'){
        var mpts=[_mrf(ghost.x,ghost.y),_mrf(ghost.x+ghost.w,ghost.y),_mrf(ghost.x+ghost.w,ghost.y+ghost.h),_mrf(ghost.x,ghost.y+ghost.h)];
        ghost.type='polyline'; ghost.points=mpts; ghost.closed=true;
      } else if(ghost.type==='circle'){
        var mcp=_mrf(ghost.cx,ghost.cy);
        ghost.cx=mcp.x; ghost.cy=mcp.y;
      } else if(ghost.type==='polyline'&&ghost.points){
        ghost.points=ghost.points.map(p=>_mrf(p.x,p.y));
      } else if(ghost.type==='text'||ghost.type==='annot'){
        var mtp=_mrf(ghost.x,ghost.y);
        ghost.x=mtp.x; ghost.y=mtp.y;
      }
      drawObject(ovCtx,ghost,false);
    });
    ovCtx.globalAlpha=1.0;
    ovCtx.setLineDash([]);
    // 대칭축 선
    ovCtx.strokeStyle='rgba(0,200,255,0.8)';
    ovCtx.lineWidth=1.5/viewZoom;
    ovCtx.setLineDash([6/viewZoom,3/viewZoom]);
    ovCtx.beginPath();
    ovCtx.moveTo(mirrorP1.x,mirrorP1.y);
    ovCtx.lineTo(_mp2x,_mp2y);
    ovCtx.stroke();
    ovCtx.setLineDash([]);
    // 라벨
    var _mlx=(mirrorP1.x+_mp2x)/2, _mly=(mirrorP1.y+_mp2y)/2;
    ovCtx.fillStyle='#00ccff';
    ovCtx.font=`${11/viewZoom}px Noto Sans KR`;
    if(mirrorStep===2){
      ovCtx.fillText('원본 삭제? Y/N', _mlx+5/viewZoom, _mly-5/viewZoom);
    } else {
      ovCtx.fillText('대칭축'+(orthoOn?' [직교]':''), _mlx+5/viewZoom, _mly-5/viewZoom);
    }
  }

  // Move/CopyMove preview
  if((tool==='move'||tool==='copyMove') && moveStep===1 && moveBasePoint && lastMouseWorld){
    const dx=lastMouseWorld.x-moveBasePoint.x, dy=lastMouseWorld.y-moveBasePoint.y;
    ovCtx.strokeStyle='rgba(255,200,0,0.5)';
    ovCtx.lineWidth=1/viewZoom;
    ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
    // draw ghost of selected objects
    selectedIds.forEach(id=>{
      const o=objects.find(x=>x.id===id);
      if(!o) return;
      const ghost=JSON.parse(JSON.stringify(o));
      moveObj(ghost,getObjX(ghost)+dx,getObjY(ghost)+dy);
      drawObject(ovCtx,ghost,false);
    });
    ovCtx.setLineDash([]);
    // base → cursor line
    ovCtx.strokeStyle='rgba(255,200,0,0.8)';
    ovCtx.beginPath();
    ovCtx.moveTo(moveBasePoint.x,moveBasePoint.y);
    ovCtx.lineTo(lastMouseWorld.x,lastMouseWorld.y);
    ovCtx.stroke();
    // base point cross
    const s=5/viewZoom;
    ovCtx.beginPath();
    ovCtx.moveTo(moveBasePoint.x-s,moveBasePoint.y); ovCtx.lineTo(moveBasePoint.x+s,moveBasePoint.y);
    ovCtx.moveTo(moveBasePoint.x,moveBasePoint.y-s); ovCtx.lineTo(moveBasePoint.x,moveBasePoint.y+s);
    ovCtx.stroke();
  }

  // Scale preview
  if(tool==='scale' && moveStep===1 && moveBasePoint && lastMouseWorld){
    const ratio=getScaleRatioFromPoint(lastMouseWorld);
    if(ratio>0){
      ovCtx.strokeStyle='rgba(0,255,200,0.55)';
      ovCtx.lineWidth=1/viewZoom;
      ovCtx.setLineDash([4/viewZoom,3/viewZoom]);
      selectedIds.forEach(id=>{
        const o=objects.find(x=>x.id===id);
        if(!o) return;
        const ghost=JSON.parse(JSON.stringify(o));
        scaleObjAround(ghost, moveBasePoint.x, moveBasePoint.y, ratio);
        drawObject(ovCtx,ghost,false);
      });
      ovCtx.setLineDash([]);
      ovCtx.strokeStyle='rgba(0,255,200,0.85)';
      ovCtx.beginPath();
      ovCtx.moveTo(moveBasePoint.x,moveBasePoint.y);
      ovCtx.lineTo(lastMouseWorld.x,lastMouseWorld.y);
      ovCtx.stroke();
      const s=5/viewZoom;
      ovCtx.beginPath();
      ovCtx.moveTo(moveBasePoint.x-s,moveBasePoint.y); ovCtx.lineTo(moveBasePoint.x+s,moveBasePoint.y);
      ovCtx.moveTo(moveBasePoint.x,moveBasePoint.y-s); ovCtx.lineTo(moveBasePoint.x,moveBasePoint.y+s);
      ovCtx.stroke();
      ovCtx.fillStyle='#00ffd0';
      ovCtx.font=`${11/viewZoom}px Noto Sans KR`;
      ovCtx.fillText(`Scale ${ratio.toFixed(3)}x`, lastMouseWorld.x+8/viewZoom, lastMouseWorld.y-8/viewZoom);
    }
  }

  if(snapOn && lastSnapPoint){
    drawSnapIcon(ovCtx, lastSnapPoint.x, lastSnapPoint.y, lastSnapPoint.snap||'end', viewZoom);
  }

  // 붙여넣기 미리보기: 고스트 객체 렌더링
  if(_pastePreview && clipboard.length && lastMouseWorld){
    let cx=0, cy=0;
    clipboard.forEach(o=>{ cx+=getObjX(o); cy+=getObjY(o); });
    cx/=clipboard.length; cy/=clipboard.length;
    const dx=lastMouseWorld.x-cx, dy=lastMouseWorld.y-cy;
    ovCtx.globalAlpha=0.5;
    clipboard.forEach(o=>{
      const ghost=JSON.parse(JSON.stringify(o));
      moveObj(ghost, getObjX(ghost)+dx, getObjY(ghost)+dy);
      drawObject(ovCtx, ghost, false);
    });
    ovCtx.globalAlpha=1.0;
  }

  ovCtx.restore();

  // ── CAD \uC2ED\uC790 \uCEE4\uC11C (\uD654\uBA74 \uC88C\uD45C\uB85C \uADF8\uB9BC) ──
  _drawCadCursorOverlay();
}

let lastMouseClient=null;
let lastMouseWorld=null;
let lastSnapPoint=null;
let _pastePreview=false; // 붙여넣기 미리보기 모드
let _sXEl=null, _sYEl=null, _dynTipEl=null;
let _lastTipText='', _lastTipLeft='', _lastTipTop='';

function setMouseCoordStatus(wx, wy){
  if(!_sXEl) _sXEl=document.getElementById('sX');
  if(!_sYEl) _sYEl=document.getElementById('sY');
  const sx=wx.toFixed(0), sy=wy.toFixed(0);
  if(_sXEl && _sXEl.textContent!==sx) _sXEl.textContent=sx;
  if(_sYEl && _sYEl.textContent!==sy) _sYEl.textContent=sy;
}

function getDynTipEl(){
  if(!_dynTipEl) _dynTipEl=document.getElementById('dynTip');
  return _dynTipEl;
}

function hideDynTip(){
  const tip=getDynTipEl();
  if(!tip) return;
  if(tip.style.display!=='none') tip.style.display='none';
  _lastTipText=''; _lastTipLeft=''; _lastTipTop='';
}

function showDynTip(text, left, top){
  const tip=getDynTipEl();
  if(!tip) return;
  if(tip.style.display!=='block') tip.style.display='block';
  if(_lastTipText!==text){
    tip.textContent=text;
    _lastTipText=text;
  }
  const l=left+'px', t=top+'px';
  if(_lastTipLeft!==l){ tip.style.left=l; _lastTipLeft=l; }
  if(_lastTipTop!==t){ tip.style.top=t; _lastTipTop=t; }
}

// ── \uCEE4\uC11C \uC124\uC815 ─────────────────────────────────
let cursorCfg = {
  sizePct: 50,      // \uD654\uBA74 \uB300\uBE44 % (full=100)
  lineWidth: 1,
  color: '#ffffff',
  showSquare: true,
  gap: 8
};

function drawWallPreview(c,x1,y1,x2,y2,hw){
  const dx=x2-x1,dy=y2-y1,len=Math.sqrt(dx*dx+dy*dy);
  if(len<1) return;
  const nx=-dy/len,ny=dx/len;
  c.beginPath();
  c.moveTo(x1+nx*hw,y1+ny*hw); c.lineTo(x2+nx*hw,y2+ny*hw);
  c.lineTo(x2-nx*hw,y2-ny*hw); c.lineTo(x1-nx*hw,y1-ny*hw);
  c.closePath(); c.stroke();
}

function showCoordLabel(wx,wy,txt){
  const s=toScreen(wx,wy);
  ovCtx.save(); ovCtx.restore();
  // drawn via HTML overlay via status
  setMouseCoordStatus(wx, wy);
}

function dist2(a,b){ return Math.sqrt((b.x-a.x)**2+(b.y-a.y)**2); }

// ── Tool Setup ───────────────────────────────
function setTool(t){
  // 뷰어 모드에서는 select만 허용
  if(document.body.classList.contains('viewer-mode') && t!=='select'){
    notify('뷰어 모드: 수정할 수 없습니다');
    return;
  }
  if(t!=='select') lastTool=t;
  tool=t;
  // \uC67C\uCABD \uD234\uBC14 + \uB9AC\uBCF8 \uBAA8\uB450 active \uCC98\uB9AC
  document.querySelectorAll('.toolBtn,.ribBtn').forEach(b=>b.classList.remove('active'));
  const tb=document.getElementById('tb_'+t);
  if(tb) tb.classList.add('active');
  const rb=document.getElementById('rb_'+t);
  if(rb) rb.classList.add('active');
  polyPoints=[];
  dimStep=0; dimP1=null; dimP2=null;
  moveStep=0; moveBasePoint=null;
  scaleRefDistance=0;
  annotStep=0; annotArrowTo=null;
  textStep=0; textOrigin=null;
  rotateStep=0; rotateBase=null;
  mirrorStep=0; mirrorP1=null; mirrorP2=null;
  removeInlineTextEditor();
  trimStep=0; trimEdges=[];
  isDrawing=false; drawStart=null;
  hideDynLenInput();
  if(t==='move'||t==='copyMove'){
    if(selectedIds.size>0){
      notify(t==='move'?'\uAE30\uC900\uC810\uC744 \uD074\uB9AD\uD558\uC138\uC694':'\uAE30\uC900\uC810\uC744 \uD074\uB9AD\uD558\uC138\uC694 (\uBCF5\uC0AC)');
    } else {
      notify('\uAC1D\uCCB4\uB97C \uBA3C\uC800 \uC120\uD0DD \uD6C4 '+( t==='move'?'M':'CO')+' \uD0A4\uB97C \uB204\uB974\uC138\uC694');
    }
  } else if(t==='scale'){
    if(selectedIds.size>0){
      notify('기준점 클릭 → 배율 입력 (SC)');
    } else {
      notify('객체를 먼저 선택 후 SC');
    }
  } else if(t==='rotate'){
    if(selectedIds.size>0){
      notify('기준점을 클릭하세요 (RO)');
    } else {
      notify('객체를 먼저 선택 후 RO');
    }
  } else if(t==='mirror'){
    if(selectedIds.size>0){
      notify('대칭축 첫번째 점 클릭 (MI)');
    } else {
      notify('객체를 먼저 선택 후 MI');
    }
  } else {

  }
  updateStatus();
}

// ── Mouse Events ─────────────────────────────
let mouseBtn=0;
wrap.addEventListener('mousedown', onMouseDown);
// 캔버스 클릭 시 포커스 확보 (Cmd+V paste 이벤트 수신을 위해)
wrap.addEventListener('mousedown', function(){ wrap.focus(); });
wrap.addEventListener('mousemove', onMouseMove);
wrap.addEventListener('mouseup', onMouseUp);
wrap.addEventListener('dblclick', onDblClick);
// 가운데 버튼 더블클릭 → 줌올
wrap.addEventListener('mousedown', function(e){ if(e.button===1 && e.detail===2){ e.preventDefault(); fitAll(); } });
wrap.addEventListener('wheel', onWheel, {passive:false});
wrap.addEventListener('contextmenu', onContextMenu);
wrap.addEventListener('mouseleave', ()=>{ lastMouseClient=null; hideDynTip(); renderOverlay(); });
wrap.addEventListener('mouseenter', ()=>{ /* cursor:none already set */ });

// ── 드래그앤드롭: 이미지/PDF 파일 ──────────────
wrap.addEventListener('dragover', function(e){
  e.preventDefault();
  e.stopPropagation();
  e.dataTransfer.dropEffect = 'copy';
  wrap.classList.add('dragover');
});
wrap.addEventListener('dragleave', function(e){
  e.preventDefault();
  e.stopPropagation();
  wrap.classList.remove('dragover');
});
wrap.addEventListener('drop', function(e){
  e.preventDefault();
  e.stopPropagation();
  wrap.classList.remove('dragover');
  handleFileDrop(e);
});

function handleFileDrop(e){
  const files = e.dataTransfer.files;
  if(!files || !files.length) return;
  const r = wrap.getBoundingClientRect();
  const dropX = e.clientX - r.left;
  const dropY = e.clientY - r.top;
  const wp = toWorld(dropX, dropY);

  Array.from(files).forEach(function(f){
    const reader = new FileReader();
    const name = f.name.toLowerCase();
    const type = f.type.toLowerCase();
    // ── DWG/DXF 파일 체크 ──
    if(name.endsWith('.dwg') || name.endsWith('.dxf')){
      importDWG(f);
      return;
    }

    // ── Excel/CSV 확장자 먼저 체크 (이미지보다 우선) ──
    const isExcel = name.endsWith('.xlsx') || name.endsWith('.xls') ||
        name.endsWith('.csv')  || name.endsWith('.tsv') ||
        type === 'text/csv' || type === 'application/csv' ||
        type.includes('spreadsheet') || type.includes('excel') ||
        type === 'application/vnd.ms-excel' ||
        type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    if(isExcel){
      if(name.endsWith('.xlsx') || name.endsWith('.xls')){
        // PHP API로 파싱
        // SheetJS CDN으로 브라우저에서 직접 파싱
        notify('\uD30C\uC77C \uBD84\uC11D \uC911...');
        _loadSheetJS(function(){
          const xlsReader = new FileReader();
          xlsReader.onload = function(ev){
            try{
              const wb = XLSX.read(ev.target.result, {type:'array'});
              const ws = wb.Sheets[wb.SheetNames[0]];
              const rows = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});
              if(!rows.length){ notify('\uB370\uC774\uD130 \uC5C6\uC74C'); return; }
              pushUndo();
              objects.push({id:nextId(),type:'table',data:rows,
                x:wp.x,y:wp.y,colW:rows[0].map(()=>100),rowH:rows.map(()=>22),
                color:'#aaccff',layerId:layers[currentLayer].id});
              refreshUI({status:true});
              notify('\uD45C \uC0BD\uC785 \uC644\uB8CC ('+rows.length+'\uD589 x '+rows[0].length+'\uC5F4)');
            } catch(err){ notify('\uD30C\uC2F1 \uC624\uB958: '+err.message); }
          };
          xlsReader.readAsArrayBuffer(f);
        });
        return;
      }
      reader.onload = function(ev){
        const text = ev.target.result;
        const sep = text.includes('\t') ? '\t' : ',';
        const rows = text.trim().split('\n').map(function(r){
          return r.split(sep).map(function(c){ return c.trim().replace(/^"|"$/g,''); });
        });
        if(!rows.length) return;
        pushUndo();
        objects.push({id:nextId(), type:'table', data:rows,
          x:wp.x, y:wp.y,
          colW:rows[0].map(()=>100), rowH:rows.map(()=>22),
          color:'#aaccff', layerId:layers[currentLayer].id});
        refreshUI({status:true});
        notify('\uD45C \uC0BD\uC785: '+f.name+' ('+rows.length+'\uD589)');
      };
      reader.readAsText(f);
      return;
    }

    // ── PDF ──
    if(type === 'application/pdf' || name.endsWith('.pdf')){
      reader.onload = function(ev){ _openPdfPicker(ev.target.result, wp); };
      reader.readAsDataURL(f);
      return;
    }

    // ── 이미지 ──
    if(type.startsWith('image/')){
      reader.onload = function(ev){
        const img = new Image();
        img.onload = function(){
          pushUndo();
          objects.push({
            id:nextId(), type:'bgimage',
            x:wp.x - img.width/2/viewZoom,
            y:wp.y - img.height/2/viewZoom,
            w:img.width/viewZoom, h:img.height/viewZoom,
            src:ev.target.result, alpha:1,
            layerId:layers[currentLayer].id, locked:false
          });
          refreshUI({status:true});
          notify('\uC774\uBBF8\uC9C0 \uC0BD\uC785: ' + f.name);
        };
        img.src = ev.target.result;
      };
      reader.readAsDataURL(f);
    }
  });
}

function getWorldPos(e, r){
  r = r || wrap.getBoundingClientRect();
  const cx=e.clientX-r.left, cy=e.clientY-r.top;
  let wp=toWorld(cx,cy);
  lastSnapPoint=null;
  if(snapOn){
    const snapped=snapPoint(wp.x,wp.y);
    let best=null, bestD=snapSize/viewZoom;
    // 1. 끝점/중점/중심/사분점 등 정확한 스냅
    objects.forEach(o=>{
      const layer=layers.find(l=>l.id===o.layerId);
      if(layer&&!layer.visible) return;
      getSnapPoints(o).forEach(p=>{
        const d=dist2(wp,p);
        if(d<bestD){ bestD=d; best=p; }
      });
    });
    // 2. 근처점 (정확한 스냅 없으면)
    if(!best){
      bestD=snapSize/viewZoom;
      objects.forEach(o=>{
        const layer=layers.find(l=>l.id===o.layerId);
        if(layer&&!layer.visible) return;
        getNearestPoints(o, wp.x, wp.y).forEach(p=>{
          const d=dist2(wp,p);
          if(d<bestD){ bestD=d; best=p; }
        });
      });
    }
    if(best){ lastSnapPoint=best; return {x:best.x, y:best.y}; }
    return snapped;
  }
  return wp;
}

function getSnapPoints(o){
  const pts=[];
  if(o.type==='line'||o.type==='wall'){
    pts.push({x:o.x1,y:o.y1,snap:'end'},{x:o.x2,y:o.y2,snap:'end'});
    pts.push({x:(o.x1+o.x2)/2,y:(o.y1+o.y2)/2,snap:'mid'});
  }
  else if(o.type==='rect'){
    // 끝점 (모서리)
    pts.push({x:o.x,y:o.y,snap:'end'},{x:o.x+o.w,y:o.y,snap:'end'},{x:o.x,y:o.y+o.h,snap:'end'},{x:o.x+o.w,y:o.y+o.h,snap:'end'});
    // 중점 (변 중간)
    pts.push({x:o.x+o.w/2,y:o.y,snap:'mid'},{x:o.x+o.w/2,y:o.y+o.h,snap:'mid'});
    pts.push({x:o.x,y:o.y+o.h/2,snap:'mid'},{x:o.x+o.w,y:o.y+o.h/2,snap:'mid'});
    // 중심
    pts.push({x:o.x+o.w/2,y:o.y+o.h/2,snap:'center'});
  }
  else if(o.type==='circle'){
    pts.push({x:o.cx,y:o.cy,snap:'center'});
    // 사분점
    pts.push({x:o.cx+o.r,y:o.cy,snap:'quad'},{x:o.cx-o.r,y:o.cy,snap:'quad'});
    pts.push({x:o.cx,y:o.cy+o.r,snap:'quad'},{x:o.cx,y:o.cy-o.r,snap:'quad'});
  }
  else if(o.type==='polyline'&&o.points){
    o.points.forEach((p,i)=>pts.push({x:p.x,y:p.y,snap:'end'}));
    // 중점
    for(let i=0;i<o.points.length-1;i++){
      pts.push({x:(o.points[i].x+o.points[i+1].x)/2,y:(o.points[i].y+o.points[i+1].y)/2,snap:'mid'});
    }
  }
  return pts;
}

// 근처점 스냅: 선분 위 가장 가까운 점
function getNearestPoints(o, wx, wy){
  const pts=[];
  if(o.type==='line'||o.type==='wall'){
    const np=nearestOnSeg(wx,wy,o.x1,o.y1,o.x2,o.y2);
    pts.push({x:np.x,y:np.y,snap:'nearest'});
  }
  else if(o.type==='polyline'&&o.points){
    for(let i=0;i<o.points.length-1;i++){
      const np=nearestOnSeg(wx,wy,o.points[i].x,o.points[i].y,o.points[i+1].x,o.points[i+1].y);
      pts.push({x:np.x,y:np.y,snap:'nearest'});
    }
  }
  return pts;
}

// ══════════════════════════════════════════════
// TRIM / EXTEND 엔진 (AutoCAD 방식)
// ══════════════════════════════════════════════

// 두 선분 교차점 (parametric)
function segSegIntersect(ax1,ay1,ax2,ay2,bx1,by1,bx2,by2){
  const dx1=ax2-ax1, dy1=ay2-ay1;
  const dx2=bx2-bx1, dy2=by2-by1;
  const d=dx1*dy2-dy1*dx2;
  if(Math.abs(d)<0.0001) return null;
  const t=((bx1-ax1)*dy2-(by1-ay1)*dx2)/d;
  const u=((bx1-ax1)*dy1-(by1-ay1)*dx1)/d;
  if(t<-0.001||t>1.001||u<-0.001||u>1.001) return null;
  return {x:ax1+t*dx1, y:ay1+t*dy1, t:t, u:u};
}

// 객체에서 선분 목록 추출
function getSegments(o){
  const segs=[];
  if(o.type==='line'||o.type==='wall'){
    segs.push({x1:o.x1,y1:o.y1,x2:o.x2,y2:o.y2});
  } else if(o.type==='rect'){
    segs.push({x1:o.x,y1:o.y,x2:o.x+o.w,y2:o.y});
    segs.push({x1:o.x+o.w,y1:o.y,x2:o.x+o.w,y2:o.y+o.h});
    segs.push({x1:o.x+o.w,y1:o.y+o.h,x2:o.x,y2:o.y+o.h});
    segs.push({x1:o.x,y1:o.y+o.h,x2:o.x,y2:o.y});
  } else if(o.type==='polyline'&&o.points){
    for(let i=0;i<o.points.length-1;i++){
      segs.push({x1:o.points[i].x,y1:o.points[i].y,x2:o.points[i+1].x,y2:o.points[i+1].y});
    }
  } else if(o.type==='circle'){
    // 원을 32개 선분으로 근사
    const n=32;
    for(let i=0;i<n;i++){
      const a1=i*Math.PI*2/n, a2=(i+1)*Math.PI*2/n;
      segs.push({x1:o.cx+o.r*Math.cos(a1),y1:o.cy+o.r*Math.sin(a1),x2:o.cx+o.r*Math.cos(a2),y2:o.cy+o.r*Math.sin(a2)});
    }
  }
  return segs;
}

// TRIM: 모든 다른 객체와 교차점 수집 (참조 비교, ID 사용 안 함)
function _getAllCuts(target){
  const cuts=[];
  const a1=target.x1,b1=target.y1,a2=target.x2,b2=target.y2;
  const dx1=a2-a1, dy1=b2-b1;
  for(let i=0;i<objects.length;i++){
    const o=objects[i];
    if(o===target) continue;
    if(!o.type) continue;
    const segs=getSegments(o);
    for(let j=0;j<segs.length;j++){
      const s=segs[j];
      // 직접 계산 (segSegIntersect 우회)
      const dx2=s.x2-s.x1, dy2=s.y2-s.y1;
      const denom=dx1*dy2-dy1*dx2;
      if(Math.abs(denom)<0.0001) continue;
      const t=((s.x1-a1)*dy2-(s.y1-b1)*dx2)/denom;
      const u=((s.x1-a1)*dy1-(s.y1-b1)*dx1)/denom;
      if(t>-0.01 && t<1.01 && u>-0.01 && u<1.01){
        const px=a1+t*dx1, py=b1+t*dy1;
        cuts.push({x:px, y:py, t:t});
      }
    }
  }
  return cuts;
}

// TRIM 실행 (AutoCAD: boundary→intersection→split→click방향 제거)
function doTrimById(target, clickPt){
  if(target.type==='line'||target.type==='wall'){
    const cuts=_getAllCuts(target);
    if(!cuts.length){ notify('교차점 없음'); return; }

    cuts.sort((a,b)=>a.t-b.t);
    // 중복 제거
    const uc=[cuts[0]];
    for(let i=1;i<cuts.length;i++){
      if(Math.abs(cuts[i].t-uc[uc.length-1].t)>0.001) uc.push(cuts[i]);
    }

    const a1=target.x1,b1=target.y1,a2=target.x2,b2=target.y2;
    const nodes=[{x:a1,y:b1}];
    uc.forEach(c=>nodes.push({x:c.x,y:c.y}));
    nodes.push({x:a2,y:b2});

    // 클릭에 가장 가까운 구간 찾기 (중점 거리)
    let kill=-1, md=Infinity;
    for(let i=0;i<nodes.length-1;i++){
      const mx=(nodes[i].x+nodes[i+1].x)/2, my=(nodes[i].y+nodes[i+1].y)/2;
      const d=Math.hypot(clickPt.x-mx,clickPt.y-my);
      if(d<md){md=d;kill=i;}
    }

    const idx=objects.indexOf(target);
    if(idx<0) return;
    objects.splice(idx,1);

    for(let i=0;i<nodes.length-1;i++){
      if(i===kill) continue;
      const len=Math.hypot(nodes[i+1].x-nodes[i].x,nodes[i+1].y-nodes[i].y);
      if(len<0.5) continue;
      const o=JSON.parse(JSON.stringify(target));
      o.id=nextId(); o.x1=nodes[i].x; o.y1=nodes[i].y; o.x2=nodes[i+1].x; o.y2=nodes[i+1].y;
      objects.push(o);
    }
    notify('트림 완료');
  }
  else if(target.type==='polyline'&&target.points&&target.points.length>1){
    let bs=-1, bd=Infinity;
    for(let i=0;i<target.points.length-1;i++){
      const d=distToSeg(clickPt.x,clickPt.y,target.points[i].x,target.points[i].y,target.points[i+1].x,target.points[i+1].y);
      if(d<bd){bd=d;bs=i;}
    }
    if(bs<0) return;
    const sp=target.points[bs],ep=target.points[bs+1];
    let near=null,nd=Infinity;
    for(let i=0;i<objects.length;i++){
      if(objects[i]===target) continue;
      getSegments(objects[i]).forEach(s=>{
        const ip=segSegIntersect(sp.x,sp.y,ep.x,ep.y,s.x1,s.y1,s.x2,s.y2);
        if(ip){const d=Math.hypot(clickPt.x-ip.x,clickPt.y-ip.y);if(d<nd){nd=d;near=ip;}}
      });
    }
    if(!near){notify('교차점 없음');return;}
    const idx=objects.indexOf(target);if(idx<0)return;objects.splice(idx,1);
    const ds=Math.hypot(clickPt.x-sp.x,clickPt.y-sp.y);
    const de=Math.hypot(clickPt.x-ep.x,clickPt.y-ep.y);
    if(ds<de){
      const pts=[{x:near.x,y:near.y},...target.points.slice(bs+1)];
      if(pts.length>=2){const o=JSON.parse(JSON.stringify(target));o.id=nextId();o.points=pts;objects.push(o);}
    } else {
      const pts=[...target.points.slice(0,bs+1),{x:near.x,y:near.y}];
      if(pts.length>=2){const o=JSON.parse(JSON.stringify(target));o.id=nextId();o.points=pts;objects.push(o);}
    }
    notify('트림 완료');
  }
}

// EXTEND (무한선 교차 → 선분 바깥 교차점으로 연장)
function doExtend(target, clickPt){
  if(target.type!=='line'&&target.type!=='wall') return;
  const a1=target.x1,b1=target.y1,a2=target.x2,b2=target.y2;
  const dx=a2-a1,dy=b2-b1;
  const ds=Math.hypot(clickPt.x-a1,clickPt.y-b1);
  const de=Math.hypot(clickPt.x-a2,clickPt.y-b2);
  const extStart=(ds<de);

  let near=null,nd=Infinity;
  for(let i=0;i<objects.length;i++){
    const o=objects[i];
    if(o===target) continue;
    getSegments(o).forEach(s=>{
      // 무한선 교차
      const denom=(a1-a2)*(s.y1-s.y2)-(b1-b2)*(s.x1-s.x2);
      if(Math.abs(denom)<0.0001) return;
      const A=a1*b2-b1*a2, B=s.x1*s.y2-s.y1*s.x2;
      const px=(A*(s.x1-s.x2)-B*(a1-a2))/denom;
      const py=(A*(s.y1-s.y2)-B*(b1-b2))/denom;
      // boundary 선분 위에 있는지
      const eps=0.1;
      if(px<Math.min(s.x1,s.x2)-eps||px>Math.max(s.x1,s.x2)+eps) return;
      if(py<Math.min(s.y1,s.y2)-eps||py>Math.max(s.y1,s.y2)+eps) return;
      // target 선분 바깥인지
      const t=(Math.abs(dx)>Math.abs(dy))?(px-a1)/dx:(py-b1)/dy;
      if(t>0.001&&t<0.999) return; // 이미 선분 위 = trim 대상
      // 연장 방향 확인
      if(extStart && t>0) return;
      if(!extStart && t<1) return;
      const d=extStart?Math.hypot(px-a1,py-b1):Math.hypot(px-a2,py-b2);
      if(d<nd){nd=d;near={x:px,y:py};}
    });
  }
  if(!near){notify('연장할 경계 없음');return;}
  pushUndo();
  if(extStart){target.x1=near.x;target.y1=near.y;}
  else{target.x2=near.x;target.y2=near.y;}
  notify('연장 완료');
}

function nearestOnSeg(px,py,x1,y1,x2,y2){
  const dx=x2-x1,dy=y2-y1,l2=dx*dx+dy*dy;
  if(l2===0) return {x:x1,y:y1};
  const t=Math.max(0,Math.min(1,((px-x1)*dx+(py-y1)*dy)/l2));
  return {x:x1+t*dx,y:y1+t*dy};
}

// 스냅 아이콘 그리기
function drawSnapIcon(c, x, y, type, zoom){
  const s=8/zoom;
  const lw=1.5/zoom;
  c.lineWidth=lw;

  // 스냅 타입별 색상
  const colors={end:'#00ff88',mid:'#00ccff',center:'#ff8800',quad:'#ff00ff',nearest:'#ffff00',inter:'#ff4444',perp:'#88ff00',tangent:'#ff88ff'};
  const col=colors[type]||'#00ff88';
  c.strokeStyle=col;
  c.fillStyle=col;

  // 스냅 타입별 아이콘
  if(type==='end'){
    // 사각형
    c.strokeRect(x-s/2,y-s/2,s,s);
  }
  else if(type==='mid'){
    // 삼각형
    c.beginPath();
    c.moveTo(x,y-s/2);
    c.lineTo(x+s/2,y+s/2);
    c.lineTo(x-s/2,y+s/2);
    c.closePath();
    c.stroke();
  }
  else if(type==='center'){
    // 원
    c.beginPath();
    c.arc(x,y,s/2,0,Math.PI*2);
    c.stroke();
    // 십자
    c.beginPath();
    c.moveTo(x-s/2,y); c.lineTo(x+s/2,y);
    c.moveTo(x,y-s/2); c.lineTo(x,y+s/2);
    c.stroke();
  }
  else if(type==='quad'){
    // 다이아몬드
    c.beginPath();
    c.moveTo(x,y-s/2);
    c.lineTo(x+s/2,y);
    c.lineTo(x,y+s/2);
    c.lineTo(x-s/2,y);
    c.closePath();
    c.stroke();
  }
  else if(type==='nearest'){
    // 모래시계
    c.beginPath();
    c.moveTo(x-s/2,y-s/2);
    c.lineTo(x+s/2,y+s/2);
    c.moveTo(x+s/2,y-s/2);
    c.lineTo(x-s/2,y+s/2);
    c.stroke();
    c.strokeRect(x-s/2,y-s/2,s,s);
  }
  else if(type==='inter'){
    // X 표시
    c.beginPath();
    c.moveTo(x-s/2,y-s/2); c.lineTo(x+s/2,y+s/2);
    c.moveTo(x+s/2,y-s/2); c.lineTo(x-s/2,y+s/2);
    c.stroke();
  }
  else if(type==='perp'){
    // ㄱ 모양
    c.beginPath();
    c.moveTo(x-s/2,y+s/2);
    c.lineTo(x-s/2,y-s/2);
    c.lineTo(x+s/2,y-s/2);
    c.stroke();
    // 작은 사각형
    const q=s/4;
    c.strokeRect(x-s/2,y-s/2,q,q);
  }
  else if(type==='tangent'){
    // 원 + 선
    c.beginPath();
    c.arc(x,y,s/3,0,Math.PI*2);
    c.stroke();
    c.beginPath();
    c.moveTo(x-s/2,y+s/3);
    c.lineTo(x+s/2,y+s/3);
    c.stroke();
  }
  else {
    // 기본 사각형
    c.strokeRect(x-s/2,y-s/2,s,s);
  }

  // 라벨 표시
  const labels={end:'끝점',mid:'중점',center:'중심',quad:'사분점',nearest:'근처점',inter:'교차점',perp:'직교',tangent:'접점'};
  const label=labels[type]||'';
  if(label){
    c.font=`${10/zoom}px sans-serif`;
    c.fillStyle=col;
    c.fillText(label, x+s/2+3/zoom, y-s/2);
  }
}

function onMouseDown(e){
  mouseBtn=e.button;
  if(e.button===1){ e.preventDefault(); return; }
  if(e.button===2) return;

  const r=wrap.getBoundingClientRect();
  const rawWp=toWorld(e.clientX-r.left, e.clientY-r.top);
  const wp=getWorldPos(e, r);
  lastMouseWorld=wp;

  // 붙여넣기 미리보기 모드: 클릭 시 배치
  if(_pastePreview && e.button===0){ _commitPaste(wp.x, wp.y); return; }

  // 트림은 mouseDown에서 바로 처리
  if(tool==='trim'){
    if(trimStep===0){
      const hit=hitTest(rawWp.x,rawWp.y);
      if(hit && (hit.type==='line'||hit.type==='wall'||hit.type==='polyline'||hit.type==='rect'||hit.type==='circle')){
        if(!trimEdges.find(id=>id===hit.id)){
          trimEdges.push(hit.id);
          selectedIds.add(hit.id);
          render();
          notify('경계 '+trimEdges.length+'개 선택 (Enter/Space: 확정)');
        }
      }
    } else if(trimStep===1){
      _trimMouseDown=true;
      _trimDragPath=[{x:rawWp.x,y:rawWp.y}];
      const hit=hitTest(rawWp.x,rawWp.y);
      if(!hit){ notify('객체 없음'); return; }
      if(hit.type!=='line'&&hit.type!=='wall'&&hit.type!=='polyline'){ notify('트림 불가: '+hit.type); return; }
      pushUndo();
      _trimDragUndo=true;
      doTrimById(hit, rawWp);
      render();
    }
    return;
  }

  // Extend
  if(tool==='extend'){
    const hit=hitTest(rawWp.x,rawWp.y);
    if(hit && (hit.type==='line'||hit.type==='wall')){
      doExtend(hit, rawWp);
      render();
    } else {
      notify('연장할 선 클릭');
    }
    return;
  }

  if(tool==='select'){
    // hit test
    const hit=hitTest(wp.x,wp.y);
    if(hit){
      if(!e.shiftKey && !selectedIds.has(hit.id)){
        selectedIds.clear();
      }
      if(e.shiftKey && selectedIds.has(hit.id)){
        selectedIds.delete(hit.id);
      } else {
        selectedIds.add(hit.id);
      }
      // \uAC1D\uCCB4 \uB4DC\uB798\uADF8 \uC774\uB3D9 (Undo\uC6A9 \uC774\uB3D9 \uC804 \uC0C1\uD0DC \uC800\uC7A5)
      pushUndo();
      isDragging=true;
      dragStart=wp;
      dragOffsets=[];
      selectedIds.forEach(id=>{
        const obj=objects.find(o=>o.id===id);
        if(obj) dragOffsets.push({id, ox:wp.x-getObjX(obj), oy:wp.y-getObjY(obj)});
      });
      selBoxStart=null; selBoxEnd=null; // \uC120\uD0DD\uBC15\uC2A4 \uCD08\uAE30\uD654
    } else {
      // \uBE48 \uACF3 \uD074\uB9AD
      if(selBoxStart===null){
        // 1\uBC88\uC9F8 \uD074\uB9AD: \uC2DC\uC791\uC810 \uC124\uC815
        selectedIds.clear();
        var _rawSel=rawWp;
        selBoxStart=_rawSel; selBoxEnd=_rawSel;
        notify('\uB450\uBC88\uC9F8 \uC810 \uD074\uB9AD\uC73C\uB85C \uC120\uD0DD \uC601\uC5ED \uC644\uB8CC | ESC \uCDE8\uC18C');
      } else {
        // 2\uBC88\uC9F8 \uD074\uB9AD: \uC601\uC5ED \uD655\uC815
        var _rawSel2=rawWp;
        selBoxEnd=_rawSel2;
        const crossing = selBoxEnd.x < selBoxStart.x; // \uC624→\uC67C = crossing
        const rx1=Math.min(selBoxStart.x,selBoxEnd.x);
        const ry1=Math.min(selBoxStart.y,selBoxEnd.y);
        const rx2=Math.max(selBoxStart.x,selBoxEnd.x);
        const ry2=Math.max(selBoxStart.y,selBoxEnd.y);
        objects.forEach(o=>{
          const layer=layers.find(l=>l.id===o.layerId);
          if(layer&&!layer.visible) return;
          if(o.locked) return;
          if(crossing){
            // \uAD50\uCC28 \uC120\uD0DD: \uC2E4\uC81C \uC138\uADF8\uBA3C\uD2B8\uAC00 \uC120\uD0DD\uBC15\uC2A4\uC640 \uAC78\uCE58\uBA74 \uC120\uD0DD
            if(objectCrossesRect(o,rx1,ry1,rx2,ry2)) selectedIds.add(o.id);
          } else {
            // \uC708\uB3C4\uC6B0 \uC120\uD0DD: \uC644\uC804\uD788 \uD3EC\uD568\uB41C \uACBD\uC6B0\uB9CC
            if(objectInsideRect(o,rx1,ry1,rx2,ry2)) selectedIds.add(o.id);
          }
        });
        notify(`${crossing?'\uAD50\uCC28':'\uC708\uB3C4\uC6B0'} \uC120\uD0DD: ${selectedIds.size}\uAC1C`);
        selBoxStart=null; selBoxEnd=null;
      }
    }
    refreshUI({props:true});
    return;
  }

  if(tool==='move'||tool==='copyMove'){
    if(selectedIds.size===0){
      const hit=hitTest(wp.x,wp.y);
      if(hit){ selectedIds.add(hit.id); render(); notify('\uAE30\uC900\uC810\uC744 \uD074\uB9AD\uD558\uC138\uC694'); }
      return;
    }
    if(moveStep===0){
      moveBasePoint=wp;
      moveStep=1;
      notify('\uBAA9\uC801\uC9C0\uB97C \uD074\uB9AD\uD558\uC138\uC694');
      return;
    }
    if(moveStep===1){
      const dx=wp.x-moveBasePoint.x, dy=wp.y-moveBasePoint.y;
      pushUndo();
      if(tool==='copyMove'){
        const copies=[];
        selectedIds.forEach(id=>{
          const o=objects.find(x=>x.id===id);
          if(o){ const copy=JSON.parse(JSON.stringify(o)); copy.id=nextId(); moveObj(copy,getObjX(copy)+dx,getObjY(copy)+dy); copies.push(copy); }
        });
        copies.forEach(c=>objects.push(c));
        selectedIds.clear(); copies.forEach(c=>selectedIds.add(c.id));
        notify(`${copies.length}\uAC1C \uBCF5\uC0AC \uC644\uB8CC`);
      } else {
        selectedIds.forEach(id=>{
          const o=objects.find(x=>x.id===id);
          if(o&&!o.locked) moveObj(o,getObjX(o)+dx,getObjY(o)+dy);
        });
        notify('\uC774\uB3D9 \uC644\uB8CC');
      }
      moveStep=0; moveBasePoint=null;
      refreshUI({status:true});
      setTool('select');
      return;
    }
  }

  // ── Scale 도구 ───────────────────────────────
  if(tool==='scale'){
    if(selectedIds.size===0){
      const hit=hitTest(wp.x,wp.y);
      if(hit){ selectedIds.add(hit.id); render(); notify('\uAE30\uC900\uC810 \uD074\uB9AD \u2192 \uBC30\uC728 \uC785\uB825'); }
      return;
    }
    if(moveStep===0){
      moveBasePoint=wp;
      scaleRefDistance=getScaleReferenceDistance(wp);
      moveStep=1;
      showScaleInput(e.clientX, e.clientY);
      notify(scaleRefDistance>0 ? '배율 입력 또는 두번째 점 클릭' : '배율 입력 후 Enter');
      renderOverlay();
      return;
    }
    if(moveStep===1){
      const ratio=getScaleRatioFromPoint(wp);
      if(ratio>0){
        applyScale(ratio);
      } else {
        showScaleInput(e.clientX, e.clientY);
        notify('배율을 입력하세요');
      }
      return;
    }
    return;
  }

  // ── Rotate 도구 (RO) ──────────────────────────
  if(tool==='rotate'){
    if(selectedIds.size===0){
      const hit=hitTest(wp.x,wp.y);
      if(hit){ selectedIds.add(hit.id); render(); notify('기준점을 클릭하세요'); }
      return;
    }
    if(rotateStep===0){
      rotateBase=wp;
      rotateStep=1;
      notify('회전 각도 지정: 클릭 또는 각도 입력 후 Enter');
      // 각도 입력창 표시
      showRotateInput(e.clientX, e.clientY);
      return;
    }
    if(rotateStep===1){
      // 마우스 클릭으로 각도 결정 (직교모드: 90° 단위 스냅)
      var ang=Math.atan2(-(wp.y-rotateBase.y), wp.x-rotateBase.x);
      if(orthoOn) ang=Math.round(ang/(Math.PI/2))*(Math.PI/2);
      applyRotate(ang);
      return;
    }
    return;
  }

  // ── Mirror 도구 (MI) ──────────────────────────
  if(tool==='mirror'){
    if(selectedIds.size===0){
      const hit=hitTest(wp.x,wp.y);
      if(hit){ selectedIds.add(hit.id); render(); notify('대칭축 첫번째 점 클릭'); }
      return;
    }
    if(mirrorStep===0){
      mirrorP1=wp;
      mirrorStep=1;
      notify('대칭축 두번째 점 클릭');
      return;
    }
    if(mirrorStep===1){
      // 직교모드: 수평/수직 스냅
      if(orthoOn){
        var mdx=Math.abs(wp.x-mirrorP1.x), mdy=Math.abs(wp.y-mirrorP1.y);
        if(mdx>=mdy) mirrorP2={x:wp.x, y:mirrorP1.y};
        else mirrorP2={x:mirrorP1.x, y:wp.y};
      } else {
        mirrorP2=wp;
      }
      // 원본 삭제 여부 묻기
      mirrorStep=2;
      notify('원본 객체를 삭제하시겠습니까? Y=삭제 / N=유지 (Enter=N)');
      return;
    }
    if(mirrorStep===2){
      // 클릭 무시 — 키보드(Y/N)로만 처리
      return;
    }
    return;
  }

  if(tool==='line'||tool==='wall'||tool==='rect'||tool==='circle'){
    if(!isDrawing){
      isDrawing=true; drawStart=wp;
      // \uC120/\uBCBD\uCCB4: \uCCAB \uC810 \uCC0D\uC740 \uD6C4 \uAE38\uC774 \uC785\uB825\uCC3D \uD45C\uC2DC
      if(tool==='line'||tool==='wall'){
        showDynLenInput(e.clientX, e.clientY);
      }
    }
    else {
      let endPt=wp;
      if(tool==='line'||tool==='wall') endPt=orthoPoint(drawStart,wp);
      commitShape(drawStart,endPt);
      // \uC120/\uBCBD\uCCB4: \uC5F0\uC18D \uADF8\uB9AC\uAE30 - \uB05D\uC810\uC5D0\uC11C \uB2E4\uC2DC \uC785\uB825\uCC3D
      if(tool==='line'||tool==='wall'){
        showDynLenInput(e.clientX, e.clientY);
      } else {
        isDrawing=false; drawStart=null;
      }
    }
    return;
  }

  if(tool==='polyline'){
    const base = polyPoints.length>0 ? polyPoints[polyPoints.length-1] : wp;
    const pt = orthoPoint(base, wp);
    polyPoints.push(pt);
    renderOverlay();
    return;
  }

  if(tool==='dim'){
    if(dimStep===0){ dimP1=wp; dimStep=1; }
    else if(dimStep===1){ dimP2=wp; dimStep=2; }
    else if(dimStep===2){
      // compute offset from dim line
      const off=30/viewZoom;
      pushUndo();
      objects.push({
        id:nextId(), type:'dim', dimType:'linear',
        x1:dimP1.x,y1:dimP1.y, x2:dimP2.x,y2:dimP2.y,
        offset:off, layerId:layers[currentLayer].id,
        color:curStyle.color, lineWidth:curStyle.lineWidth
      });
      dimStep=0; dimP1=null; dimP2=null;
      render();
      setTool('select');
    }
    return;
  }

  if(tool==='offset'){
    const hit=hitTest(wp.x,wp.y);
    if(hit && (hit.type==='line'||hit.type==='rect'||hit.type==='polyline'||hit.type==='wall')){
      cadPrompt('오프셋 거리 (px):', '100', function(val){
        const d=parseFloat(val);
        if(d && d>0){
          pushUndo();
          const copy=JSON.parse(JSON.stringify(hit));
          copy.id=nextId();
          if(hit.type==='line'||hit.type==='wall'){
            const dx=hit.x2-hit.x1,dy=hit.y2-hit.y1,len=Math.sqrt(dx*dx+dy*dy);
            const nx=-dy/len*d,ny=dx/len*d;
            copy.x1+=nx;copy.y1+=ny;copy.x2+=nx;copy.y2+=ny;
          } else if(hit.type==='rect'){
            copy.x-=d;copy.y-=d;copy.w+=d*2;copy.h+=d*2;
          } else if(hit.type==='polyline'){
            copy.points=copy.points.map(p=>({x:p.x,y:p.y+d}));
          }
          objects.push(copy);
          render();
        }
        setTool('select');
      }, function(){ setTool('select'); });
    } else {
      setTool('select');
    }
    return;
  }

  if(tool==='trim'){
    // trim은 onMouseDown에서 처리됨
    return;
  }

  if(tool==='text'){
    if(textStep===0){
      // 1단계: 위치 지정
      textOrigin = {x:wp.x, y:wp.y};
      textFontSize = 14;
      textAngle = 0;
      textStep = 1;
      notify('문자 높이 지정 | 클릭 또는 숫자 입력 후 Enter');
      return;
    }
    if(textStep===1){
      // 2단계: 문자 높이 확정 → 각도 단계로
      var dy = Math.abs(wp.y - textOrigin.y);
      var dx = Math.abs(wp.x - textOrigin.x);
      textFontSize = Math.max(3, Math.sqrt(dx*dx+dy*dy));
      textStep = 2;
      notify('문자 각도 지정 | 클릭 또는 숫자 입력 후 Enter (기본=0)');
      return;
    }
    if(textStep===2){
      // 3단계: 각도 확정 → 인라인 입력
      textAngle = Math.atan2(-(wp.y - textOrigin.y), wp.x - textOrigin.x);
      textStep = 3;
      const sp = toScreen(textOrigin.x, textOrigin.y);
      showInlineTextEditor(sp.x, sp.y, textFontSize, curStyle.color, '', function(txt){
        pushUndo();
        objects.push({
          id:nextId(), type:'text', text:txt,
          x:textOrigin.x, y:textOrigin.y, fontSize:textFontSize,
          angle:textAngle, color:curStyle.color, layerId:layers[currentLayer].id
        });
        render();
        textStep=0; textOrigin=null;
      });
      return;
    }
    return;
  }

  if(tool==='annot'){
    if(annotStep===0){
      // 1단계: 대상 객체 클릭 (꼬리 시작점)
      const hit=hitTest(wp.x,wp.y);
      if(hit){
        // 객체 중심 또는 클릭 위치를 꼬리 시작점으로
        annotArrowTo={x:wp.x, y:wp.y};
      } else {
        // 빈 곳 클릭해도 꼬리 시작점으로 사용
        annotArrowTo={x:wp.x, y:wp.y};
      }
      annotStep=1;
      return;
    }
    if(annotStep===1){
      // 2단계: 말풍선 위치 클릭 → 인라인 텍스트 입력
      const sp = toScreen(wp.x, wp.y);
      const _arrowTo = annotArrowTo;
      showInlineTextEditor(sp.x, sp.y, 12, curStyle.color, '', function(txt){
        pushUndo();
        objects.push({
          id:nextId(), type:'annot', text:txt,
          x:wp.x, y:wp.y, bubble:true,
          arrowTo:_arrowTo,
          fontSize:12, color:curStyle.color,
          layerId:layers[currentLayer].id
        });
        render();
      });
      annotStep=0; annotArrowTo=null;
      return;
    }
    return;
  }
}

function onMouseMove(e){
  const r=wrap.getBoundingClientRect();
  const wp=getWorldPos(e, r);
  lastMouseWorld=wp;
  lastMouseClient={x:e.clientX-r.left, y:e.clientY-r.top};

  setMouseCoordStatus(wp.x, wp.y);

  // Middle mouse pan (붙여넣기 미리보기 중에도 작동)
  if(e.buttons===4){
    if(!_panStart){ _panStart={x:e.clientX,y:e.clientY,vx:viewX,vy:viewY}; }
    viewX=_panStart.vx+(e.clientX-_panStart.x);
    viewY=_panStart.vy+(e.clientY-_panStart.y);
    render();
    return;
  } else if(!(e.buttons&4)){ _panStart=null; }

  // 붙여넣기 미리보기: 마우스 따라 오버레이 갱신
  if(_pastePreview){ renderOverlay(); return; }

  // 트림 드래그: 드래그 선분과 교차하는 객체 자동 트림 (펜스 방식)
  if(tool==='trim' && trimStep===1 && _trimMouseDown){
    e.preventDefault();
    const rawWp=toWorld(e.clientX-r.left, e.clientY-r.top);
    const prev=_trimDragPath.length>0?_trimDragPath[_trimDragPath.length-1]:rawWp;
    _trimDragPath.push({x:rawWp.x, y:rawWp.y});

    // 이전 점~현재 점 사이를 촘촘히 샘플링해서 hitTest
    const ddx=rawWp.x-prev.x, ddy=rawWp.y-prev.y;
    const dist=Math.sqrt(ddx*ddx+ddy*ddy);
    const step=3/viewZoom; // 3px 간격
    const samples=Math.max(1, Math.ceil(dist/step));
    const trimmedIds=new Set();
    let didTrim=false;
    for(let s=0;s<=samples;s++){
      const frac=s/samples;
      const sx=prev.x+ddx*frac, sy=prev.y+ddy*frac;
      const hit=hitTest(sx,sy);
      if(hit && (hit.type==='line'||hit.type==='wall'||hit.type==='polyline') && !trimmedIds.has(hit.id)){
        trimmedIds.add(hit.id);
        doTrimById(hit, {x:sx, y:sy});
        didTrim=true;
      }
    }
    if(didTrim) render();
    else renderOverlay();
    return;
  }

  // ── Dynamic Tip ──────────────────────────────
  if(tool === 'select'){
    hideDynTip();
  } else {
    let txt = '';
    if(tool==='line'||tool==='wall'){
      if(isDrawing && drawStart){
        const ep = orthoOn ? orthoPoint(drawStart,wp) : wp;
        const d = dist2(drawStart, ep);
        const ang = Math.atan2(ep.y-drawStart.y, ep.x-drawStart.x)*180/Math.PI;
        txt = `${formatDim(d)}  \uAC01\uB3C4:${ang.toFixed(1)}°${orthoOn?' [\uC9C1\uAD50]':''}`;
      } else {
        txt = (tool==='line' ? '\uC120' : '\uBCBD\uCCB4') + (orthoOn?' [\uC9C1\uAD50ON]':'') + ' | \uD074\uB9AD: \uC2DC\uC791\uC810';
      }
    } else if(tool==='rect'){
      if(isDrawing && drawStart){
        const w=Math.abs(wp.x-drawStart.x), h=Math.abs(wp.y-drawStart.y);
        txt = `W:${formatDim(w)}  H:${formatDim(h)}`;
      } else { txt = '\uC0AC\uAC01\uD615 | \uD074\uB9AD: \uC2DC\uC791\uC810'; }
    } else if(tool==='circle'){
      if(isDrawing && drawStart){
        txt = `R:${formatDim(dist2(drawStart,wp))}`;
      } else { txt = '\uC6D0 | \uD074\uB9AD: \uC911\uC2EC\uC810'; }
    } else if(tool==='polyline'){
      if(polyPoints.length===0){
        txt = 'PL | \uD074\uB9AD: \uCCAB\uBC88\uC9F8 \uC810';
      } else {
        const last = polyPoints[polyPoints.length-1];
        const d = dist2(last, wp);
        txt = `PL ${polyPoints.length}\uC810 | L:${formatDim(d)}  [Enter/\uC6B0\uD074\uB9AD:\uC644\uB8CC]`;
      }
    } else if(tool==='dim'){
      txt = dimStep===0?'\uCE58\uC218 | P1 \uD074\uB9AD':dimStep===1?'\uCE58\uC218 | P2 \uD074\uB9AD':'\uCE58\uC218 | \uC704\uCE58 \uD074\uB9AD';
    } else if(tool==='offset'){
      txt = '\uC624\uD504\uC14B | \uAC1D\uCCB4 \uD074\uB9AD \uD6C4 \uAC70\uB9AC \uC785\uB825';
    } else if(tool==='trim'){
      txt = trimStep===0?'트림 | 기준선 선택 (Enter/Space: 확정)':'트림 | 잘라낼 부분 클릭/드래그 (ESC: 종료)';
    } else if(tool==='move'){
      txt = moveStep===0?'\uC774\uB3D9 | \uAE30\uC900\uC810 \uD074\uB9AD': `\uC774\uB3D9 | \uAC70\uB9AC:${formatDim(dist2(moveBasePoint,wp))}  \uBAA9\uC801\uC9C0 \uD074\uB9AD`;
    } else if(tool==='copyMove'){
      txt = moveStep===0?'\uBCF5\uC0AC\uC774\uB3D9 | \uAE30\uC900\uC810 \uD074\uB9AD': `\uBCF5\uC0AC\uC774\uB3D9 | \uAC70\uB9AC:${formatDim(dist2(moveBasePoint,wp))}  \uBAA9\uC801\uC9C0 \uD074\uB9AD`;
    } else if(tool==='scale'){
      const ratio=getScaleRatioFromPoint(wp);
      txt = moveStep===0
        ? '스케일 | 기준점 클릭'
        : ratio>0
          ? `스케일 | 배율:${ratio.toFixed(3)}x  Enter/두번째 클릭 적용`
          : '스케일 | 배율 입력 후 Enter';
    } else if(tool==='text'){ txt='\uD14D\uC2A4\uD2B8 | \uD074\uB9AD: \uC704\uCE58';
    } else if(tool==='annot'){ txt=annotStep===0?'주석 | 대상 객체(꼬리 시작점) 클릭':'주석 | 말풍선 위치 클릭'; }

    if(txt){
      // \uB9C8\uC6B0\uC2A4 \uC624\uB978\uCABD \uC544\uB798 18px \uC624\uD504\uC14B
      let tx = e.clientX + 18;
      let ty = e.clientY + 18;
      // \uD654\uBA74 \uBC16 \uBC29\uC9C0
      if(tx + 260 > window.innerWidth) tx = e.clientX - 270;
      if(ty + 30 > window.innerHeight) ty = e.clientY - 36;
      showDynTip(txt, tx, ty);
    } else {
      hideDynTip();
    }
  }
  // ─────────────────────────────────────────────

  if(isDragging && dragStart && e.buttons===1){
    dragOffsets.forEach(d=>{
      const obj=objects.find(o=>o.id===d.id);
      if(obj && !obj.locked) moveObj(obj, wp.x-d.ox, wp.y-d.oy);
    });
    render(); return;
  }

  // \uC120\uD0DD\uBC15\uC2A4 1\uBC88\uC9F8 \uD074\uB9AD \uD6C4 \uB9C8\uC6B0\uC2A4 \uB530\uB77C\uAC00\uAE30 (\uBC84\uD2BC \uB204\uB97C \uD544\uC694 \uC5C6\uC74C)
  if(selBoxStart){
    selBoxEnd=toWorld(e.clientX-r.left, e.clientY-r.top);
    renderOverlay(); return;
  }

  renderOverlay();
}

let _panStart=null;

function onMouseUp(e){
  _trimDragUndo=false;
  _trimMouseDown=false;
  _trimDragPath=[];
  if(e.button===1){ _panStart=null; return; }
  if(isDragging){
    isDragging=false;
    refreshUI({props:true});
  }
  // 객체 선택 시 속성 패널 자동 표시
  if(tool==='select' && selectedIds.size>0){
    syncPropsFromSelection();
    switchRTab('props', null);
  }
}

function onDblClick(e){
  if(tool==='select'){
    const wp=getWorldPos(e);
    const hit=hitTest(wp.x,wp.y);
    if(!hit){ fitAll(); return; }
    // 치수 더블클릭 → 텍스트 편집
    if(hit.type==='dim'){
      const dist = hit.dimType==='linear' ? Math.sqrt((hit.x2-hit.x1)**2+(hit.y2-hit.y1)**2) : 0;
      const autoText = hit.dimType==='linear' ? formatDim(dist) : hit.dimType==='radius' ? 'R'+formatDim(hit.r||0) : '';
      const current = hit.customText!=null ? hit.customText : autoText;
      cadPrompt('치수 텍스트 수정 (빈칸=자동 계산값 복원):', current, function(val){
        pushUndo();
        hit.customText = val.trim()==='' ? null : val;
        render();
      });
      return;
    }
    // 텍스트/주석 더블클릭 → 인라인 편집
    if(hit.type==='text'||hit.type==='annot'){
      const sp = toScreen(hit.x, hit.y);
      const editHit = hit;
      showInlineTextEditor(sp.x, sp.y, hit.fontSize||14, hit.color||'#ffffff', hit.text||'', function(val){
        if(val!==editHit.text){ pushUndo(); editHit.text=val; render(); }
      });
      return;
    }
  }
}

function onWheel(e){
  e.preventDefault();
  const r=wrap.getBoundingClientRect();
  const cx=e.clientX-r.left, cy=e.clientY-r.top;
  const factor = e.deltaY<0?1.12:1/1.12;
  viewX = cx-(cx-viewX)*factor;
  viewY = cy-(cy-viewY)*factor;
  viewZoom *= factor;
  viewZoom = Math.max(0.001, Math.min(100, viewZoom));
  // 줌 후 월드 좌표 재계산
  lastMouseWorld = toWorld(cx, cy);
  lastMouseClient = {x:cx, y:cy};
  render();
}

function onContextMenu(e){
  e.preventDefault();
  // \uD3F4\uB9AC\uC120 \uADF8\uB9AC\uB294 \uC911 \uC6B0\uD074\uB9AD → \uC644\uB8CC
  if(tool==='polyline' && polyPoints.length>=2){
    commitPolyline();
    return;
  }
  const menu=document.getElementById('ctxMenu');
  menu.style.display='block';
  menu.style.left=e.clientX+'px';
  menu.style.top=e.clientY+'px';
}
document.addEventListener('click',()=>{ document.getElementById('ctxMenu').style.display='none'; });

// ── Shape commit ─────────────────────────────
function commitShape(s,e){
  pushUndo();
  const base={id:nextId(), layerId:layers[currentLayer].id,
    color:curStyle.color, lineWidth:curStyle.lineWidth,
    lineDash:curStyle.lineDash, lineCap:curStyle.lineCap,
    fill:curStyle.fill, fillColor:curStyle.fillColor, alpha:curStyle.alpha};

  if(tool==='line'){
    objects.push({...base, type:'line', x1:s.x,y1:s.y,x2:e.x,y2:e.y});
    // \uC5F0\uC18D \uC774\uC5B4 \uADF8\uB9AC\uAE30 - \uB05D\uC810\uC774 \uB2E4\uC74C \uC2DC\uC791\uC810
    drawStart = {x:e.x, y:e.y};
    isDrawing = true;
  }
  else if(tool==='wall'){
    objects.push({...base, type:'wall', x1:s.x,y1:s.y,x2:e.x,y2:e.y, wallWidth:curStyle.wallWidth});
    // \uC5F0\uC18D \uC774\uC5B4 \uADF8\uB9AC\uAE30
    drawStart = {x:e.x, y:e.y};
    isDrawing = true;
  }
  else if(tool==='rect'){
    const rx=Math.min(s.x,e.x), ry=Math.min(s.y,e.y), rw=Math.abs(e.x-s.x), rh=Math.abs(e.y-s.y);
    objects.push({...base, type:'rect', x:rx,y:ry,w:rw,h:rh});
    refreshUI({status:true}); setTool('select'); return;
  }
  else if(tool==='circle'){
    objects.push({...base, type:'circle', cx:s.x,cy:s.y,r:dist2(s,e)});
    refreshUI({status:true}); setTool('select'); return;
  }
  refreshUI({status:true});
}
function commitPolyline(){
  if(polyPoints.length<2) return;
  pushUndo();
  objects.push({
    id:nextId(), type:'polyline', points:[...polyPoints],
    closed:false, layerId:layers[currentLayer].id,
    color:curStyle.color, lineWidth:curStyle.lineWidth,
    lineDash:curStyle.lineDash, lineCap:curStyle.lineCap,
    alpha:curStyle.alpha
  });
  polyPoints=[];
  render();
  setTool('select');
}

function hitTest(wx,wy){
  // 화면에서 8px 범위를 월드 좌표로 변환 (줌/스케일 무관하게 일정 클릭 범위)
  const tol=8/viewZoom;
  for(let i=objects.length-1;i>=0;i--){
    const o=objects[i];
    const layer=layers.find(l=>l.id===o.layerId);
    if(layer&&!layer.visible) continue;
    if(hitObject(o,wx,wy,tol)) return o;
  }
  return null;
}

function hitObject(o,wx,wy,tol){
  if(o.type==='line'||o.type==='wall'){
    return distToSeg(wx,wy,o.x1,o.y1,o.x2,o.y2)<(o.type==='wall'?(o.wallWidth||200)/2+tol:tol);
  }
  if(o.type==='rect'){
    // fill이 있으면 내부 클릭 허용, 없으면 4변 선 근처만 판정
    if(o.fill) return wx>=o.x-tol&&wx<=o.x+o.w+tol&&wy>=o.y-tol&&wy<=o.y+o.h+tol;
    var x2=o.x+o.w, y2=o.y+o.h;
    return distToSeg(wx,wy,o.x,o.y,x2,o.y)<tol || distToSeg(wx,wy,x2,o.y,x2,y2)<tol ||
           distToSeg(wx,wy,x2,y2,o.x,y2)<tol || distToSeg(wx,wy,o.x,y2,o.x,o.y)<tol;
  }
  if(o.type==='circle'){
    const d=dist2({x:wx,y:wy},{x:o.cx,y:o.cy});
    return Math.abs(d-o.r)<tol;
  }
  if(o.type==='polyline'){
    for(let i=0;i<o.points.length-1;i++){
      if(distToSeg(wx,wy,o.points[i].x,o.points[i].y,o.points[i+1].x,o.points[i+1].y)<tol) return true;
    }
    return false;
  }
  if(o.type==='text'){
    var fs=o.fontSize||12;
    var tw=(o.text||'').length*fs*0.6; // 대략적 텍스트 폭
    return wx>=o.x-tol && wx<=o.x+tw+tol && wy>=o.y-fs-tol && wy<=o.y+tol;
  }
  if(o.type==='annot'){
    const fs=o.fontSize||12;
    const fw=o.fontWeight||'normal';
    const ff=o.fontFamily||'Noto Sans KR';
    ctx.font=`${fw} ${fs}px ${ff}`;
    const tw=ctx.measureText(o.text||'').width;
    const pad=10, bw=Math.max(tw+pad*2, 40), bh=fs+4+pad*2;
    const margin=tol+10/viewZoom;
    // 풍선 본체 영역
    if(wx>=o.x-margin && wx<=o.x+bw+margin && wy>=o.y-margin && wy<=o.y+bh+margin) return true;
    // 꼬리 영역
    if(o.arrowTo){
      if(distToSeg(wx,wy,o.x+bw/2,o.y+bh/2,o.arrowTo.x,o.arrowTo.y)<margin*2) return true;
    }
    return false;
  }
  if(o.type==='dim'){
    return Math.abs(wx-(o.x1+o.x2)/2)<30&&Math.abs(wy-(o.y1+o.y2)/2)<30;
  }
  if(o.type==='table'||o.type==='bgimage'){
    const bb=getBounds(o);
    if(bb) return wx>=bb.x-tol&&wx<=bb.x+bb.w+tol&&wy>=bb.y-tol&&wy<=bb.y+bb.h+tol;
  }
  if(o.type==='block'&&o.children){
    var bb=getBounds(o);
    if(bb) return wx>=bb.x-tol&&wx<=bb.x+bb.w+tol&&wy>=bb.y-tol&&wy<=bb.y+bb.h+tol;
  }
  return false;
}

function distToSeg(px,py,x1,y1,x2,y2){
  const dx=x2-x1,dy=y2-y1,l2=dx*dx+dy*dy;
  if(l2===0) return dist2({x:px,y:py},{x:x1,y:y1});
  const t=Math.max(0,Math.min(1,((px-x1)*dx+(py-y1)*dy)/l2));
  return dist2({x:px,y:py},{x:x1+t*dx,y:y1+t*dy});
}

// ── Object move ──────────────────────────────
function getObjX(o){
  if(o.type==='line'||o.type==='wall') return o.x1;
  if(o.type==='rect') return o.x;
  if(o.type==='circle') return o.cx;
  if(o.type==='polyline') return o.points[0]?.x||0;
  return o.x||0;
}
function getObjY(o){
  if(o.type==='line'||o.type==='wall') return o.y1;
  if(o.type==='rect') return o.y;
  if(o.type==='circle') return o.cy;
  if(o.type==='polyline') return o.points[0]?.y||0;
  return o.y||0;
}

function getScalePoints(o){
  if(o.type==='line'||o.type==='wall'){
    return [{x:o.x1,y:o.y1},{x:o.x2,y:o.y2}];
  }
  if(o.type==='polyline'){
    return Array.isArray(o.points) ? o.points : [];
  }
  const b=getBounds(o);
  if(b){
    return [
      {x:b.x,y:b.y},
      {x:b.x+b.w,y:b.y},
      {x:b.x,y:b.y+b.h},
      {x:b.x+b.w,y:b.y+b.h},
      {x:b.x+b.w/2,y:b.y+b.h/2}
    ];
  }
  if(o.x!==undefined && o.y!==undefined){
    return [{x:o.x,y:o.y}];
  }
  return [];
}

function getScaleReferenceDistance(basePoint){
  let maxDist=0;
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(!o||o.locked) return;
    getScalePoints(o).forEach(function(p){
      const d=dist2(basePoint,p);
      if(d>maxDist) maxDist=d;
    });
  });
  return maxDist;
}

function getScaleRatioFromPoint(point){
  if(!moveBasePoint||!scaleRefDistance) return 0;
  const currentDist=dist2(moveBasePoint, point);
  if(!currentDist) return 0;
  return currentDist/scaleRefDistance;
}

function moveObj(o,nx,ny){
  const ox=getObjX(o),oy=getObjY(o);
  const dx=nx-ox,dy=ny-oy;
  if(o.type==='line'||o.type==='wall'){ o.x1+=dx;o.y1+=dy;o.x2+=dx;o.y2+=dy; }
  else if(o.type==='rect'){ o.x+=dx;o.y+=dy; }
  else if(o.type==='circle'){ o.cx+=dx;o.cy+=dy; }
  else if(o.type==='polyline'){ o.points.forEach(p=>{p.x+=dx;p.y+=dy;}); }
  else if(o.type==='dim'){ o.x1+=dx;o.y1+=dy;o.x2+=dx;o.y2+=dy; }
  else if(o.type==='block'&&o.children){ o.x+=dx;o.y+=dy; o.children.forEach(function(c){moveObj(c,getObjX(c)+dx,getObjY(c)+dy);}); }
  else { o.x=(o.x||0)+dx; o.y=(o.y||0)+dy; }
  if(o.arrowTo){ o.arrowTo.x+=dx; o.arrowTo.y+=dy; }
}

function scaleObjAround(o,bx,by,ratio){
  if(o.type==='line'||o.type==='wall'){
    o.x1=bx+(o.x1-bx)*ratio; o.y1=by+(o.y1-by)*ratio;
    o.x2=bx+(o.x2-bx)*ratio; o.y2=by+(o.y2-by)*ratio;
  } else if(o.type==='rect'){
    o.x=bx+(o.x-bx)*ratio; o.y=by+(o.y-by)*ratio;
    o.w*=ratio; o.h*=ratio;
  } else if(o.type==='circle'){
    o.cx=bx+(o.cx-bx)*ratio; o.cy=by+(o.cy-by)*ratio;
    o.r*=ratio;
  } else if(o.type==='polyline'){
    o.points=o.points.map(p=>({x:bx+(p.x-bx)*ratio, y:by+(p.y-by)*ratio}));
  } else if(o.type==='bgimage'){
    o.x=bx+(o.x-bx)*ratio; o.y=by+(o.y-by)*ratio;
    o.w*=ratio; o.h*=ratio;
  } else {
    if(o.x!==undefined){ o.x=bx+(o.x-bx)*ratio; o.y=by+(o.y-by)*ratio; }
    if(o.fontSize) o.fontSize*=ratio;
    if(o.arrowTo){
      o.arrowTo.x=bx+(o.arrowTo.x-bx)*ratio;
      o.arrowTo.y=by+(o.arrowTo.y-by)*ratio;
    }
  }
}

// ── getBounds ────────────────────────────────
function getBounds(o){
  if(o.type==='line'||o.type==='wall'){
    return {x:Math.min(o.x1,o.x2),y:Math.min(o.y1,o.y2),w:Math.abs(o.x2-o.x1),h:Math.abs(o.y2-o.y1)};
  }
  if(o.type==='rect'||o.type==='bgimage') return {x:o.x,y:o.y,w:o.w,h:o.h};
  if(o.type==='table'){
    const totalW=(o.colW||[]).reduce((s,w)=>s+w,0)||200;
    const totalH=(o.rowH||[]).reduce((s,h)=>s+h,0)||22;
    return {x:o.x,y:o.y,w:totalW,h:totalH};
  }
  if(o.type==='circle') return {x:o.cx-o.r,y:o.cy-o.r,w:o.r*2,h:o.r*2};
  if(o.type==='polyline'&&o.points.length>0){
    const xs=o.points.map(p=>p.x),ys=o.points.map(p=>p.y);
    return {x:Math.min(...xs),y:Math.min(...ys),w:Math.max(...xs)-Math.min(...xs),h:Math.max(...ys)-Math.min(...ys)};
  }
  if(o.type==='text'){
    return {x:o.x,y:o.y-14,w:80,h:20};
  }
  if(o.type==='annot'){
    const fs=o.fontSize||12;
    const fw=o.fontWeight||'normal';
    const ff=o.fontFamily||'Noto Sans KR';
    ctx.font=`${fw} ${fs}px ${ff}`;
    const tw=ctx.measureText(o.text||'').width;
    const pad=10, bw=Math.max(tw+pad*2,40), bh=fs+4+pad*2;
    let x1=o.x, y1=o.y, x2=o.x+bw, y2=o.y+bh;
    if(o.arrowTo){
      x1=Math.min(x1,o.arrowTo.x); y1=Math.min(y1,o.arrowTo.y);
      x2=Math.max(x2,o.arrowTo.x); y2=Math.max(y2,o.arrowTo.y);
    }
    return {x:x1,y:y1,w:x2-x1,h:y2-y1};
  }
  if(o.type==='dim'){
    return {x:Math.min(o.x1,o.x2)-20,y:Math.min(o.y1,o.y2)-20,w:Math.abs(o.x2-o.x1)+40,h:Math.abs(o.y2-o.y1)+40};
  }
  if(o.type==='block'&&o.children&&o.children.length){
    var bx1=Infinity,by1=Infinity,bx2=-Infinity,by2=-Infinity;
    o.children.forEach(function(c){var bb=getBounds(c);if(bb){bx1=Math.min(bx1,bb.x);by1=Math.min(by1,bb.y);bx2=Math.max(bx2,bb.x+bb.w);by2=Math.max(by2,bb.y+bb.h);}});
    if(bx1!==Infinity) return {x:bx1,y:by1,w:bx2-bx1,h:by2-by1};
  }
  return null;
}

// \uC120\uBD84\uC774 \uC0AC\uAC01\uD615\uACFC \uAD50\uCC28\uD558\uB294\uC9C0 (Cohen-Sutherland)
function segIntersectsRect(x1,y1,x2,y2, rx1,ry1,rx2,ry2){
  function code(x,y){ return (x<rx1?1:0)|(x>rx2?2:0)|(y<ry1?4:0)|(y>ry2?8:0); }
  let c1=code(x1,y1), c2=code(x2,y2);
  while(true){
    if(!(c1|c2)) return true;   // \uB458 \uB2E4 \uC548\uCABD
    if(c1&c2) return false;     // \uAC19\uC740 \uBC14\uAE65\uCABD
    const c=c1||c2;
    let x,y;
    if(c&8){ x=x1+(x2-x1)*(ry2-y1)/(y2-y1); y=ry2; }
    else if(c&4){ x=x1+(x2-x1)*(ry1-y1)/(y2-y1); y=ry1; }
    else if(c&2){ y=y1+(y2-y1)*(rx2-x1)/(x2-x1); x=rx2; }
    else { y=y1+(y2-y1)*(rx1-x1)/(x2-x1); x=rx1; }
    if(c===c1){ x1=x;y1=y;c1=code(x1,y1); }
    else { x2=x;y2=y;c2=code(x2,y2); }
  }
}

// \uAD50\uCC28\uC120\uD0DD: \uC870\uAE08\uC774\uB77C\uB3C4 \uAC78\uCE58\uBA74 true
function objectCrossesRect(o,rx1,ry1,rx2,ry2){
  if(o.type==='line'||o.type==='wall'){
    return segIntersectsRect(o.x1,o.y1,o.x2,o.y2,rx1,ry1,rx2,ry2);
  }
  if(o.type==='rect'){
    // rect \uC790\uCCB4\uAC00 \uAD50\uCC28\uD558\uB294\uC9C0
    if(o.fill) return !(o.x>rx2||o.x+o.w<rx1||o.y>ry2||o.y+o.h<ry1);
    var _rx=o.x+o.w, _ry=o.y+o.h;
    return segIntersectsRect(o.x,o.y,_rx,o.y,rx1,ry1,rx2,ry2) ||
           segIntersectsRect(_rx,o.y,_rx,_ry,rx1,ry1,rx2,ry2) ||
           segIntersectsRect(_rx,_ry,o.x,_ry,rx1,ry1,rx2,ry2) ||
           segIntersectsRect(o.x,_ry,o.x,o.y,rx1,ry1,rx2,ry2);
  }
  if(o.type==='circle'){
    // fill 있으면 영역 겹침, 없으면 둘레가 선택박스와 교차하는지
    const cx=o.cx,cy=o.cy,r=o.r;
    if(o.fill){
      const nearX=Math.max(rx1,Math.min(rx2,cx));
      const nearY=Math.max(ry1,Math.min(ry2,cy));
      return (cx-nearX)**2+(cy-nearY)**2<=r*r;
    }
    // 선택박스 4변이 원 둘레와 교차하는지 체크
    function segCircle(ax,ay,bx,by){
      var dx=bx-ax,dy=by-ay;
      var fx=ax-cx,fy=ay-cy;
      var a2=dx*dx+dy*dy,b2=2*(fx*dx+fy*dy),c2=fx*fx+fy*fy-r*r;
      var disc=b2*b2-4*a2*c2;
      if(disc<0) return false;
      var sq=Math.sqrt(disc);
      var t1=(-b2-sq)/(2*a2), t2=(-b2+sq)/(2*a2);
      return (t1>=0&&t1<=1)||(t2>=0&&t2<=1);
    }
    return segCircle(rx1,ry1,rx2,ry1)||segCircle(rx2,ry1,rx2,ry2)||
           segCircle(rx2,ry2,rx1,ry2)||segCircle(rx1,ry2,rx1,ry1);
  }
  if(o.type==='polyline'&&o.points.length>0){
    // \uD3EC\uC778\uD2B8\uAC00 rect \uC548\uC5D0 \uC788\uAC70\uB098 \uC138\uADF8\uBA3C\uD2B8\uAC00 \uAC78\uCE58\uBA74 \uC120\uD0DD
    for(let i=0;i<o.points.length;i++){
      const p=o.points[i];
      if(p.x>=rx1&&p.x<=rx2&&p.y>=ry1&&p.y<=ry2) return true;
      if(i>0){
        if(segIntersectsRect(o.points[i-1].x,o.points[i-1].y,p.x,p.y,rx1,ry1,rx2,ry2)) return true;
      }
    }
    return false;
  }
  // text, annot, dim \uB4F1 - bb \uCCB4\uD06C
  const bb=getBounds(o);
  if(!bb) return false;
  return !(bb.x>rx2||bb.x+bb.w<rx1||bb.y>ry2||bb.y+bb.h<ry1);
}

// \uC708\uB3C4\uC6B0\uC120\uD0DD: \uAC1D\uCCB4 \uC804\uCCB4\uAC00 rect \uC548\uC5D0 \uC788\uC73C\uBA74 true
function objectInsideRect(o,rx1,ry1,rx2,ry2){
  if(o.type==='polyline'&&o.points.length>0){
    return o.points.every(p=>p.x>=rx1&&p.x<=rx2&&p.y>=ry1&&p.y<=ry2);
  }
  const bb=getBounds(o);
  if(!bb) return false;
  return bb.x>=rx1&&bb.y>=ry1&&bb.x+bb.w<=rx2&&bb.y+bb.h<=ry2;
}

// ── Edit Operations ──────────────────────────
function deleteSelected(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정할 수 없습니다'); return; }
  pushUndo();
  objects=objects.filter(o=>!selectedIds.has(o.id));
  selectedIds.clear(); refreshUI({status:true});
}

function copySelected(){
  clipboard=[];
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(o) clipboard.push(JSON.parse(JSON.stringify(o)));
  });
  notify(`${clipboard.length}\uAC1C \uBCF5\uC0AC\uB428`);
}

function pasteObjects(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정할 수 없습니다'); return; }
  if(!clipboard.length) return;
  _pastePreview=true;
  notify('붙여넣기 위치를 클릭하세요 (ESC=취소)');
  render();
}
function _commitPaste(wx,wy){
  if(!clipboard.length){ _pastePreview=false; return; }
  // clipboard 객체들의 중심점 계산
  let cx=0, cy=0;
  clipboard.forEach(o=>{ cx+=getObjX(o); cy+=getObjY(o); });
  cx/=clipboard.length; cy/=clipboard.length;
  const dx=wx-cx, dy=wy-cy;
  pushUndo();
  selectedIds.clear();
  clipboard.forEach(o=>{
    const copy=JSON.parse(JSON.stringify(o));
    copy.id=nextId();
    moveObj(copy, getObjX(copy)+dx, getObjY(copy)+dy);
    objects.push(copy);
    selectedIds.add(copy.id);
  });
  _pastePreview=false;
  refreshUI({status:true});
}
function _cancelPaste(){ _pastePreview=false; clipboard=[]; notify('붙여넣기 취소'); render(); }

function selectAll(){ objects.forEach(o=>selectedIds.add(o.id)); render(); }

function bringToFront(){
  selectedIds.forEach(id=>{
    const idx=objects.findIndex(o=>o.id===id);
    if(idx>=0){ const [o]=objects.splice(idx,1); objects.push(o); }
  });
  render();
}
function sendToBack(){
  selectedIds.forEach(id=>{
    const idx=objects.findIndex(o=>o.id===id);
    if(idx>=0){ const [o]=objects.splice(idx,1); objects.unshift(o); }
  });
  render();
}
function bringForward(){
  selectedIds.forEach(id=>{
    const idx=objects.findIndex(o=>o.id===id);
    if(idx>=0&&idx<objects.length-1){ const t=objects[idx]; objects[idx]=objects[idx+1]; objects[idx+1]=t; }
  });
  render();
}
function sendBackward(){
  selectedIds.forEach(id=>{
    const idx=objects.findIndex(o=>o.id===id);
    if(idx>0){ const t=objects[idx]; objects[idx]=objects[idx-1]; objects[idx-1]=t; }
  });
  render();
}

function toggleLock(){
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(o) o.locked=!o.locked;
  });
  render();
}

// ── Properties ──────────────────────────────
// \uC120\uD0DD\uB41C \uAC1D\uCCB4\uC758 \uC18D\uC131\uC744 \uD328\uB110\uC5D0 \uBC18\uC601
function syncPropsFromSelection(){
  var infoEl=document.getElementById('propInfo');
  var lenRow=document.getElementById('propLenRow');
  var areaRow=document.getElementById('propAreaRow');
  var annotGroup=document.getElementById('propAnnotGroup');
  var objGroup=document.getElementById('propObjGroup');

  // 객체 정보 필드 숨김 헬퍼
  var objRows=['propRow_x','propRow_y','propRow_x2','propRow_y2','propRow_w','propRow_h','propRow_r','propRow_fs','propRow_text','propRow_blockInfo','propRow_blockCnt'];
  function hideObjRows(){ objRows.forEach(function(id){var el=document.getElementById(id);if(el)el.style.display='none';}); }
  function showRow(id){ var el=document.getElementById(id);if(el)el.style.display='flex'; }

  if(selectedIds.size===0){
    infoEl.textContent='\uC5C6\uC74C';
    lenRow.style.display='none';
    areaRow.style.display='none';
    if(annotGroup) annotGroup.style.display='none';
    if(objGroup) objGroup.style.display='none';
    return;
  }

  var ids=[...selectedIds];
  var objs=ids.map(function(id){return objects.find(function(o){return o.id===id;});}).filter(Boolean);
  if(!objs.length) return;

  var typeNames={line:'\uC120',wall:'\uBCBD\uCCB4',rect:'\uC0AC\uAC01\uD615',circle:'\uC6D0',polyline:'\uD3F4\uB9AC\uC120',dim:'\uCE58\uC218',text:'\uD14D\uC2A4\uD2B8',annot:'\uC8FC\uC11D',table:'\uD45C',block:'\uBE14\uB85D'};

  if(objs.length===1){
    var o=objs[0];
    infoEl.textContent=(typeNames[o.type]||o.type)+' (id:'+o.id+')';

    // 객체 정보 패널
    if(objGroup){
      objGroup.style.display='block';
      hideObjRows();
      document.getElementById('prop_type').textContent=typeNames[o.type]||o.type;

      // 레이어 드롭다운 갱신
      var layerSel=document.getElementById('prop_layer');
      layerSel.innerHTML='';
      layers.forEach(function(l){
        var opt=document.createElement('option');
        opt.value=l.id; opt.textContent=l.name;
        if(l.id===o.layerId) opt.selected=true;
        layerSel.appendChild(opt);
      });

      // 타입별 좌표/크기 필드
      if(o.type==='line'||o.type==='wall'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_x2'); showRow('propRow_y2');
        document.getElementById('prop_x').value=Math.round(o.x1);
        document.getElementById('prop_y').value=Math.round(o.y1);
        document.getElementById('prop_x2').value=Math.round(o.x2);
        document.getElementById('prop_y2').value=Math.round(o.y2);
        document.getElementById('propLabel_x').textContent='시작 X';
        document.getElementById('propLabel_y').textContent='시작 Y';
      } else if(o.type==='rect'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_w'); showRow('propRow_h');
        document.getElementById('prop_x').value=Math.round(o.x);
        document.getElementById('prop_y').value=Math.round(o.y);
        document.getElementById('prop_w').value=Math.round(o.w);
        document.getElementById('prop_h').value=Math.round(o.h);
        document.getElementById('propLabel_x').textContent='X';
        document.getElementById('propLabel_y').textContent='Y';
      } else if(o.type==='circle'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_r');
        document.getElementById('prop_x').value=Math.round(o.cx);
        document.getElementById('prop_y').value=Math.round(o.cy);
        document.getElementById('prop_r').value=Math.round(o.r);
        document.getElementById('propLabel_x').textContent='중심 X';
        document.getElementById('propLabel_y').textContent='중심 Y';
      } else if(o.type==='text'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_fs'); showRow('propRow_text');
        document.getElementById('prop_x').value=Math.round(o.x);
        document.getElementById('prop_y').value=Math.round(o.y);
        document.getElementById('prop_fs').value=o.fontSize||12;
        document.getElementById('prop_text').value=o.text||'';
        document.getElementById('propLabel_x').textContent='X';
        document.getElementById('propLabel_y').textContent='Y';
      } else if(o.type==='annot'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_text');
        document.getElementById('prop_x').value=Math.round(o.x);
        document.getElementById('prop_y').value=Math.round(o.y);
        document.getElementById('prop_text').value=o.text||'';
        document.getElementById('propLabel_x').textContent='X';
        document.getElementById('propLabel_y').textContent='Y';
      } else if(o.type==='polyline'){
        showRow('propRow_x'); showRow('propRow_y');
        if(o.points&&o.points.length>0){
          document.getElementById('prop_x').value=Math.round(o.points[0].x);
          document.getElementById('prop_y').value=Math.round(o.points[0].y);
        }
        document.getElementById('propLabel_x').textContent='시작 X';
        document.getElementById('propLabel_y').textContent='시작 Y';
      } else if(o.type==='block'){
        showRow('propRow_x'); showRow('propRow_y'); showRow('propRow_blockInfo'); showRow('propRow_blockCnt');
        document.getElementById('prop_x').value=Math.round(o.x);
        document.getElementById('prop_y').value=Math.round(o.y);
        document.getElementById('prop_blockName').value=o.name||'블록';
        document.getElementById('prop_blockCnt').textContent=(o.children?o.children.length:0)+'개';
        document.getElementById('propLabel_x').textContent='X';
        document.getElementById('propLabel_y').textContent='Y';
      }
    }

    // 선 스타일 패널에 반영
    if(o.color) document.getElementById('prop_color').value=o.color;
    if(o.lineWidth) document.getElementById('prop_lw').value=o.lineWidth;
    if(o.lineDash) document.getElementById('prop_ls').value=o.lineDash;
    if(o.lineCap) document.getElementById('prop_cap').value=o.lineCap;
    if(o.fillColor) document.getElementById('prop_fill').value=o.fillColor;
    document.getElementById('prop_fillOn').checked=!!o.fill;
    document.getElementById('prop_alpha').value=o.alpha||1;
    if(o.wallWidth) document.getElementById('prop_wallW').value=o.wallWidth;

    // \uAE38\uC774 \uACC4\uC0B0
    let len=null, area=null;
    if(o.type==='line'||o.type==='wall'){
      len=Math.sqrt((o.x2-o.x1)**2+(o.y2-o.y1)**2);
    } else if(o.type==='circle'){
      len=2*Math.PI*o.r; // \uB458\uB808
      area=Math.PI*o.r*o.r;
    } else if(o.type==='rect'){
      len=2*(Math.abs(o.w)+Math.abs(o.h));
      area=Math.abs(o.w*o.h);
    } else if(o.type==='polyline'&&o.points.length>1){
      len=0;
      for(let i=1;i<o.points.length;i++){
        len+=Math.sqrt((o.points[i].x-o.points[i-1].x)**2+(o.points[i].y-o.points[i-1].y)**2);
      }
      // \uB2EB\uD78C \uD3F4\uB9AC\uC120 \uBA74\uC801 (Shoelace)
      if(o.closed&&o.points.length>2){
        let a=0;
        for(let i=0;i<o.points.length;i++){
          const j=(i+1)%o.points.length;
          a+=o.points[i].x*o.points[j].y;
          a-=o.points[j].x*o.points[i].y;
        }
        area=Math.abs(a/2);
      }
    }

    // 주석 속성 패널 표시
    if(annotGroup){
      if(o.type==='annot'){
        annotGroup.style.display='block';
        document.getElementById('prop_tailStyle').value=o.tailStyle||'arrow';
        document.getElementById('prop_tailWidth').value=o.tailWidth||2;
        document.getElementById('prop_bubbleShape').value=o.bubbleShape||'rounded';
        document.getElementById('prop_bubbleBg').value=o.bubbleBg||'#1a2a40';
        document.getElementById('prop_bubbleBorder').value=o.bubbleBorder||'#ffff88';
        document.getElementById('prop_bubbleBorderW').value=o.bubbleBorderW||1;
        document.getElementById('prop_bubbleAlpha').value=o.bubbleAlpha!=null?o.bubbleAlpha:0.9;
        document.getElementById('prop_annotFont').value=o.fontFamily||'Noto Sans KR';
        document.getElementById('prop_annotFontSize').value=o.fontSize||12;
        document.getElementById('prop_annotColor').value=o.annotColor||o.color||'#ffff88';
        document.getElementById('prop_annotWeight').value=o.fontWeight||'normal';
      } else {
        annotGroup.style.display='none';
      }
    }

    if(len!==null){
      lenRow.style.display='flex';
      const scaledLen=len*scale;
      document.getElementById('prop_len').value=Math.round(scaledLen);
      document.getElementById('prop_lenUnit').textContent=unit;
      // \uAC1D\uCCB4\uC5D0 \uC2E4\uC81C \uD53D\uC140 \uAE38\uC774 \uC800\uC7A5 (applyLength\uC5D0\uC11C \uC0AC\uC6A9)
      document.getElementById('prop_len').dataset.rawLen=len;
      document.getElementById('prop_len').dataset.objId=o.id;
    } else {
      lenRow.style.display='none';
    }

    if(area!==null){
      areaRow.style.display='flex';
      const scaledArea=(area*scale*scale)/(unit==='m'?1000000:unit==='cm'?10000:1);
      document.getElementById('prop_area').textContent=scaledArea.toFixed(2)+' '+unit+'²';
    } else {
      areaRow.style.display='none';
    }

  } else {
    // 다중 선택
    infoEl.textContent=objs.length+'개 선택됨';
    lenRow.style.display='none';
    areaRow.style.display='none';
    if(annotGroup) annotGroup.style.display='none';
    if(objGroup){
      objGroup.style.display='block'; hideObjRows();
      document.getElementById('prop_type').textContent=objs.length+'개 객체';
      // 레이어 DD: 공통이면 선택, 다르면 공란
      var layerSel=document.getElementById('prop_layer');
      layerSel.innerHTML='<option value="">-- 혼합 --</option>';
      layers.forEach(function(l){ var opt=document.createElement('option'); opt.value=l.id; opt.textContent=l.name; layerSel.appendChild(opt); });
      var layerIds=[...new Set(objs.map(function(o){return o.layerId;}))];
      if(layerIds.length===1) layerSel.value=layerIds[0];
      else layerSel.value='';
    }
    // 공통 색상이 같으면 반영
    var colors=[...new Set(objs.map(function(o){return o.color;}).filter(Boolean))];
    if(colors.length===1) document.getElementById('prop_color').value=colors[0];
  }
}

// \uAE38\uC774 \uC9C1\uC811 \uC218\uC815 → \uAC1D\uCCB4 \uBCC0\uD615
function applyLength(){
  const input=document.getElementById('prop_len');
  const newLen=parseFloat(input.value);
  const rawLen=parseFloat(input.dataset.rawLen);
  const objId=parseInt(input.dataset.objId);
  if(!newLen||!rawLen||isNaN(objId)) return;
  const o=objects.find(x=>x.id===objId);
  if(!o) return;
  const targetRaw=newLen/scale; // \uBAA9\uD45C \uD53D\uC140 \uAE38\uC774
  const ratio=targetRaw/rawLen;
  pushUndo();
  if(o.type==='line'||o.type==='wall'){
    const dx=o.x2-o.x1, dy=o.y2-o.y1;
    o.x2=o.x1+dx*ratio; o.y2=o.y1+dy*ratio;
  } else if(o.type==='circle'){
    o.r=o.r*ratio;
  } else if(o.type==='rect'){
    o.w=o.w*ratio; o.h=o.h*ratio;
  } else if(o.type==='polyline'){
    // \uB9C8\uC9C0\uB9C9 \uC810\uC744 \uBE44\uC728\uB85C \uB298\uB9AC\uAE30
    if(o.points.length>=2){
      const first=o.points[0];
      o.points=o.points.map(p=>({x:first.x+(p.x-first.x)*ratio, y:first.y+(p.y-first.y)*ratio}));
    }
  }
  refreshUI({props:true});
}

// 객체 정보 패널 값 적용
function applyObjProps(){
  if(selectedIds.size===0) return;

  // 다중 선택: 레이어 변경만 지원
  if(selectedIds.size>1){
    var layerSel=document.getElementById('prop_layer');
    if(layerSel&&layerSel.value!==''){
      pushUndo();
      var newLayerId=parseInt(layerSel.value);
      selectedIds.forEach(function(id){
        var o=objects.find(function(x){return x.id===id;});
        if(o&&!o.locked) o.layerId=newLayerId;
      });
      refreshUI();
    }
    return;
  }

  var id=[...selectedIds][0];
  var o=objects.find(function(x){return x.id===id;});
  if(!o||o.locked) return;
  pushUndo();

  // 레이어
  var layerSel=document.getElementById('prop_layer');
  if(layerSel) o.layerId=parseInt(layerSel.value)||0;

  // 좌표/크기
  var px=document.getElementById('prop_x');
  var py=document.getElementById('prop_y');
  var px2=document.getElementById('prop_x2');
  var py2=document.getElementById('prop_y2');
  var pw=document.getElementById('prop_w');
  var ph=document.getElementById('prop_h');
  var pr=document.getElementById('prop_r');
  var pfs=document.getElementById('prop_fs');
  var ptxt=document.getElementById('prop_text');
  var pbn=document.getElementById('prop_blockName');

  if(o.type==='line'||o.type==='wall'){
    if(px) o.x1=parseFloat(px.value)||0;
    if(py) o.y1=parseFloat(py.value)||0;
    if(px2) o.x2=parseFloat(px2.value)||0;
    if(py2) o.y2=parseFloat(py2.value)||0;
  } else if(o.type==='rect'){
    if(px) o.x=parseFloat(px.value)||0;
    if(py) o.y=parseFloat(py.value)||0;
    if(pw) o.w=parseFloat(pw.value)||1;
    if(ph) o.h=parseFloat(ph.value)||1;
  } else if(o.type==='circle'){
    if(px) o.cx=parseFloat(px.value)||0;
    if(py) o.cy=parseFloat(py.value)||0;
    if(pr) o.r=Math.max(1,parseFloat(pr.value)||1);
  } else if(o.type==='text'){
    if(px) o.x=parseFloat(px.value)||0;
    if(py) o.y=parseFloat(py.value)||0;
    if(pfs) o.fontSize=parseInt(pfs.value)||12;
    if(ptxt) o.text=ptxt.value;
  } else if(o.type==='annot'){
    if(px) o.x=parseFloat(px.value)||0;
    if(py) o.y=parseFloat(py.value)||0;
    if(ptxt) o.text=ptxt.value;
  } else if(o.type==='block'){
    var newX=parseFloat(px.value)||0, newY=parseFloat(py.value)||0;
    var dx=newX-o.x, dy=newY-o.y;
    if(dx||dy){ o.children.forEach(function(c){moveObj(c,getObjX(c)+dx,getObjY(c)+dy);}); }
    o.x=newX; o.y=newY;
    if(pbn) o.name=pbn.value;
  }
  render();
}

// ── 블록(그룹) 기능 ──
var _blockSeq=0;
function groupToBlock(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정 불가','warn'); return; }
  if(selectedIds.size<2){ notify('2개 이상 선택 후 그룹화하세요','warn'); return; }
  pushUndo();
  var ids=[...selectedIds];
  var children=[];
  // 바운딩 박스 계산
  var bx1=Infinity, by1=Infinity, bx2=-Infinity, by2=-Infinity;
  ids.forEach(function(id){
    var o=objects.find(function(x){return x.id===id;});
    if(!o) return;
    children.push(JSON.parse(JSON.stringify(o)));
    var bb=getBounds(o);
    if(bb){
      bx1=Math.min(bx1,bb.x); by1=Math.min(by1,bb.y);
      bx2=Math.max(bx2,bb.x+bb.w); by2=Math.max(by2,bb.y+bb.h);
    }
  });
  if(!children.length) return;
  // 원본 제거
  objects=objects.filter(function(o){return !selectedIds.has(o.id);});
  // 블록 생성
  _blockSeq++;
  var block={
    id:nextId(), type:'block', name:'블록'+_blockSeq,
    children:children, x:bx1, y:by1,
    layerId:children[0].layerId||0,
    color:'byblock', lineWidth:1, locked:false
  };
  objects.push(block);
  selectedIds.clear();
  selectedIds.add(block.id);
  refreshUI({status:true, props:true});
  notify(children.length+'개 객체 → 블록 그룹화');
}

function explodeBlock(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정 불가','warn'); return; }
  var ids=[...selectedIds];
  var blocks=ids.map(function(id){return objects.find(function(o){return o.id===id;});}).filter(function(o){return o&&o.type==='block';});
  if(!blocks.length){ notify('블록을 선택하세요','warn'); return; }
  pushUndo();
  selectedIds.clear();
  blocks.forEach(function(b){
    // 블록 제거, children을 objects에 추가
    objects=objects.filter(function(o){return o.id!==b.id;});
    b.children.forEach(function(c){
      c.id=nextId(); // 새 ID
      objects.push(c);
      selectedIds.add(c.id);
    });
  });
  refreshUI({status:true, props:true});
  notify('블록 해제 완료');
}

function applyProps(){
  curStyle.color=document.getElementById('prop_color').value;
  curStyle.lineWidth=parseFloat(document.getElementById('prop_lw').value)||1;
  curStyle.lineDash=document.getElementById('prop_ls').value;
  curStyle.lineCap=document.getElementById('prop_cap').value;
  curStyle.fill=document.getElementById('prop_fillOn').checked;
  curStyle.fillColor=document.getElementById('prop_fill').value;
  curStyle.alpha=parseFloat(document.getElementById('prop_alpha').value)||1;
  curStyle.wallWidth=parseFloat(document.getElementById('prop_wallW').value)||200;

  // Apply to selected
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(!o||o.locked) return;
    o.color=curStyle.color; o.lineWidth=curStyle.lineWidth;
    o.lineDash=curStyle.lineDash; o.lineCap=curStyle.lineCap;
    o.fill=curStyle.fill; o.fillColor=curStyle.fillColor; o.alpha=curStyle.alpha;
    if(o.type==='wall') o.wallWidth=curStyle.wallWidth;
  });
  render();
}

function applyAnnotProps(){
  const ids=[...selectedIds];
  const objs=ids.map(id=>objects.find(o=>o.id===id)).filter(o=>o&&o.type==='annot');
  if(!objs.length) return;
  pushUndo();
  objs.forEach(o=>{
    if(o.locked) return;
    o.tailStyle=document.getElementById('prop_tailStyle').value;
    o.tailWidth=parseFloat(document.getElementById('prop_tailWidth').value)||2;
    o.bubbleShape=document.getElementById('prop_bubbleShape').value;
    o.bubbleBg=document.getElementById('prop_bubbleBg').value;
    o.bubbleBorder=document.getElementById('prop_bubbleBorder').value;
    o.bubbleBorderW=parseFloat(document.getElementById('prop_bubbleBorderW').value)||1;
    o.bubbleAlpha=parseFloat(document.getElementById('prop_bubbleAlpha').value);
    o.fontFamily=document.getElementById('prop_annotFont').value;
    o.fontSize=parseInt(document.getElementById('prop_annotFontSize').value)||12;
    o.annotColor=document.getElementById('prop_annotColor').value;
    o.fontWeight=document.getElementById('prop_annotWeight').value;
  });
  render();
}

// ── Dynamic Length Input (\uC120/\uBCBD\uCCB4 \uC22B\uC790 \uC785\uB825) ──────────
function showDynLenInput(cx, cy){
  const el=document.getElementById('dynLenWrap');
  const inp=document.getElementById('dynLenInput');
  document.getElementById('dynLenUnit').textContent=unit;
  el.style.display='flex';
  // \uCEE4\uC11C \uC624\uB978\uCABD \uC544\uB798\uC5D0 \uC704\uCE58
  el.style.left=(cx+14)+'px';
  el.style.top=(cy+14)+'px';
  inp.value='';
  // \uD3EC\uCEE4\uC2A4\uB294 \uBE44\uB3D9\uAE30\uB85C (mousedown \uC774\uD6C4)
  setTimeout(()=>inp.focus(),30);
}

function hideDynLenInput(){
  document.getElementById('dynLenWrap').style.display='none';
  document.getElementById('dynLenInput').value='';
}

function onDynLenKey(e){
  if(e.key==='Enter'||e.key===' '){
    e.preventDefault();
    const inp=document.getElementById('dynLenInput');
    if(inp.dataset.mode==='scale'){
      const ratio=parseFloat(inp.value);
      inp.dataset.mode='';
      applyScale(ratio);
    } else if(inp.dataset.mode==='rotate'){
      const deg=parseFloat(inp.value);
      inp.dataset.mode='';
      if(!isNaN(deg)) applyRotate(deg*Math.PI/180);
      else { hideDynLenInput(); setTool('select'); }
    } else {
      confirmDynLen();
    }
  } else if(e.key==='F8'){
    e.preventDefault();
    toggleOrtho();
    renderOverlay();
  } else if(e.key==='F3'){
    e.preventDefault();
    toggleSnap();
  } else if(e.key==='Escape'){
    e.preventDefault();
    document.getElementById('dynLenInput').dataset.mode='';
    hideDynLenInput();
    setTool('select');
  }
}

function confirmDynLen(){
  const inp=document.getElementById('dynLenInput');
  const val=parseFloat(inp.value);
  if(!val||val<=0||!drawStart||!lastMouseWorld){ hideDynLenInput(); return; }

  // \uB9C8\uC6B0\uC2A4 \uBC29\uD5A5 \uAE30\uC900\uC73C\uB85C \uAE38\uC774 \uC801\uC6A9
  const mx=lastMouseWorld.x, my=lastMouseWorld.y;
  const dx=mx-drawStart.x, dy=my-drawStart.y;
  const mouseDist=Math.sqrt(dx*dx+dy*dy);

  let endPt;
  if(mouseDist<1){
    // \uB9C8\uC6B0\uC2A4\uAC00 \uC2DC\uC791\uC810\uC5D0 \uC788\uC73C\uBA74 \uC624\uB978\uCABD \uBC29\uD5A5 \uAE30\uBCF8
    const pxLen=val/scale;
    endPt={x:drawStart.x+pxLen, y:drawStart.y};
  } else {
    // \uB9C8\uC6B0\uC2A4 \uBC29\uD5A5 \uB2E8\uC704\uBCA1\uD130 × \uC785\uB825 \uAE38\uC774(\uD53D\uC140 \uBCC0\uD658)
    const pxLen=val/scale;
    const ux=dx/mouseDist, uy=dy/mouseDist;
    let ex=drawStart.x+ux*pxLen, ey=drawStart.y+uy*pxLen;
    // \uC9C1\uAD50\uBAA8\uB4DC\uBA74 ortho \uC801\uC6A9
    const ortho=orthoPoint(drawStart,{x:ex,y:ey});
    endPt=orthoOn?ortho:{x:ex,y:ey};
  }

  hideDynLenInput();
  commitShape(drawStart, endPt);
  // \uC5F0\uC18D \uADF8\uB9AC\uAE30: \uC785\uB825\uCC3D \uB2E4\uC2DC \uD45C\uC2DC (\uD654\uBA74 \uC911\uC559 \uAE30\uC900\uC73C\uB85C)
  if(tool==='line'||tool==='wall'){
    const sc=toScreen(endPt.x, endPt.y);
    showDynLenInput(sc.x, sc.y);
  }
}

// ── Offset ───────────────────────────────────

// ── Scale 도구 입력창 ─────────────────────────
function showScaleInput(cx, cy){
  const el=document.getElementById('dynLenWrap');
  const inp=document.getElementById('dynLenInput');
  document.getElementById('dynLenUnit').textContent='x';
  el.style.display='flex';
  el.style.left=(cx+14)+'px';
  el.style.top=(cy+14)+'px';
  inp.value='';
  inp.placeholder='\uBC30\uC728 (ex: 2, 0.5)';
  setTimeout(()=>inp.focus(),30);
  // Enter/Space 핸들러 임시 오버라이드
  inp.dataset.mode='scale';
}

function applyScale(ratio){
  if(!ratio||ratio<=0||!moveBasePoint) return;
  pushUndo();
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(!o||o.locked) return;
    scaleObjAround(o, moveBasePoint.x, moveBasePoint.y, ratio);
  });
  moveStep=0; moveBasePoint=null; scaleRefDistance=0;
  hideDynLenInput();
  refreshUI({status:true, props:true});
  notify('\uC2A4\uCF00\uC77C '+ratio+'x \uC801\uC6A9 \uC644\uB8CC');
  setTool('select');
}

// ── Rotate ────────────────────────────────────
function showRotateInput(cx, cy){
  const el=document.getElementById('dynLenWrap');
  const inp=document.getElementById('dynLenInput');
  document.getElementById('dynLenUnit').textContent='°';
  el.style.display='flex';
  el.style.left=(cx+14)+'px';
  el.style.top=(cy+14)+'px';
  inp.value='';
  inp.placeholder='각도 (ex: 90, -45)';
  setTimeout(()=>inp.focus(),30);
  inp.dataset.mode='rotate';
}

function applyRotate(angleRad){
  if(!rotateBase) return;
  pushUndo();
  const cosA=Math.cos(angleRad), sinA=Math.sin(angleRad);
  const bx=rotateBase.x, by=rotateBase.y;
  function rp(x,y){ return {x:bx+(x-bx)*cosA-(y-by)*sinA, y:by+(x-bx)*sinA+(y-by)*cosA}; }
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(!o||o.locked) return;
    if(o.type==='line'||o.type==='wall'){
      var p1=rp(o.x1,o.y1), p2=rp(o.x2,o.y2);
      o.x1=p1.x; o.y1=p1.y; o.x2=p2.x; o.y2=p2.y;
    } else if(o.type==='rect'){
      // 사각형→회전된 폴리라인으로 변환
      var pts=[rp(o.x,o.y),rp(o.x+o.w,o.y),rp(o.x+o.w,o.y+o.h),rp(o.x,o.y+o.h)];
      o.type='polyline'; o.points=pts; o.closed=true;
      delete o.x; delete o.y; delete o.w; delete o.h;
    } else if(o.type==='circle'){
      var cp=rp(o.cx,o.cy);
      o.cx=cp.x; o.cy=cp.y;
    } else if(o.type==='polyline'){
      o.points=o.points.map(p=>rp(p.x,p.y));
    } else if(o.type==='text'||o.type==='annot'){
      var tp=rp(o.x,o.y);
      o.x=tp.x; o.y=tp.y;
      o.angle=(o.angle||0)+angleRad;
      if(o.arrowTo){ var ap=rp(o.arrowTo.x,o.arrowTo.y); o.arrowTo={x:ap.x,y:ap.y}; }
    } else if(o.type==='bgimage'){
      var ip=rp(o.x,o.y);
      o.x=ip.x; o.y=ip.y;
    }
  });
  rotateStep=0; rotateBase=null;
  hideDynLenInput();
  refreshUI({status:true});
  var degStr=(angleRad*180/Math.PI).toFixed(1);
  notify('회전 '+degStr+'° 적용 완료');
  setTool('select');
}

// ── Mirror ────────────────────────────────────
function applyMirror(deleteOriginal){
  if(!mirrorP1||!mirrorP2) return;
  // 대칭축 벡터
  var ax=mirrorP2.x-mirrorP1.x, ay=mirrorP2.y-mirrorP1.y;
  var len2=ax*ax+ay*ay;
  if(len2===0) return;
  // 점을 대칭축에 대해 반사
  function reflect(x,y){
    var dx=x-mirrorP1.x, dy=y-mirrorP1.y;
    var dot=(dx*ax+dy*ay)/len2;
    return {x:mirrorP1.x+2*dot*ax-dx, y:mirrorP1.y+2*dot*ay-dy};
  }
  pushUndo();
  // 원본 유지하고 복사본 생성 (캐드 기본: 원본 삭제 여부 묻지만 여기선 복사)
  var copies=[];
  selectedIds.forEach(id=>{
    const o=objects.find(x=>x.id===id);
    if(!o) return;
    var c=JSON.parse(JSON.stringify(o));
    c.id=nextId();
    if(c.type==='line'||c.type==='wall'){
      var p1=reflect(c.x1,c.y1), p2=reflect(c.x2,c.y2);
      c.x1=p1.x; c.y1=p1.y; c.x2=p2.x; c.y2=p2.y;
    } else if(c.type==='rect'){
      var pts=[reflect(c.x,c.y),reflect(c.x+c.w,c.y),reflect(c.x+c.w,c.y+c.h),reflect(c.x,c.y+c.h)];
      c.type='polyline'; c.points=pts; c.closed=true;
      delete c.x; delete c.y; delete c.w; delete c.h;
    } else if(c.type==='circle'){
      var cp=reflect(c.cx,c.cy);
      c.cx=cp.x; c.cy=cp.y;
    } else if(c.type==='polyline'){
      c.points=c.points.map(p=>reflect(p.x,p.y));
    } else if(c.type==='text'||c.type==='annot'){
      var tp=reflect(c.x,c.y);
      c.x=tp.x; c.y=tp.y;
      // 각도 반전
      var mirAngle=Math.atan2(ay,ax);
      c.angle=2*mirAngle-(c.angle||0);
      if(c.arrowTo){ var ap=reflect(c.arrowTo.x,c.arrowTo.y); c.arrowTo={x:ap.x,y:ap.y}; }
    } else if(c.type==='bgimage'){
      var ip=reflect(c.x,c.y);
      c.x=ip.x; c.y=ip.y;
    }
    copies.push(c);
  });
  copies.forEach(c=>objects.push(c));
  // 원본 삭제
  if(deleteOriginal){
    var origIds=new Set(selectedIds);
    objects=objects.filter(o=>!origIds.has(o.id));
  }
  selectedIds.clear(); copies.forEach(c=>selectedIds.add(c.id));
  mirrorStep=0; mirrorP1=null; mirrorP2=null;
  refreshUI({status:true});
  notify(deleteOriginal ? '대칭이동 '+copies.length+'개 완료 (원본 삭제)' : '대칭복사 '+copies.length+'개 완료');
  setTool('select');
}

// ── View ─────────────────────────────────────
function zoomIn(){ viewZoom=Math.min(viewZoom*1.2,100); render(); }
function zoomOut(){ viewZoom=Math.max(viewZoom/1.2,0.001); render(); }
function fitAll(){
  if(!objects.length){ viewX=wrap.clientWidth/2; viewY=wrap.clientHeight/2; viewZoom=1; render(); return; }
  // 1차: 유의미한 line 기준 (길이 5 이상)
  let x1=Infinity,y1=Infinity,x2=-Infinity,y2=-Infinity;
  let hasLines=false;
  objects.forEach(o=>{
    if(o.type==='line'){
      const len=Math.sqrt((o.x2-o.x1)*(o.x2-o.x1)+(o.y2-o.y1)*(o.y2-o.y1));
      if(len>5){
        x1=Math.min(x1,o.x1,o.x2); y1=Math.min(y1,o.y1,o.y2);
        x2=Math.max(x2,o.x1,o.x2); y2=Math.max(y2,o.y1,o.y2);
        hasLines=true;
      }
    }
  });
  // 2차: line이 없으면 전체 객체 기준
  if(!hasLines){
    objects.forEach(o=>{
      const bb=getBounds(o);
      if(bb){ x1=Math.min(x1,bb.x);y1=Math.min(y1,bb.y);x2=Math.max(x2,bb.x+bb.w);y2=Math.max(y2,bb.y+bb.h); }
    });
  }
  if(x1===Infinity) return;
  const W=wrap.clientWidth,H=wrap.clientHeight;
  const pad=60;
  const dw=x2-x1||1, dh=y2-y1||1;
  viewZoom=Math.min((W-pad*2)/dw,(H-pad*2)/dh);
  const cx=(x1+x2)/2, cy=(y1+y2)/2;
  viewX=W/2-cx*viewZoom;
  viewY=H/2-cy*viewZoom;
  render();
}

// (더미 데이터 제거 — DB API 사용)

function filterSiteList(){
  const q = (document.getElementById('siteConnSearch').value||'').trim();
  const list = document.getElementById('siteConnList');
  if(!q){
    list.innerHTML = '<div class="cad-empty">현장번호 또는 현장명을 검색하세요</div>';
    return;
  }
  list.innerHTML = '<div class="cad-empty">검색 중...</div>';

  fetch('dist/site_api.php?action=search&q='+encodeURIComponent(q))
    .then(r=>r.json())
    .then(res=>{
      if(!res.ok || !res.data.length){
        list.innerHTML = '<div class="cad-empty">검색 결과 없음</div>';
        return;
      }
      // 헤더
      const colStyle='width:90px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
      const colStyle2='width:120px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
      list.innerHTML = `<div class="cad-list-header">
          <span style="${colStyle}">유형</span>
          <span style="${colStyle2}">현장번호</span>
          <span style="flex:1;min-width:0">현장명</span>
          <span style="${colStyle2}">발주처</span>
          <span style="${colStyle}">총수량</span>
          <span style="${colStyle}">발주담당</span>
          <span style="${colStyle}">마감</span>
          <span style="${colStyle}">담당자</span>
        </div>` + res.data.map(s =>
        `<div class="siteConnItem cad-list-row" data-no="${_esc(s.no)}" data-name="${_esc(s.name)}" data-addr="${_esc(s.addr)}" data-idx="${_esc(s.idx)}">
          <span style="${colStyle}" title="${_esc(s.type||'')}">${_esc((s.type||'').length>10?(s.type||'').substring(0,10)+'...':s.type||'')}</span>
          <span style="${colStyle2};color:var(--accent);font-weight:600">${_esc(s.no)}</span>
          <span style="flex:1;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_esc(s.name)}</span>
          <span style="${colStyle2}" title="${_esc(s.order||'')}">${_esc((s.order||'').length>15?(s.order||'').substring(0,15)+'...':s.order||'')}</span>
          <span style="${colStyle}">${_esc(s.qty||'')}</span>
          <span style="${colStyle}">${_esc(s.orderMgr||'')}</span>
          <span style="${colStyle}">${_esc(s.status||'')}</span>
          <span style="${colStyle}">${_esc(s.manager||'')}</span>
        </div>`
      ).join('');

      list.querySelectorAll('.siteConnItem').forEach(el => {
        el.addEventListener('click', function(){
          // 현장 상세 정보 가져와서 세팅
          const idx = this.dataset.idx;
          const no = this.dataset.no;
          const name = this.dataset.name;
          const addr = this.dataset.addr;
          fetch('dist/site_api.php?action=detail&idx='+idx)
            .then(r=>r.json())
            .then(d=>{
              if(d.ok) _siteDetail = d.data;
              showSiteDrawings(no, name, addr);
            })
            .catch(()=>showSiteDrawings(no, name, addr));
        });
        /* hover는 CSS .cad-list-row:hover로 처리 */
      });
    })
    .catch(()=>{
      list.innerHTML = '<div class="cad-empty">검색 오류</div>';
    });
}
let _siteDetail = null;

function applySiteConn(){ filterSiteList(); }

// 서버에 추가 포맷 내보내기
function exportToServer(id){
  const types=[];
  for(let i=1;i<=3;i++){
    const el=document.getElementById('siteSetSaveType'+i);
    if(el&&el.value) types.push(el.value);
  }
  if(!types.length) return;
  const api=window._CAD_API||'index.php?api=';
  const fname=generateFileName();

  types.forEach(ext=>{
    let content='';
    if(ext==='dxf') content=generateDXFContent();
    else if(ext==='svg') content=generateSVGContent();
    else if(ext==='png') content=canvas.toDataURL('image/png');
    else return;

    fetch(api+'export',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id:fname, ext:ext, content:content})
    }).then(r=>r.json()).then(r=>{
      if(r.ok) notify(ext.toUpperCase()+' 서버 저장: '+fname+'.'+ext);
    });
  });
}

// DXF 문자열 생성 (파일 다운로드 없이)
function generateDXFContent(){
  const unitMul = unit==='m' ? 1000 : unit==='cm' ? 10 : 1;
  const f = scale * unitMul;
  function dx(v){ return (v*f).toFixed(2); }
  function dy(v){ return (-v*f).toFixed(2); }
  // 사용 중인 레이어 수집 (빈 이름 제거)
  var usedLayers=new Set();
  usedLayers.add('0');
  objects.forEach(function(o){
    var ln=(layers.find(function(l){return l.id===o.layerId;})||{}).name||'0';
    if(ln && ln.trim()) usedLayers.add(ln.trim());
  });
  var handleNo=1;
  function h(){ return (handleNo++).toString(16).toUpperCase(); }

  // DXF 텍스트 인코딩 (한글 그대로 유지 — ODA가 UTF-8 처리)
  function dxfEnc(str){ return str||''; }

  // R12 Simple DXF (서브클래스 마커 불필요, ODA 호환 최고)
  let dxf='0\nSECTION\n2\nHEADER\n';
  dxf+='9\n$ACADVER\n1\nAC1009\n';
  dxf+='9\n$DWGCODEPAGE\n3\nANSI_949\n';
  dxf+='9\n$INSUNITS\n70\n4\n';
  dxf+='0\nENDSEC\n';
  dxf+='0\nSECTION\n2\nTABLES\n';
  dxf+='0\nTABLE\n2\nLTYPE\n70\n1\n';
  dxf+='0\nLTYPE\n2\nCONTINUOUS\n70\n0\n3\nSolid line\n72\n65\n73\n0\n40\n0.0\n';
  dxf+='0\nENDTAB\n';
  dxf+='0\nTABLE\n2\nLAYER\n70\n'+usedLayers.size+'\n';
  usedLayers.forEach(function(name){
    dxf+='0\nLAYER\n2\n'+dxfEnc(name)+'\n70\n0\n62\n7\n6\nCONTINUOUS\n';
  });
  dxf+='0\nENDTAB\n';
  dxf+='0\nENDSEC\n';

  // ENTITIES
  dxf+='0\nSECTION\n2\nENTITIES\n';
  function dxfEntity(o){
    var ln=(layers.find(l=>l.id===o.layerId)||{}).name||'0';
    if(!ln||!ln.trim()) ln='0';
    var el=dxfEnc(ln);
    if(o.type==='line'||o.type==='wall') dxf+=`0\nLINE\n8\n${el}\n10\n${dx(o.x1)}\n20\n${dy(o.y1)}\n30\n0\n11\n${dx(o.x2)}\n21\n${dy(o.y2)}\n31\n0\n`;
    else if(o.type==='rect') [[o.x,o.y,o.x+o.w,o.y],[o.x+o.w,o.y,o.x+o.w,o.y+o.h],[o.x+o.w,o.y+o.h,o.x,o.y+o.h],[o.x,o.y+o.h,o.x,o.y]].forEach(([x1,y1,x2,y2])=>{ dxf+=`0\nLINE\n8\n${el}\n10\n${dx(x1)}\n20\n${dy(y1)}\n30\n0\n11\n${dx(x2)}\n21\n${dy(y2)}\n31\n0\n`; });
    else if(o.type==='circle') dxf+=`0\nCIRCLE\n8\n${el}\n10\n${dx(o.cx)}\n20\n${dy(o.cy)}\n30\n0\n40\n${dx(o.r)}\n`;
    else if(o.type==='polyline'&&o.points) for(let i=0;i<o.points.length-1;i++) dxf+=`0\nLINE\n8\n${el}\n10\n${dx(o.points[i].x)}\n20\n${dy(o.points[i].y)}\n30\n0\n11\n${dx(o.points[i+1].x)}\n21\n${dy(o.points[i+1].y)}\n31\n0\n`;
    else if(o.type==='text'||o.type==='annot') if(o.text) dxf+=`0\nTEXT\n8\n${el}\n10\n${dx(o.x)}\n20\n${dy(o.y)}\n30\n0\n40\n${(o.fontSize||14)*unitMul}\n1\n${dxfEnc(o.text)}\n`;
    else if(o.type==='block'&&o.children) o.children.forEach(function(c){dxfEntity(c);});
  }
  objects.forEach(function(o){dxfEntity(o);});
  dxf+='0\nENDSEC\n0\nEOF\n';
  return dxf;
}

// SVG 문자열 생성 (파일 다운로드 없이)
function generateSVGContent(){
  let x1=Infinity,y1=Infinity,x2=-Infinity,y2=-Infinity;
  objects.forEach(o=>{ const bb=getBounds(o); if(bb){ x1=Math.min(x1,bb.x);y1=Math.min(y1,bb.y);x2=Math.max(x2,bb.x+bb.w);y2=Math.max(y2,bb.y+bb.h); } });
  if(x1===Infinity){ x1=0;y1=0;x2=100;y2=100; }
  const pad=20;
  let svg=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="${x1-pad} ${y1-pad} ${x2-x1+pad*2} ${y2-y1+pad*2}">\n`;
  objects.forEach(o=>{
    const c=o.color||'#fff';const lw=o.lineWidth||1;
    if(o.type==='line'||o.type==='wall') svg+=`<line x1="${o.x1}" y1="${o.y1}" x2="${o.x2}" y2="${o.y2}" stroke="${c}" stroke-width="${lw}"/>\n`;
    else if(o.type==='rect') svg+=`<rect x="${o.x}" y="${o.y}" width="${o.w}" height="${o.h}" stroke="${c}" stroke-width="${lw}" fill="none"/>\n`;
    else if(o.type==='circle') svg+=`<circle cx="${o.cx}" cy="${o.cy}" r="${o.r}" stroke="${c}" stroke-width="${lw}" fill="none"/>\n`;
    else if(o.type==='text'||o.type==='annot') svg+=`<text x="${o.x}" y="${o.y}" fill="${c}" font-size="${o.fontSize||12}">${o.text||''}</text>\n`;
  });
  svg+='</svg>';
  return svg;
}

function addWireRow(){
  const tbody=document.getElementById('wireTableBody');
  const rows=tbody.querySelectorAll('tr');
  const no=rows.length+1;
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td style="padding:4px 8px;border-bottom:1px solid var(--border);color:var(--textD)">${no}</td>
    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="text" class="modalInput" style="padding:2px 6px;font-size:11px" placeholder="품목명" data-wire-name></td>
    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><select class="modalSelect" style="padding:2px 4px;font-size:10px;width:48px" data-wire-unit><option value="M" selected>M</option><option value="EA">EA</option><option value="SET">SET</option><option value="식">식</option></select></td>
    <td style="padding:4px 8px;border-bottom:1px solid var(--border)"><input type="number" class="modalInput" style="padding:2px 6px;font-size:11px;text-align:right;width:55px" value="0" data-wire-qty></td>
    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><input type="radio" name="wireCalc" value="${no-1}" title="산출연동"></td>
    <td style="padding:4px 8px;border-bottom:1px solid var(--border);text-align:center"><span style="color:var(--danger);cursor:pointer;font-size:13px" onclick="removeWireRow(this)">✕</span></td>
  `;
  tbody.appendChild(tr);
}

function removeWireRow(el){
  const tr=el.closest('tr');
  if(tr) tr.remove();
  // 번호 재정렬
  const tbody=document.getElementById('wireTableBody');
  tbody.querySelectorAll('tr').forEach(function(r,i){
    r.querySelector('td').textContent=i+1;
  });
}

let _drawingSeq = 0; // 도면 순번

function generateFileName(){
  const no = document.getElementById('siteSetNo').value || 'N00000';
  return no + '_SC_D' + _drawingSeq;
}

function updateFileNameDisplay(){
  const el = document.getElementById('siteSetFileName');
  if(el) el.value = generateFileName();
}

// 현장 도면 목록 (DB에서 fetch)
function showSiteDrawings(no, name, addr){
  var siteIdx = _siteDetail ? _siteDetail.idx : 0;
  if(!siteIdx){
    showSiteSettings(no, name, addr);
    return;
  }

  // API로 도면 목록 가져오기
  fetch('dist/site_api.php?action=drawings&site_idx='+siteIdx)
    .then(function(r){ return r.json(); })
    .then(function(res){
      var drawings = (res.ok && res.data) ? res.data : [];

      // 도면 없으면 바로 세팅으로
      if(!drawings.length){
        showSiteSettings(no, name, addr);
        return;
      }

      // 도면 목록 단계 표시
      document.getElementById('siteStep1').style.display='none';
      document.getElementById('siteStepDrawings').style.display='flex';
      document.getElementById('siteStep2').style.display='none';
      document.getElementById('siteApplyBtn').style.display='none';
      document.getElementById('siteDrawInfo').textContent=no+' / '+name+' ('+drawings.length+'개 도면)';

      var panel = document.getElementById('siteDrawListPanel');
      panel.innerHTML = '<div style="display:flex;align-items:center;gap:6px;padding:5px 12px;background:var(--panel2);border-bottom:1px solid var(--border);font-size:10px;color:var(--textD)">'
        +'<span style="width:22px">No</span><span style="min-width:35px">구분</span><span style="flex:1">도면명</span><span style="flex:1">설명</span>'
        +'<span style="min-width:40px;text-align:center">상태</span><span style="min-width:50px">작성자</span><span style="min-width:100px">날짜</span><span style="min-width:35px"></span></div>'
        + drawings.map(function(d,i){
          var isCad = d.type==='cad';
          var badge = isCad ? '<span style="background:#6366f1;color:#fff;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700">CAD</span>'
                            : '<span style="background:#3a6a8a;color:#fff;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700">파일</span>';
          return '<div class="siteDrawRow" data-idx="'+d.idx+'" data-type="'+d.type+'" style="display:flex;align-items:center;gap:6px;padding:6px 12px;border-bottom:1px solid var(--border);cursor:pointer;transition:background 0.1s;font-size:11px">'
            +'<span style="color:var(--textD);font-size:10px;width:22px">'+(i+1)+'</span>'
            +'<span style="min-width:35px">'+badge+'</span>'
            +'<span style="color:var(--accent);font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(d.title||'')+'</span>'
            +'<span style="color:var(--textD);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(d.desc||'')+'</span>'
            +'<span style="min-width:40px;text-align:center"><span style="background:'+(isCad?'#00aaff':'#3a6a8a')+';color:#fff;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700">'+(d.status||'')+'</span></span>'
            +'<span style="color:var(--textB);min-width:50px">'+(d.author||'')+'</span>'
            +'<span style="color:var(--textD);font-size:10px;min-width:100px">'+(d.date||'')+'</span>'
            +(isCad?'<button style="background:#6366f1;border:none;color:#fff;padding:3px 10px;border-radius:3px;cursor:pointer;font-size:10px;font-weight:600;min-width:35px">열기</button>':'<span style="min-width:35px"></span>')
            +'</div>';
        }).join('');

      // CAD 도면 열기 버튼
      panel.querySelectorAll('.siteDrawRow').forEach(function(row){
        var btn = row.querySelector('button');
        if(btn){
          btn.addEventListener('click', function(e){
            e.stopPropagation();
            var drawIdx = row.dataset.idx;
            document.getElementById('siteNo').textContent=no;
            document.getElementById('siteName').textContent=name;
            closeModal('siteConnModal');
            resetSiteSteps();
            // CAD 도면 로드
            setTimeout(function(){
              fetch((window._CAD_API||'index.php?api=')+'load&id=dwg_site_'+siteIdx+'_'+drawIdx)
                .then(function(r){ return r.json(); })
                .then(function(dd){ if(dd.drawing) loadDrawingData(dd); });
            }, 300);
            notify('도면 열기: '+row.querySelector('[style*="accent"]').textContent);
          });
        }
        row.addEventListener('mouseenter', function(){ this.style.background='rgba(0,170,255,0.08)'; });
        row.addEventListener('mouseleave', function(){ this.style.background=''; });
      });

      // 새로 작성 버튼
      document.getElementById('siteDrawNewBtn').onclick=function(){
        document.getElementById('siteStepDrawings').style.display='none';
        showSiteSettings(no, name, addr);
      };
      // 다시 검색
      document.getElementById('siteDrawBackBtn').onclick=function(){
        document.getElementById('siteStepDrawings').style.display='none';
        document.getElementById('siteStep1').style.display='flex';
      };
    })
    .catch(function(){
      showSiteSettings(no, name, addr);
    });
}

function resetSiteSteps(){
  document.getElementById('siteStep1').style.display='flex';
  document.getElementById('siteStepDrawings').style.display='none';
  document.getElementById('siteStep2').style.display='none';
  document.getElementById('siteApplyBtn').style.display='none';
  var newDrawBtn=document.getElementById('siteNewDrawBtn');
  if(newDrawBtn) newDrawBtn.style.display='none';
}

// 새 도면 작성 — 현장 연결 적용 후 캔버스 초기화
function startNewDrawing(){
  applySiteSettings();
  // 캔버스 초기화
  if(typeof pushUndo==='function') pushUndo();
  objects=[];
  if(typeof renderLayerList==='function') renderLayerList();
  if(typeof render==='function') render();
  if(typeof updateStatus==='function') updateStatus();
  notify('새 도면 작성 시작');
}

function showSiteSettings(no, name, addr){
  const site = _siteDetail || {};
  _drawingSeq++;
  document.getElementById('siteStep1').style.display='none';
  document.getElementById('siteStepDrawings').style.display='none';
  document.getElementById('siteStep2').style.display='block';
  document.getElementById('siteApplyBtn').style.display='';
  var newDrawBtn=document.getElementById('siteNewDrawBtn');
  if(newDrawBtn) newDrawBtn.style.display='';
  document.getElementById('siteSelInfo').textContent=no+' / '+name;
  document.getElementById('siteSetNo').value=no;
  document.getElementById('siteSetName').value=name;
  document.getElementById('siteSetAddr').value=site.addr||addr||'';
  document.getElementById('siteSetManager').value=site.manager||'-';
  document.getElementById('siteSetOrder').value=site.orderMgr||'-';
  document.getElementById('siteSetClient').value=site.order||'-';
  document.getElementById('siteSetType').value=site.type||'-';
  document.getElementById('siteSetQty').value=site.qty||'-';
  document.getElementById('siteSetStart').value=site.start||'-';
  document.getElementById('siteSetEnd').value=site.end||'-';
  updateFileNameDisplay();
  // 저장된 도면 리스트 (DB에서 가져오기)
  var dl=document.getElementById('siteDrawingList');
  dl.innerHTML='<div class="cad-empty">불러오는 중...</div>';
  var siteIdx = site.idx || 0;
  if(siteIdx){
    fetch('dist/site_api.php?action=drawings&site_idx='+siteIdx)
      .then(function(r){ return r.json(); })
      .then(function(res){
        var drawings = (res.ok && res.data) ? res.data : [];
        if(drawings.length){
          dl.innerHTML=drawings.map(function(d){
            var isCad = d.type==='cad';
            var badge = isCad ? '<span class="cad-badge-cad">CAD</span>'
                              : '<span class="cad-badge-file">파일</span>';
            return '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-bottom:1px solid var(--border);transition:background 0.1s" onmouseenter="this.style.background=\'rgba(0,170,255,0.08)\'" onmouseleave="this.style.background=\'\'">'
              +'<span>'+badge+'</span>'
              +'<span style="color:var(--accent);font-weight:600;font-size:11px;flex:1">'+_esc(d.title||'')+'</span>'
              +'<span style="color:var(--textD);font-size:10px">'+_esc(d.status||'')+'</span>'
              +'<span style="color:var(--textD);font-size:10px">'+_esc(d.date||'')+'</span>'
              +'</div>';
          }).join('');
        } else {
          dl.innerHTML='<div class="cad-empty">저장된 도면 없음</div>';
        }
      })
      .catch(function(){ dl.innerHTML='<div class="cad-empty">저장된 도면 없음</div>'; });
  } else {
    dl.innerHTML='<div class="cad-empty">저장된 도면 없음</div>';
  }
  document.getElementById('siteBackBtn').onclick=function(){
    resetSiteSteps();
  };
}

function applySiteSettings(){
  const no=document.getElementById('siteSetNo').value;
  const name=document.getElementById('siteSetName').value;
  const sc=document.getElementById('siteSetScale').value;
  const un=document.getElementById('siteSetUnit').value;
  document.getElementById('siteNo').textContent=no;
  document.getElementById('siteName').textContent=name;
  scale=parseFloat(sc)||1;
  document.getElementById('scaleSelect').value=sc;
  unit=un;
  document.getElementById('unitSelect').value=un;
  closeModal('siteConnModal');
  // 초기화
  document.getElementById('siteStep1').style.display='flex';
  document.getElementById('siteStep2').style.display='none';
  document.getElementById('siteApplyBtn').style.display='none';
  render();
  notify('현장 연결 완료: '+no+' '+name);
}

function toggleDarkMode(){
  document.body.classList.toggle('light-mode');
  const isLight = document.body.classList.contains('light-mode');
  document.getElementById('btnDarkMode').textContent = isLight ? '☀️' : '🌙';
  render();
}

function refreshView(){
  resizeCanvas();
  render();
  updateStatus();
}

function toggleGrid(){
  showGrid=!showGrid;
  const el=document.getElementById('sGrid');
  if(el) el.textContent=showGrid?'ON':'OFF';
  const btn=document.getElementById('btnGrid');
  if(btn) btn.classList.toggle('off',!showGrid);
  const sel=document.getElementById('setGridOn');
  if(sel) sel.value=showGrid?'1':'0';
  notify('모눈 '+(showGrid?'ON ✓':'OFF'));
  render();
}
function toggleSnap(){
  snapOn=!snapOn;
  document.getElementById('sSnap').textContent=snapOn?'ON':'OFF';
  document.getElementById('btnSnap').classList.toggle('off',!snapOn);
  notify('\uC2A4\uB0C5 '+(snapOn?'ON ✓':'OFF'));
  renderOverlay();
}
function toggleOrtho(){
  orthoOn=!orthoOn;
  const el=document.getElementById('sOrtho');
  el.textContent=orthoOn?'ON':'OFF';
  const btn=document.getElementById('btnOrtho');
  btn.classList.toggle('off',!orthoOn);
  notify('\uC9C1\uAD50 \uBAA8\uB4DC '+(orthoOn?'ON ✓':'OFF'));
}

// ── Scale/Unit ───────────────────────────────
function setScale(v){ scale=parseFloat(v)||50; render(); }
function setUnit(v){ unit=v; render(); }

// ── Undo/Redo ─────────────────────────────────
function _isViewerMode(){ return document.body.classList.contains('viewer-mode'); }

function pushUndo(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정할 수 없습니다'); return false; }
  const state=JSON.stringify({objects,layers});
  history=history.slice(0,histIdx+1);
  history.push(state);
  if(history.length>MAX_HIST) history.shift();
  histIdx=history.length-1;
  saveVersion();
  markUnsaved();
}

function undo(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정할 수 없습니다'); return; }
  if(histIdx<=0){ notify('\uB354 \uC774\uC0C1 \uCDE8\uC18C\uD560 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4'); return; }
  histIdx--;
  restoreState(history[histIdx]);
}
function redo(){
  if(_isViewerMode()){ notify('뷰어 모드: 수정할 수 없습니다'); return; }
  if(histIdx>=history.length-1){ notify('\uB354 \uC774\uC0C1 \uB2E4\uC2DC\uC2E4\uD589 \uC5C6\uC74C'); return; }
  histIdx++;
  restoreState(history[histIdx]);
}
function restoreState(state){
  const s=JSON.parse(state);
  objects=s.objects; layers=s.layers;
  refreshUI({layers:true, status:true});
}

// ── Version History ──────────────────────────
function saveVersion(){
  const ts=new Date().toLocaleTimeString();
  versions.push({ts, count:objects.length, comment:'', user:CAD_USER.name||'', state:JSON.stringify({objects,layers})});
  if(versions.length>MAX_VER) versions.shift();
  renderVersionList();
}
let _comments = [];

function addVersionComment(){
  const inp=document.getElementById('versionComment');
  const comment=inp.value.trim();
  if(!comment){ notify('코멘트를 입력하세요'); return; }
  const now=new Date();
  const ts=now.toLocaleString();
  _comments.unshift({ts, comment, user:CAD_USER.name||'', count:objects.length});
  inp.value='';
  renderCommentList();
  notify('코멘트 저장됨');
}
function renderCommentList(){
  const el=document.getElementById('commentList');
  if(!el) return;
  el.innerHTML='';
  _comments.forEach((c,i)=>{
    const d=document.createElement('div');
    d.style.cssText='padding:5px 8px;border-bottom:1px solid var(--border);font-size:10px;color:var(--text)';
    d.innerHTML=`<div style="display:flex;justify-content:space-between"><span style="color:var(--accent)">${_esc(c.user)}</span><span style="color:var(--textD)">${_esc(c.ts)}</span></div><div style="color:var(--textB);margin-top:2px">💬 ${_esc(c.comment)}</div>`;
    el.appendChild(d);
  });
}
function renderVersionList(){
  const el=document.getElementById('versionList');
  if(!el) return;
  el.innerHTML='';
  [...versions].reverse().forEach((v,i)=>{
    const d=document.createElement('div');
    d.style.cssText='padding:5px 8px;cursor:pointer;border-bottom:1px solid var(--border);font-size:11px;color:var(--text);transition:background 0.1s';
    d.onmouseenter=function(){this.style.background='rgba(0,170,255,0.06)'};
    d.onmouseleave=function(){this.style.background=''};
    d.innerHTML=`<span style="color:var(--accent)">${_esc(v.ts)}</span> <span style="color:var(--textD)">– ${_esc(v.user||'')} – 객체 ${v.count}개</span>`;
    d.onclick=()=>{ const s=JSON.parse(v.state); objects=s.objects; layers=s.layers; refreshUI({layers:true, status:true}); notify('버전 복원: '+v.ts); };
    el.appendChild(d);
  });
}

// ── Auto Save ────────────────────────────────
function setAutoSave(){
  clearInterval(autoSaveTimer);
  if(document.getElementById('autoSaveOn').checked){
    const mins=parseInt(document.getElementById('autoSaveMin').value)||5;
    autoSaveTimer=setInterval(()=>{ saveJSON(true); notify('\uC790\uB3D9 \uC800\uC7A5\uB428'); }, mins*60*1000);
  }
}

// ── File Operations ──────────────────────────
function newFile(){
  cadAskConfirm(
    '\uC0C8 \uB3C4\uBA74\uC744 \uC2DC\uC791\uD558\uC2DC\uACA0\uC2B5\uB2C8\uAE4C? (\uC800\uC7A5\uB418\uC9C0 \uC54A\uC740 \uB0B4\uC6A9\uC740 \uC0AC\uB77C\uC9D1\uB2C8\uB2E4)',
    function(){
      objects=[]; selectedIds.clear(); history=[]; histIdx=-1;
      initLayers(); refreshUI({status:true});
    }
  );
}

function saveJSONLocal(silent){
  const data={objects,layers,scale,unit,version:'1.0'};
  const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download='shv_plan_'+Date.now()+'.json';
  a.click();
  if(!silent) notify('JSON \uC800\uC7A5 \uC644\uB8CC');
}
// saveJSON/openFile\uB294 CAD_ui.js\uC5D0\uC11C window.saveJSON/openFile\uB85C \uC7AC\uC815\uC758\uB428 (\uC11C\uBC84 \uC800\uC7A5 \uBC84\uC804)
function saveJSON(silent){ saveJSONLocal(silent); }

function openFileLocal(){ document.getElementById('jsonFileInput').click(); }
function openFile(){ openFileLocal(); }
function loadJSON(input){
  const f=input.files[0]; if(!f) return;
  const reader=new FileReader();
  reader.onload=e=>{
    try{
      const data=JSON.parse(e.target.result);
      objects=data.objects||[]; layers=data.layers||[];
      if(data.scale) scale=data.scale;
      if(data.unit) unit=data.unit;
      renderLayerList(); fitAll(); updateStatus();
      notify('\uD30C\uC77C \uC5F4\uAE30 \uC644\uB8CC');
    }catch(err){ notify('파일 읽기 오류: '+err.message, 'danger'); }
  };
  reader.readAsText(f);
  input.value='';
}

// ── DXF Import (Parser) ─────────────────────
var DXF_LINE_SCALE=0.5; // DXF 가져오기 기본 선 두께 배율

function parseDXF(dxfText){
  var result=[];
  var lines=dxfText.split(/\r?\n/);
  var i=0;
  var layerSet=new Set();
  var blockDefs={}; // 블록 정의: name → [{etype, props}]
  var _lw=DXF_LINE_SCALE; // 기본 선 두께
  var ETYPES=['LINE','CIRCLE','ARC','TEXT','MTEXT','LWPOLYLINE','POLYLINE','POINT','INSERT','DIMENSION','SPLINE','ELLIPSE','HATCH','SOLID','3DFACE','LEADER','ATTRIB','ATTDEF','VERTEX','SEQEND'];

  function next(){ return i<lines.length ? lines[i++].trim() : null; }
  function peek(){ return i<lines.length ? lines[i].trim() : null; }
  function peekVal(){ return i+1<lines.length ? lines[i+1].trim() : null; }
  function mkId(){ return 'dxf_'+Date.now()+'_'+Math.random().toString(36).substr(2,6); }

  // 엔티티 속성 읽기 (다음 그룹코드 0까지)
  function readProps(){
    var props={};
    while(i<lines.length){
      if(peek()==='0' && i+1<lines.length){
        var nv=peekVal();
        if(ETYPES.indexOf(nv)!==-1 || nv==='ENDSEC' || nv==='ENDBLK' || nv==='BLOCK') break;
      }
      var gc=next(), gv=next();
      if(gc===null) break;
      var gcn=parseInt(gc);
      if(props[gcn]!==undefined){
        if(!Array.isArray(props[gcn])) props[gcn]=[props[gcn]];
        props[gcn].push(gv);
      } else { props[gcn]=gv; }
    }
    return props;
  }

  // 엔티티 하나를 객체로 변환
  function entityToObj(etype, props, parentLayer, parentColor){
    var layer=props[8]||parentLayer||'0';
    layerSet.add(layer);
    var id=mkId();
    var ci=props[62]?parseInt(props[62]):0;
    var color=ci>0 ? dxfColorToHex(ci) : (parentColor||'#ffffff');
    // BYLAYER(256) / BYBLOCK(0) 처리
    if(ci===256||ci===0) color=parentColor||'#ffffff';
    // 모든 선 기본 두께로 통일 (370/43/40/41 무시)
    var lw=_lw;

    if(etype==='LINE'){
      return {id:id, type:'line', layer:layer,
        x1:parseFloat(props[10]||0), y1:-parseFloat(props[20]||0),
        x2:parseFloat(props[11]||0), y2:-parseFloat(props[21]||0),
        color:color, lineWidth:lw};
    }
    if(etype==='CIRCLE'){
      return {id:id, type:'circle', layer:layer,
        cx:parseFloat(props[10]||0), cy:-parseFloat(props[20]||0),
        r:parseFloat(props[40]||1), color:color, lineWidth:lw, fill:''};
    }
    if(etype==='ARC'){
      var cx=parseFloat(props[10]||0), cy=-parseFloat(props[20]||0);
      var r=parseFloat(props[40]||1);
      var sa=(parseFloat(props[50]||0))*Math.PI/180;
      var ea=(parseFloat(props[51]||360))*Math.PI/180;
      var pts=[], a=sa, step=Math.PI/36;
      if(ea<sa){ while(a<Math.PI*2){pts.push({x:cx+r*Math.cos(a),y:cy-r*Math.sin(a)});a+=step;} a=0; }
      while(a<=ea){pts.push({x:cx+r*Math.cos(a),y:cy-r*Math.sin(a)});a+=step;}
      pts.push({x:cx+r*Math.cos(ea),y:cy-r*Math.sin(ea)});
      if(pts.length>=2) return {id:id, type:'polyline', layer:layer, points:pts, color:color, lineWidth:lw, closed:false};
    }
    if(etype==='LWPOLYLINE'){
      var pts2=[];
      var xs=Array.isArray(props[10])?props[10]:(props[10]!==undefined?[props[10]]:[]);
      var ys=Array.isArray(props[20])?props[20]:(props[20]!==undefined?[props[20]]:[]);
      for(var j=0;j<xs.length;j++) pts2.push({x:parseFloat(xs[j]),y:-parseFloat(ys[j]||0)});
      var closed2=(parseInt(props[70]||0)&1)===1;
      if(pts2.length>=2){
        // LWPOLYLINE → 개별 LINE으로 분해 (선택/편집 용이)
        var lineArr=[];
        for(var j=0;j<pts2.length-1;j++){
          lineArr.push({id:mkId(), type:'line', layer:layer,
            x1:pts2[j].x, y1:pts2[j].y, x2:pts2[j+1].x, y2:pts2[j+1].y,
            color:color, lineWidth:lw});
        }
        if(closed2 && pts2.length>2){
          lineArr.push({id:mkId(), type:'line', layer:layer,
            x1:pts2[pts2.length-1].x, y1:pts2[pts2.length-1].y, x2:pts2[0].x, y2:pts2[0].y,
            color:color, lineWidth:lw});
        }
        return lineArr; // 배열 반환
      }
    }
    if(etype==='TEXT'||etype==='MTEXT'){
      var txt=Array.isArray(props[1])?props[1].join(''):String(props[1]||'');
      var fs=parseFloat(props[40]||10);
      return {id:id, type:'text', layer:layer,
        x:parseFloat(props[10]||0), y:-parseFloat(props[20]||0),
        text:txt.replace(/\\P/g,'\n').replace(/\\[^;]*;/g,'').replace(/\{[^}]*\}/g,''),
        fontSize:fs, color:color, fontFamily:'Arial'};
    }
    if(etype==='SOLID'||etype==='3DFACE'){
      var sp=[];
      for(var si=0;si<4;si++){
        var sx=parseFloat(props[10+si]||props[10]||0);
        var sy=-parseFloat(props[20+si]||props[20]||0);
        sp.push({x:sx,y:sy});
      }
      return {id:id, type:'polyline', layer:layer, points:sp, color:color, lineWidth:lw, closed:true};
    }
    if(etype==='LEADER'){
      var lxs=Array.isArray(props[10])?props[10]:(props[10]!==undefined?[props[10]]:[]);
      var lys=Array.isArray(props[20])?props[20]:(props[20]!==undefined?[props[20]]:[]);
      var lpts=[];
      for(var lj=0;lj<lxs.length;lj++) lpts.push({x:parseFloat(lxs[lj]),y:-parseFloat(lys[lj]||0)});
      if(lpts.length>=2) return {id:id, type:'polyline', layer:layer, points:lpts, color:color, lineWidth:lw, closed:false};
    }
    if(etype==='ELLIPSE'){
      // 타원 → 폴리라인 근사
      var ecx=parseFloat(props[10]||0), ecy=-parseFloat(props[20]||0);
      var emx=parseFloat(props[11]||1), emy=-parseFloat(props[21]||0);
      var eratio=parseFloat(props[40]||1);
      var esa=parseFloat(props[41]||0), eea=parseFloat(props[42]||Math.PI*2);
      var eMajR=Math.sqrt(emx*emx+emy*emy);
      var eMinR=eMajR*eratio;
      var eRot=Math.atan2(emy,emx);
      var ePts=[], eStep=Math.PI/36;
      for(var ea2=esa;ea2<=eea;ea2+=eStep){
        var ex=eMajR*Math.cos(ea2), ey=eMinR*Math.sin(ea2);
        ePts.push({x:ecx+ex*Math.cos(eRot)-ey*Math.sin(eRot), y:ecy+ex*Math.sin(eRot)+ey*Math.cos(eRot)});
      }
      if(ePts.length>=2) return {id:id, type:'polyline', layer:layer, points:ePts, color:color, lineWidth:lw, closed:Math.abs(eea-esa-Math.PI*2)<0.01};
    }
    // HATCH: 경계구조가 복잡하여 현재 미지원 (추후 전용 파서 필요)
    if(etype==='SPLINE'){
      // 스플라인 제어점 → 폴리라인 (근사)
      var sxs=Array.isArray(props[10])?props[10]:(props[10]!==undefined?[props[10]]:[]);
      var sys=Array.isArray(props[20])?props[20]:(props[20]!==undefined?[props[20]]:[]);
      var sPts=[];
      for(var sj=0;sj<sxs.length;sj++) sPts.push({x:parseFloat(sxs[sj]),y:-parseFloat(sys[sj]||0)});
      var sClosed=(parseInt(props[70]||0)&1)===1;
      if(sPts.length>=2) return {id:id, type:'polyline', layer:layer, points:sPts, color:color, lineWidth:lw, closed:sClosed};
    }
    return null;
  }

  // 블록 엔티티를 변환(이동+회전+스케일)하여 결과에 추가
  function insertBlock(blockName, ix, iy, sx, sy, rot, insLayer, insColor){
    var def=blockDefs[blockName];
    if(!def) return;
    var cosR=Math.cos(rot), sinR=Math.sin(rot);
    function tx(x,y){ return ix + (x*sx*cosR - y*sy*sinR); }
    function ty(x,y){ return iy + (x*sx*sinR + y*sy*cosR); }

    for(var di=0;di<def.length;di++){
      var d=def[di];
      if(d.etype==='INSERT'){
        // 중첩 블록
        var nix=parseFloat(d.props[10]||0), niy=-parseFloat(d.props[20]||0);
        var nsx=parseFloat(d.props[41]||1), nsy=parseFloat(d.props[42]||1);
        var nrot=(parseFloat(d.props[50]||0))*Math.PI/180;
        var nbName=d.props[2]||'';
        if(nbName && nbName!==blockName){ // 무한루프 방지
          insertBlock(nbName, tx(nix,niy), ty(nix,niy), sx*nsx, sy*nsy, rot+nrot, insLayer, insColor);
        }
        continue;
      }
      var obj=entityToObj(d.etype, d.props, insLayer, insColor);
      if(!obj) continue;
      // 배열이면 풀어서 처리 (LWPOLYLINE → LINE 분해)
      var objs=Array.isArray(obj)?obj:[obj];
      for(var oi=0;oi<objs.length;oi++){
        var o=objs[oi];
        // 좌표 변환
        if(o.type==='line'){
          var lx1=o.x1, ly1=o.y1, lx2=o.x2, ly2=o.y2;
          o.x1=tx(lx1,ly1); o.y1=ty(lx1,ly1);
          o.x2=tx(lx2,ly2); o.y2=ty(lx2,ly2);
        } else if(o.type==='circle'){
          var ccx=o.cx, ccy=o.cy;
          o.cx=tx(ccx,ccy); o.cy=ty(ccx,ccy);
          o.r=o.r*Math.abs(sx);
        } else if(o.type==='polyline'){
          for(var pi=0;pi<o.points.length;pi++){
            var px=o.points[pi].x, py=o.points[pi].y;
            o.points[pi].x=tx(px,py); o.points[pi].y=ty(px,py);
          }
        } else if(o.type==='text'){
          var ttx=o.x, tty=o.y;
          o.x=tx(ttx,tty); o.y=ty(ttx,tty);
          o.fontSize=o.fontSize*Math.abs(sx);
        } else if(o.type==='dim'){
          var d1x=o.x1,d1y=o.y1,d2x=o.x2,d2y=o.y2;
          o.x1=tx(d1x,d1y);o.y1=ty(d1x,d1y);
          o.x2=tx(d2x,d2y);o.y2=ty(d2x,d2y);
        }
        o.id=mkId();
        result.push(o);
      }
    }
  }

  // ── 1단계: BLOCKS 섹션 파싱 ──
  while(i<lines.length){
    var code=next(), val=next();
    if(code===null) break;
    if(code==='0' && val==='SECTION'){
      var c2=next(), v2=next();
      if(c2==='2' && v2==='BLOCKS'){
        // 블록 정의 읽기
        while(i<lines.length){
          var bc=next(), bv=next();
          if(bc===null) break;
          if(bc==='0' && bv==='ENDSEC') break;
          if(bc==='0' && bv==='BLOCK'){
            var bProps=readProps();
            var bName=bProps[2]||'';
            var bEnts=[];
            // 블록 내 엔티티 읽기
            while(i<lines.length){
              if(peek()==='0' && peekVal()==='ENDBLK') { next(); next(); readProps(); break; }
              var ec=next(), ev=next();
              if(ec===null) break;
              if(ec==='0' && ETYPES.indexOf(ev)!==-1){
                var eProps=readProps();
                bEnts.push({etype:ev, props:eProps});
              }
            }
            if(bName) blockDefs[bName]=bEnts;
          }
        }
        break;
      }
      if(c2==='2' && v2==='ENTITIES'){
        // BLOCKS 없이 바로 ENTITIES 진입
        i-=4; // 되감기
        break;
      }
    }
  }

  // ── 2단계: ENTITIES 섹션 파싱 ──
  // i를 처음으로 되돌려서 ENTITIES 찾기
  i=0;
  while(i<lines.length){
    var code2=next(), val2=next();
    if(code2==='0' && val2==='SECTION'){
      var c3=next(), v3=next();
      if(c3==='2' && v3==='ENTITIES') break;
    }
  }

  while(i<lines.length){
    var ec2=next(), ev2=next();
    if(ec2===null||ev2===null) break;
    if(ec2==='0' && ev2==='ENDSEC') break;
    if(ec2==='0' && ETYPES.indexOf(ev2)!==-1){
      var eProps2=readProps();
      if(ev2==='INSERT'){
        // 블록 삽입
        var insName=eProps2[2]||'';
        var insX=parseFloat(eProps2[10]||0);
        var insY=-parseFloat(eProps2[20]||0);
        var insScX=parseFloat(eProps2[41]||1);
        var insScY=parseFloat(eProps2[42]||1);
        var insRot=(parseFloat(eProps2[50]||0))*Math.PI/180;
        var insLayer=eProps2[8]||'0';
        var insCi=eProps2[62]?parseInt(eProps2[62]):0;
        var insColor=insCi>0?dxfColorToHex(insCi):'#ffffff';
        layerSet.add(insLayer);
        insertBlock(insName, insX, insY, insScX, insScY, insRot, insLayer, insColor);
      } else if(ev2==='DIMENSION'){
        // DIMENSION은 블록 참조(*D숫자)로 렌더링됨 — 블록에서 처리
        var dimBlock=eProps2[2]||'';
        if(dimBlock && blockDefs[dimBlock]){
          // *D 블록은 절대좌표로 저장되어 있으므로 (0,0)에 삽입
          insertBlock(dimBlock, 0, 0, 1, 1, 0, eProps2[8]||'0', '#ffffff');
        }
      } else {
        var obj2=entityToObj(ev2, eProps2, '0', '#ffffff');
        if(obj2){
          if(Array.isArray(obj2)) obj2.forEach(function(o2){ result.push(o2); });
          else result.push(obj2);
        }
      }
    }
  }

  // 레이어 자동 등록
  layerSet.forEach(function(name){
    if(!layers.find(function(l){return l.name===name;})){
      layers.push({name:name, color:'#ffffff', visible:true, locked:false});
    }
  });

  return result;
}

// DXF 색상 인덱스 → HEX
function dxfColorToHex(ci){
  const map={1:'#ff0000',2:'#ffff00',3:'#00ff00',4:'#00ffff',5:'#0000ff',6:'#ff00ff',7:'#ffffff',
    8:'#808080',9:'#c0c0c0',10:'#ff0000',11:'#ff7f7f',30:'#ff7f00',40:'#ff7f00',
    50:'#ffbf00',60:'#bfff00',70:'#7fff00',80:'#00ff00',90:'#00ff7f',
    100:'#00ffbf',110:'#00ffff',120:'#00bfff',130:'#007fff',140:'#0000ff',
    150:'#7f00ff',160:'#bf00ff',170:'#ff00ff',180:'#ff007f',190:'#ff003f',
    200:'#ff7f7f',210:'#ffbf7f',220:'#ffff7f',230:'#bfff7f',240:'#7fff7f',250:'#333333',251:'#545454',252:'#787878',253:'#a1a1a1',254:'#c8c8c8',255:'#ffffff'};
  return map[ci]||'#ffffff';
}

// ── DWG 로딩 팝업 ──
function showDwgLoading(fileName, fileSize){
  var ov=document.getElementById('dwgLoadingOverlay');
  if(ov) ov.remove();
  ov=document.createElement('div');
  ov.id='dwgLoadingOverlay';
  ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;';
  var sizeTxt=fileSize>1048576?(fileSize/1048576).toFixed(1)+'MB':fileSize>1024?(fileSize/1024).toFixed(0)+'KB':fileSize+'B';
  ov.innerHTML='<div class="cad-dwg-loading">'
    +'<div style="margin-bottom:20px"><div class="dwgSpinner" style="width:48px;height:48px;border:4px solid #1b3354;border-top:4px solid #00aaff;border-radius:50%;animation:dwgSpin 0.8s linear infinite;margin:0 auto"></div></div>'
    +'<div style="color:#d0e8ff;font-size:15px;font-weight:700;margin-bottom:8px">도면 변환 중...</div>'
    +'<div style="color:#5a8ab5;font-size:12px;margin-bottom:4px">'+fileName+' ('+sizeTxt+')</div>'
    +'<div id="dwgLoadingMsg" style="color:#3a6a8a;font-size:11px;margin-top:12px">서버에서 DWG → DXF 변환 처리 중</div>'
    +'<div style="margin-top:16px;background:#111e30;border-radius:6px;height:6px;overflow:hidden"><div id="dwgLoadingBar" style="width:0%;height:100%;background:linear-gradient(90deg,#00aaff,#00ffcc);border-radius:6px;transition:width 0.5s"></div></div>'
    +'</div>';
  if(!document.getElementById('dwgSpinStyle')){
    var st=document.createElement('style');st.id='dwgSpinStyle';
    st.textContent='@keyframes dwgSpin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }
  document.body.appendChild(ov);
  // 가짜 프로그레스
  var bar=ov.querySelector('#dwgLoadingBar');
  var msg=ov.querySelector('#dwgLoadingMsg');
  var pct=0;
  var steps=[
    {t:500,p:15,m:'서버 업로드 중...'},
    {t:1500,p:30,m:'ODA 변환 엔진 처리 중...'},
    {t:3000,p:50,m:'DXF 데이터 생성 중...'},
    {t:6000,p:70,m:'변환 데이터 수신 중...'},
    {t:10000,p:85,m:'거의 완료...'}
  ];
  window._dwgLoadingTimers=[];
  steps.forEach(function(s){
    window._dwgLoadingTimers.push(setTimeout(function(){
      if(bar&&pct<s.p){pct=s.p;bar.style.width=pct+'%';}
      if(msg)msg.textContent=s.m;
    },s.t));
  });
}
function hideDwgLoading(success,message){
  if(window._dwgLoadingTimers){window._dwgLoadingTimers.forEach(clearTimeout);window._dwgLoadingTimers=null;}
  var ov=document.getElementById('dwgLoadingOverlay');
  if(!ov) return;
  var bar=ov.querySelector('#dwgLoadingBar');
  var msg=ov.querySelector('#dwgLoadingMsg');
  if(bar) bar.style.width='100%';
  if(msg) msg.textContent=message||'완료';
  if(bar) bar.style.background=success?'linear-gradient(90deg,#00cc88,#00ffcc)':'linear-gradient(90deg,#ff4466,#ff6688)';
  setTimeout(function(){if(ov&&ov.parentNode)ov.remove();},success?600:1500);
}

// DWG 파일 업로드 → 서버 변환 → DXF 파싱
function importDWG(file){
  if(!file) return;
  const ext=file.name.toLowerCase().split('.').pop();
  if(ext!=='dwg' && ext!=='dxf'){
    notify('DWG 또는 DXF 파일만 지원합니다.','danger');
    return;
  }

  // DXF 파일이면 바로 파싱
  if(ext==='dxf'){
    showDwgLoading(file.name, file.size);
    const reader=new FileReader();
    reader.onload=function(e){
      const objs=parseDXF(e.target.result);
      if(objs.length===0){ hideDwgLoading(false,'객체를 찾을 수 없습니다'); notify('DXF에서 객체를 찾을 수 없습니다.','warn'); return; }
      pushUndo();
      objects=objects.concat(objs);
      renderLayerList(); fitAll(); updateStatus();
      hideDwgLoading(true,objs.length+'개 객체 로드 완료');
      notify(file.name+' 로드 완료 ('+objs.length+'개 객체)');
    };
    reader.readAsText(file);
    return;
  }

  // DWG → 서버 변환
  showDwgLoading(file.name, file.size);
  const fd=new FormData();
  fd.append('dwg_file', file);

  const apiBase=(window._CAD_API||'index.php?api=').replace(/[^\/]*\?.*$/,'');
  var _dwgUrl=apiBase+'dist/dwg_convert.php';
  fetch(_dwgUrl, {method:'POST', body:fd})
    .then(function(r){
      if(!r.ok) throw new Error('HTTP '+r.status+' '+r.statusText);
      return r.text();
    })
    .then(function(txt){
      var r;
      try{ r=JSON.parse(txt); }catch(e){ hideDwgLoading(false,'서버 응답 파싱 실패'); notify('서버 응답 파싱 실패: '+txt.substring(0,300),'danger'); return; }
      if(!r.ok){ hideDwgLoading(false,r.error||'변환 실패'); notify('변환 실패: '+(r.error||'알 수 없는 오류'),'danger'); return; }
      if(!r.dxf){ hideDwgLoading(false,'DXF 데이터 없음'); notify('DXF 데이터가 비어있습니다.','danger'); return; }
      var objs=parseDXF(r.dxf);
      if(objs.length===0){ hideDwgLoading(false,'객체를 찾을 수 없습니다'); notify('변환된 DXF에서 객체를 찾을 수 없습니다. DXF크기:'+r.dxf.length,'warn'); return; }
      pushUndo();
      objects=objects.concat(objs);
      renderLayerList(); fitAll(); updateStatus();
      hideDwgLoading(true,objs.length+'개 객체 변환 완료');
      notify(r.filename+' 변환 완료 ('+objs.length+'개 객체)');
    })
    .catch(function(e){ hideDwgLoading(false,'변환 오류: '+e.message); notify('DWG 변환 오류: '+e.message,'danger'); });
}

// ── DXF Export ───────────────────────────────
function saveDXF(){
  // 항상 mm 단위로 내보내기: scale 적용 + 단위 변환
  const unitMul = unit==='m' ? 1000 : unit==='cm' ? 10 : 1;
  const f = scale * unitMul; // 픽셀→mm 변환 계수
  function dx(v){ return (v*f).toFixed(2); }
  function dy(v){ return (-v*f).toFixed(2); } // Y축 반전

  let dxf='0\nSECTION\n2\nHEADER\n';
  // mm 단위 설정
  dxf+='9\n$INSUNITS\n70\n4\n';
  dxf+='0\nENDSEC\n0\nSECTION\n2\nENTITIES\n';

  objects.forEach(o=>{
    const layerName = (layers.find(l=>l.id===o.layerId)||{}).name||'0';
    if(o.type==='line'){
      dxf+=`0\nLINE\n8\n${layerName}\n10\n${dx(o.x1)}\n20\n${dy(o.y1)}\n30\n0\n11\n${dx(o.x2)}\n21\n${dy(o.y2)}\n31\n0\n`;
    }
    else if(o.type==='wall'){
      dxf+=`0\nLINE\n8\n${layerName}\n10\n${dx(o.x1)}\n20\n${dy(o.y1)}\n30\n0\n11\n${dx(o.x2)}\n21\n${dy(o.y2)}\n31\n0\n`;
    }
    else if(o.type==='rect'){
      [[o.x,o.y,o.x+o.w,o.y],[o.x+o.w,o.y,o.x+o.w,o.y+o.h],[o.x+o.w,o.y+o.h,o.x,o.y+o.h],[o.x,o.y+o.h,o.x,o.y]].forEach(([x1,y1,x2,y2])=>{
        dxf+=`0\nLINE\n8\n${layerName}\n10\n${dx(x1)}\n20\n${dy(y1)}\n30\n0\n11\n${dx(x2)}\n21\n${dy(y2)}\n31\n0\n`;
      });
    }
    else if(o.type==='circle'){
      dxf+=`0\nCIRCLE\n8\n${layerName}\n10\n${dx(o.cx)}\n20\n${dy(o.cy)}\n30\n0\n40\n${dx(o.r)}\n`;
    }
    else if(o.type==='polyline'&&o.points&&o.points.length>1){
      for(let i=0;i<o.points.length-1;i++){
        dxf+=`0\nLINE\n8\n${layerName}\n10\n${dx(o.points[i].x)}\n20\n${dy(o.points[i].y)}\n30\n0\n11\n${dx(o.points[i+1].x)}\n21\n${dy(o.points[i+1].y)}\n31\n0\n`;
      }
    }
    else if(o.type==='text'||o.type==='annot'){
      const txt = o.text||'';
      if(txt) dxf+=`0\nTEXT\n8\n${layerName}\n10\n${dx(o.x)}\n20\n${dy(o.y)}\n30\n0\n40\n${(o.fontSize||14)*f/scale}\n1\n${txt}\n`;
    }
    else if(o.type==='dim'&&o.dimType==='linear'){
      dxf+=`0\nLINE\n8\n${layerName}\n10\n${dx(o.x1)}\n20\n${dy(o.y1)}\n30\n0\n11\n${dx(o.x2)}\n21\n${dy(o.y2)}\n31\n0\n`;
    }
  });
  dxf+='0\nENDSEC\n0\nEOF\n';
  const blob=new Blob([dxf],{type:'text/plain'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='shv_plan.dxf'; a.click();
  notify('DXF \uB0B4\uBCF4\uB0B4\uAE30 \uC644\uB8CC (mm \uB2E8\uC704)');
  _exportToServer('shv_plan','dxf',dxf);
}

// ── DWG Export (서버 변환) ────────────────────
// ── 서버 Export 공통 함수 (현장폴더에 저장) ──
function _exportToServer(fname, ext, blobOrText){
  var siteNo = (typeof _getSiteNo==='function') ? _getSiteNo() : '';
  var apiUrl = (window._CAD_API||'cad.php?api=') + 'export';
  if(siteNo) apiUrl += '&site_no='+encodeURIComponent(siteNo);
  // blob이면 base64로 변환
  if(blobOrText instanceof Blob){
    var reader = new FileReader();
    reader.onload = function(){
      fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:fname,ext:ext,content:reader.result,site_no:siteNo})});
    };
    reader.readAsDataURL(blobOrText);
  } else {
    fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:fname,ext:ext,content:blobOrText,site_no:siteNo})});
  }
}

function saveDWG(filename){
  const fname = filename || 'shv_plan';
  const dxfContent = generateDXFContent();
  notify('DWG 변환 중...');
  const apiBase=(window._CAD_API||'index.php?api=').replace(/[^\/]*\?.*$/,'');
  fetch(apiBase+'dist/dwg_convert.php?mode=export',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({dxf:dxfContent, filename:fname})
  })
  .then(r=>{
    if(!r.ok) throw new Error('서버 오류');
    const ct=r.headers.get('content-type')||'';
    if(ct.includes('json')) return r.json().then(j=>{ throw new Error(j.error||'변환 실패'); });
    return r.blob();
  })
  .then(blob=>{
    // PC 다운로드
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download=fname+'.dwg';
    a.click();
    URL.revokeObjectURL(a.href);
    notify('DWG 저장 완료: '+fname+'.dwg');
    // 서버에도 저장 (현장폴더)
    _exportToServer(fname,'dwg',blob);
  })
  .catch(e=>{ notify('DWG 저장 실패: '+e.message,'danger'); });
}

// ── SVG Export ───────────────────────────────
function saveSVG(){
  const W=canvas.width, H=canvas.height;
  let svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">\n`;
  svg+=`<rect width="${W}" height="${H}" fill="#080e1a"/>\n`;
  svg+=`<g transform="translate(${viewX},${viewY}) scale(${viewZoom})">\n`;
  objects.forEach(o=>{
    const col=o.color||'#00aaff';
    const lw=o.lineWidth||1;
    if(o.type==='line'){
      svg+=`<line x1="${o.x1}" y1="${o.y1}" x2="${o.x2}" y2="${o.y2}" stroke="${col}" stroke-width="${lw}"/>\n`;
    }
    else if(o.type==='rect'){
      svg+=`<rect x="${o.x}" y="${o.y}" width="${o.w}" height="${o.h}" stroke="${col}" stroke-width="${lw}" fill="${o.fill?o.fillColor:'none'}"/>\n`;
    }
    else if(o.type==='circle'){
      svg+=`<circle cx="${o.cx}" cy="${o.cy}" r="${o.r}" stroke="${col}" stroke-width="${lw}" fill="${o.fill?o.fillColor:'none'}"/>\n`;
    }
    else if(o.type==='polyline'&&o.points.length>1){
      const pts=o.points.map(p=>`${p.x},${p.y}`).join(' ');
      svg+=`<polyline points="${pts}" stroke="${col}" stroke-width="${lw}" fill="none"/>\n`;
    }
    else if(o.type==='text'){
      svg+=`<text x="${o.x}" y="${o.y}" fill="${col}" font-size="${o.fontSize||14}" font-family="sans-serif">${o.text}</text>\n`;
    }
  });
  svg+=`</g>\n</svg>`;
  const blob=new Blob([svg],{type:'image/svg+xml'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='shv_plan.svg'; a.click();
  notify('SVG \uB0B4\uBCF4\uB0B4\uAE30 \uC644\uB8CC');
}

// ── PNG Export ───────────────────────────────
// \uCD9C\uB825\uC6A9 \uCE94\uBC84\uC2A4 \uC0DD\uC131 (\uD770 \uBC30\uACBD + \uAC80\uC815 \uC120 - \uC0C9\uC0C1 \uBC18\uC804)
function renderPrintCanvas(srcCanvas){
  var dpi=parseInt(document.getElementById('pDpi')?.value||600);
  var hiRes=Math.max(1,Math.round(dpi/96));

  // 영역 결정: select면 선택 사각형 안의 객체 바운딩박스, 아니면 전체/화면
  var sx=0, sy=0, sw=srcCanvas.width, sh=srcCanvas.height;
  if(printMode==='select' && printStart && printEnd){
    // 화면 좌표 → 월드 좌표로 변환
    var wx1=(Math.min(printStart.x,printEnd.x)-viewX)/viewZoom;
    var wy1=(Math.min(printStart.y,printEnd.y)-viewY)/viewZoom;
    var wx2=(Math.max(printStart.x,printEnd.x)-viewX)/viewZoom;
    var wy2=(Math.max(printStart.y,printEnd.y)-viewY)/viewZoom;
    // 영역 안의 객체 바운딩박스 계산
    var bx1=Infinity,by1=Infinity,bx2=-Infinity,by2=-Infinity;
    objects.forEach(function(o){
      var layer=layers.find(function(l){return l.id===o.layerId;});
      if(layer&&!layer.visible) return;
      var bb=getBounds(o);
      if(!bb) return;
      // 객체가 선택 영역과 겹치는지
      if(bb.x+bb.w<wx1||bb.x>wx2||bb.y+bb.h<wy1||bb.y>wy2) return;
      bx1=Math.min(bx1,bb.x); by1=Math.min(by1,bb.y);
      bx2=Math.max(bx2,bb.x+bb.w); by2=Math.max(by2,bb.y+bb.h);
    });
    if(bx1!==Infinity){
      // 여유 마진
      var pad=20;
      bx1-=pad; by1-=pad; bx2+=pad; by2+=pad;
      // 월드 좌표 → 화면 좌표
      sx=bx1*viewZoom+viewX; sy=by1*viewZoom+viewY;
      sw=(bx2-bx1)*viewZoom; sh=(by2-by1)*viewZoom;
    } else {
      sx=Math.min(printStart.x,printEnd.x); sy=Math.min(printStart.y,printEnd.y);
      sw=Math.abs(printEnd.x-printStart.x); sh=Math.abs(printEnd.y-printStart.y);
    }
    if(sw<10||sh<10){ sw=srcCanvas.width; sh=srcCanvas.height; sx=0; sy=0; }
  }

  // 고해상도 캔버스에 객체만 그리기
  var tmpCanvas=document.createElement('canvas');
  tmpCanvas.width=srcCanvas.width*hiRes; tmpCanvas.height=srcCanvas.height*hiRes;
  var tmpCtx=tmpCanvas.getContext('2d');
  tmpCtx.fillStyle='#000000';
  tmpCtx.fillRect(0,0,tmpCanvas.width,tmpCanvas.height);
  tmpCtx.save();
  tmpCtx.scale(hiRes, hiRes);
  tmpCtx.translate(viewX, viewY);
  tmpCtx.scale(viewZoom, viewZoom);
  for(var i=0;i<objects.length;i++){
    var o=objects[i];
    var layer=layers.find(function(l){return l.id===o.layerId;});
    if(layer&&!layer.visible) continue;
    drawObject(tmpCtx, o, false);
  }
  tmpCtx.restore();

  // 선택 영역 잘라내기
  var offCanvas=document.createElement('canvas');
  offCanvas.width=Math.round(sw*hiRes); offCanvas.height=Math.round(sh*hiRes);
  var offCtx=offCanvas.getContext('2d');
  offCtx.fillStyle='#ffffff';
  offCtx.fillRect(0,0,offCanvas.width,offCanvas.height);
  offCtx.drawImage(tmpCanvas, Math.round(sx*hiRes), Math.round(sy*hiRes), Math.round(sw*hiRes), Math.round(sh*hiRes), 0, 0, offCanvas.width, offCanvas.height);

  // 색상 반전
  var imgData=offCtx.getImageData(0,0,offCanvas.width,offCanvas.height);
  var d=imgData.data;
  for(var j=0;j<d.length;j+=4){ d[j]=255-d[j]; d[j+1]=255-d[j+1]; d[j+2]=255-d[j+2]; }
  offCtx.putImageData(imgData,0,0);

  // 워터마크
  var wmOn=document.getElementById('pWatermark')?.checked;
  if(wmOn){
    var fs=Math.max(12, Math.round(offCanvas.width/120));
    offCtx.font='bold '+fs+'px "Noto Sans KR", sans-serif';
    offCtx.fillStyle='rgba(0,0,0,0.6)';
    // 상단 좌측: 현장번호 / 현장명
    var siteNo=document.getElementById('siteNo')?.textContent||'';
    var siteName=document.getElementById('siteName')?.textContent||'';
    var topText=(siteNo&&siteNo!=='미연결'?'['+siteNo+'] ':'')+(siteName||'');
    if(topText) offCtx.fillText(topText, fs, fs*1.5);
    // 하단 우측: 회사명 / 작성자
    var company=window._CAD_COMPANY||'';
    var author=window._CAD_AUTHOR||'';
    var bottomText=company+(author?' / '+author:'');
    if(bottomText){
      var tw=offCtx.measureText(bottomText).width;
      offCtx.fillText(bottomText, offCanvas.width-tw-fs, offCanvas.height-fs);
    }
  }

  // 출력일자
  var pdOn=document.getElementById('pPrintDate')?.checked;
  if(pdOn){
    var now=new Date();
    var ds=now.getFullYear()+'년 '+(now.getMonth()+1)+'월 '+now.getDate()+'일 '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
    var dateText='출력일자 '+ds;
    var dfs=Math.max(10, Math.round(offCanvas.width/140));
    offCtx.font=dfs+'px "Noto Sans KR", sans-serif';
    offCtx.fillStyle='rgba(0,0,0,0.5)';
    var dtw=offCtx.measureText(dateText).width;
    offCtx.fillText(dateText, (offCanvas.width-dtw)/2, offCanvas.height-dfs*0.8);
  }

  return offCanvas;
}

function savePNG(){
  const printCanvas=renderPrintCanvas(canvas);
  const a=document.createElement('a');
  a.href=printCanvas.toDataURL('image/png'); a.download='shv_plan.png'; a.click();
  notify('PNG \uC800\uC7A5 \uC644\uB8CC (\uD770 \uBC30\uACBD/\uAC80\uC815 \uC120)');
}

// ── PDF Print ────────────────────────────────
function openPrintModal(mode){
  printMode=mode||'view';
  // 영역 선택 드롭다운 동기화
  var pArea=document.getElementById('pArea');
  if(pArea) pArea.value=printMode;
  onPrintAreaChange();
  openModal('printModal');
  updatePrintPreview();
}

function onPrintAreaChange(){
  var sel=document.getElementById('pArea');
  if(!sel) return;
  var selectBtn=document.getElementById('pAreaSelectBtn');
  if(sel.value==='select'){
    // 버튼만 표시 (설정 후 클릭하도록)
    if(selectBtn) selectBtn.style.display='block';
    printMode='select';
  } else {
    if(selectBtn) selectBtn.style.display='none';
    printMode=sel.value;
    printStart=null; printEnd=null;
    document.getElementById('pAreaInfo').style.display='none';
    updatePrintPreview();
  }
}
window.startPrintSelect=startPrintAreaSelect;
// 전역 등록 (cad.php 인라인에서 호출 가능하도록)
window._onPrintAreaChange=onPrintAreaChange;

function startPrintAreaSelect(){
  closeModal('printModal');
  notify('첫번째 점 클릭 → 두번째 점 클릭으로 인쇄 영역 지정 | ESC 취소');
  printMode='select';
  var po=document.getElementById('printOverlay');
  po.style.display='block';
  printStart=null; printEnd=null;
  var _step=0;

  function getPos(e){
    var cr=canvas.getBoundingClientRect();
    return {x:e.clientX-cr.left, y:e.clientY-cr.top};
  }

  po.onclick=function(e){
    var pt=getPos(e);
    if(_step===0){
      printStart=pt;
      _step=1;
      notify('두번째 점을 클릭하세요');
    } else {
      printEnd=pt;
      po.style.display='none';
      document.getElementById('printRect').style.display='none';
      document.getElementById('printLabel').style.display='none';
      var w=Math.abs(printEnd.x-printStart.x), h=Math.abs(printEnd.y-printStart.y);
      if(w>10&&h>10){
        printMode='select';
        var info=document.getElementById('pAreaInfo');
        if(info){ info.textContent='✂ 선택 영역: '+Math.round(w)+' × '+Math.round(h)+' px'; info.style.display='block'; }
        var pArea=document.getElementById('pArea');
        if(pArea) pArea.value='select';
        openModal('printModal');
        updatePrintPreview();
      } else {
        notify('영역이 너무 작습니다. 다시 선택해주세요.','warn');
      }
      po.onmousemove=null; po.onclick=null;
    }
  };

  po.onmousemove=function(e){
    if(_step===0) return;
    var cur=getPos(e);
    var cr=canvas.getBoundingClientRect();
    var pr=document.getElementById('printRect');
    pr.style.left=Math.min(printStart.x,cur.x)+'px';
    pr.style.top=Math.min(printStart.y,cur.y)+'px';
    pr.style.width=Math.abs(cur.x-printStart.x)+'px';
    pr.style.height=Math.abs(cur.y-printStart.y)+'px';
    pr.style.display='block';
    var lbl=document.getElementById('printLabel');
    if(lbl){
      lbl.style.left=(e.clientX-cr.left+10)+'px'; lbl.style.top=(e.clientY-cr.top+10)+'px';
      lbl.style.display='block';
      lbl.textContent=Math.round(Math.abs(cur.x-printStart.x))+' × '+Math.round(Math.abs(cur.y-printStart.y));
    }
  };

  // ESC 취소
  var _escHandler=function(e){
    if(e.key==='Escape'){
      po.style.display='none';
      document.getElementById('printRect').style.display='none';
      document.getElementById('printLabel').style.display='none';
      po.onmousemove=null; po.onclick=null;
      document.removeEventListener('keydown',_escHandler);
      notify('영역 선택 취소');
    }
  };
  document.addEventListener('keydown',_escHandler);
}

function updatePrintPreview(){
  var pc=renderPrintCanvas(canvas);
  var pv=document.getElementById('printPreviewCanvas');
  if(!pv) return;
  pv.width=pc.width; pv.height=pc.height;
  pv.getContext('2d').drawImage(pc,0,0);
}

function doPrintDirect(){
  // 인쇄도 PDF 생성 → pdf_reader.php에서 미리보기+인쇄
  doPrint();
}

// startPrintSelect → startPrintAreaSelect로 통합됨
function startPrintSelect(){ startPrintAreaSelect(); }

function doPrint(){
  closeModal('printModal');
  notify('PDF 생성 중...');
  const printCanvas=renderPrintCanvas(canvas);
  const paper=document.getElementById('pPaper').value;
  const orient=document.getElementById('pOrient').value;

  // jsPDF CDN 로드 후 PDF 생성
  if(typeof jspdf==='undefined' && typeof jsPDF==='undefined'){
    var s=document.createElement('script');
    s.src='https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    s.onload=function(){ _cadMakePDF(printCanvas, paper, orient); };
    s.onerror=function(){ notify('jsPDF 로드 실패','danger'); _cadPrintFallback(printCanvas, paper, orient); };
    document.head.appendChild(s);
  } else {
    _cadMakePDF(printCanvas, paper, orient);
  }
}

function _cadMakePDF(printCanvas, paper, orient){
  try {
    var JsPDF = (typeof jspdf!=='undefined') ? jspdf.jsPDF : jsPDF;
    var isLand = orient==='landscape';
    var doc = new JsPDF({orientation:isLand?'l':'p', unit:'mm', format:paper.toLowerCase()});
    var pw=doc.internal.pageSize.getWidth(), ph=doc.internal.pageSize.getHeight();
    var m=10, cw=printCanvas.width, ch=printCanvas.height;
    var r=Math.min((pw-m*2)/cw,(ph-m*2)/ch);
    var iw=cw*r, ih=ch*r, x=(pw-iw)/2, y=(ph-ih)/2;
    doc.addImage(printCanvas.toDataURL('image/png'),'PNG',x,y,iw,ih);
    var blob=doc.output('blob');
    var url=URL.createObjectURL(blob);
    var fname=((typeof currentFileName!=='undefined'&&currentFileName)?currentFileName:'shv_plan')+'.pdf';
    // PDF 미리보기 모달 표시
    _showPdfPreviewModal(url, fname);
    notify('PDF 생성 완료');
  } catch(e){
    notify('PDF 생성 실패: '+e.message,'danger');
    _cadPrintFallback(printCanvas, paper, orient);
  }
}

function _showPdfPreviewModal(url, fname){
  // 기존 모달 제거
  var old=document.getElementById('cadPdfPreviewModal');
  if(old) old.remove();
  var overlay=document.createElement('div');
  overlay.id='cadPdfPreviewModal';
  overlay.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:99999;display:flex;align-items:center;justify-content:center;';
  var modal=document.createElement('div');
  modal.style.cssText='background:#1a1f2e;border:1px solid #333;border-radius:10px;width:90%;height:90%;max-width:1200px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.5);';
  // 헤더
  var header=document.createElement('div');
  header.style.cssText='padding:10px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #333;flex-shrink:0;';
  header.innerHTML='<span style="font-size:13px;font-weight:700;color:#fff;">📄 '+_esc(fname)+'</span>'
    +'<span style="flex:1"></span>'
    +'<button onclick="window.open(\''+url+'\',\'_blank\')" style="padding:5px 12px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">📥 다운로드</button>'
    +'<button onclick="var w=window.open(\''+url+'\',\'_blank\');if(w)w.addEventListener(\'load\',function(){w.print()})" style="padding:5px 12px;background:#3b82f6;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">🖨 인쇄</button>'
    +'<button onclick="document.getElementById(\'cadPdfPreviewModal\').remove()" style="padding:5px 12px;background:#444;color:#fff;border:none;border-radius:6px;font-size:11px;cursor:pointer;">✕ 닫기</button>';
  // iframe으로 PDF 표시
  var iframe=document.createElement('iframe');
  iframe.src=url;
  iframe.style.cssText='flex:1;border:none;background:#525659;';
  modal.appendChild(header);
  modal.appendChild(iframe);
  overlay.appendChild(modal);
  overlay.onclick=function(e){ if(e.target===overlay) overlay.remove(); };
  document.body.appendChild(overlay);
}

function _cadPrintFallback(printCanvas, paper, orient){
  var dataUrl=printCanvas.toDataURL('image/png');
  var win=window.open('','_blank');
  var h=['<!DOCTYPE html><html><head><style>',
    '@page{size:'+paper+' '+orient+';margin:5mm}',
    '*{margin:0;padding:0;box-sizing:border-box}',
    'html,body{width:100%;height:100%;background:#fff}',
    'img{width:100%;height:100%;object-fit:contain}',
    '<\/style><\/head><body>',
    '<img src="'+dataUrl+'" onload="window.print()">',
    '<\/body><\/html>'].join('');
  win.document.write(h);
  win.document.close();
}

// ── Background Image / PDF ────────────────────
function openImportBg(){ openModal('bgModal'); }
function updateBgAlpha(v){
  bgAlpha=parseFloat(v);
  document.getElementById('bgAlphaVal').textContent=v;
  render();
}
function applyBg(){
  bgScaleVal=parseFloat(document.getElementById('bgScale').value)||1;
  bgAlpha=parseFloat(document.getElementById('bgAlpha').value)||0.5;
  closeModal('bgModal'); render();
}
function removeBg(){ bgImage=null; closeModal('bgModal'); render(); }

let _pdfDoc=null, _pdfCurPage=1;

function loadBgFile(input){
  const f=input.files[0]; if(!f) return;
  const reader=new FileReader();
  reader.onload=e=>{
    if(f.type==='application/pdf'||f.name.toLowerCase().endsWith('.pdf')){
      _openPdfPicker(e.target.result);
    } else {
      const img=new Image();
      img.onload=()=>{
        // 이미지를 CAD 객체로 삽입 (선택 가능)
        pushUndo();
        const wp=toWorld(wrap.clientWidth/2, wrap.clientHeight/2);
        objects.push({
          id:nextId(), type:'bgimage',
          x:wp.x-img.width/2/viewZoom, y:wp.y-img.height/2/viewZoom,
          w:img.width/viewZoom, h:img.height/viewZoom,
          src:e.target.result, alpha:bgAlpha,
          layerId:layers[currentLayer].id,
          locked:false
        });
        closeModal('bgModal'); refreshUI({status:true});
        notify('\uC774\uBBF8\uC9C0 \uC0BD\uC785 \uC644\uB8CC');
      };
      img.src=e.target.result;
    }
  };
  reader.readAsDataURL(f);
}

let _pdfDropPos = null; // 드래그앤드롭 시 삽입 위치

// ── SheetJS 동적 로딩 ─────────────────────────
function _loadSheetJS(callback){
  if(typeof XLSX !== 'undefined'){ callback(); return; }
  notify('SheetJS \uB85C\uB529 \uC911...');
  const s = document.createElement('script');
  s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  s.onload = function(){ callback(); };
  s.onerror = function(){ notify('SheetJS \uB85C\uB529 \uC2E4\uD328 (\uC778\uD130\uB137 \uC5F0\uACB0 \uD655\uC778)'); };
  document.head.appendChild(s);
}

function _openPdfPicker(dataUrl, dropPos){
  _pdfDropPos = dropPos || null;
  if(typeof pdfjsLib==='undefined'){
    notify('PDF \uB85C\uB529 \uC911...');
    const s=document.createElement('script');
    s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    s.onload=()=>{
      pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      _loadPdfDoc(dataUrl);
    };
    document.head.appendChild(s);
  } else {
    _loadPdfDoc(dataUrl);
  }
}

function _loadPdfDoc(dataUrl){
  const base64=dataUrl.split(',')[1];
  const binary=atob(base64);
  const bytes=new Uint8Array(binary.length);
  for(let i=0;i<binary.length;i++) bytes[i]=binary.charCodeAt(i);
  pdfjsLib.getDocument({data:bytes}).promise.then(function(pdf){
    _pdfDoc=pdf;
    _pdfCurPage=1;
    document.getElementById('pdfTotalPages').textContent=pdf.numPages+'\uD398\uC774\uC9C0';
    document.getElementById('pdfPageNum').max=pdf.numPages;
    document.getElementById('pdfPageNum').value=1;
    closeModal('bgModal');
    openModal('pdfPageModal');
    _renderPdfThumb(1);
  }).catch(function(e){ notify('PDF \uC624\uB958: '+e.message); });
}

function _renderPdfThumb(pageNum){
  if(!_pdfDoc) return;
  _pdfDoc.getPage(pageNum).then(function(page){
    const viewport=page.getViewport({scale:1.2});
    const c=document.getElementById('pdfThumbCanvas');
    c.width=viewport.width; c.height=viewport.height;
    page.render({canvasContext:c.getContext('2d'), viewport:viewport}).promise.then(function(){
      document.getElementById('pdfPageNum').value=pageNum;
    });
  });
}

function _insertPdfPage(){
  if(!_pdfDoc) return;
  const pageNum=parseInt(document.getElementById('pdfPageNum').value)||1;
  const alpha=parseFloat(document.getElementById('bgAlpha').value)||0.5;
  _pdfDoc.getPage(pageNum).then(function(page){
    const viewport=page.getViewport({scale:2});
    const offCanvas=document.createElement('canvas');
    offCanvas.width=viewport.width; offCanvas.height=viewport.height;
    page.render({canvasContext:offCanvas.getContext('2d'), viewport:viewport}).promise.then(function(){
      const src=offCanvas.toDataURL('image/png');
      const img=new Image();
      img.onload=()=>{
        pushUndo();
        const wp = _pdfDropPos || toWorld(wrap.clientWidth/2, wrap.clientHeight/2);
        _pdfDropPos = null;
        objects.push({
          id:nextId(), type:'bgimage',
          x:wp.x-img.width/2, y:wp.y-img.height/2,
          w:img.width, h:img.height,
          src:src, alpha:alpha,
          layerId:layers[currentLayer].id,
          locked:false
        });
        closeModal('pdfPageModal');
        refreshUI({status:true});
        notify('PDF '+pageNum+'\uD398\uC774\uC9C0 \uC0BD\uC785 \uC644\uB8CC');
      };
      img.src=src;
    });
  });
}

// ── \uD45C \uBD99\uC5EC\uB123\uAE30 (Excel \uBCF5\uC0AC → Ctrl+V or \uD30C\uC77C) ─────
function importXLSX(input){
  const f=input.files[0]; if(!f) return;
  const name=f.name.toLowerCase();
  if(name.endsWith(".xlsx")||name.endsWith(".xls")){
    _loadSheetJS(function(){
      const reader=new FileReader();
      reader.onload=function(e){
        try{
          const wb=XLSX.read(e.target.result,{type:"array"});
          const ws=wb.Sheets[wb.SheetNames[0]];
          const rows=XLSX.utils.sheet_to_json(ws,{header:1,defval:""});
          if(!rows.length){notify("\uB370\uC774\uD130 \uC5C6\uC74C");return;}
          const wp=toWorld(wrap.clientWidth/2,wrap.clientHeight/2);
          pushUndo();
          objects.push({id:nextId(),type:"table",data:rows,
            x:wp.x,y:wp.y,colW:rows[0].map(()=>100),rowH:rows.map(()=>22),
            color:"#aaccff",layerId:layers[currentLayer].id});
          refreshUI({status:true});
          notify("\uD45C \uC0BD\uC785 \uC644\uB8CC ("+rows.length+"\uD589 x "+rows[0].length+"\uC5F4)");
        }catch(err){notify("\uD30C\uC2F1 \uC624\uB958: "+err.message);}
      };
      reader.readAsArrayBuffer(f);
    });
    input.value="";
    return;
  }
  // CSV/TSV
  const reader=new FileReader();
  reader.onload=function(e){
    const text=e.target.result;
    const sep=text.includes("\t")?"\t":",";
    const rows=text.trim().split("\n").map(r=>r.split(sep).map(c=>c.trim().replace(/^"|"$/g,"")));
    if(!rows.length) return;
    const wp=toWorld(wrap.clientWidth/2,wrap.clientHeight/2);
    pushUndo();
    objects.push({id:nextId(),type:"table",data:rows,
      x:wp.x,y:wp.y,colW:rows[0].map(()=>100),rowH:rows.map(()=>22),
      color:"#aaccff",layerId:layers[currentLayer].id});
    refreshUI({status:true});
    notify("\uD45C \uC0BD\uC785 \uC644\uB8CC");
  };
  reader.readAsText(f);
  input.value="";
}

// Excel \uC140 \uBCF5\uC0AC \uD6C4 Ctrl+V \uB85C \uD45C \uC0BD\uC785
// ── Ctrl+V / Cmd+V 이미지/표 붙여넣기 ──────────
document.addEventListener('paste', function(e){
  if(e.target.id==='cmdInput'||e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;

  const cbd = e.clipboardData||window.clipboardData;

  // 1) Excel TSV 먼저 체크 (엑셀 셀 복사 시 이미지+TSV 둘 다 있음 → TSV 우선)
  const txt = cbd.getData('text/plain');
  if(txt && txt.includes('\t')){
    e.preventDefault();
    const rows=txt.trim().split('\n').map(r=>r.split('\t').map(c=>c.trim()));
    if(rows.length && rows[0].length > 1){
      pushUndo();
      const wp=toWorld(wrap.clientWidth/2,wrap.clientHeight/2);
      objects.push({id:nextId(),type:'table',data:rows,
        x:wp.x,y:wp.y,colW:rows[0].map(()=>100),rowH:rows.map(()=>22),
        color:'#aaccff',layerId:layers[currentLayer].id});
      refreshUI({status:true});
      notify('\uD45C \uBD99\uC5EC\uB123\uAE30 ('+rows.length+'\uD589 \xD7 '+rows[0].length+'\uC5F4)');
      return;
    }
  }

  // 2) 이미지 (스크린샷 등 순수 이미지만)
  const items = cbd.items;
  for(let i=0;i<items.length;i++){
    if(items[i].type.startsWith('image/')){
      e.preventDefault();
      const blob=items[i].getAsFile();
      if(!blob) continue;
      const url=URL.createObjectURL(blob);
      const img=new Image();
      img.onload=()=>{
        pushUndo();
        const wp=toWorld(wrap.clientWidth/2,wrap.clientHeight/2);
        objects.push({id:nextId(),type:'bgimage',
          x:wp.x-img.width/2/viewZoom, y:wp.y-img.height/2/viewZoom,
          w:img.width/viewZoom, h:img.height/viewZoom,
          src:url, alpha:1,
          layerId:layers[currentLayer].id, locked:false});
        refreshUI({status:true});
        notify('\uC774\uBBF8\uC9C0 \uBD99\uC5EC\uB123\uAE30 \uC644\uB8CC');
      };
      img.src=url;
      return;
    }
  }

  // 3) CAD 객체 (브라우저 기본 붙여넣기 차단)
  if(clipboard && clipboard.length > 0){ e.preventDefault(); pasteObjects(); }
});

// ── Settings ─────────────────────────────────
function applySettings(){
  gridSize=parseInt(document.getElementById('setGrid').value)||20;
  snapSize=parseInt(document.getElementById('setSnap').value)||10;
  dimFontSize=parseInt(document.getElementById('setDimFont').value)||12;
  arrowSize=parseInt(document.getElementById('setArrow').value)||10;
  closeModal('settingsModal'); render();
}

// ── Modal helpers ────────────────────────────
function openModal(id){
  document.getElementById(id).classList.add('open');
  if(id==='pointerModal') setTimeout(updateCursorPreview,50);
  if(id==='siteConnModal'){
    resetSiteSteps();
    const inp=document.getElementById('siteConnSearch');
    inp.value='';
    document.getElementById('siteConnList').innerHTML='<div class="cad-empty">현장번호 또는 현장명을 검색하세요</div>';
    inp.onkeydown=function(e){ if(e.key==='Enter'){ e.preventDefault(); filterSiteList(); } };
    setTimeout(()=>inp.focus(),50);
  }
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function switchRTab(name,btn){
  document.querySelectorAll('.rTabContent').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.rTabBtn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab_'+name).classList.add('active');
  if(btn) btn.classList.add('active');
  else {
    // find matching button by index
    const tabs=['layers','props','saves'];
    const idx=tabs.indexOf(name);
    const btns=document.querySelectorAll('.rTabBtn');
    if(btns[idx]) btns[idx].classList.add('active');
  }
}

// ── \uD3EC\uC778\uD130 \uC124\uC815 \uD568\uC218 ──────────────────────────
function applyCursorPreset(val){
  const customRow=document.getElementById('cursorCustomRow');
  const pctInput=document.getElementById('cursorSizePct');
  const presets={small:20, medium:50, large:80, full:100};
  if(val==='custom'){
    customRow.style.display='flex';
  } else {
    customRow.style.display='none';
    pctInput.value=presets[val]||50;
  }
  updateCursorPreview();
}

function updateCursorPreview(){
  const c=document.getElementById('cursorPreview');
  if(!c) return;
  const ctx2=c.getContext('2d');
  const W=c.width, H=c.height;
  ctx2.fillStyle='#000'; ctx2.fillRect(0,0,W,H);
  const pct=parseInt(document.getElementById('cursorSizePct').value)||50;
  const lw=parseFloat(document.getElementById('cursorLineWidth').value)||1;
  const col=document.getElementById('cursorColor').value||'#ffffff';
  const sq=document.getElementById('cursorSquare').checked;
  const gap=parseInt(document.getElementById('cursorGap').value)||8;
  const cx=W/2, cy=H/2;
  const halfLen=Math.min(W,H)*pct/100/2;
  ctx2.strokeStyle=col; ctx2.lineWidth=lw; ctx2.setLineDash([]);
  ctx2.beginPath();
  ctx2.moveTo(Math.max(0,cx-halfLen),cy); ctx2.lineTo(cx-gap,cy);
  ctx2.moveTo(cx+gap,cy); ctx2.lineTo(Math.min(W,cx+halfLen),cy);
  ctx2.moveTo(cx,Math.max(0,cy-halfLen)); ctx2.lineTo(cx,cy-gap);
  ctx2.moveTo(cx,cy+gap); ctx2.lineTo(cx,Math.min(H,cy+halfLen));
  ctx2.stroke();
  if(sq) ctx2.strokeRect(cx-4,cy-4,8,8);
}

function saveCursorSettings(){
  const preset=document.getElementById('cursorSizePreset').value;
  const pct = preset==='custom'
      ? parseInt(document.getElementById('cursorSizePct').value)||50
      : {small:20,medium:50,large:80,full:100}[preset]||50;
  cursorCfg.sizePct = pct;
  cursorCfg.lineWidth = parseFloat(document.getElementById('cursorLineWidth').value)||1;
  cursorCfg.color = document.getElementById('cursorColor').value||'#ffffff';
  cursorCfg.showSquare = document.getElementById('cursorSquare').checked;
  cursorCfg.gap = parseInt(document.getElementById('cursorGap').value)||8;
  closeModal('pointerModal');
  renderOverlay();
}

function showPropsPanel(){
  switchRTab('props', null);
  // highlight panel briefly
  const panel=document.getElementById('rightPanel');
  panel.style.transition='box-shadow 0.2s';
  panel.style.boxShadow='0 0 0 2px var(--accent)';
  setTimeout(()=>panel.style.boxShadow='',600);
  notify('\uC18D\uC131 \uD328\uB110 [Alt+1 / Cmd+1]');
}

// ── Notify ───────────────────────────────────
let notifyTimer;
function notify(msg){
  const el=document.getElementById('notify');
  el.textContent=msg; el.style.opacity='1';
  clearTimeout(notifyTimer);
  notifyTimer=setTimeout(()=>el.style.opacity='0',3000);
}

// ── Status bar ───────────────────────────────
function updateStatus(){
  const toolNames={select:'\uC120\uD0DD',line:'\uC120',wall:'\uBCBD\uCCB4',rect:'\uC0AC\uAC01\uD615',circle:'\uC6D0',polyline:'\uD3F4\uB9AC\uC120',offset:'\uC624\uD504\uC14B',trim:'\uD2B8\uB9BC',extend:'\uC5F0\uC7A5',dim:'\uCE58\uC218',text:'\uD14D\uC2A4\uD2B8',annot:'\uC8FC\uC11D',move:'\uC774\uB3D9',copyMove:'\uBCF5\uC0AC\uC774\uB3D9',scale:'\uC2A4\uCF00\uC77C',rotate:'\uD68C\uC804',mirror:'\uB300\uCE6D'};
  document.getElementById('sTool').textContent=toolNames[tool]||tool;
  document.getElementById('sLayer').textContent=layers[currentLayer]?.name||'-';
  document.getElementById('sCount').textContent=objects.length;
}

// ── Keyboard shortcuts ───────────────────────
document.addEventListener('keydown',e=>{
  const code = e.code;
  const k = e.key;
  const inp = document.getElementById('cmdInput');

  // INPUT/TEXTAREA 포커스 중이면 무시 (단, cmdInput 제외)
  if(e.target.tagName==='INPUT' && e.target.id !== 'cmdInput') return;
  if(e.target.tagName==='TEXTAREA') return;

  // Ctrl/Meta 조합 → 직접 실행 (명령창 무관)
  if(e.ctrlKey||e.metaKey){
    if(e.target.id==='cmdInput') return; // cmdInput에서는 기본 동작
    if(code==='KeyZ'){ e.preventDefault(); undo(); return; }
    if(code==='KeyY'){ e.preventDefault(); redo(); return; }
    if(code==='KeyC'){ e.preventDefault(); copySelected(); return; }
    if(code==='KeyV'){
      // CAD clipboard에 객체가 있으면 → preventDefault로 브라우저 붙여넣기 차단 → 미리보기 모드
      if(_pastePreview){ e.preventDefault(); return; }
      if(clipboard && clipboard.length > 0){ e.preventDefault(); pasteObjects(); return; }
      // CAD clipboard 비어있으면 → 브라우저 paste 이벤트 허용 (Excel/이미지)
      return;
    }
    if(code==='KeyA'){ e.preventDefault(); selectAll(); render(); return; }
    if(code==='KeyS'){ e.preventDefault(); saveJSON(); return; }
    if(code==='KeyN'){ e.preventDefault(); newFile(); return; }
    if(code==='KeyO'){ e.preventDefault(); var _di=document.getElementById('dwgFileInput'); if(_di){_di.value='';_di.click();} return; }
    if(code==='BracketRight'){ e.preventDefault(); bringToFront(); return; }
    if(code==='BracketLeft'){ e.preventDefault(); sendToBack(); return; }
    if(code==='KeyP'){ e.preventDefault(); openPrintModal('select'); return; }
    if(code==='KeyF'&&e.shiftKey){ e.preventDefault(); fitAll(); return; }
    if(e.shiftKey&&code==='Digit1'){ e.preventDefault(); showPropsPanel(); return; }
    if(code==='KeyG'&&e.shiftKey){ e.preventDefault(); explodeBlock(); return; }
    if(code==='KeyG'){ e.preventDefault(); groupToBlock(); return; }
    return;
  }

  // F키 → cmdInput에서도 동작
  if(k==='F3'){ e.preventDefault(); toggleSnap(); return; }
  if(k==='F8'){ e.preventDefault(); toggleOrtho(); return; }
  if(k==='F1'){ e.preventDefault(); openModal('shortcutModal'); return; }

  // cmdInput에서 Enter/Space → 명령 실행 (cmdInit에서 처리)
  if(e.target.id==='cmdInput') return;

  // Enter → 폴리선 완료 / 트림 경계 확정
  if(code==='Enter'){
    if(tool==='polyline' && polyPoints.length>=2){ commitPolyline(); }
    if(tool==='trim' && trimStep===0){
      if(!trimEdges.length){
        trimEdges=objects.filter(o=>o.type==='line'||o.type==='wall'||o.type==='polyline'||o.type==='rect'||o.type==='circle').map(o=>o.id);
        notify('전체 객체를 경계로 선택');
      } else {
        notify('경계 '+trimEdges.length+'개 확정 — 잘라낼 부분 클릭');
      }
      trimStep=1;
      selectedIds.clear();
      render();
    }
    return;
  }

  // Escape → 붙여넣기 취소 또는 선택 모드 복귀 + 명령창 클리어
  if(k==='Escape'){
    if(_pastePreview){ _cancelPaste(); return; }
    hideDynLenInput();
    setTool('select');
    selectedIds.clear();
    selBoxStart=null; selBoxEnd=null;
    moveStep=0; moveBasePoint=null;
    cmdClear();
    render();
    return;
  }

  // Delete/Backspace → 삭제
  if(code==='Delete'){ deleteSelected(); return; }

  // F키 → 직접 실행
  if(k==='F3'){ e.preventDefault(); toggleSnap(); return; }
  if(k==='F8'){ e.preventDefault(); toggleOrtho(); renderOverlay(); return; }
  if(k==='F1'){ e.preventDefault(); openModal('shortcutModal'); return; }

  // Mirror Y/N 응답 (즉시 실행, cmdInput 차단)
  if(tool==='mirror' && mirrorStep===2){
    e.preventDefault(); e.stopImmediatePropagation();
    if(k==='y'||k==='Y'){ applyMirror(true); return; }
    if(k==='n'||k==='N'||k==='Enter'||code==='Space'){ applyMirror(false); return; }
    if(k==='Escape'){ mirrorStep=0; mirrorP1=null; mirrorP2=null; setTool('select'); render(); return; }
    return;
  }

  // +/- 줌
  if(code==='Equal'||code==='NumpadAdd'){ zoomIn(); return; }
  if(code==='Minus'||code==='NumpadSubtract'){ zoomOut(); return; }

  // 알파벳/숫자 → 명령창으로 포커스해서 입력
  if(/^[a-zA-Z0-9]$/.test(k)){
    e.preventDefault();
    inp.value += k.toUpperCase();
    inp.focus();
    cmdUpdate(inp.value);
    return;
  }

  // 스페이스 → 트림 확정 / 폴리선 완료 / 명령실행 / 마지막 명령 반복
  if(code==='Space'){
    e.preventDefault();
    // 트림 경계 확정
    if(tool==='trim' && trimStep===0){
      if(!trimEdges.length){
        trimEdges=objects.filter(o=>o.type==='line'||o.type==='wall'||o.type==='polyline'||o.type==='rect'||o.type==='circle').map(o=>o.id);
        notify('전체 객체를 경계로 선택');
      } else {
        notify('경계 '+trimEdges.length+'개 확정 — 잘라낼 부분 클릭');
      }
      trimStep=1;
      selectedIds.clear();
      render();
      return;
    }
    const val = inp.value.trim();
    if(val){
      cmdExecute(val);
    } else {
      if(tool==='polyline' && polyPoints.length>=2){
        commitPolyline();
      } else {
        setTool(lastTool);
        notify('\uBC18\uBCF5: '+lastTool.toUpperCase());
      }
    }
    return;
  }
});

// ── Init ─────────────────────────────────────
function init(){
  resizeCanvas();
  initLayers();
  history.push(JSON.stringify({objects:[], layers}));
  histIdx=0;
  viewX=wrap.clientWidth/2; viewY=wrap.clientHeight/2;

  // DWG 파일 입력 이벤트 바인딩
  var dwgInp=document.getElementById('dwgFileInput');
  if(dwgInp){dwgInp.addEventListener('change',function(){if(this.files[0])importDWG(this.files[0]);this.value='';});}

  // 권한 체크: edit 권한 없으면 뷰어 모드
  if(typeof hasPermission==='function' && !hasPermission('edit')){
    setViewerMode(true);
  }

  // ── \uBA54\uB274 \uD074\uB9AD \uD1A0\uAE00 ──────────────────────────
  document.querySelectorAll('[data-menu]').forEach(btn=>{
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const menuId = this.dataset.menu;
      const drop = document.getElementById(menuId);
      const isOpen = drop.classList.contains('open');
      document.querySelectorAll('.topDropdown').forEach(d=>d.classList.remove('open'));
      if(!isOpen) drop.classList.add('open');
    });
  });
  // \uC678\uBD80 \uD074\uB9AD \uC2DC \uBA54\uB274 \uB2EB\uAE30
  document.addEventListener('click', function(e){
    if(!e.target.closest('.topMenu')){
      document.querySelectorAll('.topDropdown').forEach(d=>d.classList.remove('open'));
    }
  });

  // ── \uC774\uBCA4\uD2B8 \uC704\uC784: data-fn (click), data-change (change/input) ──────────
  const fnMap = {
    newFile, openFile, saveJSON, saveDXF, saveSVG, savePNG,
    openImportBg, undo, redo, copySelected, pasteObjects, deleteSelected,
    bringToFront, sendToBack, bringForward, sendBackward, selectAll,
    fitAll, zoomIn, zoomOut, refreshView, toggleDarkMode, applySiteConn, filterSiteList, applySiteSettings, cmdHistPrev, cmdHistNext, toggleGrid, toggleSnap, toggleOrtho, groupToBlock, explodeBlock,
    toggleLock, addLayer, editLayerStyle, moveLayerUp, moveLayerDown, addVersionComment, applySettings, removeBg, applyBg,
    doPrint, saveCursorSettings, confirmDynLen, cancelDynLen: hideDynLenInput,
    showPropsPanel,
    // int-arg layer functions
    toggleLayerVis: (arg) => toggleLayerVis(parseInt(arg)),
    toggleLayerLock: (arg) => toggleLayerLock(parseInt(arg)),
    deleteLayer: (arg) => deleteLayer(parseInt(arg)),
    pickLayerColor: (arg, el) => pickLayerColor(parseInt(arg), {stopPropagation:()=>{}, target:el}),
    // arg-based
    setTool: (arg) => setTool(arg),
    openModal: (arg) => openModal(arg),
    closeModal: (arg) => closeModal(arg),
    openPrintModal: (arg) => openPrintModal(arg),
    updatePrintPreview: () => updatePrintPreview(),
    doPrintDirect: () => doPrintDirect(),
    onPrintAreaChange: () => onPrintAreaChange(),
    startPrintAreaSelect: () => startPrintAreaSelect(),
    switchRTab: (arg, el) => {
      const btn = el;
      document.querySelectorAll('.rTabContent').forEach(t=>t.classList.remove('active'));
      document.querySelectorAll('.rTabBtn').forEach(b=>b.classList.remove('active'));
      document.getElementById('tab_'+arg).classList.add('active');
      btn.classList.add('active');
    },
    xlsxClick: () => document.getElementById('xlsxFileInput').click(),
  };

  document.addEventListener('click', function(e){
    // \uBA54\uB274 \uC678\uBD80 \uD074\uB9AD \uC2DC \uB2EB\uAE30 (topMenuBtn \uD074\uB9AD\uC740 \uC704 \uD578\uB4E4\uB7EC\uC5D0\uC11C \uCC98\uB9AC)
    if(!e.target.closest('.topMenu')){
      document.querySelectorAll('.topDropdown').forEach(d=>d.classList.remove('open'));
    }
    const el = e.target.closest('[data-fn]');
    if(!el) return;
    // ddItem \uD074\uB9AD \uC2DC \uBA54\uB274 \uB2EB\uAE30
    if(el.classList.contains('ddItem')){
      document.querySelectorAll('.topDropdown').forEach(d=>d.classList.remove('open'));
    }
    const fn = el.dataset.fn;
    const arg = el.dataset.arg;
    if(fnMap[fn]) {
      if(arg) fnMap[fn](arg, el);
      else fnMap[fn](el);
    }
  });

  // change/input \uC704\uC784
  const changeMap = {
    applyProps, applyAnnotProps, applyLength, applyObjProps, setAutoSave,
    setScale: (el) => setScale(el.value),
    setUnit: (el) => setUnit(el.value),
    updateBgAlpha: (el) => updateBgAlpha(el.value),
    importXLSX: (el) => importXLSX(el),
    loadJSON: (el) => loadJSON(el),
    loadBgFile: (el) => loadBgFile(el),
    applyCursorPreset: (el) => applyCursorPreset(el.value),
  };

  document.addEventListener('change', function(e){
    const el = e.target.closest('[data-change]')||e.target;
    const fn = el.dataset.change;
    if(fn && changeMap[fn]) changeMap[fn](el);
  });

  document.addEventListener('input', function(e){
    const el = e.target.closest('[data-change]')||e.target;
    const fn = el.dataset.change;
    if(fn && changeMap[fn]) changeMap[fn](el);
  });

  // dblclick \uC704\uC784 (\uB808\uC774\uC5B4 \uC774\uB984)
  document.addEventListener('dblclick', function(e){
    const el = e.target.closest('[data-dblclick]');
    if(!el) return;
    // renameLayer\uB294 renderLayerList \uB0B4\uBD80\uC5D0\uC11C index\uB97C data-idx\uB85C \uC804\uB2EC
    const idx = parseInt(el.closest('[data-layer-idx]')?.dataset.layerIdx);
    if(!isNaN(idx)) renameLayer(idx, el);
  });

  // dynLenInput \uC774\uBCA4\uD2B8
  const dynInp=document.getElementById('dynLenInput');
  dynInp.addEventListener('keydown', onDynLenKey);
  dynInp.addEventListener('input', function(){ this.value=this.value.replace(/[^0-9.]/g,''); });

  // \uD3EC\uC778\uD130 \uC124\uC815 \uBAA8\uB2EC
  document.getElementById('cursorSizePreset').addEventListener('change', function(){ applyCursorPreset(this.value); });
  document.getElementById('cursorSizePct').addEventListener('input', updateCursorPreview);
  document.getElementById('cursorLineWidth').addEventListener('input', updateCursorPreview);
  document.getElementById('cursorColor').addEventListener('input', updateCursorPreview);
  document.getElementById('cursorSquare').addEventListener('change', updateCursorPreview);
  document.getElementById('cursorGap').addEventListener('input', updateCursorPreview);

  // PDF 페이지 선택 모달 버튼
  document.getElementById('btnPdfInsert').addEventListener('click', _insertPdfPage);
  document.getElementById('btnPdfCancel').addEventListener('click', function(){ closeModal('pdfPageModal'); });
  document.getElementById('btnPdfPrev').addEventListener('click', function(){
    const inp=document.getElementById('pdfPageNum');
    const p=Math.max(1, parseInt(inp.value)-1);
    _pdfCurPage=p; _renderPdfThumb(p);
  });
  document.getElementById('btnPdfNext').addEventListener('click', function(){
    const inp=document.getElementById('pdfPageNum');
    const max=_pdfDoc?_pdfDoc.numPages:1;
    const p=Math.min(max, parseInt(inp.value)+1);
    _pdfCurPage=p; _renderPdfThumb(p);
  });
  document.getElementById('pdfPageNum').addEventListener('change', function(){
    const p=parseInt(this.value)||1; _pdfCurPage=p; _renderPdfThumb(p);
  });

  render();
  setAutoSave();
  updateStatus();

  // 미저장 도면 복구 체크
  const unsaved = localStorage.getItem('cad_unsaved');
  if(unsaved){
    try{
      const data = JSON.parse(unsaved);
      if(data && data.objects && data.objects.length > 0){
        cadAskConfirm(
          '저장되지 않은 도면이 있습니다.\\n복구하시겠습니까?',
          function(){
            objects = data.objects;
            if(data.layers) layers = data.layers;
            refreshUI({layers:true});
            notify('도면 복구 완료');
          },
          function(){
            localStorage.removeItem('cad_unsaved');
          }
        );
      }
    }catch(e){ localStorage.removeItem('cad_unsaved'); }
  }

  notify('SHV SmartCAD 준비 완료');
  cmdInit();
}

// 도면 변경 시 localStorage에 백업
function markUnsaved(){
  try{
    localStorage.setItem('cad_unsaved', JSON.stringify({objects:objects, layers:layers, time:Date.now()}));
  }catch(e){}
}

// 저장 완료 시 미저장 플래그 제거
function clearUnsaved(){
  localStorage.removeItem('cad_unsaved');
}

// 브라우저 닫을 때 경고
window.addEventListener('beforeunload', function(e){
  if(objects.length > 0){
    markUnsaved();
    e.preventDefault();
    e.returnValue = '';
  }
});

window.addEventListener('resize', resizeCanvas);

// 브라우저 기본 드래그앤드롭(새창 열기) 차단 - capture 단계
// ── 드래그앤드롭 전체 차단 후 canvasWrap만 허용 ──
window.addEventListener('dragenter', function(e){ e.preventDefault(); }, true);
window.addEventListener('dragover',  function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
window.addEventListener('dragleave', function(e){ e.preventDefault(); }, true);
window.addEventListener('drop', function(e){
  e.preventDefault();
  e.stopImmediatePropagation();
  const r = wrap.getBoundingClientRect();
  if(e.clientX>=r.left && e.clientX<=r.right && e.clientY>=r.top && e.clientY<=r.bottom){
    wrap.classList.remove('dragover');
    handleFileDrop(e);
  }
}, true);

// ═══════════════════════════════════════════
//  CAD 명령창 (Command Line)
// ═══════════════════════════════════════════

const CMD_LIST = [
  {cmd:'L',   label:'\uC120',         desc:'Line',       fn:()=>setTool('line')},
  {cmd:'W',   label:'\uBCBD\uCCB4',   desc:'Wall',       fn:()=>setTool('wall')},
  {cmd:'R',   label:'\uC0AC\uAC01\uD615', desc:'Rectangle',fn:()=>setTool('rect')},
  {cmd:'C',   label:'\uC6D0',         desc:'Circle',     fn:()=>setTool('circle')},
  {cmd:'P',   label:'\uD3F4\uB9AC\uC120', desc:'Polyline',fn:()=>setTool('polyline')},
  {cmd:'O',   label:'\uC624\uD504\uC14B', desc:'Offset',  fn:()=>setTool('offset')},
  {cmd:'TR',  label:'\uD2B8\uB9BC',   desc:'Trim',    fn:()=>setTool('trim')},
  {cmd:'EX',  label:'\uC5F0\uC7A5',   desc:'Extend',  fn:()=>setTool('extend')},
  {cmd:'D',   label:'\uCE58\uC218',   desc:'Dimension',  fn:()=>setTool('dim')},
  {cmd:'T',   label:'\uD14D\uC2A4\uD2B8', desc:'Text',   fn:()=>setTool('text')},
  {cmd:'M',   label:'\uC774\uB3D9',   desc:'Move',       fn:()=>setTool('move')},
  {cmd:'CO',  label:'\uBCF5\uC0AC\uC774\uB3D9', desc:'Copy+Move', fn:()=>setTool('copyMove')},
  {cmd:'SC',  label:'\uC2A4\uCF00\uC77C', desc:'Scale',  fn:()=>setTool('scale')},
  {cmd:'RO',  label:'\uD68C\uC804',   desc:'Rotate', fn:()=>setTool('rotate')},
  {cmd:'MI',  label:'\uB300\uCE6D',   desc:'Mirror', fn:()=>setTool('mirror')},
  {cmd:'E',   label:'\uC0AD\uC81C',   desc:'Erase',      fn:()=>deleteSelected()},
  {cmd:'U',   label:'\uC2E4\uD589\uCDE8\uC18C', desc:'Undo', fn:()=>undo()},
  {cmd:'REDO',label:'\uB2E4\uC2DC\uC2E4\uD589', desc:'Redo', fn:()=>redo()},
  {cmd:'Z',   label:'\uC804\uCCB4\uB9DE\uCDA4', desc:'Zoom All', fn:()=>fitAll()},
  {cmd:'ZA',  label:'\uC904All\uB9DE\uCDA4', desc:'Zoom All (ZA)', fn:()=>fitAll()},
  {cmd:'ZE',  label:'\uC904Extents', desc:'Zoom Extents', fn:()=>fitAll()},
  {cmd:'RE',  label:'\uB9AC\uD504\uB808\uC2DC', desc:'Refresh', fn:()=>refreshView()},
  {cmd:'ZI',  label:'\uC904\uC778', desc:'Zoom In', fn:()=>zoomIn()},
  {cmd:'ZO',  label:'\uC904\uC544\uC6C3', desc:'Zoom Out', fn:()=>zoomOut()},
  {cmd:'PD',  label:'PDF \uC0BD\uC785', desc:'Insert PDF', fn:()=>{ document.getElementById('bgFileInput').setAttribute('accept','.pdf'); openImportBg(); }},
  {cmd:'IM',  label:'\uC774\ubbf8\uC9C0 \uC0BD\uC785', desc:'Insert Image', fn:()=>{ document.getElementById('bgFileInput').setAttribute('accept','.png,.jpg,.jpeg,.gif,.bmp,.webp'); openImportBg(); }},
  {cmd:'DWG', label:'DWG \uC5F4\uAE30', desc:'Import DWG/DXF', fn:()=>{ const _el=document.getElementById('dwgFileInput'); if(!_el){ const inp=document.createElement('input'); inp.type='file'; inp.id='dwgFileInput'; inp.accept='.dwg,.dxf'; inp.style.display='none'; inp.onchange=function(){if(this.files[0])importDWG(this.files[0]);this.value='';}; document.body.appendChild(inp); inp.click(); } else { _el.value=''; _el.click(); } }},
  {cmd:'ET',  label:'Excel \uD45C', desc:'Insert Excel/CSV', fn:()=>{ const _el=document.getElementById('xlsxFileInput'); _el.value=''; setTimeout(()=>_el.click(),10); }},
  {cmd:'G',   label:'\uACA9\uC790\uD1A0\uAE00', desc:'Grid',  fn:()=>toggleGrid()},
  {cmd:'F3',  label:'\uC2A4\uB0C5',   desc:'Snap',       fn:()=>toggleSnap()},
  {cmd:'F8',  label:'\uC9C1\uAD50',   desc:'Ortho',      fn:()=>toggleOrtho()},
  {cmd:'LA',  label:'\uB808\uC774\uC5B4', desc:'Layers', fn:()=>switchRTab('layers',document.querySelectorAll('.rTabBtn')[0])},
  {cmd:'PR',  label:'\uC18D\uC131',   desc:'Properties', fn:()=>showPropsPanel()},
  {cmd:'SA',  label:'\uC800\uC7A5',   desc:'Save',       fn:()=>saveJSON(false)},
  {cmd:'NEW', label:'\uC0C8\uB3C4\uBA74', desc:'New',    fn:()=>newFile()},
  {cmd:'OPEN',label:'\uC5F4\uAE30',   desc:'Open',       fn:()=>openFile()},
  {cmd:'ESC', label:'\uC120\uD0DD',   desc:'Select/Esc', fn:()=>setTool('select')},
  {cmd:'PLOT',  label:'\uC778\uC1C4',   desc:'Print/Plot',   fn:()=>openPrintModal('view')},
  {cmd:'PRINT', label:'\uC778\uC1C4',   desc:'Print',        fn:()=>openPrintModal('view')},
  {cmd:'DWGS',  label:'DWG \uC800\uC7A5', desc:'Save DWG',   fn:()=>saveDWG()},
];

let _cmdDdIdx = -1;

// 명령 히스토리
let _cmdHist = [];
let _cmdHistIdx = -1;
const CMD_HIST_MAX = 50;

function cmdHistPrev(){
  if(!_cmdHist.length) return;
  if(_cmdHistIdx < _cmdHist.length-1) _cmdHistIdx++;
  const inp = document.getElementById('cmdInput');
  inp.value = _cmdHist[_cmdHistIdx].cmd;
  inp.focus();
}
function cmdHistNext(){
  const inp = document.getElementById('cmdInput');
  if(_cmdHistIdx > 0){
    _cmdHistIdx--;
    inp.value = _cmdHist[_cmdHistIdx].cmd;
  } else {
    _cmdHistIdx = -1;
    inp.value = '';
  }
  inp.focus();
}

function cmdInit(){
  const inp = document.getElementById('cmdInput');
  const dd  = document.getElementById('cmdDropdown');

  // 명령창 입력
  inp.addEventListener('input', function(){
    cmdUpdate(this.value.toUpperCase());
  });

  inp.addEventListener('keydown', function(e){
    // Mirror Y/N 응답 중이면 cmdInput 무시
    if(tool==='mirror' && mirrorStep===2){
      e.preventDefault();
      if(e.key==='y'||e.key==='Y'){ inp.value=''; inp.blur(); applyMirror(true); return; }
      if(e.key==='n'||e.key==='N'||e.key==='Enter'||e.key===' '){ inp.value=''; inp.blur(); applyMirror(false); return; }
      if(e.key==='Escape'){ inp.value=''; inp.blur(); mirrorStep=0; mirrorP1=null; mirrorP2=null; setTool('select'); render(); return; }
      return;
    }
    const items = dd.querySelectorAll('.cmdDdItem');
    const ddOpen = dd.style.display === 'block' && items.length > 0;

    if(e.key === 'ArrowUp'){
      e.preventDefault();
      if(ddOpen){
        _cmdDdIdx = Math.max(_cmdDdIdx-1, 0);
        cmdHighlight(items);
      } else {
        cmdHistPrev();
      }
    } else if(e.key === 'ArrowDown'){
      e.preventDefault();
      if(ddOpen){
        _cmdDdIdx = Math.min(_cmdDdIdx+1, items.length-1);
        cmdHighlight(items);
      } else {
        cmdHistNext();
      }
    } else if(e.key === 'Enter' || e.key === ' '){
      // 스페이스바 = Enter (텍스트 도구일 때는 띄어쓰기)
      if(e.key === ' ' && (tool === 'text' || tool === 'annot')) return;
      e.preventDefault();
      if(_cmdDdIdx >= 0 && items[_cmdDdIdx]){
        items[_cmdDdIdx].click();
      } else {
        cmdExecute(this.value.toUpperCase().trim());
      }
    } else if(e.key === 'Escape'){
      e.preventDefault();
      cmdClear();
      setTool('select');
      inp.blur();
    } else if(e.key === 'Tab'){
      e.preventDefault();
      if(items.length > 0){
        _cmdDdIdx = (_cmdDdIdx+1) % items.length;
        cmdHighlight(items);
        const matched = dd.querySelectorAll('.cmdDdItem')[_cmdDdIdx];
        if(matched) inp.value = matched.dataset.cmd;
      }
    }
  });

  // 포커스 아웃 시 드롭다운 닫기 (딜레이)
  inp.addEventListener('blur', function(){
    setTimeout(()=>{ dd.style.display='none'; }, 200);
  });
  inp.addEventListener('focus', function(){
    if(this.value) cmdUpdate(this.value.toUpperCase());
  });
}

function cmdUpdate(val){
  const dd = document.getElementById('cmdDropdown');
  _cmdDdIdx = -1;
  if(!val){ dd.style.display='none'; return; }

  const matched = CMD_LIST.filter(c =>
      c.cmd.startsWith(val) || c.label.includes(val) || c.desc.toUpperCase().includes(val)
  );

  if(!matched.length){ dd.style.display='none'; return; }

  dd.innerHTML = matched.map((c,i) => {
    // 매칭 부분 하이라이트
    const keyHl = c.cmd.startsWith(val)
        ? '<span class="cmdDdMatch">'+c.cmd.slice(0,val.length)+'</span>'+c.cmd.slice(val.length)
        : c.cmd;
    return `<div class="cmdDdItem" data-cmd="${c.cmd}" data-idx="${i}">
      <span class="cmdDdKey">${keyHl}</span>
      <span class="cmdDdLabel">${c.label}</span>
      <span class="cmdDdDesc">${c.desc}</span>
    </div>`;
  }).join('');

  dd.style.display = 'block';

  // 드롭다운 항목 클릭
  dd.querySelectorAll('.cmdDdItem').forEach(item => {
    item.addEventListener('click', function(){
      const cmd = this.dataset.cmd;
      cmdExecute(cmd);
    });
  });
}

function cmdHighlight(items){
  items.forEach((el,i) => el.classList.toggle('active', i===_cmdDdIdx));
  if(items[_cmdDdIdx]) items[_cmdDdIdx].scrollIntoView({block:'nearest'});
}

function cmdExecute(val){
  const cmd = CMD_LIST.find(c => c.cmd === val.trim());
  const inp = document.getElementById('cmdInput');
  const hist = document.getElementById('cmdHistory');

  // 히스토리 저장 (시간 + 명령 + 라벨)
  if(val && val.trim()){
    const now = new Date();
    const ts = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+' '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0')+':'+String(now.getSeconds()).padStart(2,'0');
    const matched = CMD_LIST.find(c => c.cmd === val.trim());
    const label = matched ? matched.label : '';
    _cmdHist.unshift({cmd:val.trim(), label:label, time:ts});
    if(_cmdHist.length > CMD_HIST_MAX) _cmdHist.pop();
    _cmdHistIdx = -1;
    cmdHistRender();
  }

  if(cmd){
    cmd.fn();
    hist.textContent = '\u25B6 ' + cmd.cmd + ' : ' + cmd.label;
    cmdClear();
  } else if(val){
    // 숫자면 scale/len 입력으로 처리
    const num = parseFloat(val);
    if(!isNaN(num) && num > 0){
      const lenInp = document.getElementById('dynLenInput');
      if(lenInp && document.getElementById('dynLenWrap').style.display !== 'none'){
        lenInp.value = val;
        lenInp.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));
      }
    }
    cmdClear();
  }
  inp.blur();
}

function cmdClear(){
  const inp = document.getElementById('cmdInput');
  const dd  = document.getElementById('cmdDropdown');
  inp.value = '';
  dd.style.display = 'none';
  _cmdDdIdx = -1;
}

function cmdHistToggle(){
  const panel = document.getElementById('cmdHistPanel');
  const btn = document.getElementById('cmdHistBtn');
  if(panel.style.display === 'none'){
    panel.style.display = 'block';
    btn.textContent = '▼ 기록';
    cmdHistRender();
  } else {
    panel.style.display = 'none';
    btn.textContent = '▲ 기록';
  }
}

function cmdHistRender(){
  const panel = document.getElementById('cmdHistPanel');
  if(!panel || panel.style.display === 'none') return;
  if(!_cmdHist.length){
    panel.innerHTML = '<div class="cad-empty">기록 없음</div>';
    return;
  }
  panel.innerHTML = _cmdHist.map((h, i) =>
    `<div class="cmdHistItem" data-idx="${i}" style="padding:3px 10px;color:#7099bb;cursor:pointer;border-bottom:1px solid #0d1a2a;display:flex;gap:10px;${i===0?'color:#00aaff;font-weight:600;':''}">
      <span style="color:#3a5570;font-size:10px;min-width:120px;">${h.time}</span>
      <span style="min-width:30px;font-weight:600;">${h.cmd}</span>
      <span style="color:#88aacc;">${h.label}</span>
    </div>`
  ).join('');
  panel.querySelectorAll('.cmdHistItem').forEach(el => {
    el.addEventListener('click', function(){
      const idx = parseInt(this.dataset.idx);
      const h = _cmdHist[idx];
      if(h) cmdExecute(h.cmd);
    });
    el.addEventListener('mouseenter', function(){ this.style.background='#0d1a30'; });
    el.addEventListener('mouseleave', function(){ this.style.background=''; });
  });
}

// 히스토리 버튼 이벤트
document.getElementById('cmdHistBtn').addEventListener('click', cmdHistToggle);
init();
