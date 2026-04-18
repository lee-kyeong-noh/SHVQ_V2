<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../../dist_library/erp/MaterialSettingsService.php';
require_once __DIR__ . '/../../../dist_library/erp/StockService.php';

function matSettingsH(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$auth = new AuthService();
$context = $auth->currentContext();
if ($context === []) {
    http_response_code(401);
    echo '<section data-page="mat-settings"><div class="empty-state"><p class="empty-message">인증이 필요합니다.</p></div></section>';
    exit;
}

$db                     = DbConnection::get();
$materialSettingsService = new MaterialSettingsService($db);
$stockService            = new StockService($db);

$materialSettingsResult = $materialSettingsService->get();
$settings               = is_array($materialSettingsResult['settings'] ?? null) ? $materialSettingsResult['settings'] : [];
$optionLabelMapKr       = is_array($materialSettingsResult['option_label_map_kr'] ?? null)
    ? $materialSettingsResult['option_label_map_kr'] : [];

$stockSettingsResult = $stockService->stockSettingsGet();
$stockSettings       = is_array($stockSettingsResult['settings'] ?? null) ? $stockSettingsResult['settings'] : [];
$branches            = is_array($stockSettingsResult['branches'] ?? null) ? $stockSettingsResult['branches'] : [];

$pjtItems        = is_array($settings['pjt_items'] ?? null) ? $settings['pjt_items'] : [];
$categoryOptions = is_array($settings['category_option_labels'] ?? null) ? $settings['category_option_labels'] : [];
?>
<section data-page="mat-settings">
    <div class="page-header">
        <h2 class="page-title" data-title="품목관리설정">품목관리설정</h2>
        <p class="page-subtitle">자재번호 규칙, 바코드, PJT 항목, 카테고리 옵션, 재고설정</p>
    </div>

    <!-- 자재번호 자동생성 규칙 -->
    <div class="card card-mt">
        <div class="card-header">
            <span>자재번호 자동생성 규칙</span>
            <button id="matNoRuleEditBtn" class="btn btn-ghost btn-sm"><i class="fa fa-edit"></i> 편집</button>
        </div>
        <div class="card-body--table">
            <table class="tbl">
                <tbody>
                    <tr>
                        <th class="col-180">Prefix</th>
                        <td data-setting-key="material_no_prefix" class="td-mono"><?= matSettingsH((string)($settings['material_no_prefix'] ?? 'MAT')) ?></td>
                    </tr>
                    <tr>
                        <th>Format</th>
                        <td data-setting-key="material_no_format" class="td-mono"><?= matSettingsH((string)($settings['material_no_format'] ?? 'MAT-[TAB]-[YYMM]-[SEQ]')) ?></td>
                    </tr>
                    <tr>
                        <th>Sequence Length</th>
                        <td data-setting-key="material_no_seq_len"><?= (int)($settings['material_no_seq_len'] ?? 4) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 바코드 자동생성 설정 -->
    <div class="card card-mt-sm">
        <div class="card-header">
            <span>바코드 자동생성 설정</span>
            <button id="matBarcodeEditBtn" class="btn btn-ghost btn-sm"><i class="fa fa-edit"></i> 편집</button>
        </div>
        <div class="card-body--table">
            <table class="tbl">
                <tbody>
                    <tr>
                        <th class="col-180">Format</th>
                        <td data-setting-key="barcode_format" class="td-mono"><?= matSettingsH((string)($settings['barcode_format'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th>카테고리 글자수</th>
                        <td data-setting-key="barcode_cat_len"><?= (int)($settings['barcode_cat_len'] ?? 4) ?></td>
                    </tr>
                    <tr>
                        <th>순번 자릿수</th>
                        <td data-setting-key="barcode_seq_len"><?= (int)($settings['barcode_seq_len'] ?? 3) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PJT 항목관리 -->
    <div class="card card-mt-sm" id="matPjtCard" data-pjt-items="<?= htmlspecialchars(json_encode($pjtItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
        <div class="card-header">
            <span>PJT 항목관리</span>
            <button id="matPjtAddBtn" class="btn btn-ghost btn-sm"><i class="fa fa-plus"></i> 항목 추가</button>
        </div>
        <div class="card-body--table">
            <table class="tbl" id="matPjtTable">
                <thead>
                    <tr>
                        <th class="col-110">코드</th>
                        <th>명칭</th>
                        <th class="col-100">색상</th>
                        <th class="col-60 th-center">삭제</th>
                    </tr>
                </thead>
                <tbody id="matPjtBody">
                    <?php if ($pjtItems === []): ?>
                        <tr id="matPjtEmptyRow"><td colspan="4"><div class="empty-state"><p class="empty-message">설정된 PJT 항목이 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($pjtItems as $item): ?>
                            <?php $color = matSettingsH((string)($item['color'] ?? '#94a3b8')); ?>
                            <tr
                                data-pjt-item-code="<?= matSettingsH((string)($item['code'] ?? '')) ?>"
                                data-pjt-item-name="<?= matSettingsH((string)($item['name'] ?? '')) ?>"
                                data-pjt-item-color="<?= $color ?>"
                            >
                                <td class="td-mono"><?= matSettingsH((string)($item['code'] ?? '')) ?></td>
                                <td><?= matSettingsH((string)($item['name'] ?? '')) ?></td>
                                <td>
                                    <span class="color-swatch">
                                        <span class="color-dot" style="background:<?= $color ?>"></span>
                                        <span class="td-mono text-xs"><?= $color ?></span>
                                    </span>
                                </td>
                                <td class="td-center">
                                    <button class="btn btn-ghost btn-sm pjt-delete-btn" data-pjt-code="<?= matSettingsH((string)($item['code'] ?? '')) ?>">
                                        <i class="fa fa-trash text-warn"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 카테고리 옵션 -->
    <div class="card card-mt-sm">
        <div class="card-header">
            <span>카테고리 옵션 (1~10 한글매핑)</span>
            <button id="matCatOptSaveBtn" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> 저장</button>
        </div>
        <div class="card-body--table">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="col-100">옵션 번호</th>
                        <th>설정 라벨</th>
                        <th>기본 라벨</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <?php $key = (string)$i; ?>
                        <?php $defaultLabel = matSettingsH((string)($optionLabelMapKr[$key] ?? '옵션' . $i)); ?>
                        <tr data-category-option-key="<?= $key ?>">
                            <td class="td-center">
                                <span class="badge badge-ghost">옵션 <?= $i ?></span>
                            </td>
                            <td>
                                <input type="text"
                                       id="catOpt<?= $i ?>"
                                       class="form-input"
                                       value="<?= matSettingsH((string)($categoryOptions[$key] ?? '')) ?>"
                                       placeholder="<?= $defaultLabel ?>">
                            </td>
                            <td class="td-muted"><?= $defaultLabel ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 재고관리 설정 -->
    <div class="card card-mb">
        <div class="card-header"><span>재고관리 설정</span></div>
        <div class="card-body--table">
            <table class="tbl">
                <tbody>
                    <tr>
                        <th class="col-180">기본 지사</th>
                        <td data-stock-setting-key="default_branch_idx"><?= (int)($stockSettings['default_branch_idx'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th>재고 부족 알림</th>
                        <td data-stock-setting-key="low_stock_alert">
                            <?php if ((int)($stockSettings['low_stock_alert'] ?? 0) === 1): ?>
                                <span class="badge badge-success">사용</span>
                            <?php else: ?>
                                <span class="badge badge-ghost">미사용</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>재고 부족 기준</th>
                        <td data-stock-setting-key="low_stock_threshold"><?= (int)($stockSettings['low_stock_threshold'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th>마이너스 재고 허용</th>
                        <td data-stock-setting-key="allow_negative_stock">
                            <?php if ((int)($stockSettings['allow_negative_stock'] ?? 0) === 1): ?>
                                <span class="badge badge-warn">허용</span>
                            <?php else: ?>
                                <span class="badge badge-ghost">비허용</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="card-section-hd">
                <h4 class="card-section-title">지사(창고) 목록</h4>
            </div>
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="col-70">IDX</th>
                        <th>지사명</th>
                        <th>주소</th>
                        <th class="col-120">연락처</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($branches === []): ?>
                        <tr><td colspan="4"><div class="empty-state"><p class="empty-message">지사(창고) 데이터가 없습니다.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($branches as $branch): ?>
                            <tr data-branch-idx="<?= (int)($branch['idx'] ?? 0) ?>">
                                <td class="td-muted td-mono"><?= (int)($branch['idx'] ?? 0) ?></td>
                                <td><?= matSettingsH((string)($branch['name'] ?? '')) ?></td>
                                <td class="td-muted"><?= matSettingsH((string)($branch['address'] ?? '')) ?></td>
                                <td class="td-mono"><?= matSettingsH((string)($branch['tel'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 자재번호 규칙 편집 모달 -->
    <div id="matNoRuleModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">자재번호 규칙 편집</h3>
                <button class="modal-close" id="matNoRuleCloseBtn"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Prefix</label>
                    <input id="nrPrefix" type="text" class="form-input" placeholder="MAT">
                </div>
                <div class="form-group">
                    <label class="form-label">Format</label>
                    <input id="nrFormat" type="text" class="form-input" placeholder="MAT-[TAB]-[YYMM]-[SEQ]">
                </div>
                <div class="form-group">
                    <label class="form-label">Sequence Length</label>
                    <input id="nrSeqLen" type="number" class="form-input" min="1" max="10" placeholder="4">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matNoRuleCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matNoRuleSubmitBtn" class="btn btn-primary">저장</button>
            </div>
        </div>
    </div>

    <!-- 바코드 설정 편집 모달 -->
    <div id="matBarcodeModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">바코드 설정 편집</h3>
                <button class="modal-close" id="matBarcodeCloseBtn"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Format</label>
                    <input id="bcFormat" type="text" class="form-input" placeholder="">
                </div>
                <div class="form-group">
                    <label class="form-label">카테고리 글자수</label>
                    <input id="bcCatLen" type="number" class="form-input" min="1" max="10" placeholder="4">
                </div>
                <div class="form-group">
                    <label class="form-label">순번 자릿수</label>
                    <input id="bcSeqLen" type="number" class="form-input" min="1" max="10" placeholder="3">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matBarcodeCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matBarcodeSubmitBtn" class="btn btn-primary">저장</button>
            </div>
        </div>
    </div>

    <!-- PJT 항목 추가 모달 -->
    <div id="matPjtAddModal" class="modal-overlay">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">PJT 항목 추가</h3>
                <button class="modal-close" id="matPjtAddCloseBtn"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">코드 <span class="badge badge-danger">필수</span></label>
                    <input id="pjtCode" type="text" class="form-input" placeholder="예: P01">
                </div>
                <div class="form-group">
                    <label class="form-label">명칭 <span class="badge badge-danger">필수</span></label>
                    <input id="pjtName" type="text" class="form-input" placeholder="예: 일반 프로젝트">
                </div>
                <div class="form-group">
                    <label class="form-label">색상</label>
                    <input id="pjtColor" type="color" class="form-input" value="#94a3b8">
                </div>
            </div>
            <div class="modal-footer">
                <button id="matPjtAddCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matPjtAddSubmitBtn" class="btn btn-primary">추가</button>
            </div>
        </div>
    </div>

    <!-- PJT 항목 삭제 확인 모달 -->
    <div id="matPjtDeleteModal" class="modal-overlay">
        <div class="modal-box modal-alert">
            <div class="modal-header">
                <h3 class="modal-title">PJT 항목 삭제</h3>
                <button class="modal-close" id="matPjtDeleteCloseBtn"><i class="fa fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p><strong id="matPjtDeleteLabel"></strong> 항목을 삭제하시겠습니까?</p>
                <p class="text-3 text-sm">삭제 후 저장하면 복구할 수 없습니다.</p>
            </div>
            <div class="modal-footer">
                <button id="matPjtDeleteCancelBtn" class="btn btn-ghost">취소</button>
                <button id="matPjtDeleteConfirmBtn" class="btn btn-danger">삭제</button>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';

    var _section = document.querySelector('[data-page="mat-settings"]');
    if (!_section) return;

    /* ── 공통 헬퍼 ── */
    function openModal(id)  { var m = document.getElementById(id); if (m) m.classList.add('open'); }
    function closeModal(id) { var m = document.getElementById(id); if (m) m.classList.remove('open'); }
    function bindClose(modalId) {
        var m = document.getElementById(modalId);
        if (!m) return;
        m.addEventListener('click', function (e) { if (e.target === m) closeModal(modalId); });
    }

    /* ── 자재번호 규칙 편집 ── */
    var _nrData = {
        prefix: (document.querySelector('[data-setting-key="material_no_prefix"]') || {}).textContent || 'MAT',
        format: (document.querySelector('[data-setting-key="material_no_format"]') || {}).textContent || 'MAT-[TAB]-[YYMM]-[SEQ]',
        seqLen: (document.querySelector('[data-setting-key="material_no_seq_len"]') || {}).textContent || '4',
    };

    document.getElementById('matNoRuleEditBtn').addEventListener('click', function () {
        document.getElementById('nrPrefix').value = _nrData.prefix.trim();
        document.getElementById('nrFormat').value = _nrData.format.trim();
        document.getElementById('nrSeqLen').value = _nrData.seqLen.trim();
        openModal('matNoRuleModal');
    });
    document.getElementById('matNoRuleCloseBtn').addEventListener('click', function () { closeModal('matNoRuleModal'); });
    document.getElementById('matNoRuleCancelBtn').addEventListener('click', function () { closeModal('matNoRuleModal'); });
    bindClose('matNoRuleModal');

    document.getElementById('matNoRuleSubmitBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        SHV.api.post('dist_process/saas/MaterialSettings.php', {
            todo:                 'material_settings_save',
            material_no_prefix:   document.getElementById('nrPrefix').value.trim(),
            material_no_format:   document.getElementById('nrFormat').value.trim(),
            material_no_seq_len:  document.getElementById('nrSeqLen').value,
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeModal('matNoRuleModal');
                if (window.SHV && SHV.toast) SHV.toast.success('자재번호 규칙이 저장되었습니다.');
                SHV.router.navigate('mat_settings', {});
            } else {
                if (window.SHV && SHV.toast) SHV.toast.error((res && res.message) || '저장에 실패했습니다.');
            }
        }).catch(function () {
            btn.disabled = false;
            if (window.SHV && SHV.toast) SHV.toast.error('오류가 발생했습니다.');
        });
    });

    /* ── 바코드 설정 편집 ── */
    var _bcData = {
        format: (document.querySelector('[data-setting-key="barcode_format"]') || {}).textContent || '',
        catLen: (document.querySelector('[data-setting-key="barcode_cat_len"]') || {}).textContent || '4',
        seqLen: (document.querySelector('[data-setting-key="barcode_seq_len"]') || {}).textContent || '3',
    };

    document.getElementById('matBarcodeEditBtn').addEventListener('click', function () {
        document.getElementById('bcFormat').value = _bcData.format.trim();
        document.getElementById('bcCatLen').value = _bcData.catLen.trim();
        document.getElementById('bcSeqLen').value = _bcData.seqLen.trim();
        openModal('matBarcodeModal');
    });
    document.getElementById('matBarcodeCloseBtn').addEventListener('click', function () { closeModal('matBarcodeModal'); });
    document.getElementById('matBarcodeCancelBtn').addEventListener('click', function () { closeModal('matBarcodeModal'); });
    bindClose('matBarcodeModal');

    document.getElementById('matBarcodeSubmitBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        SHV.api.post('dist_process/saas/MaterialSettings.php', {
            todo:             'material_settings_save',
            barcode_format:   document.getElementById('bcFormat').value.trim(),
            barcode_cat_len:  document.getElementById('bcCatLen').value,
            barcode_seq_len:  document.getElementById('bcSeqLen').value,
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeModal('matBarcodeModal');
                if (window.SHV && SHV.toast) SHV.toast.success('바코드 설정이 저장되었습니다.');
                SHV.router.navigate('mat_settings', {});
            } else {
                if (window.SHV && SHV.toast) SHV.toast.error((res && res.message) || '저장에 실패했습니다.');
            }
        }).catch(function () {
            btn.disabled = false;
            if (window.SHV && SHV.toast) SHV.toast.error('오류가 발생했습니다.');
        });
    });

    /* ── PJT 항목관리 ── */
    var _pjtCard    = document.getElementById('matPjtCard');
    var _pjtBody    = document.getElementById('matPjtBody');
    var _pjtItems   = [];

    try {
        _pjtItems = JSON.parse(_pjtCard.dataset.pjtItems || '[]');
    } catch (e) { _pjtItems = []; }

    function renderPjtRows() {
        _pjtBody.innerHTML = '';
        if (_pjtItems.length === 0) {
            _pjtBody.innerHTML = '<tr id="matPjtEmptyRow"><td colspan="4"><div class="empty-state"><p class="empty-message">설정된 PJT 항목이 없습니다.</p></div></td></tr>';
            return;
        }
        _pjtItems.forEach(function (item) {
            var code  = item.code  || '';
            var name  = item.name  || '';
            var color = item.color || '#94a3b8';
            var tr = document.createElement('tr');
            tr.dataset.pjtItemCode  = code;
            tr.dataset.pjtItemName  = name;
            tr.dataset.pjtItemColor = color;
            tr.innerHTML =
                '<td class="td-mono">' + _escHtml(code) + '</td>' +
                '<td>' + _escHtml(name) + '</td>' +
                '<td><span class="color-swatch">' +
                    '<span class="color-dot" style="background:' + _escHtml(color) + '"></span>' +
                    '<span class="td-mono text-xs">' + _escHtml(color) + '</span>' +
                '</span></td>' +
                '<td class="td-center"><button class="btn btn-ghost btn-sm pjt-delete-btn" data-pjt-code="' + _escHtml(code) + '">' +
                    '<i class="fa fa-trash text-warn"></i></button></td>';
            _pjtBody.appendChild(tr);
        });
    }

    function _escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function savePjtItems(callback) {
        SHV.api.post('dist_process/saas/MaterialSettings.php', {
            todo:      'save_pjt_items',
            pjt_items: JSON.stringify(_pjtItems),
        }).then(function (res) {
            if (res && res.success) {
                if (window.SHV && SHV.toast) SHV.toast.success('PJT 항목이 저장되었습니다.');
                if (callback) callback();
            } else {
                if (window.SHV && SHV.toast) SHV.toast.error((res && res.message) || '저장에 실패했습니다.');
            }
        }).catch(function () {
            if (window.SHV && SHV.toast) SHV.toast.error('오류가 발생했습니다.');
        });
    }

    /* PJT 항목 추가 */
    document.getElementById('matPjtAddBtn').addEventListener('click', function () {
        document.getElementById('pjtCode').value  = '';
        document.getElementById('pjtName').value  = '';
        document.getElementById('pjtColor').value = '#94a3b8';
        openModal('matPjtAddModal');
        document.getElementById('pjtCode').focus();
    });
    document.getElementById('matPjtAddCloseBtn').addEventListener('click', function () { closeModal('matPjtAddModal'); });
    document.getElementById('matPjtAddCancelBtn').addEventListener('click', function () { closeModal('matPjtAddModal'); });
    bindClose('matPjtAddModal');

    document.getElementById('matPjtAddSubmitBtn').addEventListener('click', function () {
        var code  = document.getElementById('pjtCode').value.trim();
        var name  = document.getElementById('pjtName').value.trim();
        var color = document.getElementById('pjtColor').value.trim() || '#94a3b8';
        if (!code || !name) {
            if (window.SHV && SHV.toast) SHV.toast.warn('코드와 명칭은 필수입니다.');
            return;
        }
        if (_pjtItems.some(function (it) { return it.code === code; })) {
            if (window.SHV && SHV.toast) SHV.toast.warn('이미 동일한 코드가 존재합니다.');
            return;
        }
        _pjtItems.push({ code: code, name: name, color: color });
        var btn = this;
        btn.disabled = true;
        savePjtItems(function () {
            btn.disabled = false;
            closeModal('matPjtAddModal');
            renderPjtRows();
        });
        btn.disabled = false;
    });

    /* PJT 항목 삭제 */
    var _pjtDeleteCode = '';
    _pjtBody.addEventListener('click', function (e) {
        var deleteBtn = e.target.closest('.pjt-delete-btn');
        if (!deleteBtn) return;
        _pjtDeleteCode = deleteBtn.dataset.pjtCode || '';
        var label = document.getElementById('matPjtDeleteLabel');
        if (label) label.textContent = _pjtDeleteCode;
        openModal('matPjtDeleteModal');
    });

    document.getElementById('matPjtDeleteCloseBtn').addEventListener('click', function () { closeModal('matPjtDeleteModal'); });
    document.getElementById('matPjtDeleteCancelBtn').addEventListener('click', function () { closeModal('matPjtDeleteModal'); });
    bindClose('matPjtDeleteModal');

    document.getElementById('matPjtDeleteConfirmBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        _pjtItems = _pjtItems.filter(function (it) { return it.code !== _pjtDeleteCode; });
        savePjtItems(function () {
            btn.disabled = false;
            closeModal('matPjtDeleteModal');
            renderPjtRows();
        });
    });

    /* ── 카테고리 옵션 저장 ── */
    document.getElementById('matCatOptSaveBtn').addEventListener('click', function () {
        var btn = this;
        var labels = {};
        for (var i = 1; i <= 10; i++) {
            var el = document.getElementById('catOpt' + i);
            if (el) labels[String(i)] = el.value.trim();
        }
        btn.disabled = true;
        SHV.api.post('dist_process/saas/MaterialSettings.php', {
            todo:   'save_category_option_labels',
            labels: JSON.stringify(labels),
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                if (window.SHV && SHV.toast) SHV.toast.success('카테고리 옵션이 저장되었습니다.');
            } else {
                if (window.SHV && SHV.toast) SHV.toast.error((res && res.message) || '저장에 실패했습니다.');
            }
        }).catch(function () {
            btn.disabled = false;
            if (window.SHV && SHV.toast) SHV.toast.error('오류가 발생했습니다.');
        });
    });

    SHV.pages = SHV.pages || {};
    SHV.pages['mat_settings'] = { destroy: function () {} };
})();
</script>
