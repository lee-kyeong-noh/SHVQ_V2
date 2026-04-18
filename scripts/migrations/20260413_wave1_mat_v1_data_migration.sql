/*
  SHVQ_V2 - Wave1 MAT V1 -> V2 Data Migration
  목적:
  1) Tb_ItemTab (V1) -> Tb_ItemTab (V2) idx 보존 이관
  2) Tb_ItemCategory (V1) -> Tb_ItemCategory (V2) idx 보존 이관 (tab_idx 포함)
  3) Tb_Item (V1) -> Tb_Item (V2) idx 보존 이관 + legacy 플래그 반영
  4) V2 테스트 데이터(소스 미존재 idx) 정리

  주의:
  - V1 DB(CSM_C004732)는 조회 전용
  - V2 DB(CSM_C004732_V2)에서 실행
  - 재실행 가능(멱등) 목표
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];

DECLARE @SourceDb SYSNAME = N'CSM_C004732';
DECLARE @SrcTabObj NVARCHAR(300) = QUOTENAME(@SourceDb) + N'.dbo.[Tb_ItemTab]';
DECLARE @SrcCategoryObj NVARCHAR(300) = QUOTENAME(@SourceDb) + N'.dbo.[Tb_ItemCategory]';
DECLARE @SrcItemObj NVARCHAR(300) = QUOTENAME(@SourceDb) + N'.dbo.[Tb_Item]';

IF DB_ID(@SourceDb) IS NULL
BEGIN
    RAISERROR(N'[STOP] Source DB not found: %s', 16, 1, @SourceDb);
    RETURN;
END;

IF OBJECT_ID(@SrcTabObj, N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table missing: %s', 16, 1, @SrcTabObj);
    RETURN;
END;

IF OBJECT_ID(@SrcCategoryObj, N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table missing: %s', 16, 1, @SrcCategoryObj);
    RETURN;
END;

IF OBJECT_ID(@SrcItemObj, N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table missing: %s', 16, 1, @SrcItemObj);
    RETURN;
END;

IF OBJECT_ID(N'dbo.Tb_ItemTab', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_ItemTab', 16, 1);
    RETURN;
END;

IF OBJECT_ID(N'dbo.Tb_ItemCategory', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_ItemCategory', 16, 1);
    RETURN;
END;

IF OBJECT_ID(N'dbo.Tb_Item', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_Item', 16, 1);
    RETURN;
END;

/* Tb_ItemCategory.tab_idx 선행 보강 */
IF COL_LENGTH('dbo.Tb_ItemCategory', 'tab_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory
        ADD tab_idx INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_tab_idx_MIG DEFAULT 0;
END;

/* Tb_Item.material_pattern 선행 보강 */
IF COL_LENGTH('dbo.Tb_Item', 'material_pattern') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item
        ADD material_pattern NVARCHAR(20) NULL;
END;

/* ============================================================
   Source staging: Tb_ItemTab
   ============================================================ */
IF OBJECT_ID('tempdb..#SrcItemTab') IS NOT NULL DROP TABLE #SrcItemTab;
CREATE TABLE #SrcItemTab (
    src_idx INT NOT NULL,
    tab_name NVARCHAR(120) NOT NULL,
    sort_order INT NOT NULL,
    regdate DATETIME NOT NULL
);

DECLARE @ExprTabIdx NVARCHAR(MAX) = N'NULL';
DECLARE @ExprTabName NVARCHAR(MAX) = N'NULL';
DECLARE @ExprTabSort NVARCHAR(MAX) = N'NULL';
DECLARE @ExprTabRegdate NVARCHAR(MAX) = N'NULL';

IF COL_LENGTH(@SrcTabObj, 'idx') IS NOT NULL
    SET @ExprTabIdx = N'TRY_CONVERT(INT, s.[idx])';
ELSE IF COL_LENGTH(@SrcTabObj, 'id') IS NOT NULL
    SET @ExprTabIdx = N'TRY_CONVERT(INT, s.[id])';

IF COL_LENGTH(@SrcTabObj, 'name') IS NOT NULL
    SET @ExprTabName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[name]))), '''')';
ELSE IF COL_LENGTH(@SrcTabObj, 'tab_name') IS NOT NULL
    SET @ExprTabName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[tab_name]))), '''')';

IF COL_LENGTH(@SrcTabObj, 'sort_order') IS NOT NULL
    SET @ExprTabSort = N'TRY_CONVERT(INT, s.[sort_order])';
ELSE IF COL_LENGTH(@SrcTabObj, 'sort') IS NOT NULL
    SET @ExprTabSort = N'TRY_CONVERT(INT, s.[sort])';
ELSE IF COL_LENGTH(@SrcTabObj, 'order_no') IS NOT NULL
    SET @ExprTabSort = N'TRY_CONVERT(INT, s.[order_no])';

IF COL_LENGTH(@SrcTabObj, 'regdate') IS NOT NULL
    SET @ExprTabRegdate = N'TRY_CONVERT(DATETIME, s.[regdate])';
ELSE IF COL_LENGTH(@SrcTabObj, 'created_date') IS NOT NULL
    SET @ExprTabRegdate = N'TRY_CONVERT(DATETIME, s.[created_date])';
ELSE IF COL_LENGTH(@SrcTabObj, 'created_at') IS NOT NULL
    SET @ExprTabRegdate = N'TRY_CONVERT(DATETIME, s.[created_at])';

DECLARE @LoadTabSql NVARCHAR(MAX) = N'
INSERT INTO #SrcItemTab (src_idx, tab_name, sort_order, regdate)
SELECT
    ISNULL(NULLIF(' + @ExprTabIdx + N', 0), ROW_NUMBER() OVER (ORDER BY (SELECT NULL))) AS src_idx,
    ISNULL(' + @ExprTabName + N', N''탭'' + CONVERT(NVARCHAR(20), ROW_NUMBER() OVER (ORDER BY (SELECT NULL)))) AS tab_name,
    ISNULL(' + @ExprTabSort + N', 0) AS sort_order,
    ISNULL(' + @ExprTabRegdate + N', GETDATE()) AS regdate
FROM ' + @SrcTabObj + N' s;';

EXEC sp_executesql @LoadTabSql;

;WITH d AS (
    SELECT
        src_idx,
        ROW_NUMBER() OVER (PARTITION BY src_idx ORDER BY regdate DESC, sort_order ASC, src_idx ASC) AS rn
    FROM #SrcItemTab
)
DELETE FROM d WHERE rn > 1;

IF NOT EXISTS (SELECT 1 FROM #SrcItemTab)
BEGIN
    RAISERROR(N'[STOP] Source Tb_ItemTab has no rows.', 16, 1);
    RETURN;
END;

/* ============================================================
   Source staging: Tb_ItemCategory
   ============================================================ */
IF OBJECT_ID('tempdb..#SrcItemCategory') IS NOT NULL DROP TABLE #SrcItemCategory;
CREATE TABLE #SrcItemCategory (
    src_idx INT NOT NULL,
    tab_idx INT NOT NULL,
    category_name NVARCHAR(120) NOT NULL,
    parent_idx INT NOT NULL,
    depth INT NOT NULL,
    sort_order INT NOT NULL,
    option_1 NVARCHAR(120) NOT NULL,
    option_2 NVARCHAR(120) NOT NULL,
    option_3 NVARCHAR(120) NOT NULL,
    option_4 NVARCHAR(120) NOT NULL,
    option_5 NVARCHAR(120) NOT NULL,
    option_6 NVARCHAR(120) NOT NULL,
    option_7 NVARCHAR(120) NOT NULL,
    option_8 NVARCHAR(120) NOT NULL,
    option_9 NVARCHAR(120) NOT NULL,
    option_10 NVARCHAR(120) NOT NULL,
    is_deleted BIT NOT NULL,
    regdate DATETIME NOT NULL
);

DECLARE @ExprCatIdx NVARCHAR(MAX) = N'NULL';
DECLARE @ExprCatTabIdx NVARCHAR(MAX) = N'2';
DECLARE @ExprCatName NVARCHAR(MAX) = N'NULL';
DECLARE @ExprCatParentIdx NVARCHAR(MAX) = N'0';
DECLARE @ExprCatDepth NVARCHAR(MAX) = N'1';
DECLARE @ExprCatSort NVARCHAR(MAX) = N'0';
DECLARE @ExprCatRegdate NVARCHAR(MAX) = N'NULL';
DECLARE @ExprCatDeleted NVARCHAR(MAX) = N'0';
DECLARE @ExprCatOpt1 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt2 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt3 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt4 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt5 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt6 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt7 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt8 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt9 NVARCHAR(MAX) = N'''''';
DECLARE @ExprCatOpt10 NVARCHAR(MAX) = N'''''';

IF COL_LENGTH(@SrcCategoryObj, 'idx') IS NOT NULL
    SET @ExprCatIdx = N'TRY_CONVERT(INT, s.[idx])';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'id') IS NOT NULL
    SET @ExprCatIdx = N'TRY_CONVERT(INT, s.[id])';

IF COL_LENGTH(@SrcCategoryObj, 'tab_idx') IS NOT NULL
    SET @ExprCatTabIdx = N'ISNULL(NULLIF(TRY_CONVERT(INT, s.[tab_idx]), 0), 2)';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'item_tab_idx') IS NOT NULL
    SET @ExprCatTabIdx = N'ISNULL(NULLIF(TRY_CONVERT(INT, s.[item_tab_idx]), 0), 2)';

IF COL_LENGTH(@SrcCategoryObj, 'name') IS NOT NULL
    SET @ExprCatName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[name]))), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'category_name') IS NOT NULL
    SET @ExprCatName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[category_name]))), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'cat_name') IS NOT NULL
    SET @ExprCatName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[cat_name]))), '''')';

IF COL_LENGTH(@SrcCategoryObj, 'parent_idx') IS NOT NULL
    SET @ExprCatParentIdx = N'ISNULL(TRY_CONVERT(INT, s.[parent_idx]), 0)';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'up_idx') IS NOT NULL
    SET @ExprCatParentIdx = N'ISNULL(TRY_CONVERT(INT, s.[up_idx]), 0)';

IF COL_LENGTH(@SrcCategoryObj, 'depth') IS NOT NULL
    SET @ExprCatDepth = N'ISNULL(NULLIF(TRY_CONVERT(INT, s.[depth]), 0), 1)';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'lvl') IS NOT NULL
    SET @ExprCatDepth = N'ISNULL(NULLIF(TRY_CONVERT(INT, s.[lvl]), 0), 1)';

IF COL_LENGTH(@SrcCategoryObj, 'sort_order') IS NOT NULL
    SET @ExprCatSort = N'ISNULL(TRY_CONVERT(INT, s.[sort_order]), 0)';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'sort') IS NOT NULL
    SET @ExprCatSort = N'ISNULL(TRY_CONVERT(INT, s.[sort]), 0)';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'order_no') IS NOT NULL
    SET @ExprCatSort = N'ISNULL(TRY_CONVERT(INT, s.[order_no]), 0)';

IF COL_LENGTH(@SrcCategoryObj, 'regdate') IS NOT NULL
    SET @ExprCatRegdate = N'TRY_CONVERT(DATETIME, s.[regdate])';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'created_date') IS NOT NULL
    SET @ExprCatRegdate = N'TRY_CONVERT(DATETIME, s.[created_date])';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'created_at') IS NOT NULL
    SET @ExprCatRegdate = N'TRY_CONVERT(DATETIME, s.[created_at])';

IF COL_LENGTH(@SrcCategoryObj, 'is_deleted') IS NOT NULL
    SET @ExprCatDeleted = N'CASE WHEN TRY_CONVERT(INT, s.[is_deleted]) = 1 THEN 1 ELSE 0 END';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'del_yn') IS NOT NULL
    SET @ExprCatDeleted = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[del_yn])))) IN (''Y'',''1'',''TRUE'') THEN 1 ELSE 0 END';

IF COL_LENGTH(@SrcCategoryObj, 'option_1') IS NOT NULL SET @ExprCatOpt1 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_1]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option1') IS NOT NULL SET @ExprCatOpt1 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option1]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_2') IS NOT NULL SET @ExprCatOpt2 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_2]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option2') IS NOT NULL SET @ExprCatOpt2 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option2]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_3') IS NOT NULL SET @ExprCatOpt3 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_3]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option3') IS NOT NULL SET @ExprCatOpt3 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option3]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_4') IS NOT NULL SET @ExprCatOpt4 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_4]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option4') IS NOT NULL SET @ExprCatOpt4 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option4]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_5') IS NOT NULL SET @ExprCatOpt5 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_5]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option5') IS NOT NULL SET @ExprCatOpt5 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option5]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_6') IS NOT NULL SET @ExprCatOpt6 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_6]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option6') IS NOT NULL SET @ExprCatOpt6 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option6]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_7') IS NOT NULL SET @ExprCatOpt7 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_7]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option7') IS NOT NULL SET @ExprCatOpt7 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option7]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_8') IS NOT NULL SET @ExprCatOpt8 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_8]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option8') IS NOT NULL SET @ExprCatOpt8 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option8]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_9') IS NOT NULL SET @ExprCatOpt9 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_9]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option9') IS NOT NULL SET @ExprCatOpt9 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option9]), '''')';
IF COL_LENGTH(@SrcCategoryObj, 'option_10') IS NOT NULL SET @ExprCatOpt10 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option_10]), '''')';
ELSE IF COL_LENGTH(@SrcCategoryObj, 'option10') IS NOT NULL SET @ExprCatOpt10 = N'ISNULL(CONVERT(NVARCHAR(120), s.[option10]), '''')';

DECLARE @LoadCategorySql NVARCHAR(MAX) = N'
INSERT INTO #SrcItemCategory
(
    src_idx, tab_idx, category_name, parent_idx, depth, sort_order,
    option_1, option_2, option_3, option_4, option_5,
    option_6, option_7, option_8, option_9, option_10,
    is_deleted, regdate
)
SELECT
    ISNULL(NULLIF(' + @ExprCatIdx + N', 0), ROW_NUMBER() OVER (ORDER BY (SELECT NULL))) AS src_idx,
    ISNULL(NULLIF(' + @ExprCatTabIdx + N', 0), 2) AS tab_idx,
    ISNULL(' + @ExprCatName + N', N''카테고리'' + CONVERT(NVARCHAR(20), ROW_NUMBER() OVER (ORDER BY (SELECT NULL)))) AS category_name,
    ISNULL(' + @ExprCatParentIdx + N', 0) AS parent_idx,
    ISNULL(NULLIF(' + @ExprCatDepth + N', 0), 1) AS depth,
    ISNULL(' + @ExprCatSort + N', 0) AS sort_order,
    LEFT(ISNULL(' + @ExprCatOpt1 + N', N''''), 120) AS option_1,
    LEFT(ISNULL(' + @ExprCatOpt2 + N', N''''), 120) AS option_2,
    LEFT(ISNULL(' + @ExprCatOpt3 + N', N''''), 120) AS option_3,
    LEFT(ISNULL(' + @ExprCatOpt4 + N', N''''), 120) AS option_4,
    LEFT(ISNULL(' + @ExprCatOpt5 + N', N''''), 120) AS option_5,
    LEFT(ISNULL(' + @ExprCatOpt6 + N', N''''), 120) AS option_6,
    LEFT(ISNULL(' + @ExprCatOpt7 + N', N''''), 120) AS option_7,
    LEFT(ISNULL(' + @ExprCatOpt8 + N', N''''), 120) AS option_8,
    LEFT(ISNULL(' + @ExprCatOpt9 + N', N''''), 120) AS option_9,
    LEFT(ISNULL(' + @ExprCatOpt10 + N', N''''), 120) AS option_10,
    CAST(ISNULL(' + @ExprCatDeleted + N', 0) AS BIT) AS is_deleted,
    ISNULL(' + @ExprCatRegdate + N', GETDATE()) AS regdate
FROM ' + @SrcCategoryObj + N' s;';

EXEC sp_executesql @LoadCategorySql;

;WITH d AS (
    SELECT
        src_idx,
        ROW_NUMBER() OVER (PARTITION BY src_idx ORDER BY regdate DESC, sort_order ASC, src_idx ASC) AS rn
    FROM #SrcItemCategory
)
DELETE FROM d WHERE rn > 1;

IF NOT EXISTS (SELECT 1 FROM #SrcItemCategory)
BEGIN
    RAISERROR(N'[STOP] Source Tb_ItemCategory has no rows.', 16, 1);
    RETURN;
END;

/* ============================================================
   Source staging: Tb_Item
   ============================================================ */
IF OBJECT_ID('tempdb..#SrcItem') IS NOT NULL DROP TABLE #SrcItem;
CREATE TABLE #SrcItem (
    src_idx INT NOT NULL,
    item_code NVARCHAR(120) NOT NULL,
    item_name NVARCHAR(255) NOT NULL,
    standard NVARCHAR(255) NOT NULL,
    unit NVARCHAR(60) NOT NULL,
    tab_idx INT NOT NULL,
    category_idx INT NOT NULL,
    inventory_management NVARCHAR(10) NOT NULL,
    material_pattern NVARCHAR(20) NOT NULL,
    safety_count INT NOT NULL,
    base_count INT NOT NULL,
    memo NVARCHAR(1000) NOT NULL,
    regdate DATETIME NOT NULL,
    is_deleted BIT NOT NULL,
    use_yn NVARCHAR(1) NOT NULL,
    is_use BIT NOT NULL,
    is_active BIT NOT NULL
);

DECLARE @ExprItemIdx NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemCode NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemName NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemStandard NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemUnit NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemTabIdx NVARCHAR(MAX) = N'0';
DECLARE @ExprItemCategoryIdx NVARCHAR(MAX) = N'0';
DECLARE @ExprItemInventory NVARCHAR(MAX) = N'''유''';
DECLARE @ExprItemMaterialPattern NVARCHAR(MAX) = N'''''';
DECLARE @ExprItemSafety NVARCHAR(MAX) = N'0';
DECLARE @ExprItemBase NVARCHAR(MAX) = N'0';
DECLARE @ExprItemMemo NVARCHAR(MAX) = N'''''';
DECLARE @ExprItemRegdate NVARCHAR(MAX) = N'NULL';
DECLARE @ExprItemDeleted NVARCHAR(MAX) = N'0';
DECLARE @ExprItemUseYn NVARCHAR(MAX) = N'''Y''';
DECLARE @ExprItemIsUse NVARCHAR(MAX) = N'1';
DECLARE @ExprItemIsActive NVARCHAR(MAX) = N'1';

IF COL_LENGTH(@SrcItemObj, 'idx') IS NOT NULL
    SET @ExprItemIdx = N'TRY_CONVERT(INT, s.[idx])';
ELSE IF COL_LENGTH(@SrcItemObj, 'id') IS NOT NULL
    SET @ExprItemIdx = N'TRY_CONVERT(INT, s.[id])';

IF COL_LENGTH(@SrcItemObj, 'item_code') IS NOT NULL
    SET @ExprItemCode = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[item_code]))), '''')';
ELSE IF COL_LENGTH(@SrcItemObj, 'code') IS NOT NULL
    SET @ExprItemCode = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[code]))), '''')';

IF COL_LENGTH(@SrcItemObj, 'name') IS NOT NULL
    SET @ExprItemName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), s.[name]))), '''')';
ELSE IF COL_LENGTH(@SrcItemObj, 'item_name') IS NOT NULL
    SET @ExprItemName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), s.[item_name]))), '''')';

IF COL_LENGTH(@SrcItemObj, 'standard') IS NOT NULL
    SET @ExprItemStandard = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), s.[standard]))), '''')';
ELSE IF COL_LENGTH(@SrcItemObj, 'spec') IS NOT NULL
    SET @ExprItemStandard = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), s.[spec]))), '''')';

IF COL_LENGTH(@SrcItemObj, 'unit') IS NOT NULL
    SET @ExprItemUnit = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(60), s.[unit]))), '''')';

IF COL_LENGTH(@SrcItemObj, 'tab_idx') IS NOT NULL
    SET @ExprItemTabIdx = N'ISNULL(TRY_CONVERT(INT, s.[tab_idx]), 0)';
ELSE IF COL_LENGTH(@SrcItemObj, 'item_tab_idx') IS NOT NULL
    SET @ExprItemTabIdx = N'ISNULL(TRY_CONVERT(INT, s.[item_tab_idx]), 0)';

IF COL_LENGTH(@SrcItemObj, 'category_idx') IS NOT NULL
    SET @ExprItemCategoryIdx = N'ISNULL(TRY_CONVERT(INT, s.[category_idx]), 0)';
ELSE IF COL_LENGTH(@SrcItemObj, 'cat_idx') IS NOT NULL
    SET @ExprItemCategoryIdx = N'ISNULL(TRY_CONVERT(INT, s.[cat_idx]), 0)';

IF COL_LENGTH(@SrcItemObj, 'inventory_management') IS NOT NULL
    SET @ExprItemInventory = N'CASE WHEN LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[inventory_management]))) IN (N''유'', N''Y'', N''1'', N''TRUE'') THEN N''유'' ELSE N''무'' END';
ELSE IF COL_LENGTH(@SrcItemObj, 'stock_use') IS NOT NULL
    SET @ExprItemInventory = N'CASE WHEN TRY_CONVERT(INT, s.[stock_use]) = 1 THEN N''유'' ELSE N''무'' END';

IF COL_LENGTH(@SrcItemObj, 'material_pattern') IS NOT NULL
    SET @ExprItemMaterialPattern = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(20), s.[material_pattern]))), '''')';

IF COL_LENGTH(@SrcItemObj, 'safety_count') IS NOT NULL
    SET @ExprItemSafety = N'ISNULL(TRY_CONVERT(INT, s.[safety_count]), 0)';
ELSE IF COL_LENGTH(@SrcItemObj, 'safe_qty') IS NOT NULL
    SET @ExprItemSafety = N'ISNULL(TRY_CONVERT(INT, s.[safe_qty]), 0)';

IF COL_LENGTH(@SrcItemObj, 'base_count') IS NOT NULL
    SET @ExprItemBase = N'ISNULL(TRY_CONVERT(INT, s.[base_count]), 0)';
ELSE IF COL_LENGTH(@SrcItemObj, 'base_qty') IS NOT NULL
    SET @ExprItemBase = N'ISNULL(TRY_CONVERT(INT, s.[base_qty]), 0)';

IF COL_LENGTH(@SrcItemObj, 'memo') IS NOT NULL
    SET @ExprItemMemo = N'ISNULL(CONVERT(NVARCHAR(1000), s.[memo]), N'''')';
ELSE IF COL_LENGTH(@SrcItemObj, 'remark') IS NOT NULL
    SET @ExprItemMemo = N'ISNULL(CONVERT(NVARCHAR(1000), s.[remark]), N'''')';
ELSE IF COL_LENGTH(@SrcItemObj, 'note') IS NOT NULL
    SET @ExprItemMemo = N'ISNULL(CONVERT(NVARCHAR(1000), s.[note]), N'''')';

IF COL_LENGTH(@SrcItemObj, 'regdate') IS NOT NULL
    SET @ExprItemRegdate = N'TRY_CONVERT(DATETIME, s.[regdate])';
ELSE IF COL_LENGTH(@SrcItemObj, 'created_date') IS NOT NULL
    SET @ExprItemRegdate = N'TRY_CONVERT(DATETIME, s.[created_date])';
ELSE IF COL_LENGTH(@SrcItemObj, 'created_at') IS NOT NULL
    SET @ExprItemRegdate = N'TRY_CONVERT(DATETIME, s.[created_at])';

IF COL_LENGTH(@SrcItemObj, 'is_deleted') IS NOT NULL
    SET @ExprItemDeleted = N'CASE WHEN TRY_CONVERT(INT, s.[is_deleted]) = 1 THEN 1 ELSE 0 END';
ELSE IF COL_LENGTH(@SrcItemObj, 'del_yn') IS NOT NULL
    SET @ExprItemDeleted = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[del_yn])))) IN (''Y'',''1'',''TRUE'') THEN 1 ELSE 0 END';

IF COL_LENGTH(@SrcItemObj, 'use_yn') IS NOT NULL
BEGIN
    SET @ExprItemUseYn = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[use_yn])))) IN (''N'',''0'',''FALSE'') THEN N''N'' ELSE N''Y'' END';
    SET @ExprItemIsUse = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[use_yn])))) IN (''N'',''0'',''FALSE'') THEN 0 ELSE 1 END';
    SET @ExprItemIsActive = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(10), s.[use_yn])))) IN (''N'',''0'',''FALSE'') THEN 0 ELSE 1 END';
END;
ELSE IF COL_LENGTH(@SrcItemObj, 'is_use') IS NOT NULL
BEGIN
    SET @ExprItemUseYn = N'CASE WHEN TRY_CONVERT(INT, s.[is_use]) = 1 THEN N''Y'' ELSE N''N'' END';
    SET @ExprItemIsUse = N'CASE WHEN TRY_CONVERT(INT, s.[is_use]) = 1 THEN 1 ELSE 0 END';
    SET @ExprItemIsActive = N'CASE WHEN TRY_CONVERT(INT, s.[is_use]) = 1 THEN 1 ELSE 0 END';
END;
ELSE IF COL_LENGTH(@SrcItemObj, 'is_active') IS NOT NULL
BEGIN
    SET @ExprItemUseYn = N'CASE WHEN TRY_CONVERT(INT, s.[is_active]) = 1 THEN N''Y'' ELSE N''N'' END';
    SET @ExprItemIsUse = N'CASE WHEN TRY_CONVERT(INT, s.[is_active]) = 1 THEN 1 ELSE 0 END';
    SET @ExprItemIsActive = N'CASE WHEN TRY_CONVERT(INT, s.[is_active]) = 1 THEN 1 ELSE 0 END';
END;

DECLARE @LoadItemSql NVARCHAR(MAX) = N'
INSERT INTO #SrcItem
(
    src_idx, item_code, item_name, standard, unit,
    tab_idx, category_idx, inventory_management, material_pattern,
    safety_count, base_count, memo, regdate, is_deleted,
    use_yn, is_use, is_active
)
SELECT
    ISNULL(NULLIF(' + @ExprItemIdx + N', 0), ROW_NUMBER() OVER (ORDER BY (SELECT NULL))) AS src_idx,
    LEFT(ISNULL(' + @ExprItemCode + N', N''ITEM-'' + CONVERT(NVARCHAR(20), ROW_NUMBER() OVER (ORDER BY (SELECT NULL)))), 120) AS item_code,
    LEFT(ISNULL(' + @ExprItemName + N', N''품목'' + CONVERT(NVARCHAR(20), ROW_NUMBER() OVER (ORDER BY (SELECT NULL)))), 255) AS item_name,
    LEFT(ISNULL(' + @ExprItemStandard + N', N''''), 255) AS standard,
    LEFT(ISNULL(' + @ExprItemUnit + N', N''''), 60) AS unit,
    ISNULL(' + @ExprItemTabIdx + N', 0) AS tab_idx,
    ISNULL(' + @ExprItemCategoryIdx + N', 0) AS category_idx,
    CASE WHEN ' + @ExprItemInventory + N' = N''유'' THEN N''유'' ELSE N''무'' END AS inventory_management,
    LEFT(ISNULL(' + @ExprItemMaterialPattern + N', N''''), 20) AS material_pattern,
    ISNULL(' + @ExprItemSafety + N', 0) AS safety_count,
    ISNULL(' + @ExprItemBase + N', 0) AS base_count,
    LEFT(ISNULL(' + @ExprItemMemo + N', N''''), 1000) AS memo,
    ISNULL(' + @ExprItemRegdate + N', GETDATE()) AS regdate,
    CAST(ISNULL(' + @ExprItemDeleted + N', 0) AS BIT) AS is_deleted,
    CASE WHEN ' + @ExprItemUseYn + N' = N''N'' THEN N''N'' ELSE N''Y'' END AS use_yn,
    CAST(CASE WHEN ISNULL(' + @ExprItemIsUse + N', 1) = 1 THEN 1 ELSE 0 END AS BIT) AS is_use,
    CAST(CASE WHEN ISNULL(' + @ExprItemIsActive + N', 1) = 1 THEN 1 ELSE 0 END AS BIT) AS is_active
FROM ' + @SrcItemObj + N' s;';

EXEC sp_executesql @LoadItemSql;

;WITH d AS (
    SELECT
        src_idx,
        ROW_NUMBER() OVER (PARTITION BY src_idx ORDER BY regdate DESC, src_idx ASC) AS rn
    FROM #SrcItem
)
DELETE FROM d WHERE rn > 1;

IF NOT EXISTS (SELECT 1 FROM #SrcItem)
BEGIN
    RAISERROR(N'[STOP] Source Tb_Item has no rows.', 16, 1);
    RETURN;
END;

/* ============================================================
   Apply migration (ItemTab -> ItemCategory -> Item)
   ============================================================ */
DECLARE @HasIdentityTab BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_ItemTab'), 'idx', 'IsIdentity') = 1 THEN 1 ELSE 0 END;
DECLARE @HasIdentityCategory BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_ItemCategory'), 'idx', 'IsIdentity') = 1 THEN 1 ELSE 0 END;
DECLARE @HasIdentityItem BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_Item'), 'idx', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

DECLARE @TargetTabBefore INT = (SELECT COUNT(1) FROM dbo.Tb_ItemTab);
DECLARE @TargetCategoryBefore INT = (SELECT COUNT(1) FROM dbo.Tb_ItemCategory);
DECLARE @TargetItemBefore INT = (SELECT COUNT(1) FROM dbo.Tb_Item);

BEGIN TRANSACTION;

/* 1) Tb_ItemTab */
UPDATE t
SET
    t.name = s.tab_name,
    t.sort_order = s.sort_order,
    t.is_deleted = 0,
    t.regdate = ISNULL(s.regdate, t.regdate),
    t.updated_at = GETDATE()
FROM dbo.Tb_ItemTab t
INNER JOIN #SrcItemTab s
    ON s.src_idx = t.idx;

IF @HasIdentityTab = 1 SET IDENTITY_INSERT dbo.Tb_ItemTab ON;

INSERT INTO dbo.Tb_ItemTab (idx, name, sort_order, is_deleted, regdate, updated_at)
SELECT
    s.src_idx,
    s.tab_name,
    s.sort_order,
    0,
    s.regdate,
    GETDATE()
FROM #SrcItemTab s
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.Tb_ItemTab t WHERE t.idx = s.src_idx
);

IF @HasIdentityTab = 1 SET IDENTITY_INSERT dbo.Tb_ItemTab OFF;

BEGIN TRY
    DELETE t
    FROM dbo.Tb_ItemTab t
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItemTab s WHERE s.src_idx = t.idx
    );
END TRY
BEGIN CATCH
    UPDATE t
    SET
        t.is_deleted = 1,
        t.updated_at = GETDATE()
    FROM dbo.Tb_ItemTab t
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItemTab s WHERE s.src_idx = t.idx
    );
END CATCH;

/* 2) Tb_ItemCategory */
UPDATE c
SET
    c.tab_idx = s.tab_idx,
    c.name = s.category_name,
    c.parent_idx = s.parent_idx,
    c.depth = s.depth,
    c.sort_order = s.sort_order,
    c.option_1 = s.option_1,
    c.option_2 = s.option_2,
    c.option_3 = s.option_3,
    c.option_4 = s.option_4,
    c.option_5 = s.option_5,
    c.option_6 = s.option_6,
    c.option_7 = s.option_7,
    c.option_8 = s.option_8,
    c.option_9 = s.option_9,
    c.option_10 = s.option_10,
    c.is_deleted = s.is_deleted,
    c.regdate = ISNULL(s.regdate, c.regdate),
    c.updated_at = GETDATE()
FROM dbo.Tb_ItemCategory c
INNER JOIN #SrcItemCategory s
    ON s.src_idx = c.idx;

IF @HasIdentityCategory = 1 SET IDENTITY_INSERT dbo.Tb_ItemCategory ON;

INSERT INTO dbo.Tb_ItemCategory
(
    idx, tab_idx, name, parent_idx, depth, sort_order,
    option_1, option_2, option_3, option_4, option_5,
    option_6, option_7, option_8, option_9, option_10,
    is_deleted, regdate, updated_at
)
SELECT
    s.src_idx,
    s.tab_idx,
    s.category_name,
    s.parent_idx,
    s.depth,
    s.sort_order,
    s.option_1,
    s.option_2,
    s.option_3,
    s.option_4,
    s.option_5,
    s.option_6,
    s.option_7,
    s.option_8,
    s.option_9,
    s.option_10,
    s.is_deleted,
    s.regdate,
    GETDATE()
FROM #SrcItemCategory s
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.Tb_ItemCategory c WHERE c.idx = s.src_idx
);

IF @HasIdentityCategory = 1 SET IDENTITY_INSERT dbo.Tb_ItemCategory OFF;

BEGIN TRY
    DELETE c
    FROM dbo.Tb_ItemCategory c
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItemCategory s WHERE s.src_idx = c.idx
    );
END TRY
BEGIN CATCH
    UPDATE c
    SET
        c.is_deleted = 1,
        c.updated_at = GETDATE()
    FROM dbo.Tb_ItemCategory c
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItemCategory s WHERE s.src_idx = c.idx
    );
END CATCH;

/* 3) Tb_Item */
UPDATE i
SET
    i.item_code = s.item_code,
    i.name = s.item_name,
    i.standard = s.standard,
    i.unit = s.unit,
    i.tab_idx = s.tab_idx,
    i.category_idx = s.category_idx,
    i.inventory_management = s.inventory_management,
    i.material_pattern = s.material_pattern,
    i.safety_count = s.safety_count,
    i.base_count = s.base_count,
    i.memo = s.memo,
    i.origin_idx = s.src_idx,
    i.legacy_idx = s.src_idx,
    i.is_legacy_copy = 1,
    i.legacy_copied = 1,
    i.is_migrated = 1,
    i.use_yn = s.use_yn,
    i.is_use = s.is_use,
    i.is_active = s.is_active,
    i.is_deleted = s.is_deleted,
    i.regdate = ISNULL(s.regdate, i.regdate),
    i.updated_at = GETDATE(),
    i.updated_by = 0
FROM dbo.Tb_Item i
INNER JOIN #SrcItem s
    ON s.src_idx = i.idx;

IF @HasIdentityItem = 1 SET IDENTITY_INSERT dbo.Tb_Item ON;

INSERT INTO dbo.Tb_Item
(
    idx,
    item_code,
    name,
    standard,
    unit,
    tab_idx,
    category_idx,
    inventory_management,
    material_pattern,
    safety_count,
    base_count,
    origin_idx,
    legacy_idx,
    is_legacy_copy,
    legacy_copied,
    is_migrated,
    memo,
    use_yn,
    is_use,
    is_active,
    created_by,
    created_at,
    updated_by,
    updated_at,
    is_deleted,
    regdate
)
SELECT
    s.src_idx,
    s.item_code,
    s.item_name,
    s.standard,
    s.unit,
    s.tab_idx,
    s.category_idx,
    s.inventory_management,
    s.material_pattern,
    s.safety_count,
    s.base_count,
    s.src_idx,
    s.src_idx,
    1,
    1,
    1,
    s.memo,
    s.use_yn,
    s.is_use,
    s.is_active,
    0,
    ISNULL(s.regdate, GETDATE()),
    0,
    GETDATE(),
    s.is_deleted,
    ISNULL(s.regdate, GETDATE())
FROM #SrcItem s
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.Tb_Item i WHERE i.idx = s.src_idx
);

IF @HasIdentityItem = 1 SET IDENTITY_INSERT dbo.Tb_Item OFF;

BEGIN TRY
    DELETE i
    FROM dbo.Tb_Item i
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItem s WHERE s.src_idx = i.idx
    );
END TRY
BEGIN CATCH
    UPDATE i
    SET
        i.is_deleted = 1,
        i.deleted_at = GETDATE(),
        i.deleted_by = 0,
        i.updated_at = GETDATE(),
        i.updated_by = 0
    FROM dbo.Tb_Item i
    WHERE NOT EXISTS (
        SELECT 1 FROM #SrcItem s WHERE s.src_idx = i.idx
    );
END CATCH;

COMMIT TRANSACTION;

DECLARE @TargetTabAfter INT = (SELECT COUNT(1) FROM dbo.Tb_ItemTab);
DECLARE @TargetCategoryAfter INT = (SELECT COUNT(1) FROM dbo.Tb_ItemCategory);
DECLARE @TargetItemAfter INT = (SELECT COUNT(1) FROM dbo.Tb_Item);

PRINT N'[OK] MAT V1->V2 migration completed';

SELECT
    (SELECT COUNT(1) FROM #SrcItemTab) AS src_tab_count,
    @TargetTabBefore AS target_tab_before,
    @TargetTabAfter AS target_tab_after,
    (SELECT COUNT(1) FROM #SrcItemCategory) AS src_category_count,
    @TargetCategoryBefore AS target_category_before,
    @TargetCategoryAfter AS target_category_after,
    (SELECT COUNT(1) FROM #SrcItem) AS src_item_count,
    @TargetItemBefore AS target_item_before,
    @TargetItemAfter AS target_item_after;
