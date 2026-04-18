<?php
declare(strict_types=1);

final class CategoryService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private ?string $categoryTableName = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function list(array $query): array
    {
        $table = $this->categoryTable();

        $search = trim((string)($query['search'] ?? ''));
        $includeDeleted = ((int)($query['include_deleted'] ?? 0)) === 1;
        $page = max(1, (int)($query['p'] ?? 1));
        $limit = min(500, max(1, (int)($query['limit'] ?? 200)));

        $hasParentFilter = array_key_exists('parent_idx', $query) || array_key_exists('parent', $query);
        $parentIdx = (int)($query['parent_idx'] ?? $query['parent'] ?? 0);
        $tabIdx = (int)($query['tab_idx'] ?? 0);

        $where = ['1=1'];
        $params = [];

        if (!$includeDeleted && $this->columnExists($table, 'is_deleted')) {
            $where[] = 'ISNULL(c.is_deleted, 0) = 0';
        }

        if ($hasParentFilter && $this->columnExists($table, 'parent_idx')) {
            $where[] = 'ISNULL(c.parent_idx, 0) = ?';
            $params[] = $parentIdx;
        }
        if ($tabIdx > 0 && $this->columnExists($table, 'tab_idx')) {
            $where[] = 'ISNULL(c.tab_idx, 0) = ?';
            $params[] = $tabIdx;
        }

        if ($search !== '' && $this->columnExists($table, 'name')) {
            $where[] = 'c.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} c {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;

        $orderParts = [];
        if ($this->columnExists($table, 'sort_order')) {
            $orderParts[] = 'ISNULL(c.sort_order, 0) ASC';
        }
        if ($this->columnExists($table, 'name')) {
            $orderParts[] = 'c.name ASC';
        }
        $orderParts[] = 'c.idx ASC';
        $orderSql = implode(', ', $orderParts);

        $listSql = "SELECT * FROM (
                        SELECT
                            c.*,
                            " . ($this->columnExists($table, 'parent_idx') ? 'ISNULL(c.parent_idx, 0)' : '0') . " AS parent_idx_safe,
                            ROW_NUMBER() OVER (ORDER BY {$orderSql}) AS rn
                        FROM {$table} c
                        {$whereSql}
                    ) z
                    WHERE z.rn BETWEEN ? AND ?";

        $stmt = $this->db->prepare($listSql);
        $stmt->execute(array_merge($params, [$rowFrom, $rowTo]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'table' => $table,
            'list' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
            'option_columns' => $this->categoryOptionColumns($table),
        ];
    }

    public function detail(int $idx): ?array
    {
        if ($idx <= 0) {
            return null;
        }

        $table = $this->categoryTable();
        $where = 'WHERE idx = ?';

        if ($this->columnExists($table, 'is_deleted')) {
            $where .= ' AND ISNULL(is_deleted, 0) = 0';
        }

        $stmt = $this->db->prepare("SELECT TOP 1 * FROM {$table} {$where}");
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $input, int $actorUserPk = 0): array
    {
        $table = $this->categoryTable();

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        $fieldTypes = $this->fieldTypeMap();
        $columns = [];
        $values = [];

        foreach ($fieldTypes as $field => $type) {
            if (!$this->columnExists($table, $field)) {
                continue;
            }

            if ($field === 'name') {
                $columns[] = $field;
                $values[] = $name;
                continue;
            }

            if (!array_key_exists($field, $input)) {
                continue;
            }

            $columns[] = $field;
            $values[] = $this->castByType($type, $input[$field]);
        }

        if ($this->columnExists($table, 'parent_idx') && !in_array('parent_idx', $columns, true)) {
            $columns[] = 'parent_idx';
            $values[] = (int)($input['parent_idx'] ?? 0);
        }

        if ($this->columnExists($table, 'sort_order') && !in_array('sort_order', $columns, true)) {
            $parentIdx = (int)($input['parent_idx'] ?? 0);
            $columns[] = 'sort_order';
            $values[] = $this->nextSortOrder($table, $parentIdx);
        }

        if ($this->columnExists($table, 'is_use') && !in_array('is_use', $columns, true)) {
            $columns[] = 'is_use';
            $values[] = 1;
        }

        if ($this->columnExists($table, 'is_active') && !in_array('is_active', $columns, true)) {
            $columns[] = 'is_active';
            $values[] = 1;
        }

        if ($this->columnExists($table, 'use_yn') && !in_array('use_yn', $columns, true)) {
            $columns[] = 'use_yn';
            $values[] = 'Y';
        }

        if ($this->columnExists($table, 'regdate') && !in_array('regdate', $columns, true)) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($this->columnExists($table, 'created_by') && !in_array('created_by', $columns, true) && $actorUserPk > 0) {
            $columns[] = 'created_by';
            $values[] = $actorUserPk;
        }

        if ($columns === []) {
            throw new RuntimeException('no writable columns for ' . $table);
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $this->quoteColumns($columns)) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        $newIdx = $this->lastInsertId();

        return [
            'table' => $table,
            'idx' => $newIdx,
            'name' => $name,
            'created_by' => $actorUserPk,
        ];
    }

    public function update(int $idx, array $input, int $actorUserPk = 0): array
    {
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        $table = $this->categoryTable();
        $fieldTypes = $this->fieldTypeMap();

        $setSql = [];
        $params = [];
        $changed = [];

        foreach ($fieldTypes as $field => $type) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (!$this->columnExists($table, $field)) {
                continue;
            }
            if ($field === 'idx') {
                continue;
            }

            $setSql[] = $this->qi($field) . ' = ?';
            $params[] = $this->castByType($type, $input[$field]);
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

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'table' => $table,
            'idx' => $idx,
            'updated_count' => (int)$stmt->rowCount(),
            'changed_fields' => $changed,
            'updated_by' => $actorUserPk,
        ];
    }

    public function deleteByIds(array $idxList, int $deletedBy = 0): array
    {
        if ($idxList === []) {
            throw new InvalidArgumentException('idx_list is required');
        }

        $table = $this->categoryTable();
        $placeholders = implode(',', array_fill(0, count($idxList), '?'));

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
            if ($this->columnExists($table, 'updated_at')) {
                $setSql[] = 'updated_at = GETDATE()';
            }

            $params = array_merge($params, $idxList);
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setSql) . " WHERE idx IN ({$placeholders})";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return [
                'table' => $table,
                'idx_list' => $idxList,
                'deleted_count' => (int)$stmt->rowCount(),
                'delete_mode' => 'soft',
            ];
        }

        $stmt = $this->db->prepare('DELETE FROM ' . $table . " WHERE idx IN ({$placeholders})");
        $stmt->execute($idxList);

        return [
            'table' => $table,
            'idx_list' => $idxList,
            'deleted_count' => (int)$stmt->rowCount(),
            'delete_mode' => 'hard',
        ];
    }

    public function reorder(array $orders, int $actorUserPk = 0): array
    {
        $table = $this->categoryTable();
        if ($orders === []) {
            throw new InvalidArgumentException('orders is required');
        }

        $updated = 0;

        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            $idx = (int)($row['idx'] ?? 0);
            if ($idx <= 0) {
                continue;
            }

            $setSql = [];
            $params = [];

            if ($this->columnExists($table, 'sort_order') && array_key_exists('sort_order', $row)) {
                $setSql[] = $this->qi('sort_order') . ' = ?';
                $params[] = (int)$row['sort_order'];
            }

            if ($this->columnExists($table, 'parent_idx') && array_key_exists('parent_idx', $row)) {
                $setSql[] = $this->qi('parent_idx') . ' = ?';
                $params[] = (int)$row['parent_idx'];
            }

            if ($setSql === []) {
                continue;
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
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $updated += (int)$stmt->rowCount();
        }

        return [
            'table' => $table,
            'updated_count' => $updated,
            'requested_count' => count($orders),
        ];
    }

    public function saveFolderOptions(int $idx, array $options, bool $inheritChildren, int $actorUserPk = 0): array
    {
        if ($idx <= 0) {
            throw new InvalidArgumentException('idx is required');
        }

        $table = $this->categoryTable();

        $targets = [$idx];
        if ($inheritChildren) {
            $targets = array_values(array_unique(array_merge($targets, $this->descendantIds($idx))));
        }

        $updatedCount = 0;
        foreach ($targets as $targetIdx) {
            $updatedCount += $this->applyFolderOptions($table, $targetIdx, $options, $actorUserPk);
        }

        return [
            'table' => $table,
            'root_idx' => $idx,
            'inherit_children' => $inheritChildren,
            'target_count' => count($targets),
            'updated_count' => $updatedCount,
        ];
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
            $parsed = preg_split('/[\s,]+/', trim((string)$idxInput)) ?: [];
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

    private function applyFolderOptions(string $table, int $idx, array $options, int $actorUserPk): int
    {
        $optionColumns = $this->categoryOptionColumns($table);
        $setSql = [];
        $params = [];

        if ($this->columnExists($table, 'option_val')) {
            $optionVal = (int)($options['option_val'] ?? 0);
            if ($optionVal < 0) {
                $optionVal = 0;
            }
            if ($optionVal > 10) {
                $optionVal = 10;
            }
            $setSql[] = $this->qi('option_val') . ' = ?';
            $params[] = $optionVal;
        }

        foreach ($optionColumns as $column) {
            if (!array_key_exists($column, $options)) {
                continue;
            }
            $setSql[] = $this->qi($column) . ' = ?';
            $params[] = trim((string)$options[$column]);
        }

        if ($this->columnExists($table, 'option_values_json') && array_key_exists('option_values_json', $options)) {
            $json = $options['option_values_json'];
            if (is_array($json)) {
                $json = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $setSql[] = $this->qi('option_values_json') . ' = ?';
            $params[] = trim((string)$json);
        }

        if ($setSql === []) {
            return 0;
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

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    private function descendantIds(int $rootIdx): array
    {
        $table = $this->categoryTable();
        if (!$this->columnExists($table, 'parent_idx')) {
            return [];
        }

        $all = [];
        $queue = [$rootIdx];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_int($current) || $current <= 0) {
                continue;
            }

            $stmt = $this->db->prepare('SELECT idx FROM ' . $table . ' WHERE parent_idx = ?');
            $stmt->execute([$current]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $childIdx = (int)($row['idx'] ?? 0);
                if ($childIdx <= 0 || in_array($childIdx, $all, true)) {
                    continue;
                }
                $all[] = $childIdx;
                $queue[] = $childIdx;
            }
        }

        return $all;
    }

    private function categoryOptionColumns(string $table): array
    {
        $columns = [];
        for ($i = 1; $i <= 10; $i++) {
            $name = 'option_' . $i;
            if ($this->columnExists($table, $name)) {
                $columns[] = $name;
            }
        }
        return $columns;
    }

    private function nextSortOrder(string $table, int $parentIdx): int
    {
        if (!$this->columnExists($table, 'sort_order')) {
            return 0;
        }

        if ($this->columnExists($table, 'parent_idx')) {
            $stmt = $this->db->prepare('SELECT ISNULL(MAX(sort_order), 0) + 1 AS next_sort FROM ' . $table . ' WHERE ISNULL(parent_idx,0)=?');
            $stmt->execute([$parentIdx]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->db->query('SELECT ISNULL(MAX(sort_order), 0) + 1 AS next_sort FROM ' . $table);
        return (int)$stmt->fetchColumn();
    }

    private function categoryTable(): string
    {
        if (is_string($this->categoryTableName)) {
            return $this->categoryTableName;
        }

        if ($this->tableExists('Tb_Category')) {
            $this->categoryTableName = 'Tb_Category';
            return $this->categoryTableName;
        }

        if ($this->tableExists('Tb_ItemCategory')) {
            $this->categoryTableName = 'Tb_ItemCategory';
            return $this->categoryTableName;
        }

        throw new RuntimeException('Tb_Category 또는 Tb_ItemCategory 테이블이 없습니다');
    }

    private function fieldTypeMap(): array
    {
        $map = [
            'name' => 'string',
            'parent_idx' => 'int_nullable',
            'sort_order' => 'int',
            'depth' => 'int_nullable',
            'memo' => 'string',
            'use_yn' => 'string',
            'is_use' => 'bool01',
            'is_active' => 'bool01',
            'code' => 'string',
            'cat_code' => 'string',
            'cat_type' => 'string',
            'option_val' => 'int',
            'option_values_json' => 'json',
        ];

        for ($i = 1; $i <= 10; $i++) {
            $map['option_' . $i] = 'string';
        }

        return $map;
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
            if ($text === '1' || $text === 'true' || $text === 'on' || $text === 'yes' || $text === 'y') {
                return 1;
            }
            return 0;
        }

        if ($type === 'json') {
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $text = trim((string)$value);
            if ($text === '') {
                return '{}';
            }
            return $text;
        }

        return trim((string)$value);
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
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME = ?"
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

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$tableName, $columnName]);

        $exists = (int)$stmt->fetchColumn() > 0;
        $this->columnExistsCache[$key] = $exists;

        return $exists;
    }
}
