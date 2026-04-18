<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth = new AuthService();
$_ctx = $auth->currentContext();
if ($_ctx === []) { http_response_code(401); echo '<div class="empty-state"><div class="empty-icon"><i class="fa fa-lock"></i></div><div class="empty-message">로그인이 필요합니다.</div></div>'; exit; }
$_userPk    = (int)($_ctx['user_pk']    ?? 0);
$_roleLevel = (int)($_ctx['role_level']  ?? 0);
$_tenantId  = (int)($_ctx['tenant_id']   ?? 0);
$_cssV = @filemtime(__DIR__ . '/../../../css/v2/pages/facility.css') ?: '1';
?>
<link rel="stylesheet" href="css/v2/pages/facility.css?v=<?= $_cssV ?>">

<section data-page="cctv_viewer" data-title="CCTV Viewer">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1"><i class="fa fa-video-camera text-accent"></i> CCTV Viewer</h2>
        <p class="text-xs text-3 mt-1 m-0">로컬 뷰어를 통해 NVR에 직접 연결하여 실시간 CCTV를 시청합니다. 서버 부하 없이 PC에서 직접 스트리밍합니다.</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="ov-os-badge" id="cvOsBadge"><i class="fa fa-desktop"></i> 감지 중...</span>
        <button class="btn btn-outline btn-sm" id="cvBtnCheck"><i class="fa fa-refresh"></i> 상태 확인</button>
    </div>
</div>

<!-- ── 뷰어 상태 배너 ── -->
<div id="cvStatusBanner" class="ov-status ov-status-checking">
    <div class="ov-status-icon"><i class="fa fa-spinner fa-spin"></i></div>
    <div>
        <div class="ov-status-title">뷰어 상태 확인 중...</div>
        <div class="ov-status-desc">localhost:1984 연결을 시도하고 있습니다.</div>
    </div>
</div>

<!-- ── 설치 안내 ── -->
<div id="cvInstallBox" class="ov-install-box hidden">
    <i class="fa fa-download ov-install-icon"></i>
    <h3>SHV CCTV Viewer 설치가 필요합니다</h3>
    <p>CCTV 실시간 시청을 위해 뷰어 프로그램을 설치해주세요.<br>설치 후 자동으로 백그라운드에서 실행됩니다.</p>
    <div class="ov-install-btns">
        <a id="cvDownloadBtn" href="#" class="btn btn-primary btn-lg"><i class="fa fa-download"></i> <span id="cvDownloadLabel">다운로드</span></a>
        <button class="btn btn-outline btn-lg" id="cvBtnRecheck"><i class="fa fa-refresh"></i> 설치 완료 후 다시 확인</button>
    </div>
    <div class="ov-install-note">
        <p>📌 PC 재부팅 후에도 백그라운드에서 자동 실행됩니다.</p>
        <p>📌 영상을 보지 않을 때는 리소스를 사용하지 않습니다.</p>
    </div>
</div>

<!-- ── CCTV 뷰어 (뷰어 연결 후) ── -->
<div id="cvViewerArea" class="hidden">
    <!-- NVR 선택 -->
    <div class="card py-3 px-4 mb-3">
        <div class="flex items-center gap-3 flex-wrap">
            <label class="text-sm font-semibold text-2">NVR 선택</label>
            <select id="cvNvrSelect" class="form-select form-select-sm mw-200">
                <option value="">NVR 선택...</option>
            </select>
            <div class="flex items-center gap-2 ml-auto">
                <button class="btn btn-success btn-sm" id="cvBtnConnectAll"><i class="fa fa-play"></i> 전체 연결</button>
                <button class="btn btn-outline btn-sm" id="cvBtnDisconnectAll"><i class="fa fa-stop"></i> 전체 중지</button>
            </div>
        </div>
    </div>

    <!-- 그리드 툴바 -->
    <div class="cv-toolbar mb-3">
        <button class="btn btn-sm cv-grid-btn active" data-grid="4">4분할</button>
        <button class="btn btn-sm cv-grid-btn" data-grid="6">6분할</button>
        <button class="btn btn-sm cv-grid-btn" data-grid="9">9분할</button>
        <button class="btn btn-sm cv-grid-btn" data-grid="16">16분할</button>
    </div>

    <!-- 카메라 그리드 -->
    <div id="cvGrid" class="cv-grid cv-grid-4"></div>
</div>

</section>

<script>
/* ════════════════════════════════════════
   SHVQ V2 — CCTV Viewer
   로컬 뷰어(localhost:1984) NVR 직접 연결
   ════════════════════════════════════════ */
(function(){
'use strict';

var VIEWER_URL = 'http://localhost:1984';
var API_URL    = 'dist_process/saas/Onvif.php';
var _viewerReady = false;
var _gridSize = 4;
var _nvrList  = [];
var _peers    = [];
var _currentNvr = null;
var _channelOrder = [];
var _singleIdx = -1;

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── 제조사별 RTSP URL ── */
function buildRtspUrl(nvr, ch, quality) {
    quality = quality || 'sub';
    var user = nvr.login_user || nvr.user || 'admin';
    var pass = nvr.login_pass || nvr.pass || '';
    var ip = nvr.ip || '';
    var port = nvr.rtsp_port || nvr.rtspPort || '554';
    var auth = pass ? (user+':'+pass+'@') : (user+'@');
    var mfr = nvr.manufacturer || '';
    if (mfr === 'vigi')      return 'rtsp://'+auth+ip+':'+port+'/ch'+ch+'/'+quality+'/av_stream';
    if (mfr === 'hikvision') return 'rtsp://'+auth+ip+':'+port+'/Streaming/Channels/'+(ch*100+(quality==='main'?1:2));
    if (mfr === 'dahua')     return 'rtsp://'+auth+ip+':'+port+'/cam/realmonitor?channel='+ch+'&subtype='+(quality==='main'?0:1);
    return 'rtsp://'+auth+ip+':'+port+'/ch'+ch+'/'+quality+'/av_stream';
}

/* ── OS 감지 ── */
function initOS() {
    var ua = navigator.userAgent;
    var os = ua.indexOf('Win')!==-1?'windows':ua.indexOf('Mac')!==-1?'mac':'unknown';
    var badge = document.getElementById('cvOsBadge');
    var dlLabel = document.getElementById('cvDownloadLabel');
    var dlBtn = document.getElementById('cvDownloadBtn');
    if (os === 'windows') {
        badge.innerHTML = '<i class="fa fa-windows"></i> Windows';
        dlLabel.textContent = 'Windows용 다운로드 (SHV_Viewer_Setup.exe)';
        dlBtn.href = 'downloads/SHV_Viewer_Setup.exe';
    } else if (os === 'mac') {
        badge.innerHTML = '<i class="fa fa-apple"></i> macOS';
        dlLabel.textContent = 'macOS용 다운로드 (SHV_Viewer.pkg)';
        dlBtn.href = 'downloads/SHV_Viewer.pkg';
    }
}

/* ── 뷰어 상태 확인 ── */
function checkViewer() {
    var banner = document.getElementById('cvStatusBanner');
    banner.className = 'ov-status ov-status-checking';
    banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-spinner fa-spin"></i></div><div><div class="ov-status-title">뷰어 상태 확인 중...</div><div class="ov-status-desc">localhost:1984 연결을 시도하고 있습니다.</div></div>';
    var ws, done = false;
    var timer = setTimeout(function(){ if(done)return; done=true; try{ws.close();}catch(e){} showNotInstalled(); }, 2000);
    try {
        ws = new WebSocket('ws://localhost:1984/api/ws');
        ws.onopen = function(){ if(done)return; done=true; clearTimeout(timer); ws.close();
            _viewerReady = true;
            banner.className = 'ov-status ov-status-ready';
            banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-check-circle"></i></div><div><div class="ov-status-title">뷰어 연결 완료</div><div class="ov-status-desc">NVR을 선택하여 CCTV를 시청하세요.</div></div>';
            document.getElementById('cvInstallBox').classList.add('hidden');
            document.getElementById('cvViewerArea').classList.remove('hidden');
            loadNvrList();
        };
        ws.onerror = function(){ if(done)return; done=true; clearTimeout(timer); showNotInstalled(); };
        ws.onclose = function(){ if(!done){ done=true; clearTimeout(timer); showNotInstalled(); } };
    } catch(e){ if(!done){ done=true; clearTimeout(timer); showNotInstalled(); } }

    function showNotInstalled() {
        _viewerReady = false;
        banner.className = 'ov-status ov-status-install';
        banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-download"></i></div><div><div class="ov-status-title">뷰어가 설치되지 않았거나 실행 중이 아닙니다</div><div class="ov-status-desc">SHV CCTV Viewer를 설치해주세요.</div></div>';
        document.getElementById('cvInstallBox').classList.remove('hidden');
        document.getElementById('cvViewerArea').classList.add('hidden');
    }
}

/* ── NVR 목록 로드 (ONVIF 카메라 DB → IP 기준 그룹화) ── */
function loadNvrList() {
    SHV.api.get(API_URL, {todo:'camera_list'}).then(function(res) {
        if (!res.ok) return;
        var cams = (res.data && res.data.items) || [];
        cams.sort(function(a,b){ return (parseInt((a.channel||'').replace(/\D/g,''))||999) - (parseInt((b.channel||'').replace(/\D/g,''))||999); });
        var nvrMap = {};
        cams.forEach(function(c) {
            var key = c.ip || ''; if (!key) return;
            if (!nvrMap[key]) {
                nvrMap[key] = {
                    ip: c.ip, name: (c.name||'').replace(/\s*CH\d+/i,'').trim() || c.ip,
                    login_user: c.user||'', login_pass: c.pass||'',
                    manufacturer: c.manufacturer||'', rtsp_port: c.rtspPort||'554',
                    channel_count: 0, channels: []
                };
            }
            nvrMap[key].channel_count++;
            nvrMap[key].channels.push(c);
        });
        _nvrList = Object.keys(nvrMap).map(function(k){ return nvrMap[k]; });
        var sel = document.getElementById('cvNvrSelect');
        sel.innerHTML = '<option value="">NVR 선택...</option>';
        _nvrList.forEach(function(n,i){ sel.innerHTML += '<option value="'+i+'">'+esc(n.name)+' ('+esc(n.ip)+', '+n.channel_count+'채널)</option>'; });
        if (_nvrList.length === 1) { sel.value = '0'; loadChannels(); }
    });
}

/* ── 채널 로드 + 그리드 렌더 ── */
function loadChannels() {
    var idx = document.getElementById('cvNvrSelect').value;
    if (idx === '') return;
    _currentNvr = _nvrList[parseInt(idx)];
    if (!_currentNvr) return;
    disconnectAll();
    _channelOrder = [];
    _singleIdx = -1;
    renderGrid();
    connectAll();
}

function renderGrid() {
    var grid = document.getElementById('cvGrid');
    if (!_currentNvr) { grid.innerHTML = ''; return; }
    var count = Math.min(_gridSize, parseInt(_currentNvr.channel_count) || 8);
    if (!_channelOrder.length || _channelOrder.length !== count) {
        _channelOrder = [];
        for (var k = 0; k < count; k++) _channelOrder.push(k);
    }
    var html = '';
    for (var i = 0; i < _gridSize; i++) {
        var chIdx = _channelOrder[i];
        if (i < count && chIdx !== undefined) {
            html += '<div class="cv-cell" data-idx="'+i+'" data-ch="'+chIdx+'">'
                + '<video id="cv-video-'+i+'" autoplay muted playsinline></video>'
                + '<div class="cv-label">CH'+(chIdx+1)+'</div></div>';
        } else {
            html += '<div class="cv-cell"><div class="cv-no-stream">빈 채널</div></div>';
        }
    }
    grid.innerHTML = html;
    /* 더블클릭 → 1분할 확대/복원 */
    grid.querySelectorAll('.cv-cell[data-idx]').forEach(function(cell){
        cell.addEventListener('dblclick', function(){ dblClickCell(parseInt(this.dataset.idx)); });
    });
}

/* ── WebRTC 연결 ── */
function doConnect(video, chNum, streamId, quality) {
    var rtspUrl = buildRtspUrl(_currentNvr, chNum, quality);
    fetch(VIEWER_URL+'/api/streams?src='+encodeURIComponent(rtspUrl)+'&name='+encodeURIComponent(streamId), {method:'PUT'})
        .then(function(){ startWebRTC(video, streamId); })
        .catch(function(){ startWebRTC(video, streamId); });
}

function startWebRTC(video, streamId) {
    var pc = new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
    _peers.push(pc);
    pc.addTransceiver('video', {direction:'recvonly'});
    pc.ontrack = function(e){ if(e.streams&&e.streams[0]){ video.srcObject=e.streams[0]; video.play().catch(function(){}); } };
    pc.createOffer().then(function(o){ return pc.setLocalDescription(o); })
    .then(function(){ return fetch(VIEWER_URL+'/api/webrtc?src='+encodeURIComponent(streamId),{method:'POST',headers:{'Content-Type':'application/sdp'},body:pc.localDescription.sdp}); })
    .then(function(r){ return r.text(); })
    .then(function(sdp){ if(sdp&&sdp.indexOf('v=0')>=0) pc.setRemoteDescription(new RTCSessionDescription({type:'answer',sdp:sdp})); })
    .catch(function(){});
}

function connectAll() {
    if (!_viewerReady) { SHV.toast.warn('뷰어가 실행 중이 아닙니다'); return; }
    if (!_currentNvr) { SHV.toast.warn('NVR을 선택하세요'); return; }
    var count = Math.min(_gridSize, parseInt(_currentNvr.channel_count)||8);
    for (var i = 0; i < count; i++) {
        (function(gi){
            var chIdx = _channelOrder[gi]; if (chIdx === undefined) return;
            var streamId = 'cv_'+_currentNvr.ip.replace(/\./g,'_')+'_ch'+(chIdx+1);
            setTimeout(function(){ doConnect(document.getElementById('cv-video-'+gi), chIdx+1, streamId); }, gi*300);
        })(i);
    }
}

function disconnectAll() {
    _peers.forEach(function(pc){ try{pc.close();}catch(e){} });
    _peers = [];
    document.querySelectorAll('#cvGrid video').forEach(function(v){ v.pause(); v.srcObject=null; v.src=''; });
}

/* ── 그리드 크기 변경 ── */
function setGrid(n) {
    _gridSize = n;
    document.getElementById('cvGrid').className = 'cv-grid cv-grid-'+n;
    document.querySelectorAll('.cv-grid-btn').forEach(function(b){ b.classList.toggle('active', parseInt(b.dataset.grid)===n); });
    _singleIdx = -1;
    disconnectAll();
    renderGrid();
    connectAll();
}

/* ── 더블클릭 1분할 ── */
function dblClickCell(gridIdx) {
    if (_singleIdx >= 0) { _singleIdx=-1; setGrid(_gridSize); return; }
    _singleIdx = gridIdx;
    var chIdx = _channelOrder[gridIdx]; if (chIdx === undefined) return;
    var grid = document.getElementById('cvGrid');
    grid.className = 'cv-grid cv-grid-1';
    grid.innerHTML = '<div class="cv-cell" data-idx="'+gridIdx+'">'
        + '<video id="cv-video-single" autoplay muted playsinline></video>'
        + '<div class="cv-label">CH'+(chIdx+1)+' <span class="text-3 text-xs">(더블클릭: 돌아가기)</span></div></div>';
    grid.querySelector('.cv-cell').addEventListener('dblclick', function(){ dblClickCell(gridIdx); });
    disconnectAll();
    var streamId = 'cv_'+_currentNvr.ip.replace(/\./g,'_')+'_ch'+(chIdx+1)+'_main';
    var rtspUrl = buildRtspUrl(_currentNvr, chIdx+1, 'main');
    fetch(VIEWER_URL+'/api/streams?src='+encodeURIComponent(rtspUrl)+'&name='+encodeURIComponent(streamId),{method:'PUT'})
        .then(function(){ startWebRTC(document.getElementById('cv-video-single'), streamId); })
        .catch(function(){ startWebRTC(document.getElementById('cv-video-single'), streamId); });
}

/* ── 이벤트 바인딩 ── */
function bindEvents() {
    document.getElementById('cvBtnCheck').addEventListener('click', checkViewer);
    var recheck = document.getElementById('cvBtnRecheck');
    if (recheck) recheck.addEventListener('click', checkViewer);
    document.getElementById('cvNvrSelect').addEventListener('change', loadChannels);
    document.getElementById('cvBtnConnectAll').addEventListener('click', connectAll);
    document.getElementById('cvBtnDisconnectAll').addEventListener('click', disconnectAll);
    document.querySelectorAll('.cv-grid-btn').forEach(function(btn){
        btn.addEventListener('click', function(){ setGrid(parseInt(this.dataset.grid)); });
    });
}

/* ── SHV.pages 라이프사이클 ── */
SHV.pages.cctv_viewer = {
    init: function() { initOS(); bindEvents(); checkViewer(); },
    destroy: function() { disconnectAll(); _nvrList=[]; _currentNvr=null; _channelOrder=[]; }
};

})();
</script>
