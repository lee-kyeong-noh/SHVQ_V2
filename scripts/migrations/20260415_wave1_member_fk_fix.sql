/*
  SHVQ_V2 - Wave 1 Member FK Fix
  DB: CSM_C004732_V2 (67번 개발DB)
  목적: Tb_Members.head_idx -> Tb_HeadOffice.idx FK 생성 실패 보정
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

DECLARE @HeadObjId INT = OBJECT_ID(N'dbo.Tb_HeadOffice', N'U');
DECLARE @MembersObjId INT = OBJECT_ID(N'dbo.Tb_Members', N'U');
DECLARE @HeadIdxColId INT = CASE WHEN @HeadObjId IS NULL THEN NULL ELSE COLUMNPROPERTY(@HeadObjId, 'idx', 'ColumnId') END;

IF @HeadObjId IS NOT NULL AND @HeadIdxColId IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes i
        JOIN sys.index_columns ic
          ON ic.object_id = i.object_id
         AND ic.index_id = i.index_id
        WHERE i.object_id = @HeadObjId
          AND i.is_unique = 1
          AND ic.is_included_column = 0
        GROUP BY i.object_id, i.index_id
        HAVING COUNT(1) = 1
           AND MAX(CASE WHEN ic.key_ordinal = 1 THEN ic.column_id ELSE NULL END) = @HeadIdxColId
    )
    BEGIN
        IF EXISTS (SELECT 1 FROM dbo.Tb_HeadOffice WHERE idx IS NULL)
        BEGIN
            RAISERROR(N'[WARN] Tb_HeadOffice.idx has NULL values. Cannot create unique key for FK.', 10, 1);
        END
        ELSE IF EXISTS (SELECT idx FROM dbo.Tb_HeadOffice GROUP BY idx HAVING COUNT(1) > 1)
        BEGIN
            RAISERROR(N'[WARN] Tb_HeadOffice.idx has duplicates. Cannot create unique key for FK.', 10, 1);
        END
        ELSE
        BEGIN
            CREATE UNIQUE INDEX UX_Tb_HeadOffice_idx_for_member_fk
                ON dbo.Tb_HeadOffice(idx);
        END
    END
END
ELSE
BEGIN
    RAISERROR(N'[WARN] Tb_HeadOffice or idx column missing. FK patch skipped.', 10, 1);
END;
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.Tb_HeadOffice', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_Members', 'head_idx') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'FK_Tb_Members_HeadOffice_HeadIdx'
   )
   AND EXISTS (
        SELECT 1
        FROM sys.indexes i
        JOIN sys.index_columns ic
          ON ic.object_id = i.object_id
         AND ic.index_id = i.index_id
        WHERE i.object_id = OBJECT_ID(N'dbo.Tb_HeadOffice')
          AND i.is_unique = 1
          AND ic.is_included_column = 0
        GROUP BY i.object_id, i.index_id
        HAVING COUNT(1) = 1
           AND MAX(CASE WHEN ic.key_ordinal = 1 THEN ic.column_id ELSE NULL END) = COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_HeadOffice'), 'idx', 'ColumnId')
   )
BEGIN
    ALTER TABLE dbo.Tb_Members WITH CHECK
    ADD CONSTRAINT FK_Tb_Members_HeadOffice_HeadIdx
    FOREIGN KEY (head_idx) REFERENCES dbo.Tb_HeadOffice(idx);
END;
GO

SELECT
    OBJECT_ID(N'dbo.Tb_Members', N'U') AS members_object_id,
    OBJECT_ID(N'dbo.Tb_HeadOffice', N'U') AS headoffice_object_id,
    (
        SELECT COUNT(1)
        FROM sys.foreign_keys
        WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'FK_Tb_Members_HeadOffice_HeadIdx'
    ) AS fk_exists,
    (
        SELECT COUNT(1)
        FROM sys.indexes i
        JOIN sys.index_columns ic
          ON ic.object_id = i.object_id
         AND ic.index_id = i.index_id
        WHERE i.object_id = OBJECT_ID(N'dbo.Tb_HeadOffice')
          AND i.is_unique = 1
          AND ic.is_included_column = 0
        GROUP BY i.object_id, i.index_id
        HAVING COUNT(1) = 1
           AND MAX(CASE WHEN ic.key_ordinal = 1 THEN ic.column_id ELSE NULL END) = COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_HeadOffice'), 'idx', 'ColumnId')
    ) AS head_idx_unique_key_count,
    (
        SELECT COUNT(1)
        FROM dbo.Tb_HeadOffice
        WHERE idx IS NULL
    ) AS head_idx_null_count,
    (
        SELECT COUNT(1)
        FROM (
            SELECT idx
            FROM dbo.Tb_HeadOffice
            GROUP BY idx
            HAVING COUNT(1) > 1
        ) d
    ) AS head_idx_duplicate_count;
GO
