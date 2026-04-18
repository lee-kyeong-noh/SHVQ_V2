/*
  SHVQ_V2 - Wave1 MAT Media/Component Patch
  목적:
  1) Tb_Item.upload_files_banner / upload_files_detail 컬럼 보장
  2) V1(CSM_C004732).Tb_Item -> V2(CSM_C004732_V2).Tb_Item 이미지 파일명 백필
  3) V2 구성품 기준 테이블 Tb_ItemComponent 생성/보강
  4) V2 Tb_ItemChild + V1 Tb_ItemChild 데이터를 Tb_ItemComponent로 이관

  주의:
  - 실행 DB: CSM_C004732_V2
  - V1 DB(CSM_C004732)는 조회 전용
  - 재실행 가능(멱등) 스크립트
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.Tb_Item', N'U') IS NULL
    BEGIN
        RAISERROR(N'[STOP] Target table missing: dbo.Tb_Item', 16, 1);
        ROLLBACK TRANSACTION;
        RETURN;
    END;

    /* ============================================================
       1) Tb_Item 이미지 컬럼 보장
       ============================================================ */
    IF COL_LENGTH('dbo.Tb_Item', 'upload_files_banner') IS NULL
    BEGIN
        ALTER TABLE dbo.Tb_Item
            ADD upload_files_banner NVARCHAR(500) NULL;
    END;

    IF COL_LENGTH('dbo.Tb_Item', 'upload_files_detail') IS NULL
    BEGIN
        ALTER TABLE dbo.Tb_Item
            ADD upload_files_detail NVARCHAR(500) NULL;
    END;

    /* ============================================================
       2) V1 -> V2 이미지 파일명 백필
       ============================================================ */
    IF DB_ID(N'CSM_C004732') IS NOT NULL
       AND OBJECT_ID(N'[CSM_C004732].dbo.[Tb_Item]', N'U') IS NOT NULL
    BEGIN
        IF COL_LENGTH('CSM_C004732.dbo.Tb_Item', 'upload_files_banner') IS NOT NULL
        BEGIN
            UPDATE t
            SET t.upload_files_banner = CONVERT(NVARCHAR(500), s.upload_files_banner)
            FROM dbo.Tb_Item t
            INNER JOIN [CSM_C004732].dbo.Tb_Item s
                ON s.idx = t.idx
            WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), t.upload_files_banner))), N'') = N''
              AND ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), s.upload_files_banner))), N'') <> N'';
        END;

        IF COL_LENGTH('CSM_C004732.dbo.Tb_Item', 'upload_files_detail') IS NOT NULL
        BEGIN
            UPDATE t
            SET t.upload_files_detail = CONVERT(NVARCHAR(500), s.upload_files_detail)
            FROM dbo.Tb_Item t
            INNER JOIN [CSM_C004732].dbo.Tb_Item s
                ON s.idx = t.idx
            WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), t.upload_files_detail))), N'') = N''
              AND ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), s.upload_files_detail))), N'') <> N'';
        END;
    END;

    /* ============================================================
       3) Tb_ItemComponent 생성/보강 (V2 표준)
       ============================================================ */
    IF OBJECT_ID(N'dbo.Tb_ItemComponent', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.Tb_ItemComponent (
            idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            parent_item_idx INT NOT NULL,
            child_item_idx INT NOT NULL,
            qty DECIMAL(18,4) NOT NULL CONSTRAINT DF_Tb_ItemComponent_qty DEFAULT (1),
            sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemComponent_sort_order DEFAULT (0),
            legacy_idx INT NULL,
            is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemComponent_is_deleted DEFAULT (0),
            created_by INT NULL,
            updated_by INT NULL,
            deleted_by INT NULL,
            created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ItemComponent_created_at DEFAULT (GETDATE()),
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        );
    END;

    IF COL_LENGTH('dbo.Tb_ItemComponent', 'parent_item_idx') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD parent_item_idx INT NOT NULL CONSTRAINT DF_Tb_ItemComponent_parent_item_idx_A DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'child_item_idx') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD child_item_idx INT NOT NULL CONSTRAINT DF_Tb_ItemComponent_child_item_idx_A DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'qty') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD qty DECIMAL(18,4) NOT NULL CONSTRAINT DF_Tb_ItemComponent_qty_A DEFAULT (1);
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'sort_order') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemComponent_sort_order_A DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'legacy_idx') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD legacy_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemComponent_is_deleted_A DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'deleted_by') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ItemComponent_created_at_A DEFAULT (GETDATE());
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD updated_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ItemComponent', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_ItemComponent ADD deleted_at DATETIME NULL;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemComponent')
          AND name = N'IX_Tb_ItemComponent_ParentSort'
    )
    BEGIN
        CREATE INDEX IX_Tb_ItemComponent_ParentSort
            ON dbo.Tb_ItemComponent(parent_item_idx, sort_order, idx);
    END;

    /* ============================================================
       4) 구성품 데이터 이관
       - 우선순위: V2 Tb_ItemChild(우선) -> V1 Tb_ItemChild
       ============================================================ */
    IF OBJECT_ID('tempdb..#SrcComponent') IS NOT NULL DROP TABLE #SrcComponent;
    CREATE TABLE #SrcComponent (
        legacy_idx INT NULL,
        parent_item_idx INT NOT NULL,
        child_item_idx INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL,
        sort_order INT NOT NULL,
        is_deleted BIT NOT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        deleted_by INT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        deleted_at DATETIME NULL,
        source_priority TINYINT NOT NULL
    );

    IF OBJECT_ID(N'dbo.Tb_ItemChild', N'U') IS NOT NULL
    BEGIN
        DECLARE @V2ParentExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2ChildExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2QtyExpr NVARCHAR(MAX) = N'1';
        DECLARE @V2SortExpr NVARCHAR(MAX) = N'0';
        DECLARE @V2DeletedExpr NVARCHAR(MAX) = N'0';
        DECLARE @V2CreatedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2UpdatedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2DeletedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2CreatedAtExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2UpdatedAtExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V2DeletedAtExpr NVARCHAR(MAX) = N'NULL';

        IF COL_LENGTH('dbo.Tb_ItemChild', 'parent_item_idx') IS NOT NULL
            SET @V2ParentExpr = N'TRY_CONVERT(INT, s.[parent_item_idx])';
        ELSE IF COL_LENGTH('dbo.Tb_ItemChild', 'parent_idx') IS NOT NULL
            SET @V2ParentExpr = N'TRY_CONVERT(INT, s.[parent_idx])';

        IF COL_LENGTH('dbo.Tb_ItemChild', 'child_item_idx') IS NOT NULL
            SET @V2ChildExpr = N'TRY_CONVERT(INT, s.[child_item_idx])';
        ELSE IF COL_LENGTH('dbo.Tb_ItemChild', 'child_idx') IS NOT NULL
            SET @V2ChildExpr = N'TRY_CONVERT(INT, s.[child_idx])';

        IF COL_LENGTH('dbo.Tb_ItemChild', 'qty') IS NOT NULL
            SET @V2QtyExpr = N'TRY_CONVERT(DECIMAL(18,4), s.[qty])';
        ELSE IF COL_LENGTH('dbo.Tb_ItemChild', 'child_qty') IS NOT NULL
            SET @V2QtyExpr = N'TRY_CONVERT(DECIMAL(18,4), s.[child_qty])';

        IF COL_LENGTH('dbo.Tb_ItemChild', 'sort_order') IS NOT NULL
            SET @V2SortExpr = N'TRY_CONVERT(INT, s.[sort_order])';

        IF COL_LENGTH('dbo.Tb_ItemChild', 'is_deleted') IS NOT NULL
            SET @V2DeletedExpr = N'CASE WHEN TRY_CONVERT(INT, s.[is_deleted]) = 1 THEN 1 ELSE 0 END';
        ELSE IF COL_LENGTH('dbo.Tb_ItemChild', 'del_yn') IS NOT NULL
            SET @V2DeletedExpr = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[del_yn])))) IN (''Y'',''1'',''TRUE'') THEN 1 ELSE 0 END';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'created_by') IS NOT NULL
            SET @V2CreatedByExpr = N'TRY_CONVERT(INT, s.[created_by])';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'updated_by') IS NOT NULL
            SET @V2UpdatedByExpr = N'TRY_CONVERT(INT, s.[updated_by])';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'deleted_by') IS NOT NULL
            SET @V2DeletedByExpr = N'TRY_CONVERT(INT, s.[deleted_by])';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'created_at') IS NOT NULL
            SET @V2CreatedAtExpr = N'TRY_CONVERT(DATETIME, s.[created_at])';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'updated_at') IS NOT NULL
            SET @V2UpdatedAtExpr = N'TRY_CONVERT(DATETIME, s.[updated_at])';
        IF COL_LENGTH('dbo.Tb_ItemChild', 'deleted_at') IS NOT NULL
            SET @V2DeletedAtExpr = N'TRY_CONVERT(DATETIME, s.[deleted_at])';

        DECLARE @LoadV2Sql NVARCHAR(MAX) = N'
        INSERT INTO #SrcComponent
        (
            legacy_idx, parent_item_idx, child_item_idx, qty, sort_order, is_deleted,
            created_by, updated_by, deleted_by, created_at, updated_at, deleted_at, source_priority
        )
        SELECT
            TRY_CONVERT(INT, s.[idx]) AS legacy_idx,
            ISNULL(NULLIF(' + @V2ParentExpr + N', 0), 0) AS parent_item_idx,
            ISNULL(NULLIF(' + @V2ChildExpr + N', 0), 0) AS child_item_idx,
            ISNULL(NULLIF(' + @V2QtyExpr + N', 0), 1) AS qty,
            ISNULL(' + @V2SortExpr + N', 0) AS sort_order,
            ' + @V2DeletedExpr + N' AS is_deleted,
            ' + @V2CreatedByExpr + N' AS created_by,
            ' + @V2UpdatedByExpr + N' AS updated_by,
            ' + @V2DeletedByExpr + N' AS deleted_by,
            ' + @V2CreatedAtExpr + N' AS created_at,
            ' + @V2UpdatedAtExpr + N' AS updated_at,
            ' + @V2DeletedAtExpr + N' AS deleted_at,
            1 AS source_priority
        FROM dbo.[Tb_ItemChild] s
        WHERE ISNULL(NULLIF(' + @V2ParentExpr + N', 0), 0) > 0
          AND ISNULL(NULLIF(' + @V2ChildExpr + N', 0), 0) > 0;';

        EXEC sp_executesql @LoadV2Sql;
    END;

    IF DB_ID(N'CSM_C004732') IS NOT NULL
       AND OBJECT_ID(N'[CSM_C004732].dbo.[Tb_ItemChild]', N'U') IS NOT NULL
    BEGIN
        DECLARE @V1ParentExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1ChildExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1QtyExpr NVARCHAR(MAX) = N'1';
        DECLARE @V1SortExpr NVARCHAR(MAX) = N'0';
        DECLARE @V1DeletedExpr NVARCHAR(MAX) = N'0';
        DECLARE @V1CreatedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1UpdatedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1DeletedByExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1CreatedAtExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1UpdatedAtExpr NVARCHAR(MAX) = N'NULL';
        DECLARE @V1DeletedAtExpr NVARCHAR(MAX) = N'NULL';

        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'parent_item_idx') IS NOT NULL
            SET @V1ParentExpr = N'TRY_CONVERT(INT, s.[parent_item_idx])';
        ELSE IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'parent_idx') IS NOT NULL
            SET @V1ParentExpr = N'TRY_CONVERT(INT, s.[parent_idx])';

        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'child_item_idx') IS NOT NULL
            SET @V1ChildExpr = N'TRY_CONVERT(INT, s.[child_item_idx])';
        ELSE IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'child_idx') IS NOT NULL
            SET @V1ChildExpr = N'TRY_CONVERT(INT, s.[child_idx])';

        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'qty') IS NOT NULL
            SET @V1QtyExpr = N'TRY_CONVERT(DECIMAL(18,4), s.[qty])';
        ELSE IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'child_qty') IS NOT NULL
            SET @V1QtyExpr = N'TRY_CONVERT(DECIMAL(18,4), s.[child_qty])';

        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'sort_order') IS NOT NULL
            SET @V1SortExpr = N'TRY_CONVERT(INT, s.[sort_order])';

        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'is_deleted') IS NOT NULL
            SET @V1DeletedExpr = N'CASE WHEN TRY_CONVERT(INT, s.[is_deleted]) = 1 THEN 1 ELSE 0 END';
        ELSE IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'del_yn') IS NOT NULL
            SET @V1DeletedExpr = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[del_yn])))) IN (''Y'',''1'',''TRUE'') THEN 1 ELSE 0 END';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'created_by') IS NOT NULL
            SET @V1CreatedByExpr = N'TRY_CONVERT(INT, s.[created_by])';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'updated_by') IS NOT NULL
            SET @V1UpdatedByExpr = N'TRY_CONVERT(INT, s.[updated_by])';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'deleted_by') IS NOT NULL
            SET @V1DeletedByExpr = N'TRY_CONVERT(INT, s.[deleted_by])';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'created_at') IS NOT NULL
            SET @V1CreatedAtExpr = N'TRY_CONVERT(DATETIME, s.[created_at])';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'updated_at') IS NOT NULL
            SET @V1UpdatedAtExpr = N'TRY_CONVERT(DATETIME, s.[updated_at])';
        IF COL_LENGTH('CSM_C004732.dbo.Tb_ItemChild', 'deleted_at') IS NOT NULL
            SET @V1DeletedAtExpr = N'TRY_CONVERT(DATETIME, s.[deleted_at])';

        DECLARE @LoadV1Sql NVARCHAR(MAX) = N'
        INSERT INTO #SrcComponent
        (
            legacy_idx, parent_item_idx, child_item_idx, qty, sort_order, is_deleted,
            created_by, updated_by, deleted_by, created_at, updated_at, deleted_at, source_priority
        )
        SELECT
            TRY_CONVERT(INT, s.[idx]) AS legacy_idx,
            ISNULL(NULLIF(' + @V1ParentExpr + N', 0), 0) AS parent_item_idx,
            ISNULL(NULLIF(' + @V1ChildExpr + N', 0), 0) AS child_item_idx,
            ISNULL(NULLIF(' + @V1QtyExpr + N', 0), 1) AS qty,
            ISNULL(' + @V1SortExpr + N', 0) AS sort_order,
            ' + @V1DeletedExpr + N' AS is_deleted,
            ' + @V1CreatedByExpr + N' AS created_by,
            ' + @V1UpdatedByExpr + N' AS updated_by,
            ' + @V1DeletedByExpr + N' AS deleted_by,
            ' + @V1CreatedAtExpr + N' AS created_at,
            ' + @V1UpdatedAtExpr + N' AS updated_at,
            ' + @V1DeletedAtExpr + N' AS deleted_at,
            2 AS source_priority
        FROM [CSM_C004732].dbo.[Tb_ItemChild] s
        WHERE ISNULL(NULLIF(' + @V1ParentExpr + N', 0), 0) > 0
          AND ISNULL(NULLIF(' + @V1ChildExpr + N', 0), 0) > 0;';

        EXEC sp_executesql @LoadV1Sql;
    END;

    ;WITH dedup AS (
        SELECT
            *,
            ROW_NUMBER() OVER (
                PARTITION BY parent_item_idx, child_item_idx
                ORDER BY is_deleted ASC, source_priority ASC, sort_order ASC, ISNULL(legacy_idx, 2147483647) ASC
            ) AS rn
        FROM #SrcComponent
    )
    DELETE FROM dedup WHERE rn > 1;

    UPDATE tgt
    SET
        tgt.qty = src.qty,
        tgt.sort_order = src.sort_order,
        tgt.legacy_idx = COALESCE(tgt.legacy_idx, src.legacy_idx),
        tgt.is_deleted = src.is_deleted,
        tgt.created_by = COALESCE(tgt.created_by, src.created_by),
        tgt.updated_by = COALESCE(src.updated_by, tgt.updated_by),
        tgt.deleted_by = CASE WHEN src.is_deleted = 1 THEN COALESCE(src.deleted_by, tgt.deleted_by) ELSE NULL END,
        tgt.created_at = COALESCE(tgt.created_at, src.created_at, GETDATE()),
        tgt.updated_at = GETDATE(),
        tgt.deleted_at = CASE WHEN src.is_deleted = 1 THEN COALESCE(src.deleted_at, GETDATE()) ELSE NULL END
    FROM dbo.Tb_ItemComponent tgt
    INNER JOIN #SrcComponent src
        ON src.parent_item_idx = tgt.parent_item_idx
       AND src.child_item_idx = tgt.child_item_idx;

    INSERT INTO dbo.Tb_ItemComponent
    (
        parent_item_idx, child_item_idx, qty, sort_order, legacy_idx,
        is_deleted, created_by, updated_by, deleted_by, created_at, updated_at, deleted_at
    )
    SELECT
        src.parent_item_idx,
        src.child_item_idx,
        src.qty,
        src.sort_order,
        src.legacy_idx,
        src.is_deleted,
        src.created_by,
        src.updated_by,
        src.deleted_by,
        COALESCE(src.created_at, GETDATE()),
        GETDATE(),
        CASE WHEN src.is_deleted = 1 THEN COALESCE(src.deleted_at, GETDATE()) ELSE NULL END
    FROM #SrcComponent src
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.Tb_ItemComponent tgt
        WHERE tgt.parent_item_idx = src.parent_item_idx
          AND tgt.child_item_idx = src.child_item_idx
    );

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemComponent')
          AND name = N'UX_Tb_ItemComponent_ParentChild_Active'
    )
    BEGIN
        CREATE UNIQUE INDEX UX_Tb_ItemComponent_ParentChild_Active
            ON dbo.Tb_ItemComponent(parent_item_idx, child_item_idx)
            WHERE is_deleted = 0;
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    DECLARE @Err NVARCHAR(4000) = ERROR_MESSAGE();
    RAISERROR(N'[FAIL] %s', 16, 1, @Err);
    RETURN;
END CATCH;

PRINT N'[OK] MAT media/component patch completed';
SELECT
    (SELECT COUNT(1) FROM dbo.Tb_Item WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), upload_files_banner))), N'') <> N'') AS item_banner_filled,
    (SELECT COUNT(1) FROM dbo.Tb_Item WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(500), upload_files_detail))), N'') <> N'') AS item_detail_filled,
    (SELECT COUNT(1) FROM dbo.Tb_ItemComponent) AS component_total_count;
