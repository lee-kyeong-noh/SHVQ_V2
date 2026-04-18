<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/saas/GroupwareService.php';

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="emp"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$security = require __DIR__ . '/../../../config/security.php';
$service  = new GroupwareService(DbConnection::get(), $security);
$scope    = $service->resolveScope($context, '', 0);
$userPk   = (int)($context['user_pk'] ?? 0);

$missing = $service->missingTables($service->requiredTablesByDomain('all'));
$ready   = $missing === [];
$counts  = [];
if ($ready) {
    $summary = $service->dashboardSummary($scope, $userPk);
    $counts  = $summary['counts'] ?? [];
}

$cards = [
    ['icon' => 'fa-sitemap',        'color' => 'blue',   'key' => 'department_count',         'label' => '부서',           'nav' => 'org_chart'],
    ['icon' => 'fa-users',          'color' => 'green',  'key' => 'employee_count',            'label' => '임직원',         'nav' => 'org_chart'],
    ['icon' => 'fa-book',           'color' => 'teal',   'key' => 'phonebook_count',           'label' => '연락처',         'nav' => 'org_chart_card'],
    ['icon' => 'fa-calendar',       'color' => 'orange', 'key' => 'holiday_requested_count',   'label' => '휴가 신청중',    'nav' => 'holiday'],
    ['icon' => 'fa-clock-o',        'color' => 'purple', 'key' => 'overtime_requested_count',  'label' => '초과근무 신청중','nav' => 'work_overtime'],
    ['icon' => 'fa-file-text-o',    'color' => 'red',    'key' => 'approval_pending_my_count', 'label' => '결재 대기',      'nav' => 'approval_req'],
    ['icon' => 'fa-comment',        'color' => 'cyan',   'key' => 'chat_unread_count',         'label' => '읽지 않은 메시지','nav' => 'chat'],
    ['icon' => 'fa-check-circle-o', 'color' => 'gray',   'key' => 'attendance_today_count',    'label' => '오늘 출근',      'nav' => 'attitude'],
];
?>
<section data-page="emp">
    <div class="page-header">
        <h2 class="page-title" data-title="그룹웨어">그룹웨어</h2>
        <p class="page-subtitle">업무 협업 · HR · 전자결재</p>
    </div>

    <?php if (!$ready): ?>
    <div class="card card-mt card-mb">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-message">그룹웨어 DB 테이블이 준비되지 않았습니다. (미싱: <?= implode(', ', $missing) ?>)</p>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- 요약 카드 -->
    <div class="grp-home-grid card-mt">
        <?php foreach ($cards as $c): ?>
        <button class="card grp-stat-card" data-grp-nav="<?= $c['nav'] ?>">
            <div class="grp-stat-icon grp-stat-icon--<?= $c['color'] ?>">
                <i class="fa <?= $c['icon'] ?>"></i>
            </div>
            <div class="grp-stat-val"><?= number_format((int)($counts[$c['key']] ?? 0)) ?></div>
            <div class="grp-stat-label"><?= $c['label'] ?></div>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- 바로가기 -->
    <div class="card card-mt card-mb">
        <div class="card-header"><span>바로가기</span></div>
        <div class="card-body">
            <div class="grp-shortcut-row">
                <button class="btn btn-outline" data-grp-nav="org_chart"><i class="fa fa-sitemap mr-1"></i>조직도</button>
                <button class="btn btn-outline" data-grp-nav="org_chart_card"><i class="fa fa-book mr-1"></i>주소록</button>
                <button class="btn btn-outline" data-grp-nav="holiday"><i class="fa fa-calendar mr-1"></i>휴가신청</button>
                <button class="btn btn-outline" data-grp-nav="work_overtime"><i class="fa fa-clock-o mr-1"></i>초과근무</button>
                <button class="btn btn-outline" data-grp-nav="attitude"><i class="fa fa-list-alt mr-1"></i>근태현황</button>
                <button class="btn btn-outline" data-grp-nav="approval_req"><i class="fa fa-file-text-o mr-1"></i>전자결재</button>
                <button class="btn btn-outline" data-grp-nav="chat"><i class="fa fa-comment mr-1"></i>채팅</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</section>
<style>
.grp-home-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: var(--sp-3);
    padding: 0 var(--sp-5);
}
.grp-stat-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--sp-5) var(--sp-4);
    text-align: center;
    border: none;
    cursor: pointer;
    border-radius: var(--radius-lg);
    min-height: 120px;
    color: var(--text-1);
    transition: box-shadow var(--duration-fast) var(--ease-default),
                transform var(--duration-fast) var(--ease-default);
    width: 100%;
}
.grp-stat-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
.grp-stat-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    margin-bottom: var(--sp-2);
}
.grp-stat-icon--blue   { background: rgba(41,82,163,.12);  color: var(--accent); }
.grp-stat-icon--green  { background: rgba(34,197,94,.12);  color: #22c55e; }
.grp-stat-icon--teal   { background: rgba(20,184,166,.12); color: #14b8a6; }
.grp-stat-icon--orange { background: rgba(234,88,12,.12);  color: #ea580c; }
.grp-stat-icon--purple { background: rgba(139,92,246,.12); color: #8b5cf6; }
.grp-stat-icon--red    { background: rgba(239,68,68,.12);  color: var(--danger); }
.grp-stat-icon--cyan   { background: rgba(6,182,212,.12);  color: #06b6d4; }
.grp-stat-icon--gray   { background: rgba(100,116,139,.12);color: var(--text-3); }
.grp-stat-val   { font-size: 28px; font-weight: 700; line-height: 1; color: var(--text-1); }
.grp-stat-label { font-size: 12px; color: var(--text-3); margin-top: var(--sp-1); }
.grp-shortcut-row { display: flex; flex-wrap: wrap; gap: var(--sp-2); }
@media (max-width: 768px) {
    .grp-home-grid { grid-template-columns: repeat(2, 1fr); padding: 0 var(--sp-3); }
}
</style>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="emp"]');
    if (!_section) return;

    _section.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-grp-nav]');
        if (btn) SHV.router.navigate(btn.dataset.grpNav);
    });

    SHV.pages = SHV.pages || {};
    SHV.pages['emp'] = { destroy: function () {} };
})();
</script>
