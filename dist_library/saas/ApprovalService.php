<?php
declare(strict_types=1);

final class ApprovalService
{
    private PDO $db;
    private array $security;
    private AuditLogger $audit;

    private array $tableExistsCache = [];
    private array $columnExistsCache = [];
    private array $userNameCache = [];

    private const TABLE_DOC = 'Tb_ApprDoc';
    private const TABLE_LINE = 'Tb_ApprLine';
    private const TABLE_PRESET = 'Tb_ApprLinePreset';

    private const ROLE_APPROVER_MAX_IDX = 3;
    private const DOC_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'RECALLED', 'CANCELED'];
    private const DOC_TYPES = ['GENERAL', 'OFFICIAL'];
    private const LINE_TYPE_APPROVER = 'APPROVER';
    private const LINE_TYPE_REFERENCE = 'REFERENCE';

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
        $this->audit = new AuditLogger($db, $security);
    }

    public function resolveScope(array $context, string $serviceCode = '', int $tenantId = 0): array
    {
        $roleLevel = (int)($context['role_level'] ?? 0);

        $resolvedServiceCode = trim($serviceCode);
        if ($resolvedServiceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
            $resolvedServiceCode = trim((string)($context['service_code'] ?? 'shvq'));
            if ($resolvedServiceCode === '') {
                $resolvedServiceCode = 'shvq';
            }
        }

        $resolvedTenantId = $tenantId;
        if ($resolvedTenantId <= 0 || $roleLevel < 1 || $roleLevel > 4) {
            $resolvedTenantId = (int)($context['tenant_id'] ?? 0);
        }

        return [
            'service_code' => $resolvedServiceCode,
            'tenant_id' => max(0, $resolvedTenantId),
        ];
    }

    public function requiredTables(): array
    {
        return [
            self::TABLE_DOC,
            self::TABLE_LINE,
            self::TABLE_PRESET,
        ];
    }

    public function missingTables(array $tables = []): array
    {
        if ($tables === []) {
            $tables = $this->requiredTables();
        }

        $missing = [];
        foreach ($tables as $table) {
            if (!is_string($table) || trim($table) === '') {
                continue;
            }
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return array_values(array_unique($missing));
    }

    public function docList(array $scope, int $userPk, int $roleLevel, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_DOC) || !$this->tableExists(self::TABLE_LINE)) {
            return [];
        }

        $where = [
            'd.service_code = ?',
            'd.tenant_id = ?',
            'ISNULL(d.is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $tab = strtolower(trim((string)($filters['tab'] ?? '')));
        if ($tab === 'pending') {
            $where[] = "ISNULL(d.status, 'DRAFT') = 'SUBMITTED'";
            $where[] = "EXISTS (
                SELECT 1
                FROM dbo." . $this->qi(self::TABLE_LINE) . " lp
                WHERE lp.doc_idx = d.idx
                  AND lp.service_code = d.service_code
                  AND lp.tenant_id = d.tenant_id
                  AND ISNULL(lp.is_deleted, 0) = 0
                  AND UPPER(ISNULL(lp.line_type, 'APPROVER')) = 'APPROVER'
                  AND ISNULL(lp.line_order, 0) = ISNULL(d.current_line_order, 0)
                  AND ISNULL(lp.decision_status, 'PENDING') = 'PENDING'
                  AND lp.actor_user_idx = ?
            )";
            $params[] = $userPk;
        } elseif ($tab === 'done') {
            $where[] = "EXISTS (
                SELECT 1
                FROM dbo." . $this->qi(self::TABLE_LINE) . " ld
                WHERE ld.doc_idx = d.idx
                  AND ld.service_code = d.service_code
                  AND ld.tenant_id = d.tenant_id
                  AND ISNULL(ld.is_deleted, 0) = 0
                  AND UPPER(ISNULL(ld.line_type, 'APPROVER')) = 'APPROVER'
                  AND ld.actor_user_idx = ?
                  AND UPPER(ISNULL(ld.decision_status, 'PENDING')) IN ('APPROVED', 'REJECTED')
            )";
            $params[] = $userPk;
        } elseif ($tab === 'reference' || $tab === 'ref') {
            $where[] = "EXISTS (
                SELECT 1
                FROM dbo." . $this->qi(self::TABLE_LINE) . " lr
                WHERE lr.doc_idx = d.idx
                  AND lr.service_code = d.service_code
                  AND lr.tenant_id = d.tenant_id
                  AND ISNULL(lr.is_deleted, 0) = 0
                  AND UPPER(ISNULL(lr.line_type, 'REFERENCE')) = 'REFERENCE'
                  AND lr.actor_user_idx = ?
            )";
            $params[] = $userPk;
            $where[] = "UPPER(ISNULL(d.status, 'DRAFT')) IN ('SUBMITTED', 'APPROVED', 'REJECTED', 'RECALLED')";
        } elseif ($tab === 'draft') {
            $where[] = 'ISNULL(d.writer_user_idx, 0) = ?';
            $where[] = "UPPER(ISNULL(d.status, 'DRAFT')) IN ('DRAFT', 'REJECTED', 'RECALLED')";
            $params[] = $userPk;
        } elseif (!$this->isApproverRole($roleLevel)) {
            $where[] = "(ISNULL(d.writer_user_idx, 0) = ? OR EXISTS (
                SELECT 1
                FROM dbo." . $this->qi(self::TABLE_LINE) . " la
                WHERE la.doc_idx = d.idx
                  AND la.service_code = d.service_code
                  AND la.tenant_id = d.tenant_id
                  AND ISNULL(la.is_deleted, 0) = 0
                  AND la.actor_user_idx = ?
            ))";
            $params[] = $userPk;
            $params[] = $userPk;
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '' && in_array($status, self::DOC_STATUSES, true)) {
            $where[] = "UPPER(ISNULL(d.status, 'DRAFT')) = ?";
            $params[] = $status;
        }

        $docType = strtoupper(trim((string)($filters['doc_type'] ?? '')));
        if ($docType !== '' && in_array($docType, self::DOC_TYPES, true)) {
            $where[] = "UPPER(ISNULL(d.doc_type, 'GENERAL')) = ?";
            $params[] = $docType;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.doc_no LIKE ?)';
            $sp = '%' . $search . '%';
            $params[] = $sp;
            $params[] = $sp;
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 200)));
        $sql = "SELECT TOP {$limit}
                    d.idx,
                    ISNULL(d.doc_no, '') AS doc_no,
                    ISNULL(d.doc_type, 'GENERAL') AS doc_type,
                    ISNULL(d.title, '') AS title,
                    ISNULL(d.writer_user_idx, 0) AS writer_user_idx,
                    ISNULL(d.status, 'DRAFT') AS status,
                    ISNULL(d.current_line_order, 0) AS current_line_order,
                    d.submitted_at,
                    d.completed_at,
                    d.recalled_at,
                    d.created_at,
                    d.updated_at,
                    ISNULL((
                        SELECT TOP 1 lcur.actor_user_idx
                        FROM dbo." . $this->qi(self::TABLE_LINE) . " lcur
                        WHERE lcur.doc_idx = d.idx
                          AND lcur.service_code = d.service_code
                          AND lcur.tenant_id = d.tenant_id
                          AND ISNULL(lcur.is_deleted, 0) = 0
                          AND UPPER(ISNULL(lcur.line_type, 'APPROVER')) = 'APPROVER'
                          AND ISNULL(lcur.line_order, 0) = ISNULL(d.current_line_order, 0)
                        ORDER BY lcur.idx ASC
                    ), 0) AS current_approver_user_idx
                FROM dbo." . $this->qi(self::TABLE_DOC) . " d
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ISNULL(d.updated_at, d.created_at) DESC, d.idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['writer_name'] = $this->resolveUserDisplayName((int)($row['writer_user_idx'] ?? 0));
            $row['current_approver_name'] = $this->resolveUserDisplayName((int)($row['current_approver_user_idx'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function docDetail(array $scope, int $docId, int $userPk, int $roleLevel): ?array
    {
        if ($docId <= 0 || !$this->tableExists(self::TABLE_DOC)) {
            return null;
        }

        $doc = $this->loadDocRow($scope, $docId);
        if ($doc === null) {
            return null;
        }

        $writerUserPk = (int)($doc['writer_user_idx'] ?? 0);
        if (!$this->canAccessDoc($scope, $docId, $userPk, $roleLevel, $writerUserPk)) {
            return null;
        }

        $lineSql = "SELECT
                        idx,
                        doc_idx,
                        ISNULL(line_type, 'APPROVER') AS line_type,
                        ISNULL(line_order, 0) AS line_order,
                        ISNULL(actor_user_idx, 0) AS actor_user_idx,
                        ISNULL(decision_status, 'PENDING') AS decision_status,
                        decided_at,
                        ISNULL(decision_comment, '') AS decision_comment,
                        created_at,
                        updated_at
                    FROM dbo." . $this->qi(self::TABLE_LINE) . "
                    WHERE doc_idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0
                    ORDER BY
                        CASE WHEN UPPER(ISNULL(line_type, 'APPROVER')) = 'APPROVER' THEN 0 ELSE 1 END ASC,
                        line_order ASC,
                        idx ASC";
        $lineStmt = $this->db->prepare($lineSql);
        $lineStmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($lines)) {
            $lines = [];
        }

        $approverLines = [];
        $referenceLines = [];
        foreach ($lines as $line) {
            $line['actor_name'] = $this->resolveUserDisplayName((int)($line['actor_user_idx'] ?? 0));
            $lineType = strtoupper(trim((string)($line['line_type'] ?? self::LINE_TYPE_APPROVER)));
            if ($lineType === self::LINE_TYPE_REFERENCE) {
                $referenceLines[] = $line;
            } else {
                $approverLines[] = $line;
            }
        }

        $doc['writer_name'] = $this->resolveUserDisplayName($writerUserPk);
        $doc['lines'] = $lines;
        $doc['approver_lines'] = $approverLines;
        $doc['reference_lines'] = $referenceLines;

        return $doc;
    }

    public function docSave(array $scope, int $actorUserPk, int $roleLevel, array $payload): array
    {
        $docId = (int)($payload['doc_id'] ?? $payload['idx'] ?? 0);
        $docType = $this->normalizeDocType((string)($payload['doc_type'] ?? 'GENERAL'));
        $title = trim((string)($payload['title'] ?? ''));
        $bodyHtml = trim((string)($payload['body_html'] ?? $payload['content'] ?? $payload['body'] ?? ''));
        $bodyText = trim((string)($payload['body_text'] ?? ''));
        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim((string)preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)));
        }

        $approvers = $this->parseIntList($payload['approver_user_ids'] ?? $payload['approver_ids'] ?? []);
        $references = $this->parseIntList(
            $payload['reference_user_ids'] ?? $payload['ref_user_ids'] ?? $payload['reference_ids'] ?? []
        );

        if ($title === '') {
            throw new InvalidArgumentException('title is required');
        }
        if ($approvers === []) {
            throw new InvalidArgumentException('approver_user_ids is required');
        }

        $referenceMap = [];
        foreach ($references as $referenceUserPk) {
            if ($referenceUserPk <= 0 || in_array($referenceUserPk, $approvers, true)) {
                continue;
            }
            $referenceMap[$referenceUserPk] = true;
        }
        $references = array_map('intval', array_keys($referenceMap));

        try {
            $this->db->beginTransaction();

            if ($docId > 0) {
                $doc = $this->loadDocRow($scope, $docId);
                if ($doc === null) {
                    throw new RuntimeException('approval doc not found');
                }
                if (!$this->canWriteDoc((int)($doc['writer_user_idx'] ?? 0), $actorUserPk, $roleLevel)) {
                    throw new RuntimeException('insufficient role level');
                }

                $status = strtoupper(trim((string)($doc['status'] ?? 'DRAFT')));
                if (!in_array($status, ['DRAFT', 'REJECTED', 'RECALLED'], true)) {
                    throw new RuntimeException('approval doc is not editable');
                }

                $updateSql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                              SET doc_type = ?,
                                  title = ?,
                                  body_html = ?,
                                  body_text = ?,
                                  status = 'DRAFT',
                                  current_line_order = NULL,
                                  submitted_at = NULL,
                                  completed_at = NULL,
                                  recalled_at = NULL,
                                  updated_by = ?,
                                  updated_at = GETDATE()
                              WHERE idx = ?
                                AND service_code = ?
                                AND tenant_id = ?
                                AND ISNULL(is_deleted, 0) = 0";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([
                    $docType,
                    $title,
                    $bodyHtml,
                    $bodyText,
                    $actorUserPk > 0 ? $actorUserPk : null,
                    $docId,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                ]);

                $this->replaceDocLines($scope, $docId, $approvers, $references, $actorUserPk);
            } else {
                $docNo = $this->nextDocNo($scope);
                $insertSql = "INSERT INTO dbo." . $this->qi(self::TABLE_DOC) . "
                              (service_code, tenant_id, doc_no, doc_type, title, body_html, body_text, writer_user_idx, status, current_line_order, submitted_at, completed_at, recalled_at, is_deleted, created_by, updated_by, created_at, updated_at)
                              OUTPUT INSERTED.idx
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', NULL, NULL, NULL, NULL, 0, ?, ?, GETDATE(), GETDATE())";
                $insertStmt = $this->db->prepare($insertSql);
                $insertStmt->execute([
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                    $docNo,
                    $docType,
                    $title,
                    $bodyHtml,
                    $bodyText,
                    $actorUserPk,
                    $actorUserPk > 0 ? $actorUserPk : null,
                    $actorUserPk > 0 ? $actorUserPk : null,
                ]);
                $row = $insertStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $docId = (int)($row['idx'] ?? 0);
                if ($docId <= 0) {
                    throw new RuntimeException('approval doc insert failed');
                }

                $this->replaceDocLines($scope, $docId, $approvers, $references, $actorUserPk);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.appr.doc_save', $actorUserPk, 'OK', 'Approval document saved', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
        ]);

        $saved = $this->docDetail($scope, $docId, $actorUserPk, $roleLevel);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    public function docSubmit(array $scope, int $docId, int $actorUserPk, int $roleLevel): array
    {
        $doc = $this->loadDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }
        if (!$this->canWriteDoc((int)($doc['writer_user_idx'] ?? 0), $actorUserPk, $roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $status = strtoupper(trim((string)($doc['status'] ?? 'DRAFT')));
        if (!in_array($status, ['DRAFT', 'REJECTED', 'RECALLED'], true)) {
            throw new RuntimeException('approval doc status is not submittable');
        }

        $firstLine = $this->loadNextPendingApproverLine($scope, $docId);
        if ($firstLine === null) {
            throw new RuntimeException('approval approver line missing');
        }

        $sql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                SET status = 'SUBMITTED',
                    current_line_order = ?,
                    submitted_at = GETDATE(),
                    completed_at = NULL,
                    recalled_at = NULL,
                    updated_by = ?,
                    updated_at = GETDATE()
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (int)($firstLine['line_order'] ?? 1),
            $actorUserPk > 0 ? $actorUserPk : null,
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $this->audit->log('groupware.appr.doc_submit', $actorUserPk, 'OK', 'Approval document submitted', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
        ]);

        $saved = $this->docDetail($scope, $docId, $actorUserPk, $roleLevel);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    public function docRecall(array $scope, int $docId, int $actorUserPk, int $roleLevel): array
    {
        $doc = $this->loadDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }
        if (!$this->canWriteDoc((int)($doc['writer_user_idx'] ?? 0), $actorUserPk, $roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $status = strtoupper(trim((string)($doc['status'] ?? 'DRAFT')));
        if ($status !== 'SUBMITTED') {
            throw new RuntimeException('approval doc is not recallable');
        }

        try {
            $this->db->beginTransaction();

            $lineSql = "UPDATE dbo." . $this->qi(self::TABLE_LINE) . "
                        SET decision_status = CASE
                                WHEN UPPER(ISNULL(line_type, 'APPROVER')) = 'APPROVER' THEN 'PENDING'
                                ELSE 'REFERENCE'
                            END,
                            decided_at = NULL,
                            decision_comment = '',
                            updated_by = ?,
                            updated_at = GETDATE()
                        WHERE doc_idx = ?
                          AND service_code = ?
                          AND tenant_id = ?
                          AND ISNULL(is_deleted, 0) = 0";
            $lineStmt = $this->db->prepare($lineSql);
            $lineStmt->execute([
                $actorUserPk > 0 ? $actorUserPk : null,
                $docId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            $docSql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                       SET status = 'RECALLED',
                           current_line_order = NULL,
                           recalled_at = GETDATE(),
                           completed_at = GETDATE(),
                           updated_by = ?,
                           updated_at = GETDATE()
                       WHERE idx = ?
                         AND service_code = ?
                         AND tenant_id = ?
                         AND ISNULL(is_deleted, 0) = 0";
            $docStmt = $this->db->prepare($docSql);
            $docStmt->execute([
                $actorUserPk > 0 ? $actorUserPk : null,
                $docId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.appr.doc_recall', $actorUserPk, 'OK', 'Approval document recalled', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
        ]);

        $saved = $this->docDetail($scope, $docId, $actorUserPk, $roleLevel);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    public function lineApprove(array $scope, int $docId, int $actorUserPk, int $roleLevel, string $comment = ''): array
    {
        return $this->lineAction($scope, $docId, $actorUserPk, $roleLevel, 'approve', $comment);
    }

    public function lineReject(array $scope, int $docId, int $actorUserPk, int $roleLevel, string $comment = ''): array
    {
        return $this->lineAction($scope, $docId, $actorUserPk, $roleLevel, 'reject', $comment);
    }

    public function presetList(array $scope, int $userPk, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE_PRESET)) {
            return [];
        }

        $where = [
            'service_code = ?',
            'tenant_id = ?',
            'ISNULL(is_deleted, 0) = 0',
        ];
        $params = [
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ];

        $includeShared = $this->toBoolInt($filters['include_shared'] ?? 1) === 1;
        if ($includeShared) {
            $where[] = '(ISNULL(created_by, 0) = ? OR ISNULL(is_shared, 0) = 1)';
        } else {
            $where[] = 'ISNULL(created_by, 0) = ?';
        }
        $params[] = $userPk;

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'preset_name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $limit = min(300, max(1, (int)($filters['limit'] ?? 100)));
        $sql = "SELECT TOP {$limit}
                    idx,
                    ISNULL(preset_name, '') AS preset_name,
                    ISNULL(doc_type, 'GENERAL') AS doc_type,
                    ISNULL(line_json, '{}') AS line_json,
                    CAST(ISNULL(is_shared, 0) AS INT) AS is_shared,
                    ISNULL(created_by, 0) AS created_by,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_PRESET) . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ISNULL(updated_at, created_at) DESC, idx DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $decoded = $this->decodePresetJson((string)($row['line_json'] ?? '{}'));
            $row['approver_user_ids'] = $decoded['approver_user_ids'];
            $row['reference_user_ids'] = $decoded['reference_user_ids'];
            $row['created_by_name'] = $this->resolveUserDisplayName((int)($row['created_by'] ?? 0));
        }
        unset($row);

        return $rows;
    }

    public function presetSave(array $scope, int $actorUserPk, int $roleLevel, array $payload): array
    {
        $presetId = (int)($payload['preset_id'] ?? $payload['idx'] ?? 0);
        $presetName = trim((string)($payload['preset_name'] ?? $payload['name'] ?? ''));
        $docType = $this->normalizeDocType((string)($payload['doc_type'] ?? 'GENERAL'));
        $isShared = $this->toBoolInt($payload['is_shared'] ?? 0);
        $approvers = $this->parseIntList($payload['approver_user_ids'] ?? $payload['approver_ids'] ?? []);
        $references = $this->parseIntList(
            $payload['reference_user_ids'] ?? $payload['reference_ids'] ?? $payload['ref_user_ids'] ?? []
        );

        if ($presetName === '') {
            throw new InvalidArgumentException('preset_name is required');
        }
        if ($approvers === []) {
            throw new InvalidArgumentException('approver_user_ids is required');
        }

        $referenceMap = [];
        foreach ($references as $referenceUserPk) {
            if ($referenceUserPk <= 0 || in_array($referenceUserPk, $approvers, true)) {
                continue;
            }
            $referenceMap[$referenceUserPk] = true;
        }
        $references = array_map('intval', array_keys($referenceMap));
        $lineJson = $this->buildPresetJson($approvers, $references);

        if ($presetId > 0) {
            $existing = $this->getPresetRow($scope, $presetId);
            if ($existing === null) {
                throw new RuntimeException('approval preset not found');
            }
            $ownerUserPk = (int)($existing['created_by'] ?? 0);
            if ($ownerUserPk !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
                throw new RuntimeException('insufficient role level');
            }

            $sql = "UPDATE dbo." . $this->qi(self::TABLE_PRESET) . "
                    SET preset_name = ?,
                        doc_type = ?,
                        line_json = ?,
                        is_shared = ?,
                        updated_by = ?,
                        updated_at = GETDATE()
                    WHERE idx = ?
                      AND service_code = ?
                      AND tenant_id = ?
                      AND ISNULL(is_deleted, 0) = 0";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $presetName,
                $docType,
                $lineJson,
                $isShared,
                $actorUserPk > 0 ? $actorUserPk : null,
                $presetId,
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);
        } else {
            $sql = "INSERT INTO dbo." . $this->qi(self::TABLE_PRESET) . "
                    (service_code, tenant_id, preset_name, doc_type, line_json, is_shared, is_deleted, created_by, updated_by, created_at, updated_at)
                    OUTPUT INSERTED.idx
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, GETDATE(), GETDATE())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $presetName,
                $docType,
                $lineJson,
                $isShared,
                $actorUserPk > 0 ? $actorUserPk : null,
                $actorUserPk > 0 ? $actorUserPk : null,
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $presetId = (int)($row['idx'] ?? 0);
            if ($presetId <= 0) {
                throw new RuntimeException('approval preset insert failed');
            }
        }

        $saved = $this->getPresetRow($scope, $presetId);
        if ($saved === null) {
            throw new RuntimeException('approval preset reload failed');
        }

        $decoded = $this->decodePresetJson((string)($saved['line_json'] ?? '{}'));
        $saved['approver_user_ids'] = $decoded['approver_user_ids'];
        $saved['reference_user_ids'] = $decoded['reference_user_ids'];
        $saved['created_by_name'] = $this->resolveUserDisplayName((int)($saved['created_by'] ?? 0));

        $this->audit->log('groupware.appr.preset_save', $actorUserPk, 'OK', 'Approval preset saved', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'preset_id' => $presetId,
        ]);

        return $saved;
    }

    private function lineAction(
        array $scope,
        int $docId,
        int $actorUserPk,
        int $roleLevel,
        string $action,
        string $comment
    ): array {
        $action = strtolower(trim($action));
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('invalid line action');
        }

        $doc = $this->loadDocRow($scope, $docId);
        if ($doc === null) {
            throw new RuntimeException('approval doc not found');
        }

        $status = strtoupper(trim((string)($doc['status'] ?? 'DRAFT')));
        if ($status !== 'SUBMITTED') {
            throw new RuntimeException('approval doc is not in SUBMITTED status');
        }

        $currentLineOrder = (int)($doc['current_line_order'] ?? 0);
        if ($currentLineOrder <= 0) {
            throw new RuntimeException('approval current line is invalid');
        }

        $currentLine = $this->loadCurrentPendingApproverLine($scope, $docId, $currentLineOrder);
        if ($currentLine === null) {
            throw new RuntimeException('approval line order mismatch');
        }

        $lineApproverPk = (int)($currentLine['actor_user_idx'] ?? 0);
        if ($lineApproverPk !== $actorUserPk && !$this->isApproverRole($roleLevel)) {
            throw new RuntimeException('insufficient role level');
        }

        $decision = strtoupper($action === 'approve' ? 'APPROVED' : 'REJECTED');
        $comment = trim($comment);
        if ($decision === 'REJECTED' && $comment === '') {
            throw new InvalidArgumentException('comment is required for reject');
        }

        try {
            $this->db->beginTransaction();

            $lineSql = "UPDATE dbo." . $this->qi(self::TABLE_LINE) . "
                        SET decision_status = ?,
                            decided_at = GETDATE(),
                            decision_comment = ?,
                            updated_by = ?,
                            updated_at = GETDATE()
                        WHERE idx = ?
                          AND service_code = ?
                          AND tenant_id = ?
                          AND ISNULL(is_deleted, 0) = 0
                          AND UPPER(ISNULL(line_type, 'APPROVER')) = 'APPROVER'
                          AND ISNULL(decision_status, 'PENDING') = 'PENDING'";
            $lineStmt = $this->db->prepare($lineSql);
            $lineStmt->execute([
                $decision,
                $comment,
                $actorUserPk > 0 ? $actorUserPk : null,
                (int)($currentLine['idx'] ?? 0),
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
            ]);
            if ($lineStmt->rowCount() <= 0) {
                throw new RuntimeException('approval line already processed');
            }

            if ($decision === 'REJECTED') {
                $docSql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                           SET status = 'REJECTED',
                               current_line_order = NULL,
                               completed_at = GETDATE(),
                               updated_by = ?,
                               updated_at = GETDATE()
                           WHERE idx = ?
                             AND service_code = ?
                             AND tenant_id = ?
                             AND ISNULL(is_deleted, 0) = 0";
                $docStmt = $this->db->prepare($docSql);
                $docStmt->execute([
                    $actorUserPk > 0 ? $actorUserPk : null,
                    $docId,
                    (string)$scope['service_code'],
                    (int)$scope['tenant_id'],
                ]);
            } else {
                $nextLine = $this->loadNextPendingApproverLine($scope, $docId);
                if ($nextLine === null) {
                    $docSql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                               SET status = 'APPROVED',
                                   current_line_order = NULL,
                                   completed_at = GETDATE(),
                                   updated_by = ?,
                                   updated_at = GETDATE()
                               WHERE idx = ?
                                 AND service_code = ?
                                 AND tenant_id = ?
                                 AND ISNULL(is_deleted, 0) = 0";
                    $docStmt = $this->db->prepare($docSql);
                    $docStmt->execute([
                        $actorUserPk > 0 ? $actorUserPk : null,
                        $docId,
                        (string)$scope['service_code'],
                        (int)$scope['tenant_id'],
                    ]);
                } else {
                    $docSql = "UPDATE dbo." . $this->qi(self::TABLE_DOC) . "
                               SET current_line_order = ?,
                                   updated_by = ?,
                                   updated_at = GETDATE()
                               WHERE idx = ?
                                 AND service_code = ?
                                 AND tenant_id = ?
                                 AND ISNULL(is_deleted, 0) = 0";
                    $docStmt = $this->db->prepare($docSql);
                    $docStmt->execute([
                        (int)($nextLine['line_order'] ?? 1),
                        $actorUserPk > 0 ? $actorUserPk : null,
                        $docId,
                        (string)$scope['service_code'],
                        (int)$scope['tenant_id'],
                    ]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit->log('groupware.appr.line_action', $actorUserPk, 'OK', 'Approval line action applied', [
            'service_code' => (string)$scope['service_code'],
            'tenant_id' => (int)$scope['tenant_id'],
            'doc_id' => $docId,
            'action' => $action,
        ]);

        $saved = $this->docDetail($scope, $docId, $actorUserPk, $roleLevel);
        if ($saved === null) {
            throw new RuntimeException('approval doc reload failed');
        }

        return $saved;
    }

    private function loadDocRow(array $scope, int $docId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    service_code,
                    tenant_id,
                    doc_no,
                    doc_type,
                    title,
                    body_html,
                    body_text,
                    writer_user_idx,
                    status,
                    current_line_order,
                    submitted_at,
                    completed_at,
                    recalled_at,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_DOC) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function replaceDocLines(
        array $scope,
        int $docId,
        array $approvers,
        array $references,
        int $actorUserPk
    ): void {
        $deleteSql = "UPDATE dbo." . $this->qi(self::TABLE_LINE) . "
                      SET is_deleted = 1,
                          updated_by = ?,
                          updated_at = GETDATE()
                      WHERE doc_idx = ?
                        AND service_code = ?
                        AND tenant_id = ?
                        AND ISNULL(is_deleted, 0) = 0";
        $deleteStmt = $this->db->prepare($deleteSql);
        $deleteStmt->execute([
            $actorUserPk > 0 ? $actorUserPk : null,
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $insertSql = "INSERT INTO dbo." . $this->qi(self::TABLE_LINE) . "
                      (service_code, tenant_id, doc_idx, line_type, line_order, actor_user_idx, decision_status, decided_at, decision_comment, is_deleted, created_by, updated_by, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, NULL, '', 0, ?, ?, GETDATE(), GETDATE())";
        $insertStmt = $this->db->prepare($insertSql);

        $lineOrder = 1;
        foreach ($approvers as $approverUserPk) {
            $insertStmt->execute([
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $docId,
                self::LINE_TYPE_APPROVER,
                $lineOrder,
                $approverUserPk,
                'PENDING',
                $actorUserPk > 0 ? $actorUserPk : null,
                $actorUserPk > 0 ? $actorUserPk : null,
            ]);
            $lineOrder++;
        }

        $refOrder = 1;
        foreach ($references as $referenceUserPk) {
            $insertStmt->execute([
                (string)$scope['service_code'],
                (int)$scope['tenant_id'],
                $docId,
                self::LINE_TYPE_REFERENCE,
                $refOrder,
                $referenceUserPk,
                'REFERENCE',
                $actorUserPk > 0 ? $actorUserPk : null,
                $actorUserPk > 0 ? $actorUserPk : null,
            ]);
            $refOrder++;
        }
    }

    private function loadNextPendingApproverLine(array $scope, int $docId): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    line_order,
                    actor_user_idx,
                    decision_status
                FROM dbo." . $this->qi(self::TABLE_LINE) . "
                WHERE doc_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND UPPER(ISNULL(line_type, 'APPROVER')) = 'APPROVER'
                  AND ISNULL(decision_status, 'PENDING') = 'PENDING'
                ORDER BY line_order ASC, idx ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function loadCurrentPendingApproverLine(array $scope, int $docId, int $lineOrder): ?array
    {
        $sql = "SELECT TOP 1
                    idx,
                    line_order,
                    actor_user_idx,
                    decision_status
                FROM dbo." . $this->qi(self::TABLE_LINE) . "
                WHERE doc_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND UPPER(ISNULL(line_type, 'APPROVER')) = 'APPROVER'
                  AND ISNULL(line_order, 0) = ?
                  AND ISNULL(decision_status, 'PENDING') = 'PENDING'
                ORDER BY idx ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $lineOrder,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function canAccessDoc(array $scope, int $docId, int $userPk, int $roleLevel, int $writerUserPk): bool
    {
        if ($this->isApproverRole($roleLevel)) {
            return true;
        }
        if ($userPk <= 0) {
            return false;
        }
        if ($writerUserPk === $userPk) {
            return true;
        }

        $sql = "SELECT COUNT(1)
                FROM dbo." . $this->qi(self::TABLE_LINE) . "
                WHERE doc_idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0
                  AND actor_user_idx = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $docId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $userPk,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function canWriteDoc(int $writerUserPk, int $actorUserPk, int $roleLevel): bool
    {
        if ($writerUserPk > 0 && $writerUserPk === $actorUserPk) {
            return true;
        }
        return $this->isApproverRole($roleLevel);
    }

    private function getPresetRow(array $scope, int $presetId): ?array
    {
        if ($presetId <= 0 || !$this->tableExists(self::TABLE_PRESET)) {
            return null;
        }

        $sql = "SELECT TOP 1
                    idx,
                    service_code,
                    tenant_id,
                    ISNULL(preset_name, '') AS preset_name,
                    ISNULL(doc_type, 'GENERAL') AS doc_type,
                    ISNULL(line_json, '{}') AS line_json,
                    CAST(ISNULL(is_shared, 0) AS INT) AS is_shared,
                    ISNULL(created_by, 0) AS created_by,
                    ISNULL(updated_by, 0) AS updated_by,
                    created_at,
                    updated_at
                FROM dbo." . $this->qi(self::TABLE_PRESET) . "
                WHERE idx = ?
                  AND service_code = ?
                  AND tenant_id = ?
                  AND ISNULL(is_deleted, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $presetId,
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function buildPresetJson(array $approvers, array $references): string
    {
        $payload = [
            'approver_user_ids' => array_values(array_map('intval', $approvers)),
            'reference_user_ids' => array_values(array_map('intval', $references)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return '{"approver_user_ids":[],"reference_user_ids":[]}';
        }

        return $json;
    }

    private function decodePresetJson(string $lineJson): array
    {
        $decoded = json_decode(trim($lineJson), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $approvers = $this->parseIntList($decoded['approver_user_ids'] ?? []);
        $references = $this->parseIntList($decoded['reference_user_ids'] ?? []);

        return [
            'approver_user_ids' => $approvers,
            'reference_user_ids' => $references,
        ];
    }

    private function nextDocNo(array $scope): string
    {
        $prefix = 'APPR-' . date('Ymd') . '-';
        $sql = "SELECT TOP 1 doc_no
                FROM dbo." . $this->qi(self::TABLE_DOC) . "
                WHERE service_code = ?
                  AND tenant_id = ?
                  AND doc_no LIKE ?
                ORDER BY idx DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            (string)$scope['service_code'],
            (int)$scope['tenant_id'],
            $prefix . '%',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $lastDocNo = trim((string)($row['doc_no'] ?? ''));

        $seq = 1;
        if ($lastDocNo !== '' && str_starts_with($lastDocNo, $prefix)) {
            $tail = substr($lastDocNo, strlen($prefix));
            if (ctype_digit((string)$tail)) {
                $seq = ((int)$tail) + 1;
            }
        }

        return $prefix . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    }

    private function resolveUserDisplayName(int $userPk): string
    {
        if ($userPk <= 0) {
            return '';
        }

        if (isset($this->userNameCache[$userPk])) {
            return (string)$this->userNameCache[$userPk];
        }

        $table = $this->normalizeIdentifier((string)($this->security['auth']['user_table'] ?? 'Tb_Users'), 'Tb_Users');
        $pkCol = $this->normalizeIdentifier((string)($this->security['auth']['user_pk_column'] ?? 'idx'), 'idx');
        $nameCandidates = ['name', 'user_name', 'nickname'];
        $loginCandidates = [
            (string)($this->security['auth']['user_login_column'] ?? 'id'),
            'login_id',
            'id',
        ];

        $nameCol = null;
        foreach ($nameCandidates as $candidate) {
            if ($this->columnExists($table, $candidate)) {
                $nameCol = $candidate;
                break;
            }
        }

        $loginCol = null;
        foreach ($loginCandidates as $candidate) {
            $candidate = $this->normalizeIdentifier($candidate, 'id');
            if ($this->columnExists($table, $candidate)) {
                $loginCol = $candidate;
                break;
            }
        }

        if (!$this->tableExists($table) || !$this->columnExists($table, $pkCol) || ($nameCol === null && $loginCol === null)) {
            $this->userNameCache[$userPk] = '#' . $userPk;
            return (string)$this->userNameCache[$userPk];
        }

        $selectExpr = $nameCol !== null
            ? 'ISNULL(' . $this->qi($nameCol) . ", '') AS display_name"
            : 'ISNULL(' . $this->qi((string)$loginCol) . ", '') AS display_name";

        $sql = 'SELECT TOP 1 ' . $selectExpr
             . ' FROM dbo.' . $this->qi($table)
             . ' WHERE ' . $this->qi($pkCol) . ' = ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userPk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $displayName = trim((string)($row['display_name'] ?? ''));
        if ($displayName === '' && $loginCol !== null && $nameCol !== null) {
            $fallbackSql = 'SELECT TOP 1 ISNULL(' . $this->qi($loginCol) . ", '') AS display_name"
                         . ' FROM dbo.' . $this->qi($table)
                         . ' WHERE ' . $this->qi($pkCol) . ' = ?';
            $fallbackStmt = $this->db->prepare($fallbackSql);
            $fallbackStmt->execute([$userPk]);
            $fallbackRow = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $displayName = trim((string)($fallbackRow['display_name'] ?? ''));
        }

        if ($displayName === '') {
            $displayName = '#' . $userPk;
        }

        $this->userNameCache[$userPk] = $displayName;
        return $displayName;
    }

    private function normalizeDocType(string $docType): string
    {
        $docType = strtoupper(trim($docType));
        if (!in_array($docType, self::DOC_TYPES, true)) {
            return 'GENERAL';
        }
        return $docType;
    }

    private function parseIntList($value): array
    {
        if (is_array($value)) {
            $source = $value;
        } else {
            $source = preg_split('/[\s,]+/', trim((string)$value)) ?: [];
        }

        $map = [];
        foreach ($source as $token) {
            $token = trim((string)$token);
            if ($token === '' || !ctype_digit($token)) {
                continue;
            }

            $id = (int)$token;
            if ($id > 0) {
                $map[$id] = true;
            }
        }

        return array_map('intval', array_keys($map));
    }

    private function isApproverRole(int $roleLevel): bool
    {
        return $roleLevel >= 1 && $roleLevel <= self::ROLE_APPROVER_MAX_IDX;
    }

    private function toBoolInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $str = strtolower(trim((string)$value));
        if (in_array($str, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return 1;
        }

        return 0;
    }

    private function tableExists(string $tableName): bool
    {
        $tableName = $this->normalizeIdentifier($tableName, '');
        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$tableName];
        }

        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN OBJECT_ID(?, 'U') IS NULL THEN 0 ELSE 1 END AS exists_yn");
            $stmt->execute(['dbo.' . $tableName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $tableName = $this->normalizeIdentifier($tableName, '');
        $columnName = $this->normalizeIdentifier($columnName, '');
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $cacheKey = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return (bool)$this->columnExistsCache[$cacheKey];
        }

        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN COL_LENGTH(?, ?) IS NULL THEN 0 ELSE 1 END AS exists_yn");
            $stmt->execute(['dbo.' . $tableName, $columnName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function normalizeIdentifier(string $value, string $default): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $trimmed) !== 1) {
            return $default;
        }

        return $trimmed;
    }

    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }
}

