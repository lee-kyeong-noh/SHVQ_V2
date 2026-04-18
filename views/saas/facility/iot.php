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
$_isAdmin = ($_roleLevel <= 2);
?>
<link rel="stylesheet" href="css/v2/pages/facility.css?v=<?= $_cssV ?>">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js" defer></script>

<section data-page="iot" data-title="IoT 관리">

<!-- ── 페이지 헤더 ── -->
<div class="page-header-row">
    <div>
        <h2 class="m-0 text-xl font-bold text-1"><i class="fa fa-microchip text-accent"></i> IoT 관리</h2>
        <p class="text-xs text-3 mt-1 m-0">SmartThings · Tuya IoT 장치를 통합 관리합니다.</p>
    </div>
    <div class="flex items-center gap-2">
        <?php if ($_isAdmin): ?>
        <button class="btn btn-outline btn-sm" id="iotBtnSync"><i class="fa fa-refresh"></i> 장치 동기화</button>
        <button class="btn btn-glass-primary btn-sm" id="iotBtnSettings"><i class="fa fa-cog"></i> 설정</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── 메인 탭 ── -->
<div class="ov-tabs">
    <button class="ov-tab-btn active" data-tab="dashboard"><i class="fa fa-tachometer"></i> 대시보드</button>
    <button class="ov-tab-btn" data-tab="devices"><i class="fa fa-cubes"></i> 장치현황</button>
    <button class="ov-tab-btn" data-tab="spaces"><i class="fa fa-map-marker"></i> 공간매핑</button>
    <button class="ov-tab-btn" data-tab="doorlock"><i class="fa fa-key"></i> 도어락</button>
    <button class="ov-tab-btn" data-tab="schedule"><i class="fa fa-clock-o"></i> 스케줄</button>
    <button class="ov-tab-btn" data-tab="logs"><i class="fa fa-history"></i> 로그</button>
</div>

<!-- ═══════ 대시보드 탭 ═══════ -->
<div class="ov-tab-pane active" id="iotTabDashboard">
    <!-- 요약 카드 -->
    <div class="iot-summary-grid" id="iotSummaryGrid">
        <div class="iot-summary-card">
            <div class="iot-summary-icon"><i class="fa fa-cubes"></i></div>
            <div class="iot-summary-count" id="iotTotalCount">-</div>
            <div class="iot-summary-label">전체 장치</div>
        </div>
        <div class="iot-summary-card">
            <div class="iot-summary-icon iot-summary-icon--success"><i class="fa fa-check-circle"></i></div>
            <div class="iot-summary-count" id="iotOnlineCount">-</div>
            <div class="iot-summary-label">온라인</div>
        </div>
        <div class="iot-summary-card">
            <div class="iot-summary-icon iot-summary-icon--danger"><i class="fa fa-times-circle"></i></div>
            <div class="iot-summary-count" id="iotOfflineCount">-</div>
            <div class="iot-summary-label">오프라인</div>
        </div>
        <div class="iot-summary-card">
            <div class="iot-summary-icon iot-summary-icon--warn"><i class="fa fa-key"></i></div>
            <div class="iot-summary-count" id="iotDoorlockCount">-</div>
            <div class="iot-summary-label">도어락</div>
        </div>
    </div>

    <!-- 플랫폼별 연결 상태 -->
    <div class="card py-3 px-4 mb-3">
        <h3 class="m-0 text-md font-bold text-1 mb-3">플랫폼 연결 상태</h3>
        <div class="flex gap-4 flex-wrap" id="iotPlatformStatus">
            <div class="flex items-center gap-2">
                <span class="ov-badge iot-badge-st">SmartThings</span>
                <span class="text-xs text-3" id="iotStStatus">확인 중...</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="ov-badge iot-badge-tuya">Tuya</span>
                <span class="text-xs text-3" id="iotTuyaStatus">확인 중...</span>
            </div>
        </div>
    </div>

    <!-- 최근 이벤트 -->
    <div class="card py-3 px-4">
        <h3 class="m-0 text-md font-bold text-1 mb-3">최근 이벤트</h3>
        <div id="iotRecentEvents">
            <div class="text-xs text-3 text-center p-4">이벤트 로딩 중...</div>
        </div>
    </div>
</div>

<!-- ═══════ 장치현황 탭 ═══════ -->
<div class="ov-tab-pane" id="iotTabDevices">
    <!-- 위치 필터 칩 -->
    <div class="flex gap-2 flex-wrap mb-3" id="iotLocationBar"></div>

    <div class="card flex-shrink-0 py-3 px-4 mb-3">
        <div class="flex items-center gap-2 flex-wrap">
            <div class="shv-search flex-1 max-w-400">
                <i class="fa fa-search shv-search-icon"></i>
                <input type="text" id="iotDeviceSearch" class="form-input" placeholder="장치명 / 유형 검색...">
            </div>
            <select class="form-select form-select-sm" id="iotDevicePlatformFilter">
                <option value="">전체 플랫폼</option>
                <option value="smartthings">SmartThings</option>
                <option value="tuya">Tuya</option>
            </select>
            <select class="form-select form-select-sm" id="iotDeviceStatusFilter">
                <option value="">전체 상태</option>
                <option value="online">온라인</option>
                <option value="offline">오프라인</option>
            </select>
            <span class="ov-count" id="iotDeviceCount"></span>
            <div class="flex items-center gap-2 ml-auto">
                <button class="btn btn-outline btn-sm iot-inv-toggle" id="iotInvToggle"><i class="fa fa-list"></i> 전체 장비</button>
                <div class="flex gap-1">
                    <button class="btn btn-outline btn-sm active" data-devview="card"><i class="fa fa-th-large"></i></button>
                    <button class="btn btn-outline btn-sm" data-devview="table"><i class="fa fa-list"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- 카드 뷰 -->
    <div class="iot-device-grid" id="iotDeviceGrid"></div>

    <!-- 테이블 뷰 -->
    <div class="card hidden" id="iotDeviceTableWrap">
        <div class="tbl-scroll">
            <table class="tbl tbl-sticky-header" id="iotDeviceTable">
                <colgroup>
                    <col><col class="col-80"><col class="col-100"><col class="col-80">
                    <col class="col-80"><col class="col-60"><col class="col-80">
                </colgroup>
                <thead><tr>
                    <th>장비명</th><th>타입</th><th>위치</th><th>방</th>
                    <th>상태</th><th>매핑</th><th>제어</th>
                </tr></thead>
                <tbody id="iotDeviceTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════ 공간매핑 탭 ═══════ -->
<div class="ov-tab-pane" id="iotTabSpaces">
    <!-- 위치 필터 (장치현황과 공유) -->
    <div class="flex gap-2 flex-wrap mb-3" id="iotSpaceLocationBar"></div>
    <div class="card flex-shrink-0 py-3 px-4 mb-3">
        <div class="flex items-center gap-2">
            <h3 class="m-0 text-md font-bold text-1">공간별 장치 배치</h3>
            <?php if ($_isAdmin): ?>
            <div class="ml-auto flex items-center gap-2">
                <button class="btn btn-outline btn-sm" id="iotBtnEditMode"><i class="fa fa-pencil"></i> 수정</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="iot-space-grid" id="iotSpaceGrid"></div>
</div>

<!-- ═══════ 도어락 탭 ═══════ -->
<div class="ov-tab-pane" id="iotTabDoorlock">
    <div class="card flex-shrink-0 py-3 px-4 mb-3">
        <div class="flex items-center gap-2 flex-wrap">
            <h3 class="m-0 text-md font-bold text-1">도어락 관리</h3>
            <span class="ov-count" id="iotDoorlockListCount"></span>
            <?php if ($_isAdmin): ?>
            <div class="ml-auto flex items-center gap-2">
                <button class="btn btn-outline btn-sm" id="iotBtnDoorlockSync"><i class="fa fa-refresh"></i> 동기화</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 도어락 서브 탭 -->
    <div class="ov-tabs mb-3">
        <button class="ov-tab-btn active" data-subtab="dl-list"><i class="fa fa-list"></i> 도어락 목록</button>
        <button class="ov-tab-btn" data-subtab="dl-users"><i class="fa fa-users"></i> 사용자 관리</button>
        <button class="ov-tab-btn" data-subtab="dl-temp"><i class="fa fa-key"></i> 임시 비밀번호</button>
        <button class="ov-tab-btn" data-subtab="dl-log"><i class="fa fa-history"></i> 출입 로그</button>
    </div>

    <div class="ov-tab-pane active" id="iotSubDlList">
        <div class="iot-doorlock-grid" id="iotDoorlockGrid">
            <div class="empty-state">
                <div class="empty-icon"><i class="fa fa-key"></i></div>
                <div class="empty-message">도어락 데이터 로딩 중...</div>
            </div>
        </div>
    </div>
    <div class="ov-tab-pane" id="iotSubDlUsers">
        <div class="card py-3 px-4">
            <div class="text-sm text-3 text-center p-4">도어락을 선택하면 사용자 목록이 표시됩니다.</div>
        </div>
    </div>
    <div class="ov-tab-pane" id="iotSubDlTemp">
        <div class="card py-3 px-4">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="m-0 text-md font-bold text-1">임시 비밀번호</h3>
                <?php if ($_isAdmin): ?>
                <button class="btn btn-primary btn-sm ml-auto" id="iotBtnAddTempPw"><i class="fa fa-plus"></i> 임시 비밀번호 생성</button>
                <?php endif; ?>
            </div>
            <div id="iotTempPwList">
                <div class="text-sm text-3 text-center p-4">도어락을 선택하세요.</div>
            </div>
        </div>
    </div>
    <div class="ov-tab-pane" id="iotSubDlLog">
        <div class="card py-3 px-4">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="m-0 text-md font-bold text-1">출입 로그</h3>
                <select class="form-select form-select-sm ml-auto" id="iotDlLogFilter">
                    <option value="">전체</option>
                    <option value="7">최근 7일</option>
                    <option value="30">최근 30일</option>
                </select>
            </div>
            <div id="iotDlLogList">
                <div class="text-sm text-3 text-center p-4">도어락을 선택하세요.</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════ 스케줄 탭 ═══════ -->
<div class="ov-tab-pane" id="iotTabSchedule">
    <div class="card py-3 px-4">
        <div class="flex items-center gap-2 mb-3">
            <h3 class="m-0 text-md font-bold text-1">자동화 스케줄</h3>
            <?php if ($_isAdmin): ?>
            <button class="btn btn-primary btn-sm ml-auto" id="iotBtnAddSchedule"><i class="fa fa-plus"></i> 스케줄 추가</button>
            <?php endif; ?>
        </div>
        <div id="iotScheduleList">
            <div class="empty-state">
                <div class="empty-icon"><i class="fa fa-clock-o"></i></div>
                <div class="empty-message">등록된 스케줄이 없습니다</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════ 로그 탭 ═══════ -->
<div class="ov-tab-pane" id="iotTabLogs">
    <div class="card py-3 px-4">
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            <h3 class="m-0 text-md font-bold text-1">IoT 이벤트 로그</h3>
            <!-- capability 필터 -->
            <div class="flex gap-1 flex-wrap" id="iotEventCapFilter">
                <button class="iot-loc-chip active" data-cap="">전체</button>
                <button class="iot-loc-chip" data-cap="button">🔔 초인종</button>
                <button class="iot-loc-chip" data-cap="motionSensor">👁️ 모션</button>
                <button class="iot-loc-chip" data-cap="contactSensor">🚪 접촉</button>
                <button class="iot-loc-chip" data-cap="presenceSensor">👤 출입</button>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <select class="form-select form-select-sm" id="iotLogPlatform">
                    <option value="">전체 플랫폼</option>
                    <option value="smartthings">SmartThings</option>
                    <option value="tuya">Tuya</option>
                </select>
                <select class="form-select form-select-sm" id="iotLogType">
                    <option value="">전체 유형</option>
                    <option value="event">이벤트</option>
                    <option value="command">명령 실행</option>
                </select>
            </div>
        </div>
        <div id="iotLogContainer">
            <div class="text-sm text-3 text-center p-4">로그 로딩 중...</div>
        </div>
    </div>
</div>

</section>

<script>
/* ════════════════════════════════════════
   SHVQ V2 — 통합 IoT 관리
   SmartThings + Tuya + 도어락 통합
   v2 — 테이블뷰, 이모지, 제어, 필터, 대시보드 상세
   ════════════════════════════════════════ */
(function(){
'use strict';

var IOT_API   = 'dist_process/saas/IntegrationIot.php';
var ROLE_LEVEL = <?= $_roleLevel ?>;
var TENANT_ID  = <?= $_tenantId ?>;
var IS_ADMIN   = ROLE_LEVEL <= 2;

var _devices  = [];
var _spaces   = [];
var _currentDoorlock = null;
var _currentLocation = '';
var _showFullInventory = true;
var _deviceView = 'card'; /* card | table */
var _docClickHandler = null;
var _pendingTimers = [];

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── 장치 타입별 이모지 ── */
var TYPE_EMOJI = {
    switch:'💡', light:'💡', dimmer:'💡', colorControl:'🌈',
    sensor:'📡', motionSensor:'👁️', contactSensor:'🚪', temperatureMeasurement:'🌡️',
    humiditySensor:'💧', presenceSensor:'👤', illuminanceMeasurement:'☀️',
    doorlock:'🔐', lock:'🔐',
    thermostat:'🌡️', airConditioner:'❄️', fan:'🌀',
    windowShade:'🪟', blinds:'🪟', curtain:'🪟',
    hub:'📦', bridge:'📦',
    button:'🔔', alarm:'🚨', siren:'🚨',
    camera:'📹', televisor:'📺', tv:'📺',
    outlet:'🔌', plug:'🔌', powerMeter:'⚡',
    speaker:'🔊', mediaPlayer:'🔊',
    washer:'🧺', dryer:'🧺', dishwasher:'🍽️',
    robot:'🤖', vacuum:'🤖',
    valve:'🚰', irrigator:'💦',
    garage:'🏠', garageDoor:'🏠',
};
function deviceEmoji(type) {
    if (!type) return '📦';
    var t = type.toLowerCase();
    for (var k in TYPE_EMOJI) { if (t.indexOf(k.toLowerCase()) >= 0) return TYPE_EMOJI[k]; }
    return '📦';
}

/* ── 상태 뱃지 HTML ── */
function stateBadge(state) {
    var s = (state||'unknown').toLowerCase();
    if (s === 'on' || s === 'online' || s === 'active' || s === 'locked' || s === 'open')
        return '<span class="ov-badge iot-state-on">'+esc(state)+'</span>';
    if (s === 'off' || s === 'offline' || s === 'inactive' || s === 'unlocked' || s === 'closed')
        return '<span class="ov-badge iot-state-off">'+esc(state)+'</span>';
    return '<span class="ov-badge iot-state-unknown">'+esc(state||'-')+'</span>';
}

/* ── API ── */
function iotApi(todo, data) { return SHV.api.post(IOT_API+'?todo='+todo, data||{}); }
function iotGet(todo, params) { return SHV.api.get(IOT_API, Object.assign({todo:todo}, params||{})); }

/* ── 대시보드 로드 ── */
function loadDashboard() {
    iotGet('dashboard').then(function(res) {
        if (!res.ok) return;
        var d = res.data || {};
        document.getElementById('iotTotalCount').textContent = d.total || 0;
        document.getElementById('iotOnlineCount').textContent = d.online || 0;
        document.getElementById('iotOfflineCount').textContent = d.offline || 0;
        document.getElementById('iotDoorlockCount').textContent = d.doorlock || 0;

        /* 플랫폼 상태 */
        document.getElementById('iotStStatus').textContent = d.smartthings_connected ? '연결됨' : '미연결';
        document.getElementById('iotTuyaStatus').textContent = d.tuya_connected ? '연결됨' : '미연결';

        /* 타입별 분포 뱃지 */
        var typeCounts = d.device_type_counts || {};
        var typeKeys = Object.keys(typeCounts).sort(function(a,b){ return typeCounts[b]-typeCounts[a]; });
        var existingChips = document.getElementById('iotTypeChips');
        if (existingChips) existingChips.remove();
        if (typeKeys.length) {
            var typeHtml = '<div id="iotTypeChips" class="flex flex-wrap gap-2 mt-2">';
            typeKeys.forEach(function(k){ typeHtml += '<span class="iot-type-chip">'+deviceEmoji(k)+' '+esc(k)+' <strong>'+typeCounts[k]+'</strong></span>'; });
            typeHtml += '</div>';
            var summaryEl = document.getElementById('iotSummaryGrid');
            if (summaryEl) summaryEl.insertAdjacentHTML('afterend', typeHtml);
        }

        /* 최근 이벤트 */
        var events = d.recent_events || [];
        var evBox = document.getElementById('iotRecentEvents');
        if (!events.length) { evBox.innerHTML = '<div class="text-xs text-3 text-center p-4">최근 이벤트 없음</div>'; return; }
        evBox.innerHTML = events.slice(0,10).map(function(ev) {
            return '<div class="iot-log-row">'
                + '<span class="iot-log-time">'+esc(ev.time||'')+'</span>'
                + '<span class="iot-log-event">'+esc(ev.device||'')+'</span>'
                + '<span class="iot-log-detail">'+esc(ev.message||'')+'</span>'
                + '</div>';
        }).join('');
    }).catch(function(){
        document.getElementById('iotRecentEvents').innerHTML = '<div class="text-xs text-3 text-center p-4">API 연결 실패</div>';
    });
}

/* ── 장치 목록 로드 ── */
function loadDevices() {
    iotGet('device_list').then(function(res) {
        if (!res.ok) { renderDevicesEmpty('장치 로딩 실패'); return; }
        _devices = (res.data && res.data.items) || res.data || [];
        buildLocationBar();
        renderDevices();
    }).catch(function(){ renderDevicesEmpty('API 연결 실패'); });
}

/* ── 위치 필터 칩 ── */
function buildLocationBar() {
    var bar = document.getElementById('iotLocationBar');
    if (!bar || !_devices.length) return;
    var locs = {};
    _devices.forEach(function(d) { var loc = d.location || d.location_name || ''; if (loc) { locs[loc] = (locs[loc]||0) + 1; } });
    var locNames = Object.keys(locs).sort();
    if (!locNames.length) { bar.innerHTML = ''; return; }
    var html = '<button class="iot-loc-chip'+(!_currentLocation?' active':'')+'" data-loc="">전체</button>';
    locNames.forEach(function(n) {
        html += '<button class="iot-loc-chip'+(n===_currentLocation?' active':'')+'" data-loc="'+esc(n)+'">'+esc(n)+' <span class="iot-loc-chip-count">'+locs[n]+'</span></button>';
    });
    bar.innerHTML = html;
}

/* ── 장치 필터링 ── */
function filterDevices() {
    var q = (document.getElementById('iotDeviceSearch').value||'').toLowerCase();
    var pf = document.getElementById('iotDevicePlatformFilter').value;
    var sf = document.getElementById('iotDeviceStatusFilter').value;
    return _devices.filter(function(d) {
        /* 위치 필터 */
        if (_currentLocation && (d.location||d.location_name||'') !== _currentLocation) return false;
        /* 인벤토리 토글: 전체 or 제어가능만 */
        if (!_showFullInventory && !d.is_ctrl) return false;
        /* hub 제외 */
        if ((d.device_type||d.type||'').toLowerCase() === 'hub') return false;
        /* 검색 */
        var label = (d.name||d.device_label||'').toLowerCase();
        var type = (d.device_type||d.type||'').toLowerCase();
        if (q && label.indexOf(q)<0 && type.indexOf(q)<0) return false;
        /* 플랫폼 */
        if (pf && d.platform !== pf) return false;
        /* 상태 */
        if (sf && d.status !== sf) return false;
        return true;
    });
}

function renderDevices() {
    var filtered = filterDevices();
    document.getElementById('iotDeviceCount').textContent = '총 '+_devices.length+'대'+(filtered.length!==_devices.length?' (필터 '+filtered.length+'대)':'');

    /* 인벤토리 토글 텍스트 */
    var toggleBtn = document.getElementById('iotInvToggle');
    if (toggleBtn) toggleBtn.innerHTML = _showFullInventory ? '<i class="fa fa-microchip"></i> 제어 장비만' : '<i class="fa fa-list"></i> 전체 장비';

    if (_deviceView === 'table') { renderDeviceTable(filtered); } else { renderDeviceCards(filtered); }
}

/* ── 카드 뷰 ── */
function renderDeviceCards(filtered) {
    document.getElementById('iotDeviceGrid').classList.remove('hidden');
    document.getElementById('iotDeviceTableWrap').classList.add('hidden');
    var grid = document.getElementById('iotDeviceGrid');
    if (!filtered.length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fa fa-cubes"></i></div><div class="empty-message">해당 조건의 장치가 없습니다</div></div>'; return; }
    grid.innerHTML = filtered.map(function(d) {
        var type = d.device_type || d.type || 'switch';
        var state = (d.last_state||d.status||'unknown').toLowerCase();
        var statusCls = d.status==='online'?'iot-device-status-online':'iot-device-status-offline';
        var platformBadge = (d.platform||'').indexOf('tuya')>=0?'<span class="ov-badge iot-badge-tuya">Tuya</span>':'<span class="ov-badge iot-badge-st">ST</span>';
        var emoji = deviceEmoji(type);
        var label = d.name || d.device_label || d.device_name || 'Unknown';
        var ctrlHtml = '';
        if (d.is_ctrl && IS_ADMIN) {
            if (state === 'off' || state === 'offline') ctrlHtml = '<button class="iot-cmd-on" data-action="device-cmd" data-idx="'+(d.idx||d.device_id)+'" data-cmd="on">ON</button>';
            else ctrlHtml = '<button class="iot-cmd-off" data-action="device-cmd" data-idx="'+(d.idx||d.device_id)+'" data-cmd="off">OFF</button>';
        }
        return '<div class="iot-device-card" data-action="device-detail" data-id="'+esc(d.device_id||d.external_id)+'">'
            + '<div class="iot-device-header">'
            + '<div class="iot-device-icon">'+emoji+'</div>'
            + '<div class="flex-1 min-w-0"><div class="iot-device-name">'+esc(label)+'</div>'
            + '<div class="iot-device-type">'+esc(type)+' '+platformBadge+'</div></div>'
            + stateBadge(state)
            + '</div>'
            + '<dl class="iot-device-meta">'
            + (d.location||d.location_name?'<dt>위치</dt><dd>'+esc(d.location||d.location_name)+'</dd>':'')
            + (d.room||d.room_name?'<dt>방</dt><dd>'+esc(d.room||d.room_name)+'</dd>':'')
            + (d.map_count>0?'<dt>매핑</dt><dd>'+d.map_count+'</dd>':'')
            + '</dl>'
            + '<div class="iot-device-actions">'
            + ctrlHtml
            + '<button class="btn btn-outline btn-sm" data-action="device-detail" data-id="'+esc(d.device_id||d.external_id)+'"><i class="fa fa-info-circle"></i> 상세</button>'
            + '</div></div>';
    }).join('');
}

/* ── 테이블 뷰 ── */
function renderDeviceTable(filtered) {
    document.getElementById('iotDeviceGrid').classList.add('hidden');
    document.getElementById('iotDeviceTableWrap').classList.remove('hidden');
    var tbody = document.getElementById('iotDeviceTbody');
    if (!filtered.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-3">해당 조건의 장비가 없습니다</td></tr>'; return; }
    tbody.innerHTML = filtered.map(function(d) {
        var type = d.device_type || d.type || 'switch';
        var state = (d.last_state||d.status||'unknown').toLowerCase();
        var label = d.name || d.device_label || d.device_name || 'Unknown';
        var ctrlHtml = '-';
        if (d.is_ctrl && IS_ADMIN) {
            if (state === 'off' || state === 'offline') ctrlHtml = '<button class="iot-cmd-on" data-action="device-cmd" data-idx="'+(d.idx||d.device_id)+'" data-cmd="on">ON</button>';
            else ctrlHtml = '<button class="iot-cmd-off" data-action="device-cmd" data-idx="'+(d.idx||d.device_id)+'" data-cmd="off">OFF</button>';
        }
        return '<tr class="iot-device-row'+(d.is_ctrl?'':' iot-device-row--readonly')+'" data-action="device-detail" data-id="'+esc(d.device_id||d.external_id)+'">'
            + '<td class="font-semibold">'+deviceEmoji(type)+' '+esc(label)+'</td>'
            + '<td class="text-xs">'+esc(type)+'</td>'
            + '<td class="text-xs">'+esc(d.location||d.location_name||'-')+'</td>'
            + '<td class="text-xs">'+esc(d.room||d.room_name||'-')+'</td>'
            + '<td>'+(d.is_ctrl ? stateBadge(state) : '<span class="text-xs text-3">'+esc(d.health_state||d.status||'-')+'</span>')+'</td>'
            + '<td>'+(d.map_count||0)+'</td>'
            + '<td>'+ctrlHtml+'</td></tr>';
    }).join('');
}

function renderDevicesEmpty(msg) {
    document.getElementById('iotDeviceGrid').innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fa fa-cubes"></i></div><div class="empty-message">'+esc(msg)+'</div></div>';
    document.getElementById('iotDeviceGrid').classList.remove('hidden');
    document.getElementById('iotDeviceTableWrap').classList.add('hidden');
}

/* ── 공간별 장비 (V1 방 단위 제어 카드) ── */
function loadSpaces() {
    if (!_devices.length) {
        loadDevices();
        _pendingTimers.push(setTimeout(function(){ renderSpaceCards(); }, 500));
        return;
    }
    renderSpaceCards();
}

function renderSpaceCards() {
    var grid = document.getElementById('iotSpaceGrid');
    if (!_devices.length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fa fa-map-marker"></i></div><div class="empty-message">장비를 동기화하면 위치/방 기준으로 표시됩니다</div></div>'; return; }

    /* 위치/방 트리 구성 (hub 제외) */
    var tree = {};
    _devices.forEach(function(d) {
        var type = (d.device_type||d.type||'').toLowerCase();
        if (type === 'hub') return;
        if (_currentLocation && (d.location||d.location_name||'') !== _currentLocation) return;
        var loc = d.location || d.location_name || '미지정';
        var room = d.room || d.room_name || '미지정';
        if (!tree[loc]) tree[loc] = {};
        if (!tree[loc][room]) tree[loc][room] = [];
        tree[loc][room].push(d);
    });

    if (!Object.keys(tree).length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fa fa-map-marker"></i></div><div class="empty-message">해당 위치에 장비가 없습니다</div></div>'; return; }

    var html = '';
    var locKeys = Object.keys(tree).sort(function(a,b){ if(a==='미지정') return 1; if(b==='미지정') return -1; return a.localeCompare(b); });

    locKeys.forEach(function(loc) {
        var rooms = tree[loc];
        var roomKeys = Object.keys(rooms).sort(function(a,b){ if(a==='미지정') return 1; if(b==='미지정') return -1; return a.localeCompare(b); });

        html += '<div class="iot-space-loc-header mb-2 mt-3 flex items-center justify-between">';
        html += '<span class="text-md font-bold text-accent"><i class="fa fa-map-marker mr-1"></i>'+esc(loc)+' <span class="text-xs text-3 font-normal">'+roomKeys.length+'개 방</span></span>';
        html += '</div>';

        roomKeys.forEach(function(room) {
            var devs = rooms[room];
            var roomId = loc + ':' + room;
            var ctrlDevs = devs.filter(function(d){ return d.is_ctrl; });

            html += '<div class="iot-room-card">';
            html += '<div class="iot-room-header">';
            html += '<span class="iot-room-name"><i class="fa fa-home text-3 mr-1"></i>'+esc(room)+' <span class="text-xs text-3 font-normal">'+devs.length+'</span></span>';
            if (IS_ADMIN && ctrlDevs.length > 1) {
                html += '<span class="flex gap-1">';
                html += '<button class="iot-cmd-on" data-action="room-all" data-room="'+esc(roomId)+'" data-cmd="on" >ALL ON</button>';
                html += '<button class="iot-cmd-off" data-action="room-all" data-room="'+esc(roomId)+'" data-cmd="off" >ALL OFF</button>';
                html += '</span>';
            }
            html += '</div>';

            html += '<div class="iot-room-grid">';
            devs.forEach(function(dev) {
                var type = dev.device_type || dev.type || 'switch';
                var state = (dev.last_state||'unknown').toLowerCase();
                var cmds = dev.cmds || [];
                var isOn = state === 'on' || state === 'open' || state === 'active';
                var isOff = state === 'off' || state === 'closed' || state === 'inactive';
                var isOffline = dev.status === 'offline';
                var isSensor = !dev.is_ctrl;
                var emoji = deviceEmoji(type);

                /* 상태 텍스트 */
                var stateText;
                if (isOffline) stateText = '오프라인';
                else if (isSensor) {
                    var stMap = {active:'감지됨',inactive:'대기',open:'열림',closed:'닫힘'};
                    stateText = stMap[state] || state || '대기';
                } else if (cmds.indexOf('open')>=0) stateText = isOn ? '열림' : '닫힘';
                else if (cmds.indexOf('lock')>=0) stateText = state==='locked'?'잠금':'해제';
                else stateText = isOn ? '켜짐' : '꺼짐';

                var cardCls = 'iot-room-device';
                if (isOffline) cardCls += ' is-offline';
                else if (isSensor && isOn) cardCls += ' is-sensor-active';
                else if (isOn) cardCls += ' is-on';
                else cardCls += ' is-off';

                html += '<div class="'+cardCls+'" data-action="device-detail" data-id="'+esc(dev.device_id||dev.external_id)+'" title="'+esc(dev.name||dev.device_label||'')+'">';

                /* 이모지 + 전원버튼 */
                html += '<div class="flex items-center justify-between w-full mb-1">';
                html += '<span class="iot-room-device-emoji">'+emoji+'</span>';
                if (dev.is_ctrl && IS_ADMIN && !isOffline) {
                    var cmdVal, cmdLabel;
                    if (cmds.indexOf('open')>=0) { cmdVal = isOn?'close':'open'; cmdLabel = isOn?'닫기':'열기'; }
                    else if (cmds.indexOf('lock')>=0) { cmdVal = state==='locked'?'unlock':'lock'; cmdLabel = state==='locked'?'해제':'잠금'; }
                    else { cmdVal = isOn?'off':'on'; }
                    html += '<button class="iot-room-device-pwr" data-action="device-cmd" data-idx="'+(dev.idx||dev.device_id)+'" data-cmd="'+cmdVal+'" onclick="event.stopPropagation()"><i class="fa fa-power-off"></i></button>';
                } else if (isSensor) {
                    html += '<div class="iot-sensor-dot'+(isOn?' active':'')+'"></div>';
                }
                html += '</div>';

                /* 라벨 + 상태 */
                html += '<div class="iot-room-device-label">'+esc(dev.name||dev.device_label||'Unknown')+'</div>';
                html += '<div class="iot-room-device-state">'+stateText+'</div>';

                html += '</div>';
            });
            html += '</div></div>';
        });
    });
    grid.innerHTML = html;
}

/* ── 도어락 목록 ── */
function loadDoorlocks() {
    var doorlocks = _devices.filter(function(d){ var t=(d.device_type||d.type||'').toLowerCase(); return t==='doorlock'||t==='lock'||t==='access_control'; });
    document.getElementById('iotDoorlockListCount').textContent = doorlocks.length+'대';
    var grid = document.getElementById('iotDoorlockGrid');
    if (!doorlocks.length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fa fa-key"></i></div><div class="empty-message">도어락 장치가 없습니다</div></div>'; return; }
    grid.innerHTML = doorlocks.map(function(d) {
        var statusCls = d.status==='online'?'iot-device-status-online':'iot-device-status-offline';
        return '<div class="iot-doorlock-card">'
            + '<div class="iot-doorlock-header">'
            + '<div class="iot-doorlock-icon"><i class="fa fa-key"></i></div>'
            + '<div class="flex-1 min-w-0"><div class="font-bold text-1 truncate">'+esc(d.name)+'</div>'
            + '<div class="text-xs text-3">'+esc(d.room||d.location||'위치 미지정')+'</div></div>'
            + '<span class="iot-device-status '+statusCls+'">'+(d.status==='online'?'온라인':'오프라인')+'</span>'
            + '</div>'
            + '<div class="iot-doorlock-body">'
            + '<div class="text-xs text-3">플랫폼: '+(d.platform==='tuya'?'<span class="ov-badge iot-badge-tuya">Tuya</span>':'<span class="ov-badge iot-badge-st">SmartThings</span>')+'</div>'
            + (d.battery?'<div class="text-xs text-3 mt-1">배터리: '+esc(d.battery)+'%</div>':'')
            + '</div>'
            + '<div class="iot-doorlock-actions">'
            + '<button class="btn btn-primary btn-sm" data-action="doorlock-select" data-id="'+esc(d.device_id)+'"><i class="fa fa-key"></i> 관리</button>'
            + '<button class="btn btn-outline btn-sm" data-action="doorlock-log" data-id="'+esc(d.device_id)+'"><i class="fa fa-history"></i> 출입로그</button>'
            + '</div></div>';
    }).join('');
}

/* ── 편집모드 + 드래그 정렬 (SortableJS) ── */
var _editMode = false;
var _sortableInstances = [];
var _eventCapFilter = '';

function initCardDragDrop() {
    _sortableInstances.forEach(function(s) { try { s.destroy(); } catch(e){} });
    _sortableInstances = [];
    if (!_editMode || typeof Sortable === 'undefined') return;
    document.querySelectorAll('.iot-room-grid').forEach(function(grid) {
        _sortableInstances.push(new Sortable(grid, {
            animation: 200,
            ghostClass: 'iot-sort-ghost',
            chosenClass: 'iot-sort-chosen',
            dragClass: 'iot-sort-drag',
            onEnd: function(evt) {
                /* 순서 저장 (백엔드 연동 시 API 호출) */
                var items = Array.from(evt.from.children);
                items.forEach(function(el, i) {
                    var did = el.dataset.didx || el.querySelector('[data-idx]')?.dataset.idx;
                    if (did) { /* 향후 sort_order API 호출 */ }
                });
            }
        }));
    });
}

/* ── 공간매핑 위치 칩 빌드 ── */
function buildSpaceLocationBar() {
    var bar = document.getElementById('iotSpaceLocationBar');
    if (!bar || !_devices.length) return;
    var locs = {};
    _devices.forEach(function(d) { var loc = d.location || d.location_name || ''; if (loc) locs[loc] = (locs[loc]||0)+1; });
    var locNames = Object.keys(locs).sort();
    if (!locNames.length) { bar.innerHTML = ''; return; }
    var html = '<button class="iot-loc-chip'+(!_currentLocation?' active':'')+'" data-loc="">전체</button>';
    locNames.forEach(function(n) {
        html += '<button class="iot-loc-chip'+(n===_currentLocation?' active':'')+'" data-loc="'+esc(n)+'">'+esc(n)+' <span class="iot-loc-chip-count">'+locs[n]+'</span></button>';
    });
    bar.innerHTML = html;
}

/* ── 로그 상세 로드 (V1 capability 필터 + 명령/이벤트 분류) ── */
function loadLogs() {
    var platform = document.getElementById('iotLogPlatform').value;
    var logType = (document.getElementById('iotLogType') || {}).value || '';
    var params = {platform: platform, limit: 100};
    if (_eventCapFilter) params.capability = _eventCapFilter;
    if (logType) params.log_type = logType;

    iotGet('event_log', params).then(function(res) {
        if (!res.ok) return;
        var logs = (res.data && res.data.items) || res.data || [];
        var box = document.getElementById('iotLogContainer');
        if (!logs.length) { box.innerHTML = '<div class="text-xs text-3 text-center p-4">로그 없음</div>'; return; }

        /* device_id → 장비명 매핑 */
        var devMap = {};
        _devices.forEach(function(d) { if (d.device_id || d.external_id) devMap[d.device_id || d.external_id] = d; });

        var eventEmojis = {pushed:'🔔',held:'🔔',double:'🔔',active:'👁️',inactive:'👁️',open:'🚪',closed:'🚪',detected:'📡',on:'💡',off:'💡'};
        box.innerHTML = '<table class="tbl"><thead><tr><th class="col-140">시간</th><th>장치</th><th class="col-100">유형</th><th class="col-80">값</th><th class="col-120">위치</th></tr></thead><tbody>'
            + logs.map(function(l) {
                var val = l.value || l.event_value || l.message || '';
                var cap = l.capability || l.event_capability || l.device_name || '';
                var em = eventEmojis[val] || eventEmojis[cap] || '📡';
                var valColor = (val==='active'||val==='pushed'||val==='open'||val==='on'||val==='detected') ? 'var(--danger)' : 'var(--success)';
                var dev = devMap[l.device_external_id || l.device_id || ''] || {};
                var devLabel = l.device_label || dev.name || dev.device_label || cap || '-';
                return '<tr>'
                    + '<td class="text-xs ov-font-mono whitespace-nowrap">'+esc(l.time||l.created_at||l.event_timestamp||'-')+'</td>'
                    + '<td class="font-semibold">'+em+' '+esc(devLabel)+'</td>'
                    + '<td class="text-xs">'+esc(cap)+'</td>'
                    + '<td><span class="iot-log-val '+(valColor.indexOf('danger')>=0?'iot-log-val--active':'iot-log-val--inactive')+'">'+esc(val)+'</span></td>'
                    + '<td class="text-xs text-3">'+esc((dev.location||dev.location_name||'')+' '+(dev.room||dev.room_name||''))+'</td>'
                    + '</tr>';
            }).join('')+'</tbody></table>';
    }).catch(function(){});
}

/* ── 탭 전환 ── */
function switchTab(tabName) {
    document.querySelectorAll('[data-page="iot"] .ov-tab-btn[data-tab]').forEach(function(b){ b.classList.toggle('active', b.dataset.tab===tabName); });
    ['dashboard','devices','spaces','doorlock','schedule','logs'].forEach(function(t) {
        var pane = document.getElementById('iotTab'+t.charAt(0).toUpperCase()+t.slice(1));
        if (pane) pane.classList.toggle('active', t===tabName);
    });
    /* 탭 진입 시 데이터 로드 */
    if (tabName==='devices' && !_devices.length) loadDevices();
    if (tabName==='spaces') { buildSpaceLocationBar(); loadSpaces(); }
    if (tabName==='doorlock') loadDoorlocks();
    if (tabName==='logs') loadLogs();
}

/* 도어락 서브탭 */
function switchSubTab(subTabName) {
    document.querySelectorAll('[data-subtab]').forEach(function(b){ b.classList.toggle('active', b.dataset.subtab===subTabName); });
    ['dl-list','dl-users','dl-temp','dl-log'].forEach(function(t) {
        var id = 'iotSub'+t.split('-').map(function(w){return w.charAt(0).toUpperCase()+w.slice(1);}).join('');
        var pane = document.getElementById(id);
        if (pane) pane.classList.toggle('active', t===subTabName);
    });
}

/* ── 이벤트 바인딩 ── */
function bindEvents() {
    /* 메인 탭 */
    document.querySelectorAll('[data-page="iot"] .ov-tab-btn[data-tab]').forEach(function(btn){
        btn.addEventListener('click', function(){ switchTab(this.dataset.tab); });
    });
    /* 도어락 서브탭 */
    document.querySelectorAll('[data-subtab]').forEach(function(btn){
        btn.addEventListener('click', function(){ switchSubTab(this.dataset.subtab); });
    });
    /* 검색/필터 */
    var searchInput = document.getElementById('iotDeviceSearch');
    if (searchInput) searchInput.addEventListener('input', renderDevices);
    var pfFilter = document.getElementById('iotDevicePlatformFilter');
    if (pfFilter) pfFilter.addEventListener('change', renderDevices);
    var sfFilter = document.getElementById('iotDeviceStatusFilter');
    if (sfFilter) sfFilter.addEventListener('change', renderDevices);

    /* 위치 필터 칩 (위임) */
    document.getElementById('iotLocationBar').addEventListener('click', function(e) {
        var chip = e.target.closest('.iot-loc-chip');
        if (!chip) return;
        _currentLocation = chip.dataset.loc || '';
        document.querySelectorAll('.iot-loc-chip').forEach(function(c){ c.classList.toggle('active', (c.dataset.loc||'') === _currentLocation); });
        renderDevices();
    });

    /* 인벤토리 토글 */
    var invToggle = document.getElementById('iotInvToggle');
    if (invToggle) invToggle.addEventListener('click', function(){ _showFullInventory = !_showFullInventory; renderDevices(); });

    /* 뷰 전환 (카드/테이블) */
    document.querySelectorAll('[data-devview]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            _deviceView = this.dataset.devview;
            document.querySelectorAll('[data-devview]').forEach(function(b){ b.classList.toggle('active', b.dataset.devview === _deviceView); });
            renderDevices();
        });
    });
    /* 로그 필터 */
    var logPf = document.getElementById('iotLogPlatform');
    if (logPf) logPf.addEventListener('change', loadLogs);
    var logType = document.getElementById('iotLogType');
    if (logType) logType.addEventListener('change', loadLogs);
    /* capability 필터 칩 */
    var capFilter = document.getElementById('iotEventCapFilter');
    if (capFilter) capFilter.addEventListener('click', function(e) {
        var chip = e.target.closest('.iot-loc-chip');
        if (!chip) return;
        _eventCapFilter = chip.dataset.cap || '';
        capFilter.querySelectorAll('.iot-loc-chip').forEach(function(c){ c.classList.toggle('active', (c.dataset.cap||'') === _eventCapFilter); });
        loadLogs();
    });

    /* 편집모드 토글 */
    var editModeBtn = document.getElementById('iotBtnEditMode');
    if (editModeBtn) editModeBtn.addEventListener('click', function() {
        _editMode = !_editMode;
        this.innerHTML = _editMode ? '<i class="fa fa-check"></i> 완료' : '<i class="fa fa-pencil"></i> 수정';
        this.classList.toggle('btn-danger', _editMode);
        renderSpaceCards();
        if (_editMode) initCardDragDrop();
    });

    /* 공간매핑 위치 칩 */
    var spaceLocBar = document.getElementById('iotSpaceLocationBar');
    if (spaceLocBar) spaceLocBar.addEventListener('click', function(e) {
        var chip = e.target.closest('.iot-loc-chip');
        if (!chip) return;
        _currentLocation = chip.dataset.loc || '';
        this.querySelectorAll('.iot-loc-chip').forEach(function(c){ c.classList.toggle('active', (c.dataset.loc||'') === _currentLocation); });
        renderSpaceCards();
    });

    /* 동기화 */
    var syncBtn = document.getElementById('iotBtnSync');
    if (syncBtn) syncBtn.addEventListener('click', function() {
        SHV.toast.info('장치 동기화 중...');
        iotApi('sync_devices').then(function(res) {
            if (res.ok) { SHV.toast.success('동기화 완료'); loadDashboard(); loadDevices(); }
            else SHV.toast.error(res.message||'동기화 실패');
        });
    });
    /* 설정 */
    var settingsBtn = document.getElementById('iotBtnSettings');
    if (settingsBtn) settingsBtn.addEventListener('click', function() {
        SHV.modal.open(IOT_API+'?todo=settings_form', 'IoT 설정', 'lg');
    });

    /* 위임 이벤트 (named reference → destroy에서 제거) */
    _docClickHandler = function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;
        var id = btn.dataset.id;
        if (action === 'doorlock-select') {
            _currentDoorlock = id;
            var dlDev = _devices.find(function(x){ return (x.device_id||x.external_id)===id; });
            SHV.toast.info('도어락 선택: '+(dlDev?dlDev.name||dlDev.device_label:id));
            switchSubTab('dl-users');
            loadDoorlockUsers(id);
            loadDoorlockTempPw(id);
        } else if (action === 'doorlock-log') {
            _currentDoorlock = id;
            switchSubTab('dl-log');
            loadDoorlockLog(id);
        } else if (action === 'device-detail') {
            showDeviceDetail(id);
        } else if (action === 'device-cmd') {
            e.stopPropagation();
            var idx = btn.dataset.idx;
            var cmd = btn.dataset.cmd;
            SHV.toast.info(cmd.toUpperCase() + ' 명령 전송 중...');
            iotApi('device_cmd', {device_idx: idx, command: cmd}).then(function(res) {
                if (res.ok) { SHV.toast.success(cmd.toUpperCase() + ' 완료'); loadDevices(); }
                else {
                    var msg = res.message || '명령 실패';
                    if (IS_ADMIN && res.detail) {
                        var d = res.detail;
                        if (d.error) msg += ' (' + d.error + ')';
                        if (d.token_debug) msg += ' [토큰: ' + d.token_debug + ']';
                    }
                    SHV.toast.error(msg);
                }
            });
        } else if (action === 'room-all') {
            e.stopPropagation();
            roomAllCmd(btn.dataset.room, btn.dataset.cmd);
        }
    };
    document.addEventListener('click', _docClickHandler);
}

/* ── 도어락 사용자 관리 ── */
function loadDoorlockUsers(deviceId) {
    iotGet('doorlock_users', {device_id:deviceId}).then(function(res) {
        var box = document.getElementById('iotSubDlUsers').querySelector('.card');
        if (!res.ok || !res.data) { box.innerHTML = '<div class="text-sm text-3 text-center p-4">사용자 데이터 없음</div>'; return; }
        var users = res.data.items || res.data || [];
        var addBtn = IS_ADMIN ? '<div class="flex justify-end mb-3"><button class="btn btn-primary btn-sm" data-action="dl-add-user"><i class="fa fa-plus"></i> 사용자 추가</button></div>' : '';
        if (!users.length) { box.innerHTML = addBtn+'<div class="text-sm text-3 text-center p-4">등록된 사용자 없음</div>'; return; }
        box.innerHTML = addBtn+'<table class="tbl tbl-sticky-header"><thead><tr><th>이름</th><th>ID</th><th>인증방식</th><th>상태</th>'+(IS_ADMIN?'<th>작업</th>':'')+'</tr></thead><tbody>'
            + users.map(function(u){
                return '<tr><td class="font-semibold">'+esc(u.name||u.user_name||'-')+'</td>'
                    + '<td class="text-xs ov-font-mono">'+esc(u.user_id||u.uid||'-')+'</td>'
                    + '<td class="text-xs">'+esc(u.auth_type||u.credential_type||'-')+'</td>'
                    + '<td>'+stateBadge(u.status||'active')+'</td>'
                    + (IS_ADMIN?'<td><button class="btn btn-danger btn-sm" data-action="dl-remove-user" data-uid="'+esc(u.user_id||u.uid)+'"><i class="fa fa-trash"></i></button></td>':'')
                    + '</tr>';
            }).join('')+'</tbody></table>';
    }).catch(function(){ document.getElementById('iotSubDlUsers').querySelector('.card').innerHTML = '<div class="text-sm text-3 text-center p-4">API 연결 실패</div>'; });
}

/* ── 도어락 임시 비밀번호 ── */
function loadDoorlockTempPw(deviceId) {
    iotGet('doorlock_temp_passwords', {device_id:deviceId}).then(function(res) {
        var box = document.getElementById('iotTempPwList');
        var items = (res.ok && res.data) ? (res.data.items || res.data || []) : [];
        if (!items.length) { box.innerHTML = '<div class="text-sm text-3 text-center p-4">임시 비밀번호 없음</div>'; return; }
        box.innerHTML = '<table class="tbl"><thead><tr><th>ID</th><th>이름</th><th>유형</th><th>유효기간</th><th>상태</th>'+(IS_ADMIN?'<th>작업</th>':'')+'</tr></thead><tbody>'
            + items.map(function(pw){
                return '<tr><td class="text-xs ov-font-mono">'+esc(pw.password_id||pw.id||'-')+'</td>'
                    + '<td class="font-semibold">'+esc(pw.name||'-')+'</td>'
                    + '<td class="text-xs">'+esc(pw.type||'임시')+'</td>'
                    + '<td class="text-xs">'+esc(pw.effective_time||'-')+' ~ '+esc(pw.invalid_time||'-')+'</td>'
                    + '<td>'+stateBadge(pw.status||'active')+'</td>'
                    + (IS_ADMIN?'<td><button class="btn btn-danger btn-sm" data-action="dl-delete-pw" data-pwid="'+esc(pw.password_id||pw.id)+'"><i class="fa fa-trash"></i></button></td>':'')
                    + '</tr>';
            }).join('')+'</tbody></table>';
    }).catch(function(){ document.getElementById('iotTempPwList').innerHTML = '<div class="text-sm text-3 text-center p-4">API 연결 실패</div>'; });
}

/* ── 도어락 출입 로그 ── */
function loadDoorlockLog(deviceId) {
    var days = document.getElementById('iotDlLogFilter').value || '';
    iotGet('doorlock_log', {device_id:deviceId, days:days}).then(function(res) {
        var box = document.getElementById('iotDlLogList');
        var logs = (res.ok && res.data) ? (res.data.items || res.data || []) : [];
        if (!logs.length) { box.innerHTML = '<div class="text-sm text-3 text-center p-4">출입 기록 없음</div>'; return; }
        box.innerHTML = '<table class="tbl"><thead><tr><th>시간</th><th>해제 방식</th><th>인증키</th><th>사용자</th></tr></thead><tbody>'
            + logs.map(function(l){
                return '<tr><td class="text-xs ov-font-mono whitespace-nowrap">'+esc(l.time||l.created_at||'-')+'</td>'
                    + '<td class="text-xs">'+esc(l.unlock_method||l.action||'-')+'</td>'
                    + '<td class="text-xs ov-font-mono">'+esc(l.auth_key||'-')+'</td>'
                    + '<td class="font-semibold">'+esc(l.user_name||l.name||'-')+'</td></tr>';
            }).join('')+'</tbody></table>';
    }).catch(function(){ document.getElementById('iotDlLogList').innerHTML = '<div class="text-sm text-3 text-center p-4">API 연결 실패</div>'; });
}

/* ── 장치 상세 모달 ── */
function showDeviceDetail(deviceId) {
    var d = _devices.find(function(x){ return (x.device_id||x.external_id) === deviceId; });
    if (!d) return;
    var type = d.device_type || d.type || 'device';
    var label = d.name || d.device_label || d.device_name || 'Unknown';
    var cmds = d.cmds || [];
    var html = '<div class="iot-detail-grid">'
        + '<dt>장치명</dt><dd>'+deviceEmoji(type)+' '+esc(label)+'</dd>'
        + '<dt>유형</dt><dd>'+esc(type)+'</dd>'
        + '<dt>플랫폼</dt><dd>'+esc(d.platform||d.adapter||'-')+'</dd>'
        + '<dt>상태</dt><dd>'+stateBadge(d.last_state||d.status||'unknown')+'</dd>'
        + '<dt>위치</dt><dd>'+esc(d.location||d.location_name||'-')+'</dd>'
        + '<dt>방</dt><dd>'+esc(d.room||d.room_name||'-')+'</dd>'
        + (d.manufacturer?'<dt>제조사</dt><dd>'+esc(d.manufacturer)+'</dd>':'')
        + (d.model?'<dt>모델</dt><dd>'+esc(d.model)+'</dd>':'')
        + (d.firmware?'<dt>펌웨어</dt><dd>'+esc(d.firmware)+'</dd>':'')
        + (d.last_event?'<dt>최근 이벤트</dt><dd>'+esc(d.last_event)+'</dd>':'')
        + (cmds.length?'<dt>명령어</dt><dd>'+cmds.map(function(c){ return '<span class="ov-badge ov-badge-blue mr-1">'+esc(c)+'</span>'; }).join('')+'</dd>':'')
        + '<dt>제어 가능</dt><dd>'+(d.is_ctrl?'<span class="ov-badge ov-badge-green">예</span>':'<span class="ov-badge ov-badge-gray">아니오</span>')+'</dd>'
        + '<dt>매핑 수</dt><dd>'+(d.map_count||0)+'</dd>'
        + '</div>';
    if (IS_ADMIN && d.is_ctrl && cmds.length) {
        html += '<div class="iot-detail-actions flex gap-2 mt-4 pt-3">';
        cmds.forEach(function(c) {
            var cls = (c==='on'||c==='open'||c==='unlock') ? 'iot-cmd-on' : 'iot-cmd-off';
            html += '<button class="'+cls+'" data-action="device-cmd" data-idx="'+(d.idx||d.device_id)+'" data-cmd="'+esc(c)+'">'+esc(c.toUpperCase())+'</button>';
        });
        html += '</div>';
    }
    SHV.modal.openHtml(html, label+' 상세', 'md');
}

/* ── 방 전체 제어 (ALL ON / ALL OFF) ── */
function roomAllCmd(roomId, cmd) {
    var parts = roomId.split(':');
    var loc = parts[0], room = parts[1];
    var targets = _devices.filter(function(d) {
        return (d.location||d.location_name||'미지정') === loc
            && (d.room||d.room_name||'미지정') === room
            && d.is_ctrl;
    });
    if (!targets.length) { SHV.toast.warn('제어 가능한 장치가 없습니다'); return; }
    SHV.toast.info(room + ' ' + cmd.toUpperCase() + ' (' + targets.length + '대)...');
    var done = 0;
    targets.forEach(function(d, i) {
        _pendingTimers.push(setTimeout(function() {
            iotApi('device_cmd', {device_idx: d.idx||d.device_id, command: cmd}).then(function() {
                done++;
                if (done >= targets.length) { SHV.toast.success(room + ' ' + cmd.toUpperCase() + ' 완료'); loadDevices(); renderSpaceCards(); }
            });
        }, i * 200));
    });
}

/* ── SHV.pages 라이프사이클 ── */
SHV.pages.iot = {
    init: function() {
        bindEvents();
        loadDashboard();
        loadDevices();
    },
    destroy: function() {
        if (_docClickHandler) { document.removeEventListener('click', _docClickHandler); _docClickHandler = null; }
        _pendingTimers.forEach(function(t){ clearTimeout(t); });
        _pendingTimers = [];
        _sortableInstances.forEach(function(s){ try{s.destroy();}catch(e){} });
        _sortableInstances = [];
        _devices = [];
        _spaces = [];
        _currentDoorlock = null;
        _currentLocation = '';
        _showFullInventory = true;
        _deviceView = 'card';
        _editMode = false;
        _eventCapFilter = '';
    }
};

})();
</script>
