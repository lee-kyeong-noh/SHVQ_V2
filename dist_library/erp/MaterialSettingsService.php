<?php
declare(strict_types=1);

final class MaterialSettingsService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTable();
        $this->ensureDefaults();
    }

    public function get(): array
    {
        $settings = $this->defaultSettings();

        $stmt = $this->db->query(
            "SELECT setting_key, setting_value, setting_type
             FROM Tb_MaterialSetting
             WHERE ISNULL(is_deleted, 0) = 0"
        );
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $row) {
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($key === '' || !array_key_exists($key, $settings)) {
                continue;
            }

            $type = trim((string)($row['setting_type'] ?? $this->settingTypeMap()[$key] ?? 'string'));
            $settings[$key] = $this->decodeByType($type, (string)($row['setting_value'] ?? ''));
        }

        if (!is_array($settings['pjt_items'])) {
            $settings['pjt_items'] = $this->defaultPjtItems();
        }
        if (!is_array($settings['category_option_labels'])) {
            $settings['category_option_labels'] = $this->defaultCategoryOptionLabels();
        }

        return [
            'settings' => $settings,
            'option_label_map_kr' => $this->optionLabelMapKorean(),
        ];
    }

    public function save(array $input, int $actorUserPk = 0): array
    {
        $types = $this->settingTypeMap();
        $allowed = array_keys($types);
        $updates = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $type = $types[$key];
            $value = $this->normalizeInputByType($type, $input[$key]);
            $updates[$key] = [
                'type' => $type,
                'value' => $value,
            ];
        }

        if ($updates === []) {
            throw new InvalidArgumentException('no writable setting key provided');
        }

        foreach ($updates as $key => $meta) {
            $this->upsertSetting($key, $meta['value'], $meta['type'], $actorUserPk);
        }

        return $this->get();
    }

    public function savePjtItems(array $items, int $actorUserPk = 0): array
    {
        $normalized = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string)($row['code'] ?? $row['key'] ?? $row['idx'] ?? ''));
            $name = trim((string)($row['name'] ?? $row['label'] ?? ''));
            $color = trim((string)($row['color'] ?? '#94a3b8'));

            if ($name === '') {
                continue;
            }
            if ($code === '') {
                $code = (string)(count($normalized) + 1);
            }

            $normalized[] = [
                'code' => $code,
                'name' => $name,
                'color' => $color,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('pjt_items is empty');
        }

        $this->upsertSetting(
            'pjt_items',
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'json',
            $actorUserPk
        );

        return $this->get();
    }

    public function saveCategoryOptionLabels(array $options, int $actorUserPk = 0): array
    {
        $default = $this->defaultCategoryOptionLabels();
        $normalized = [];

        foreach ($default as $idx => $name) {
            $value = trim((string)($options[(string)$idx] ?? $options[$idx] ?? $name));
            if ($value === '') {
                $value = $name;
            }
            $normalized[(string)$idx] = $value;
        }

        $this->upsertSetting(
            'category_option_labels',
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'json',
            $actorUserPk
        );

        return $this->get();
    }

    private function upsertSetting(string $key, string $value, string $type, int $actorUserPk): void
    {
        $existsStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM Tb_MaterialSetting WHERE setting_key = ?'
        );
        $existsStmt->execute([$key]);
        $exists = (int)$existsStmt->fetchColumn() > 0;

        if ($exists) {
            $setSql = [
                'setting_value = ?',
                'setting_type = ?',
                'is_deleted = 0',
            ];
            $params = [$value, $type];

            if ($this->columnExists('Tb_MaterialSetting', 'updated_by')) {
                $setSql[] = 'updated_by = ?';
                $params[] = max(0, $actorUserPk);
            }
            if ($this->columnExists('Tb_MaterialSetting', 'updated_at')) {
                $setSql[] = 'updated_at = GETDATE()';
            }

            $params[] = $key;
            $sql = 'UPDATE Tb_MaterialSetting SET ' . implode(', ', $setSql) . ' WHERE setting_key = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return;
        }

        $columns = ['setting_key', 'setting_value', 'setting_type'];
        $values = [$key, $value, $type];

        if ($this->columnExists('Tb_MaterialSetting', 'updated_by')) {
            $columns[] = 'updated_by';
            $values[] = max(0, $actorUserPk);
        }

        $sql = 'INSERT INTO Tb_MaterialSetting (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    private function normalizeInputByType(string $type, $value): string
    {
        if ($type === 'int') {
            return (string)(int)$value;
        }

        if ($type === 'bool01') {
            $text = strtolower(trim((string)$value));
            $truthy = ['1', 'true', 'on', 'yes', 'y'];
            return in_array($text, $truthy, true) ? '1' : '0';
        }

        if ($type === 'json') {
            if (is_array($value)) {
                return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $text = trim((string)$value);
            if ($text === '') {
                return '{}';
            }

            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            throw new InvalidArgumentException('invalid json value');
        }

        return trim((string)$value);
    }

    private function decodeByType(string $type, string $value)
    {
        if ($type === 'int') {
            return (int)$value;
        }

        if ($type === 'bool01') {
            return ((int)$value) === 1 ? 1 : 0;
        }

        if ($type === 'json') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $value;
    }

    private function defaultSettings(): array
    {
        return [
            'material_no_prefix' => 'MAT',
            'material_no_format' => 'MAT-[TAB]-[YYMM]-[SEQ]',
            'material_no_seq_len' => 4,
            'barcode_format' => '{CAT}-{TYPE}-{DATE}+{EMP}{SEQ}',
            'barcode_cat_len' => 4,
            'barcode_seq_len' => 3,
            'item_category_max_depth' => 4,
            'pjt_items' => $this->defaultPjtItems(),
            'category_option_labels' => $this->defaultCategoryOptionLabels(),
        ];
    }

    private function settingTypeMap(): array
    {
        return [
            'material_no_prefix' => 'string',
            'material_no_format' => 'string',
            'material_no_seq_len' => 'int',
            'barcode_format' => 'string',
            'barcode_cat_len' => 'int',
            'barcode_seq_len' => 'int',
            'item_category_max_depth' => 'int',
            'pjt_items' => 'json',
            'category_option_labels' => 'json',
        ];
    }

    private function defaultPjtItems(): array
    {
        return [
            ['code' => '1', 'name' => '표준', 'color' => '#3b82f6'],
            ['code' => '2', 'name' => '비표준', 'color' => '#10b981'],
            ['code' => '3', 'name' => '보수', 'color' => '#f59e0b'],
            ['code' => '4', 'name' => '리모델링', 'color' => '#ef4444'],
        ];
    }

    private function defaultCategoryOptionLabels(): array
    {
        return [
            '1' => '수도권',
            '2' => '동부권',
            '3' => '서부권',
            '4' => '남부권',
            '5' => '북부권',
            '6' => '충청권',
            '7' => '전라권',
            '8' => '경상권',
            '9' => '강원권',
            '10' => '제주권',
        ];
    }

    private function optionLabelMapKorean(): array
    {
        return [
            '1' => '옵션1',
            '2' => '옵션2',
            '3' => '옵션3',
            '4' => '옵션4',
            '5' => '옵션5',
            '6' => '옵션6',
            '7' => '옵션7',
            '8' => '옵션8',
            '9' => '옵션9',
            '10' => '옵션10',
        ];
    }

    private function ensureDefaults(): void
    {
        $types = $this->settingTypeMap();
        $defaults = $this->defaultSettings();

        foreach ($defaults as $key => $value) {
            $type = $types[$key] ?? 'string';
            $encoded = $type === 'json'
                ? (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$value;

            $existsStmt = $this->db->prepare('SELECT COUNT(*) FROM Tb_MaterialSetting WHERE setting_key = ?');
            $existsStmt->execute([$key]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                continue;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO Tb_MaterialSetting (setting_key, setting_value, setting_type) VALUES (?, ?, ?)'
            );
            $stmt->execute([$key, $encoded, $type]);
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableExists('Tb_MaterialSetting')) {
            $this->ensureMissingColumns();
            return;
        }

        $sql = "CREATE TABLE Tb_MaterialSetting (
            idx INT IDENTITY(1,1) PRIMARY KEY,
            setting_key NVARCHAR(120) NOT NULL,
            setting_value NVARCHAR(MAX) NOT NULL DEFAULT '',
            setting_type NVARCHAR(20) NOT NULL DEFAULT 'string',
            updated_by INT NOT NULL DEFAULT 0,
            is_deleted BIT NOT NULL DEFAULT 0,
            regdate DATETIME NOT NULL DEFAULT GETDATE(),
            updated_at DATETIME NULL
        )";
        $this->db->exec($sql);
        $this->db->exec('CREATE UNIQUE INDEX UX_Tb_MaterialSetting_Key ON Tb_MaterialSetting(setting_key)');

        $this->tableExistsCache['tb_materialsetting'] = true;
    }

    private function ensureMissingColumns(): void
    {
        $columns = [
            'setting_type' => "ALTER TABLE Tb_MaterialSetting ADD setting_type NVARCHAR(20) NOT NULL DEFAULT 'string'",
            'updated_by' => 'ALTER TABLE Tb_MaterialSetting ADD updated_by INT NOT NULL DEFAULT 0',
            'is_deleted' => 'ALTER TABLE Tb_MaterialSetting ADD is_deleted BIT NOT NULL DEFAULT 0',
            'updated_at' => 'ALTER TABLE Tb_MaterialSetting ADD updated_at DATETIME NULL',
        ];

        foreach ($columns as $column => $ddl) {
            if ($this->columnExists('Tb_MaterialSetting', $column)) {
                continue;
            }
            $this->db->exec($ddl);
            $this->columnExistsCache[strtolower('Tb_MaterialSetting.' . $column)] = true;
        }

        try {
            $this->db->exec('CREATE UNIQUE INDEX UX_Tb_MaterialSetting_Key ON Tb_MaterialSetting(setting_key)');
        } catch (Throwable $e) {
            $this->logThrowable('ensureMissingColumns.create_unique_index', $e);
        }
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
        error_log('[MaterialSettingsService] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
