<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/MaterialService.php';

function matViewH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function matViewJ(mixed $value): string
{
    return htmlspecialchars((string)json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

function matViewTableExists(PDO $db, string $tableName): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=?");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}


$auth    = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-view"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$itemIdx = (int)($_GET['idx'] ?? $_GET['item_idx'] ?? 0);
if ($itemIdx <= 0) {
    http_response_code(400);
    echo '<section data-page="mat-view"><div class="empty-state"><p class="empty-message">유효하지 않은 품목입니다.</p></div></section>';
    exit;
}

$db      = DbConnection::get();
$service = new MaterialService($db);
$row     = $service->detail($itemIdx);

if (!is_array($row) || $row === []) {
    http_response_code(404);
    echo '<section data-page="mat-view"><div class="empty-state"><p class="empty-message">품목을 찾을 수 없습니다.</p></div></section>';
    exit;
}

/* ── 구성품 조회 (componentList 사용 — follow_mode 포함) ── */
$components = $service->componentList($itemIdx);

/* ── 탭 / 카테고리 목록 ── */
$tabRows = [];
if (matViewTableExists($db, 'Tb_ItemTab')) {
    $stmt    = $db->query('SELECT idx, name FROM Tb_ItemTab ORDER BY idx ASC');
    $tabRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
$categoryRows = [];
if (matViewTableExists($db, 'Tb_ItemCategory')) {
    try {
        $stmt         = $db->query('SELECT idx, name FROM Tb_ItemCategory WHERE ISNULL(is_deleted,0)=0 ORDER BY idx ASC');
        $categoryRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $categoryRows = [];
    }
}

/* ── 편의 변수 ── */
$inventoryRaw   = trim((string)($row['inventory_management'] ?? '무'));
$inventory      = ($inventoryRaw === '유' || strtoupper($inventoryRaw) === 'Y' || $inventoryRaw === '1') ? '유' : '무';
$pattern        = trim((string)($row['material_pattern'] ?? ''));
$hidden         = (string)($row['hidden'] ?? '0');
$isSplit        = (string)($row['is_split'] ?? '');
$followMode     = (string)($row['follow_mode'] ?? '');
$qty            = $row['qty'] ?? null;
$safetyCount    = is_numeric($row['safety_count'] ?? null) ? (float)$row['safety_count'] : null;
$tenantId       = $context['tenant_id'] ?? $context['company_idx'] ?? 'default';
$legacyImgBase  = 'https://shvq.kr/SHVQ_V2/uploads/mat/';
$newImgBase     = 'https://shvq.kr/SHVQ_V2/uploads/' . $tenantId . '/mat/';
$bannerImg      = trim((string)($row['banner_img'] ?? $row['upload_files_banner'] ?? ''));
$detailImg      = trim((string)($row['detail_img'] ?? $row['upload_files_detail'] ?? ''));

function matViewImgUrl(string $filename, string $legacyBase, string $newBase): string
{
    if ($filename === '') return '';
    if (str_starts_with($filename, 'http')) return $filename;
    // V2 업로드 파일: StorageService prefix 패턴 (banner_/detail_ + 타임스탬프)
    // 예: banner_11_20260413120000_a1b2.jpg / detail_20260413120000_c3d4.png
    if (preg_match('/^(banner|detail)_/', $filename)) {
        return $newBase . $filename;
    }
    return $legacyBase . $filename;
}

$bannerUrl = matViewImgUrl($bannerImg, $legacyImgBase, $newImgBase);
$detailUrl = matViewImgUrl($detailImg, $legacyImgBase, $newImgBase);

/* ── 유형 배지 맵 ── */
$patternBadgeMap = [
    '견적' => 'mat-badge mat-badge-estimate',
    '지급' => 'mat-badge mat-badge-labor',
    '판매' => 'mat-badge mat-badge-sale',
    '비품' => 'mat-badge mat-badge-fixture',
    '구성품' => 'mat-badge mat-badge-component',
    '기타' => 'mat-badge mat-badge-other',
];
$patternCls = $patternBadgeMap[$pattern] ?? 'mat-badge mat-badge-other';

/* ── 재고 수량 경고 판단 ── */
$qtyWarn = ($qty !== null && $safetyCount !== null && (float)$qty < $safetyCount);

/* ── section data 전달용 JSON ── */
$rowData = [
    'idx'                 => (int)$itemIdx,
    'item_code'           => (string)($row['item_code'] ?? ''),
    'name'                => (string)($row['name'] ?? ''),
    'material_pattern'    => $pattern,
    'standard'            => (string)($row['standard'] ?? ''),
    'unit'                => (string)($row['unit'] ?? ''),
    'barcode'             => (string)($row['barcode'] ?? ''),
    'tab_idx'             => (int)($row['tab_idx'] ?? 0),
    'category_idx'        => (int)($row['category_idx'] ?? 0),
    'attribute'           => (string)($row['attribute'] ?? ''),
    'hidden'              => $hidden,
    'relative_purchase'   => (string)($row['relative_purchase'] ?? 'O'),
    'sale_price'          => (string)($row['sale_price'] ?? '0'),
    'cost'                => (string)($row['cost'] ?? '0'),
    'supply_price'        => (string)($row['supply_price'] ?? ''),
    'purchase_price'      => (string)($row['purchase_price'] ?? ''),
    'work_price'          => (string)($row['work_price'] ?? ''),
    'tax_price'           => (string)($row['tax_price'] ?? ''),
    'safety'              => (string)($row['safety'] ?? ''),
    'inventory_management'=> $inventory,
    'qty'                 => (string)($row['qty'] ?? ''),
    'safety_count'        => (string)($row['safety_count'] ?? ''),
    'base_count'          => (string)($row['base_count'] ?? ''),
    'company_idx'         => (int)($row['company_idx'] ?? 0),
    'company_name'        => (string)($row['company_name'] ?? ''),
    'is_split'            => $isSplit,
    'follow_mode'         => $followMode,
    'banner_img'          => $bannerImg,
    'detail_img'          => $detailImg,
    'contents'            => (string)($row['contents'] ?? ''),
    'memo'                => (string)($row['memo'] ?? ''),
];
?>
<link rel="stylesheet" href="css/v2/pages/mat.css?v=20260415r">
<section
    data-page="mat-view"
    data-mat-api="dist_process/saas/Material.php"
    data-material-idx="<?= (int)$itemIdx ?>"
    data-row="<?= matViewJ($rowData) ?>"
>
    <div class="page-header">
        <button class="btn btn-ghost btn-sm mv-sv" onclick="history.back()">
            <i class="fa fa-arrow-left"></i>
        </button>
        <div class="mat-view-header-info">
            <div class="mat-view-header-name">
                <h2 class="page-title mv-sv">
                    <?= matViewH((string)($row['name'] ?? '')) ?>
                </h2>
                <?php if ($pattern !== ''): ?>
                    <span class="<?= $patternCls ?> mv-sv"><?= matViewH($pattern) ?></span>
                <?php endif; ?>
                <div class="mv-se mat-hdr-edit-row">
                    <input id="meName" type="text" class="form-input mat-hdr-name-input"
                           value="<?= matViewH((string)($row['name'] ?? '')) ?>">
                    <select id="mePattern" class="form-select mat-hdr-pattern-select">
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
            <p class="page-subtitle td-mono mv-sv"><?= matViewH((string)($row['item_code'] ?? '')) ?></p>
        </div>
        <div class="flex items-center gap-1 ml-auto">
            <button id="matEditBtn" class="btn btn-outline btn-sm mv-sv">
                <i class="fa fa-edit"></i> 수정
            </button>
            <button id="matDeleteBtn" class="btn btn-outline-warn btn-sm mv-sv">
                <i class="fa fa-trash"></i> 삭제
            </button>
            <span class="mat-edit-badge mv-se"><i class="fa fa-circle"></i> 수정중</span>
            <button id="matEditSubmitBtn" class="btn btn-primary btn-sm mv-se">
                <i class="fa fa-save"></i> 저장
            </button>
            <button id="matEditCancelBtn" class="btn btn-ghost btn-sm mv-se">취소</button>
        </div>
    </div>

    <!-- ── 메인 바디 ── -->
    <div class="mat-view-inner">

        <!-- ── 좌: 이미지 탭 + KPI + 감사 ── -->
        <div class="mat-view-left">

            <!-- 이미지 + KPI 묶음 카드 -->
            <div class="mat-left-group">

            <!-- 이미지 스택 (배너/상세 동시 표시) — 조회 모드 -->
            <div class="mat-view-img-stack mv-sv">
                <div class="mat-view-img-slot">
                    <span class="mat-view-img-slot-label">배너</span>
                    <div class="mat-view-img-slot-area">
                        <?php if ($bannerUrl !== ''): ?>
                            <img src="<?= matViewH($bannerUrl) ?>" alt="배너이미지">
                        <?php else: ?>
                            <div class="mat-view-img-empty">
                                <i class="fa fa-image"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mat-view-img-slot">
                    <span class="mat-view-img-slot-label">상세</span>
                    <div class="mat-view-img-slot-area">
                        <?php if ($detailUrl !== ''): ?>
                            <img src="<?= matViewH($detailUrl) ?>" alt="상세이미지">
                        <?php else: ?>
                            <div class="mat-view-img-empty">
                                <i class="fa fa-image"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 이미지 업로드 — 편집 모드 -->
            <div class="mat-view-img-edit mv-se">
                <div class="mat-img-pair">
                    <div>
                        <span class="mat-img-label">배너사진</span>
                        <?php if ($bannerUrl !== ''): ?>
                            <div class="mat-img-current-wrap" id="meBannerCurrentWrap">
                                <img src="<?= matViewH($bannerUrl) ?>" alt="현재 배너" id="meBannerCurrentImg">
                                <button type="button" class="mat-img-remove" id="meBannerCurrentRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="mat-img-current-wrap mat-img-current-wrap--empty" id="meBannerCurrentWrap"></div>
                        <?php endif; ?>
                        <div class="mat-img-upload-area" id="meBannerArea">
                            <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                            <span>새 배너 이미지 선택</span>
                            <img class="mat-img-preview" id="meBannerPreview" alt="배너미리보기">
                            <button type="button" class="mat-img-remove" id="meBannerRemove">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        <input type="file" id="meBannerFile" accept="image/*" class="d-none">
                        <input type="hidden" id="meDelBanner" value="0">
                    </div>
                    <div>
                        <span class="mat-img-label">상세이미지</span>
                        <?php if ($detailUrl !== ''): ?>
                            <div class="mat-img-current-wrap" id="meDetailCurrentWrap">
                                <img src="<?= matViewH($detailUrl) ?>" alt="현재 상세" id="meDetailCurrentImg">
                                <button type="button" class="mat-img-remove" id="meDetailCurrentRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="mat-img-current-wrap mat-img-current-wrap--empty" id="meDetailCurrentWrap"></div>
                        <?php endif; ?>
                        <div class="mat-img-upload-area" id="meDetailArea">
                            <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                            <span>새 상세 이미지 선택</span>
                            <img class="mat-img-preview" id="meDetailPreview" alt="상세미리보기">
                            <button type="button" class="mat-img-remove" id="meDetailRemove">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        <input type="file" id="meDetailFile" accept="image/*" class="d-none">
                        <input type="hidden" id="meDelDetail" value="0">
                    </div>
                </div>
            </div>

            <!-- KPI -->
            <div class="mat-view-kpi">
                <div class="mat-view-kpi-item">
                    <span class="mat-view-kpi-label">판매단가</span>
                    <span class="mat-view-kpi-value kpi-primary">
                        <?php $sp = $row['sale_price'] ?? null; echo is_numeric($sp) ? number_format((float)$sp) : '-'; ?>
                    </span>
                </div>
                <div class="mat-view-kpi-item">
                    <span class="mat-view-kpi-label">원가</span>
                    <span class="mat-view-kpi-value">
                        <?php $cp = $row['cost'] ?? null; echo is_numeric($cp) ? number_format((float)$cp) : '-'; ?>
                    </span>
                </div>
                <div class="mat-view-kpi-item">
                    <span class="mat-view-kpi-label">재고수량</span>
                    <span class="mat-view-kpi-value <?= $qtyWarn ? 'kpi-warn' : '' ?>">
                        <?= $qty !== null ? number_format((float)$qty) : '-' ?>
                        <?php if ($qtyWarn): ?><span class="badge badge-warn">미달</span><?php endif; ?>
                    </span>
                </div>
            </div>

            </div><!-- /.mat-left-group -->

            <!-- 감사정보 -->
            <?php
            $auditCreated = trim((string)($row['created_at'] ?? $row['regdate'] ?? ''));
            $auditUpdated = trim((string)($row['updated_at'] ?? ''));
            $auditBy      = trim((string)($row['created_by'] ?? ''));
            ?>
            <div class="mat-view-audit-mini">
                <span class="mat-view-audit-mini-item">
                    <span class="mat-view-audit-mini-label">등록</span>
                    <span class="mat-view-audit-mini-value td-mono">
                        <?= $auditCreated !== '' ? matViewH($auditCreated) : '-' ?>
                    </span>
                </span>
                <?php if ($auditBy !== '' && $auditBy !== '0'): ?>
                <span class="mat-view-audit-mini-sep">/</span>
                <span class="mat-view-audit-mini-item">
                    <span class="mat-view-audit-mini-label">등록자</span>
                    <span class="mat-view-audit-mini-value"><?= matViewH($auditBy) ?></span>
                </span>
                <?php endif; ?>
            </div>

        </div><!-- /.mat-view-left -->

        <!-- ── 우: 정보 섹션 3종 + 구성품 ── -->
        <div class="mat-view-right">

            <!-- 3섹션 가로 배치 -->
            <div class="mat-view-sections-row">

                <!-- 기본정보 -->
                <div class="mat-view-section mat-view-section--primary">
                    <div class="mat-view-section-title">
                        <i class="fa fa-info-circle"></i> 기본정보
                    </div>
                    <div class="mat-view-info-grid mat-view-info-grid--1col">
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">자재번호</span>
                            <span class="mat-view-field-value mat-view-field-value--main td-mono mv-sv">
                                <?= matViewH((string)($row['item_code'] ?? '')) ?: '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-mono" id="meItemCode" type="text"
                                   value="<?= matViewH((string)($row['item_code'] ?? '')) ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">규격</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['standard'] ?? '')) ?: '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm" id="meStandard" type="text"
                                   value="<?= matViewH((string)($row['standard'] ?? '')) ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">단위</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['unit'] ?? '')) ?: '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm" id="meUnit" type="text"
                                   value="<?= matViewH((string)($row['unit'] ?? '')) ?>" placeholder="EA, M, KG …">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">바코드</span>
                            <span class="mat-view-field-value td-mono mv-sv">
                                <?php $bc = trim((string)($row['barcode'] ?? '')); echo $bc !== '' ? matViewH($bc) : '-'; ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-mono" id="meBarcode" type="text"
                                   value="<?= matViewH((string)($row['barcode'] ?? '')) ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">매입처</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['company_name'] ?? '')) ?: '-' ?>
                            </span>
                            <div class="mv-se mat-company-search">
                                <input id="meCompanyName" type="text" class="form-input form-input-sm"
                                       placeholder="매입처명 검색..." autocomplete="off">
                                <ul id="meCompanyDropdown" class="mat-company-dropdown"></ul>
                                <input type="hidden" id="meCompanyIdx" value="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 가격정보 -->
                <div class="mat-view-section">
                    <div class="mat-view-section-title">
                        <i class="fa fa-won-sign"></i> 가격정보
                    </div>
                    <div class="mat-view-info-grid">
                        <?php
                        $priceFields = [
                            'sale_price'     => '판매단가',
                            'cost'           => '원가',
                            'supply_price'   => '공급단가',
                            'purchase_price' => '매입단가',
                            'work_price'     => '작업단가',
                            'tax_price'      => '세액',
                            'safety'         => '안전관리비',
                        ];
                        $priceFieldIds = [
                            'sale_price'     => 'meSalePrice',
                            'cost'           => 'meCost',
                            'supply_price'   => 'meSupplyPrice',
                            'purchase_price' => 'mePurchasePrice',
                            'work_price'     => 'meWorkPrice',
                            'tax_price'      => 'meTaxPrice',
                            'safety'         => 'meSafety',
                        ];
                        foreach ($priceFields as $key => $label):
                            $val       = $row[$key] ?? null;
                            $formatted = is_numeric($val) ? number_format((float)$val) : null;
                            $rawVal    = is_numeric($val) ? (string)(int)(float)$val : '';
                            $isPrimary = ($key === 'sale_price' || $key === 'cost');
                            $valCls    = $isPrimary ? 'mat-view-field-value mat-view-field-value--accent mv-sv' : 'mat-view-field-value mv-sv';
                            $inputId   = $priceFieldIds[$key];
                        ?>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label"><?= $label ?></span>
                            <span class="<?= $valCls ?>">
                                <?= $formatted !== null ? $formatted : '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-num" id="<?= $inputId ?>"
                                   type="number" min="0" value="<?= $rawVal ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 재고/분류 -->
                <div class="mat-view-section mat-view-section--wide">
                    <div class="mat-view-section-title">
                        <i class="fa fa-boxes"></i> 재고 / 분류
                    </div>
                    <div class="mat-view-info-grid">
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">재고관리</span>
                            <span class="mat-view-field-value mv-sv">
                                <?php if ($inventory === '유'): ?>
                                    <span class="badge badge-success">유</span>
                                <?php else: ?>
                                    <span class="badge badge-ghost">무</span>
                                <?php endif; ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meInventory">
                                <option value="무">재고 미관리</option>
                                <option value="유">재고 관리</option>
                            </select>
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">재고수량</span>
                            <span class="mat-view-field-value mat-view-field-value--accent mv-sv <?= $qtyWarn ? 'mat-stock-warn' : '' ?>">
                                <?= $qty !== null ? number_format((float)$qty) : '-' ?>
                                <?php if ($qtyWarn): ?><span class="badge badge-warn">미달</span><?php endif; ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-num" id="meQty"
                                   type="number" min="0" value="<?= $qty !== null ? (int)(float)$qty : '' ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">안전재고</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= $safetyCount !== null ? number_format($safetyCount) : '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-num" id="meSafetyCount"
                                   type="number" min="0" value="<?= $safetyCount !== null ? (int)$safetyCount : '' ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">기본수량</span>
                            <span class="mat-view-field-value mv-sv">
                                <?php $bqty = $row['base_count'] ?? null; echo is_numeric($bqty) ? number_format((float)$bqty) : '-'; ?>
                            </span>
                            <input class="mv-se form-input form-input-sm td-num" id="meBaseCount"
                                   type="number" min="0" value="<?= is_numeric($row['base_count'] ?? null) ? (int)(float)$row['base_count'] : '' ?>">
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">탭</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['tab_name'] ?? '')) ?: '-' ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meTabIdx">
                                <option value="0">미지정</option>
                                <?php foreach ($tabRows as $tab): ?>
                                    <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                        <?= matViewH((string)($tab['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">카테고리</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['category_name'] ?? '')) ?: '-' ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meCategoryIdx">
                                <option value="0">미지정</option>
                                <?php foreach ($categoryRows as $cat): ?>
                                    <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                        <?= matViewH((string)($cat['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">PJT(공종)</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['attribute'] ?? '')) ?: '-' ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meAttribute">
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
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">판매유무</span>
                            <span class="mat-view-field-value mv-sv">
                                <?php if ($hidden === '1'): ?>
                                    <span class="badge badge-ghost">미판매</span>
                                <?php else: ?>
                                    <span class="badge badge-success">판매</span>
                                <?php endif; ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meHidden">
                                <option value="0">판매</option>
                                <option value="1">미판매</option>
                            </select>
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">내역연동</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= matViewH((string)($row['relative_purchase'] ?? 'O')) ?>
                            </span>
                            <select class="mv-se form-select form-select-sm" id="meRelativePurchase">
                                <option value="O">O — 연동</option>
                                <option value="X">X — 미연동</option>
                            </select>
                        </div>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">품목분할</span>
                            <span class="mat-view-field-value mv-sv">
                                <?= $isSplit !== '' ? matViewH($isSplit) : '-' ?>
                            </span>
                            <input class="mv-se form-input form-input-sm" id="meIsSplit" type="text"
                                   value="<?= matViewH($isSplit) ?>">
                        </div>

                        <?php if ($pattern === '구성품' && $followMode !== ''): ?>
                        <div class="mat-view-field">
                            <span class="mat-view-field-label">수량연동</span>
                            <span class="mat-view-field-value mv-sv"><?= matViewH($followMode) ?></span>
                        </div>
                        <div class="mat-view-field"></div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /.mat-view-sections-row -->

            <!-- 내용 / 메모 -->
            <div class="mat-view-memo-card">
                <div class="mat-section-header">
                    <i class="fa fa-align-left"></i> 내용 / 메모
                </div>
                <div class="mat-view-field mat-view-field--stack">
                    <span class="mat-view-field-label">내용</span>
                    <div class="mat-view-memo-text mv-sv"><?php
                        $cnt = trim((string)($row['contents'] ?? ''));
                        echo $cnt !== '' ? nl2br(matViewH($cnt)) : '<span class="td-muted">-</span>';
                    ?></div>
                    <textarea class="mv-se form-input mat-view-memo-textarea" id="meContents" rows="3"><?= matViewH((string)($row['contents'] ?? '')) ?></textarea>
                </div>
                <div class="mat-view-field mat-view-field--stack">
                    <span class="mat-view-field-label">메모</span>
                    <div class="mat-view-memo-text mv-sv"><?php
                        $memo = trim((string)($row['memo'] ?? ''));
                        echo $memo !== '' ? nl2br(matViewH($memo)) : '<span class="td-muted">-</span>';
                    ?></div>
                    <textarea class="mv-se form-input mat-view-memo-textarea" id="meMemo" rows="2"><?= matViewH((string)($row['memo'] ?? '')) ?></textarea>
                </div>
            </div>

            <!-- 구성품 -->
            <div class="mat-view-comp-card">
                <div class="mat-section-header">
                    <i class="fa fa-cubes"></i> 구성품
                    <span class="badge badge-ghost"><?= count($components) ?>개</span>
                    <button id="matComponentAddBtn" class="btn btn-sm btn-outline ml-auto">
                        <i class="fa fa-plus"></i> 구성품 추가
                    </button>
                </div>
                <div class="mat-view-comp-table-wrap">
                    <table class="tbl" id="matComponentTable">
                        <thead>
                            <tr>
                                <th class="col-70">IDX</th>
                                <th class="col-110">자재번호</th>
                                <th>품목명</th>
                                <th class="col-100">규격</th>
                                <th class="col-80 th-right">수량</th>
                                <th class="col-72 th-center">수량연동</th>
                                <th class="col-72 th-center">재고관리</th>
                                <th class="col-60 th-center">삭제</th>
                            </tr>
                        </thead>
                        <tbody id="matComponentBody">
                            <?php if ($components === []): ?>
                                <tr id="matComponentEmpty">
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <p class="empty-message">구성품 데이터가 없습니다.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($components as $comp): ?>
                                    <?php
                                    $childInv    = trim((string)($comp['child_inventory_management'] ?? ''));
                                    $childFollow = trim((string)($comp['child_follow_mode'] ?? ''));
                                    ?>
                                    <tr
                                        data-component-idx="<?= (int)($comp['idx'] ?? 0) ?>"
                                        data-child-item-idx="<?= (int)($comp['child_item_idx'] ?? 0) ?>"
                                    >
                                        <td class="td-muted td-mono"><?= (int)($comp['idx'] ?? 0) ?></td>
                                        <td class="td-mono td-nowrap">
                                            <?= matViewH((string)($comp['child_item_code'] ?? '')) ?>
                                        </td>
                                        <td><?= matViewH((string)($comp['child_item_name'] ?? '')) ?></td>
                                        <td class="td-muted">
                                            <?= matViewH((string)($comp['child_standard'] ?? '')) ?>
                                        </td>
                                        <td class="td-num">
                                            <input type="number"
                                                   class="mat-qty-input"
                                                   value="<?= (int)($comp['child_qty'] ?? 1) ?>"
                                                   min="1"
                                                   data-component-idx="<?= (int)($comp['idx'] ?? 0) ?>">
                                        </td>
                                        <td class="td-center">
                                            <?php if ($childFollow === '1'): ?>
                                                <span class="badge badge-info">연동</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">고정</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-center">
                                            <?php if ($childInv === '유'): ?>
                                                <span class="badge badge-success">유</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">무</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-center">
                                            <button class="btn btn-xs btn-outline-warn mat-comp-del-btn"
                                                    data-component-idx="<?= (int)($comp['idx'] ?? 0) ?>">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- /.mat-view-comp-card -->

            <!-- 변경 이력 패널 -->
            <div class="mat-view-history-card">
                <div class="mat-section-header">
                    <i class="fa fa-history"></i> 변경 이력
                    <span class="badge badge-ghost" id="matHistoryCountBadge">-</span>
                    <button id="matHistoryToggleBtn" class="btn btn-sm btn-ghost ml-auto" type="button">
                        <i class="fa fa-chevron-down" id="matHistoryChevron"></i>
                    </button>
                </div>
                <div class="mat-view-history-body" id="matHistoryBody" hidden>
                    <div class="mat-view-comp-table-wrap">
                        <table class="tbl" id="matHistoryTable">
                            <thead>
                                <tr>
                                    <th class="col-50 th-center">No</th>
                                    <th class="col-150">변경시각</th>
                                    <th class="col-120">변경 필드</th>
                                    <th>이전 값</th>
                                    <th>이후 값</th>
                                    <th class="col-60 th-center">복구</th>
                                </tr>
                            </thead>
                            <tbody id="matHistoryTbody">
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <p class="empty-message">이력을 불러오는 중...</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mat-pager-bar">
                        <span class="mat-pager-info" id="matHistoryPagerInfo"></span>
                        <div class="mat-pager-btns" id="matHistoryPager"></div>
                    </div>
                </div>
            </div><!-- /.mat-view-history-card -->

        </div><!-- /.mat-view-right -->

    </div><!-- /.mat-view-inner -->

    <?php /* 수정 모달 제거됨 — 인라인 편집으로 전환 */ if (false): ?>
    <div id="matEditModal" class="modal-overlay _REMOVED">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-edit"></i> 품목 수정</h3>
                <button class="modal-close" id="matEditCloseBtn">
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
                            <input id="meName" type="text" class="form-input"
                                   placeholder="품목명을 입력하세요">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                유형 <span class="badge badge-danger">필수</span>
                            </label>
                            <select id="mePattern" class="form-select">
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
                            <input id="meStandard" type="text" class="form-input"
                                   placeholder="규격">
                        </div>
                        <div class="form-group">
                            <label class="form-label">단위</label>
                            <input id="meUnit" type="text" class="form-input"
                                   placeholder="EA, M, KG …">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">자재번호</label>
                            <input id="meItemCode" type="text" class="form-input"
                                   placeholder="자재번호">
                        </div>
                        <div class="form-group">
                            <label class="form-label">바코드</label>
                            <input id="meBarcode" type="text" class="form-input"
                                   placeholder="바코드">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group fg-2">
                            <label class="form-label">매입처</label>
                            <div class="mat-company-search">
                                <input id="meCompanyName" type="text" class="form-input"
                                       placeholder="매입처명 검색..." autocomplete="off">
                                <ul id="meCompanyDropdown" class="mat-company-dropdown"></ul>
                            </div>
                            <input type="hidden" id="meCompanyIdx" value="0">
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
                            <select id="meTabIdx" class="form-select">
                                <option value="0">미지정</option>
                                <?php foreach ($tabRows as $tab): ?>
                                    <option value="<?= (int)($tab['idx'] ?? 0) ?>">
                                        <?= matViewH((string)($tab['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">카테고리</label>
                            <select id="meCategoryIdx" class="form-select">
                                <option value="0">미지정</option>
                                <?php foreach ($categoryRows as $cat): ?>
                                    <option value="<?= (int)($cat['idx'] ?? 0) ?>">
                                        <?= matViewH((string)($cat['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">PJT(공종)</label>
                            <select id="meAttribute" class="form-select">
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
                            <select id="meHidden" class="form-select">
                                <option value="0">판매</option>
                                <option value="1">미판매</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">내역연동</label>
                        <select id="meRelativePurchase" class="form-select">
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
                            <input id="meSalePrice" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                원가 <span class="badge badge-danger">필수</span>
                            </label>
                            <input id="meCost" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">공급단가</label>
                            <input id="meSupplyPrice" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">매입단가</label>
                            <input id="mePurchasePrice" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">작업단가</label>
                            <input id="meWorkPrice" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">세액</label>
                            <input id="meTaxPrice" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">안전관리비</label>
                        <input id="meSafety" type="number" class="form-input"
                               placeholder="0" min="0">
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
                            <select id="meInventory" class="form-select">
                                <option value="무">재고 미관리</option>
                                <option value="유">재고 관리</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">재고수량</label>
                            <input id="meQty" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">안전재고</label>
                            <input id="meSafetyCount" type="number" class="form-input"
                                   placeholder="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">기본수량</label>
                            <input id="meBaseCount" type="number" class="form-input"
                                   placeholder="0" min="0">
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
                            <?php if ($bannerUrl !== ''): ?>
                                <div class="mat-img-current-wrap" id="meBannerCurrentWrap">
                                    <img src="<?= matViewH($bannerUrl) ?>"
                                         alt="현재 배너" id="meBannerCurrentImg">
                                    <button type="button" class="mat-img-remove"
                                            id="meBannerCurrentRemove">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div id="meBannerCurrentWrap"></div>
                            <?php endif; ?>
                            <div class="mat-img-upload-area" id="meBannerArea">
                                <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                                <span>새 배너 이미지 선택</span>
                                <img class="mat-img-preview" id="meBannerPreview"
                                     alt="배너미리보기">
                                <button type="button" class="mat-img-remove"
                                        id="meBannerRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <input type="file" id="meBannerFile" accept="image/*"
                                   class="d-none">
                            <input type="hidden" id="meDelBanner" name="del_banner" value="0">
                        </div>
                        <div>
                            <span class="mat-img-label">상세이미지</span>
                            <?php if ($detailUrl !== ''): ?>
                                <div class="mat-img-current-wrap" id="meDetailCurrentWrap">
                                    <img src="<?= matViewH($detailUrl) ?>"
                                         alt="현재 상세" id="meDetailCurrentImg">
                                    <button type="button" class="mat-img-remove"
                                            id="meDetailCurrentRemove">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div id="meDetailCurrentWrap"></div>
                            <?php endif; ?>
                            <div class="mat-img-upload-area" id="meDetailArea">
                                <i class="fa fa-cloud-upload-alt mat-img-upload-icon"></i>
                                <span>새 상세 이미지 선택</span>
                                <img class="mat-img-preview" id="meDetailPreview"
                                     alt="상세미리보기">
                                <button type="button" class="mat-img-remove"
                                        id="meDetailRemove">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <input type="file" id="meDetailFile" accept="image/*"
                                   class="d-none">
                            <input type="hidden" id="meDelDetail" name="del_detail" value="0">
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
                        <textarea id="meContents" class="form-input" rows="3"
                                  placeholder="품목 내용을 입력하세요"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">메모</label>
                        <textarea id="meMemo" class="form-input" rows="2"
                                  placeholder="메모"></textarea>
                    </div>
                </div>

            </div>
    <?php endif; /* /수정 모달 제거됨 */ ?>

    <!-- 구성품 검색 모달 -->
    <div id="matComponentSearchModal" class="modal-overlay">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-search"></i> 구성품 품목 검색</h3>
                <button class="modal-close" id="matCompSearchCloseBtn">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group fg-2">
                        <label class="form-label">품목 검색</label>
                        <input id="matCompSearchInput" type="text" class="form-input"
                               placeholder="품목명/규격/자재번호">
                    </div>
                    <div class="fg-auto">
                        <button id="matCompSearchBtn" class="btn btn-primary">
                            <i class="fa fa-search"></i> 검색
                        </button>
                    </div>
                </div>
                <div class="card-body--table" id="matCompSearchResultWrap">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th class="col-60">IDX</th>
                                <th class="col-110">자재번호</th>
                                <th class="col-130">카테고리</th>
                                <th>품목명</th>
                                <th class="col-100">규격</th>
                                <th class="col-60">단위</th>
                                <th class="col-60 th-center">선택</th>
                            </tr>
                        </thead>
                        <tbody id="matCompSearchBody">
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <p class="empty-message">품목명을 검색하세요.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button id="matCompSearchCancelBtn" class="btn btn-ghost">닫기</button>
            </div>
        </div>
    </div>

    <!-- 이력 복구 확인 모달 -->
    <div id="matHistoryRestoreModal" class="modal-overlay">
        <div class="modal-box modal-alert">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-undo"></i> 이력 복구</h3>
                <button class="modal-close" id="matHistoryRestoreCloseBtn">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>이 시점의 값으로 복구하시겠습니까?</p>
                <p class="text-3 text-sm">복구 후 현재 값은 이력에 저장됩니다.</p>
            </div>
            <div class="modal-footer">
                <button id="matHistoryRestoreCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matHistoryRestoreSubmitBtn" class="btn btn-primary">
                    <i class="fa fa-undo"></i> 복구
                </button>
            </div>
        </div>
    </div>

    <!-- 삭제 확인 모달 -->
    <div id="matDeleteModal" class="modal-overlay">
        <div class="modal-box modal-alert">
            <div class="modal-header">
                <h3 class="modal-title">품목 삭제</h3>
                <button class="modal-close" id="matDeleteCloseBtn">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p><strong id="matDeleteNameLabel"></strong> 품목을 삭제하시겠습니까?</p>
                <p class="text-3 text-sm">삭제된 데이터는 복구할 수 없습니다.</p>
            </div>
            <div class="modal-footer">
                <button id="matDeleteCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matDeleteSubmitBtn" class="btn btn-danger">삭제</button>
            </div>
        </div>
    </div>

</section>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="mat-view"]');
    if (!_section) return;

    var _api     = _section.dataset.matApi || 'dist_process/saas/Material.php';
    var _itemIdx = parseInt(_section.dataset.materialIdx || '0', 10);
    var _row     = {};
    try { _row = JSON.parse(_section.dataset.row || '{}'); } catch (e) {}

    /* ── 매입처 자동완성 ── */
    (function () {
        var input    = document.getElementById('meCompanyName');
        var dropdown = document.getElementById('meCompanyDropdown');
        var idxInput = document.getElementById('meCompanyIdx');
        if (!input || !dropdown || !idxInput) return;

        var _timer = null;

        input.addEventListener('input', function () {
            clearTimeout(_timer);
            var q = input.value.trim();
            if (q.length < 1) { hideDropdown(); return; }
            _timer = setTimeout(function () { fetchCompanies(q); }, 250);
        });

        input.addEventListener('focus', function () {
            var q = input.value.trim();
            if (q.length >= 1) fetchCompanies(q);
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                hideDropdown();
            }
        });

        function fetchCompanies(q) {
            SHV.api.get(_api, { todo: 'company_list', q: q, limit: 20 })
                .then(function (res) {
                    if (!res || !res.success || !res.data || res.data.length === 0) {
                        hideDropdown();
                        return;
                    }
                    renderDropdown(res);
                }).catch(function () { hideDropdown(); });
        }

        function renderDropdown(res) {
            var items   = res.data || [];
            var nameCol = res.name_column || 'name';
            var idCol   = res.id_column   || 'idx';
            dropdown.innerHTML = '';
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.className   = 'mat-company-option';
                li.textContent = item[nameCol] || item.name || '';
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    input.value    = item[nameCol] || item.name || '';
                    idxInput.value = String(item[idCol]  || item.idx  || '0');
                    hideDropdown();
                });
                dropdown.appendChild(li);
            });
            dropdown.classList.add('open');
        }

        function hideDropdown() {
            dropdown.innerHTML = '';
            dropdown.classList.remove('open');
        }
    })();

    /* ── 이미지 업로드 초기화 헬퍼 ── */
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
            /* 이미지 선택 → 저장 버튼 활성화 */
            if (_originalSnap) {
                document.getElementById('matEditSubmitBtn').disabled = false;
            }
        });
        remove.addEventListener('click', function (e) {
            e.stopPropagation();
            input.value   = '';
            preview.src   = '';
            preview.style.display = 'none';
            remove.style.display  = 'none';
            area.classList.remove('has-file');
        });
    }

    initImgUpload('meBannerArea', 'meBannerFile', 'meBannerPreview', 'meBannerRemove');
    initImgUpload('meDetailArea', 'meDetailFile', 'meDetailPreview', 'meDetailRemove');

    /* ── 현재 이미지 삭제 버튼 ── */
    var _bannerCurRemove = document.getElementById('meBannerCurrentRemove');
    if (_bannerCurRemove) {
        _bannerCurRemove.addEventListener('click', function () {
            document.getElementById('meDelBanner').value = '1';
            var wrap = document.getElementById('meBannerCurrentWrap');
            if (wrap) wrap.style.display = 'none';
        });
    }
    var _detailCurRemove = document.getElementById('meDetailCurrentRemove');
    if (_detailCurRemove) {
        _detailCurRemove.addEventListener('click', function () {
            document.getElementById('meDelDetail').value = '1';
            var wrap = document.getElementById('meDetailCurrentWrap');
            if (wrap) wrap.style.display = 'none';
        });
    }

    /* ── 인라인 수정 모드 ── */
    var _originalSnap = null;

    function _snapValues() {
        var snap = {};
        _section.querySelectorAll('[id^="me"]').forEach(function (el) {
            if (el.type === 'file') { return; }
            snap[el.id] = el.value;
        });
        return snap;
    }

    function _isDirty() {
        if (!_originalSnap) { return false; }
        var bf = document.getElementById('meBannerFile');
        var df = document.getElementById('meDetailFile');
        if ((bf && bf.files.length > 0) || (df && df.files.length > 0)) { return true; }
        var cur = _snapValues();
        return JSON.stringify(_originalSnap) !== JSON.stringify(cur);
    }

    function enterEditMode() {
        document.getElementById('meName').value             = _row.name || '';
        document.getElementById('mePattern').value          = _row.material_pattern || '';
        document.getElementById('meStandard').value         = _row.standard || '';
        document.getElementById('meUnit').value             = _row.unit || '';
        document.getElementById('meItemCode').value         = _row.item_code || '';
        document.getElementById('meBarcode').value          = _row.barcode || '';
        document.getElementById('meCompanyName').value      = _row.company_name || '';
        document.getElementById('meCompanyIdx').value       = String(_row.company_idx || '0');
        document.getElementById('meTabIdx').value           = String(_row.tab_idx || '0');
        document.getElementById('meCategoryIdx').value      = String(_row.category_idx || '0');
        document.getElementById('meAttribute').value        = _row.attribute || '';
        document.getElementById('meHidden').value           = String(_row.hidden || '0');
        document.getElementById('meRelativePurchase').value = _row.relative_purchase || 'O';
        document.getElementById('meSalePrice').value        = _row.sale_price || '';
        document.getElementById('meCost').value             = _row.cost || '';
        document.getElementById('meSupplyPrice').value      = _row.supply_price || '';
        document.getElementById('mePurchasePrice').value    = _row.purchase_price || '';
        document.getElementById('meWorkPrice').value        = _row.work_price || '';
        document.getElementById('meTaxPrice').value         = _row.tax_price || '';
        document.getElementById('meSafety').value           = _row.safety || '';
        var inv = String(_row.inventory_management || '').trim();
        document.getElementById('meInventory').value        = (inv === '유' || inv === 'Y' || inv === '1') ? '유' : '무';
        document.getElementById('meQty').value              = _row.qty || '';
        document.getElementById('meSafetyCount').value      = _row.safety_count || '';
        document.getElementById('meBaseCount').value        = _row.base_count || '';
        document.getElementById('meIsSplit').value           = _row.is_split || '';
        document.getElementById('meContents').value         = _row.contents || '';
        document.getElementById('meMemo').value             = _row.memo || '';

        /* 이미지 초기화 */
        document.getElementById('meDelBanner').value = '0';
        document.getElementById('meDelDetail').value = '0';
        var bw = document.getElementById('meBannerCurrentWrap');
        if (bw) bw.style.display = '';
        var dw = document.getElementById('meDetailCurrentWrap');
        if (dw) dw.style.display = '';
        var bprev = document.getElementById('meBannerPreview');
        var brem  = document.getElementById('meBannerRemove');
        var ba    = document.getElementById('meBannerArea');
        if (bprev) { bprev.src = ''; bprev.style.display = 'none'; }
        if (brem)  brem.style.display = 'none';
        if (ba)    ba.classList.remove('has-file');
        var dprev = document.getElementById('meDetailPreview');
        var drem  = document.getElementById('meDetailRemove');
        var da    = document.getElementById('meDetailArea');
        if (dprev) { dprev.src = ''; dprev.style.display = 'none'; }
        if (drem)  drem.style.display = 'none';
        if (da)    da.classList.remove('has-file');
        var bf = document.getElementById('meBannerFile');
        var df = document.getElementById('meDetailFile');
        if (bf) bf.value = '';
        if (df) df.value = '';

        _section.classList.add('mat-editing');
        _originalSnap = _snapValues();   /* 스냅샷: 편집 시작값 기록 */
        document.getElementById('matEditSubmitBtn').disabled = true;   /* 변경 없으면 저장 비활성 */
        document.getElementById('meName').focus();
    }

    function exitEditMode(force) {
        if (!force && _isDirty()) {
            SHV.modal.confirm(
                '저장 안됨',
                '변경 내용이 저장되지 않았습니다.<br>취소하고 되돌리시겠습니까?',
                function () {
                    _originalSnap = null;
                    _section.classList.remove('mat-editing');
                }
            );
            return;
        }
        _originalSnap = null;
        _section.querySelectorAll('.is-dirty').forEach(function (el) { el.classList.remove('is-dirty'); });
        _section.classList.remove('mat-editing');
    }

    var _editBtn = document.getElementById('matEditBtn');
    if (_editBtn) _editBtn.addEventListener('click', enterEditMode);
    var _cancelBtn = document.getElementById('matEditCancelBtn');
    if (_cancelBtn) _cancelBtn.addEventListener('click', function () { exitEditMode(false); });

    /* ── 필드 변경 감지 → is-dirty 클래스 토글 + 저장 버튼 활성화 ── */
    _section.querySelectorAll('[id^="me"]').forEach(function (el) {
        if (el.type === 'file') { return; }
        function _checkField() {
            if (!_originalSnap) { return; }
            var orig = _originalSnap[el.id] !== undefined ? _originalSnap[el.id] : '';
            el.classList.toggle('is-dirty', el.value !== orig);
            document.getElementById('matEditSubmitBtn').disabled = !_isDirty();
        }
        el.addEventListener('input',  _checkField);
        el.addEventListener('change', _checkField);
    });

    /* Escape 키로 편집 취소 */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && _section.classList.contains('mat-editing')) {
            exitEditMode(false);
        }
    });

    /* ── 필드 클릭 → 수정 모드 진입 (정보카드 + 메모만, 구성품/이력 제외) ── */
    var _rightPanel = _section.querySelector('.mat-view-right');
    if (_rightPanel) {
        _rightPanel.addEventListener('click', function (e) {
            if (_section.classList.contains('mat-editing')) { return; }
            /* 구성품/이력 카드 클릭은 제외 */
            if (e.target.closest('.mat-view-comp-card, .mat-view-history-card')) { return; }
            /* 필드값 텍스트 또는 메모 텍스트 클릭만 수정 모드 진입 */
            if (e.target.closest('.mat-view-field-value, .mat-view-memo-text')) {
                enterEditMode();
            }
        });
    }

    /* ── 수정 submit ── */
    document.getElementById('matEditSubmitBtn').addEventListener('click', function () {
        var name    = document.getElementById('meName').value.trim();
        var pattern = document.getElementById('mePattern').value;
        var sale    = document.getElementById('meSalePrice').value.trim();
        var cost    = document.getElementById('meCost').value.trim();

        if (!name) {
            SHV.toast.warn('품목명은 필수입니다.');
            document.getElementById('meName').focus();
            return;
        }
        if (!pattern) {
            SHV.toast.warn('유형을 선택해주세요.');
            document.getElementById('mePattern').focus();
            return;
        }
        if (sale === '') {
            SHV.toast.warn('판매단가를 입력해주세요.');
            document.getElementById('meSalePrice').focus();
            return;
        }
        if (cost === '') {
            SHV.toast.warn('원가를 입력해주세요.');
            document.getElementById('meCost').focus();
            return;
        }

        var _btn = document.getElementById('matEditSubmitBtn');
        _btn.disabled = true;

        var fd = new FormData();
        fd.append('todo',                 'material_update');
        fd.append('idx',                  String(_itemIdx));
        fd.append('item_code',            document.getElementById('meItemCode').value.trim());
        fd.append('name',                 name);
        fd.append('material_pattern',     pattern);
        fd.append('standard',             document.getElementById('meStandard').value.trim());
        fd.append('unit',                 document.getElementById('meUnit').value.trim());
        fd.append('barcode',              document.getElementById('meBarcode').value.trim());
        fd.append('company_name',         document.getElementById('meCompanyName').value.trim());
        fd.append('company_idx',          document.getElementById('meCompanyIdx').value);
        fd.append('tab_idx',              document.getElementById('meTabIdx').value);
        fd.append('category_idx',         document.getElementById('meCategoryIdx').value);
        fd.append('attribute',            document.getElementById('meAttribute').value);
        fd.append('hidden',               document.getElementById('meHidden').value);
        fd.append('relative_purchase',    document.getElementById('meRelativePurchase').value);
        fd.append('sale_price',           sale);
        fd.append('cost',                 cost);
        fd.append('supply_price',         document.getElementById('meSupplyPrice').value.trim());
        fd.append('purchase_price',       document.getElementById('mePurchasePrice').value.trim());
        fd.append('work_price',           document.getElementById('meWorkPrice').value.trim());
        fd.append('tax_price',            document.getElementById('meTaxPrice').value.trim());
        fd.append('safety',               document.getElementById('meSafety').value.trim());
        fd.append('inventory_management', document.getElementById('meInventory').value);
        fd.append('qty',                  document.getElementById('meQty').value.trim());
        fd.append('safety_count',         document.getElementById('meSafetyCount').value.trim());
        fd.append('base_count',           document.getElementById('meBaseCount').value.trim());
        fd.append('is_split',             document.getElementById('meIsSplit').value.trim());
        fd.append('contents',             document.getElementById('meContents').value.trim());
        fd.append('memo',                 document.getElementById('meMemo').value.trim());
        fd.append('del_banner',           document.getElementById('meDelBanner').value);
        fd.append('del_detail',           document.getElementById('meDelDetail').value);

        var bannerFile = document.getElementById('meBannerFile').files[0];
        var detailFile = document.getElementById('meDetailFile').files[0];
        if (bannerFile) fd.append('upload_files_banner', bannerFile);
        if (detailFile) fd.append('upload_files_detail', detailFile);

        SHV.api.upload(_api, fd).then(function (res) {
            _btn.disabled = false;
            if (res && res.success) {
                exitEditMode(true);   /* 저장 완료 → dirty check 없이 즉시 종료 */
                SHV.toast.success('품목이 수정되었습니다.');
                SHV.router.navigate('mat_view', { idx: _itemIdx });
            } else {
                SHV.toast.error((res && res.message) || '수정에 실패했습니다.');
            }
        }).catch(function () {
            _btn.disabled = false;
            SHV.toast.error('오류가 발생했습니다.');
        });
    });

    /* ── 삭제 모달 ── */
    var _deleteModal = document.getElementById('matDeleteModal');

    function openDeleteModal() {
        var label = document.getElementById('matDeleteNameLabel');
        if (label) label.textContent = _row.name || _row.item_code || '해당';
        _deleteModal.classList.add('open');
    }
    function closeDeleteModal() { _deleteModal.classList.remove('open'); }

    var _deleteBtn = document.getElementById('matDeleteBtn');
    if (_deleteBtn) _deleteBtn.addEventListener('click', openDeleteModal);
    document.getElementById('matDeleteCloseBtn').addEventListener('click', closeDeleteModal);
    document.getElementById('matDeleteCancelBtn').addEventListener('click', closeDeleteModal);
    _deleteModal.addEventListener('click', function (e) {
        if (e.target === _deleteModal) closeDeleteModal();
    });

    document.getElementById('matDeleteSubmitBtn').addEventListener('click', function () {
        var _btn = document.getElementById('matDeleteSubmitBtn');
        _btn.disabled = true;
        SHV.api.post(_api, { todo: 'material_delete', idx: _itemIdx })
            .then(function (res) {
                _btn.disabled = false;
                if (res && res.success) {
                    closeDeleteModal();
                    SHV.toast.success('품목이 삭제되었습니다.');
                    history.back();
                } else {
                    SHV.toast.error((res && res.message) || '삭제에 실패했습니다.');
                }
            }).catch(function () {
                _btn.disabled = false;
                SHV.toast.error('오류가 발생했습니다.');
            });
    });

    /* ── 구성품 수량 변경 (blur → API) ── */
    var _compBody = document.getElementById('matComponentBody');
    if (_compBody) {
        _compBody.addEventListener('blur', function (e) {
            var input = e.target.closest('.mat-qty-input');
            if (!input) return;
            var compIdx = parseInt(input.dataset.componentIdx || '0', 10);
            var qty     = parseInt(input.value, 10);
            if (compIdx <= 0 || isNaN(qty) || qty < 1) return;
            SHV.api.post(_api, {
                todo: 'component_update',
                idx:  compIdx,
                qty:  qty,
            }).then(function (res) {
                if (res && res.success) {
                    SHV.toast.success('수량이 변경되었습니다.');
                } else {
                    SHV.toast.error((res && res.message) || '수량 변경에 실패했습니다.');
                }
            }).catch(function () {
                SHV.toast.error('오류가 발생했습니다.');
            });
        }, true);

        /* ── 구성품 삭제 버튼 ── */
        _compBody.addEventListener('click', function (e) {
            var btn = e.target.closest('.mat-comp-del-btn');
            if (!btn) return;
            var compIdx = parseInt(btn.dataset.componentIdx || '0', 10);
            if (compIdx <= 0) return;
            btn.disabled = true;
            SHV.api.post(_api, {
                todo: 'component_delete',
                idx:  compIdx,
            }).then(function (res) {
                btn.disabled = false;
                if (res && res.success) {
                    var tr = btn.closest('tr');
                    if (tr) tr.remove();
                    /* 빈 상태 체크 */
                    if (_compBody.querySelectorAll('tr[data-component-idx]').length === 0) {
                        var emptyRow = document.createElement('tr');
                        emptyRow.id = 'matComponentEmpty';
                        emptyRow.innerHTML = '<td colspan="8"><div class="empty-state"><p class="empty-message">구성품 데이터가 없습니다.</p></div></td>';
                        _compBody.appendChild(emptyRow);
                    }
                    SHV.toast.success('구성품이 삭제되었습니다.');
                } else {
                    SHV.toast.error((res && res.message) || '삭제에 실패했습니다.');
                }
            }).catch(function () {
                btn.disabled = false;
                SHV.toast.error('오류가 발생했습니다.');
            });
        });
    }

    /* ── 구성품 추가 검색 모달 ── */
    var _compSearchModal = document.getElementById('matComponentSearchModal');

    function openCompSearch() {
        document.getElementById('matCompSearchInput').value = '';
        document.getElementById('matCompSearchBody').innerHTML =
            '<tr><td colspan="7"><div class="empty-state"><p class="empty-message">품목명을 검색하세요.</p></div></td></tr>';
        _compSearchModal.classList.add('open');
        document.getElementById('matCompSearchInput').focus();
    }
    function closeCompSearch() { _compSearchModal.classList.remove('open'); }

    var _compAddBtn = document.getElementById('matComponentAddBtn');
    if (_compAddBtn) _compAddBtn.addEventListener('click', openCompSearch);
    document.getElementById('matCompSearchCloseBtn').addEventListener('click', closeCompSearch);
    document.getElementById('matCompSearchCancelBtn').addEventListener('click', closeCompSearch);
    _compSearchModal.addEventListener('click', function (e) {
        if (e.target === _compSearchModal) closeCompSearch();
    });

    function doCompSearch() {
        var keyword = document.getElementById('matCompSearchInput').value.trim();
        if (!keyword) { SHV.toast.warn('검색어를 입력하세요.'); return; }
        var _tbody = document.getElementById('matCompSearchBody');
        _tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p class="empty-message">검색 중...</p></div></td></tr>';
        SHV.api.post(_api, {
            todo:         'component_search',
            q:            keyword,
            tab_idx:      0,
            category_idx: 0,
            limit:        50
        }).then(function (res) {
            var list = (res && res.data && res.data.data) ? res.data.data : [];
            if (!list.length) {
                _tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p class="empty-message">검색 결과가 없습니다.</p></div></td></tr>';
                return;
            }
            var html = '';
            list.forEach(function (item) {
                var code   = String(item.item_code     || '');
                var cat    = String(item.category_name || '');
                var name   = String(item.name          || '');
                var std    = String(item.standard      || '');
                var unit   = String(item.unit          || '');
                var follow = String(item.follow_mode   || '');
                var idx    = parseInt(item.idx || '0', 10);
                var esc    = function (s) { return s.replace(/"/g, '&quot;'); };
                html += '<tr data-item-idx="' + idx + '"'
                      + ' data-item-code="'     + esc(code)   + '"'
                      + ' data-item-name="'     + esc(name)   + '"'
                      + ' data-item-standard="' + esc(std)    + '"'
                      + ' data-item-unit="'     + esc(unit)   + '"'
                      + ' data-follow-mode="'   + esc(follow) + '">'
                      + '<td class="td-muted td-mono">' + idx + '</td>'
                      + '<td class="td-mono td-nowrap">' + code + '</td>'
                      + '<td class="td-muted td-ellipsis">' + (cat || '-') + '</td>'
                      + '<td>' + name + '</td>'
                      + '<td class="td-muted">' + std + '</td>'
                      + '<td class="td-muted">' + unit + '</td>'
                      + '<td class="td-center"><button class="btn btn-xs btn-primary mat-comp-pick-btn">선택</button></td>'
                      + '</tr>';
            });
            _tbody.innerHTML = html;
        }).catch(function () {
            _tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p class="empty-message">오류가 발생했습니다.</p></div></td></tr>';
        });
    }

    document.getElementById('matCompSearchBtn').addEventListener('click', doCompSearch);
    document.getElementById('matCompSearchInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doCompSearch();
    });

    /* 구성품 선택 */
    document.getElementById('matCompSearchBody').addEventListener('click', function (e) {
        var btn = e.target.closest('.mat-comp-pick-btn');
        if (!btn) return;
        var tr        = btn.closest('tr[data-item-idx]');
        if (!tr) return;
        var childIdx  = parseInt(tr.dataset.itemIdx, 10);
        var childCode = tr.dataset.itemCode || '';
        var childName = tr.dataset.itemName || '';
        var childStd  = tr.dataset.itemStandard || '';
        var childUnit = tr.dataset.itemUnit || '';
        if (childIdx <= 0) return;
        btn.disabled = true;
        SHV.api.post(_api, {
            todo:            'component_add',
            parent_item_idx: _itemIdx,
            child_item_idx:  childIdx,
            qty:             1,
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeCompSearch();
                /* 빈 행 제거 */
                var emptyRow = document.getElementById('matComponentEmpty');
                if (emptyRow) emptyRow.remove();
                /* 새 행 추가 */
                var newCompIdx  = (res.data && res.data.idx) ? parseInt(res.data.idx, 10) : 0;
                var childFollow = tr.dataset.followMode || '0';
                var followBadge = (childFollow === '1')
                    ? '<span class="badge badge-info">연동</span>'
                    : '<span class="badge badge-ghost">고정</span>';
                var newTr = document.createElement('tr');
                newTr.setAttribute('data-component-idx', String(newCompIdx));
                newTr.setAttribute('data-child-item-idx', String(childIdx));
                newTr.innerHTML = '<td class="td-muted td-mono">' + (newCompIdx || '-') + '</td>'
                    + '<td class="td-mono td-nowrap">' + childCode + '</td>'
                    + '<td>' + childName + '</td>'
                    + '<td class="td-muted">' + childStd + '</td>'
                    + '<td class="td-num"><input type="number" class="mat-qty-input" value="1" min="1"'
                    + ' data-component-idx="' + newCompIdx + '"></td>'
                    + '<td class="td-center">' + followBadge + '</td>'
                    + '<td class="td-center"><span class="badge badge-ghost">무</span></td>'
                    + '<td class="td-center"><button class="btn btn-xs btn-outline-warn mat-comp-del-btn"'
                    + ' data-component-idx="' + newCompIdx + '"><i class="fa fa-trash"></i></button></td>';
                document.getElementById('matComponentBody').appendChild(newTr);
                SHV.toast.success('구성품이 추가되었습니다.');
            } else {
                SHV.toast.error((res && res.message) || '추가에 실패했습니다.');
            }
        }).catch(function () {
            btn.disabled = false;
            SHV.toast.error('오류가 발생했습니다.');
        });
    });

    /* ════════════════════════════════════════
       변경 이력 패널
    ════════════════════════════════════════ */
    (function () {
        var _body     = document.getElementById('matHistoryBody');
        var _tbody    = document.getElementById('matHistoryTbody');
        var _badge    = document.getElementById('matHistoryCountBadge');
        var _pager    = document.getElementById('matHistoryPager');
        var _pInfo    = document.getElementById('matHistoryPagerInfo');
        var _chevron  = document.getElementById('matHistoryChevron');
        var _toggle   = document.getElementById('matHistoryToggleBtn');
        var _rstModal = document.getElementById('matHistoryRestoreModal');
        if (!_body || !_tbody) return;

        var _page    = 1;
        var _total   = 0;
        var _pages   = 1;
        var _loaded  = false;
        var _pendingRestoreIdx = 0;

        function loadHistory(p) {
            _page = p || 1;
            _tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p class="empty-message">불러오는 중...</p></div></td></tr>';
            SHV.api.get(_api, { todo: 'history_list', item_idx: _itemIdx, p: _page, limit: 20 })
                .then(function (res) {
                    var d    = (res && res.data) ? res.data : {};
                    _total = parseInt(d.total || '0', 10);
                    _pages = parseInt(d.pages || '1', 10);
                    if (_badge) _badge.textContent = _total;
                    if (_pInfo) _pInfo.textContent = '총 ' + _total.toLocaleString() + '건  |  ' + _page + ' / ' + Math.max(1, _pages) + ' 페이지';
                    var list = Array.isArray(d.data) ? d.data : [];
                    if (!list.length) {
                        _tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p class="empty-message">변경 이력이 없습니다.</p></div></td></tr>';
                        if (_pager) _pager.innerHTML = '';
                        return;
                    }
                    var html = '';
                    list.forEach(function (h, i) {
                        var idx       = parseInt(h.idx || '0', 10);
                        var fieldName = String(h.changed_field || h.field || '');
                        var before    = String(h.before_value  || '');
                        var after     = String(h.after_value   || '');
                        var changedAt = String(h.changed_at    || h.created_at || '');
                        var changedBy = String(h.changed_by    || '');
                        var rowNo     = (_page - 1) * 20 + i + 1;
                        var byHtml    = changedBy ? '<br><small class="td-muted">' + changedBy + '</small>' : '';
                        html += '<tr>'
                            + '<td class="td-center td-muted td-mono">' + rowNo + '</td>'
                            + '<td class="td-mono td-nowrap td-muted">' + changedAt + byHtml + '</td>'
                            + '<td class="td-muted">' + fieldName + '</td>'
                            + '<td class="mat-history-val td-muted">' + (before || '-') + '</td>'
                            + '<td class="mat-history-val">' + (after  || '-') + '</td>'
                            + '<td class="td-center">'
                            + (idx > 0 ? '<button class="btn btn-xs btn-ghost mat-history-restore-btn" data-history-idx="' + idx + '" title="이 시점으로 복구"><i class="fa fa-undo"></i></button>' : '-')
                            + '</td>'
                            + '</tr>';
                    });
                    _tbody.innerHTML = html;
                    renderPager();
                }).catch(function () {
                    _tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p class="empty-message">이력 조회에 실패했습니다.</p></div></td></tr>';
                });
        }

        function renderPager() {
            if (!_pager) return;
            if (_pages <= 1) { _pager.innerHTML = ''; return; }
            var html = '';
            if (_page > 1) html += '<button class="page-item" data-hp="' + (_page - 1) + '">‹</button>';
            var s = Math.max(1, _page - 2), e = Math.min(_pages, _page + 2);
            for (var i = s; i <= e; i++) {
                html += '<button class="page-item' + (i === _page ? ' page-active' : '') + '" data-hp="' + i + '">' + i + '</button>';
            }
            if (_page < _pages) html += '<button class="page-item" data-hp="' + (_page + 1) + '">›</button>';
            _pager.innerHTML = html;
        }

        if (_pager) {
            _pager.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-hp]');
                if (btn) loadHistory(parseInt(btn.dataset.hp, 10));
            });
        }

        /* 복구 버튼 → 확인 모달 오픈 */
        _tbody.addEventListener('click', function (e) {
            var btn = e.target.closest('.mat-history-restore-btn');
            if (!btn) return;
            _pendingRestoreIdx = parseInt(btn.dataset.historyIdx || '0', 10);
            if (_pendingRestoreIdx <= 0) return;
            if (_rstModal) _rstModal.classList.add('open');
        });

        /* 복구 확인 모달 */
        if (_rstModal) {
            var _rstSubmit  = document.getElementById('matHistoryRestoreSubmitBtn');
            var _rstCancel  = document.getElementById('matHistoryRestoreCancelBtn');
            var _rstClose   = document.getElementById('matHistoryRestoreCloseBtn');
            function closeRstModal() { _rstModal.classList.remove('open'); }
            if (_rstClose)  _rstClose.addEventListener('click', closeRstModal);
            if (_rstCancel) _rstCancel.addEventListener('click', closeRstModal);
            _rstModal.addEventListener('click', function (e) { if (e.target === _rstModal) closeRstModal(); });
            if (_rstSubmit) {
                _rstSubmit.addEventListener('click', function () {
                    if (_pendingRestoreIdx <= 0) return;
                    this.disabled = true;
                    var self = this;
                    SHV.api.post(_api, { todo: 'history_restore', history_idx: _pendingRestoreIdx })
                        .then(function (res) {
                            self.disabled = false;
                            closeRstModal();
                            if (res && res.success) {
                                SHV.toast.success('복구되었습니다.');
                                SHV.router.navigate('mat_view', { idx: _itemIdx });
                            } else {
                                SHV.toast.error((res && res.message) || '복구에 실패했습니다.');
                            }
                        }).catch(function () {
                            self.disabled = false;
                            SHV.toast.error('오류가 발생했습니다.');
                        });
                });
            }
        }

        /* 토글 버튼 */
        if (_toggle) {
            _toggle.addEventListener('click', function () {
                var isHidden = _body.hidden;
                _body.hidden = !isHidden;
                if (_chevron) _chevron.className = isHidden ? 'fa fa-chevron-up' : 'fa fa-chevron-down';
                if (isHidden && !_loaded) {
                    _loaded = true;
                    loadHistory(1);
                }
            });
        }
    }());

    SHV.pages = SHV.pages || {};
    SHV.pages['mat_view'] = { destroy: function () {} };
})();
</script>
