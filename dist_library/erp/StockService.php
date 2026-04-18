<?php
declare(strict_types=1);

final class StockService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTables();
    }

    public function stockStatus(array $query): array
    {
        $branchIdx = (int)($query['branch_idx'] ?? 0);
        $tabIdx = (int)($query['tab_idx'] ?? 0);
        $search = trim((string)($query['search'] ?? ''));
        $page = max(1, (int)($query['p'] ?? 1));
        $limit = min(500, max(1, (int)($query['limit'] ?? 50)));
        $includeZero = ((int)($query['include_zero'] ?? 0)) === 1;

        $branches = $this->branchList();

        if (!$this->tableExists('Tb_Item')) {
            return [
                'list' => [],
                'branches' => $branches,
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
                'include_zero' => $includeZero,
            ];
        }

        $stockHasItemIdx = $this->columnExists('Tb_Stock', 'item_idx');
        $stockHasBranchIdx = $this->columnExists('Tb_Stock', 'branch_idx');
        $stockHasQty = $this->columnExists('Tb_Stock', 'qty');

        $where = [];
        $params = [];

        if ($this->columnExists('Tb_Item', 'inventory_management')) {
            $where[] = "ISNULL(i.inventory_management, N'무') = N'유'";
        } else {
            $where[] = '1=1';
        }

        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.is_deleted, 0) = 0';
        }

        if ($search !== '') {
            $searchSql = [];
            foreach (['name', 'standard', 'item_code'] as $column) {
                if ($this->columnExists('Tb_Item', $column)) {
                    $searchSql[] = "i.{$column} LIKE ?";
                }
            }

            if ($searchSql !== []) {
                $where[] = '(' . implode(' OR ', $searchSql) . ')';
                $sp = '%' . $search . '%';
                for ($i = 0; $i < count($searchSql); $i++) {
                    $params[] = $sp;
                }
            }
        }

        if ($tabIdx > 0 && $this->columnExists('Tb_Item', 'tab_idx')) {
            $where[] = 'i.tab_idx = ?';
            $params[] = $tabIdx;
        }

        if (!$includeZero && $stockHasItemIdx && $stockHasQty) {
            $existsSql = 'EXISTS (SELECT 1 FROM Tb_Stock s WHERE s.item_idx = i.idx AND ISNULL(s.qty,0) <> 0';
            if ($branchIdx > 0 && $stockHasBranchIdx) {
                $existsSql .= ' AND s.branch_idx = ?';
                $params[] = $branchIdx;
            }
            $existsSql .= ')';
            $where[] = $existsSql;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM Tb_Item i {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $tabJoin = $this->tableExists('Tb_ItemTab')
            && $this->columnExists('Tb_Item', 'tab_idx')
            && $this->columnExists('Tb_ItemTab', 'idx')
            ? 'LEFT JOIN Tb_ItemTab t ON i.tab_idx = t.idx'
            : '';
        $catJoin = $this->tableExists('Tb_ItemCategory')
            && $this->columnExists('Tb_Item', 'category_idx')
            && $this->columnExists('Tb_ItemCategory', 'idx')
            ? 'LEFT JOIN Tb_ItemCategory c ON i.category_idx = c.idx'
            : '';

        $tabNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($tabJoin !== '') {
            if ($this->columnExists('Tb_ItemTab', 'name')) {
                $tabNameExpr = "ISNULL(t.name, '')";
            } elseif ($this->columnExists('Tb_ItemTab', 'tab_name')) {
                $tabNameExpr = "ISNULL(t.tab_name, '')";
            }
        }

        $catNameExpr = "CAST('' AS NVARCHAR(120))";
        if ($catJoin !== '') {
            if ($this->columnExists('Tb_ItemCategory', 'name')) {
                $catNameExpr = "ISNULL(c.name, '')";
            } elseif ($this->columnExists('Tb_ItemCategory', 'category_name')) {
                $catNameExpr = "ISNULL(c.category_name, '')";
            }
        }

        $itemCodeExpr = $this->columnExists('Tb_Item', 'item_code')
            ? "ISNULL(i.item_code, '')"
            : "CAST('' AS NVARCHAR(120))";
        $itemNameExpr = $this->columnExists('Tb_Item', 'name')
            ? "ISNULL(i.name, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemStandardExpr = $this->columnExists('Tb_Item', 'standard')
            ? "ISNULL(i.standard, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemUnitExpr = $this->columnExists('Tb_Item', 'unit')
            ? "ISNULL(i.unit, '')"
            : "CAST('' AS NVARCHAR(60))";
        $itemTabIdxExpr = $this->columnExists('Tb_Item', 'tab_idx')
            ? 'ISNULL(i.tab_idx, 0)'
            : '0';
        $itemCategoryIdxExpr = $this->columnExists('Tb_Item', 'category_idx')
            ? 'ISNULL(i.category_idx, 0)'
            : '0';
        $itemInventoryExpr = $this->columnExists('Tb_Item', 'inventory_management')
            ? "ISNULL(i.inventory_management, N'무')"
            : "N'무'";
        $itemSafetyCountExpr = $this->columnExists('Tb_Item', 'safety_count')
            ? 'ISNULL(i.safety_count, 0)'
            : '0';
        $itemBaseCountExpr = $this->columnExists('Tb_Item', 'base_count')
            ? 'ISNULL(i.base_count, 0)'
            : '0';

        $orderParts = [];
        if ($this->columnExists('Tb_Item', 'name')) {
            $orderParts[] = 'i.name ASC';
        }
        $orderParts[] = 'i.idx DESC';

        $listSql = "SELECT * FROM (
            SELECT
                i.idx,
                {$itemCodeExpr} AS item_code,
                {$itemNameExpr} AS name,
                {$itemStandardExpr} AS standard,
                {$itemUnitExpr} AS unit,
                {$itemTabIdxExpr} AS tab_idx,
                {$itemCategoryIdxExpr} AS category_idx,
                {$itemInventoryExpr} AS inventory_management,
                {$itemSafetyCountExpr} AS safety_count,
                {$itemBaseCountExpr} AS base_count,
                {$tabNameExpr} AS tab_name,
                {$catNameExpr} AS category_name,
                ROW_NUMBER() OVER (ORDER BY " . implode(', ', $orderParts) . ") AS rn
            FROM Tb_Item i
            {$tabJoin}
            {$catJoin}
            {$whereSql}
        ) x WHERE x.rn BETWEEN ? AND ?";

        $listParams = array_merge($params, [$rowFrom, $rowTo]);
        $stmt = $this->db->prepare($listSql);
        $stmt->execute($listParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemIds = [];
        foreach ($rows as $row) {
            $itemIds[] = (int)($row['idx'] ?? 0);
        }
        $itemIds = array_values(array_filter(array_unique($itemIds), static fn(int $v): bool => $v > 0));

        $stockMap = [];
        if ($itemIds !== [] && $stockHasItemIdx && $stockHasBranchIdx && $stockHasQty) {
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            $stockSql = "SELECT item_idx, branch_idx, ISNULL(qty,0) AS qty FROM Tb_Stock WHERE item_idx IN ({$ph})";
            $stockStmt = $this->db->prepare($stockSql);
            $stockStmt->execute($itemIds);
            $stockRows = $stockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($stockRows as $stockRow) {
                $iIdx = (int)($stockRow['item_idx'] ?? 0);
                $bIdx = (int)($stockRow['branch_idx'] ?? 0);
                $qty = (int)($stockRow['qty'] ?? 0);
                if ($iIdx <= 0 || $bIdx <= 0) {
                    continue;
                }
                if (!isset($stockMap[$iIdx])) {
                    $stockMap[$iIdx] = [];
                }
                $stockMap[$iIdx][$bIdx] = $qty;
            }
        }

        foreach ($rows as &$row) {
            $iIdx = (int)($row['idx'] ?? 0);
            $stocks = $stockMap[$iIdx] ?? [];
            $row['stocks'] = $stocks;
            $row['total_qty'] = array_sum($stocks);
        }
        unset($row);

        return [
            'list' => $rows,
            'branches' => $branches,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
            'include_zero' => $includeZero,
        ];
    }

    public function stockIn(array $input, int $actorUserPk = 0, string $actorName = ''): array
    {
        $data = $this->pickAndCast($input, $this->stockInFieldTypeMap());

        $itemIdx = (int)($data['item_idx'] ?? 0);
        $branchIdx = (int)($data['branch_idx'] ?? 0);
        $qty = (int)($data['qty'] ?? 0);
        $memo = (string)($data['memo'] ?? '');

        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }
        if ($branchIdx <= 0) {
            throw new InvalidArgumentException('branch_idx is required');
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException('qty must be greater than 0');
        }

        $this->ensureInventoryManagedItem($itemIdx);
        $this->ensureWarehouseBranch($branchIdx);

        $this->db->beginTransaction();
        try {
            $stockRow = $this->getStockRowForUpdate($itemIdx, $branchIdx);
            $beforeQty = (int)($stockRow['qty'] ?? 0);
            $afterQty = $beforeQty + $qty;

            if (is_array($stockRow)) {
                $this->updateStockQty((int)$stockRow['idx'], $afterQty, true, false);
            } else {
                $this->insertStockRow($itemIdx, $branchIdx, $afterQty, true, false);
            }

            $this->insertStockLog([
                'stock_type' => 1,
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => $qty,
                'before_qty' => $beforeQty,
                'after_qty' => $afterQty,
                'memo' => $memo,
                'emp_idx' => max(0, $actorUserPk),
                'emp_name' => $actorName,
            ]);

            $this->db->commit();

            return [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => $qty,
                'before_qty' => $beforeQty,
                'after_qty' => $afterQty,
                'message' => '입고 완료',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('stockIn', $e, [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => $qty,
            ]);
            throw $e;
        }
    }

    public function stockOut(array $input, int $actorUserPk = 0, string $actorName = ''): array
    {
        $data = $this->pickAndCast($input, $this->stockOutFieldTypeMap());

        $itemIdx = (int)($data['item_idx'] ?? 0);
        $branchIdx = (int)($data['branch_idx'] ?? 0);
        $qty = (int)($data['qty'] ?? 0);
        $siteIdx = (int)($data['site_idx'] ?? 0);
        $memo = (string)($data['memo'] ?? '');

        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }
        if ($branchIdx <= 0) {
            throw new InvalidArgumentException('branch_idx is required');
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException('qty must be greater than 0');
        }

        $this->ensureInventoryManagedItem($itemIdx);
        $this->ensureWarehouseBranch($branchIdx);

        $settings = $this->stockSettingsGet();
        $allowNegative = ((int)($settings['settings']['allow_negative_stock'] ?? 0)) === 1;

        $this->db->beginTransaction();
        try {
            $stockRow = $this->getStockRowForUpdate($itemIdx, $branchIdx);
            $beforeQty = (int)($stockRow['qty'] ?? 0);

            if (!$allowNegative && $qty > $beforeQty) {
                throw new RuntimeException('재고 부족 (현재고: ' . $beforeQty . ')');
            }

            $afterQty = $beforeQty - $qty;

            if (is_array($stockRow)) {
                $this->updateStockQty((int)$stockRow['idx'], $afterQty, false, true);
            } else {
                $this->insertStockRow($itemIdx, $branchIdx, $afterQty, false, true);
            }

            $this->insertStockLog([
                'stock_type' => 2,
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => $qty,
                'before_qty' => $beforeQty,
                'after_qty' => $afterQty,
                'site_idx' => $siteIdx > 0 ? $siteIdx : null,
                'memo' => $memo,
                'emp_idx' => max(0, $actorUserPk),
                'emp_name' => $actorName,
            ]);

            $this->db->commit();

            return [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'site_idx' => $siteIdx,
                'qty' => $qty,
                'before_qty' => $beforeQty,
                'after_qty' => $afterQty,
                'allow_negative_stock' => $allowNegative,
                'message' => '출고 완료',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('stockOut', $e, [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => $qty,
                'site_idx' => $siteIdx,
            ]);
            throw $e;
        }
    }

    public function stockTransfer(array $input, int $actorUserPk = 0, string $actorName = ''): array
    {
        $data = $this->pickAndCast($input, $this->stockTransferFieldTypeMap());

        $itemIdx = (int)($data['item_idx'] ?? 0);
        $fromBranchIdx = (int)($data['from_branch_idx'] ?? 0);
        $toBranchIdx = (int)($data['to_branch_idx'] ?? 0);
        $qty = (int)($data['qty'] ?? 0);
        $memo = (string)($data['memo'] ?? '');

        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }
        if ($fromBranchIdx <= 0 || $toBranchIdx <= 0) {
            throw new InvalidArgumentException('from_branch_idx and to_branch_idx are required');
        }
        if ($fromBranchIdx === $toBranchIdx) {
            throw new InvalidArgumentException('same branch transfer is not allowed');
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException('qty must be greater than 0');
        }

        $this->ensureInventoryManagedItem($itemIdx);
        $this->ensureWarehouseBranch($fromBranchIdx);
        $this->ensureWarehouseBranch($toBranchIdx);

        $settings = $this->stockSettingsGet();
        $allowNegative = ((int)($settings['settings']['allow_negative_stock'] ?? 0)) === 1;

        $this->db->beginTransaction();
        try {
            $fromRow = $this->getStockRowForUpdate($itemIdx, $fromBranchIdx);
            $toRow = $this->getStockRowForUpdate($itemIdx, $toBranchIdx);

            $fromBefore = (int)($fromRow['qty'] ?? 0);
            $toBefore = (int)($toRow['qty'] ?? 0);

            if (!$allowNegative && $qty > $fromBefore) {
                throw new RuntimeException('출발 창고 재고 부족 (현재고: ' . $fromBefore . ')');
            }

            $fromAfter = $fromBefore - $qty;
            $toAfter = $toBefore + $qty;

            if (is_array($fromRow)) {
                $this->updateStockQty((int)$fromRow['idx'], $fromAfter, false, true);
            } else {
                $this->insertStockRow($itemIdx, $fromBranchIdx, $fromAfter, false, true);
            }

            if (is_array($toRow)) {
                $this->updateStockQty((int)$toRow['idx'], $toAfter, true, false);
            } else {
                $this->insertStockRow($itemIdx, $toBranchIdx, $toAfter, true, false);
            }

            $this->insertStockLog([
                'stock_type' => 3,
                'item_idx' => $itemIdx,
                'branch_idx' => $fromBranchIdx,
                'qty' => $qty,
                'before_qty' => $fromBefore,
                'after_qty' => $fromAfter,
                'target_branch_idx' => $toBranchIdx,
                'memo' => $memo,
                'emp_idx' => max(0, $actorUserPk),
                'emp_name' => $actorName,
            ]);

            $this->insertStockLog([
                'stock_type' => 4,
                'item_idx' => $itemIdx,
                'branch_idx' => $toBranchIdx,
                'qty' => $qty,
                'before_qty' => $toBefore,
                'after_qty' => $toAfter,
                'target_branch_idx' => $fromBranchIdx,
                'memo' => $memo,
                'emp_idx' => max(0, $actorUserPk),
                'emp_name' => $actorName,
            ]);

            $this->db->commit();

            return [
                'item_idx' => $itemIdx,
                'from_branch_idx' => $fromBranchIdx,
                'to_branch_idx' => $toBranchIdx,
                'qty' => $qty,
                'from_before_qty' => $fromBefore,
                'from_after_qty' => $fromAfter,
                'to_before_qty' => $toBefore,
                'to_after_qty' => $toAfter,
                'allow_negative_stock' => $allowNegative,
                'message' => '창고간 이동 완료',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('stockTransfer', $e, [
                'item_idx' => $itemIdx,
                'from_branch_idx' => $fromBranchIdx,
                'to_branch_idx' => $toBranchIdx,
                'qty' => $qty,
            ]);
            throw $e;
        }
    }

    public function stockAdjust(array $input, int $actorUserPk = 0, string $actorName = ''): array
    {
        $data = $this->pickAndCast($input, $this->stockAdjustFieldTypeMap());

        $itemIdx = (int)($data['item_idx'] ?? 0);
        $branchIdx = (int)($data['branch_idx'] ?? 0);
        $newQty = (int)($data['new_qty'] ?? 0);
        $memo = (string)($data['memo'] ?? '');

        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }
        if ($branchIdx <= 0) {
            throw new InvalidArgumentException('branch_idx is required');
        }
        if ($newQty < 0) {
            throw new InvalidArgumentException('new_qty must be 0 or greater');
        }

        $this->ensureInventoryManagedItem($itemIdx);
        $this->ensureWarehouseBranch($branchIdx);

        $this->db->beginTransaction();
        try {
            $stockRow = $this->getStockRowForUpdate($itemIdx, $branchIdx);
            $beforeQty = (int)($stockRow['qty'] ?? 0);
            $diff = $newQty - $beforeQty;
            if ($diff === 0) {
                throw new RuntimeException('변경사항이 없습니다');
            }

            if (is_array($stockRow)) {
                $this->updateStockQty((int)$stockRow['idx'], $newQty, false, false);
            } else {
                $this->insertStockRow($itemIdx, $branchIdx, $newQty, false, false);
            }

            $stockType = $diff > 0 ? 5 : 6;
            $this->insertStockLog([
                'stock_type' => $stockType,
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'qty' => abs($diff),
                'before_qty' => $beforeQty,
                'after_qty' => $newQty,
                'memo' => $memo,
                'emp_idx' => max(0, $actorUserPk),
                'emp_name' => $actorName,
            ]);

            $this->db->commit();

            return [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'before_qty' => $beforeQty,
                'new_qty' => $newQty,
                'diff' => $diff,
                'stock_type' => $stockType,
                'message' => '재고 조정 완료',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('stockAdjust', $e, [
                'item_idx' => $itemIdx,
                'branch_idx' => $branchIdx,
                'new_qty' => $newQty,
            ]);
            throw $e;
        }
    }

    public function stockLog(array $query): array
    {
        $branchIdx = (int)($query['branch_idx'] ?? 0);
        $stockTypeRaw = $query['stock_type'] ?? 0;
        $stockType = (int)$stockTypeRaw;
        $stockTypeList = $this->normalizeStockTypeList(
            $query['stock_type_in'] ?? $query['stock_type_list'] ?? $query['stock_types'] ?? null
        );
        if ($stockTypeList === []) {
            $stockTypeList = $this->normalizeStockTypeList($stockTypeRaw);
        } elseif ($stockType > 0) {
            $stockTypeList[] = $stockType;
        }
        if ($stockTypeList !== []) {
            $stockTypeList = array_values(array_unique($stockTypeList));
        }
        $itemIdx = (int)($query['item_idx'] ?? 0);
        $search = trim((string)($query['search'] ?? ''));
        $dateS = trim((string)($query['date_s'] ?? ''));
        $dateE = trim((string)($query['date_e'] ?? ''));
        $page = max(1, (int)($query['p'] ?? 1));
        $limit = min(500, max(1, (int)($query['limit'] ?? 50)));

        $itemJoin = $this->tableExists('Tb_Item')
            ? 'LEFT JOIN Tb_Item i ON l.item_idx = i.idx'
            : '';

        $branchNameColumn = $this->columnExists('Tb_Department', 'depart_name')
            ? 'depart_name'
            : ($this->columnExists('Tb_Department', 'name') ? 'name' : '');
        $branchJoin = $branchNameColumn !== ''
            ? 'LEFT JOIN Tb_Department d ON l.branch_idx = d.idx'
            : '';

        $hasTargetBranchIdx = $this->columnExists('Tb_StockLog', 'target_branch_idx');
        $targetBranchJoin = ($branchNameColumn !== '' && $hasTargetBranchIdx)
            ? 'LEFT JOIN Tb_Department td ON l.target_branch_idx = td.idx'
            : '';

        $siteNameColumn = $this->columnExists('Tb_Site', 'name')
            ? 'name'
            : ($this->columnExists('Tb_Site', 'site_name') ? 'site_name' : '');
        $hasSiteIdx = $this->columnExists('Tb_StockLog', 'site_idx');
        $siteJoin = ($siteNameColumn !== '' && $hasSiteIdx)
            ? 'LEFT JOIN Tb_Site s ON l.site_idx = s.idx'
            : '';

        $where = ['1=1'];
        $params = [];

        if ($branchIdx > 0 && $this->columnExists('Tb_StockLog', 'branch_idx')) {
            $where[] = 'l.branch_idx = ?';
            $params[] = $branchIdx;
        }
        if ($stockTypeList !== [] && $this->columnExists('Tb_StockLog', 'stock_type')) {
            $where[] = 'l.stock_type IN (' . implode(',', array_fill(0, count($stockTypeList), '?')) . ')';
            foreach ($stockTypeList as $type) {
                $params[] = $type;
            }
        }
        if ($itemIdx > 0 && $this->columnExists('Tb_StockLog', 'item_idx')) {
            $where[] = 'l.item_idx = ?';
            $params[] = $itemIdx;
        }
        if ($search !== '' && $itemJoin !== '') {
            $searchSql = [];
            foreach (['name', 'item_code', 'standard'] as $column) {
                if ($this->columnExists('Tb_Item', $column)) {
                    $searchSql[] = "i.{$column} LIKE ?";
                }
            }

            if ($searchSql !== []) {
                $where[] = '(' . implode(' OR ', $searchSql) . ')';
                $sp = '%' . $search . '%';
                for ($i = 0; $i < count($searchSql); $i++) {
                    $params[] = $sp;
                }
            }
        }
        if ($dateS !== '' && $dateE !== '' && $this->columnExists('Tb_StockLog', 'regdate')) {
            $where[] = 'l.regdate BETWEEN ? AND ?';
            $params[] = $dateS . ' 00:00:00';
            $params[] = $dateE . ' 23:59:59';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM Tb_StockLog l {$itemJoin} {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $itemCodeExpr = ($itemJoin !== '' && $this->columnExists('Tb_Item', 'item_code'))
            ? "ISNULL(i.item_code, '')"
            : "CAST('' AS NVARCHAR(120))";
        $itemNameExpr = ($itemJoin !== '' && $this->columnExists('Tb_Item', 'name'))
            ? "ISNULL(i.name, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemStandardExpr = ($itemJoin !== '' && $this->columnExists('Tb_Item', 'standard'))
            ? "ISNULL(i.standard, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemUnitExpr = ($itemJoin !== '' && $this->columnExists('Tb_Item', 'unit'))
            ? "ISNULL(i.unit, '')"
            : "CAST('' AS NVARCHAR(60))";

        $beforeQtyExpr = $this->columnExists('Tb_StockLog', 'before_qty') ? 'ISNULL(l.before_qty, 0)' : '0';
        $afterQtyExpr = $this->columnExists('Tb_StockLog', 'after_qty') ? 'ISNULL(l.after_qty, 0)' : '0';
        $targetBranchIdxExpr = $hasTargetBranchIdx ? 'l.target_branch_idx' : 'NULL';
        $siteIdxExpr = $hasSiteIdx ? 'l.site_idx' : 'NULL';
        $memoExpr = $this->columnExists('Tb_StockLog', 'memo') ? "ISNULL(l.memo, '')" : "CAST('' AS NVARCHAR(500))";
        $empIdxExpr = $this->columnExists('Tb_StockLog', 'emp_idx') ? 'ISNULL(l.emp_idx, 0)' : '0';
        $empNameExpr = $this->columnExists('Tb_StockLog', 'emp_name') ? "ISNULL(l.emp_name, '')" : "CAST('' AS NVARCHAR(50))";
        $regdateExpr = $this->columnExists('Tb_StockLog', 'regdate') ? 'l.regdate' : "CAST('' AS NVARCHAR(19))";

        $branchNameExpr = $branchJoin !== ''
            ? "ISNULL(d.{$branchNameColumn}, '')"
            : "CAST('' AS NVARCHAR(200))";
        $targetBranchNameExpr = $targetBranchJoin !== ''
            ? "ISNULL(td.{$branchNameColumn}, '')"
            : "CAST('' AS NVARCHAR(200))";
        $siteNameExpr = $siteJoin !== ''
            ? "ISNULL(s.{$siteNameColumn}, '')"
            : "CAST('' AS NVARCHAR(200))";

        $listSql = "SELECT * FROM (
            SELECT
                l.idx,
                l.stock_type,
                l.item_idx,
                l.branch_idx,
                l.qty,
                {$beforeQtyExpr} AS before_qty,
                {$afterQtyExpr} AS after_qty,
                {$targetBranchIdxExpr} AS target_branch_idx,
                {$siteIdxExpr} AS site_idx,
                {$memoExpr} AS memo,
                {$empIdxExpr} AS emp_idx,
                {$empNameExpr} AS emp_name,
                {$regdateExpr} AS regdate,
                {$itemCodeExpr} AS item_code,
                {$itemNameExpr} AS item_name,
                {$itemStandardExpr} AS standard,
                {$itemUnitExpr} AS unit,
                {$branchNameExpr} AS branch_name,
                {$targetBranchNameExpr} AS target_branch_name,
                {$siteNameExpr} AS site_name,
                ROW_NUMBER() OVER (ORDER BY l.idx DESC) AS rn
            FROM Tb_StockLog l
            {$itemJoin}
            {$branchJoin}
            {$targetBranchJoin}
            {$siteJoin}
            {$whereSql}
        ) z WHERE z.rn BETWEEN ? AND ?";

        $listParams = array_merge($params, [$rowFrom, $rowTo]);
        $stmt = $this->db->prepare($listSql);
        $stmt->execute($listParams);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ];
    }

    public function stockSettingsGet(): array
    {
        $this->ensureStockSettingTable();

        $defaults = [
            'default_branch_idx' => 0,
            'low_stock_alert' => 1,
            'low_stock_threshold' => 10,
            'allow_negative_stock' => 0,
        ];

        $stmt = $this->db->query("SELECT setting_key, setting_value FROM Tb_StockSetting");
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $settings = $defaults;
        foreach ($rows as $row) {
            $key = trim((string)($row['setting_key'] ?? ''));
            if (!array_key_exists($key, $defaults)) {
                continue;
            }
            $settings[$key] = $this->castByType($this->settingsFieldTypeMap()[$key], $row['setting_value'] ?? null);
        }

        return [
            'settings' => $settings,
            'branches' => $this->branchList(),
        ];
    }

    public function stockSettingsSave(array $input, int $actorUserPk = 0): array
    {
        $this->ensureStockSettingTable();

        $map = $this->settingsFieldTypeMap();
        $updates = $this->pickAndCast($input, $map);
        if ($updates === []) {
            throw new InvalidArgumentException('no writable setting key provided');
        }

        if (isset($updates['default_branch_idx']) && (int)$updates['default_branch_idx'] > 0) {
            $this->ensureWarehouseBranch((int)$updates['default_branch_idx']);
        }

        foreach ($updates as $key => $value) {
            $existsStmt = $this->db->prepare('SELECT COUNT(*) FROM Tb_StockSetting WHERE setting_key = ?');
            $existsStmt->execute([$key]);
            $exists = (int)$existsStmt->fetchColumn() > 0;

            if ($exists) {
                $sql = 'UPDATE Tb_StockSetting SET setting_value = ?';
                $params = [(string)$value];
                if ($this->columnExists('Tb_StockSetting', 'updated_by')) {
                    $sql .= ', updated_by = ?';
                    $params[] = max(0, $actorUserPk);
                }
                if ($this->columnExists('Tb_StockSetting', 'updated_at')) {
                    $sql .= ', updated_at = GETDATE()';
                }
                $sql .= ' WHERE setting_key = ?';
                $params[] = $key;

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            } else {
                $columns = ['setting_key', 'setting_value'];
                $values = [$key, (string)$value];

                if ($this->columnExists('Tb_StockSetting', 'updated_by')) {
                    $columns[] = 'updated_by';
                    $values[] = max(0, $actorUserPk);
                }

                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $sql = 'INSERT INTO Tb_StockSetting (' . implode(',', $this->quoteColumns($columns)) . ') VALUES (' . $placeholders . ')';
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
            }
        }

        return $this->stockSettingsGet();
    }

    public function branchList(): array
    {
        if (!$this->tableExists('Tb_Department')) {
            return [];
        }

        $where = ['1=1'];
        if ($this->columnExists('Tb_Department', 'type')) {
            $where[] = 'ISNULL(type, 0) = 1';
        }
        if ($this->columnExists('Tb_Department', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted, 0) = 0';
        }

        $order = [];
        if ($this->columnExists('Tb_Department', 'sort_order')) {
            $order[] = 'sort_order ASC';
        }
        $order[] = 'idx ASC';

        $nameColumn = $this->columnExists('Tb_Department', 'depart_name') ? 'depart_name' : 'name';
        $addressColumn = $this->columnExists('Tb_Department', 'address') ? 'address' : "''";
        $telColumn = $this->columnExists('Tb_Department', 'tel') ? 'tel' : "''";

        $sql = 'SELECT idx, ISNULL(' . $nameColumn . ", '') AS name, ISNULL(" . $addressColumn . ", '') AS address, ISNULL(" . $telColumn . ", '') AS tel FROM Tb_Department WHERE " . implode(' AND ', $where) . ' ORDER BY ' . implode(', ', $order);
        $stmt = $this->db->query($sql);

        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function itemSearch(string $queryText, int $tabIdx = 0, int $limit = 20): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));

        $where = [];
        $params = [];

        if ($this->columnExists('Tb_Item', 'inventory_management')) {
            $where[] = "ISNULL(i.inventory_management, N'무') = N'유'";
        }

        $searchSql = [];
        foreach (['name', 'standard', 'item_code'] as $column) {
            if ($this->columnExists('Tb_Item', $column)) {
                $searchSql[] = "i.{$column} LIKE ?";
            }
        }

        if ($searchSql === []) {
            return [];
        }

        $where[] = '(' . implode(' OR ', $searchSql) . ')';
        $sp = '%' . $queryText . '%';
        for ($i = 0; $i < count($searchSql); $i++) {
            $params[] = $sp;
        }

        if ($tabIdx > 0 && $this->columnExists('Tb_Item', 'tab_idx')) {
            $where[] = 'i.tab_idx = ?';
            $params[] = $tabIdx;
        }

        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.is_deleted, 0) = 0';
        }

        $itemCodeExpr = $this->columnExists('Tb_Item', 'item_code')
            ? "ISNULL(i.item_code, '')"
            : "CAST('' AS NVARCHAR(120))";
        $itemNameExpr = $this->columnExists('Tb_Item', 'name')
            ? "ISNULL(i.name, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemStandardExpr = $this->columnExists('Tb_Item', 'standard')
            ? "ISNULL(i.standard, '')"
            : "CAST('' AS NVARCHAR(255))";
        $itemUnitExpr = $this->columnExists('Tb_Item', 'unit')
            ? "ISNULL(i.unit, '')"
            : "CAST('' AS NVARCHAR(60))";
        $itemTabIdxExpr = $this->columnExists('Tb_Item', 'tab_idx')
            ? 'ISNULL(i.tab_idx, 0)'
            : '0';
        $itemCategoryIdxExpr = $this->columnExists('Tb_Item', 'category_idx')
            ? 'ISNULL(i.category_idx, 0)'
            : '0';
        $itemInventoryExpr = $this->columnExists('Tb_Item', 'inventory_management')
            ? "ISNULL(i.inventory_management, N'무')"
            : "N'무'";

        $orderSql = $this->columnExists('Tb_Item', 'name')
            ? 'i.name ASC'
            : 'i.idx DESC';

        $sql = "SELECT TOP ({$limit})
                    i.idx,
                    {$itemCodeExpr} AS item_code,
                    {$itemNameExpr} AS name,
                    {$itemStandardExpr} AS standard,
                    {$itemUnitExpr} AS unit,
                    {$itemTabIdxExpr} AS tab_idx,
                    {$itemCategoryIdxExpr} AS category_idx,
                    {$itemInventoryExpr} AS inventory_management
                FROM Tb_Item i
                WHERE " . implode(' AND ', $where) . '
                ORDER BY ' . $orderSql;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function itemStockDetail(int $itemIdx): array
    {
        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }

        $this->ensureInventoryManagedItem($itemIdx);

        $where = ['s.item_idx = ?'];
        $params = [$itemIdx];

        if ($this->columnExists('Tb_Department', 'type')) {
            $where[] = 'ISNULL(d.type, 0) = 1';
        }

        $sql = "SELECT
                    s.idx,
                    s.item_idx,
                    s.branch_idx,
                    ISNULL(s.qty,0) AS qty,
                    ISNULL(s.safety_qty,0) AS safety_qty,
                    s.last_in_date,
                    s.last_out_date,
                    s.regdate,
                    " . ($this->columnExists('Tb_Department', 'depart_name') ? "ISNULL(d.depart_name,'')" : "ISNULL(d.name,'')") . " AS branch_name
                FROM Tb_Stock s
                LEFT JOIN Tb_Department d ON s.branch_idx = d.idx
                WHERE " . implode(' AND ', $where) . '
                ORDER BY branch_name ASC, s.branch_idx ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function siteSearch(string $queryText, int $limit = 20): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return [];
        }

        if (!$this->tableExists('Tb_Site')) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $siteNameCol = $this->columnExists('Tb_Site', 'site_name')
            ? 'site_name'
            : ($this->columnExists('Tb_Site', 'name') ? 'name' : '');

        if ($siteNameCol === '') {
            return [];
        }

        $memberNameExpr = "CAST('' AS NVARCHAR(200))";
        if ($this->tableExists('Tb_Members')) {
            $memberNameExpr = "ISNULL(m.name, '')";
        }

        $isDeletedFilter = $this->columnExists('Tb_Site', 'is_deleted')
            ? ' AND ISNULL(s.is_deleted, 0) = 0'
            : '';

        $sql = "SELECT TOP ({$limit})
                    s.idx,
                    ISNULL(s.{$siteNameCol}, '') AS site_name,
                    {$memberNameExpr} AS member_name
                FROM Tb_Site s
                " . ($this->tableExists('Tb_Members') ? 'LEFT JOIN Tb_Members m ON s.member_idx = m.idx' : '') . "
                WHERE (s.{$siteNameCol} LIKE ? " . ($this->tableExists('Tb_Members') ? 'OR ISNULL(m.name, \'\') LIKE ?' : '') . ")
                {$isDeletedFilter}
                ORDER BY s.idx DESC";

        $stmt = $this->db->prepare($sql);
        $params = ['%' . $queryText . '%'];
        if ($this->tableExists('Tb_Members')) {
            $params[] = '%' . $queryText . '%';
        }
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function ensureInventoryManagedItem(int $itemIdx): void
    {
        if (!$this->tableExists('Tb_Item')) {
            throw new RuntimeException('Tb_Item table is missing');
        }

        $sql = 'SELECT TOP 1 idx, inventory_management FROM Tb_Item WHERE idx = ?';
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $sql .= ' AND ISNULL(is_deleted,0)=0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$itemIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('품목을 찾을 수 없습니다');
        }

        if ($this->columnExists('Tb_Item', 'inventory_management')) {
            $managed = trim((string)($row['inventory_management'] ?? ''));
            if ($managed !== '유') {
                throw new RuntimeException('재고관리 대상 품목이 아닙니다');
            }
        }
    }

    private function ensureWarehouseBranch(int $branchIdx): void
    {
        if (!$this->tableExists('Tb_Department')) {
            throw new RuntimeException('Tb_Department table is missing');
        }

        $where = ['idx = ?'];
        if ($this->columnExists('Tb_Department', 'type')) {
            $where[] = 'ISNULL(type, 0) = 1';
        }
        if ($this->columnExists('Tb_Department', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted, 0) = 0';
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM Tb_Department WHERE ' . implode(' AND ', $where));
        $stmt->execute([$branchIdx]);
        if ((int)$stmt->fetchColumn() < 1) {
            throw new RuntimeException('유효한 창고(지사)가 아닙니다');
        }
    }

    private function getStockRowForUpdate(int $itemIdx, int $branchIdx): ?array
    {
        $sql = 'SELECT TOP 1 idx, qty FROM Tb_Stock WITH (UPDLOCK, HOLDLOCK) WHERE item_idx = ? AND branch_idx = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$itemIdx, $branchIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function insertStockRow(int $itemIdx, int $branchIdx, int $qty, bool $markIn, bool $markOut): void
    {
        $columns = ['item_idx', 'branch_idx', 'qty'];
        $values = [$itemIdx, $branchIdx, $qty];

        if ($markIn && $this->columnExists('Tb_Stock', 'last_in_date')) {
            $columns[] = 'last_in_date';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($markOut && $this->columnExists('Tb_Stock', 'last_out_date')) {
            $columns[] = 'last_out_date';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($this->columnExists('Tb_Stock', 'updated_at')) {
            $columns[] = 'updated_at';
            $values[] = date('Y-m-d H:i:s');
        }

        $ph = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO Tb_Stock (' . implode(',', $this->quoteColumns($columns)) . ') VALUES (' . $ph . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    private function updateStockQty(int $stockIdx, int $qty, bool $markIn, bool $markOut): void
    {
        $setSql = [$this->qi('qty') . ' = ?'];
        $params = [$qty];

        if ($markIn && $this->columnExists('Tb_Stock', 'last_in_date')) {
            $setSql[] = $this->qi('last_in_date') . ' = GETDATE()';
        }
        if ($markOut && $this->columnExists('Tb_Stock', 'last_out_date')) {
            $setSql[] = $this->qi('last_out_date') . ' = GETDATE()';
        }
        if ($this->columnExists('Tb_Stock', 'updated_at')) {
            $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
        }

        $params[] = $stockIdx;

        $sql = 'UPDATE Tb_Stock SET ' . implode(', ', $setSql) . ' WHERE idx = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function insertStockLog(array $input): void
    {
        $map = $this->stockLogFieldTypeMap();
        $data = $this->pickAndCast($input, $map);

        $columns = [];
        $values = [];

        foreach ($data as $field => $value) {
            if (!$this->columnExists('Tb_StockLog', $field)) {
                continue;
            }
            $columns[] = $field;
            $values[] = $value;
        }

        if ($this->columnExists('Tb_StockLog', 'regdate')) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($this->columnExists('Tb_StockLog', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($columns === []) {
            throw new RuntimeException('Tb_StockLog writable columns are not available');
        }

        $ph = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO Tb_StockLog (' . implode(',', $this->quoteColumns($columns)) . ') VALUES (' . $ph . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    private function stockInFieldTypeMap(): array
    {
        return [
            'item_idx' => 'int',
            'branch_idx' => 'int',
            'qty' => 'int',
            'memo' => 'string',
        ];
    }

    private function stockOutFieldTypeMap(): array
    {
        return [
            'item_idx' => 'int',
            'branch_idx' => 'int',
            'qty' => 'int',
            'site_idx' => 'int_nullable',
            'memo' => 'string',
        ];
    }

    private function stockTransferFieldTypeMap(): array
    {
        return [
            'item_idx' => 'int',
            'from_branch_idx' => 'int',
            'to_branch_idx' => 'int',
            'qty' => 'int',
            'memo' => 'string',
        ];
    }

    private function stockAdjustFieldTypeMap(): array
    {
        return [
            'item_idx' => 'int',
            'branch_idx' => 'int',
            'new_qty' => 'int',
            'memo' => 'string',
        ];
    }

    private function stockLogFieldTypeMap(): array
    {
        return [
            'stock_type' => 'int',
            'item_idx' => 'int',
            'branch_idx' => 'int',
            'qty' => 'int',
            'before_qty' => 'int',
            'after_qty' => 'int',
            'target_branch_idx' => 'int_nullable',
            'site_idx' => 'int_nullable',
            'memo' => 'string',
            'emp_idx' => 'int_nullable',
            'emp_name' => 'string',
        ];
    }

    private function settingsFieldTypeMap(): array
    {
        return [
            'default_branch_idx' => 'int',
            'low_stock_alert' => 'bool01',
            'low_stock_threshold' => 'int',
            'allow_negative_stock' => 'bool01',
        ];
    }

    private function pickAndCast(array $input, array $fieldTypeMap): array
    {
        $picked = [];
        foreach ($fieldTypeMap as $field => $type) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $picked[$field] = $this->castByType($type, $input[$field]);
        }
        return $picked;
    }

    private function castByType(string $type, $value)
    {
        if ($type === 'int') {
            return (int)$value;
        }

        if ($type === 'int_nullable') {
            if ($value === '' || $value === null) {
                return null;
            }
            return (int)$value;
        }

        if ($type === 'bool01') {
            $text = strtolower(trim((string)$value));
            if ($text === '1' || $text === 'true' || $text === 'on' || $text === 'yes') {
                return 1;
            }
            return 0;
        }

        return trim((string)$value);
    }

    /**
     * stock_type 다중 필터 입력을 정규화한다.
     * 지원 형태:
     * - "1,4"
     * - "1 4"
     * - [1, 4]
     */
    private function normalizeStockTypeList($raw): array
    {
        if ($raw === null) {
            return [];
        }

        $tokens = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                $token = trim((string)$value);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        } else {
            $parts = preg_split('/[\s,]+/', trim((string)$raw)) ?: [];
            foreach ($parts as $part) {
                $token = trim((string)$part);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        $list = [];
        foreach ($tokens as $token) {
            if (!preg_match('/^-?\d+$/', $token)) {
                continue;
            }
            $value = (int)$token;
            if ($value > 0) {
                $list[] = $value;
            }
        }

        return array_values(array_unique($list));
    }

    private function logThrowable(string $scope, Throwable $e, array $context = []): void
    {
        $payload = [
            'scope' => $scope,
            'message' => $e->getMessage(),
            'context' => $context,
        ];
        error_log('[StockService] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ensureTables(): void
    {
        $this->ensureStockTable();
        $this->ensureStockLogTable();
        $this->ensureStockSettingTable();
    }

    private function ensureStockTable(): void
    {
        if ($this->tableExists('Tb_Stock')) {
            return;
        }

        $sql = "CREATE TABLE Tb_Stock (
            idx INT IDENTITY(1,1) PRIMARY KEY,
            item_idx INT NOT NULL,
            branch_idx INT NOT NULL,
            qty INT DEFAULT 0,
            safety_qty INT DEFAULT 0,
            last_in_date DATETIME NULL,
            last_out_date DATETIME NULL,
            regdate DATETIME DEFAULT GETDATE(),
            updated_at DATETIME NULL
        )";
        $this->db->exec($sql);
        $this->db->exec("CREATE UNIQUE INDEX UX_Stock_Item_Branch ON Tb_Stock(item_idx, branch_idx)");

        $this->tableExistsCache['tb_stock'] = true;
    }

    private function ensureStockLogTable(): void
    {
        if (!$this->tableExists('Tb_StockLog')) {
            $sql = "CREATE TABLE Tb_StockLog (
                idx INT IDENTITY(1,1) PRIMARY KEY,
                stock_type TINYINT NOT NULL,
                item_idx INT NOT NULL,
                branch_idx INT NOT NULL,
                qty INT NOT NULL,
                before_qty INT DEFAULT 0,
                after_qty INT DEFAULT 0,
                target_branch_idx INT NULL,
                site_idx INT NULL,
                memo NVARCHAR(500) DEFAULT '',
                emp_idx INT NULL,
                emp_name NVARCHAR(50) DEFAULT '',
                regdate DATETIME DEFAULT GETDATE(),
                created_at DATETIME NULL
            )";
            $this->db->exec($sql);
            $this->tableExistsCache['tb_stocklog'] = true;
        }

        $columnSql = [
            'stock_type' => "ALTER TABLE Tb_StockLog ADD stock_type TINYINT NOT NULL DEFAULT 0",
            'item_idx' => "ALTER TABLE Tb_StockLog ADD item_idx INT NOT NULL DEFAULT 0",
            'branch_idx' => "ALTER TABLE Tb_StockLog ADD branch_idx INT NOT NULL DEFAULT 0",
            'qty' => "ALTER TABLE Tb_StockLog ADD qty INT NOT NULL DEFAULT 0",
            'before_qty' => "ALTER TABLE Tb_StockLog ADD before_qty INT NOT NULL DEFAULT 0",
            'after_qty' => "ALTER TABLE Tb_StockLog ADD after_qty INT NOT NULL DEFAULT 0",
            'target_branch_idx' => "ALTER TABLE Tb_StockLog ADD target_branch_idx INT NULL",
            'site_idx' => "ALTER TABLE Tb_StockLog ADD site_idx INT NULL",
            'memo' => "ALTER TABLE Tb_StockLog ADD memo NVARCHAR(500) NOT NULL DEFAULT ''",
            'emp_idx' => "ALTER TABLE Tb_StockLog ADD emp_idx INT NULL",
            'emp_name' => "ALTER TABLE Tb_StockLog ADD emp_name NVARCHAR(50) NOT NULL DEFAULT ''",
            'regdate' => "ALTER TABLE Tb_StockLog ADD regdate DATETIME NOT NULL DEFAULT GETDATE()",
            'created_at' => "ALTER TABLE Tb_StockLog ADD created_at DATETIME NULL",
        ];

        foreach ($columnSql as $column => $sql) {
            if ($this->columnExists('Tb_StockLog', $column)) {
                continue;
            }

            $this->db->exec($sql);
            $this->columnExistsCache[strtolower('Tb_StockLog.' . $column)] = true;
        }
    }

    private function ensureStockSettingTable(): void
    {
        if (!$this->tableExists('Tb_StockSetting')) {
            $sql = "CREATE TABLE Tb_StockSetting (
                idx INT IDENTITY(1,1) PRIMARY KEY,
                setting_key NVARCHAR(100) NOT NULL,
                setting_value NVARCHAR(500) DEFAULT '',
                updated_by INT DEFAULT 0,
                regdate DATETIME DEFAULT GETDATE(),
                updated_at DATETIME NULL
            )";
            $this->db->exec($sql);
            $this->tableExistsCache['tb_stocksetting'] = true;
        }

        $defaults = [
            'default_branch_idx' => '0',
            'low_stock_alert' => '1',
            'low_stock_threshold' => '10',
            'allow_negative_stock' => '0',
        ];

        foreach ($defaults as $key => $value) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM Tb_StockSetting WHERE setting_key = ?');
            $stmt->execute([$key]);
            if ((int)$stmt->fetchColumn() > 0) {
                continue;
            }
            $ins = $this->db->prepare('INSERT INTO Tb_StockSetting (setting_key, setting_value) VALUES (?, ?)');
            $ins->execute([$key, $value]);
        }
    }

    private function quoteColumns(array $columns): array
    {
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = $this->qi($column);
        }
        return $quoted;
    }

    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function tableExists(string $tableName): bool
    {
        $key = strtolower($tableName);
        if (array_key_exists($key, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$key];
        }

        $stmt = $this->db->prepare('SELECT CASE WHEN OBJECT_ID(:obj, :type) IS NULL THEN 0 ELSE 1 END AS exists_flag');
        $stmt->execute([
            'obj' => 'dbo.' . $tableName,
            'type' => 'U',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $exists = ((int)($row['exists_flag'] ?? 0)) === 1;
        $this->tableExistsCache[$key] = $exists;

        return $exists;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $key = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($key, $this->columnExistsCache)) {
            return (bool)$this->columnExistsCache[$key];
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$tableName, $columnName]);

        $exists = (int)$stmt->fetchColumn() > 0;
        $this->columnExistsCache[$key] = $exists;

        return $exists;
    }
}
