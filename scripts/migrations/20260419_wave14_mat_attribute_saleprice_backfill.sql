SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave14 MAT attribute/sale_price patch start ===';

IF OBJECT_ID(N'dbo.Tb_Item', N'U') IS NULL
BEGIN
    PRINT 'Tb_Item table is missing - skip';
    RETURN;
END

IF COL_LENGTH('dbo.Tb_Item', 'attribute') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD attribute INT NULL;
    PRINT 'Added column: Tb_Item.attribute (INT NULL)';
END
ELSE
BEGIN
    PRINT 'Column already exists: Tb_Item.attribute';
END

IF COL_LENGTH('dbo.Tb_Item', 'sale_price') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD sale_price DECIMAL(18,2) NULL;
    PRINT 'Added column: Tb_Item.sale_price (DECIMAL(18,2) NULL)';
END
ELSE
BEGIN
    PRINT 'Column already exists: Tb_Item.sale_price';
END

DECLARE @SourceDb SYSNAME = N'CSM_C004732';
DECLARE @SrcItemObj NVARCHAR(300) = QUOTENAME(@SourceDb) + N'.dbo.[Tb_Item]';

IF DB_ID(@SourceDb) IS NULL OR OBJECT_ID(@SrcItemObj, N'U') IS NULL
BEGIN
    PRINT 'Source V1 Tb_Item is missing - skip backfill';
END
ELSE
BEGIN
    DECLARE @SrcHasAttribute BIT = CASE WHEN COL_LENGTH(@SourceDb + '.dbo.Tb_Item', 'attribute') IS NULL THEN 0 ELSE 1 END;
    DECLARE @SrcHasSalePrice BIT = CASE WHEN COL_LENGTH(@SourceDb + '.dbo.Tb_Item', 'sale_price') IS NULL THEN 0 ELSE 1 END;
    DECLARE @SrcHasPrice BIT = CASE WHEN COL_LENGTH(@SourceDb + '.dbo.Tb_Item', 'price') IS NULL THEN 0 ELSE 1 END;

    IF @SrcHasAttribute = 1
    BEGIN
        DECLARE @AttrSql NVARCHAR(MAX) = N'
;WITH Src AS (
    SELECT
        s.idx,
        TRY_CONVERT(INT, s.attribute) AS attribute_src
    FROM [CSM_C004732].dbo.Tb_Item s WITH (NOLOCK)
),
Matched AS (
    SELECT
        t.idx AS target_idx,
        COALESCE(s_idx.attribute_src, s_origin.attribute_src, s_legacy.attribute_src) AS attribute_src
    FROM dbo.Tb_Item t
    OUTER APPLY (SELECT TOP 1 s.attribute_src FROM Src s WHERE s.idx = t.idx) s_idx
    OUTER APPLY (SELECT TOP 1 s.attribute_src FROM Src s WHERE ISNULL(t.origin_idx, 0) > 0 AND s.idx = t.origin_idx) s_origin
    OUTER APPLY (SELECT TOP 1 s.attribute_src FROM Src s WHERE ISNULL(t.legacy_idx, 0) > 0 AND s.idx = t.legacy_idx) s_legacy
)
UPDATE t
SET t.attribute = m.attribute_src
FROM dbo.Tb_Item t
INNER JOIN Matched m ON m.target_idx = t.idx
WHERE m.attribute_src IS NOT NULL
  AND t.attribute IS NULL;';
        EXEC sp_executesql @AttrSql;
        PRINT 'Backfilled Tb_Item.attribute from V1';
    END
    ELSE
    BEGIN
        PRINT 'Source column missing: V1 Tb_Item.attribute';
    END

    DECLARE @SaleExpr NVARCHAR(500) = N'NULL';
    IF @SrcHasSalePrice = 1 AND @SrcHasPrice = 1
        SET @SaleExpr = N'COALESCE(TRY_CONVERT(DECIMAL(18,2), s.sale_price), TRY_CONVERT(DECIMAL(18,2), s.price))';
    ELSE IF @SrcHasSalePrice = 1
        SET @SaleExpr = N'TRY_CONVERT(DECIMAL(18,2), s.sale_price)';
    ELSE IF @SrcHasPrice = 1
        SET @SaleExpr = N'TRY_CONVERT(DECIMAL(18,2), s.price)';

    IF @SaleExpr <> N'NULL'
    BEGIN
        DECLARE @Sql NVARCHAR(MAX) = N'
;WITH Src AS (
    SELECT
        s.idx,
        sale_price_src = ' + @SaleExpr + N'
    FROM [CSM_C004732].dbo.Tb_Item s WITH (NOLOCK)
),
Matched AS (
    SELECT
        t.idx AS target_idx,
        COALESCE(s_idx.sale_price_src, s_origin.sale_price_src, s_legacy.sale_price_src) AS sale_price_src
    FROM dbo.Tb_Item t
    OUTER APPLY (SELECT TOP 1 s.sale_price_src FROM Src s WHERE s.idx = t.idx) s_idx
    OUTER APPLY (SELECT TOP 1 s.sale_price_src FROM Src s WHERE ISNULL(t.origin_idx, 0) > 0 AND s.idx = t.origin_idx) s_origin
    OUTER APPLY (SELECT TOP 1 s.sale_price_src FROM Src s WHERE ISNULL(t.legacy_idx, 0) > 0 AND s.idx = t.legacy_idx) s_legacy
)
UPDATE t
SET t.sale_price = m.sale_price_src
FROM dbo.Tb_Item t
INNER JOIN Matched m ON m.target_idx = t.idx
WHERE m.sale_price_src IS NOT NULL
  AND (t.sale_price IS NULL OR TRY_CONVERT(DECIMAL(18,2), t.sale_price) = 0);';

        EXEC sp_executesql @Sql;
        PRINT 'Backfilled Tb_Item.sale_price from V1';
    END
    ELSE
    BEGIN
        PRINT 'Source columns missing: V1 Tb_Item.sale_price/price';
    END
END

PRINT '=== Wave14 MAT attribute/sale_price patch done ===';

SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'Tb_Item'
  AND COLUMN_NAME IN ('attribute', 'sale_price')
ORDER BY COLUMN_NAME;

DECLARE @SummarySql NVARCHAR(MAX) = N'
SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN attribute IS NULL THEN 0 ELSE 1 END) AS attribute_filled_count,
    SUM(CASE WHEN ISNULL(TRY_CONVERT(DECIMAL(18,2), sale_price), 0) > 0 THEN 1 ELSE 0 END) AS sale_price_gt_zero_count
FROM dbo.Tb_Item WITH (NOLOCK);';
EXEC sp_executesql @SummarySql;
