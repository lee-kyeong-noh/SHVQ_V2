<?php
declare(strict_types=1);

final class MaterialService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private array $columnNullableCache = [];
    private ?array $companyLookupCache = null;
    private ?bool $v1ItemTableExistsCache = null;
    private array $v1ColumnExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function list(array $query): array
    {
        if (!$this->tableExists('Tb_Item')) {
            return [
                'data' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 1,
                'limit' => 50,
            ];
        }

        $search = trim((string)($query['search'] ?? ''));
        $tabIdx = (int)($query['tab_idx'] ?? 0);
        $categoryIdx = (int)($query['category_idx'] ?? $query['cat_idx'] ?? 0);
        $includeSubtree = ((int)($query['include_subtree'] ?? 0)) === 1;
        $hasParentFilter = array_key_exists('parent_idx', $query) || array_key_exists('parent_item_idx', $query);
        $parentIdx = (int)($query['parent_idx'] ?? $query['parent_item_idx'] ?? 0);
        $parentCol = $this->columnExists('Tb_Item', 'parent_idx')
            ? 'parent_idx'
            : ($this->columnExists('Tb_Item', 'parent_item_idx') ? 'parent_item_idx' : null);
        $materialPattern = trim((string)($query['material_pattern'] ?? $query['materialPattern'] ?? ''));
        $page = max(1, (int)($query['p'] ?? 1));
        $limit = min(500, max(1, (int)($query['limit'] ?? 50)));
        $includeDeleted = ((int)($query['include_deleted'] ?? 0)) === 1;

        $where = ['1=1'];
        $params = [];

        if (!$includeDeleted && $this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        if ($search !== '') {
            $searchCols = [];
            foreach (['name', 'standard', 'item_code'] as $column) {
                if (!$this->columnExists('Tb_Item', $column)) {
                    continue;
                }
                $searchCols[] = 'ISNULL(i.' . $this->qi($column) . ", '') LIKE ?";
            }

            if ($searchCols !== []) {
                $where[] = '(' . implode(' OR ', $searchCols) . ')';
                $sp = '%' . $search . '%';
                for ($i = 0; $i < count($searchCols); $i++) {
                    $params[] = $sp;
                }
            }
        }

        if ($tabIdx > 0 && $this->columnExists('Tb_Item', 'tab_idx')) {
            $where[] = 'ISNULL(i.' . $this->qi('tab_idx') . ', 0) = ?';
            $params[] = $tabIdx;
        }

        if ($categoryIdx > 0 && $this->columnExists('Tb_Item', 'category_idx')) {
            if ($includeSubtree) {
                $categoryIds = $this->categorySubtreeIds($categoryIdx);
                if ($categoryIds === []) {
                    $categoryIds = [$categoryIdx];
                }
                $ph = implode(', ', array_fill(0, count($categoryIds), '?'));
                $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) IN (' . $ph . ')';
                foreach ($categoryIds as $catId) {
                    $params[] = $catId;
                }
            } else {
                $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) = ?';
                $params[] = $categoryIdx;
            }
        }
        if ($hasParentFilter && $parentCol !== null) {
            $where[] = 'ISNULL(i.' . $this->qi($parentCol) . ', 0) = ?';
            $params[] = $parentIdx;
        }

        if ($materialPattern !== '' && $this->columnExists('Tb_Item', 'material_pattern')) {
            $where[] = 'i.' . $this->qi('material_pattern') . ' = ?';
            $params[] = $materialPattern;
        }

        $attributeFilter = trim((string)($query['attribute'] ?? ''));
        if ($attributeFilter !== '' && $this->columnExists('Tb_Item', 'attribute')) {
            $where[] = "ISNULL(i." . $this->qi('attribute') . ", '') LIKE ?";
            $params[] = '%' . $attributeFilter . '%';
        }

        $priceMin = isset($query['price_min']) && is_numeric($query['price_min']) ? (float)$query['price_min'] : null;
        $priceMax = isset($query['price_max']) && is_numeric($query['price_max']) ? (float)$query['price_max'] : null;
        if ($priceMin !== null && $this->columnExists('Tb_Item', 'sale_price')) {
            $where[] = "ISNULL(i." . $this->qi('sale_price') . ", 0) >= ?";
            $params[] = $priceMin;
        }
        if ($priceMax !== null && $this->columnExists('Tb_Item', 'sale_price')) {
            $where[] = "ISNULL(i." . $this->qi('sale_price') . ", 0) <= ?";
            $params[] = $priceMax;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM Tb_Item i {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $joinTab = $this->tableExists('Tb_ItemTab')
            && $this->columnExists('Tb_Item', 'tab_idx')
            && $this->columnExists('Tb_ItemTab', 'idx')
            ? 'LEFT JOIN Tb_ItemTab t ON i.' . $this->qi('tab_idx') . ' = t.' . $this->qi('idx')
            : '';
        $joinCategory = $this->tableExists('Tb_ItemCategory')
            && $this->columnExists('Tb_Item', 'category_idx')
            && $this->columnExists('Tb_ItemCategory', 'idx')
            ? 'LEFT JOIN Tb_ItemCategory c ON i.' . $this->qi('category_idx') . ' = c.' . $this->qi('idx')
            : '';
        $joinCompany = $this->companyJoinSql('i', 'co');

        $tabNameExpr = $this->joinedNameExpr('Tb_ItemTab', 't', 120);
        $categoryNameExpr = $this->joinedNameExpr('Tb_ItemCategory', 'c', 120);
        $companyNameExpr = $this->companyNameExpr('i', 'co', 255);
        $parentExpr = $parentCol !== null ? 'ISNULL(i.' . $this->qi($parentCol) . ', 0)' : '0';

        $orderParts = [];
        if ($this->columnExists('Tb_Item', 'name')) {
            $orderParts[] = 'i.' . $this->qi('name') . ' ASC';
        }
        $orderParts[] = 'i.' . $this->qi('idx') . ' DESC';

        $listSql = "SELECT * FROM (
            SELECT
                i." . $this->qi('idx') . " AS idx,
                " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                " . $this->itemStringExpr('name', 255, '') . " AS name,
                " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                " . $this->itemStringExpr('unit', 60, '') . " AS unit,
                " . $this->itemIntExpr('tab_idx', 0) . " AS tab_idx,
                " . $this->itemIntExpr('category_idx', 0) . " AS category_idx,
                " . $this->itemStringExpr('inventory_management', 10, '무') . " AS inventory_management,
                " . $this->itemStringExpr('material_pattern', 20, '') . " AS material_pattern,
                " . $this->itemFloatExpr('cost', 0) . " AS cost,
                {$companyNameExpr} AS company_name,
                " . $this->itemStringExpr('attribute', 60, '') . " AS attribute,
                " . $this->itemFloatExpr('safety_count', 0) . " AS safety_count,
                " . $this->itemFloatExpr('base_count', 0) . " AS base_count,
                " . $this->itemFloatExpr('sale_price', 0) . " AS sale_price,
                " . $this->itemImageAliasExpr('banner_img', 'upload_files_banner', 500) . " AS banner_img,
                " . $this->itemStringExpr('upload_files_banner', 500, '') . " AS upload_files_banner,
                " . $this->itemStringExpr('upload_files_detail', 500, '') . " AS upload_files_detail,
                " . $this->itemFloatExpr('qty', 0) . " AS qty,
                " . $this->itemIntExpr('origin_idx', 0) . " AS origin_idx,
                " . $this->itemIntExpr('legacy_idx', 0) . " AS legacy_idx,
                " . $this->itemIntExpr('is_legacy_copy', 0) . " AS is_legacy_copy,
                " . $this->itemIntExpr('legacy_copied', 0) . " AS legacy_copied,
                " . $this->itemIntExpr('is_migrated', 0) . " AS is_migrated,
                {$parentExpr} AS parent_idx,
                {$tabNameExpr} AS tab_name,
                {$categoryNameExpr} AS category_name,
                " . ($this->tableExists('Tb_ItemComponent') ? "(SELECT COUNT(*) FROM Tb_ItemComponent ic WHERE ic.[parent_item_idx]=i.[idx] AND ISNULL(ic.[is_deleted],0)=0)" : "0") . " AS component_count,
                " . ($this->tableExists('Tb_ItemComponent') ? "(SELECT COUNT(*) FROM Tb_ItemComponent ic WHERE ic.[parent_item_idx]=i.[idx] AND ISNULL(ic.[is_deleted],0)=0)" : "0") . " AS child_count,
                ROW_NUMBER() OVER (ORDER BY " . implode(', ', $orderParts) . ") AS rn
            FROM Tb_Item i
            {$joinTab}
            {$joinCategory}
            {$joinCompany}
            {$whereSql}
        ) x WHERE x.rn BETWEEN ? AND ?";

        $stmt = $this->db->prepare($listSql);
        $stmt->execute(array_merge($params, [$rowFrom, $rowTo]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $this->normalizeListRows($rows) : [];

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ];
    }

    public function detail(int $idx): ?array
    {
        if ($idx <= 0 || !$this->tableExists('Tb_Item')) {
            return null;
        }

        $joinTab = $this->tableExists('Tb_ItemTab')
            && $this->columnExists('Tb_Item', 'tab_idx')
            && $this->columnExists('Tb_ItemTab', 'idx')
            ? 'LEFT JOIN Tb_ItemTab t ON i.' . $this->qi('tab_idx') . ' = t.' . $this->qi('idx')
            : '';
        $joinCategory = $this->tableExists('Tb_ItemCategory')
            && $this->columnExists('Tb_Item', 'category_idx')
            && $this->columnExists('Tb_ItemCategory', 'idx')
            ? 'LEFT JOIN Tb_ItemCategory c ON i.' . $this->qi('category_idx') . ' = c.' . $this->qi('idx')
            : '';
        $joinCompany = $this->companyJoinSql('i', 'co');
        $companyNameExpr = $this->companyNameExpr('i', 'co', 255);

        $where = ['i.' . $this->qi('idx') . ' = ?'];
        $params = [$idx];
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $sql = "SELECT TOP 1
                    i." . $this->qi('idx') . " AS idx,
                    " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                    " . $this->itemStringExpr('name', 255, '') . " AS name,
                    " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                    " . $this->itemStringExpr('unit', 60, '') . " AS unit,
                    " . $this->itemStringExpr('inventory_management', 10, '무') . " AS inventory_management,
                    " . $this->itemStringExpr('material_pattern', 20, '') . " AS material_pattern,
                    " . $this->itemStringExpr('barcode', 120, '') . " AS barcode,
                    " . $this->itemStringExpr('attribute', 60, '') . " AS attribute,
                    " . $this->itemStringExpr('hidden', 5, '0') . " AS hidden,
                    " . $this->itemStringExpr('relative_purchase', 5, 'O') . " AS relative_purchase,
                    " . $this->itemIntExpr('tab_idx', 0) . " AS tab_idx,
                    " . $this->itemIntExpr('category_idx', 0) . " AS category_idx,
                    " . $this->itemFloatExpr('safety_count', 0) . " AS safety_count,
                    " . $this->itemFloatExpr('base_count', 0) . " AS base_count,
                    " . $this->itemFloatExpr('sale_price', 0) . " AS sale_price,
                    " . $this->itemFloatExpr('cost', 0) . " AS cost,
                    " . $this->itemIntExpr('company_idx', 0) . " AS company_idx,
                    {$companyNameExpr} AS company_name,
                    " . $this->itemFloatExpr('supply_price', 0) . " AS supply_price,
                    " . $this->itemFloatExpr('purchase_price', 0) . " AS purchase_price,
                    " . $this->itemFloatExpr('work_price', 0) . " AS work_price,
                    " . $this->itemFloatExpr('tax_price', 0) . " AS tax_price,
                    " . $this->itemFloatExpr('safety', 0) . " AS safety,
                    " . $this->itemFloatExpr('qty', 0) . " AS qty,
                    " . $this->itemStringExpr('contents', 4000, '') . " AS contents,
                    " . $this->itemStringExpr('memo', 2000, '') . " AS memo,
                    " . $this->itemStringExpr('is_split', 5, '') . " AS is_split,
                    " . $this->itemStringExpr('follow_mode', 5, '') . " AS follow_mode,
                    " . $this->itemImageAliasExpr('banner_img', 'upload_files_banner', 500) . " AS banner_img,
                    " . $this->itemImageAliasExpr('detail_img', 'upload_files_detail', 500) . " AS detail_img,
                    " . $this->itemStringExpr('upload_files_banner', 500, '') . " AS upload_files_banner,
                    " . $this->itemStringExpr('upload_files_detail', 500, '') . " AS upload_files_detail,
                    " . $this->itemTextExpr('created_at', 30, '') . " AS created_at,
                    " . $this->itemTextExpr('updated_at', 30, '') . " AS updated_at,
                    " . $this->itemTextExpr('regdate', 30, '') . " AS regdate,
                    " . $this->itemTextExpr('created_by', 60, '') . " AS created_by,
                    " . $this->itemTextExpr('updated_by', 60, '') . " AS updated_by,
                    " . $this->itemIntExpr('origin_idx', 0) . " AS origin_idx,
                    " . $this->itemIntExpr('legacy_idx', 0) . " AS legacy_idx,
                    " . $this->itemIntExpr('is_legacy_copy', 0) . " AS is_legacy_copy,
                    " . $this->itemIntExpr('legacy_copied', 0) . " AS legacy_copied,
                    " . $this->itemIntExpr('is_migrated', 0) . " AS is_migrated,
                    " . $this->joinedNameExpr('Tb_ItemTab', 't', 120) . " AS tab_name,
                    " . $this->joinedNameExpr('Tb_ItemCategory', 'c', 120) . " AS category_name
                FROM Tb_Item i
                {$joinTab}
                {$joinCategory}
                {$joinCompany}
                WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $input, int $actorUserPk = 0): array
    {
        $table = $this->itemTable();
        $payload = $this->normalizePayload($input);

        if ($this->columnExists($table, 'name') && trim((string)($payload['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }

        $fieldTypes = $this->fieldTypeMap();
        $columns = [];
        $values = [];

        foreach ($fieldTypes as $field => $type) {
            if ($field === 'idx' || !$this->columnExists($table, $field)) {
                continue;
            }
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $columns[] = $field;
            $values[] = $this->castByType($type, $payload[$field]);
        }

        if ($this->columnExists($table, 'inventory_management') && !in_array('inventory_management', $columns, true)) {
            $columns[] = 'inventory_management';
            $values[] = '무';
        }

        if ($this->columnExists($table, 'is_deleted') && !in_array('is_deleted', $columns, true)) {
            $columns[] = 'is_deleted';
            $values[] = 0;
        }

        if ($this->columnExists($table, 'regdate') && !in_array('regdate', $columns, true)) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($this->columnExists($table, 'created_at') && !in_array('created_at', $columns, true)) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($this->columnExists($table, 'created_by') && !in_array('created_by', $columns, true) && $actorUserPk > 0) {
            $columns[] = 'created_by';
            $values[] = max(0, $actorUserPk);
        }

        if ($columns === []) {
            throw new InvalidArgumentException('no writable field provided');
        }

        try {
            $sql = 'INSERT INTO ' . $table
                . ' (' . implode(',', $this->quoteColumns($columns)) . ')'
                . ' OUTPUT INSERTED.' . $this->qi('idx') . ' AS new_idx'
                . ' VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);

            $newIdxRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $newIdx = (int)($newIdxRow['new_idx'] ?? 0);
            if ($newIdx <= 0) {
                $newIdx = $this->lastInsertId();
            }
            $rowData = $newIdx > 0 ? ($this->detail($newIdx) ?? []) : [];
            if ($newIdx > 0) {
                $this->logItemHistory(
                    $newIdx,
                    'create',
                    null,
                    null,
                    $rowData,
                    $rowData,
                    $actorUserPk
                );
            }
            return [
                'idx' => $newIdx,
                'created_count' => $newIdx > 0 ? 1 : (int)$stmt->rowCount(),
                'row' => $rowData,
            ];
        } catch (Throwable $e) {
            $this->logThrowable('create', $e, ['actor_user_pk' => $actorUserPk]);
            throw $e;
        }
    }

    public function update(int $idx, array $input, int $actorUserPk = 0): array
    {
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        $table = $this->itemTable();
        $payload = $this->normalizePayload($input);
        $fieldTypes = $this->fieldTypeMap();
        $beforeRow = $this->detail($idx);

        $setSql = [];
        $params = [];
        $changed = [];

        foreach ($fieldTypes as $field => $type) {
            if ($field === 'idx') {
                continue;
            }
            if (!array_key_exists($field, $payload) || !$this->columnExists($table, $field)) {
                continue;
            }

            if ($field === 'name' && trim((string)$payload[$field]) === '') {
                throw new InvalidArgumentException('name cannot be empty');
            }

            $setSql[] = $this->qi($field) . ' = ?';
            $params[] = $this->castByType($type, $payload[$field]);
            $changed[] = $field;
        }

        if ($setSql === []) {
            throw new InvalidArgumentException('no updatable field provided');
        }

        if ($this->columnExists($table, 'updated_by')) {
            $setSql[] = $this->qi('updated_by') . ' = ?';
            $params[] = max(0, $actorUserPk);
        }

        if ($this->columnExists($table, 'updated_at')) {
            $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
        }

        $params[] = $idx;
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(is_deleted, 0) = 0';
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $afterRow = $this->detail($idx) ?? [];
            $changedBefore = [];
            $changedAfter = [];
            foreach ($changed as $field) {
                $changedBefore[$field] = $beforeRow[$field] ?? null;
                $changedAfter[$field] = $afterRow[$field] ?? null;
            }
            $this->logItemHistory(
                $idx,
                'update',
                $changed === [] ? null : implode(',', $changed),
                $changedBefore,
                $changedAfter,
                is_array($afterRow) ? $afterRow : [],
                $actorUserPk
            );
            return [
                'idx' => $idx,
                'updated_count' => (int)$stmt->rowCount(),
                'changed_fields' => $changed,
                'row' => is_array($afterRow) ? $afterRow : [],
            ];
        } catch (Throwable $e) {
            $this->logThrowable('update', $e, [
                'idx' => $idx,
                'actor_user_pk' => $actorUserPk,
                'changed_fields' => $changed,
            ]);
            throw $e;
        }
    }

    public function deleteByIds(array $idxList, int $deletedBy = 0): array
    {
        if ($idxList === []) {
            throw new InvalidArgumentException('idx_list is required');
        }

        $table = $this->itemTable();
        $placeholders = implode(',', array_fill(0, count($idxList), '?'));
        $beforeRows = [];
        foreach ($idxList as $itemIdx) {
            $before = $this->detail((int)$itemIdx);
            if (is_array($before) && $before !== []) {
                $beforeRows[(int)$itemIdx] = $before;
            }
        }

        try {
            if ($this->columnExists($table, 'is_deleted')) {
                $setSql = ['is_deleted = 1'];
                $params = [];

                if ($this->columnExists($table, 'deleted_at')) {
                    $setSql[] = 'deleted_at = GETDATE()';
                }
                if ($this->columnExists($table, 'deleted_by')) {
                    $setSql[] = 'deleted_by = ?';
                    $params[] = max(0, $deletedBy);
                }
                if ($this->columnExists($table, 'updated_by')) {
                    $setSql[] = 'updated_by = ?';
                    $params[] = max(0, $deletedBy);
                }
                if ($this->columnExists($table, 'updated_at')) {
                    $setSql[] = 'updated_at = GETDATE()';
                }

                $params = array_merge($params, $idxList);
                $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . " WHERE idx IN ({$placeholders})";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                foreach ($beforeRows as $itemIdx => $snapshot) {
                    $this->logItemHistory(
                        (int)$itemIdx,
                        'delete',
                        null,
                        $snapshot,
                        null,
                        $snapshot,
                        $deletedBy
                    );
                }

                return [
                    'idx_list' => $idxList,
                    'deleted_count' => (int)$stmt->rowCount(),
                    'delete_mode' => 'soft',
                ];
            }

            $stmt = $this->db->prepare('DELETE FROM ' . $table . " WHERE idx IN ({$placeholders})");
            $stmt->execute($idxList);
            foreach ($beforeRows as $itemIdx => $snapshot) {
                $this->logItemHistory(
                    (int)$itemIdx,
                    'delete',
                    null,
                    $snapshot,
                    null,
                    $snapshot,
                    $deletedBy
                );
            }

            return [
                'idx_list' => $idxList,
                'deleted_count' => (int)$stmt->rowCount(),
                'delete_mode' => 'hard',
            ];
        } catch (Throwable $e) {
            $this->logThrowable('deleteByIds', $e, [
                'idx_count' => count($idxList),
                'deleted_by' => $deletedBy,
            ]);
            throw $e;
        }
    }

    public function normalizeIdxList($idxInput, int $singleIdx = 0): array
    {
        $tokens = [];

        if (is_array($idxInput)) {
            foreach ($idxInput as $value) {
                $token = trim((string)$value);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        } else {
            $raw = trim((string)$idxInput);
            if ($raw !== '' && $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $value) {
                        $token = trim((string)$value);
                        if ($token !== '') {
                            $tokens[] = $token;
                        }
                    }
                }
            }

            $parsed = preg_split('/[\s,]+/', $raw) ?: [];
            foreach ($parsed as $value) {
                $token = trim((string)$value);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        if ($tokens === [] && $singleIdx > 0) {
            $tokens[] = (string)$singleIdx;
        }

        $idxList = [];
        foreach ($tokens as $token) {
            if (!ctype_digit($token)) {
                continue;
            }
            $idx = (int)$token;
            if ($idx > 0) {
                $idxList[] = $idx;
            }
        }

        return array_values(array_unique($idxList));
    }

    public function search(string $q, int $limit = 20): array
    {
        if (!$this->tableExists('Tb_Item')) {
            return [];
        }

        $keyword = trim($q);
        if ($keyword === '') {
            return [];
        }

        $limit = min(50, max(1, $limit));
        $whereCols = [];
        foreach (['name', 'item_code', 'standard'] as $column) {
            if ($this->columnExists('Tb_Item', $column)) {
                $whereCols[] = 'ISNULL(i.' . $this->qi($column) . ", N'') LIKE ?";
            }
        }

        if ($whereCols === []) {
            return [];
        }

        $whereSql = '(' . implode(' OR ', $whereCols) . ')';
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $whereSql .= ' AND ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $sql = "SELECT TOP {$limit}
                    i." . $this->qi('idx') . " AS idx,
                    " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                    " . $this->itemStringExpr('name', 255, '') . " AS name,
                    " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                    " . $this->itemStringExpr('unit', 60, '') . " AS unit
                FROM Tb_Item i
                WHERE {$whereSql}
                ORDER BY " . ($this->columnExists('Tb_Item', 'name') ? 'i.' . $this->qi('name') . ' ASC' : 'i.' . $this->qi('idx') . ' DESC');

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_fill(0, count($whereCols), '%' . $keyword . '%'));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function companyList(string $q = '', int $limit = 20): array
    {
        $lookup = $this->resolveCompanyLookup();
        if ($lookup === []) {
            return [
                'data' => [],
                'source_table' => '',
                'id_column' => '',
                'name_column' => '',
            ];
        }

        $table = (string)$lookup['table'];
        $idColumn = (string)$lookup['id_column'];
        $nameColumn = (string)$lookup['name_column'];
        $limit = min(100, max(1, $limit));
        $keyword = trim($q);
        $where = ['1=1'];
        $params = [];

        if ($keyword !== '') {
            $where[] = 'ISNULL(CONVERT(NVARCHAR(255), c.' . $this->qi($nameColumn) . "), N'') LIKE ?";
            $params[] = '%' . $keyword . '%';
        }

        if ($this->columnExists($table, 'is_deleted')) {
            $where[] = 'ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $sql = "SELECT TOP {$limit}
                    ISNULL(c." . $this->qi($idColumn) . ", 0) AS idx,
                    ISNULL(CONVERT(NVARCHAR(255), c." . $this->qi($nameColumn) . "), N'') AS company_name
                FROM " . $this->qi($table) . " c
                WHERE " . implode(' AND ', $where) . "
                ORDER BY company_name ASC, idx ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => is_array($rows) ? $rows : [],
            'source_table' => $table,
            'id_column' => $idColumn,
            'name_column' => $nameColumn,
        ];
    }

    public function v1LegacyList(array $query): array
    {
        $page = max(1, (int)($query['p'] ?? 1));
        $limit = min(200, max(1, (int)($query['limit'] ?? 50)));
        $search = trim((string)($query['search'] ?? ''));
        $isMigrated = strtoupper(trim((string)($query['is_migrated'] ?? '')));

        if (!$this->v1ItemTableExists()) {
            return [
                'data' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
            ];
        }

        $where = ['1=1'];
        $params = [];

        if ($this->v1ItemColumnExists('is_deleted')) {
            $where[] = 'ISNULL(v1.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        if ($search !== '') {
            $searchCols = ['CONVERT(NVARCHAR(30), v1.' . $this->qi('idx') . ') LIKE ?'];
            foreach (['item_code', 'name', 'standard'] as $column) {
                if ($this->v1ItemColumnExists($column)) {
                    $searchCols[] = 'ISNULL(CONVERT(NVARCHAR(255), v1.' . $this->qi($column) . "), N'') LIKE ?";
                }
            }
            $where[] = '(' . implode(' OR ', $searchCols) . ')';
            $like = '%' . $search . '%';
            for ($i = 0; $i < count($searchCols); $i++) {
                $params[] = $like;
            }
        }

        $migratedExpr = $this->v2LegacyExistsExpr('v1.' . $this->qi('idx'));
        if ($isMigrated === 'O') {
            $where[] = $migratedExpr . ' = 1';
        } elseif ($isMigrated === 'X') {
            $where[] = $migratedExpr . ' = 0';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $v1Table = $this->v1ItemTableSql();

        $countSql = 'SELECT COUNT(*) FROM ' . $v1Table . ' v1 ' . $whereSql;
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $sql = "SELECT * FROM (
                    SELECT
                        ISNULL(v1." . $this->qi('idx') . ", 0) AS idx,
                        " . $this->v1StringExpr('v1', 'item_code', 120, '') . " AS item_code,
                        " . $this->v1StringExpr('v1', 'name', 255, '') . " AS name,
                        " . $this->v1StringExpr('v1', 'standard', 255, '') . " AS standard,
                        " . $this->v1StringExpr('v1', 'unit', 60, '') . " AS unit,
                        " . $this->v1IntExpr('v1', 'tab_idx', 0) . " AS tab_idx,
                        " . $this->v1IntExpr('v1', 'category_idx', 0) . " AS category_idx,
                        " . $this->v1StringExpr('v1', 'material_pattern', 20, '') . " AS material_pattern,
                        " . $this->v1StringExpr('v1', 'attribute', 60, '') . " AS attribute,
                        " . $this->v1FloatExpr('v1', 'sale_price', 0) . " AS sale_price,
                        " . $this->v1FloatExpr('v1', 'cost', 0) . " AS cost,
                        " . $this->v1FloatExpr('v1', 'qty', 0) . " AS qty,
                        {$migratedExpr} AS is_migrated,
                        ROW_NUMBER() OVER (ORDER BY v1." . $this->qi('idx') . " DESC) AS rn
                    FROM {$v1Table} v1
                    {$whereSql}
                ) z
                WHERE z.rn BETWEEN ? AND ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, [$rowFrom, $rowTo]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ];
    }

    public function copyFromV1(array $idxList, ?int $tabIdx = null, ?int $categoryIdx = null, int $actorUserPk = 0): array
    {
        $idxList = array_values(array_unique(array_map('intval', $idxList)));
        $idxList = array_values(array_filter($idxList, static fn (int $v): bool => $v > 0));
        if ($idxList === []) {
            throw new InvalidArgumentException('idx_list is required');
        }
        if (!$this->tableExists('Tb_Item')) {
            throw new RuntimeException('Tb_Item 테이블이 없습니다');
        }
        if (!$this->v1ItemTableExists()) {
            throw new RuntimeException('V1 Tb_Item 테이블에 접근할 수 없습니다');
        }

        $requested = count($idxList);
        $legacyRows = $this->loadV1ItemsByIdx($idxList);

        $missingIdxList = [];
        foreach ($idxList as $legacyIdx) {
            if (!array_key_exists($legacyIdx, $legacyRows)) {
                $missingIdxList[] = $legacyIdx;
            }
        }
        $availableLegacyIdx = array_values(array_diff($idxList, $missingIdxList));
        $duplicatedLegacyIdx = $this->loadExistingLegacyIdx($availableLegacyIdx);
        $duplicatedSet = array_fill_keys($duplicatedLegacyIdx, true);

        $copiedLegacyIdxList = [];
        $createdIdxList = [];
        $failedLegacyIdxList = [];

        $startedTx = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTx = true;
            }

            foreach ($idxList as $legacyIdx) {
                if (in_array($legacyIdx, $missingIdxList, true)) {
                    continue;
                }
                if (isset($duplicatedSet[$legacyIdx])) {
                    continue;
                }

                $legacyRow = $legacyRows[$legacyIdx] ?? null;
                if (!is_array($legacyRow) || $legacyRow === []) {
                    $failedLegacyIdxList[] = $legacyIdx;
                    continue;
                }

                try {
                    $payload = $this->buildCopyPayloadFromV1($legacyRow, $tabIdx, $categoryIdx);
                    $created = $this->create($payload, $actorUserPk);
                    $newIdx = (int)($created['idx'] ?? 0);
                    if ($newIdx > 0) {
                        $copiedLegacyIdxList[] = $legacyIdx;
                        $createdIdxList[] = $newIdx;
                    } else {
                        $failedLegacyIdxList[] = $legacyIdx;
                    }
                } catch (Throwable $e) {
                    $failedLegacyIdxList[] = $legacyIdx;
                    $this->logThrowable('copyFromV1.item', $e, ['legacy_idx' => $legacyIdx]);
                }
            }

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('copyFromV1', $e, ['requested' => $requested]);
            throw $e;
        }

        $skippedIdxList = array_values(array_unique(array_merge(
            $duplicatedLegacyIdx,
            $missingIdxList,
            $failedLegacyIdxList
        )));
        sort($skippedIdxList);

        return [
            'requested' => $requested,
            'copied' => count($copiedLegacyIdxList),
            'skipped' => count($skippedIdxList),
            'copied_idx_list' => $copiedLegacyIdxList,
            'created_idx_list' => $createdIdxList,
            'skipped_idx_list' => $skippedIdxList,
            'missing_idx_list' => $missingIdxList,
            'duplicate_idx_list' => $duplicatedLegacyIdx,
            'failed_idx_list' => $failedLegacyIdxList,
        ];
    }

    public function moveCategoryParent(int $catIdx, int $newParentIdx, int $actorUserPk = 0): array
    {
        if ($catIdx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        $table = $this->materialCategoryTable();
        if (!$this->columnExists($table, 'parent_idx')) {
            throw new RuntimeException($table . ' parent_idx 컬럼이 없습니다');
        }

        $newParentIdx = max(0, $newParentIdx);
        if ($catIdx === $newParentIdx) {
            throw new RuntimeException('자기 자신을 부모로 지정할 수 없습니다');
        }

        $current = $this->materialCategoryRow($table, $catIdx);
        if (!is_array($current) || $current === []) {
            throw new RuntimeException('대상 카테고리를 찾을 수 없습니다');
        }

        $oldParentIdx = (int)($current['parent_idx'] ?? 0);
        $oldDepth = $this->columnExists($table, 'depth') ? (int)($current['depth'] ?? 0) : null;

        if ($newParentIdx > 0) {
            $parentRow = $this->materialCategoryRow($table, $newParentIdx);
            if (!is_array($parentRow) || $parentRow === []) {
                throw new RuntimeException('새 부모 카테고리를 찾을 수 없습니다');
            }

            $descendantIds = $this->materialCategoryDescendantIds($table, $catIdx);
            if (in_array($newParentIdx, $descendantIds, true)) {
                throw new RuntimeException('하위 카테고리는 부모로 지정할 수 없습니다');
            }
        }

        if ($oldParentIdx === $newParentIdx) {
            return [
                'idx' => $catIdx,
                'new_parent_idx' => $newParentIdx,
            ];
        }

        $setSql = [$this->qi('parent_idx') . ' = ?'];
        $params = [];
        if ($newParentIdx <= 0 && $this->columnNullable($table, 'parent_idx')) {
            $params[] = null;
        } else {
            $params[] = $newParentIdx;
        }

        $newDepth = null;
        if ($this->columnExists($table, 'depth')) {
            $newDepth = $this->materialCategoryDepthForParent($table, $newParentIdx);
            $setSql[] = $this->qi('depth') . ' = ?';
            $params[] = $newDepth;
        }

        if ($this->columnExists($table, 'updated_by')) {
            $setSql[] = $this->qi('updated_by') . ' = ?';
            $params[] = max(0, $actorUserPk);
        }

        if ($this->columnExists($table, 'updated_at')) {
            $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
        }

        $params[] = $catIdx;
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $this->qi('idx') . ' = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $startedTx = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTx = true;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ($newDepth !== null) {
                $this->recalculateCategorySubtreeDepth($table, $catIdx, (int)$newDepth, $actorUserPk);
            }

            $this->logCategoryMoveHistory(
                $table,
                $catIdx,
                $oldParentIdx,
                $newParentIdx,
                $oldDepth,
                $newDepth,
                $actorUserPk
            );

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('moveCategoryParent', $e, [
                'cat_idx' => $catIdx,
                'new_parent_idx' => $newParentIdx,
            ]);
            throw $e;
        }

        return [
            'idx' => $catIdx,
            'new_parent_idx' => $newParentIdx,
        ];
    }

    public function tabInsert(string $name, int $actorUserPk = 0): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (!$this->tableExists('Tb_ItemTab')) {
            throw new RuntimeException('Tb_ItemTab 테이블이 없습니다');
        }

        $nameColumn = $this->tabNameColumn();
        if ($nameColumn === null) {
            throw new RuntimeException('Tb_ItemTab의 이름 컬럼이 없습니다');
        }

        $columns = [$nameColumn];
        $params = [$name];
        $valuesSql = ['?'];

        if ($this->columnExists('Tb_ItemTab', 'sort_order')) {
            $columns[] = 'sort_order';
            $valuesSql[] = '?';
            $params[] = $this->nextSortOrder('Tb_ItemTab', 'sort_order');
        }

        if ($this->columnExists('Tb_ItemTab', 'is_deleted')) {
            $columns[] = 'is_deleted';
            $valuesSql[] = '0';
        }
        if ($this->columnExists('Tb_ItemTab', 'regdate')) {
            $columns[] = 'regdate';
            $valuesSql[] = 'GETDATE()';
        }
        if ($this->columnExists('Tb_ItemTab', 'created_at')) {
            $columns[] = 'created_at';
            $valuesSql[] = 'GETDATE()';
        }
        if ($this->columnExists('Tb_ItemTab', 'updated_at')) {
            $columns[] = 'updated_at';
            $valuesSql[] = 'GETDATE()';
        }
        if ($this->columnExists('Tb_ItemTab', 'created_by')) {
            $columns[] = 'created_by';
            $valuesSql[] = '?';
            $params[] = max(0, $actorUserPk);
        }
        if ($this->columnExists('Tb_ItemTab', 'updated_by')) {
            $columns[] = 'updated_by';
            $valuesSql[] = '?';
            $params[] = max(0, $actorUserPk);
        }

        $sql = 'INSERT INTO Tb_ItemTab (' . implode(', ', $this->quoteColumns($columns)) . ')'
            . ' OUTPUT INSERTED.' . $this->qi('idx') . ' AS new_idx'
            . ' VALUES (' . implode(', ', $valuesSql) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'idx' => (int)($row['new_idx'] ?? 0),
            'name' => $name,
            'table' => 'Tb_ItemTab',
        ];
    }

    public function tabDelete(int $idx, int $actorUserPk = 0): array
    {
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }
        if (!$this->tableExists('Tb_ItemTab')) {
            throw new RuntimeException('Tb_ItemTab 테이블이 없습니다');
        }

        $itemUpdated = 0;
        if ($this->tableExists('Tb_Item') && $this->columnExists('Tb_Item', 'tab_idx')) {
            $setSql = [];
            $params = [];
            if ($this->columnNullable('Tb_Item', 'tab_idx')) {
                $setSql[] = $this->qi('tab_idx') . ' = NULL';
            } else {
                $setSql[] = $this->qi('tab_idx') . ' = 0';
            }
            if ($this->columnExists('Tb_Item', 'updated_by')) {
                $setSql[] = $this->qi('updated_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_Item', 'updated_at')) {
                $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
            }
            $params[] = $idx;
            $sql = 'UPDATE Tb_Item SET ' . implode(', ', $setSql) . ' WHERE ' . $this->qi('tab_idx') . ' = ?';
            if ($this->columnExists('Tb_Item', 'is_deleted')) {
                $sql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $itemUpdated = (int)$stmt->rowCount();
        }

        $tabDeleted = 0;
        $deleteMode = 'hard';
        if ($this->columnExists('Tb_ItemTab', 'is_deleted')) {
            $setSql = [$this->qi('is_deleted') . ' = 1'];
            $params = [];
            if ($this->columnExists('Tb_ItemTab', 'deleted_by')) {
                $setSql[] = $this->qi('deleted_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_ItemTab', 'deleted_at')) {
                $setSql[] = $this->qi('deleted_at') . ' = GETDATE()';
            }
            if ($this->columnExists('Tb_ItemTab', 'updated_by')) {
                $setSql[] = $this->qi('updated_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_ItemTab', 'updated_at')) {
                $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
            }
            $params[] = $idx;
            $stmt = $this->db->prepare('UPDATE Tb_ItemTab SET ' . implode(', ', $setSql) . ' WHERE ' . $this->qi('idx') . ' = ?');
            $stmt->execute($params);
            $tabDeleted = (int)$stmt->rowCount();
            $deleteMode = 'soft';
        } else {
            $stmt = $this->db->prepare('DELETE FROM Tb_ItemTab WHERE ' . $this->qi('idx') . ' = ?');
            $stmt->execute([$idx]);
            $tabDeleted = (int)$stmt->rowCount();
        }

        return [
            'idx' => $idx,
            'item_tab_cleared_count' => $itemUpdated,
            'deleted_count' => $tabDeleted,
            'delete_mode' => $deleteMode,
        ];
    }

    public function moveItems(array $idxList, ?int $targetTabIdx, ?int $targetCategoryIdx, bool $copy = false, int $actorUserPk = 0): array
    {
        $idxList = array_values(array_unique(array_map('intval', $idxList)));
        $idxList = array_values(array_filter($idxList, static fn (int $v): bool => $v > 0));
        if ($idxList === []) {
            throw new InvalidArgumentException('idx_list is required');
        }
        if ($targetTabIdx === null && $targetCategoryIdx === null) {
            throw new InvalidArgumentException('target_tab_idx or target_cat_idx is required');
        }

        $result = [
            'copy_mode' => $copy ? 1 : 0,
            'requested_count' => count($idxList),
            'processed_count' => 0,
            'moved_count' => 0,
            'copied_count' => 0,
            'failed_idx_list' => [],
            'new_idx_list' => [],
        ];

        $startedTx = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTx = true;
            }

            if ($copy) {
                foreach ($idxList as $sourceIdx) {
                    $sourceRow = $this->detail($sourceIdx);
                    if (!is_array($sourceRow) || $sourceRow === []) {
                        $result['failed_idx_list'][] = $sourceIdx;
                        continue;
                    }

                    $payload = $this->extractWritablePayload($sourceRow);
                    if ($targetTabIdx !== null && $this->columnExists('Tb_Item', 'tab_idx')) {
                        $payload['tab_idx'] = $targetTabIdx;
                    }
                    if ($targetCategoryIdx !== null && $this->columnExists('Tb_Item', 'category_idx')) {
                        $payload['category_idx'] = $targetCategoryIdx;
                    }
                    if (array_key_exists('item_code', $payload) && trim((string)$payload['item_code']) !== '') {
                        $payload['item_code'] = $this->nextCopyItemCode((string)$payload['item_code']);
                    }

                    $created = $this->create($payload, $actorUserPk);
                    $newIdx = (int)($created['idx'] ?? 0);
                    if ($newIdx > 0) {
                        $result['new_idx_list'][] = $newIdx;
                        $result['copied_count']++;
                    } else {
                        $result['failed_idx_list'][] = $sourceIdx;
                    }
                    $result['processed_count']++;
                }
            } else {
                $updateFields = [];
                if ($targetTabIdx !== null && $this->columnExists('Tb_Item', 'tab_idx')) {
                    $updateFields['tab_idx'] = $targetTabIdx;
                }
                if ($targetCategoryIdx !== null && $this->columnExists('Tb_Item', 'category_idx')) {
                    $updateFields['category_idx'] = $targetCategoryIdx;
                }
                if ($updateFields === []) {
                    throw new RuntimeException('이동 가능한 필드(tab_idx/category_idx)가 없습니다');
                }

                foreach ($idxList as $itemIdx) {
                    try {
                        $updated = $this->update($itemIdx, $updateFields, $actorUserPk);
                        if ((int)($updated['updated_count'] ?? 0) > 0) {
                            $result['moved_count']++;
                        }
                        $result['processed_count']++;
                    } catch (Throwable $e) {
                        $result['failed_idx_list'][] = $itemIdx;
                    }
                }
            }

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logThrowable('moveItems', $e, [
                'idx_count' => count($idxList),
                'copy' => $copy ? 1 : 0,
                'target_tab_idx' => $targetTabIdx,
                'target_category_idx' => $targetCategoryIdx,
            ]);
            throw $e;
        }
    }

    public function fillItemCodes(int $tabIdx = 0, int $categoryIdx = 0, string $prefix = 'MAT', int $actorUserPk = 0): array
    {
        $table = $this->itemTable();
        if (!$this->columnExists($table, 'item_code')) {
            return [
                'updated_count' => 0,
                'updated_rows' => [],
                'prefix' => $this->normalizeItemCodePrefix($prefix),
            ];
        }

        $prefix = $this->normalizeItemCodePrefix($prefix);
        $where = ["ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(120), i." . $this->qi('item_code') . "))), N'') = N''"];
        $params = [];
        if ($this->columnExists($table, 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }
        if ($tabIdx > 0 && $this->columnExists($table, 'tab_idx')) {
            $where[] = 'ISNULL(i.' . $this->qi('tab_idx') . ', 0) = ?';
            $params[] = $tabIdx;
        }
        if ($categoryIdx > 0 && $this->columnExists($table, 'category_idx')) {
            $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) = ?';
            $params[] = $categoryIdx;
        }

        $stmt = $this->db->prepare('SELECT i.' . $this->qi('idx') . ' AS idx FROM ' . $table . ' i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.' . $this->qi('idx') . ' ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $updatedRows = [];
        foreach ($rows as $row) {
            $itemIdx = (int)($row['idx'] ?? 0);
            if ($itemIdx <= 0) {
                continue;
            }
            $seed = $prefix . '-' . str_pad((string)$itemIdx, 6, '0', STR_PAD_LEFT);
            $newCode = $this->nextAvailableItemCode($seed, $itemIdx);
            $updated = $this->update($itemIdx, ['item_code' => $newCode], $actorUserPk);
            if ((int)($updated['updated_count'] ?? 0) > 0) {
                $updatedRows[] = [
                    'idx' => $itemIdx,
                    'item_code' => $newCode,
                ];
            }
        }

        return [
            'updated_count' => count($updatedRows),
            'updated_rows' => $updatedRows,
            'prefix' => $prefix,
        ];
    }

    public function frequentItems(int $limit = 20): array
    {
        if (!$this->tableExists('Tb_Item')) {
            return [];
        }
        $limit = min(100, max(1, $limit));

        if ($this->tableExists('Tb_StockLog') && $this->columnExists('Tb_StockLog', 'item_idx')) {
            $logTimeCol = $this->firstExistingColumn('Tb_StockLog', ['updated_at', 'created_at', 'regdate', 'log_date']);
            $logTimeExpr = $logTimeCol !== null ? 'MAX(l.' . $this->qi($logTimeCol) . ')' : 'CAST(GETDATE() AS DATETIME)';
            $logWhere = ['ISNULL(l.' . $this->qi('item_idx') . ', 0) > 0'];
            if ($this->columnExists('Tb_StockLog', 'is_deleted')) {
                $logWhere[] = 'ISNULL(l.' . $this->qi('is_deleted') . ', 0) = 0';
            }

            $sql = "WITH freq AS (
                        SELECT TOP {$limit}
                            ISNULL(l." . $this->qi('item_idx') . ", 0) AS item_idx,
                            COUNT(1) AS usage_count,
                            {$logTimeExpr} AS last_used_at
                        FROM Tb_StockLog l
                        WHERE " . implode(' AND ', $logWhere) . "
                        GROUP BY ISNULL(l." . $this->qi('item_idx') . ", 0)
                        ORDER BY COUNT(1) DESC, {$logTimeExpr} DESC
                    )
                    SELECT
                        f.item_idx AS idx,
                        " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                        " . $this->itemStringExpr('name', 255, '') . " AS name,
                        " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                        " . $this->itemStringExpr('unit', 60, '') . " AS unit,
                        " . $this->itemIntExpr('category_idx', 0) . " AS category_idx,
                        " . $this->itemStringExpr('attribute', 60, '') . " AS attribute,
                        " . $this->itemFloatExpr('cost', 0) . " AS cost,
                        " . $this->itemFloatExpr('sale_price', 0) . " AS sale_price,
                        " . $this->itemImageAliasExpr('banner_img', 'upload_files_banner', 500) . " AS banner_img,
                        " . $this->itemStringExpr('upload_files_banner', 500, '') . " AS upload_files_banner,
                        " . $this->itemStringExpr('upload_files_detail', 500, '') . " AS upload_files_detail,
                        ISNULL(f.usage_count, 0) AS usage_count,
                        CONVERT(NVARCHAR(19), f.last_used_at, 120) AS last_used_at
                    FROM freq f
                    LEFT JOIN Tb_Item i ON i." . $this->qi('idx') . " = f.item_idx
                    ORDER BY f.usage_count DESC, f.last_used_at DESC, f.item_idx DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $this->normalizeListRows($rows) : [];
        }

        $itemTimeCol = $this->firstExistingColumn('Tb_Item', ['updated_at', 'created_at', 'regdate']);
        $itemTimeExpr = $itemTimeCol !== null
            ? 'CONVERT(NVARCHAR(19), i.' . $this->qi($itemTimeCol) . ', 120)'
            : "CONVERT(NVARCHAR(19), GETDATE(), 120)";
        $where = ['1=1'];
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }
        $sql = "SELECT TOP {$limit}
                    i." . $this->qi('idx') . " AS idx,
                    " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                    " . $this->itemStringExpr('name', 255, '') . " AS name,
                    " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                    " . $this->itemStringExpr('unit', 60, '') . " AS unit,
                    " . $this->itemIntExpr('category_idx', 0) . " AS category_idx,
                    " . $this->itemStringExpr('attribute', 60, '') . " AS attribute,
                    " . $this->itemFloatExpr('cost', 0) . " AS cost,
                    " . $this->itemFloatExpr('sale_price', 0) . " AS sale_price,
                    " . $this->itemImageAliasExpr('banner_img', 'upload_files_banner', 500) . " AS banner_img,
                    " . $this->itemStringExpr('upload_files_banner', 500, '') . " AS upload_files_banner,
                    " . $this->itemStringExpr('upload_files_detail', 500, '') . " AS upload_files_detail,
                    0 AS usage_count,
                    {$itemTimeExpr} AS last_used_at
                FROM Tb_Item i
                WHERE " . implode(' AND ', $where) . "
                ORDER BY " . ($itemTimeCol !== null ? 'i.' . $this->qi($itemTimeCol) . ' DESC, ' : '') . 'i.' . $this->qi('idx') . ' DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $this->normalizeListRows($rows) : [];
    }

    public function historyList(int $itemIdx, int $page = 1, int $limit = 20): array
    {
        if ($itemIdx <= 0) {
            throw new InvalidArgumentException('item_idx is required');
        }
        if (!$this->tableExists('Tb_ItemHistory')) {
            return [
                'data' => [],
                'total' => 0,
                'page' => max(1, $page),
                'pages' => 1,
                'limit' => min(100, max(1, $limit)),
            ];
        }

        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $where = ['h.' . $this->qi('item_idx') . ' = ?'];
        $params = [$itemIdx];
        if ($this->columnExists('Tb_ItemHistory', 'is_deleted')) {
            $where[] = 'ISNULL(h.' . $this->qi('is_deleted') . ', 0) = 0';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM Tb_ItemHistory h ' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $sql = "SELECT * FROM (
                    SELECT
                        h." . $this->qi('idx') . " AS idx,
                        ISNULL(h." . $this->qi('item_idx') . ", 0) AS item_idx,
                        ISNULL(CONVERT(NVARCHAR(30), h." . $this->qi('action') . "), N'') AS action,
                        " . ($this->columnExists('Tb_ItemHistory', 'changed_field') ? "ISNULL(CONVERT(NVARCHAR(200), h." . $this->qi('changed_field') . "), N'')" : "CAST(N'' AS NVARCHAR(200))") . " AS changed_field,
                        " . ($this->columnExists('Tb_ItemHistory', 'before_value') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('before_value') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS before_value,
                        " . ($this->columnExists('Tb_ItemHistory', 'after_value') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('after_value') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS after_value,
                        " . ($this->columnExists('Tb_ItemHistory', 'snapshot_json') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('snapshot_json') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS snapshot_json,
                        " . ($this->columnExists('Tb_ItemHistory', 'created_by') ? "ISNULL(h." . $this->qi('created_by') . ", 0)" : "0") . " AS created_by,
                        " . ($this->columnExists('Tb_ItemHistory', 'created_at') ? "CONVERT(NVARCHAR(19), h." . $this->qi('created_at') . ", 120)" : "CONVERT(NVARCHAR(19), GETDATE(), 120)") . " AS created_at,
                        ROW_NUMBER() OVER (ORDER BY h." . $this->qi('idx') . " DESC) AS rn
                    FROM Tb_ItemHistory h
                    {$whereSql}
                ) z
                WHERE z.rn BETWEEN ? AND ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, [$rowFrom, $rowTo]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
        ];
    }

    public function historyRestore(int $historyIdx, int $actorUserPk = 0): array
    {
        if ($historyIdx <= 0) {
            throw new InvalidArgumentException('history_idx is required');
        }
        if (!$this->tableExists('Tb_ItemHistory')) {
            throw new RuntimeException('Tb_ItemHistory 테이블이 없습니다');
        }

        $history = $this->loadHistoryRow($historyIdx);
        if (!is_array($history) || $history === []) {
            throw new RuntimeException('복구할 이력을 찾을 수 없습니다');
        }

        $itemIdx = (int)($history['item_idx'] ?? 0);
        if ($itemIdx <= 0) {
            throw new RuntimeException('이력의 item_idx가 유효하지 않습니다');
        }

        $snapshot = null;
        foreach (['snapshot_json', 'after_value', 'before_value'] as $key) {
            $raw = trim((string)($history[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                $snapshot = $decoded;
                break;
            }
        }
        if (!is_array($snapshot) || $snapshot === []) {
            throw new RuntimeException('복구 가능한 스냅샷이 없습니다');
        }

        $payload = $this->extractWritablePayload($snapshot);
        if ($payload === []) {
            throw new RuntimeException('복구 가능한 필드가 없습니다');
        }

        $beforeRow = $this->detail($itemIdx);
        $updated = $this->update($itemIdx, $payload, $actorUserPk);
        $afterRow = is_array($updated['row'] ?? null) ? $updated['row'] : ($this->detail($itemIdx) ?? []);
        $this->logItemHistory(
            $itemIdx,
            'restore',
            'history_idx',
            ['history_idx' => $historyIdx, 'before' => $beforeRow],
            ['history_idx' => $historyIdx, 'after' => $afterRow],
            $afterRow,
            $actorUserPk
        );

        return [
            'history_idx' => $historyIdx,
            'item_idx' => $itemIdx,
            'updated_count' => (int)($updated['updated_count'] ?? 0),
            'row' => $afterRow,
        ];
    }

    public function inlineUpdate(int $idx, string $field, $value, int $actorUserPk = 0): array
    {
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException('field is required');
        }

        $aliasMap = [
            'item_name' => 'name',
            'spec' => 'standard',
            'cat_idx' => 'category_idx',
            'item_tab_idx' => 'tab_idx',
        ];
        $mappedField = $aliasMap[$field] ?? $field;
        $fieldTypes = $this->fieldTypeMap();
        if (!array_key_exists($mappedField, $fieldTypes)) {
            throw new InvalidArgumentException('지원하지 않는 field: ' . $field);
        }
        if ($mappedField === 'idx') {
            throw new InvalidArgumentException('idx는 수정할 수 없습니다');
        }
        if (!$this->columnExists('Tb_Item', $mappedField)) {
            throw new RuntimeException('컬럼이 존재하지 않습니다: ' . $mappedField);
        }

        $updated = $this->update($idx, [$mappedField => $value], $actorUserPk);
        $row = is_array($updated['row'] ?? null) ? $updated['row'] : [];

        return [
            'idx' => $idx,
            'field' => $mappedField,
            'value' => $row[$mappedField] ?? $value,
            'updated_count' => (int)($updated['updated_count'] ?? 0),
            'row' => $row,
        ];
    }

    public function searchComponent(string $q, int $tabIdx = 0, int $categoryIdx = 0, int $limit = 20): array
    {
        if (!$this->tableExists('Tb_Item') || !$this->columnExists('Tb_Item', 'material_pattern')) {
            return [];
        }

        $keyword = trim($q);
        $tabIdx = max(0, $tabIdx);
        $categoryIdx = max(0, $categoryIdx);
        $limit = min(50, max(1, $limit));

        $where = ["ISNULL(i." . $this->qi('material_pattern') . ", N'') = N'구성품'"];
        $params = [];

        if ($keyword !== '') {
            $searchCols = [];
            foreach (['name', 'item_code', 'standard'] as $column) {
                if ($this->columnExists('Tb_Item', $column)) {
                    $searchCols[] = 'ISNULL(i.' . $this->qi($column) . ", N'') LIKE ?";
                }
            }

            if ($searchCols !== []) {
                $where[] = '(' . implode(' OR ', $searchCols) . ')';
                $like = '%' . $keyword . '%';
                for ($i = 0; $i < count($searchCols); $i++) {
                    $params[] = $like;
                }
            }
        }

        if ($tabIdx > 0 && $this->columnExists('Tb_Item', 'tab_idx')) {
            $where[] = 'ISNULL(i.' . $this->qi('tab_idx') . ', 0) = ?';
            $params[] = $tabIdx;
        }

        if ($categoryIdx > 0 && $this->columnExists('Tb_Item', 'category_idx')) {
            $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) = ?';
            $params[] = $categoryIdx;
        }

        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $joinCategory = $this->tableExists('Tb_ItemCategory')
            && $this->columnExists('Tb_Item', 'category_idx')
            && $this->columnExists('Tb_ItemCategory', 'idx')
            ? 'LEFT JOIN Tb_ItemCategory c ON i.' . $this->qi('category_idx') . ' = c.' . $this->qi('idx')
            : '';

        $sql = "SELECT TOP {$limit}
                    i." . $this->qi('idx') . " AS idx,
                    " . $this->itemStringExpr('item_code', 120, '') . " AS item_code,
                    " . $this->itemStringExpr('name', 255, '') . " AS name,
                    " . $this->itemStringExpr('standard', 255, '') . " AS standard,
                    " . $this->itemStringExpr('unit', 60, '') . " AS unit,
                    " . $this->itemStringExpr('material_pattern', 20, '') . " AS material_pattern,
                    " . $this->itemStringExpr('follow_mode', 5, '') . " AS follow_mode,
                    " . $this->itemIntExpr('tab_idx', 0) . " AS tab_idx,
                    " . $this->itemIntExpr('category_idx', 0) . " AS category_idx,
                    " . $this->joinedNameExpr('Tb_ItemCategory', 'c', 120) . " AS category_name
                FROM Tb_Item i
                {$joinCategory}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY " . ($this->columnExists('Tb_Item', 'name') ? 'i.' . $this->qi('name') . ' ASC' : 'i.' . $this->qi('idx') . ' DESC');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function componentList(int $parentIdx): array
    {
        $componentTable = $this->itemComponentTable();
        if ($parentIdx <= 0 || $componentTable === '') {
            return [];
        }

        $parentCol = $this->itemChildParentColumn($componentTable);
        $childCol = $this->itemChildChildColumn($componentTable);
        $qtyCol = $this->itemChildQtyColumn($componentTable);
        $sortCol = $this->itemChildSortColumn($componentTable);

        $where = ['c.' . $this->qi($parentCol) . ' = ?'];
        $params = [$parentIdx];
        if ($this->columnExists($componentTable, 'is_deleted')) {
            $where[] = 'ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
        }
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $where[] = 'ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $sortExpr = $sortCol !== null ? 'ISNULL(c.' . $this->qi($sortCol) . ', 0)' : '0';
        $sql = "SELECT
                    c." . $this->qi('idx') . " AS idx,
                    ISNULL(c." . $this->qi($parentCol) . ", 0) AS parent_item_idx,
                    ISNULL(c." . $this->qi($childCol) . ", 0) AS child_item_idx,
                    ISNULL(c." . $this->qi($qtyCol) . ", 1) AS child_qty,
                    {$sortExpr} AS sort_order,
                    " . $this->itemStringExpr('item_code', 120, '') . " AS child_item_code,
                    " . $this->itemStringExpr('name', 255, '') . " AS child_item_name,
                    " . $this->itemStringExpr('standard', 255, '') . " AS child_standard,
                    " . $this->itemStringExpr('unit', 60, '') . " AS child_unit,
                    " . $this->itemStringExpr('inventory_management', 10, '무') . " AS child_inventory_management,
                    " . $this->itemStringExpr('material_pattern', 20, '') . " AS child_material_pattern,
                    " . $this->itemStringExpr('follow_mode', 5, '') . " AS child_follow_mode
                FROM {$componentTable} c
                LEFT JOIN Tb_Item i ON c." . $this->qi($childCol) . " = i." . $this->qi('idx') . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$sortExpr} ASC, c." . $this->qi('idx') . " ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function componentAdd(int $parentIdx, int $childIdx, float $qty, int $actorUserPk = 0): array
    {
        $componentTable = $this->itemComponentTable(true);
        if ($parentIdx <= 0 || $childIdx <= 0) {
            throw new InvalidArgumentException('parent_item_idx, child_item_idx is required');
        }
        if ($parentIdx === $childIdx) {
            throw new InvalidArgumentException('부모와 구성품은 같을 수 없습니다');
        }

        $qty = max(1, $qty);
        $parentCol = $this->itemChildParentColumn($componentTable);
        $childCol = $this->itemChildChildColumn($componentTable);
        $qtyCol = $this->itemChildQtyColumn($componentTable);
        $sortCol = $this->itemChildSortColumn($componentTable);
        $this->assertComponentChildItem($childIdx);
        $this->assertComponentNotDuplicated($componentTable, $parentCol, $childCol, $parentIdx, $childIdx);

        $columns = [$parentCol, $childCol, $qtyCol];
        $valuesSql = ['?', '?', '?'];
        $params = [$parentIdx, $childIdx, $qty];

        if ($sortCol !== null) {
            $columns[] = $sortCol;
            $valuesSql[] = '?';
            $params[] = $this->nextComponentSortOrder($componentTable, $parentCol, $parentIdx);
        }

        if ($this->columnExists($componentTable, 'created_by')) {
            $columns[] = 'created_by';
            $valuesSql[] = '?';
            $params[] = max(0, $actorUserPk);
        }
        if ($this->columnExists($componentTable, 'updated_by')) {
            $columns[] = 'updated_by';
            $valuesSql[] = '?';
            $params[] = max(0, $actorUserPk);
        }
        if ($this->columnExists($componentTable, 'created_at')) {
            $columns[] = 'created_at';
            $valuesSql[] = 'GETDATE()';
        }
        if ($this->columnExists($componentTable, 'updated_at')) {
            $columns[] = 'updated_at';
            $valuesSql[] = 'GETDATE()';
        }
        if ($this->columnExists($componentTable, 'is_deleted')) {
            $columns[] = 'is_deleted';
            $valuesSql[] = '0';
        }

        $sql = 'INSERT INTO ' . $componentTable . ' (' . implode(', ', $this->quoteColumns($columns)) . ')'
            . ' OUTPUT INSERTED.' . $this->qi('idx') . ' AS new_idx'
            . ' VALUES (' . implode(', ', $valuesSql) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['idx' => (int)($row['new_idx'] ?? 0)];
    }

    public function componentUpdate(int $idx, float $qty, int $actorUserPk = 0): array
    {
        $componentTable = $this->itemComponentTable(true);
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        $qtyCol = $this->itemChildQtyColumn($componentTable);
        $setSql = [$this->qi($qtyCol) . ' = ?'];
        $params = [$qty];

        if ($this->columnExists($componentTable, 'updated_by')) {
            $setSql[] = $this->qi('updated_by') . ' = ?';
            $params[] = max(0, $actorUserPk);
        }
        if ($this->columnExists($componentTable, 'updated_at')) {
            $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
        }

        $params[] = $idx;
        $sql = 'UPDATE ' . $componentTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $this->qi('idx') . ' = ?';
        if ($this->columnExists($componentTable, 'is_deleted')) {
            $sql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return [
            'idx' => $idx,
            'updated_count' => (int)$stmt->rowCount(),
        ];
    }

    public function componentDelete(int $idx, int $actorUserPk = 0): array
    {
        $componentTable = $this->itemComponentTable(true);
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        if ($this->columnExists($componentTable, 'is_deleted')) {
            $setSql = [$this->qi('is_deleted') . ' = 1'];
            $params = [];

            if ($this->columnExists($componentTable, 'deleted_by')) {
                $setSql[] = $this->qi('deleted_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists($componentTable, 'deleted_at')) {
                $setSql[] = $this->qi('deleted_at') . ' = GETDATE()';
            }
            if ($this->columnExists($componentTable, 'updated_by')) {
                $setSql[] = $this->qi('updated_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists($componentTable, 'updated_at')) {
                $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
            }

            $params[] = $idx;
            $sql = 'UPDATE ' . $componentTable . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $this->qi('idx') . ' = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return [
                'idx' => $idx,
                'deleted_count' => (int)$stmt->rowCount(),
            ];
        }

        $stmt = $this->db->prepare('DELETE FROM ' . $componentTable . ' WHERE ' . $this->qi('idx') . ' = ?');
        $stmt->execute([$idx]);

        return [
            'idx' => $idx,
            'deleted_count' => (int)$stmt->rowCount(),
        ];
    }

    private function itemTable(): string
    {
        if ($this->tableExists('Tb_Item')) {
            return 'Tb_Item';
        }
        throw new RuntimeException('Tb_Item 테이블이 없습니다');
    }

    private function itemComponentTable(bool $required = false): string
    {
        if ($this->tableExists('Tb_ItemComponent')) {
            return 'Tb_ItemComponent';
        }
        if ($this->tableExists('Tb_ItemChild')) {
            return 'Tb_ItemChild';
        }
        if ($required) {
            throw new RuntimeException('Tb_ItemComponent/Tb_ItemChild 테이블이 없습니다');
        }
        return '';
    }

    private function normalizePayload(array $input): array
    {
        $payload = $input;

        if (!array_key_exists('item_code', $payload) && array_key_exists('code', $payload)) {
            $payload['item_code'] = $payload['code'];
        }
        if (!array_key_exists('name', $payload) && array_key_exists('item_name', $payload)) {
            $payload['name'] = $payload['item_name'];
        }
        if (!array_key_exists('standard', $payload) && array_key_exists('spec', $payload)) {
            $payload['standard'] = $payload['spec'];
        }
        if (!array_key_exists('tab_idx', $payload) && array_key_exists('item_tab_idx', $payload)) {
            $payload['tab_idx'] = $payload['item_tab_idx'];
        }
        if (!array_key_exists('category_idx', $payload) && array_key_exists('cat_idx', $payload)) {
            $payload['category_idx'] = $payload['cat_idx'];
        }

        return $payload;
    }

    private function fieldTypeMap(): array
    {
        return [
            'item_code' => 'string',
            'name' => 'string',
            'standard' => 'string',
            'spec' => 'string',
            'unit' => 'string',
            'tab_idx' => 'int_nullable',
            'category_idx' => 'int_nullable',
            'inventory_management' => 'string',
            'safety_count' => 'float',
            'base_count' => 'float',
            'memo' => 'string',
            'remark' => 'string',
            'remarks' => 'string',
            'note' => 'string',
            'price' => 'float',
            'cost' => 'float',
            'material_pattern' => 'string',
            'barcode' => 'string',
            'attribute' => 'string',
            'hidden' => 'string',
            'relative_purchase' => 'string',
            'company_idx' => 'int_nullable',
            'company_name' => 'string',
            'sale_price' => 'float',
            'supply_price' => 'float',
            'purchase_price' => 'float',
            'work_price' => 'float',
            'tax_price' => 'float',
            'safety' => 'float',
            'qty' => 'float',
            'contents' => 'string',
            'reg_date' => 'date',
            'is_split' => 'string',
            'follow_mode' => 'string',
            'upload_files_banner' => 'string',
            'upload_files_detail' => 'string',
            'use_yn' => 'string',
            'is_use' => 'bool01',
            'is_active' => 'bool01',
            'origin_idx' => 'int_nullable',
            'legacy_idx' => 'int_nullable',
            'is_legacy_copy' => 'bool01',
            'legacy_copied' => 'bool01',
            'is_migrated' => 'bool01',
        ];
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

        if ($type === 'float') {
            if ($value === '' || $value === null) {
                return 0;
            }
            return (float)$value;
        }

        if ($type === 'bool01') {
            $text = strtolower(trim((string)$value));
            if (in_array($text, ['1', 'true', 'on', 'yes', 'y'], true)) {
                return 1;
            }
            return 0;
        }

        if ($type === 'date') {
            $text = trim((string)$value);
            if ($text === '') {
                return null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
                throw new InvalidArgumentException('date must be YYYY-MM-DD');
            }
            $parts = explode('-', $text);
            $year = (int)($parts[0] ?? 0);
            $month = (int)($parts[1] ?? 0);
            $day = (int)($parts[2] ?? 0);
            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException('date is invalid: ' . $text);
            }
            return $text;
        }

        return trim((string)$value);
    }

    private function itemStringExpr(string $column, int $len, string $default = ''): string
    {
        if ($this->columnExists('Tb_Item', $column)) {
            return 'ISNULL(i.' . $this->qi($column) . ", N'" . str_replace("'", "''", $default) . "')";
        }

        return "CAST(N'" . str_replace("'", "''", $default) . "' AS NVARCHAR({$len}))";
    }

    private function itemIntExpr(string $column, int $default = 0): string
    {
        if ($this->columnExists('Tb_Item', $column)) {
            return 'ISNULL(i.' . $this->qi($column) . ', ' . (int)$default . ')';
        }

        return (string)(int)$default;
    }

    private function itemFloatExpr(string $column, float $default = 0): string
    {
        $literal = $this->floatLiteral($default);
        if ($this->columnExists('Tb_Item', $column)) {
            return 'ISNULL(i.' . $this->qi($column) . ', ' . $literal . ')';
        }

        return $literal;
    }

    private function itemTextExpr(string $column, int $len, string $default = ''): string
    {
        $escapedDefault = str_replace("'", "''", $default);
        if ($this->columnExists('Tb_Item', $column)) {
            return 'ISNULL(CONVERT(NVARCHAR(' . $len . '), i.' . $this->qi($column) . "), N'{$escapedDefault}')";
        }

        return "CAST(N'{$escapedDefault}' AS NVARCHAR({$len}))";
    }

    private function itemImageAliasExpr(string $primaryColumn, string $fallbackColumn, int $len = 500): string
    {
        $parts = [];
        if ($this->columnExists('Tb_Item', $primaryColumn)) {
            $parts[] = 'NULLIF(CONVERT(NVARCHAR(' . $len . '), i.' . $this->qi($primaryColumn) . "), N'')";
        }
        if ($this->columnExists('Tb_Item', $fallbackColumn)) {
            $parts[] = 'NULLIF(CONVERT(NVARCHAR(' . $len . '), i.' . $this->qi($fallbackColumn) . "), N'')";
        }

        if ($parts === []) {
            return "CAST(NULL AS NVARCHAR({$len}))";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", CAST(NULL AS NVARCHAR({$len})))";
    }

    private function normalizeListRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = $this->normalizeListRow($row);
        }
        return $result;
    }

    private function normalizeListRow(array $row): array
    {
        foreach (['idx', 'tab_idx', 'category_idx', 'origin_idx', 'legacy_idx', 'is_legacy_copy', 'legacy_copied', 'is_migrated', 'component_count', 'child_count', 'usage_count'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $row[$key] = (int)$row[$key];
        }

        foreach (['cost', 'sale_price', 'safety_count', 'base_count', 'qty'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $raw = $row[$key];
            if ($raw === null || $raw === '') {
                $row[$key] = 0.0;
                continue;
            }
            $row[$key] = is_numeric((string)$raw) ? (float)$raw : 0.0;
        }

        if (array_key_exists('attribute', $row)) {
            $row['attribute'] = trim((string)$row['attribute']);
        }

        $banner = trim((string)($row['banner_img'] ?? ''));
        $uploadBanner = trim((string)($row['upload_files_banner'] ?? ''));
        $uploadDetail = trim((string)($row['upload_files_detail'] ?? ''));

        if ($banner === '') {
            $banner = $uploadBanner;
        }
        if ($banner === '') {
            $banner = $uploadDetail;
        }

        $row['banner_img'] = $banner;
        $row['upload_files_banner'] = $uploadBanner;
        $row['upload_files_detail'] = $uploadDetail;
        $row['image_url'] = $banner;

        return $row;
    }

    private function categorySubtreeIds(int $rootIdx): array
    {
        if ($rootIdx <= 0) {
            return [];
        }
        if (
            !$this->tableExists('Tb_ItemCategory')
            || !$this->columnExists('Tb_ItemCategory', 'idx')
            || !$this->columnExists('Tb_ItemCategory', 'parent_idx')
        ) {
            return [$rootIdx];
        }

        $where = ['1=1'];
        if ($this->columnExists('Tb_ItemCategory', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted, 0) = 0';
        }

        $stmt = $this->db->prepare(
            'SELECT idx, ISNULL(parent_idx, 0) AS parent_idx FROM Tb_ItemCategory WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [$rootIdx];
        }

        $childrenByParent = [];
        foreach ($rows as $row) {
            $idx = (int)($row['idx'] ?? 0);
            $parentIdx = (int)($row['parent_idx'] ?? 0);
            if ($idx <= 0) {
                continue;
            }
            if (!array_key_exists($parentIdx, $childrenByParent)) {
                $childrenByParent[$parentIdx] = [];
            }
            $childrenByParent[$parentIdx][] = $idx;
        }

        $queue = [$rootIdx];
        $visited = [];
        $result = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_int($current) || $current <= 0 || array_key_exists($current, $visited)) {
                continue;
            }
            $visited[$current] = true;
            $result[] = $current;

            if (!array_key_exists($current, $childrenByParent)) {
                continue;
            }
            foreach ($childrenByParent[$current] as $childIdx) {
                if (!array_key_exists($childIdx, $visited)) {
                    $queue[] = $childIdx;
                }
            }
        }

        if ($result === []) {
            $result[] = $rootIdx;
        }

        return array_values(array_unique(array_map('intval', $result)));
    }

    private function joinedNameExpr(string $table, string $alias, int $len): string
    {
        if (!$this->tableExists($table)) {
            return "CAST(N'' AS NVARCHAR({$len}))";
        }

        foreach (['name', 'tab_name', 'category_name'] as $column) {
            if ($this->columnExists($table, $column)) {
                return 'ISNULL(' . $alias . '.' . $this->qi($column) . ", N'')";
            }
        }

        return "CAST(N'' AS NVARCHAR({$len}))";
    }

    private function quoteColumns(array $columns): array
    {
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = $this->qi($column);
        }
        return $quoted;
    }

    private function itemChildParentColumn(string $table): string
    {
        foreach (['parent_item_idx', 'parent_idx'] as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        throw new RuntimeException($table . ' parent 컬럼이 없습니다');
    }

    private function itemChildChildColumn(string $table): string
    {
        foreach (['child_item_idx', 'child_idx'] as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        throw new RuntimeException($table . ' child 컬럼이 없습니다');
    }

    private function itemChildQtyColumn(string $table): string
    {
        if ($this->columnExists($table, 'qty')) {
            return 'qty';
        }
        if ($this->columnExists($table, 'child_qty')) {
            return 'child_qty';
        }
        throw new RuntimeException($table . ' qty 컬럼이 없습니다');
    }

    private function itemChildSortColumn(string $table): ?string
    {
        return $this->columnExists($table, 'sort_order') ? 'sort_order' : null;
    }

    private function assertComponentChildItem(int $childIdx): void
    {
        if (!$this->tableExists('Tb_Item')) {
            throw new RuntimeException('Tb_Item 테이블이 없습니다');
        }

        $sql = 'SELECT TOP 1 i.' . $this->qi('idx') . ' AS idx';
        if ($this->columnExists('Tb_Item', 'material_pattern')) {
            $sql .= ", ISNULL(CONVERT(NVARCHAR(20), i." . $this->qi('material_pattern') . "), N'') AS material_pattern";
        }
        $sql .= ' FROM Tb_Item i WHERE i.' . $this->qi('idx') . ' = ?';
        if ($this->columnExists('Tb_Item', 'is_deleted')) {
            $sql .= ' AND ISNULL(i.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$childIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)($row['idx'] ?? 0) <= 0) {
            throw new RuntimeException('구성품 대상 품목을 찾을 수 없습니다');
        }

        if ($this->columnExists('Tb_Item', 'material_pattern')) {
            $pattern = trim((string)($row['material_pattern'] ?? ''));
            if ($pattern !== '구성품') {
                throw new RuntimeException("material_pattern='구성품' 품목만 추가할 수 있습니다");
            }
        }
    }

    private function assertComponentNotDuplicated(string $table, string $parentCol, string $childCol, int $parentIdx, int $childIdx): void
    {
        $sql = 'SELECT TOP 1 ' . $this->qi('idx')
            . ' FROM ' . $table . ' WHERE ' . $this->qi($parentCol) . ' = ? AND ' . $this->qi($childCol) . ' = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$parentIdx, $childIdx]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('이미 등록된 구성품입니다');
        }
    }

    private function nextComponentSortOrder(string $table, string $parentCol, int $parentIdx): int
    {
        $sortCol = $this->itemChildSortColumn($table);
        if ($sortCol === null) {
            return 0;
        }

        $sql = 'SELECT ISNULL(MAX(' . $this->qi($sortCol) . '), 0) + 1 AS next_sort'
            . ' FROM ' . $table . ' WHERE ' . $this->qi($parentCol) . ' = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$parentIdx]);
        return (int)$stmt->fetchColumn();
    }

    private function floatLiteral(float $value): string
    {
        if ((float)(int)$value === $value) {
            return (string)(int)$value;
        }
        $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        return $text === '' ? '0' : $text;
    }

    private function companyJoinSql(string $itemAlias = 'i', string $companyAlias = 'co'): string
    {
        $lookup = $this->resolveCompanyLookup();
        if ($lookup === [] || !$this->columnExists('Tb_Item', 'company_idx')) {
            return '';
        }

        $table = (string)$lookup['table'];
        $idColumn = (string)$lookup['id_column'];
        $join = 'LEFT JOIN ' . $this->qi($table) . ' ' . $companyAlias
            . ' ON ' . $itemAlias . '.' . $this->qi('company_idx')
            . ' = ' . $companyAlias . '.' . $this->qi($idColumn);

        if ($this->columnExists($table, 'is_deleted')) {
            $join .= ' AND ISNULL(' . $companyAlias . '.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        return $join;
    }

    private function companyNameExpr(string $itemAlias = 'i', string $companyAlias = 'co', int $len = 255): string
    {
        $parts = [];
        $lookup = $this->resolveCompanyLookup();

        if ($lookup !== []) {
            $parts[] = 'NULLIF(CONVERT(NVARCHAR(' . $len . '), '
                . $companyAlias . '.' . $this->qi((string)$lookup['name_column']) . "), N'')";
        }

        if ($this->columnExists('Tb_Item', 'company_name')) {
            $parts[] = 'NULLIF(CONVERT(NVARCHAR(' . $len . '), '
                . $itemAlias . '.' . $this->qi('company_name') . "), N'')";
        }

        if ($parts === []) {
            return "CAST(N'' AS NVARCHAR({$len}))";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", CAST(N'' AS NVARCHAR({$len})))";
    }

    private function resolveCompanyLookup(): array
    {
        if ($this->companyLookupCache !== null) {
            return $this->companyLookupCache;
        }

        $candidates = [
            ['table' => 'Tb_Company', 'id_column' => 'idx', 'name_column' => 'company_name'],
            ['table' => 'Tb_Company', 'id_column' => 'idx', 'name_column' => 'name'],
            ['table' => 'Tb_Vendor',  'id_column' => 'idx', 'name_column' => 'vendor_name'],
            ['table' => 'Tb_Vendor',  'id_column' => 'idx', 'name_column' => 'name'],
        ];

        foreach ($candidates as $candidate) {
            $table = (string)$candidate['table'];
            $idColumn = (string)$candidate['id_column'];
            $nameColumn = (string)$candidate['name_column'];
            if (!$this->tableExists($table)) {
                continue;
            }
            if (!$this->columnExists($table, $idColumn)) {
                continue;
            }
            if (!$this->columnExists($table, $nameColumn)) {
                continue;
            }

            $this->companyLookupCache = [
                'table' => $table,
                'id_column' => $idColumn,
                'name_column' => $nameColumn,
            ];
            return $this->companyLookupCache;
        }

        $this->companyLookupCache = [];
        return $this->companyLookupCache;
    }

    private function tabNameColumn(): ?string
    {
        foreach (['name', 'tab_name'] as $column) {
            if ($this->columnExists('Tb_ItemTab', $column)) {
                return $column;
            }
        }
        return null;
    }

    private function nextSortOrder(string $tableName, string $sortColumn = 'sort_order'): int
    {
        if (!$this->tableExists($tableName) || !$this->columnExists($tableName, $sortColumn)) {
            return 0;
        }
        $sql = 'SELECT ISNULL(MAX(' . $this->qi($sortColumn) . '), 0) + 1 FROM ' . $this->qi($tableName);
        $stmt = $this->db->query($sql);
        return $stmt !== false ? (int)$stmt->fetchColumn() : 0;
    }

    private function columnNullable(string $tableName, string $columnName): bool
    {
        $key = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($key, $this->columnNullableCache)) {
            return (bool)$this->columnNullableCache[$key];
        }
        if (!$this->tableExists($tableName)) {
            $this->columnNullableCache[$key] = false;
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$tableName, $columnName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isNullable = strtoupper((string)($row['IS_NULLABLE'] ?? 'NO')) === 'YES';
        $this->columnNullableCache[$key] = $isNullable;
        return $isNullable;
    }

    private function firstExistingColumn(string $tableName, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            $columnName = trim((string)$column);
            if ($columnName !== '' && $this->columnExists($tableName, $columnName)) {
                return $columnName;
            }
        }
        return null;
    }

    private function normalizeItemCodePrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));
        $prefix = preg_replace('/[^A-Z0-9_-]/', '', $prefix) ?? '';
        return $prefix === '' ? 'MAT' : substr($prefix, 0, 30);
    }

    private function nextCopyItemCode(string $baseCode): string
    {
        $seed = trim($baseCode);
        if ($seed === '') {
            $seed = 'ITEM';
        }
        $seed = $seed . '-COPY';
        return $this->nextAvailableItemCode($seed, 0);
    }

    private function nextAvailableItemCode(string $seed, int $excludeIdx = 0): string
    {
        $seed = substr(trim($seed), 0, 120);
        if ($seed === '') {
            $seed = 'ITEM-' . date('YmdHis');
        }
        if (!$this->itemCodeExists($seed, $excludeIdx)) {
            return $seed;
        }

        for ($i = 2; $i <= 9999; $i++) {
            $suffix = '-' . $i;
            $base = substr($seed, 0, max(1, 120 - strlen($suffix)));
            $candidate = $base . $suffix;
            if (!$this->itemCodeExists($candidate, $excludeIdx)) {
                return $candidate;
            }
        }

        $suffix = '-' . date('His');
        return substr($seed, 0, max(1, 120 - strlen($suffix))) . $suffix;
    }

    private function itemCodeExists(string $itemCode, int $excludeIdx = 0): bool
    {
        if (!$this->tableExists('Tb_Item') || !$this->columnExists('Tb_Item', 'item_code')) {
            return false;
        }
        $sql = 'SELECT TOP 1 ' . $this->qi('idx') . ' FROM Tb_Item WHERE ' . $this->qi('item_code') . ' = ?';
        $params = [$itemCode];
        if ($excludeIdx > 0) {
            $sql .= ' AND ' . $this->qi('idx') . ' <> ?';
            $params[] = $excludeIdx;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function v1DbName(): string
    {
        if (function_exists('shvEnv')) {
            $name = trim((string)shvEnv('MAT_V1_DB_NAME', 'CSM_C004732'));
            if ($name !== '') {
                return $name;
            }
        }
        return 'CSM_C004732';
    }

    private function v1ItemTableSql(): string
    {
        return $this->qi($this->v1DbName()) . '.dbo.' . $this->qi('Tb_Item');
    }

    private function v1ItemTableExists(): bool
    {
        if ($this->v1ItemTableExistsCache !== null) {
            return $this->v1ItemTableExistsCache;
        }

        $dbName = $this->v1DbName();
        $stmt = $this->db->prepare(
            "SELECT CASE
                WHEN DB_ID(?) IS NULL THEN 0
                WHEN OBJECT_ID(?, 'U') IS NULL THEN 0
                ELSE 1
            END"
        );
        $stmt->execute([$dbName, $dbName . '.dbo.Tb_Item']);
        $this->v1ItemTableExistsCache = (int)$stmt->fetchColumn() === 1;
        return $this->v1ItemTableExistsCache;
    }

    private function v1ItemColumnExists(string $columnName): bool
    {
        $columnName = trim($columnName);
        if ($columnName === '') {
            return false;
        }

        $key = strtolower($columnName);
        if (array_key_exists($key, $this->v1ColumnExistsCache)) {
            return (bool)$this->v1ColumnExistsCache[$key];
        }
        if (!$this->v1ItemTableExists()) {
            $this->v1ColumnExistsCache[$key] = false;
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $this->qi($this->v1DbName())
            . ".INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='Tb_Item' AND COLUMN_NAME=?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$columnName]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $this->v1ColumnExistsCache[$key] = $exists;
        return $exists;
    }

    private function v2LegacyExistsExpr(string $legacyIdxExpr): string
    {
        if (!$this->tableExists('Tb_Item') || !$this->columnExists('Tb_Item', 'legacy_idx')) {
            return '0';
        }

        return 'CASE WHEN EXISTS (
                    SELECT 1
                    FROM Tb_Item v2
                    WHERE ISNULL(v2.' . $this->qi('legacy_idx') . ', 0) = ' . $legacyIdxExpr . '
                ) THEN 1 ELSE 0 END';
    }

    private function v1StringExpr(string $alias, string $column, int $len, string $default = ''): string
    {
        $escapedDefault = str_replace("'", "''", $default);
        if ($this->v1ItemColumnExists($column)) {
            return 'ISNULL(CONVERT(NVARCHAR(' . $len . '), ' . $alias . '.' . $this->qi($column) . "), N'{$escapedDefault}')";
        }
        return "CAST(N'{$escapedDefault}' AS NVARCHAR({$len}))";
    }

    private function v1IntExpr(string $alias, string $column, int $default = 0): string
    {
        if ($this->v1ItemColumnExists($column)) {
            return 'ISNULL(' . $alias . '.' . $this->qi($column) . ', ' . (int)$default . ')';
        }
        return (string)(int)$default;
    }

    private function v1FloatExpr(string $alias, string $column, float $default = 0): string
    {
        $literal = $this->floatLiteral($default);
        if ($this->v1ItemColumnExists($column)) {
            return 'ISNULL(' . $alias . '.' . $this->qi($column) . ', ' . $literal . ')';
        }
        return $literal;
    }

    private function loadV1ItemsByIdx(array $idxList): array
    {
        $idxList = array_values(array_unique(array_map('intval', $idxList)));
        $idxList = array_values(array_filter($idxList, static fn (int $v): bool => $v > 0));
        if ($idxList === [] || !$this->v1ItemTableExists()) {
            return [];
        }

        $selectColumns = ['idx'];
        foreach (array_keys($this->fieldTypeMap()) as $column) {
            if (in_array($column, ['idx', 'legacy_idx', 'is_legacy_copy', 'legacy_copied', 'is_migrated'], true)) {
                continue;
            }
            if ($this->v1ItemColumnExists($column) && !in_array($column, $selectColumns, true)) {
                $selectColumns[] = $column;
            }
        }

        $selectSql = [];
        foreach ($selectColumns as $column) {
            $selectSql[] = 'v1.' . $this->qi($column) . ' AS ' . $this->qi($column);
        }

        $placeholders = implode(',', array_fill(0, count($idxList), '?'));
        $sql = 'SELECT ' . implode(', ', $selectSql)
            . ' FROM ' . $this->v1ItemTableSql() . ' v1'
            . ' WHERE v1.' . $this->qi('idx') . " IN ({$placeholders})";
        if ($this->v1ItemColumnExists('is_deleted')) {
            $sql .= ' AND ISNULL(v1.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($idxList);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $legacyIdx = (int)($row['idx'] ?? 0);
                if ($legacyIdx > 0) {
                    $mapped[$legacyIdx] = $row;
                }
            }
        }
        return $mapped;
    }

    private function loadExistingLegacyIdx(array $legacyIdxList): array
    {
        $legacyIdxList = array_values(array_unique(array_map('intval', $legacyIdxList)));
        $legacyIdxList = array_values(array_filter($legacyIdxList, static fn (int $v): bool => $v > 0));
        if ($legacyIdxList === [] || !$this->columnExists('Tb_Item', 'legacy_idx')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($legacyIdxList), '?'));
        $sql = 'SELECT ' . $this->qi('legacy_idx') . ' AS legacy_idx FROM Tb_Item'
            . ' WHERE ' . $this->qi('legacy_idx') . " IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($legacyIdxList);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $legacyIdx = (int)($row['legacy_idx'] ?? 0);
            if ($legacyIdx > 0) {
                $existing[] = $legacyIdx;
            }
        }
        $existing = array_values(array_unique($existing));
        sort($existing);
        return $existing;
    }

    private function buildCopyPayloadFromV1(array $legacyRow, ?int $tabIdx, ?int $categoryIdx): array
    {
        $legacyIdx = (int)($legacyRow['idx'] ?? 0);
        if ($legacyIdx <= 0) {
            throw new InvalidArgumentException('legacy idx is invalid');
        }

        $payload = [];
        foreach ($this->fieldTypeMap() as $field => $_type) {
            if (in_array($field, ['idx', 'legacy_idx', 'is_legacy_copy', 'legacy_copied', 'is_migrated'], true)) {
                continue;
            }
            if (array_key_exists($field, $legacyRow)) {
                $payload[$field] = $legacyRow[$field];
            }
        }

        if ($tabIdx !== null) {
            $payload['tab_idx'] = $tabIdx;
        }
        if ($categoryIdx !== null) {
            $payload['category_idx'] = $categoryIdx;
        }

        $seed = trim((string)($legacyRow['item_code'] ?? ''));
        if ($seed === '') {
            $seed = 'MAT-' . str_pad((string)$legacyIdx, 6, '0', STR_PAD_LEFT);
        }
        $payload['item_code'] = $this->nextAvailableItemCode($seed, 0);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            $payload['name'] = 'V1_ITEM_' . $legacyIdx;
        }

        $payload['legacy_idx'] = $legacyIdx;
        $payload['is_legacy_copy'] = 1;
        $payload['legacy_copied'] = 1;
        $payload['is_migrated'] = 1;

        return $payload;
    }

    private function extractWritablePayload(array $source): array
    {
        $payload = [];
        foreach ($this->fieldTypeMap() as $field => $_type) {
            if ($field === 'idx') {
                continue;
            }
            if (array_key_exists($field, $source)) {
                $payload[$field] = $source[$field];
            }
        }
        return $payload;
    }

    private function materialCategoryTable(): string
    {
        if ($this->tableExists('Tb_ItemCategory')) {
            return 'Tb_ItemCategory';
        }
        if ($this->tableExists('Tb_Category')) {
            return 'Tb_Category';
        }
        throw new RuntimeException('Tb_ItemCategory/Tb_Category 테이블이 없습니다');
    }

    private function materialCategoryRow(string $table, int $idx): ?array
    {
        if ($idx <= 0) {
            return null;
        }

        $select = ['c.' . $this->qi('idx') . ' AS idx'];
        if ($this->columnExists($table, 'parent_idx')) {
            $select[] = 'ISNULL(c.' . $this->qi('parent_idx') . ', 0) AS parent_idx';
        } else {
            $select[] = '0 AS parent_idx';
        }
        if ($this->columnExists($table, 'depth')) {
            $select[] = 'ISNULL(c.' . $this->qi('depth') . ', 0) AS depth';
        } else {
            $select[] = '0 AS depth';
        }

        $sql = 'SELECT TOP 1 ' . implode(', ', $select)
            . ' FROM ' . $table . ' c'
            . ' WHERE c.' . $this->qi('idx') . ' = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function materialCategoryDepthForParent(string $table, int $parentIdx): int
    {
        if (!$this->columnExists($table, 'depth')) {
            return 0;
        }
        if ($parentIdx <= 0) {
            return 0;
        }

        $sql = 'SELECT TOP 1 ISNULL(c.' . $this->qi('depth') . ', 0) AS depth'
            . ' FROM ' . $table . ' c'
            . ' WHERE c.' . $this->qi('idx') . ' = ?';
        if ($this->columnExists($table, 'is_deleted')) {
            $sql .= ' AND ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$parentIdx]);
        $parentDepth = (int)$stmt->fetchColumn();
        return $parentDepth + 1;
    }

    private function materialCategoryDescendantIds(string $table, int $rootIdx): array
    {
        if ($rootIdx <= 0 || !$this->columnExists($table, 'parent_idx')) {
            return [];
        }

        $found = [];
        $seen = [$rootIdx => true];
        $queue = [$rootIdx];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_int($current) || $current <= 0) {
                continue;
            }

            $sql = 'SELECT c.' . $this->qi('idx') . ' AS idx'
                . ' FROM ' . $table . ' c'
                . ' WHERE ISNULL(c.' . $this->qi('parent_idx') . ', 0) = ?';
            if ($this->columnExists($table, 'is_deleted')) {
                $sql .= ' AND ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$current]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $childIdx = (int)($row['idx'] ?? 0);
                if ($childIdx <= 0 || isset($seen[$childIdx])) {
                    continue;
                }
                $seen[$childIdx] = true;
                $found[] = $childIdx;
                $queue[] = $childIdx;
            }
        }

        return $found;
    }

    private function recalculateCategorySubtreeDepth(string $table, int $rootIdx, int $rootDepth, int $actorUserPk = 0): void
    {
        if ($rootIdx <= 0 || !$this->columnExists($table, 'depth') || !$this->columnExists($table, 'parent_idx')) {
            return;
        }

        $seen = [];
        $queue = [[$rootIdx, max(0, $rootDepth)]];

        while ($queue !== []) {
            $node = array_shift($queue);
            $nodeIdx = (int)($node[0] ?? 0);
            $depth = (int)($node[1] ?? 0);
            if ($nodeIdx <= 0 || isset($seen[$nodeIdx])) {
                continue;
            }
            $seen[$nodeIdx] = true;

            $setSql = [$this->qi('depth') . ' = ?'];
            $params = [$depth];
            if ($this->columnExists($table, 'updated_by')) {
                $setSql[] = $this->qi('updated_by') . ' = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists($table, 'updated_at')) {
                $setSql[] = $this->qi('updated_at') . ' = GETDATE()';
            }

            $params[] = $nodeIdx;
            $updateSql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setSql)
                . ' WHERE ' . $this->qi('idx') . ' = ?';
            if ($this->columnExists($table, 'is_deleted')) {
                $updateSql .= ' AND ISNULL(' . $this->qi('is_deleted') . ', 0) = 0';
            }

            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute($params);

            $childSql = 'SELECT c.' . $this->qi('idx') . ' AS idx'
                . ' FROM ' . $table . ' c'
                . ' WHERE ISNULL(c.' . $this->qi('parent_idx') . ', 0) = ?';
            if ($this->columnExists($table, 'is_deleted')) {
                $childSql .= ' AND ISNULL(c.' . $this->qi('is_deleted') . ', 0) = 0';
            }
            $childStmt = $this->db->prepare($childSql);
            $childStmt->execute([$nodeIdx]);
            $children = $childStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($children as $child) {
                $childIdx = (int)($child['idx'] ?? 0);
                if ($childIdx > 0 && !isset($seen[$childIdx])) {
                    $queue[] = [$childIdx, $depth + 1];
                }
            }
        }
    }

    private function logCategoryMoveHistory(
        string $table,
        int $catIdx,
        int $oldParentIdx,
        int $newParentIdx,
        ?int $oldDepth,
        ?int $newDepth,
        int $actorUserPk = 0
    ): void {
        try {
            $this->ensureItemHistoryTable();
            if (!$this->tableExists('Tb_ItemHistory')) {
                return;
            }

            $columns = ['item_idx', 'action'];
            $valuesSql = ['?', '?'];
            $params = [0, 'category_move_parent'];

            if ($this->columnExists('Tb_ItemHistory', 'changed_field')) {
                $columns[] = 'changed_field';
                $valuesSql[] = '?';
                $params[] = 'parent_idx';
            }

            $before = [
                'entity' => 'category',
                'table' => $table,
                'idx' => $catIdx,
                'parent_idx' => $oldParentIdx,
                'depth' => $oldDepth,
            ];
            $after = [
                'entity' => 'category',
                'table' => $table,
                'idx' => $catIdx,
                'parent_idx' => $newParentIdx,
                'depth' => $newDepth,
            ];

            if ($this->columnExists('Tb_ItemHistory', 'before_value')) {
                $columns[] = 'before_value';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($before);
            }
            if ($this->columnExists('Tb_ItemHistory', 'after_value')) {
                $columns[] = 'after_value';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($after);
            }
            if ($this->columnExists('Tb_ItemHistory', 'snapshot_json')) {
                $columns[] = 'snapshot_json';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($after);
            }
            if ($this->columnExists('Tb_ItemHistory', 'created_by')) {
                $columns[] = 'created_by';
                $valuesSql[] = '?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_ItemHistory', 'created_at')) {
                $columns[] = 'created_at';
                $valuesSql[] = 'GETDATE()';
            }
            if ($this->columnExists('Tb_ItemHistory', 'is_deleted')) {
                $columns[] = 'is_deleted';
                $valuesSql[] = '0';
            }

            $sql = 'INSERT INTO Tb_ItemHistory (' . implode(', ', $this->quoteColumns($columns)) . ')'
                . ' VALUES (' . implode(', ', $valuesSql) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            $this->logThrowable('logCategoryMoveHistory', $e, [
                'table' => $table,
                'cat_idx' => $catIdx,
                'old_parent_idx' => $oldParentIdx,
                'new_parent_idx' => $newParentIdx,
            ]);
        }
    }

    private function ensureItemHistoryTable(): void
    {
        if ($this->tableExists('Tb_ItemHistory')) {
            return;
        }

        $sql = "
IF OBJECT_ID(N'dbo.Tb_ItemHistory', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ItemHistory (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        item_idx INT NOT NULL,
        action NVARCHAR(30) NOT NULL,
        changed_field NVARCHAR(200) NULL,
        before_value NVARCHAR(MAX) NULL,
        after_value NVARCHAR(MAX) NULL,
        snapshot_json NVARCHAR(MAX) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ItemHistory_created_at DEFAULT (GETDATE()),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemHistory_is_deleted DEFAULT (0)
    );
END;
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemHistory')
      AND name = N'IX_Tb_ItemHistory_item_idx_created_at'
)
BEGIN
    CREATE INDEX IX_Tb_ItemHistory_item_idx_created_at
        ON dbo.Tb_ItemHistory(item_idx, created_at DESC, idx DESC);
END;
";
        $this->db->exec($sql);
        unset($this->tableExistsCache[strtolower('Tb_ItemHistory')]);
        unset($this->columnExistsCache[strtolower('Tb_ItemHistory.item_idx')]);
        unset($this->columnExistsCache[strtolower('Tb_ItemHistory.action')]);
    }

    private function loadHistoryRow(int $historyIdx): ?array
    {
        $where = ['h.' . $this->qi('idx') . ' = ?'];
        if ($this->columnExists('Tb_ItemHistory', 'is_deleted')) {
            $where[] = 'ISNULL(h.' . $this->qi('is_deleted') . ', 0) = 0';
        }

        $sql = "SELECT TOP 1
                    h." . $this->qi('idx') . " AS idx,
                    ISNULL(h." . $this->qi('item_idx') . ", 0) AS item_idx,
                    ISNULL(CONVERT(NVARCHAR(30), h." . $this->qi('action') . "), N'') AS action,
                    " . ($this->columnExists('Tb_ItemHistory', 'changed_field') ? "ISNULL(CONVERT(NVARCHAR(200), h." . $this->qi('changed_field') . "), N'')" : "CAST(N'' AS NVARCHAR(200))") . " AS changed_field,
                    " . ($this->columnExists('Tb_ItemHistory', 'before_value') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('before_value') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS before_value,
                    " . ($this->columnExists('Tb_ItemHistory', 'after_value') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('after_value') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS after_value,
                    " . ($this->columnExists('Tb_ItemHistory', 'snapshot_json') ? "ISNULL(CONVERT(NVARCHAR(MAX), h." . $this->qi('snapshot_json') . "), N'')" : "CAST(N'' AS NVARCHAR(MAX))") . " AS snapshot_json
                FROM Tb_ItemHistory h
                WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$historyIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function logItemHistory(
        int $itemIdx,
        string $action,
        ?string $changedField,
        $beforeValue,
        $afterValue,
        $snapshot,
        int $actorUserPk = 0
    ): void {
        if ($itemIdx <= 0 || trim($action) === '') {
            return;
        }

        try {
            $this->ensureItemHistoryTable();
            if (!$this->tableExists('Tb_ItemHistory')) {
                return;
            }

            $columns = ['item_idx', 'action'];
            $params = [$itemIdx, trim($action)];
            $valuesSql = ['?', '?'];

            if ($this->columnExists('Tb_ItemHistory', 'changed_field')) {
                $columns[] = 'changed_field';
                $valuesSql[] = '?';
                $params[] = $changedField;
            }
            if ($this->columnExists('Tb_ItemHistory', 'before_value')) {
                $columns[] = 'before_value';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($beforeValue);
            }
            if ($this->columnExists('Tb_ItemHistory', 'after_value')) {
                $columns[] = 'after_value';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($afterValue);
            }
            if ($this->columnExists('Tb_ItemHistory', 'snapshot_json')) {
                $columns[] = 'snapshot_json';
                $valuesSql[] = '?';
                $params[] = $this->historyValueJson($snapshot);
            }
            if ($this->columnExists('Tb_ItemHistory', 'created_by')) {
                $columns[] = 'created_by';
                $valuesSql[] = '?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_ItemHistory', 'created_at')) {
                $columns[] = 'created_at';
                $valuesSql[] = 'GETDATE()';
            }
            if ($this->columnExists('Tb_ItemHistory', 'is_deleted')) {
                $columns[] = 'is_deleted';
                $valuesSql[] = '0';
            }

            $sql = 'INSERT INTO Tb_ItemHistory (' . implode(', ', $this->quoteColumns($columns)) . ') VALUES (' . implode(', ', $valuesSql) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            $this->logThrowable('logItemHistory', $e, [
                'item_idx' => $itemIdx,
                'action' => $action,
            ]);
        }
    }

    private function historyValueJson($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return (string)$value;
        }
        return $json;
    }

    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function lastInsertId(): int
    {
        $stmt = $this->db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS new_idx');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return (int)($row['new_idx'] ?? 0);
    }

    private function tableExists(string $tableName): bool
    {
        $key = strtolower($tableName);
        if (array_key_exists($key, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$key];
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=?"
        );
        $stmt->execute([$tableName]);

        $exists = (int)$stmt->fetchColumn() > 0;
        $this->tableExistsCache[$key] = $exists;

        return $exists;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $key = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($key, $this->columnExistsCache)) {
            return (bool)$this->columnExistsCache[$key];
        }

        if (!$this->tableExists($tableName)) {
            $this->columnExistsCache[$key] = false;
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$tableName, $columnName]);

        $exists = (int)$stmt->fetchColumn() > 0;
        $this->columnExistsCache[$key] = $exists;

        return $exists;
    }

    private function logThrowable(string $scope, Throwable $e, array $context = []): void
    {
        $payload = [
            'scope' => $scope,
            'message' => $e->getMessage(),
            'context' => $context,
        ];
        error_log('[MaterialService] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
