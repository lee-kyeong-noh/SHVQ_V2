<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/MaterialService.php';

/* ── 헬퍼 ── */
function matListH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function matListTableExists(PDO $db, string $tableName): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=?");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

/** 카테고리 flat 배열 → idx:경로문자열 맵 */
function matListBuildCategoryPaths(array $flatRows): array
{
    $byIdx = [];
    foreach ($flatRows as $row) {
        $byIdx[(int)$row['idx']] = $row;
    }
    $paths = [];
    foreach ($flatRows as $row) {
        $path  = [];
        $cur   = $row;
        $limit = 8;
        while ($cur && $limit-- > 0) {
            array_unshift($path, matListH((string)($cur['name'] ?? '')));
            $pid = (int)($cur['parent_idx'] ?? 0);
            $cur = $pid > 0 ? ($byIdx[$pid] ?? null) : null;
        }
        $paths[(int)$row['idx']] = implode(' &rsaquo; ', $path);
    }
    return $paths;
}

/** 카테고리 트리 HTML 재귀 렌더링 (토글·카운트·CRUD·드래그 지원) */
function matListRenderTree(array $allCats, int $parentIdx, int $activeCatIdx, array $countMap): string
{
    $html = '';
    foreach ($allCats as $cat) {
        if ((int)($cat['parent_idx'] ?? 0) !== $parentIdx) {
            continue;
        }
        $idx    = (int)$cat['idx'];
        $pIdx   = (int)($cat['parent_idx'] ?? 0);
        $depth  = (int)($cat['depth'] ?? 1);
        $name   = matListH((string)($cat['name'] ?? ''));
        $count  = (int)($countMap[$idx] ?? 0);
        $active = $activeCatIdx === $idx ? ' active' : '';

        $hasChildren = false;
        foreach ($allCats as $c) {
            if ((int)($c['parent_idx'] ?? 0) === $idx) { $hasChildren = true; break; }
        }
        $icon = $hasChildren ? 'fa-folder' : 'fa-tag';

        $toggleBtn = $hasChildren
            ? '<button class="mat-cat-toggle open" data-toggle-idx="' . $idx . '" type="button"><i class="fa fa-chevron-down"></i></button>'
            : '<span class="mat-cat-toggle-ph"></span>';

        $countSpan = $count > 0
            ? '<span class="mat-cat-count">(' . $count . ')</span>'
            : '';

        $html .= '<div class="mat-cat-node" data-cat-idx="' . $idx . '" data-parent-idx="' . $pIdx . '">';
        $html .= '<div class="mat-cat-item' . $active . '" data-cat-idx="' . $idx . '" data-depth="' . $depth . '" draggable="true">';
        $html .= $toggleBtn;
        $html .= '<i class="fa ' . $icon . ' mat-cat-icon"></i>';
        $html .= '<span class="mat-cat-name">' . $name . '</span>';
        $html .= $countSpan;
        $html .= '<span class="mat-cat-actions">';
        $html .= '<button class="mat-cat-action-btn mat-cat-add-btn" data-parent-idx="' . $idx . '" type="button" title="하위 추가"><i class="fa fa-plus"></i></button>';
        $html .= '<button class="mat-cat-action-btn mat-cat-edit-btn" data-cat-idx="' . $idx . '" data-cat-name="' . $name . '" type="button" title="수정"><i class="fa fa-pencil"></i></button>';
        $html .= '<button class="mat-cat-action-btn mat-cat-del-btn" data-cat-idx="' . $idx . '" data-cat-name="' . $name . '" type="button" title="삭제"><i class="fa fa-times"></i></button>';
        $html .= '</span>';
        $html .= '</div>'; /* .mat-cat-item */

        if ($hasChildren) {
            $html .= '<div class="mat-cat-children open" data-parent-idx="' . $idx . '">';
            $html .= matListRenderTree($allCats, $idx, $activeCatIdx, $countMap);
            $html .= '</div>';
        }

        $html .= '</div>'; /* .mat-cat-node */
    }
    return $html;
}

/* ── 인증 ── */
$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-list" class="mat-list-page"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$db      = DbConnection::get();
$service = new MaterialService($db);

/* ── 요청 파라미터 ── */
$page            = max(1, (int)($_GET['p'] ?? 1));
$limit           = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$search          = trim((string)($_GET['search'] ?? ''));
$tabIdx          = (int)($_GET['tab_idx'] ?? 0);
$categoryIdx     = (int)($_GET['category_idx'] ?? 0);
$materialPattern = trim((string)($_GET['material_pattern'] ?? ''));
$attributeFilter = trim((string)($_GET['attribute'] ?? ''));
$priceMin        = isset($_GET['price_min']) && is_numeric($_GET['price_min']) ? (float)$_GET['price_min'] : null;
$priceMax        = isset($_GET['price_max']) && is_numeric($_GET['price_max']) ? (float)$_GET['price_max'] : null;

/* ── 품목 목록 조회 ── */
$result = $service->list([
    'p'                => $page,
    'limit'            => $limit,
    'search'           => $search,
    'tab_idx'          => $tabIdx,
    'category_idx'     => $categoryIdx,
    'material_pattern' => $materialPattern,
    'attribute'        => $attributeFilter,
    'price_min'        => $priceMin,
    'price_max'        => $priceMax,
]);
$rows  = is_array($result['data'] ?? null) ? $result['data'] : [];
$total = (int)($result['total'] ?? 0);
$pages = (int)($result['pages'] ?? 1);

/* ── 탭 목록 ── */
$tabRows = [];
if (matListTableExists($db, 'Tb_ItemTab')) {
    $stmt    = $db->query('SELECT idx, name FROM Tb_ItemTab ORDER BY idx ASC');
    $tabRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
$currentTabName = '전체';
foreach ($tabRows as $tab) {
    if ((int)($tab['idx'] ?? 0) === $tabIdx) {
        $currentTabName = (string)($tab['name'] ?? '전체');
        break;
    }
}

/* ── 카테고리 목록 (트리 빌드용, 품목 수 포함) ── */
$categoryRows = [];
if (matListTableExists($db, 'Tb_ItemCategory')) {
    try {
        $hasItemTbl  = matListTableExists($db, 'Tb_Item');
        $cntSubQuery = $hasItemTbl
            ? ', (SELECT COUNT(*) FROM Tb_Item i WHERE i.category_idx = c.idx AND ISNULL(i.is_deleted,0)=0) AS item_count'
            : ', 0 AS item_count';
        $stmt = $db->query(
            'SELECT c.idx, ISNULL(c.parent_idx,0) AS parent_idx, c.name,'
            . ' ISNULL(c.depth,1) AS depth, ISNULL(c.sort_order,c.idx) AS sort_order'
            . $cntSubQuery
            . ' FROM Tb_ItemCategory c'
            . ' WHERE ISNULL(c.is_deleted,0)=0'
            . ' ORDER BY c.sort_order ASC, c.idx ASC'
        );
        $categoryRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        try {
            $stmt = $db->query(
                'SELECT idx, 0 AS parent_idx, name, 1 AS depth, idx AS sort_order, 0 AS item_count'
                . ' FROM Tb_ItemCategory WHERE ISNULL(is_deleted,0)=0 ORDER BY idx ASC'
            );
            $categoryRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e2) {
            $categoryRows = [];
        }
    }
}

/* 카테고리별 품목 수 맵 */
$catCountMap = [];
foreach ($categoryRows as $cat) {
    $catCountMap[(int)$cat['idx']] = (int)($cat['item_count'] ?? 0);
}

$categoryPaths = matListBuildCategoryPaths($categoryRows);

/* ── CSRF ── */
$csrfInfo  = $auth->csrfToken();
$csrfToken = (string)($csrfInfo['csrf_token'] ?? '');

/* ── 유형 배지 맵 ── */
$patternBadgeMap = [
    '견적'  => 'mat-badge mat-badge-estimate',
    '지급'  => 'mat-badge mat-badge-labor',
    '판매'  => 'mat-badge mat-badge-sale',
    '비품'  => 'mat-badge mat-badge-fixture',
    '구성품' => 'mat-badge mat-badge-component',
    '기타'  => 'mat-badge mat-badge-other',
];
?>
<link rel="stylesheet" href="css/v2/pages/mat.css?v=20260414k">
<section
    data-page="mat-list"
    class="mat-list-page"
    data-csrf-token="<?= matListH($csrfToken) ?>"
    data-mat-api="dist_process/saas/Material.php"
    data-mat-page="<?= (int)$page ?>"
    data-mat-limit="<?= (int)$limit ?>"
    data-mat-tab="<?= (int)$tabIdx ?>"
    data-mat-cat="<?= (int)$categoryIdx ?>"
    data-mat-pattern="<?= matListH($materialPattern) ?>"
    data-mat-attribute="<?= matListH($attributeFilter) ?>"
    data-mat-price-min="<?= $priceMin !== null ? $priceMin : '' ?>"
    data-mat-price-max="<?= $priceMax !== null ? $priceMax : '' ?>"
>

    <!-- ① 탭 바 -->
    <div class="mat-tab-bar" id="matTabBar">
        <button class="mat-tab-item <?= $tabIdx === 0 ? 'active' : '' ?>" data-tab="0">전체</button>
        <?php foreach ($tabRows as $tab): ?>
            <div class="mat-tab-wrap <?= (int)($tab['idx'] ?? 0) === $tabIdx ? 'active' : '' ?>">
                <button
                    class="mat-tab-item <?= (int)($tab['idx'] ?? 0) === $tabIdx ? 'active' : '' ?>"
                    data-tab="<?= (int)($tab['idx'] ?? 0) ?>"
                ><?= matListH((string)($tab['name'] ?? '')) ?></button>
                <button class="mat-tab-del-btn" data-tab-idx="<?= (int)($tab['idx'] ?? 0) ?>" data-tab-name="<?= matListH((string)($tab['name'] ?? '')) ?>" type="button" title="탭 삭제"><i class="fa fa-times"></i></button>
            </div>
        <?php endforeach; ?>
        <button class="mat-tab-add-btn" id="matTabAddBtn" type="button" title="탭 추가"><i class="fa fa-plus"></i></button>
        <button class="mat-tab-item mat-tab-freq" id="matTabFreqBtn" type="button">
            <i class="fa fa-star"></i> 자주 쓰는
        </button>
        <button class="mat-tab-item mat-tab-v1" id="matTabV1Btn" type="button">
            <i class="fa fa-history"></i> V1 이관
        </button>
    </div>

    <!-- ② 바디 -->
    <div class="mat-body">

        <!-- 카테고리 사이드바 -->
        <div class="mat-sidebar">
            <div class="mat-sidebar-header">
                <i class="fa fa-layer-group mat-cat-icon"></i>
                <span class="mat-sidebar-title">카테고리</span>
                <div class="mat-cat-header-btns">
                    <button class="mat-cat-header-btn" id="matCatExpandAll" type="button" title="전체 펼치기"><i class="fa fa-expand-alt"></i></button>
                    <button class="mat-cat-header-btn" id="matCatCollapseAll" type="button" title="전체 닫기"><i class="fa fa-compress-alt"></i></button>
                    <button class="mat-cat-header-btn" id="matCatHeaderAddBtn" type="button" title="카테고리 추가"><i class="fa fa-plus"></i></button>
                </div>
            </div>
            <div class="mat-category-tree" id="matCategoryTree">
                <div class="mat-cat-item <?= $categoryIdx === 0 ? 'active' : '' ?>"
                     data-cat-idx="0" data-depth="0">
                    <span class="mat-cat-toggle-ph"></span>
                    <i class="fa fa-list mat-cat-icon"></i>
                    <span class="mat-cat-name">전체</span>
                </div>
                <?= matListRenderTree($categoryRows, 0, $categoryIdx, $catCountMap) ?>
            </div>
        </div>

        <!-- 콘텐츠 영역 -->
        <div class="mat-content">

            <!-- 툴바 -->
            <div class="mat-toolbar">
                <span class="mat-content-title" id="matContentTitle">
                    <i class="fa fa-list-ul"></i>
                    <?= $tabIdx === 0 ? '전체 품목' : matListH($currentTabName) . ' 전체 품목' ?>
                </span>
                <div class="mat-toolbar-search">
                    <input id="matListSearch" type="text" class="form-input mat-search-input"
                           placeholder="품목명, 규격, 업체"
                           value="<?= matListH($search) ?>">
                    <button id="matListSearchBtn" class="btn btn-primary btn-sm">
                        <i class="fa fa-search"></i>
                    </button>
                </div>
                <span class="mat-count-label" id="matListCount"><?= number_format($total) ?>건</span>
                <div class="mat-toolbar-actions">
                    <button id="matFilterToggleBtn" class="btn btn-ghost btn-sm<?= ($materialPattern !== '' || $attributeFilter !== '' || $priceMin !== null || $priceMax !== null) ? ' btn-filter-active' : '' ?>" type="button" title="상세 필터">
                        <i class="fa fa-filter"></i> 필터<?= ($materialPattern !== '' || $attributeFilter !== '' || $priceMin !== null || $priceMax !== null) ? ' <span class="filter-dot"></span>' : '' ?>
                    </button>
                    <button id="matCreateBtn" class="btn btn-primary btn-sm mat-normal-only">
                        품목등록
                    </button>
                    <button id="matCopyFromV1Btn" class="btn btn-success btn-sm mat-v1-only" style="display:none" type="button">
                        <i class="fa fa-copy"></i> 신규로 복사
                    </button>
                    <button id="matFillCodeBtn" class="btn btn-ghost btn-sm mat-normal-only" type="button" title="자재번호 일괄 생성">
                        <i class="fa fa-barcode"></i> 자재번호
                    </button>
                    <button id="matMoveBtn" class="btn btn-ghost btn-sm mat-normal-only" disabled title="이동/복사">
                        <i class="fa fa-arrows-alt"></i> 이동
                    </button>
                    <button id="matEditBtn" class="btn btn-ghost btn-sm mat-normal-only" disabled>
                        <i class="fa fa-edit"></i> 수정
                    </button>
                </div>
            </div>

            <!-- 필터 패널 -->
            <div class="mat-filter-panel<?= ($materialPattern !== '' || $attributeFilter !== '' || $priceMin !== null || $priceMax !== null) ? ' open' : '' ?>" id="matFilterPanel">
                <div class="mat-filter-row">
                    <div class="mat-filter-group">
                        <label class="mat-filter-label">품목유형</label>
                        <select id="matFilterPattern" class="form-input form-input-sm">
                            <option value="">전체</option>
                            <?php foreach (array_keys($patternBadgeMap) as $p): ?>
                                <option value="<?= matListH($p) ?>"<?= $materialPattern === $p ? ' selected' : '' ?>><?= matListH($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mat-filter-group">
                        <label class="mat-filter-label">PJT(공종)</label>
                        <input id="matFilterAttribute" type="text" class="form-input form-input-sm" placeholder="공종명 검색" value="<?= matListH($attributeFilter) ?>">
                    </div>
                    <div class="mat-filter-group">
                        <label class="mat-filter-label">판매단가 범위</label>
                        <div class="mat-filter-range">
                            <input id="matFilterPriceMin" type="number" class="form-input form-input-sm" placeholder="최소" min="0" value="<?= $priceMin !== null ? (int)$priceMin : '' ?>">
                            <span class="mat-filter-range-sep">~</span>
                            <input id="matFilterPriceMax" type="number" class="form-input form-input-sm" placeholder="최대" min="0" value="<?= $priceMax !== null ? (int)$priceMax : '' ?>">
                        </div>
                    </div>
                    <div class="mat-filter-btns">
                        <button id="matFilterApplyBtn" class="btn btn-primary btn-sm">적용</button>
                        <button id="matFilterResetBtn" class="btn btn-ghost btn-sm">초기화</button>
                    </div>
                </div>
            </div>

            <!-- 테이블 -->
            <div class="mat-table-wrap">
                <table class="tbl tbl-select tbl-sticky-header" id="matListTable">
                    <thead>
                        <tr>
                            <th class="col-50 th-center" data-sort-key="idx">No <span class="sort-icon">↕</span></th>
                            <th class="col-140" data-sort-key="item_code">자재번호 <span class="sort-icon">↕</span></th>
                            <th class="col-160" data-sort="false">카테고리</th>
                            <th data-sort-key="name">품목명 <span class="sort-icon">↕</span></th>
                            <th class="col-100" data-sort="false">규격</th>
                            <th class="col-50 th-center" data-sort="false">단위</th>
                            <th class="col-80 th-center" data-sort="false">품목유형</th>
                            <th class="col-90 th-right" data-sort-key="sale_price">판매단가 <span class="sort-icon">↕</span></th>
                            <th class="col-90 th-right" data-sort-key="cost">원가 <span class="sort-icon">↕</span></th>
                            <th class="col-70 th-right" data-sort-key="qty">재고 <span class="sort-icon">↕</span></th>
                            <th class="col-100 mat-col-detail" data-sort="false">매입처</th>
                            <th class="col-100 mat-col-detail" data-sort="false">PJT</th>
                            <th class="col-60 th-center" data-sort="false">구성품</th>
                        </tr>
                    </thead>
                    <tbody id="matListBody">
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="13">
                                    <div class="empty-state">
                                        <p class="empty-message">조회된 품목이 없습니다.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rIdx       = (int)($row['idx'] ?? 0);
                                $catIdx2    = (int)($row['category_idx'] ?? 0);
                                $catPath    = $catIdx2 > 0
                                    ? ($categoryPaths[$catIdx2] ?? matListH((string)($row['category_name'] ?? '')))
                                    : '';
                                $pattern    = trim((string)($row['material_pattern'] ?? ''));
                                $badgeCls   = $patternBadgeMap[$pattern] ?? 'mat-badge mat-badge-other';
                                $salePrice  = is_numeric($row['sale_price'] ?? null) ? (float)$row['sale_price'] : null;
                                $cost       = is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : null;
                                $qty        = is_numeric($row['qty'] ?? null) ? (float)$row['qty'] : null;
                                $safetyCnt  = is_numeric($row['safety_count'] ?? null) ? (float)$row['safety_count'] : null;
                                $company    = trim((string)($row['company_name'] ?? ''));
                                $attribute  = trim((string)($row['attribute'] ?? ''));
                                $qtyWarn    = ($safetyCnt !== null && $qty !== null && $qty <= $safetyCnt) ? ' mat-stock-warn' : '';
                                ?>
                                <tr class="clickable" data-material-idx="<?= $rIdx ?>" data-row-id="<?= $rIdx ?>">
                                    <td class="td-center td-muted td-mono"><?= $rIdx ?></td>
                                    <td class="td-mono td-nowrap td-muted">
                                        <?= matListH((string)($row['item_code'] ?? '')) ?>
                                    </td>
                                    <td class="td-ellipsis">
                                        <?php if ($catPath !== ''): ?>
                                            <span class="mat-cat-path"><?= $catPath ?></span>
                                        <?php else: ?>
                                            <span class="td-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="td-bold mat-inline-cell"
                                        data-inline-field="item_name"
                                        data-inline-raw="<?= matListH((string)($row['name'] ?? '')) ?>"
                                        data-inline-type="text">
                                        <?= matListH((string)($row['name'] ?? '')) ?>
                                    </td>
                                    <td class="td-muted mat-inline-cell"
                                        data-inline-field="spec"
                                        data-inline-raw="<?= matListH((string)($row['standard'] ?? '')) ?>"
                                        data-inline-type="text">
                                        <?= matListH((string)($row['standard'] ?? '')) ?>
                                    </td>
                                    <td class="td-center td-muted">
                                        <?= matListH((string)($row['unit'] ?? '')) ?>
                                    </td>
                                    <td class="td-center">
                                        <?php if ($pattern !== ''): ?>
                                            <span class="<?= $badgeCls ?>"><?= matListH($pattern) ?></span>
                                        <?php else: ?>
                                            <span class="td-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="td-num mat-inline-cell"
                                        data-inline-field="sale_price"
                                        data-inline-raw="<?= $salePrice !== null ? $salePrice : '' ?>"
                                        data-inline-type="number">
                                        <?= $salePrice !== null ? number_format($salePrice) : '-' ?>
                                    </td>
                                    <td class="td-num mat-inline-cell"
                                        data-inline-field="cost"
                                        data-inline-raw="<?= $cost !== null ? $cost : '' ?>"
                                        data-inline-type="number">
                                        <?= $cost !== null ? number_format($cost) : '-' ?>
                                    </td>
                                    <td class="td-num<?= $qtyWarn ?>">
                                        <?= $qty !== null ? number_format($qty) : '-' ?>
                                    </td>
                                    <td class="td-muted mat-col-detail">
                                        <?= $company !== '' ? matListH($company) : '-' ?>
                                    </td>
                                    <td class="td-center mat-col-detail">
                                        <?php if ($attribute !== ''): ?>
                                            <span class="mat-badge mat-badge-pjt"><?= matListH($attribute) ?></span>
                                        <?php else: ?>
                                            <span class="td-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="td-center">
                                        <?php $compCnt = (int)($row['component_count'] ?? 0); ?>
                                        <?php if ($compCnt > 0): ?>
                                            <span class="badge badge-info"><?= $compCnt ?></span>
                                        <?php else: ?>
                                            <span class="td-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 페이지네이션 -->
            <div class="mat-pager-bar">
                <span class="mat-pager-info">
                    총 <?= number_format($total) ?>건 &nbsp;|&nbsp; 페이지 <?= (int)$page ?> / <?= max(1, $pages) ?>
                </span>
                <div id="matListPager" class="mat-pager-btns">
                    <?php if ($page > 1): ?>
                        <button class="page-item" data-mat-page="<?= $page - 1 ?>">‹</button>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                        <button class="page-item <?= $i === $page ? 'page-active' : '' ?>"
                                data-mat-page="<?= $i ?>"><?= $i ?></button>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <button class="page-item" data-mat-page="<?= $page + 1 ?>">›</button>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.mat-content -->

    </div><!-- /.mat-body -->

    <!-- ── 품목 등록 모달 ── -->
    <div id="matCreateModal" class="modal-overlay">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-plus"></i> 품목 등록</h3>
                <button class="modal-close" id="matCreateCloseBtn">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">

                <!-- 섹션 1 - 기본정보 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-info-circle"></i> 기본정보
                    </p>
                    <div class="form-row">
                        <div class="form-group fg-2">
                            <label class="form-label">
                                품목명 <span class="badge badge-danger">필수</span>
                            </label>
                            <input id="mcName" type="text" class="form-input"
                                   placeholder="품목명을 입력하세요">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                유형 <span class="badge badge-danger">필수</span>
                            </label>
                            <select id="mcPattern" class="form-select">
                                <option value="">선택</option>
                                <option value="견적">견적</option>
                                <option value="지급">지급</option>
                                <option value="판매">판매</option>
                                <option value="비품">비품</option>
                                <option value="구성품">구성품</option>
                                <option value="기타">기타</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">규격</label>
                            <input id="mcStandard" type="text" class="form-input" placeholder="규격">
                        </div>
                        <div class="form-group">
                            <label class="form-label">단위</label>
                            <input id="mcUnit" type="text" class="form-input" placeholder="EA, M, KG …">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                자재번호 <span class="text-3 text-xs">(미입력 시 자동생성)</span>
                            </label>
                            <input id="mcItemCode" type="text" class="form-input" placeholder="자동생성">
                        </div>
                        <div class="form-group">
                            <label class="form-label">바코드</label>
                            <input id="mcBarcode" type="text" class="form-input" placeholder="바코드">
                        </div>
                    </div>
                </div>

                <!-- 섹션 2 - 분류 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-tags"></i> 분류
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">품목탭</label>
                            <select id="mcTabIdx" class="form-select">
                                <option value="0">미지정</option>
                                <?php foreach ($tabRows as $tab): ?>
                                    <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                        <?= matListH((string)($tab['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">카테고리</label>
                            <select id="mcCategoryIdx" class="form-select">
                                <option value="0">미지정</option>
                                <?php foreach ($categoryRows as $cat): ?>
                                    <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                        <?= matListH((string)($cat['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">PJT(공종)</label>
                            <select id="mcAttribute" class="form-select">
                                <option value="">미지정</option>
                                <option value="전기">전기</option>
                                <option value="소방">소방</option>
                                <option value="통신">통신</option>
                                <option value="기계배관">기계배관</option>
                                <option value="공사">공사</option>
                                <option value="자재구매">자재구매</option>
                                <option value="내선">내선</option>
                                <option value="기타">기타</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">판매유무</label>
                            <select id="mcHidden" class="form-select">
                                <option value="0">판매</option>
                                <option value="1">미판매</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">내역연동</label>
                        <select id="mcRelativePurchase" class="form-select">
                            <option value="O">O — 연동</option>
                            <option value="X">X — 미연동</option>
                        </select>
                    </div>
                </div>

                <!-- 섹션 3 - 가격 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-won-sign"></i> 가격
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                판매단가 <span class="badge badge-danger">필수</span>
                            </label>
                            <input id="mcSalePrice" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                원가 <span class="badge badge-danger">필수</span>
                            </label>
                            <input id="mcCost" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">공급단가</label>
                            <input id="mcSupplyPrice" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">매입단가</label>
                            <input id="mcPurchasePrice" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">작업단가</label>
                            <input id="mcWorkPrice" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">세액</label>
                            <input id="mcTaxPrice" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">안전관리비</label>
                        <input id="mcSafety" type="number" class="form-input" placeholder="0" min="0">
                    </div>
                </div>

                <!-- 섹션 4 - 재고 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-boxes"></i> 재고
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">재고관리</label>
                            <select id="mcInventory" class="form-select">
                                <option value="무">무 (재고 미관리)</option>
                                <option value="유">유 (재고 관리)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">재고수량</label>
                            <input id="mcQty" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">안전재고</label>
                            <input id="mcSafetyCount" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">기본수량</label>
                            <input id="mcBaseCount" type="number" class="form-input" placeholder="0" min="0">
                        </div>
                    </div>
                </div>

                <!-- 섹션 5 - 이미지 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-image"></i> 이미지
                    </p>
                    <div class="mat-img-pair">
                        <div>
                            <span class="mat-img-label">배너사진</span>
                            <div class="mat-img-upload-area" id="mcBannerArea">
                                <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                                <span>클릭하여 배너 이미지 선택</span>
                                <img class="mat-img-preview" id="mcBannerPreview" alt="배너미리보기">
                                <button type="button" class="mat-img-remove" id="mcBannerRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <input type="file" id="mcBannerFile" accept="image/*" class="d-none">
                        </div>
                        <div>
                            <span class="mat-img-label">상세이미지</span>
                            <div class="mat-img-upload-area" id="mcDetailArea">
                                <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                                <span>클릭하여 상세 이미지 선택</span>
                                <img class="mat-img-preview" id="mcDetailPreview" alt="상세미리보기">
                                <button type="button" class="mat-img-remove" id="mcDetailRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <input type="file" id="mcDetailFile" accept="image/*" class="d-none">
                        </div>
                    </div>
                </div>

                <!-- 섹션 6 - 내용/메모 -->
                <div class="mat-modal-section">
                    <p class="mat-modal-section-title">
                        <i class="fa fa-align-left"></i> 내용 / 메모
                    </p>
                    <div class="form-group">
                        <label class="form-label">내용</label>
                        <textarea id="mcContents" class="form-input" rows="3"
                                  placeholder="품목 내용을 입력하세요"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">메모</label>
                        <textarea id="mcMemo" class="form-input" rows="2"
                                  placeholder="메모"></textarea>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button id="matCreateCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matCreateSubmitBtn" class="btn btn-primary">
                    <i class="fa fa-save"></i> 등록
                </button>
            </div>
        </div>
    </div>


    <!-- ── 카테고리 추가 모달 ── -->
    <div id="matCatCreateModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-plus"></i> 카테고리 추가</h3>
                <button class="modal-close" id="matCatCreateCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mcatParentIdx" value="0">
                <div class="form-group">
                    <label class="form-label">
                        상위 위치 <span id="mcatParentLabel" class="badge badge-ghost">최상위 카테고리</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">카테고리명 <span class="badge badge-danger">필수</span></label>
                    <input id="mcatName" type="text" class="form-input" placeholder="카테고리명을 입력하세요">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matCatCreateCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matCatCreateSubmitBtn" class="btn btn-primary" type="button">
                    <i class="fa fa-save"></i> 추가
                </button>
            </div>
        </div>
    </div>

    <!-- ── 카테고리 수정 모달 ── -->
    <div id="matCatEditModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-pencil"></i> 카테고리 수정</h3>
                <button class="modal-close" id="matCatEditCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mcatEditIdx" value="0">
                <div class="form-group">
                    <label class="form-label">카테고리명 <span class="badge badge-danger">필수</span></label>
                    <input id="mcatEditName" type="text" class="form-input" placeholder="카테고리명">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matCatEditCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matCatEditSubmitBtn" class="btn btn-primary" type="button">
                    <i class="fa fa-save"></i> 저장
                </button>
            </div>
        </div>
    </div>

    <!-- ── 카테고리 삭제 확인 모달 ── -->
    <div id="matCatDeleteModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-trash"></i> 카테고리 삭제</h3>
                <button class="modal-close" id="matCatDeleteCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mcatDelIdx" value="0">
                <p class="text-sm text-2">
                    <strong id="mcatDelName" class="text-1"></strong> 카테고리를 삭제합니다.<br>
                    <span class="text-warn">하위 카테고리 및 연결된 품목 분류가 해제됩니다.</span>
                </p>
            </div>
            <div class="modal-footer">
                <button id="matCatDeleteCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matCatDeleteSubmitBtn" class="btn btn-danger" type="button">
                    <i class="fa fa-trash"></i> 삭제
                </button>
            </div>
        </div>
    </div>

    <!-- ── 품목 삭제 확인 모달 ── -->
    <div id="matItemDeleteModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-trash"></i> 품목 삭제</h3>
                <button class="modal-close" id="matItemDeleteCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-2">
                    선택한 <strong id="matItemDeleteCount" class="text-1">0</strong>건의 품목을 삭제합니다.<br>
                    <span class="text-warn">삭제된 품목은 복구할 수 없습니다.</span>
                </p>
            </div>
            <div class="modal-footer">
                <button id="matItemDeleteCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matItemDeleteSubmitBtn" class="btn btn-danger" type="button">
                    <i class="fa fa-trash"></i> 삭제
                </button>
            </div>
        </div>
    </div>

    <!-- ── 탭 추가 모달 ── -->
    <div id="matTabCreateModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-plus"></i> 탭 추가</h3>
                <button class="modal-close" id="matTabCreateCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">탭 이름 <span class="badge badge-danger">필수</span></label>
                    <input id="mtabName" type="text" class="form-input" placeholder="탭 이름을 입력하세요">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matTabCreateCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matTabCreateSubmitBtn" class="btn btn-primary" type="button">
                    <i class="fa fa-save"></i> 추가
                </button>
            </div>
        </div>
    </div>

    <!-- ── 탭 삭제 확인 모달 ── -->
    <div id="matTabDeleteModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-trash"></i> 탭 삭제</h3>
                <button class="modal-close" id="matTabDeleteCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mtabDelIdx" value="0">
                <p class="text-sm text-2">
                    <strong id="mtabDelName" class="text-1"></strong> 탭을 삭제합니다.<br>
                    <span class="text-warn">탭 내 품목의 탭 지정이 해제됩니다.</span>
                </p>
            </div>
            <div class="modal-footer">
                <button id="matTabDeleteCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matTabDeleteSubmitBtn" class="btn btn-danger" type="button">
                    <i class="fa fa-trash"></i> 삭제
                </button>
            </div>
        </div>
    </div>

    <!-- ── V1 → V2 신규로 복사 모달 ── -->
    <div id="matV1CopyModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-copy"></i> V1 품목 신규 복사</h3>
                <button class="modal-close" id="matV1CopyCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-2 mb-8">
                    선택한 <strong id="matV1CopyCount" class="text-1">0</strong>건을 V2로 복사합니다.
                </p>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">대상 탭</label>
                        <select id="v1copyTabIdx" class="form-select">
                            <option value="0">미지정</option>
                            <?php foreach ($tabRows as $tab): ?>
                                <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                    <?= matListH((string)($tab['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">대상 카테고리</label>
                        <select id="v1copyCategoryIdx" class="form-select">
                            <option value="0">미지정</option>
                            <?php foreach ($categoryRows as $cat): ?>
                                <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                    <?= matListH((string)($cat['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="matV1CopyCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matV1CopySubmitBtn" class="btn btn-success" type="button">
                    <i class="fa fa-check"></i> 복사 실행
                </button>
            </div>
        </div>
    </div>

    <!-- ── 품목 이동/복사 모달 ── -->
    <div id="matMoveModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-arrows-alt"></i> 품목 이동 / 복사</h3>
                <button class="modal-close" id="matMoveCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-2 mb-8">
                    선택한 <strong id="matMoveCount" class="text-1">0</strong>건을 이동하거나 복사합니다.
                </p>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">대상 탭</label>
                        <select id="mmoveTabIdx" class="form-select">
                            <option value="0">미지정</option>
                            <?php foreach ($tabRows as $tab): ?>
                                <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                    <?= matListH((string)($tab['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">대상 카테고리</label>
                        <select id="mmoveCategoryIdx" class="form-select">
                            <option value="0">미지정</option>
                            <?php foreach ($categoryRows as $cat): ?>
                                <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                    <?= matListH((string)($cat['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">작업 유형</label>
                    <div class="mat-radio-group">
                        <label class="mat-radio-label">
                            <input type="radio" name="mmoveType" value="move" checked> 이동
                        </label>
                        <label class="mat-radio-label">
                            <input type="radio" name="mmoveType" value="copy"> 복사
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="matMoveCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matMoveSubmitBtn" class="btn btn-primary" type="button">
                    <i class="fa fa-check"></i> 확인
                </button>
            </div>
        </div>
    </div>

    <!-- ── 자재번호 일괄생성 모달 ── -->
    <div id="matFillCodeModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-barcode"></i> 자재번호 일괄 생성</h3>
                <button class="modal-close" id="matFillCodeCloseBtn" type="button">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-2 mb-8">자재번호가 없는 품목에 번호를 자동 생성합니다.</p>
                <div class="form-group">
                    <label class="form-label">접두어 (Prefix)</label>
                    <input id="mfillPrefix" type="text" class="form-input" value="MAT" placeholder="MAT">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">대상 탭</label>
                        <select id="mfillTabIdx" class="form-select">
                            <option value="0">전체</option>
                            <?php foreach ($tabRows as $tab): ?>
                                <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                    <?= matListH((string)($tab['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">대상 카테고리</label>
                        <select id="mfillCategoryIdx" class="form-select">
                            <option value="0">전체</option>
                            <?php foreach ($categoryRows as $cat): ?>
                                <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                    <?= matListH((string)($cat['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="matFillCodeCancelBtn" class="btn btn-ghost" type="button">취소</button>
                <button id="matFillCodeSubmitBtn" class="btn btn-primary" type="button">
                    <i class="fa fa-cog"></i> 생성
                </button>
            </div>
        </div>
    </div>

</section>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="mat-list"]');
    if (!_section) return;

    var _api = _section.dataset.matApi || 'dist_process/saas/Material.php';

    /* ── 상태 ── */
    var _state = {
        tabIdx:      parseInt(_section.dataset.matTab     || '0', 10),
        categoryIdx: parseInt(_section.dataset.matCat     || '0', 10),
        limit:       parseInt(_section.dataset.matLimit   || '50', 10),
        selectedIdx: 0,
        selectedIds: new Set(),
        sorts:       [],
        filterPattern:   _section.dataset.matPattern   || '',
        filterAttribute: _section.dataset.matAttribute || '',
        filterPriceMin:  _section.dataset.matPriceMin  || '',
        filterPriceMax:  _section.dataset.matPriceMax  || '',
        isV1Mode:    false,  /* V1 이관 탭 활성 여부 */
        isFreqMode:  false   /* 자주 쓰는 품목 탭 활성 여부 */
    };

    var _tbl = null;

    function _escHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            if (ch === '&') return '&amp;';
            if (ch === '<') return '&lt;';
            if (ch === '>') return '&gt;';
            if (ch === '"') return '&quot;';
            return '&#39;';
        });
    }

    /* ── 재조회 ── */
    function reload(p, extra) {
        var params = {
            tab_idx:      _state.tabIdx,
            category_idx: _state.categoryIdx,
            limit:        _state.limit,
            search:       (document.getElementById('matListSearch') || {}).value || '',
            p:            p || 1
        };
        if (_state.sorts && _state.sorts.length > 0) {
            params.sort = _state.sorts.map(function (s) { return s.key + ':' + s.dir; }).join(',');
        }
        if (_state.filterPattern)   params.material_pattern = _state.filterPattern;
        if (_state.filterAttribute) params.attribute        = _state.filterAttribute;
        if (_state.filterPriceMin)  params.price_min        = _state.filterPriceMin;
        if (_state.filterPriceMax)  params.price_max        = _state.filterPriceMax;
        if (extra) Object.assign(params, extra);
        SHV.router.navigate('material_list', params);
    }

    /* ── 필터 패널 ── */
    var _filterPanel = document.getElementById('matFilterPanel');
    var _filterToggleBtn = document.getElementById('matFilterToggleBtn');

    _filterToggleBtn.addEventListener('click', function () {
        _filterPanel.classList.toggle('open');
    });

    function _hasActiveFilter() {
        return _state.filterPattern || _state.filterAttribute || _state.filterPriceMin || _state.filterPriceMax;
    }

    function _updateFilterBtn() {
        var active = !!_hasActiveFilter();
        _filterToggleBtn.classList.toggle('btn-filter-active', active);
        var dot = _filterToggleBtn.querySelector('.filter-dot');
        if (active && !dot) {
            var s = document.createElement('span');
            s.className = 'filter-dot';
            _filterToggleBtn.appendChild(s);
        } else if (!active && dot) {
            dot.parentNode.removeChild(dot);
        }
    }

    document.getElementById('matFilterApplyBtn').addEventListener('click', function () {
        _state.filterPattern   = (document.getElementById('matFilterPattern').value   || '').trim();
        _state.filterAttribute = (document.getElementById('matFilterAttribute').value || '').trim();
        _state.filterPriceMin  = (document.getElementById('matFilterPriceMin').value  || '').trim();
        _state.filterPriceMax  = (document.getElementById('matFilterPriceMax').value  || '').trim();
        _updateFilterBtn();
        reload(1);
    });

    document.getElementById('matFilterResetBtn').addEventListener('click', function () {
        _state.filterPattern   = '';
        _state.filterAttribute = '';
        _state.filterPriceMin  = '';
        _state.filterPriceMax  = '';
        document.getElementById('matFilterPattern').value   = '';
        document.getElementById('matFilterAttribute').value = '';
        document.getElementById('matFilterPriceMin').value  = '';
        document.getElementById('matFilterPriceMax').value  = '';
        _updateFilterBtn();
        reload(1);
    });

    /* ── 카테고리 전체 펼침/닫기 ── */
    document.getElementById('matCatExpandAll').addEventListener('click', function () {
        _catTree.querySelectorAll('.mat-cat-children').forEach(function (el) { el.classList.add('open'); });
        _catTree.querySelectorAll('.mat-cat-toggle').forEach(function (el) { el.classList.add('open'); });
    });

    document.getElementById('matCatCollapseAll').addEventListener('click', function () {
        _catTree.querySelectorAll('.mat-cat-children').forEach(function (el) { el.classList.remove('open'); });
        _catTree.querySelectorAll('.mat-cat-toggle').forEach(function (el) { el.classList.remove('open'); });
    });

    /* ── 자주 쓰는 품목 모드 전환 ── */
    function setFreqMode(on) {
        _state.isFreqMode = on;
        var freqBtn = document.getElementById('matTabFreqBtn');
        if (freqBtn) freqBtn.classList.toggle('active', on);
        if (on) {
            /* 다른 탭 active 해제 */
            document.querySelectorAll('.mat-tab-bar .mat-tab-item:not(.mat-tab-freq), .mat-tab-bar .mat-tab-wrap').forEach(function (el) {
                el.classList.remove('active');
                var inner = el.querySelector('.mat-tab-item');
                if (inner) inner.classList.remove('active');
            });
        }
        document.querySelectorAll('.mat-normal-only').forEach(function (el) { el.style.display = on ? 'none' : ''; });
        document.querySelectorAll('.mat-v1-only').forEach(function (el) { el.style.display = 'none'; });
        var sidebar = document.querySelector('.mat-sidebar');
        if (sidebar) sidebar.style.display = on ? 'none' : '';
        if (on && _filterPanel) _filterPanel.classList.remove('open');
    }

    function reloadFreq() {
        var tbody = document.getElementById('matListBody');
        tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">자주 쓰는 품목 로딩 중...</p></div></td></tr>';
        SHV.api.get(_api, { todo: 'frequent_items', limit: 20 })
            .then(function (res) {
                var rows = (res && res.data && Array.isArray(res.data.data)) ? res.data.data : [];
                var countEl = document.getElementById('matListCount');
                if (countEl) countEl.textContent = rows.length + '건';
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">자주 쓰는 품목이 없습니다.</p></div></td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function (r) {
                    var idx  = parseInt(r.idx || '0', 10);
                    if (!isFinite(idx)) idx = 0;
                    var code = _escHtml(r.item_code || '');
                    var name = _escHtml(r.name || '');
                    var std  = _escHtml(r.standard || '');
                    var unit = _escHtml(r.unit || '');
                    var pat  = _escHtml(r.material_pattern || '');
                    var sp   = parseFloat(r.sale_price || 0);
                    var cost = parseFloat(r.cost        || 0);
                    html += '<tr class="clickable" data-material-idx="' + idx + '" data-row-id="' + idx + '">'
                        + '<td class="td-center td-muted td-mono">' + idx + '</td>'
                        + '<td class="td-mono td-nowrap td-muted">' + (code || '-') + '</td>'
                        + '<td class="td-muted">-</td>'
                        + '<td class="td-bold">' + name + '</td>'
                        + '<td class="td-muted">' + std + '</td>'
                        + '<td class="td-center td-muted">' + unit + '</td>'
                        + '<td class="td-center"><span class="mat-badge mat-badge-other">' + (pat || '-') + '</span></td>'
                        + '<td class="td-num">' + (sp   ? sp.toLocaleString()   : '-') + '</td>'
                        + '<td class="td-num">' + (cost ? cost.toLocaleString() : '-') + '</td>'
                        + '<td class="td-num td-muted">-</td>'
                        + '<td class="td-muted mat-col-detail">-</td>'
                        + '<td class="td-center mat-col-detail">-</td>'
                        + '<td class="td-center">-</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;
            }).catch(function () {
                tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">로드에 실패했습니다.</p></div></td></tr>';
            });
    }

    /* ── V1 이관 모드 전환 ── */
    function setV1Mode(on) {
        _state.isV1Mode = on;
        if (on && _state.isFreqMode) { _state.isFreqMode = false; var fb = document.getElementById('matTabFreqBtn'); if (fb) fb.classList.remove('active'); }
        /* 탭 활성 표시 */
        var v1Btn = document.getElementById('matTabV1Btn');
        if (v1Btn) v1Btn.classList.toggle('active', on);
        /* 일반 탭 active 해제 (V1 모드 진입 시) */
        if (on) {
            document.querySelectorAll('.mat-tab-bar .mat-tab-item:not(.mat-tab-v1), .mat-tab-bar .mat-tab-wrap').forEach(function (el) {
                el.classList.remove('active');
                var inner = el.querySelector('.mat-tab-item');
                if (inner) inner.classList.remove('active');
            });
        }
        /* 버튼 show/hide */
        document.querySelectorAll('.mat-normal-only').forEach(function (el) { el.style.display = on ? 'none' : ''; });
        document.querySelectorAll('.mat-v1-only').forEach(function (el) { el.style.display = on ? '' : 'none'; });
        /* 카테고리 사이드바: V1 모드는 숨김 */
        var sidebar = document.querySelector('.mat-sidebar');
        if (sidebar) sidebar.style.display = on ? 'none' : '';
        /* 필터 패널 닫기 */
        if (on && _filterPanel) _filterPanel.classList.remove('open');
    }

    var _v1SelectedIds = new Set();

    function reloadV1(p) {
        var search = (document.getElementById('matListSearch') || {}).value || '';
        var tbody = document.getElementById('matListBody');
        tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">V1 데이터 로딩 중...</p></div></td></tr>';
        SHV.api.get(_api, { todo: 'v1_legacy_list', p: p || 1, limit: _state.limit, search: search })
            .then(function (res) {
                var rows = (res && res.data && res.data.data) ? res.data.data : [];
                var total = (res && res.data) ? parseInt(res.data.total || '0', 10) : 0;
                if (!isFinite(total)) total = 0;
                var countEl = document.getElementById('matListCount');
                if (countEl) countEl.textContent = total.toLocaleString() + '건';
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">V1 품목이 없습니다.</p></div></td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function (r) {
                    var idx    = parseInt(r.idx || '0', 10);
                    if (!isFinite(idx)) idx = 0;
                    var code   = _escHtml(r.item_code || '');
                    var name   = _escHtml(r.name || '');
                    var std    = _escHtml(r.standard || '');
                    var unit   = _escHtml(r.unit || '');
                    var pat    = _escHtml(r.material_pattern || '');
                    var sp     = parseFloat(r.sale_price || 0);
                    var cost   = parseFloat(r.cost || 0);
                    var isCopied = r.is_migrated || r.is_copied ? 1 : 0;
                    var rowCls = isCopied ? ' mat-row-v1-copied' : '';
                    var chk    = _v1SelectedIds.has(idx) ? ' checked' : '';
                    html += '<tr class="mat-row-v1' + rowCls + '" data-v1-idx="' + idx + '">'
                          + '<td class="td-center"><input type="checkbox" class="mat-v1-chk"' + chk + ' value="' + idx + '"></td>'
                          + '<td class="td-muted td-mono">' + idx + '</td>'
                          + '<td class="td-mono td-muted">' + (code || '-') + '</td>'
                          + '<td class="td-bold">' + name + '</td>'
                          + '<td class="td-muted">' + std + '</td>'
                          + '<td class="td-center td-muted">' + unit + '</td>'
                          + '<td class="td-center"><span class="mat-badge mat-badge-other">' + (pat || '-') + '</span></td>'
                          + '<td class="td-num">' + (sp ? sp.toLocaleString() : '-') + '</td>'
                          + '<td class="td-num">' + (cost ? cost.toLocaleString() : '-') + '</td>'
                          + '<td class="td-num td-muted">-</td>'
                          + '<td class="td-muted mat-col-detail">-</td>'
                          + '<td class="td-center mat-col-detail">-</td>'
                          + '<td class="td-center">'
                          +   (isCopied ? '<span class="badge badge-success">이관완료</span>' : '<span class="badge badge-ghost">미이관</span>')
                          + '</td>'
                          + '</tr>';
                });
                tbody.innerHTML = html;
                /* 체크박스 이벤트 */
                tbody.querySelectorAll('.mat-v1-chk').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var v = parseInt(this.value, 10);
                        if (this.checked) _v1SelectedIds.add(v);
                        else _v1SelectedIds.delete(v);
                        var copyBtn = document.getElementById('matCopyFromV1Btn');
                        if (copyBtn) copyBtn.disabled = _v1SelectedIds.size === 0;
                    });
                });
                /* 복사 버튼 상태 */
                var copyBtn = document.getElementById('matCopyFromV1Btn');
                if (copyBtn) copyBtn.disabled = _v1SelectedIds.size === 0;
            }).catch(function () {
                tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><p class="empty-message">V1 데이터 로드 실패</p></div></td></tr>';
            });
    }

    /* ── 탭 클릭 ── */
    document.getElementById('matTabBar').addEventListener('click', function (e) {
        /* 자주 쓰는 품목 탭 */
        if (e.target.closest('#matTabFreqBtn')) {
            if (!_state.isFreqMode) {
                if (_state.isV1Mode) setV1Mode(false);
                setFreqMode(true);
                reloadFreq();
            }
            return;
        }
        /* V1 이관 탭 */
        if (e.target.closest('#matTabV1Btn')) {
            if (!_state.isV1Mode) {
                if (_state.isFreqMode) setFreqMode(false);
                setV1Mode(true);
                _v1SelectedIds.clear();
                reloadV1(1);
            }
            return;
        }
        var btn = e.target.closest('.mat-tab-item');
        if (!btn || btn.id === 'matTabAddBtn') return;
        if (_state.isV1Mode)   { setV1Mode(false); }
        if (_state.isFreqMode) { setFreqMode(false); }
        _state.tabIdx      = parseInt(btn.dataset.tab, 10);
        _state.categoryIdx = 0;
        reload(1);
    });

    /* ── 카테고리 트리 통합 이벤트 ── */
    var _catTree = document.getElementById('matCategoryTree');

    _catTree.addEventListener('click', function (e) {
        /* 토글 버튼 */
        var toggleBtn = e.target.closest('.mat-cat-toggle');
        if (toggleBtn) {
            e.stopPropagation();
            var tIdx = parseInt(toggleBtn.dataset.toggleIdx || '0', 10);
            var children = _catTree.querySelector('.mat-cat-children[data-parent-idx="' + tIdx + '"]');
            if (children) {
                var open = children.classList.toggle('open');
                toggleBtn.classList.toggle('open', open);
            }
            return;
        }

        /* 하위 추가 버튼 */
        var addBtn = e.target.closest('.mat-cat-add-btn');
        if (addBtn) {
            e.stopPropagation();
            openCatCreateModal(parseInt(addBtn.dataset.parentIdx || '0', 10));
            return;
        }

        /* 수정 버튼 */
        var editBtn = e.target.closest('.mat-cat-edit-btn');
        if (editBtn) {
            e.stopPropagation();
            openCatEditModal(parseInt(editBtn.dataset.catIdx || '0', 10), editBtn.dataset.catName || '');
            return;
        }

        /* 삭제 버튼 */
        var delBtn = e.target.closest('.mat-cat-del-btn');
        if (delBtn) {
            e.stopPropagation();
            openCatDeleteModal(parseInt(delBtn.dataset.catIdx || '0', 10), delBtn.dataset.catName || '');
            return;
        }

        /* 카테고리 선택 (필터링) */
        var item = e.target.closest('.mat-cat-item');
        if (!item) return;
        _state.categoryIdx = parseInt(item.dataset.catIdx || '0', 10);

        /* 활성 표시 갱신 */
        _catTree.querySelectorAll('.mat-cat-item.active').forEach(function (el) { el.classList.remove('active'); });
        item.classList.add('active');

        reload(1);
    });

    /* ── 검색 버튼 ── */
    document.getElementById('matListSearchBtn').addEventListener('click', function () { reload(1); });

    /* ── 페이저 ── */
    var _pager = document.getElementById('matListPager');
    if (_pager) {
        _pager.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-mat-page]');
            if (btn) reload(parseInt(btn.dataset.matPage, 10));
        });
    }

    /* ── 테이블 — SHV.table 초기화 ── */
    _tbl = SHV.table.init({
        tbody:       'matListBody',
        getRowId:    function (tr) { return tr.dataset.rowId || tr.dataset.materialIdx || ''; },
        onSelect:    function (ids) {
            _state.selectedIds = ids;
            _state.selectedIdx = ids.size === 1 ? parseInt(ids.values().next().value, 10) : 0;
            document.getElementById('matEditBtn').disabled = ids.size !== 1;
            var mb = document.getElementById('matMoveBtn');
            if (mb) mb.disabled = ids.size === 0;
        },
        onSort:      function (sorts) { _state.sorts = sorts; reload(1); },
        searchInput: 'matListSearch',
        onSearch:    function () { reload(1); },
        searchDelay: 300,
        rowActions:  [
            {
                icon: 'fa-eye', title: '상세',
                onClick: function (id) { SHV.router.navigate('mat_view', { idx: id }); }
            },
            {
                icon: 'fa-trash', title: '삭제', cls: 'btn-danger-ghost',
                onClick: function (id) { openItemDeleteModal([id]); }
            }
        ],
        onEnter:  function (id) { SHV.router.navigate('mat_view', { idx: id }); },
        keyboard: true
    });

    /* ── 인라인 편집 ── */
    var _tbody = document.getElementById('matListBody');
    var _activeInlineCell = null;

    function startInlineEdit(td) {
        if (_activeInlineCell && _activeInlineCell !== td) cancelInlineEdit(_activeInlineCell);
        if (td.classList.contains('mat-inline-editing')) return;
        var field   = td.dataset.inlineField;
        var raw     = td.dataset.inlineRaw || '';
        var type    = td.dataset.inlineType || 'text';
        var origHtml = td.innerHTML;
        td.classList.add('mat-inline-editing');
        td.dataset.origHtml = origHtml;
        var input = document.createElement('input');
        input.type      = type === 'number' ? 'number' : 'text';
        input.className = 'mat-inline-input';
        input.value     = raw;
        td.innerHTML = '';
        td.appendChild(input);
        input.focus();
        input.select();
        _activeInlineCell = td;

        function commitEdit() {
            var newVal  = input.value.trim();
            var tr      = td.closest('tr[data-material-idx]');
            var itemIdx = tr ? parseInt(tr.dataset.materialIdx, 10) : 0;
            if (!tr || itemIdx <= 0) { cancelInlineEdit(td); return; }
            /* 변경 없으면 취소 */
            if (newVal === raw) { cancelInlineEdit(td); return; }
            input.disabled = true;
            SHV.api.post(_api, { todo: 'item_inline_update', idx: itemIdx, field: field, value: newVal })
                .then(function (res) {
                    if (res && res.success) {
                        td.dataset.inlineRaw = newVal;
                        /* 표시 값 갱신 */
                        if (type === 'number' && newVal !== '') {
                            td.innerHTML = parseFloat(newVal).toLocaleString();
                        } else {
                            td.innerHTML = newVal || '-';
                        }
                        td.classList.remove('mat-inline-editing');
                        _activeInlineCell = null;
                        SHV.toast.success('저장되었습니다.');
                    } else {
                        cancelInlineEdit(td);
                        SHV.toast.error((res && res.message) || '저장에 실패했습니다.');
                    }
                }).catch(function () {
                    cancelInlineEdit(td);
                    SHV.toast.error('오류가 발생했습니다.');
                });
        }

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { e.preventDefault(); commitEdit(); }
            if (e.key === 'Escape') { cancelInlineEdit(td); }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { if (td.classList.contains('mat-inline-editing')) commitEdit(); }, 150);
        });
    }

    function cancelInlineEdit(td) {
        if (!td || !td.dataset.origHtml) return;
        td.innerHTML = td.dataset.origHtml;
        td.classList.remove('mat-inline-editing');
        delete td.dataset.origHtml;
        if (_activeInlineCell === td) _activeInlineCell = null;
    }

    /* ── 더블클릭 → 인라인 편집 or 상세 ── */
    if (_tbody) {
        _tbody.addEventListener('dblclick', function (e) {
            var td = e.target.closest('td.mat-inline-cell');
            if (td && !_state.isV1Mode && !_state.isFreqMode) {
                var tr = td.closest('tr[data-material-idx]');
                if (tr) startInlineEdit(td);
                return;
            }
            var tr = e.target.closest('tr[data-material-idx]');
            if (tr && !_state.isV1Mode) SHV.router.navigate('mat_view', { idx: tr.dataset.materialIdx });
        });
    }

    /* ── 수정 버튼 ── */
    document.getElementById('matEditBtn').addEventListener('click', function () {
        if (_state.selectedIdx > 0) {
            SHV.router.navigate('mat_view', { idx: _state.selectedIdx });
        }
    });

    /* ── 이동/복사 버튼 ── */
    var _moveBtn = document.getElementById('matMoveBtn');
    if (_moveBtn) _moveBtn.addEventListener('click', function () {
        if (_state.selectedIds.size === 0) return;
        openMoveModal(Array.from(_state.selectedIds));
    });

    /* ── 품목 삭제 모달 ── */
    var _itemDeleteModal = document.getElementById('matItemDeleteModal');
    var _deleteIds       = [];

    function openItemDeleteModal(ids) {
        _deleteIds = ids;
        document.getElementById('matItemDeleteCount').textContent = ids.length;
        document.getElementById('matItemDeleteSubmitBtn').disabled = false;
        _itemDeleteModal.classList.add('open');
    }
    function closeItemDeleteModal() { _itemDeleteModal.classList.remove('open'); }

    document.getElementById('matItemDeleteCloseBtn').addEventListener('click', closeItemDeleteModal);
    document.getElementById('matItemDeleteCancelBtn').addEventListener('click', closeItemDeleteModal);
    _itemDeleteModal.addEventListener('click', function (e) {
        if (e.target === _itemDeleteModal) closeItemDeleteModal();
    });

    document.getElementById('matItemDeleteSubmitBtn').addEventListener('click', function () {
        if (_deleteIds.length === 0) return;
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'material_delete', idx_list: _deleteIds.join(',') })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeItemDeleteModal();
                    SHV.toast.success(_deleteIds.length + '건이 삭제되었습니다.');
                    if (_tbl) _tbl.clearSelection();
                    reload(1);
                } else {
                    SHV.toast.error((res && res.message) || '삭제에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });


    /* ── 이미지 업로드 헬퍼 ── */
    function initImgUpload(areaId, inputId, previewId, removeId) {
        var area    = document.getElementById(areaId);
        var input   = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        var remove  = document.getElementById(removeId);
        if (!area || !input || !preview || !remove) return;

        preview.style.display = 'none';
        remove.style.display  = 'none';

        area.addEventListener('click', function (e) {
            if (e.target === remove || remove.contains(e.target)) return;
            input.click();
        });
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                preview.src = ev.target.result;
                preview.style.display = 'block';
                remove.style.display  = 'flex';
                area.classList.add('has-file');
            };
            reader.readAsDataURL(file);
        });
        remove.addEventListener('click', function (e) {
            e.stopPropagation();
            input.value           = '';
            preview.src           = '';
            preview.style.display = 'none';
            remove.style.display  = 'none';
            area.classList.remove('has-file');
        });
    }

    initImgUpload('mcBannerArea', 'mcBannerFile', 'mcBannerPreview', 'mcBannerRemove');
    initImgUpload('mcDetailArea', 'mcDetailFile', 'mcDetailPreview', 'mcDetailRemove');

    /* ── 등록 모달 ── */
    var _createModal = document.getElementById('matCreateModal');

    function resetImg(areaId, inputId, previewId, removeId) {
        var area = document.getElementById(areaId), input = document.getElementById(inputId),
            prev = document.getElementById(previewId), rem  = document.getElementById(removeId);
        if (!area) return;
        input.value = ''; prev.src = '';
        prev.style.display = 'none'; rem.style.display = 'none';
        area.classList.remove('has-file');
    }

    function openCreateModal() {
        ['mcName','mcStandard','mcUnit','mcItemCode','mcBarcode',
         'mcSalePrice','mcCost','mcSupplyPrice','mcPurchasePrice',
         'mcWorkPrice','mcTaxPrice','mcSafety','mcQty','mcSafetyCount',
         'mcBaseCount','mcContents','mcMemo'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('mcPattern').value          = '';
        document.getElementById('mcTabIdx').value           = _state.tabIdx > 0 ? String(_state.tabIdx) : '0';
        document.getElementById('mcCategoryIdx').value      = _state.categoryIdx > 0 ? String(_state.categoryIdx) : '0';
        document.getElementById('mcAttribute').value        = '';
        document.getElementById('mcHidden').value           = '0';
        document.getElementById('mcRelativePurchase').value = 'O';
        document.getElementById('mcInventory').value        = '무';
        resetImg('mcBannerArea', 'mcBannerFile', 'mcBannerPreview', 'mcBannerRemove');
        resetImg('mcDetailArea', 'mcDetailFile', 'mcDetailPreview', 'mcDetailRemove');
        _createModal.classList.add('open');
        document.getElementById('mcName').focus();
    }

    function closeCreateModal() { _createModal.classList.remove('open'); }

    document.getElementById('matCreateBtn').addEventListener('click', openCreateModal);
    document.getElementById('matCreateCloseBtn').addEventListener('click', closeCreateModal);
    document.getElementById('matCreateCancelBtn').addEventListener('click', closeCreateModal);
    _createModal.addEventListener('click', function (e) {
        if (e.target === _createModal) closeCreateModal();
    });

    /* ── 등록 제출 ── */
    document.getElementById('matCreateSubmitBtn').addEventListener('click', function () {
        var name    = document.getElementById('mcName').value.trim();
        var pattern = document.getElementById('mcPattern').value;
        var sale    = document.getElementById('mcSalePrice').value.trim();
        var cost    = document.getElementById('mcCost').value.trim();

        if (!name)    { SHV.toast.warn('품목명은 필수입니다.'); document.getElementById('mcName').focus(); return; }
        if (!pattern) { SHV.toast.warn('유형을 선택해주세요.'); document.getElementById('mcPattern').focus(); return; }
        if (sale === '') { SHV.toast.warn('판매단가를 입력해주세요.'); document.getElementById('mcSalePrice').focus(); return; }
        if (cost === '') { SHV.toast.warn('원가를 입력해주세요.'); document.getElementById('mcCost').focus(); return; }

        var _btn = document.getElementById('matCreateSubmitBtn');
        _btn.disabled = true;

        var fd = new FormData();
        fd.append('todo', 'material_create');
        [['item_code','mcItemCode'],['name','mcName'],['material_pattern','mcPattern'],
         ['standard','mcStandard'],['unit','mcUnit'],['barcode','mcBarcode'],
         ['tab_idx','mcTabIdx'],['category_idx','mcCategoryIdx'],
         ['attribute','mcAttribute'],['hidden','mcHidden'],
         ['relative_purchase','mcRelativePurchase'],
         ['sale_price','mcSalePrice'],['cost','mcCost'],
         ['supply_price','mcSupplyPrice'],['purchase_price','mcPurchasePrice'],
         ['work_price','mcWorkPrice'],['tax_price','mcTaxPrice'],
         ['safety','mcSafety'],['inventory_management','mcInventory'],
         ['qty','mcQty'],['safety_count','mcSafetyCount'],
         ['base_count','mcBaseCount'],['contents','mcContents'],['memo','mcMemo']
        ].forEach(function (pair) {
            var el = document.getElementById(pair[1]);
            if (el) fd.append(pair[0], el.value.trim ? el.value.trim() : el.value);
        });

        var bannerFile = document.getElementById('mcBannerFile').files[0];
        var detailFile = document.getElementById('mcDetailFile').files[0];
        if (bannerFile) fd.append('upload_files_banner', bannerFile);
        if (detailFile) fd.append('upload_files_detail', detailFile);

        SHV.api.upload(_api, fd).then(function (res) {
            _btn.disabled = false;
            if (res && res.success) {
                closeCreateModal();
                SHV.toast.success('품목이 등록되었습니다.');
                reload(1);
            } else {
                SHV.toast.error((res && res.message) || '등록에 실패했습니다.');
            }
        }).catch(function () {
            _btn.disabled = false;
            SHV.toast.error('오류가 발생했습니다.');
        });
    });

    /* ════════════════════════════════════════
       카테고리 CRUD
    ════════════════════════════════════════ */

    /* ── 추가 모달 ── */
    var _catCreateModal = document.getElementById('matCatCreateModal');

    function openCatCreateModal(parentIdx) {
        document.getElementById('mcatName').value       = '';
        document.getElementById('mcatParentIdx').value  = String(parentIdx || 0);
        document.getElementById('mcatParentLabel').textContent =
            parentIdx > 0 ? '하위 카테고리' : '최상위 카테고리';
        document.getElementById('matCatCreateSubmitBtn').disabled = false;
        _catCreateModal.classList.add('open');
        setTimeout(function () { document.getElementById('mcatName').focus(); }, 50);
    }
    function closeCatCreateModal() { _catCreateModal.classList.remove('open'); }

    document.getElementById('matCatHeaderAddBtn').addEventListener('click', function () { openCatCreateModal(0); });
    document.getElementById('matCatCreateCloseBtn').addEventListener('click', closeCatCreateModal);
    document.getElementById('matCatCreateCancelBtn').addEventListener('click', closeCatCreateModal);
    _catCreateModal.addEventListener('click', function (e) { if (e.target === _catCreateModal) closeCatCreateModal(); });

    document.getElementById('matCatCreateSubmitBtn').addEventListener('click', function () {
        var name      = document.getElementById('mcatName').value.trim();
        var parentIdx = parseInt(document.getElementById('mcatParentIdx').value || '0', 10);
        if (!name) { SHV.toast.warn('카테고리명을 입력하세요.'); document.getElementById('mcatName').focus(); return; }
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'category_create', name: name, parent_idx: parentIdx })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeCatCreateModal();
                    SHV.toast.success('카테고리가 추가되었습니다.');
                    reload(_state.page || 1);
                } else {
                    SHV.toast.error((res && res.message) || '추가에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* ── 수정 모달 ── */
    var _catEditModal = document.getElementById('matCatEditModal');

    function openCatEditModal(idx, name) {
        document.getElementById('mcatEditIdx').value  = String(idx);
        document.getElementById('mcatEditName').value = name;
        document.getElementById('matCatEditSubmitBtn').disabled = false;
        _catEditModal.classList.add('open');
        setTimeout(function () {
            var el = document.getElementById('mcatEditName');
            el.focus(); el.select();
        }, 50);
    }
    function closeCatEditModal() { _catEditModal.classList.remove('open'); }

    document.getElementById('matCatEditCloseBtn').addEventListener('click', closeCatEditModal);
    document.getElementById('matCatEditCancelBtn').addEventListener('click', closeCatEditModal);
    _catEditModal.addEventListener('click', function (e) { if (e.target === _catEditModal) closeCatEditModal(); });

    document.getElementById('matCatEditSubmitBtn').addEventListener('click', function () {
        var idx  = parseInt(document.getElementById('mcatEditIdx').value || '0', 10);
        var name = document.getElementById('mcatEditName').value.trim();
        if (!name) { SHV.toast.warn('카테고리명을 입력하세요.'); document.getElementById('mcatEditName').focus(); return; }
        if (idx <= 0) { SHV.toast.error('오류: idx 없음'); return; }
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'category_update', idx: idx, name: name })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeCatEditModal();
                    SHV.toast.success('카테고리명이 수정되었습니다.');
                    reload(_state.page || 1);
                } else {
                    SHV.toast.error((res && res.message) || '수정에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* ── 삭제 확인 모달 ── */
    var _catDeleteModal = document.getElementById('matCatDeleteModal');

    function openCatDeleteModal(idx, name) {
        document.getElementById('mcatDelIdx').value           = String(idx);
        document.getElementById('mcatDelName').textContent    = name;
        document.getElementById('matCatDeleteSubmitBtn').disabled = false;
        _catDeleteModal.classList.add('open');
    }
    function closeCatDeleteModal() { _catDeleteModal.classList.remove('open'); }

    document.getElementById('matCatDeleteCloseBtn').addEventListener('click', closeCatDeleteModal);
    document.getElementById('matCatDeleteCancelBtn').addEventListener('click', closeCatDeleteModal);
    _catDeleteModal.addEventListener('click', function (e) { if (e.target === _catDeleteModal) closeCatDeleteModal(); });

    document.getElementById('matCatDeleteSubmitBtn').addEventListener('click', function () {
        var idx = parseInt(document.getElementById('mcatDelIdx').value || '0', 10);
        if (idx <= 0) return;
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'category_delete', idx: idx })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeCatDeleteModal();
                    SHV.toast.success('카테고리가 삭제되었습니다.');
                    if (_state.categoryIdx === idx) _state.categoryIdx = 0;
                    reload(1);
                } else {
                    SHV.toast.error((res && res.message) || '삭제에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* ════════════════════════════════════════
       카테고리 드래그 앤 드롭 정렬
    ════════════════════════════════════════ */
    (function () {
        var _dragSrc = null;

        _catTree.addEventListener('dragstart', function (e) {
            var item = e.target.closest('.mat-cat-item[draggable="true"]');
            if (!item || parseInt(item.dataset.catIdx || '0', 10) === 0) { e.preventDefault(); return; }
            _dragSrc = item.parentNode; /* .mat-cat-node */
            if (!_dragSrc || !_dragSrc.classList.contains('mat-cat-node')) { _dragSrc = null; e.preventDefault(); return; }
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', item.dataset.catIdx || '');
            setTimeout(function () { if (_dragSrc) _dragSrc.classList.add('dragging'); }, 0);
        });

        _catTree.addEventListener('dragover', function (e) {
            if (!_dragSrc) return;
            var item = e.target.closest('.mat-cat-item');
            if (!item || parseInt(item.dataset.catIdx || '0', 10) === 0) return;
            var targetNode = item.parentNode;
            if (!targetNode || !targetNode.classList.contains('mat-cat-node')) return;
            if (targetNode === _dragSrc || targetNode.parentNode !== _dragSrc.parentNode) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            _catTree.querySelectorAll('.mat-cat-node.drag-over').forEach(function (n) { n.classList.remove('drag-over'); });
            targetNode.classList.add('drag-over');
        });

        _catTree.addEventListener('dragleave', function (e) {
            var item = e.target.closest('.mat-cat-item');
            if (item && item.parentNode) item.parentNode.classList.remove('drag-over');
        });

        _catTree.addEventListener('drop', function (e) {
            if (!_dragSrc) return;
            var item = e.target.closest('.mat-cat-item');
            if (!item || parseInt(item.dataset.catIdx || '0', 10) === 0) return;
            var targetNode = item.parentNode;
            if (!targetNode || !targetNode.classList.contains('mat-cat-node')) return;
            if (targetNode === _dragSrc || targetNode.parentNode !== _dragSrc.parentNode) return;
            e.preventDefault();
            targetNode.classList.remove('drag-over');

            var container = _dragSrc.parentNode;
            var rect      = targetNode.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) {
                container.insertBefore(_dragSrc, targetNode);
            } else {
                container.insertBefore(_dragSrc, targetNode.nextSibling);
            }

            /* 순서 수집 → API */
            var orders   = [];
            var siblings = container.querySelectorAll(':scope > .mat-cat-node');
            for (var i = 0; i < siblings.length; i++) {
                var cIdx = parseInt(siblings[i].dataset.catIdx || '0', 10);
                var pIdx = parseInt(siblings[i].dataset.parentIdx || '0', 10);
                if (cIdx > 0) orders.push({ idx: cIdx, sort_order: i + 1, parent_idx: pIdx });
            }

            SHV.api.post(_api, { todo: 'category_reorder', orders: JSON.stringify(orders) })
                .then(function (res) {
                    if (res && res.success) SHV.toast.success('순서가 저장되었습니다.');
                    else SHV.toast.error('순서 저장에 실패했습니다.');
                })
                .catch(function () { SHV.toast.error('오류가 발생했습니다.'); });
        });

        _catTree.addEventListener('dragend', function () {
            if (_dragSrc) _dragSrc.classList.remove('dragging');
            _catTree.querySelectorAll('.mat-cat-node.drag-over').forEach(function (n) { n.classList.remove('drag-over'); });
            _dragSrc = null;
        });
    }());

    /* ════════════════════════════════════════
       V1 → V2 신규 복사
    ════════════════════════════════════════ */
    var _v1CopyModal = document.getElementById('matV1CopyModal');

    function openV1CopyModal() {
        document.getElementById('matV1CopyCount').textContent = _v1SelectedIds.size;
        document.getElementById('matV1CopySubmitBtn').disabled = false;
        _v1CopyModal.classList.add('open');
    }
    function closeV1CopyModal() { _v1CopyModal.classList.remove('open'); }

    var _copyFromV1Btn = document.getElementById('matCopyFromV1Btn');
    if (_copyFromV1Btn) {
        _copyFromV1Btn.disabled = true;
        _copyFromV1Btn.addEventListener('click', function () {
            if (_v1SelectedIds.size === 0) { SHV.toast.warn('복사할 품목을 선택하세요.'); return; }
            openV1CopyModal();
        });
    }
    document.getElementById('matV1CopyCloseBtn').addEventListener('click', closeV1CopyModal);
    document.getElementById('matV1CopyCancelBtn').addEventListener('click', closeV1CopyModal);
    _v1CopyModal.addEventListener('click', function (e) { if (e.target === _v1CopyModal) closeV1CopyModal(); });

    document.getElementById('matV1CopySubmitBtn').addEventListener('click', function () {
        if (_v1SelectedIds.size === 0) return;
        var tabIdx      = parseInt(document.getElementById('v1copyTabIdx').value      || '0', 10);
        var categoryIdx = parseInt(document.getElementById('v1copyCategoryIdx').value || '0', 10);
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, {
            todo:         'copy_from_v1',
            idx_list:     Array.from(_v1SelectedIds).join(','),
            tab_idx:      tabIdx,
            category_idx: categoryIdx
        }).then(function (res) {
            self.disabled = false;
            if (res && res.success) {
                closeV1CopyModal();
                var msg = (res.message || '') + (res.data && res.data.msg ? ' ' + res.data.msg : '');
                SHV.toast.success(msg || 'V2로 복사되었습니다.');
                _v1SelectedIds.clear();
                reloadV1(1);
            } else {
                SHV.toast.error((res && res.message) || '복사에 실패했습니다.');
            }
        }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* ════════════════════════════════════════
       탭 관리 (추가 / 삭제)
    ════════════════════════════════════════ */

    /* 탭 추가 모달 */
    var _tabCreateModal = document.getElementById('matTabCreateModal');

    function openTabCreateModal() {
        document.getElementById('mtabName').value = '';
        document.getElementById('matTabCreateSubmitBtn').disabled = false;
        _tabCreateModal.classList.add('open');
        setTimeout(function () { document.getElementById('mtabName').focus(); }, 50);
    }
    function closeTabCreateModal() { _tabCreateModal.classList.remove('open'); }

    var _tabAddBtn = document.getElementById('matTabAddBtn');
    if (_tabAddBtn) _tabAddBtn.addEventListener('click', openTabCreateModal);
    document.getElementById('matTabCreateCloseBtn').addEventListener('click', closeTabCreateModal);
    document.getElementById('matTabCreateCancelBtn').addEventListener('click', closeTabCreateModal);
    _tabCreateModal.addEventListener('click', function (e) { if (e.target === _tabCreateModal) closeTabCreateModal(); });

    document.getElementById('matTabCreateSubmitBtn').addEventListener('click', function () {
        var name = document.getElementById('mtabName').value.trim();
        if (!name) { SHV.toast.warn('탭 이름을 입력하세요.'); document.getElementById('mtabName').focus(); return; }
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'tab_insert', name: name })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeTabCreateModal();
                    SHV.toast.success('탭이 추가되었습니다.');
                    reload(1);
                } else {
                    SHV.toast.error((res && res.message) || '탭 추가에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* 탭 삭제 모달 */
    var _tabDeleteModal = document.getElementById('matTabDeleteModal');

    function openTabDeleteModal(idx, name) {
        document.getElementById('mtabDelIdx').value = String(idx);
        document.getElementById('mtabDelName').textContent = name;
        document.getElementById('matTabDeleteSubmitBtn').disabled = false;
        _tabDeleteModal.classList.add('open');
    }
    function closeTabDeleteModal() { _tabDeleteModal.classList.remove('open'); }

    document.getElementById('matTabDeleteCloseBtn').addEventListener('click', closeTabDeleteModal);
    document.getElementById('matTabDeleteCancelBtn').addEventListener('click', closeTabDeleteModal);
    _tabDeleteModal.addEventListener('click', function (e) { if (e.target === _tabDeleteModal) closeTabDeleteModal(); });

    document.getElementById('matTabDeleteSubmitBtn').addEventListener('click', function () {
        var idx = parseInt(document.getElementById('mtabDelIdx').value || '0', 10);
        if (idx <= 0) return;
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, { todo: 'tab_delete', idx: idx })
            .then(function (res) {
                self.disabled = false;
                if (res && res.success) {
                    closeTabDeleteModal();
                    SHV.toast.success('탭이 삭제되었습니다.');
                    if (_state.tabIdx === idx) _state.tabIdx = 0;
                    reload(1);
                } else {
                    SHV.toast.error((res && res.message) || '탭 삭제에 실패했습니다.');
                }
            }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* 탭 삭제 버튼 — 탭 바 이벤트 위임 */
    document.getElementById('matTabBar').addEventListener('click', function (e) {
        var delBtn = e.target.closest('.mat-tab-del-btn');
        if (delBtn) {
            e.stopPropagation();
            openTabDeleteModal(
                parseInt(delBtn.dataset.tabIdx || '0', 10),
                delBtn.dataset.tabName || ''
            );
        }
    });

    /* ════════════════════════════════════════
       품목 이동 / 복사
    ════════════════════════════════════════ */
    var _moveModal = document.getElementById('matMoveModal');
    var _moveIds   = [];

    function openMoveModal(ids) {
        _moveIds = ids;
        document.getElementById('matMoveCount').textContent = ids.length;
        document.getElementById('mmoveTabIdx').value      = _state.tabIdx > 0 ? String(_state.tabIdx) : '0';
        document.getElementById('mmoveCategoryIdx').value = _state.categoryIdx > 0 ? String(_state.categoryIdx) : '0';
        var radios = document.querySelectorAll('input[name="mmoveType"]');
        radios.forEach(function (r) { r.checked = r.value === 'move'; });
        document.getElementById('matMoveSubmitBtn').disabled = false;
        _moveModal.classList.add('open');
    }
    function closeMoveModal() { _moveModal.classList.remove('open'); }

    document.getElementById('matMoveCloseBtn').addEventListener('click', closeMoveModal);
    document.getElementById('matMoveCancelBtn').addEventListener('click', closeMoveModal);
    _moveModal.addEventListener('click', function (e) { if (e.target === _moveModal) closeMoveModal(); });

    document.getElementById('matMoveSubmitBtn').addEventListener('click', function () {
        if (_moveIds.length === 0) return;
        var tabIdx      = parseInt(document.getElementById('mmoveTabIdx').value      || '0', 10);
        var categoryIdx = parseInt(document.getElementById('mmoveCategoryIdx').value || '0', 10);
        var copyMode    = document.querySelector('input[name="mmoveType"]:checked');
        var isCopy      = copyMode && copyMode.value === 'copy' ? 1 : 0;
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, {
            todo:            'move_items',
            idx_list:        _moveIds.join(','),
            target_tab_idx:  tabIdx,
            target_cat_idx:  categoryIdx,
            copy:            isCopy
        }).then(function (res) {
            self.disabled = false;
            if (res && res.success) {
                closeMoveModal();
                SHV.toast.success(isCopy ? '복사되었습니다.' : '이동되었습니다.');
                if (_tbl) _tbl.clearSelection();
                reload(1);
            } else {
                SHV.toast.error((res && res.message) || '처리에 실패했습니다.');
            }
        }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    /* ════════════════════════════════════════
       자재번호 일괄 생성
    ════════════════════════════════════════ */
    var _fillCodeModal = document.getElementById('matFillCodeModal');

    function openFillCodeModal() {
        document.getElementById('mfillPrefix').value      = 'MAT';
        document.getElementById('mfillTabIdx').value      = _state.tabIdx > 0 ? String(_state.tabIdx) : '0';
        document.getElementById('mfillCategoryIdx').value = _state.categoryIdx > 0 ? String(_state.categoryIdx) : '0';
        document.getElementById('matFillCodeSubmitBtn').disabled = false;
        _fillCodeModal.classList.add('open');
        setTimeout(function () {
            var p = document.getElementById('mfillPrefix');
            if (p) { p.focus(); p.select(); }
        }, 50);
    }
    function closeFillCodeModal() { _fillCodeModal.classList.remove('open'); }

    var _fillCodeBtn = document.getElementById('matFillCodeBtn');
    if (_fillCodeBtn) _fillCodeBtn.addEventListener('click', openFillCodeModal);
    document.getElementById('matFillCodeCloseBtn').addEventListener('click', closeFillCodeModal);
    document.getElementById('matFillCodeCancelBtn').addEventListener('click', closeFillCodeModal);
    _fillCodeModal.addEventListener('click', function (e) { if (e.target === _fillCodeModal) closeFillCodeModal(); });

    document.getElementById('matFillCodeSubmitBtn').addEventListener('click', function () {
        var prefix      = (document.getElementById('mfillPrefix').value || 'MAT').trim();
        var tabIdx      = parseInt(document.getElementById('mfillTabIdx').value      || '0', 10);
        var categoryIdx = parseInt(document.getElementById('mfillCategoryIdx').value || '0', 10);
        this.disabled = true;
        var self = this;
        SHV.api.post(_api, {
            todo:         'fill_item_codes',
            tab_idx:      tabIdx,
            category_idx: categoryIdx,
            prefix:       prefix
        }).then(function (res) {
            self.disabled = false;
            if (res && res.success) {
                closeFillCodeModal();
                var msg = (res.message || '자재번호가 생성되었습니다.');
                if (res.data && res.data.count !== undefined) msg = res.data.count + '건의 자재번호가 생성되었습니다.';
                SHV.toast.success(msg);
                reload(1);
            } else {
                SHV.toast.error((res && res.message) || '생성에 실패했습니다.');
            }
        }).catch(function () { self.disabled = false; SHV.toast.error('오류가 발생했습니다.'); });
    });

    SHV.pages = SHV.pages || {};
    SHV.pages['material_list'] = { destroy: function () {} };
})();
</script>
