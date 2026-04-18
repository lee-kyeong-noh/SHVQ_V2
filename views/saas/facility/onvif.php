<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';

$auth = new AuthService();
$_ctx = $auth->currentContext();
if ($_ctx === []) { http_response_code(401); echo '<div class="empty-state"><div class="empty-icon"><i class="fa fa-lock"></i></div><div class="empty-message">로그인이 필요합니다.</div></div>'; exit; }
$_userPk    = (int)($_ctx['user_pk']    ?? 0);
$_loginId   = (string)($_ctx['login_id'] ?? '');
$_roleLevel = (int)($_ctx['role_level']  ?? 0);
$_tenantId  = (int)($_ctx['tenant_id']   ?? 0);
$_cssV = @filemtime(__DIR__ . '/../../../css/v2/pages/facility.css') ?: '1';
?>
<link rel="stylesheet" href="css/v2/pages/facility.css?v=<?= $_cssV ?>">

<section data-page="onvif" data-title="ONVIF 카메라">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1"><i class="fa fa-video-camera text-accent"></i> ONVIF 카메라 관리</h2>
        <p class="text-xs text-3 mt-1 m-0">로컬 뷰어를 통해 카메라에 직접 연결합니다. 서버 부하 없이 PC에서 직접 스트리밍합니다.</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <span class="ov-os-badge" id="ovOsBadge"><i class="fa fa-desktop"></i> 감지 중...</span>
        <button class="btn btn-outline btn-sm" id="ovBtnCheckViewer"><i class="fa fa-refresh"></i> 상태 확인</button>
    </div>
</div>

<!-- ── 뷰어 상태 배너 ── -->
<div id="ovStatusBanner" class="ov-status ov-status-checking">
    <div class="ov-status-icon"><i class="fa fa-spinner fa-spin"></i></div>
    <div>
        <div class="ov-status-title">뷰어 상태 확인 중...</div>
        <div class="ov-status-desc">localhost:1984 연결을 시도하고 있습니다.</div>
    </div>
</div>

<!-- ── 설치 안내 (뷰어 미설치) ── -->
<div id="ovInstallBox" class="ov-install-box hidden">
    <i class="fa fa-download ov-install-icon"></i>
    <h3>SHV CCTV Viewer 설치가 필요합니다</h3>
    <p>CCTV 실시간 시청을 위해 뷰어 프로그램을 설치해주세요.<br>설치 후 자동으로 백그라운드에서 실행되며, 이후 접속 시 바로 영상을 볼 수 있습니다.</p>
    <div class="ov-install-btns">
        <a id="ovDownloadBtn" href="#" class="btn btn-primary btn-lg"><i class="fa fa-download"></i> <span id="ovDownloadLabel">다운로드</span></a>
        <button class="btn btn-outline btn-lg" id="ovBtnRecheck"><i class="fa fa-refresh"></i> 설치 완료 후 다시 확인</button>
    </div>
    <div class="ov-install-note">
        <p>📌 설치 후 프로그램 목록에 <strong>SHV CCTV Viewer</strong>가 추가됩니다.</p>
        <p>📌 PC 재부팅 후에도 백그라운드에서 자동 실행됩니다.</p>
        <p>📌 영상을 보지 않을 때는 리소스를 사용하지 않습니다.</p>
    </div>
</div>

<!-- ── 카메라 관리 영역 (뷰어 연결 후 표시) ── -->
<div id="ovMainArea" class="hidden">

    <!-- 액션 버튼 -->
    <div class="card flex-shrink-0 py-3 px-4 mb-3">
        <div class="flex items-center gap-2 flex-wrap">
            <div class="shv-search flex-1 max-w-400">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="ovSearchInput" class="form-input" placeholder="카메라명 / IP 검색...">
            </div>
            <span class="ov-count" id="ovCamCount"></span>
            <div class="flex items-center gap-2 ml-auto">
                <button class="btn btn-outline btn-sm" id="ovBtnMultiView"><i class="fa fa-th"></i> 멀티뷰</button>
                <button class="btn btn-outline btn-sm" id="ovBtnDiscover"><i class="fa fa-wifi"></i> 자동 검색</button>
                <?php if ($_roleLevel <= 2): ?>
                <button class="btn btn-glass-primary btn-sm" id="ovBtnAddCam"><i class="fa fa-plus"></i> 카메라 추가</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 탭 -->
    <div class="ov-tabs">
        <button class="ov-tab-btn active" data-tab="grid"><i class="fa fa-th-large"></i> 카드 뷰</button>
        <button class="ov-tab-btn" data-tab="list"><i class="fa fa-list"></i> 목록 뷰</button>
    </div>

    <!-- 카드 뷰 -->
    <div class="ov-tab-pane active" id="ovTabGrid">
        <div class="ov-grid" id="ovGrid"></div>
        <div class="empty-state hidden" id="ovEmpty">
            <div class="empty-icon"><i class="fa fa-video-camera"></i></div>
            <div class="empty-message">등록된 카메라가 없습니다</div>
            <p class="text-xs text-3 mt-1">카메라 추가 또는 자동 검색으로 ONVIF 장치를 등록하세요</p>
        </div>
    </div>

    <!-- 목록 뷰 -->
    <div class="ov-tab-pane" id="ovTabList">
        <div class="card">
            <div class="tbl-scroll">
                <table class="tbl tbl-sticky-header" id="ovListTable">
                    <colgroup>
                        <col><col class="col-130"><col class="col-60"><col class="col-70">
                        <col class="col-80"><col class="col-80"><col class="col-60"><col class="col-100">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>카메라명</th>
                            <th>IP 주소</th>
                            <th>포트</th>
                            <th>채널</th>
                            <th>연결방식</th>
                            <th>상태</th>
                            <th>PTZ</th>
                            <th>작업</th>
                        </tr>
                    </thead>
                    <tbody id="ovTbody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div><!-- /#ovMainArea -->

<!-- ── 스트림 뷰어 (커스텀 오버레이) ── -->
<div class="ov-viewer-overlay" id="ovViewerOverlay">
    <div class="ov-viewer-header">
        <div>
            <div class="ov-viewer-title" id="ovViewerTitle"></div>
            <div class="ov-viewer-subtitle" id="ovViewerSubtitle"></div>
        </div>
        <div class="flex items-center gap-2">
            <button class="btn btn-outline btn-sm" id="ovSnapBtn" disabled title="스냅샷"><i class="fa fa-camera"></i></button>
            <button class="ov-viewer-close" id="ovViewerClose">&times;</button>
        </div>
    </div>
    <div class="ov-viewer-body">
        <!-- 영상 -->
        <div class="ov-stream-box" id="ovStreamBox">
            <div class="ov-stream-loading" id="ovStreamLoading">
                <div class="ov-loading-logo"><span>SH</span> Vision Portal</div>
                <div class="ov-loading-spinner"></div>
                <div class="ov-loading-sub">CONNECTING TO CAMERA</div>
            </div>
            <video id="ovStreamVideo" autoplay muted playsinline></video>
            <div class="ov-stream-placeholder" id="ovStreamPlaceholder">
                <div class="ov-placeholder-brand"><span>SH</span> Vision Portal</div>
                <i class="fa fa-exclamation-triangle" style="font-size:32px;color:var(--warn);"></i>
                <div class="ov-placeholder-msg" id="ovPlaceholderMsg">카메라 연결 실패</div>
            </div>
        </div>

        <!-- 스트림 컨트롤 -->
        <div class="ov-stream-ctrl">
            <span class="text-xs font-semibold" id="ovQualityLabel">화질</span>
            <div class="ov-quality-toggle">
                <button id="ovQMain" class="active" data-q="main">메인 (고화질)</button>
                <button id="ovQSub" data-q="sub">서브 (빠름)</button>
            </div>
            <div class="flex items-center gap-2 ml-auto">
                <button class="btn ov-btn-purple btn-sm hidden" id="ovPtzToggleBtn"><i class="fa fa-crosshairs"></i> PTZ</button>
                <button class="btn btn-outline btn-sm" id="ovRefreshStream"><i class="fa fa-refresh"></i> 새로고침</button>
                <button class="btn btn-success btn-sm" id="ovPingCurrent"><i class="fa fa-plug"></i> 연결 테스트</button>
            </div>
        </div>

        <!-- PTZ 컨트롤 -->
        <div class="ov-ptz-wrap hidden" id="ovPtzWrap">
            <div class="ov-ptz-title"><i class="fa fa-crosshairs"></i> PTZ 제어</div>
            <div class="flex items-center gap-0">
                <div class="ov-ptz-grid">
                    <button class="ov-ptz-btn" data-dir="upleft">↖</button>
                    <button class="ov-ptz-btn" data-dir="up">↑</button>
                    <button class="ov-ptz-btn" data-dir="upright">↗</button>
                    <button class="ov-ptz-btn" data-dir="left">←</button>
                    <button class="ov-ptz-btn center">■</button>
                    <button class="ov-ptz-btn" data-dir="right">→</button>
                    <button class="ov-ptz-btn" data-dir="downleft">↙</button>
                    <button class="ov-ptz-btn" data-dir="down">↓</button>
                    <button class="ov-ptz-btn" data-dir="downright">↘</button>
                </div>
                <div class="ov-ptz-zoom">
                    <button data-dir="zoomin" title="줌 인">+</button>
                    <button data-dir="zoomout" title="줌 아웃">-</button>
                </div>
                <div class="ml-3 flex-1">
                    <div class="ov-ptz-preset-label">프리셋</div>
                    <div class="ov-ptz-presets" id="ovPresets">
                        <button class="btn btn-outline btn-sm" data-preset="1">P1</button>
                        <button class="btn btn-outline btn-sm" data-preset="2">P2</button>
                        <button class="btn btn-outline btn-sm" data-preset="3">P3</button>
                        <button class="btn btn-outline btn-sm" data-preset="4">P4</button>
                    </div>
                    <div class="mt-2">
                        <button class="btn btn-outline btn-sm" id="ovSavePreset"><i class="fa fa-bookmark"></i> 현재 위치 저장</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 녹화 컨트롤 -->
        <div class="ov-rec-wrap" id="ovRecWrap">
            <div class="ov-rec-title"><i class="fa fa-circle text-danger"></i> 녹화</div>
            <div class="ov-rec-ctrl">
                <button class="btn btn-danger btn-sm" id="ovRecStartBtn"><i class="fa fa-circle"></i> 녹화 시작</button>
                <button class="btn btn-outline btn-sm hidden" id="ovRecStopBtn"><i class="fa fa-stop"></i> 녹화 중지</button>
                <div class="ov-rec-status ov-rec-status-idle" id="ovRecStatus"><span>대기</span></div>
                <span class="ov-rec-timer" id="ovRecTimer"></span>
                <div class="ml-auto flex items-center gap-2">
                    <label class="text-xs font-semibold text-3">시간</label>
                    <select class="form-select form-select-sm" id="ovRecDuration">
                        <option value="60">1분</option>
                        <option value="180" selected>3분</option>
                        <option value="300">5분</option>
                        <option value="600">10분</option>
                        <option value="1800">30분</option>
                        <option value="3600">1시간</option>
                        <option value="0">수동 중지</option>
                    </select>
                </div>
            </div>
            <div class="ov-rec-files" id="ovRecFiles"></div>
        </div>

        <!-- 카메라 정보 -->
        <div class="ov-info-grid" id="ovInfoGrid"></div>
    </div>
</div><!-- /#ovViewerOverlay -->

<!-- ── 멀티뷰 (커스텀 오버레이) ── -->
<div class="ov-mv-overlay" id="ovMvOverlay">
    <div class="ov-mv-header">
        <div class="ov-mv-toolbar" id="ovMvToolbar">
            <button data-grid="1">1</button>
            <button data-grid="4">4</button>
            <button data-grid="6" class="active">6</button>
            <button data-grid="8">8</button>
            <button data-grid="16">16</button>
        </div>
        <div class="ov-mv-title" id="ovMvTitle"><i class="fa fa-th"></i> 멀티뷰</div>
        <button class="ov-viewer-close" id="ovMvClose">&times;</button>
    </div>
    <div class="ov-mv-grid ov-mv-grid-6" id="ovMvGrid"></div>
    <button class="ov-mv-back hidden" id="ovMvBack"><i class="fa fa-arrow-left"></i> 전체 보기</button>
</div>

</section>

<script>
/* ════════════════════════════════════════
   SHVQ V2 — ONVIF 카메라 관리
   로컬 뷰어 (localhost:1984) 방식
   ════════════════════════════════════════ */
(function(){
'use strict';

var VIEWER_URL  = 'http://localhost:1984';
var API_URL     = 'dist_process/saas/Onvif.php';
var ROLE_LEVEL  = <?= $_roleLevel ?>;
var TENANT_ID   = <?= $_tenantId ?>;

var _viewerReady = false;
var _camCache    = [];
var _currentCamId = null;
var _currentQuality = 'sub';
var _docClickHandler = null;
var _docKeydownHandler = null;

/* ── 유틸 ── */
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── 제조사별 RTSP 패턴 ── */
var RTSP_PATTERNS = {
    vigi: {
        label: 'TP-Link VIGI NVR', port: 554,
        mainFn: function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/ch'+ch+'/main/av_stream'; },
        subFn:  function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/ch'+ch+'/sub/av_stream'; },
        note: 'VIGI NVR: /ch{N}/main/av_stream (메인), /ch{N}/sub/av_stream (서브)'
    },
    hikvision: {
        label: 'Hikvision', port: 554,
        mainFn: function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/Streaming/Channels/'+ch+'01'; },
        subFn:  function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/Streaming/Channels/'+ch+'02'; },
        note: 'Hikvision: CH1=101/102, CH2=201/202 ...'
    },
    dahua: {
        label: 'Dahua', port: 554,
        mainFn: function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/cam/realmonitor?channel='+ch+'&subtype=0'; },
        subFn:  function(ip,p,u,pw,ch){ return 'rtsp://'+u+':'+pw+'@'+ip+':'+p+'/cam/realmonitor?channel='+ch+'&subtype=1'; },
        note: 'Dahua: subtype=0(메인), subtype=1(서브)'
    }
};

function getRtspUrl(c, quality) {
    quality = quality || c.defaultStream || 'sub';
    if (c.connMethod === 'rtsp') return quality === 'main' ? (c.rtspMain||'') : (c.rtspSub||c.rtspMain||'');
    if (quality === 'main' && c.rtspMain) return c.rtspMain;
    if (quality === 'sub'  && c.rtspSub)  return c.rtspSub;
    var base = 'rtsp://'+(c.user||'admin')+':'+(c.pass||'')+'@'+c.ip+':554/';
    return quality === 'main' ? base+'stream1' : base+'stream2';
}

function rtspToStreamId(rtspUrl) {
    if (!rtspUrl) return '';
    var m = rtspUrl.match(/@([^:/]+)(?::(\d+))?.*?\/(ch\d+)/i);
    if (m) return 'cam_' + m[1].replace(/\./g,'_') + '_' + m[3].toLowerCase();
    var m2 = rtspUrl.match(/@([^:/]+)(?::(\d+))?/);
    if (m2) return 'cam_' + m2[1].replace(/\./g,'_') + (m2[2]&&m2[2]!=='554'?'_'+m2[2]:'');
    return '';
}

function genId(ip, channel) {
    if (ip) return 'cam_' + (ip+'_'+(channel||'0')).replace(/[^a-zA-Z0-9]/g,'_').toLowerCase();
    return 'cam_'+Date.now().toString(36)+Math.random().toString(36).substr(2,4);
}

/* ── API 호출 ── */
function camApi(todo, data) {
    return SHV.api.post(API_URL + '?todo=' + todo, data || {});
}

function camApiGet(todo, params) {
    return SHV.api.get(API_URL, Object.assign({todo: todo}, params || {}));
}

/* ── 데이터 레이어 ── */
function camDbLoad(cb) {
    camApiGet('camera_list').then(function(res) {
        if (res.ok && res.data) {
            _camCache = res.data.items || res.data || [];
        }
        if (cb) cb(_camCache);
    }).catch(function() { if (cb) cb(_camCache); });
}

function camDbUpsert(cam, cb) {
    camApi('camera_upsert', cam).then(function(res) {
        if (res.ok && res.data && res.data.item) {
            var idx = _camCache.findIndex(function(x){ return x.id === res.data.item.id; });
            if (idx >= 0) _camCache[idx] = res.data.item; else _camCache.push(res.data.item);
        }
        if (cb) cb(res);
    }).catch(function(){ if (cb) cb({ok:false}); });
}

function camDbDelete(camId, cb) {
    camApi('camera_delete', {id: camId}).then(function(res) {
        if (res.ok) _camCache = _camCache.filter(function(x){ return x.id !== camId; });
        if (cb) cb(res);
    }).catch(function(){ if (cb) cb({ok:false}); });
}

function camDbBulkUpsert(camsArr, cb) {
    camApi('camera_bulk_upsert', {cameras: JSON.stringify(camsArr)}).then(function(res) {
        if (res.ok) camDbLoad(cb); else if (cb) cb(res);
    }).catch(function(){ if (cb) cb({ok:false}); });
}

/* ── OS 감지 ── */
function detectOS() {
    var ua = navigator.userAgent;
    if (ua.indexOf('Win') !== -1) return 'windows';
    if (ua.indexOf('Mac') !== -1) return 'mac';
    return 'unknown';
}

function initOS() {
    var os = detectOS();
    var badge = document.getElementById('ovOsBadge');
    var dlLabel = document.getElementById('ovDownloadLabel');
    var dlBtn = document.getElementById('ovDownloadBtn');
    if (os === 'windows') {
        badge.innerHTML = '<i class="fa fa-windows"></i> Windows';
        dlLabel.textContent = 'Windows용 다운로드 (SHV_Viewer_Setup.exe)';
        dlBtn.href = 'downloads/SHV_Viewer_Setup.exe';
    } else if (os === 'mac') {
        badge.innerHTML = '<i class="fa fa-apple"></i> macOS';
        dlLabel.textContent = 'macOS용 다운로드 (SHV_Viewer.pkg)';
        dlBtn.href = 'downloads/SHV_Viewer.pkg';
    } else {
        badge.innerHTML = '<i class="fa fa-desktop"></i> ' + os;
    }
}

/* ── 뷰어 상태 확인 (WebSocket) ── */
function checkViewer() {
    var banner = document.getElementById('ovStatusBanner');
    banner.className = 'ov-status ov-status-checking';
    banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-spinner fa-spin"></i></div><div><div class="ov-status-title">뷰어 상태 확인 중...</div><div class="ov-status-desc">localhost:1984 연결을 시도하고 있습니다.</div></div>';

    var ws, done = false;
    var timer = setTimeout(function(){
        if (done) return; done = true;
        try { ws.close(); } catch(e) {}
        showNotInstalled();
    }, 2000);

    try {
        ws = new WebSocket('ws://localhost:1984/api/ws');
        ws.onopen = function() {
            if (done) return; done = true; clearTimeout(timer); ws.close();
            _viewerReady = true;
            banner.className = 'ov-status ov-status-ready';
            banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-check-circle"></i></div><div><div class="ov-status-title">뷰어 연결 완료</div><div class="ov-status-desc">SHV CCTV Viewer가 정상 동작 중입니다. 카메라를 관리하고 실시간 영상을 확인하세요.</div></div>';
            document.getElementById('ovInstallBox').classList.add('hidden');
            document.getElementById('ovMainArea').classList.remove('hidden');
            camDbLoad(function(){ render(); });
        };
        ws.onerror = function() { if (done) return; done = true; clearTimeout(timer); showNotInstalled(); };
        ws.onclose = function() { if (!done) { done = true; clearTimeout(timer); showNotInstalled(); } };
    } catch(e) { if (!done) { done = true; clearTimeout(timer); showNotInstalled(); } }

    function showNotInstalled() {
        _viewerReady = false;
        banner.className = 'ov-status ov-status-install';
        banner.innerHTML = '<div class="ov-status-icon"><i class="fa fa-download"></i></div><div><div class="ov-status-title">뷰어가 설치되지 않았거나 실행 중이 아닙니다</div><div class="ov-status-desc">아래에서 SHV CCTV Viewer를 다운로드하여 설치해주세요.</div></div>';
        document.getElementById('ovInstallBox').classList.remove('hidden');
        document.getElementById('ovMainArea').classList.add('hidden');
    }
}

/* ── 렌더: 카드뷰 ── */
function renderGrid(cams) {
    var q = (document.getElementById('ovSearchInput').value || '').toLowerCase();
    var filtered = cams.filter(function(c){ return !q || c.name.toLowerCase().indexOf(q)>=0 || (c.ip||'').indexOf(q)>=0; });
    var grid  = document.getElementById('ovGrid');
    var empty = document.getElementById('ovEmpty');
    document.getElementById('ovCamCount').textContent = '총 ' + cams.length + '대';

    if (!filtered.length) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
        return;
    }
    empty.classList.add('hidden');
    grid.innerHTML = filtered.map(function(c) {
        var dotCls = c.status==='online'?'ov-dot-online':c.status==='offline'?'ov-dot-offline':'ov-dot-unknown';
        var ptzBadge = c.isPtz==='1' ? '<span class="ov-ptz-badge"><i class="fa fa-crosshairs"></i> PTZ</span>' : '';
        var connBadge = '';
        if (c.connMethod==='manufacturer' && c.manufacturer) {
            var cls = {vigi:'ov-badge-vigi',hikvision:'ov-badge-hik',dahua:'ov-badge-dahua'};
            var lbl = {vigi:'VIGI',hikvision:'HIK',dahua:'DAHUA'};
            connBadge = '<span class="ov-badge '+(cls[c.manufacturer]||'ov-badge-gray')+'">'+(lbl[c.manufacturer]||c.manufacturer)+'</span>';
        } else if (c.connMethod==='rtsp') {
            connBadge = '<span class="ov-badge ov-badge-gray">RTSP</span>';
        } else {
            connBadge = '<span class="ov-badge ov-badge-blue">ONVIF</span>';
        }
        var actions = '<button class="btn btn-primary btn-sm" data-action="stream" data-id="'+esc(c.id)+'"><i class="fa fa-play"></i> 보기</button>'
            + '<button class="btn btn-success btn-sm" data-action="ping" data-id="'+esc(c.id)+'"><i class="fa fa-plug"></i></button>'
            + (c.isPtz==='1'?'<button class="btn ov-btn-purple btn-sm" data-action="stream-ptz" data-id="'+esc(c.id)+'"><i class="fa fa-crosshairs"></i> PTZ</button>':'')
            + (ROLE_LEVEL<=2?'<button class="btn btn-outline btn-sm" data-action="edit" data-id="'+esc(c.id)+'"><i class="fa fa-pencil"></i></button>'
            + '<button class="btn btn-danger btn-sm" data-action="delete" data-id="'+esc(c.id)+'"><i class="fa fa-trash"></i></button>':'');

        return '<div class="ov-card">'
            + '<div class="ov-card-thumb" data-action="stream" data-id="'+esc(c.id)+'">'
            + (c.snapshot?'<img src="'+esc(c.snapshot)+'" alt="snap">':'<div class="ov-no-snap"><i class="fa fa-video-camera"></i><span>스냅샷 없음</span></div>')
            + '<div class="ov-dot '+dotCls+'"></div>' + ptzBadge
            + '</div>'
            + '<div class="ov-card-body">'
            + '<div class="ov-card-name" title="'+esc(c.name)+'">'+esc(c.name)+'</div>'
            + '<div class="ov-card-sub"><span class="ov-ip">'+esc(c.ip)+':'+esc(c.port||'80')+'</span>'+(c.channel?'<span>'+esc(c.channel)+'</span>':'')+connBadge+'</div>'
            + '<div class="ov-card-actions">'+actions+'</div>'
            + '</div></div>';
    }).join('');
}

/* ── 렌더: 테이블 ── */
function renderTable(cams) {
    var q = (document.getElementById('ovSearchInput').value || '').toLowerCase();
    var filtered = cams.filter(function(c){ return !q || c.name.toLowerCase().indexOf(q)>=0 || (c.ip||'').indexOf(q)>=0; });
    var tbody = document.getElementById('ovTbody');
    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-3">등록된 카메라가 없습니다</td></tr>';
        return;
    }
    tbody.innerHTML = filtered.map(function(c) {
        var badge = c.status==='online'?'<span class="ov-badge ov-badge-green">온라인</span>'
                   :c.status==='offline'?'<span class="ov-badge ov-badge-red">오프라인</span>'
                   :'<span class="ov-badge ov-badge-gray">미확인</span>';
        var conn = '';
        if (c.connMethod==='manufacturer'&&c.manufacturer) {
            var cls={vigi:'ov-badge-vigi',hikvision:'ov-badge-hik',dahua:'ov-badge-dahua'};
            var lbl={vigi:'VIGI',hikvision:'HIK',dahua:'DAHUA'};
            conn='<span class="ov-badge '+(cls[c.manufacturer]||'')+'">'+(lbl[c.manufacturer]||c.manufacturer)+'</span>';
        } else if (c.connMethod==='rtsp') conn='<span class="ov-badge ov-badge-gray">RTSP</span>';
        else conn='<span class="ov-badge ov-badge-blue">ONVIF</span>';
        var ptz = c.isPtz==='1'?'<span class="ov-badge ov-badge-blue">PTZ</span>':'<span class="text-3">-</span>';
        return '<tr>'
            + '<td class="font-semibold">'+esc(c.name)+'</td>'
            + '<td class="ov-font-mono">'+esc(c.ip)+'</td>'
            + '<td>'+esc(c.port||'80')+'</td>'
            + '<td>'+esc(c.channel||'-')+'</td>'
            + '<td>'+conn+'</td>'
            + '<td>'+badge+'</td>'
            + '<td>'+ptz+'</td>'
            + '<td><div class="flex gap-1">'
            + '<button class="btn btn-primary btn-sm" data-action="stream" data-id="'+esc(c.id)+'"><i class="fa fa-play"></i></button>'
            + (ROLE_LEVEL<=2?'<button class="btn btn-outline btn-sm" data-action="edit" data-id="'+esc(c.id)+'"><i class="fa fa-pencil"></i></button>'
            + '<button class="btn btn-danger btn-sm" data-action="delete" data-id="'+esc(c.id)+'"><i class="fa fa-trash"></i></button>':'')
            + '</div></td></tr>';
    }).join('');
}

function render() { renderGrid(_camCache); renderTable(_camCache); }

/* ── WebRTC 연결 (로컬 뷰어) ── */
var _rtcPeer = null;

function connectWebRTC(video, rtspUrl, streamId, onSuccess, onFail) {
    if (_rtcPeer) { try { _rtcPeer.close(); } catch(e){} _rtcPeer = null; }

    /* 로컬 뷰어에 스트림 등록 */
    fetch(VIEWER_URL + '/api/streams?src=' + encodeURIComponent(rtspUrl) + '&name=' + encodeURIComponent(streamId), {method:'PUT'})
        .then(function(){ _doWebRTC(video, streamId, onSuccess, onFail); })
        .catch(function(){ _doWebRTC(video, streamId, onSuccess, onFail); });
}

function _doWebRTC(video, streamId, onSuccess, onFail) {
    var pc = new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
    _rtcPeer = pc;
    pc.addTransceiver('video', {direction:'recvonly'});
    pc.addTransceiver('audio', {direction:'recvonly'});

    pc.ontrack = function(e) {
        if (e.streams && e.streams[0]) {
            video.srcObject = e.streams[0];
            video.play().catch(function(){});
        }
    };
    pc.oniceconnectionstatechange = function() {
        if (pc.iceConnectionState==='connected'||pc.iceConnectionState==='completed') {
            if (onSuccess) onSuccess();
        }
        if (pc.iceConnectionState==='failed'||pc.iceConnectionState==='disconnected') {
            _fallbackMSE(video, streamId, onSuccess, onFail);
        }
    };

    pc.createOffer().then(function(offer) {
        return pc.setLocalDescription(offer);
    }).then(function() {
        return fetch(VIEWER_URL + '/api/webrtc?src=' + encodeURIComponent(streamId), {
            method:'POST', headers:{'Content-Type':'application/sdp'}, body: pc.localDescription.sdp
        });
    }).then(function(r) {
        if (r.status === 404) { if (onFail) onFail('채널에 카메라 없음'); return ''; }
        return r.text();
    }).then(function(sdp) {
        if (!sdp || sdp.indexOf('v=0') < 0) { _fallbackMSE(video, streamId, onSuccess, onFail); return; }
        pc.setRemoteDescription(new RTCSessionDescription({type:'answer', sdp:sdp}));
        setTimeout(function(){ if (onSuccess && !video.srcObject) _fallbackMSE(video, streamId, onSuccess, onFail); }, 10000);
    }).catch(function(){ _fallbackMSE(video, streamId, onSuccess, onFail); });
}

function _fallbackMSE(video, streamId, onSuccess, onFail) {
    if (_rtcPeer) { try { _rtcPeer.close(); } catch(e){} _rtcPeer = null; }
    video.srcObject = null;
    video.src = VIEWER_URL + '/api/stream.mp4?src=' + encodeURIComponent(streamId);
    video.onloadeddata = function() { if (onSuccess) onSuccess(); };
    video.onerror = function() { if (onFail) onFail('스트림 연결 실패'); };
    setTimeout(function() { if (!video.readyState) { if (onFail) onFail('타임아웃'); } }, 10000);
    video.play().catch(function(){ if (onFail) onFail('재생 실패'); });
}

/* ── 스트림 뷰어 열기/닫기 ── */
function openStream(camId, showPtz) {
    var c = _camCache.find(function(x){ return x.id === camId; });
    if (!c) return;
    if (!_viewerReady) { SHV.toast.warn('뷰어가 실행 중이 아닙니다'); return; }

    _currentCamId = camId;
    _currentQuality = c.defaultStream || 'sub';

    document.getElementById('ovViewerTitle').textContent = c.name;
    document.getElementById('ovViewerSubtitle').textContent = c.ip + ':' + (c.port||'80') + (c.channel?' · '+c.channel:'');
    document.getElementById('ovQMain').classList.toggle('active', _currentQuality==='main');
    document.getElementById('ovQSub').classList.toggle('active',  _currentQuality==='sub');

    /* PTZ */
    var ptzBtn = document.getElementById('ovPtzToggleBtn');
    var ptzWrap = document.getElementById('ovPtzWrap');
    if (c.isPtz === '1') {
        ptzBtn.classList.remove('hidden');
        ptzWrap.classList.toggle('hidden', !showPtz);
        ptzBtn.classList.toggle('active', !!showPtz);
    } else {
        ptzBtn.classList.add('hidden');
        ptzWrap.classList.add('hidden');
    }

    /* 영상 연결 */
    var video = document.getElementById('ovStreamVideo');
    var loading = document.getElementById('ovStreamLoading');
    var placeholder = document.getElementById('ovStreamPlaceholder');
    video.style.display = 'none'; placeholder.classList.remove('active');
    loading.classList.add('active');

    var rtsp = getRtspUrl(c, _currentQuality);
    if (rtsp) {
        var streamId = rtspToStreamId(rtsp) || c.id;
        connectWebRTC(video, rtsp, streamId,
            function() { loading.classList.remove('active'); video.style.display = ''; },
            function(msg) {
                loading.classList.remove('active');
                video.style.display = 'none';
                document.getElementById('ovPlaceholderMsg').textContent = msg || '카메라 연결 실패';
                placeholder.classList.add('active');
            }
        );
    } else {
        loading.classList.remove('active');
        placeholder.classList.add('active');
    }

    /* 정보 그리드 */
    var connLabel = c.connMethod==='manufacturer'&&c.manufacturer&&RTSP_PATTERNS[c.manufacturer] ? RTSP_PATTERNS[c.manufacturer].label : c.connMethod==='rtsp'?'RTSP 직접':'ONVIF 자동';
    document.getElementById('ovInfoGrid').innerHTML =
        '<div class="ov-info-item"><div class="ov-info-lbl">IP 주소</div><div class="ov-info-val">'+esc(c.ip)+'</div></div>'
      + '<div class="ov-info-item"><div class="ov-info-lbl">포트</div><div class="ov-info-val">'+esc(c.port||'80')+'</div></div>'
      + '<div class="ov-info-item"><div class="ov-info-lbl">연결방식</div><div class="ov-info-val">'+esc(connLabel)+'</div></div>'
      + '<div class="ov-info-item"><div class="ov-info-lbl">채널</div><div class="ov-info-val">'+esc(c.channel||'-')+'</div></div>'
      + '<div class="ov-info-item"><div class="ov-info-lbl">모델</div><div class="ov-info-val">'+esc([c.manufacturer,c.model].filter(Boolean).join(' ')||'-')+'</div></div>'
      + '<div class="ov-info-item"><div class="ov-info-lbl">메모</div><div class="ov-info-val">'+esc(c.memo||'-')+'</div></div>';

    /* 녹화 초기화 */
    _recReset();

    document.getElementById('ovViewerOverlay').classList.add('open');
}

function closeStream() {
    if (_rtcPeer) { try { _rtcPeer.close(); } catch(e){} _rtcPeer = null; }
    var video = document.getElementById('ovStreamVideo');
    if (video) { video.pause(); video.srcObject = null; video.src = ''; video.style.display = 'none'; }
    document.getElementById('ovViewerOverlay').classList.remove('open');
    _currentCamId = null;
}

/* ── 카메라 추가/수정 모달 ── */
function openAddModal(editId) {
    if (ROLE_LEVEL > 2) { SHV.toast.warn('관리자 권한이 필요합니다'); return; }
    var c = editId ? _camCache.find(function(x){ return x.id===editId; }) : null;
    var isEdit = !!c;
    var title = isEdit ? '카메라 수정' : '카메라 추가';

    var html = '<input type="hidden" id="ovEditIdx" value="'+(isEdit?esc(c.id):'')+'">'
        + '<div class="ov-form-section"><div class="ov-form-section-title">연결 방식</div>'
        + '<div class="ov-conn-method"><button id="ovConnOnvif" class="'+((!c||c.connMethod==='onvif')?'active':'')+'" data-conn="onvif"><i class="fa fa-magic"></i> ONVIF</button>'
        + '<button id="ovConnMfr" class="'+((c&&c.connMethod==='manufacturer')?'active':'')+'" data-conn="manufacturer"><i class="fa fa-industry"></i> 제조사 선택</button>'
        + '<button id="ovConnRtsp" class="'+((c&&c.connMethod==='rtsp')?'active':'')+'" data-conn="rtsp"><i class="fa fa-link"></i> RTSP 직접</button></div>'
        + '<div class="text-xs text-3 mb-3" id="ovConnHint">IP + 아이디 + 비번만 입력하면 RTSP URL을 자동으로 가져옵니다.</div></div>'
        + '<div class="ov-form-section"><div class="ov-form-section-title">기본 정보</div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">카메라명 <span class="text-accent">*</span></label><input type="text" class="form-input" id="ovFName" value="'+esc(c?c.name:'')+'"></div>'
        + '<div class="form-group"><label class="form-label">채널 / 위치</label><input type="text" class="form-input" id="ovFChannel" value="'+esc(c?c.channel:'')+'"></div></div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">IP 주소 <span class="text-accent">*</span></label><input type="text" class="form-input" id="ovFIp" value="'+esc(c?c.ip:'')+'"></div>'
        + '<div class="form-group"><label class="form-label">포트</label><input type="text" class="form-input" id="ovFPort" value="'+esc(c?c.port:'80')+'"></div></div></div>'
        + '<div class="ov-form-section"><div class="ov-form-section-title">인증</div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">사용자명</label><input type="text" class="form-input" id="ovFUser" value="'+esc(c?c.user:'')+'"></div>'
        + '<div class="form-group"><label class="form-label">비밀번호</label><input type="password" class="form-input" id="ovFPass" value="'+esc(c?c.pass:'')+'"></div></div></div>'
        + '<div class="ov-form-section" id="ovOptSection"><div class="ov-form-section-title">ONVIF 옵션</div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">기본 스트림</label><select class="form-select" id="ovFStream"><option value="sub"'+((!c||c.defaultStream!=='main')?' selected':'')+'>서브 스트림 (빠름, 권장)</option><option value="main"'+((c&&c.defaultStream==='main')?' selected':'')+'>메인 스트림 (고화질)</option></select></div>'
        + '<div class="form-group"><label class="form-label">PTZ 카메라 여부</label><select class="form-select" id="ovFPtz"><option value="0"'+((!c||c.isPtz!=='1')?' selected':'')+'>일반 카메라</option><option value="1"'+((c&&c.isPtz==='1')?' selected':'')+'>PTZ 카메라</option></select></div></div></div>'
        + '<div class="ov-form-section hidden" id="ovMfrSection"><div class="ov-form-section-title">제조사 / NVR</div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">제조사 <span class="text-accent">*</span></label><select class="form-select" id="ovFMfr"><option value="vigi"'+((c&&c.manufacturer==='vigi')?' selected':'')+'>TP-Link VIGI NVR</option><option value="hikvision"'+((c&&c.manufacturer==='hikvision')?' selected':'')+'>Hikvision</option><option value="dahua"'+((c&&c.manufacturer==='dahua')?' selected':'')+'>Dahua</option></select></div>'
        + '<div class="form-group"><label class="form-label">RTSP 포트</label><input type="text" class="form-input" id="ovFRtspPort" value="'+esc(c?c.rtspPort:'554')+'"></div></div>'
        + '<div class="ov-form-row"><div class="form-group"><label class="form-label">채널 수</label><select class="form-select" id="ovFMfrChCount"><option value="1">1채널</option><option value="4">4채널</option><option value="8" selected>8채널</option><option value="16">16채널</option><option value="32">32채널</option></select><div class="ov-form-hint">NVR 채널 수 선택</div></div></div>'
        + '<div class="mt-1"><label class="form-label">RTSP URL 미리보기</label><div class="ov-mfr-preview" id="ovMfrPreview"></div></div></div>'
        + '<div class="ov-form-section hidden" id="ovRtspSection"><div class="ov-form-section-title">RTSP URL</div>'
        + '<div class="ov-form-row full"><div class="form-group"><label class="form-label">메인 스트림 URL</label><input type="text" class="form-input" id="ovFRtspMain" value="'+esc(c?c.rtspMain:'')+'"></div></div>'
        + '<div class="ov-form-row full"><div class="form-group"><label class="form-label">서브 스트림 URL</label><input type="text" class="form-input" id="ovFRtspSub" value="'+esc(c?c.rtspSub:'')+'"></div></div></div>'
        + '<div class="ov-form-row full"><div class="form-group"><label class="form-label">메모</label><input type="text" class="form-input" id="ovFMemo" value="'+esc(c?c.memo:'')+'"></div></div>'
        + '<div class="flex gap-2 justify-end mt-2 flex-wrap">'
        + '<button class="btn btn-success btn-sm" id="ovTestConn"><i class="fa fa-plug"></i> 연결 테스트</button>'
        + '<button class="btn btn-outline btn-sm hidden" id="ovLoadChBtn"><i class="fa fa-list-ul"></i> 채널 불러오기</button>'
        + '<button class="btn btn-outline btn-sm" onclick="SHV.modal.close()">취소</button>'
        + '<button class="btn btn-primary btn-sm" id="ovSaveBtn"><i class="fa fa-save"></i> 저장</button>'
        + '</div>'
        + '<div class="ov-ch-panel hidden" id="ovChPanel">'
        + '<div class="ov-ch-panel-header"><span class="ov-ch-panel-title"><i class="fa fa-th-list"></i> <span id="ovChPanelTitle">채널 목록</span></span>'
        + '<label class="text-xs font-semibold text-2 flex items-center gap-1"><input type="checkbox" id="ovChSelectAll"> 전체선택</label></div>'
        + '<div id="ovChTableWrap"></div>'
        + '<div class="ov-ch-panel-footer"><span id="ovChSelectedCount" class="text-xs text-3">0개 선택</span>'
        + '<button class="btn btn-primary btn-sm ml-auto" id="ovRegisterChBtn"><i class="fa fa-plus-circle"></i> 선택 채널 일괄 등록</button></div></div>';

    SHV.modal.openHtml(html, title, 'lg');

    /* 연결방식 초기 상태 */
    setTimeout(function() {
        var conn = c ? c.connMethod || 'onvif' : 'onvif';
        setConnMethod(conn);
        bindModalEvents();
    }, 50);
}

var _connMethod = 'onvif';
var _loadedChannels = [];

function setConnMethod(m) {
    _connMethod = m;
    var onvifBtn = document.getElementById('ovConnOnvif');
    var mfrBtn   = document.getElementById('ovConnMfr');
    var rtspBtn  = document.getElementById('ovConnRtsp');
    if (onvifBtn) onvifBtn.classList.toggle('active', m==='onvif');
    if (mfrBtn)   mfrBtn.classList.toggle('active', m==='manufacturer');
    if (rtspBtn)  rtspBtn.classList.toggle('active', m==='rtsp');

    var optS = document.getElementById('ovOptSection');
    var mfrS = document.getElementById('ovMfrSection');
    var rtspS = document.getElementById('ovRtspSection');
    if (optS)  optS.classList.toggle('hidden', m!=='onvif');
    if (mfrS)  mfrS.classList.toggle('hidden', m!=='manufacturer');
    if (rtspS) rtspS.classList.toggle('hidden', m!=='rtsp');

    var hints = {onvif:'IP + 아이디 + 비번만 입력하면 RTSP URL을 자동으로 가져옵니다.', manufacturer:'제조사를 선택하면 RTSP URL이 자동 생성됩니다.', rtsp:'RTSP URL을 직접 입력합니다.'};
    var hint = document.getElementById('ovConnHint');
    if (hint) hint.textContent = hints[m] || '';
    if (m === 'manufacturer') mfrPreview();
}

function mfrPreview() {
    var mfr = document.getElementById('ovFMfr');
    var box = document.getElementById('ovMfrPreview');
    if (!mfr || !box) return;
    var pat = RTSP_PATTERNS[mfr.value];
    if (!pat) { box.innerHTML = ''; return; }
    var ip = (document.getElementById('ovFIp').value||'').trim() || '(IP)';
    var rp = (document.getElementById('ovFRtspPort').value||'').trim() || String(pat.port);
    var user = (document.getElementById('ovFUser').value||'').trim() || 'admin';
    var pass = document.getElementById('ovFPass').value || '(비밀번호)';
    var chCount = parseInt(document.getElementById('ovFMfrChCount').value) || 1;
    var lines = [];
    for (var ch = 1; ch <= Math.min(chCount, 32); ch++) {
        if (chCount > 1) lines.push('<span class="text-accent font-bold">CH'+ch+'</span>');
        lines.push('메인: '+esc(pat.mainFn(ip,rp,user,pass,ch)));
        lines.push('서브: '+esc(pat.subFn(ip,rp,user,pass,ch)));
        if (ch < chCount) lines.push('');
    }
    box.innerHTML = lines.join('<br>');
}

function bindModalEvents() {
    /* 연결방식 버튼 */
    document.querySelectorAll('.ov-conn-method button').forEach(function(btn) {
        btn.addEventListener('click', function(){ setConnMethod(this.dataset.conn); });
    });
    /* 제조사 미리보기 갱신 */
    ['ovFIp','ovFUser','ovFPass','ovFRtspPort','ovFMfr','ovFMfrChCount'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', function(){ if (_connMethod==='manufacturer') mfrPreview(); });
        if (el) el.addEventListener('change', function(){ if (_connMethod==='manufacturer') mfrPreview(); });
    });
    /* 저장 */
    var saveBtn = document.getElementById('ovSaveBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveCam);
    /* 연결 테스트 */
    var testBtn = document.getElementById('ovTestConn');
    if (testBtn) testBtn.addEventListener('click', testConnection);
    /* 채널 불러오기 */
    var loadChBtn = document.getElementById('ovLoadChBtn');
    if (loadChBtn) loadChBtn.addEventListener('click', loadChannels);
    /* 채널 전체선택 */
    var chAll = document.getElementById('ovChSelectAll');
    if (chAll) chAll.addEventListener('change', function(){ document.querySelectorAll('.ov-ch-chk').forEach(function(el){ el.checked = chAll.checked; }); chUpdateCount(); });
    /* 채널 일괄 등록 */
    var regChBtn = document.getElementById('ovRegisterChBtn');
    if (regChBtn) regChBtn.addEventListener('click', registerChannels);
}

function saveCam() {
    var name = (document.getElementById('ovFName').value||'').trim();
    var ip   = (document.getElementById('ovFIp').value||'').trim();
    if (!name) { SHV.toast.warn('카메라명을 입력하세요'); return; }
    if (!ip)   { SHV.toast.warn('IP/DDNS 주소를 입력하세요'); return; }
    var user = (document.getElementById('ovFUser').value||'').trim();
    var pass = document.getElementById('ovFPass').value;
    var editId = document.getElementById('ovEditIdx').value;
    if (!user) { SHV.toast.warn('로그인 ID를 입력하세요'); return; }
    if (!pass && !editId) { SHV.toast.warn('비밀번호를 입력하세요'); return; }

    var mfr = _connMethod==='manufacturer' ? document.getElementById('ovFMfr').value : '';
    var rtspPort = (document.getElementById('ovFRtspPort').value||'').trim() || '554';

    /* 제조사 + 다채널 일괄등록 */
    if (_connMethod === 'manufacturer' && !editId) {
        var chCount = parseInt(document.getElementById('ovFMfrChCount').value) || 1;
        if (chCount > 1) {
            var pat = RTSP_PATTERNS[mfr];
            if (!pat) { SHV.toast.warn('제조사를 선택하세요'); return; }
            var bulkCams = [];
            for (var ch = 1; ch <= chCount; ch++) {
                bulkCams.push({
                    id: genId(ip,'CH'+ch), name: name+' CH'+ch, channel: 'CH'+ch,
                    ip: ip, port: (document.getElementById('ovFPort').value||'').trim()||'80',
                    user: user, pass: pass, memo: (document.getElementById('ovFMemo').value||'').trim(),
                    connMethod: 'manufacturer', manufacturer: mfr, rtspPort: rtspPort,
                    defaultStream: 'sub', isPtz: '0',
                    rtspMain: pat.mainFn(ip,rtspPort,user,pass,ch),
                    rtspSub: pat.subFn(ip,rtspPort,user,pass,ch),
                    status: 'unknown'
                });
            }
            camDbBulkUpsert(bulkCams, function(){ render(); });
            SHV.modal.close();
            SHV.toast.success(bulkCams.length + '개 채널이 일괄 등록되었습니다');
            return;
        }
    }

    var rtspMain = (document.getElementById('ovFRtspMain').value||'').trim();
    var rtspSub  = (document.getElementById('ovFRtspSub').value||'').trim();
    if (_connMethod === 'manufacturer') {
        var pat = RTSP_PATTERNS[mfr];
        if (pat) { rtspMain = pat.mainFn(ip,rtspPort,user,pass,1); rtspSub = pat.subFn(ip,rtspPort,user,pass,1); }
    }

    var cam = {
        id: editId || genId(ip, (document.getElementById('ovFChannel').value||'').trim()),
        name: name, channel: (document.getElementById('ovFChannel').value||'').trim(),
        ip: ip, port: (document.getElementById('ovFPort').value||'').trim()||'80',
        user: user, pass: pass, memo: (document.getElementById('ovFMemo').value||'').trim(),
        connMethod: _connMethod, manufacturer: mfr, rtspPort: rtspPort,
        defaultStream: document.getElementById('ovFStream').value,
        isPtz: document.getElementById('ovFPtz').value,
        rtspMain: rtspMain, rtspSub: rtspSub, status: 'unknown'
    };

    camDbUpsert(cam, function(res) {
        if (res.ok) render(); else SHV.toast.error('저장 실패: '+(res.message||'서버 오류'));
    });
    SHV.modal.close();
    SHV.toast.success(editId ? '카메라 정보가 수정되었습니다' : '카메라가 추가되었습니다');
}

function deleteCam(camId) {
    shvConfirm({title:'카메라 삭제', message:'이 카메라를 삭제하시겠습니까?'}).then(function(ok) {
        if (!ok) return;
        camDbDelete(camId, function(res) {
            if (res.ok) { render(); SHV.toast.success('카메라가 삭제되었습니다'); }
            else SHV.toast.error('삭제 실패');
        });
    });
}

/* ── 연결 테스트 ── */
function testConnection() {
    var ip = (document.getElementById('ovFIp').value||'').trim();
    if (!ip) { SHV.toast.warn('IP 주소를 입력하세요'); return; }

    if (_connMethod === 'manufacturer') {
        var rp = (document.getElementById('ovFRtspPort').value||'').trim()||'554';
        var user = (document.getElementById('ovFUser').value||'').trim();
        var pass = document.getElementById('ovFPass').value;
        SHV.toast.info('RTSP 연결 + 인증 확인 중 ('+ip+':'+rp+')...');
        camApiGet('rtsp_auth_check', {ip:ip, port:rp, user:user, pass:pass, timeout:5}).then(function(res) {
            if (res.ok) SHV.toast.success('RTSP 연결 + 인증 성공!');
            else SHV.toast.error(res.message || 'RTSP 연결 실패');
        });
        return;
    }

    var port = (document.getElementById('ovFPort').value||'').trim()||'80';
    SHV.toast.info('연결 테스트 중 ('+ip+':'+port+')...');
    camApiGet('test', {ip:ip, port:port, user:document.getElementById('ovFUser').value, pass:document.getElementById('ovFPass').value}).then(function(res) {
        if (res.ok) {
            SHV.toast.success('연결 성공! ' + (res.data ? [res.data.manufacturer,res.data.model].filter(Boolean).join(' ') : ''));
            var btn = document.getElementById('ovLoadChBtn');
            if (btn) btn.classList.remove('hidden');
        } else {
            SHV.toast.error(res.message || '연결 실패');
        }
    });
}

/* ── 채널 불러오기 ── */
function loadChannels() {
    var ip = (document.getElementById('ovFIp').value||'').trim();
    var port = (document.getElementById('ovFPort').value||'').trim()||'80';
    var panel = document.getElementById('ovChPanel');
    panel.classList.remove('hidden');
    document.getElementById('ovChTableWrap').innerHTML = '<div class="ov-ch-loading"><i class="fa fa-spinner fa-spin"></i> 채널 조회 중...</div>';
    _loadedChannels = [];

    camApiGet('channels', {ip:ip, port:port, user:document.getElementById('ovFUser').value, pass:document.getElementById('ovFPass').value, timeout:6}).then(function(res) {
        if (!res.ok) {
            document.getElementById('ovChTableWrap').innerHTML = '<div class="ov-ch-loading text-danger"><i class="fa fa-exclamation-circle"></i> '+ esc(res.message||'채널 조회 실패')+'</div>';
            return;
        }
        var channels = (res.data && res.data.channels) || [];
        _loadedChannels = channels;
        document.getElementById('ovChPanelTitle').textContent = '채널 목록 (' + channels.length + '개)';
        if (!channels.length) {
            document.getElementById('ovChTableWrap').innerHTML = '<div class="ov-ch-loading">채널 없음</div>';
            return;
        }
        var rows = channels.map(function(ch, i) {
            return '<tr><td class="text-center col-60"><input type="checkbox" class="ov-ch-chk" data-idx="'+i+'"></td>'
                + '<td class="text-center font-bold col-60">CH'+ch.channel_no+'</td>'
                + '<td class="font-semibold">'+esc(ch.name)+'</td>'
                + '<td>'+esc(ch.encoding||'-')+'</td><td>'+esc(ch.resolution||'-')+'</td>'
                + '<td>'+(ch.fps>0?ch.fps+'fps':'-')+'</td>'
                + '<td class="ov-ch-rtsp" title="'+esc(ch.rtsp)+'">'+esc(ch.rtsp)+'</td></tr>';
        }).join('');
        document.getElementById('ovChTableWrap').innerHTML =
            '<div class="tbl-scroll"><table class="ov-ch-table"><thead><tr><th></th><th>채널</th><th>이름</th><th>코덱</th><th>해상도</th><th>FPS</th><th>RTSP URL</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
        /* 체크 이벤트 */
        document.querySelectorAll('.ov-ch-chk').forEach(function(el) { el.addEventListener('change', chUpdateCount); });
    });
}

function chUpdateCount() {
    var n = document.querySelectorAll('.ov-ch-chk:checked').length;
    var el = document.getElementById('ovChSelectedCount');
    if (el) el.textContent = n + '개 선택';
}

function registerChannels() {
    var checked = document.querySelectorAll('.ov-ch-chk:checked');
    if (!checked.length) { SHV.toast.warn('등록할 채널을 선택하세요'); return; }
    var ip = (document.getElementById('ovFIp').value||'').trim();
    var port = (document.getElementById('ovFPort').value||'').trim()||'80';
    var user = (document.getElementById('ovFUser').value||'').trim();
    var pass = document.getElementById('ovFPass').value;
    var baseName = (document.getElementById('ovFName').value||'').trim();
    var memo = (document.getElementById('ovFMemo').value||'').trim();
    var newCams = [];
    checked.forEach(function(el) {
        var ch = _loadedChannels[parseInt(el.dataset.idx)];
        if (!ch) return;
        if (_camCache.find(function(c){ return c.ip===ip && c.channel===('CH'+ch.channel_no); })) return;
        newCams.push({
            id: genId(ip,'CH'+ch.channel_no), name: ((baseName||ip)+' CH'+ch.channel_no).trim(),
            channel: 'CH'+ch.channel_no, ip: ip, port: port, user: user, pass: pass, memo: memo,
            connMethod: 'onvif', defaultStream: 'sub', isPtz: '0',
            rtspMain: ch.rtsp||'', rtspSub: ch.rtsp ? ch.rtsp.replace('/stream1','/stream2') : '',
            status: 'online'
        });
    });
    if (!newCams.length) { SHV.toast.warn('이미 등록된 채널입니다'); return; }
    camDbBulkUpsert(newCams, function(){ render(); });
    SHV.modal.close();
    SHV.toast.success(newCams.length + '개 채널이 등록되었습니다');
}

/* ── 카드 핑 ── */
function pingCard(camId) {
    var c = _camCache.find(function(x){ return x.id === camId; });
    if (!c) return;
    SHV.toast.info(c.name + ' 연결 확인 중...');
    var todo = (c.connMethod==='manufacturer'||c.connMethod==='rtsp') ? 'tcp_check' : 'test';
    var params = {ip: c.ip, port: (todo==='tcp_check' ? c.rtspPort||'554' : c.port||'80'), user: c.user||'', pass: c.pass||'', timeout: 3};
    camApiGet(todo, params).then(function(res) {
        c.status = res.ok ? 'online' : 'offline';
        render();
        if (res.ok) SHV.toast.success(c.name + ' 온라인');
        else SHV.toast.error(c.name + ' 연결 실패');
    });
}

/* ── 자동 검색 ── */
function discover() {
    SHV.toast.info('네트워크에서 ONVIF 장치 검색 중...');
    camApiGet('discover').then(function(res) {
        if (!res.ok) { SHV.toast.warn(res.message || '장치 검색 실패'); return; }
        var devices = (res.data && res.data.devices) || [];
        if (!devices.length) { SHV.toast.info('발견된 ONVIF 장치 없음'); return; }
        var newCams = [];
        devices.forEach(function(dev) {
            if (!_camCache.find(function(c){ return c.ip === dev.ip; })) {
                newCams.push({ id: genId(dev.ip,''), name: dev.name||'ONVIF 카메라', ip: dev.ip, port: dev.port||'80',
                    connMethod:'onvif', defaultStream:'sub', isPtz:'0', status:'online', memo:'자동검색' });
            }
        });
        if (!newCams.length) { SHV.toast.info('새로 발견된 장치 없음'); return; }
        camDbBulkUpsert(newCams, function(){ render(); });
        SHV.toast.success(newCams.length + '대 추가됨');
    });
}

/* ── PTZ ── */
function sendPtz(dir) {
    if (!_currentCamId) return;
    var c = _camCache.find(function(x){ return x.id === _currentCamId; });
    if (!c) return;
    camApiGet('ptz', {dir:dir, ip:c.ip, port:c.port||'80', user:c.user||'', pass:c.pass||''}).then(function(res) {
        if (!res.ok) SHV.toast.warn(res.message || 'PTZ 실패');
    });
}

/* ── 녹화 ── */
var _recState = {recording:false, timer:null, elapsed:0};

function _recReset() {
    _recState.recording = false; _recState.elapsed = 0;
    if (_recState.timer) { clearInterval(_recState.timer); _recState.timer = null; }
    _recUpdateUI();
}
function _recUpdateUI() {
    var startBtn = document.getElementById('ovRecStartBtn');
    var stopBtn  = document.getElementById('ovRecStopBtn');
    var status   = document.getElementById('ovRecStatus');
    var timer    = document.getElementById('ovRecTimer');
    if (_recState.recording) {
        startBtn.classList.add('hidden'); stopBtn.classList.remove('hidden');
        status.className = 'ov-rec-status ov-rec-status-recording';
        status.innerHTML = '<span class="ov-rec-dot"></span> 녹화 중';
    } else {
        startBtn.classList.remove('hidden'); stopBtn.classList.add('hidden');
        status.className = 'ov-rec-status ov-rec-status-idle';
        status.innerHTML = '<span>대기</span>';
        if (timer) timer.textContent = '';
    }
}

function recStart() {
    if (!_currentCamId) return;
    var c = _camCache.find(function(x){ return x.id === _currentCamId; });
    if (!c) return;
    var rtsp = getRtspUrl(c, _currentQuality);
    if (!rtsp) { SHV.toast.warn('RTSP URL이 없습니다'); return; }
    var duration = parseInt(document.getElementById('ovRecDuration').value) || 0;
    SHV.toast.info('녹화 시작 중...');
    camApi('record_start', { stream_id: rtspToStreamId(rtsp)||c.id, rtsp: rtsp, duration_sec: String(duration), height:'720' }).then(function(res) {
        if (res.ok) {
            _recState.recording = true; _recState.elapsed = 0; _recUpdateUI();
            _recState.timer = setInterval(function() {
                _recState.elapsed++;
                var el = document.getElementById('ovRecTimer');
                if (el) { var m=Math.floor(_recState.elapsed/60); var s=_recState.elapsed%60; el.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s; }
                if (duration > 0 && _recState.elapsed >= duration) { _recReset(); SHV.toast.success('녹화 완료'); }
            }, 1000);
            SHV.toast.success('녹화 시작!');
        } else SHV.toast.error('녹화 실패: '+(res.message||''));
    });
}

function recStop() {
    if (!_recState.recording) return;
    camApi('record_stop', {}).then(function() { _recReset(); SHV.toast.success('녹화 중지됨'); });
}

/* ── 멀티뷰 ── */
var _mvGridSize = 6;
var _mvSingleCam = null;
var _mvPeers = [];

function mvCleanup() {
    _mvPeers.forEach(function(pc){ try{pc.close();}catch(e){} });
    _mvPeers = [];
}

function openMultiView() {
    if (!_camCache.length) { SHV.toast.warn('등록된 카메라가 없습니다'); return; }
    if (!_viewerReady) { SHV.toast.warn('뷰어가 실행 중이 아닙니다'); return; }
    _mvSingleCam = null;
    document.getElementById('ovMvBack').classList.add('hidden');
    mvSetGrid(_mvGridSize);
    mvRenderCells(_camCache);
    document.getElementById('ovMvOverlay').classList.add('open');
}

function closeMultiView() {
    mvCleanup();
    document.querySelectorAll('#ovMvGrid video').forEach(function(v){ v.pause(); v.srcObject=null; v.src=''; });
    document.getElementById('ovMvOverlay').classList.remove('open');
    _mvSingleCam = null;
}

function mvSetGrid(n) {
    _mvGridSize = n;
    var grid = document.getElementById('ovMvGrid');
    grid.className = 'ov-mv-grid ov-mv-grid-' + n;
    document.querySelectorAll('#ovMvToolbar button').forEach(function(b){
        b.classList.toggle('active', b.textContent.trim() === String(n));
    });
    if (!_mvSingleCam) mvRenderCells(_camCache);
}

function mvConnectWebRTC(video, streamId) {
    var pc = new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
    _mvPeers.push(pc);
    pc.addTransceiver('video', {direction:'recvonly'});
    pc.ontrack = function(e){ if(e.streams&&e.streams[0]) video.srcObject=e.streams[0]; };
    pc.oniceconnectionstatechange = function(){
        if (pc.iceConnectionState==='failed') {
            video.srcObject = null;
            video.src = VIEWER_URL+'/api/stream.mp4?src='+encodeURIComponent(streamId);
            video.play().catch(function(){});
        }
    };
    pc.createOffer().then(function(o){ return pc.setLocalDescription(o); })
    .then(function(){ return fetch(VIEWER_URL+'/api/webrtc?src='+encodeURIComponent(streamId),{method:'POST',headers:{'Content-Type':'application/sdp'},body:pc.localDescription.sdp}); })
    .then(function(r){ return r.text(); })
    .then(function(sdp){ if(sdp&&sdp.indexOf('v=0')>=0) pc.setRemoteDescription(new RTCSessionDescription({type:'answer',sdp:sdp})); })
    .catch(function(){});
}

function mvRenderCells(cams) {
    cams = cams.slice().sort(function(a,b){
        var chA=parseInt((a.channel||'').replace(/\D/g,''))||999;
        var chB=parseInt((b.channel||'').replace(/\D/g,''))||999;
        return chA-chB;
    });
    var grid = document.getElementById('ovMvGrid');
    mvCleanup();
    var html = '';
    for (var i = 0; i < _mvGridSize; i++) {
        var c = cams[i] || null;
        if (c) {
            html += '<div class="ov-mv-cell" data-cam="'+esc(c.id)+'">'
                + '<video id="mv-video-'+i+'" autoplay muted playsinline></video>'
                + '<div class="ov-mv-label">'+esc(c.channel||'CH'+(i+1))+' '+esc(c.name)+'</div></div>';
        } else {
            html += '<div class="ov-mv-cell"><div class="ov-mv-no-stream">빈 채널</div></div>';
        }
    }
    grid.innerHTML = html;

    /* go2rtc에 등록된 스트림만 연결 */
    fetch(VIEWER_URL+'/api/streams', {signal: AbortSignal.timeout(3000)})
    .then(function(r){ return r.json(); })
    .then(function(streams){
        for (var i=0; i<_mvGridSize; i++) {
            (function(idx){
                var c = cams[idx]; if (!c) return;
                var video = document.getElementById('mv-video-'+idx); if (!video) return;
                var rtsp = getRtspUrl(c, 'sub');
                var streamId = rtspToStreamId(rtsp) || c.id;
                if (!streams[streamId]) {
                    video.parentElement.innerHTML = '<div class="ov-mv-no-stream">카메라 없음</div><div class="ov-mv-label">'+esc(c.channel)+' '+esc(c.name)+'</div>';
                    return;
                }
                setTimeout(function(){ mvConnectWebRTC(video, streamId); }, idx*300);
            })(i);
        }
    }).catch(function(){});

    /* 셀 클릭 → 확대 */
    grid.querySelectorAll('.ov-mv-cell[data-cam]').forEach(function(cell) {
        cell.addEventListener('click', function() {
            var camId = this.dataset.cam;
            if (_mvSingleCam) { mvBackToGrid(); return; }
            _mvSingleCam = camId;
            mvCleanup();
            var c = _camCache.find(function(x){ return x.id===camId; });
            if (!c) return;
            var g = document.getElementById('ovMvGrid');
            g.className = 'ov-mv-grid ov-mv-grid-1';
            g.innerHTML = '<div class="ov-mv-cell"><video id="mv-single-video" autoplay muted playsinline></video>'
                + '<div class="ov-mv-label">'+esc(c.channel||'')+' '+esc(c.name)+'</div></div>';
            var rtsp = getRtspUrl(c, 'main');
            var streamId = rtspToStreamId(rtsp) || c.id;
            /* 로컬 뷰어에 메인 스트림 등록 */
            fetch(VIEWER_URL+'/api/streams?src='+encodeURIComponent(rtsp)+'&name='+encodeURIComponent(streamId),{method:'PUT'})
            .then(function(){ mvConnectWebRTC(document.getElementById('mv-single-video'), streamId); })
            .catch(function(){ mvConnectWebRTC(document.getElementById('mv-single-video'), streamId); });
            document.getElementById('ovMvBack').classList.remove('hidden');
        });
    });
}

function mvBackToGrid() {
    _mvSingleCam = null;
    document.getElementById('ovMvBack').classList.add('hidden');
    mvSetGrid(_mvGridSize);
}

/* ── 이벤트 바인딩 ── */
function bindEvents() {
    /* 뷰어 상태 확인 */
    document.getElementById('ovBtnCheckViewer').addEventListener('click', checkViewer);
    var recheck = document.getElementById('ovBtnRecheck');
    if (recheck) recheck.addEventListener('click', checkViewer);

    /* 검색 */
    document.getElementById('ovSearchInput').addEventListener('input', function(){ render(); });

    /* 탭 */
    document.querySelectorAll('.ov-tab-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.ov-tab-btn').forEach(function(b){ b.classList.remove('active'); });
            document.querySelectorAll('.ov-tab-pane').forEach(function(p){ p.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('ovTab' + btn.dataset.tab.charAt(0).toUpperCase() + btn.dataset.tab.slice(1)).classList.add('active');
        });
    });

    /* 탭 이름 매핑 (grid→Grid, list→List) */

    /* 카드/테이블 액션 위임 (named reference → destroy에서 제거) */
    _docClickHandler = function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;
        var id = btn.dataset.id;
        if (action === 'stream') openStream(id, false);
        else if (action === 'stream-ptz') openStream(id, true);
        else if (action === 'ping') pingCard(id);
        else if (action === 'edit') openAddModal(id);
        else if (action === 'delete') deleteCam(id);
    };
    document.addEventListener('click', _docClickHandler);

    /* 카메라 추가 */
    var addBtn = document.getElementById('ovBtnAddCam');
    if (addBtn) addBtn.addEventListener('click', function(){ openAddModal(); });

    /* 멀티뷰 */
    document.getElementById('ovBtnMultiView').addEventListener('click', openMultiView);
    document.getElementById('ovMvClose').addEventListener('click', closeMultiView);
    document.getElementById('ovMvBack').addEventListener('click', mvBackToGrid);
    document.querySelectorAll('#ovMvToolbar button').forEach(function(btn){
        btn.addEventListener('click', function(){ mvSetGrid(parseInt(this.dataset.grid)); });
    });

    /* 자동 검색 */
    document.getElementById('ovBtnDiscover').addEventListener('click', discover);

    /* 스트림 뷰어 닫기 */
    document.getElementById('ovViewerClose').addEventListener('click', closeStream);

    /* 화질 토글 */
    document.querySelectorAll('.ov-quality-toggle button').forEach(function(btn){
        btn.addEventListener('click', function(){
            _currentQuality = this.dataset.q;
            document.getElementById('ovQMain').classList.toggle('active', _currentQuality==='main');
            document.getElementById('ovQSub').classList.toggle('active', _currentQuality==='sub');
            SHV.toast.info(_currentQuality==='main'?'메인 스트림 (고화질)':'서브 스트림 (빠름)');
        });
    });

    /* PTZ 토글 */
    document.getElementById('ovPtzToggleBtn').addEventListener('click', function(){
        var wrap = document.getElementById('ovPtzWrap');
        wrap.classList.toggle('hidden');
        this.classList.toggle('active', !wrap.classList.contains('hidden'));
    });

    /* PTZ 방향 버튼 */
    document.querySelectorAll('.ov-ptz-btn[data-dir], .ov-ptz-zoom button[data-dir]').forEach(function(btn){
        btn.addEventListener('click', function(){ sendPtz(this.dataset.dir); });
    });

    /* 프리셋 */
    document.querySelectorAll('#ovPresets button[data-preset]').forEach(function(btn){
        btn.addEventListener('click', function(){ SHV.toast.info('프리셋 '+this.dataset.preset+'로 이동 (백엔드 구현 필요)'); });
    });
    var savePreset = document.getElementById('ovSavePreset');
    if (savePreset) savePreset.addEventListener('click', function(){ SHV.toast.info('현재 위치 저장 (백엔드 구현 필요)'); });

    /* 새로고침 */
    document.getElementById('ovRefreshStream').addEventListener('click', function(){
        if (_currentCamId) { closeStream(); openStream(_currentCamId, false); }
    });

    /* 연결 테스트 (뷰어 내) */
    document.getElementById('ovPingCurrent').addEventListener('click', function(){
        if (_currentCamId) pingCard(_currentCamId);
    });

    /* 녹화 */
    document.getElementById('ovRecStartBtn').addEventListener('click', recStart);
    document.getElementById('ovRecStopBtn').addEventListener('click', recStop);

    /* ESC 키 (named reference → destroy에서 제거) */
    _docKeydownHandler = function(e) {
        if (e.key === 'Escape') {
            if (document.getElementById('ovMvOverlay').classList.contains('open')) closeMultiView();
            else if (document.getElementById('ovViewerOverlay').classList.contains('open')) closeStream();
        }
    };
    document.addEventListener('keydown', _docKeydownHandler);
}

/* ── SHV.pages 라이프사이클 ── */
SHV.pages.onvif = {
    init: function() {
        initOS();
        checkViewer();
        bindEvents();
    },
    destroy: function() {
        if (_docClickHandler) { document.removeEventListener('click', _docClickHandler); _docClickHandler = null; }
        if (_docKeydownHandler) { document.removeEventListener('keydown', _docKeydownHandler); _docKeydownHandler = null; }
        closeStream();
        closeMultiView();
        _recReset();
        _camCache = [];
    }
};

})();
</script>
