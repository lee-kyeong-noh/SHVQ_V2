/*
  SHVQ_V2 - Wave1 MAT Item Bootstrap
  목적:
  1) MAT CRUD가 사용하는 Tb_Item 테이블 보장
  2) MAT 화면 필터/드롭다운용 Tb_ItemTab, Tb_ItemCategory 최소 스키마 보장
  3) 재실행해도 안전(idempotent)
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

/* ============================================================
   Tb_Item
   ============================================================ */
IF OBJECT_ID(N'dbo.Tb_Item', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Item (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        item_code NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_Item_item_code DEFAULT N'',
        name NVARCHAR(255) NOT NULL CONSTRAINT DF_Tb_Item_name DEFAULT N'',
        standard NVARCHAR(255) NOT NULL CONSTRAINT DF_Tb_Item_standard DEFAULT N'',
        unit NVARCHAR(60) NOT NULL CONSTRAINT DF_Tb_Item_unit DEFAULT N'',
        tab_idx INT NOT NULL CONSTRAINT DF_Tb_Item_tab_idx DEFAULT 0,
        category_idx INT NOT NULL CONSTRAINT DF_Tb_Item_category_idx DEFAULT 0,
        inventory_management NVARCHAR(10) NOT NULL CONSTRAINT DF_Tb_Item_inventory_management DEFAULT N'무',
        material_pattern NVARCHAR(20) NULL,
        safety_count INT NOT NULL CONSTRAINT DF_Tb_Item_safety_count DEFAULT 0,
        base_count INT NOT NULL CONSTRAINT DF_Tb_Item_base_count DEFAULT 0,
        origin_idx INT NULL,
        legacy_idx INT NULL,
        is_legacy_copy BIT NOT NULL CONSTRAINT DF_Tb_Item_is_legacy_copy DEFAULT 0,
        legacy_copied BIT NOT NULL CONSTRAINT DF_Tb_Item_legacy_copied DEFAULT 0,
        is_migrated BIT NOT NULL CONSTRAINT DF_Tb_Item_is_migrated DEFAULT 0,
        memo NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_memo DEFAULT N'',
        remark NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_remark DEFAULT N'',
        remarks NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_remarks DEFAULT N'',
        note NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_note DEFAULT N'',
        price DECIMAL(18,2) NOT NULL CONSTRAINT DF_Tb_Item_price DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL CONSTRAINT DF_Tb_Item_cost DEFAULT 0,
        use_yn NVARCHAR(1) NOT NULL CONSTRAINT DF_Tb_Item_use_yn DEFAULT N'Y',
        is_use BIT NOT NULL CONSTRAINT DF_Tb_Item_is_use DEFAULT 1,
        is_active BIT NOT NULL CONSTRAINT DF_Tb_Item_is_active DEFAULT 1,
        created_by INT NOT NULL CONSTRAINT DF_Tb_Item_created_by DEFAULT 0,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Item_created_at DEFAULT GETDATE(),
        updated_by INT NOT NULL CONSTRAINT DF_Tb_Item_updated_by DEFAULT 0,
        updated_at DATETIME NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Item_is_deleted DEFAULT 0,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Item_regdate DEFAULT GETDATE()
    );
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'item_code') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD item_code NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_Item_item_code_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'name') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD name NVARCHAR(255) NOT NULL CONSTRAINT DF_Tb_Item_name_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'standard') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD standard NVARCHAR(255) NOT NULL CONSTRAINT DF_Tb_Item_standard_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'unit') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD unit NVARCHAR(60) NOT NULL CONSTRAINT DF_Tb_Item_unit_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'tab_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD tab_idx INT NOT NULL CONSTRAINT DF_Tb_Item_tab_idx_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'category_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD category_idx INT NOT NULL CONSTRAINT DF_Tb_Item_category_idx_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'inventory_management') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD inventory_management NVARCHAR(10) NOT NULL CONSTRAINT DF_Tb_Item_inventory_management_A DEFAULT N'무';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'material_pattern') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD material_pattern NVARCHAR(20) NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'safety_count') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD safety_count INT NOT NULL CONSTRAINT DF_Tb_Item_safety_count_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'base_count') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD base_count INT NOT NULL CONSTRAINT DF_Tb_Item_base_count_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'origin_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD origin_idx INT NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'legacy_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD legacy_idx INT NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'is_legacy_copy') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD is_legacy_copy BIT NOT NULL CONSTRAINT DF_Tb_Item_is_legacy_copy_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'legacy_copied') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD legacy_copied BIT NOT NULL CONSTRAINT DF_Tb_Item_legacy_copied_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'is_migrated') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD is_migrated BIT NOT NULL CONSTRAINT DF_Tb_Item_is_migrated_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'memo') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD memo NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_memo_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'remark') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD remark NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_remark_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'remarks') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD remarks NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_remarks_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'note') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD note NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_Item_note_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'price') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD price DECIMAL(18,2) NOT NULL CONSTRAINT DF_Tb_Item_price_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'cost') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD cost DECIMAL(18,2) NOT NULL CONSTRAINT DF_Tb_Item_cost_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'use_yn') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD use_yn NVARCHAR(1) NOT NULL CONSTRAINT DF_Tb_Item_use_yn_A DEFAULT N'Y';
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'is_use') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD is_use BIT NOT NULL CONSTRAINT DF_Tb_Item_is_use_A DEFAULT 1;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'is_active') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD is_active BIT NOT NULL CONSTRAINT DF_Tb_Item_is_active_A DEFAULT 1;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'created_by') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD created_by INT NOT NULL CONSTRAINT DF_Tb_Item_created_by_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'created_at') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Item_created_at_A DEFAULT GETDATE();
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'updated_by') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD updated_by INT NOT NULL CONSTRAINT DF_Tb_Item_updated_by_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'updated_at') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD updated_at DATETIME NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'is_deleted') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Item_is_deleted_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'deleted_by') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD deleted_by INT NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'deleted_at') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD deleted_at DATETIME NULL;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'regdate') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Item_regdate_A DEFAULT GETDATE();
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Item')
      AND name = N'IX_Tb_Item_ItemCode'
)
BEGIN
    CREATE INDEX IX_Tb_Item_ItemCode ON dbo.Tb_Item(item_code);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Item')
      AND name = N'IX_Tb_Item_Name'
)
BEGIN
    CREATE INDEX IX_Tb_Item_Name ON dbo.Tb_Item(name);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Item')
      AND name = N'IX_Tb_Item_TabCategory'
)
BEGIN
    CREATE INDEX IX_Tb_Item_TabCategory ON dbo.Tb_Item(tab_idx, category_idx, idx);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Item')
      AND name = N'IX_Tb_Item_IsDeleted'
)
BEGIN
    CREATE INDEX IX_Tb_Item_IsDeleted ON dbo.Tb_Item(is_deleted, idx);
END;
GO

/* ============================================================
   Tb_ItemTab (최소 스키마)
   ============================================================ */
IF OBJECT_ID(N'dbo.Tb_ItemTab', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ItemTab (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemTab_name DEFAULT N'',
        sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemTab_sort_order DEFAULT 0,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemTab_is_deleted DEFAULT 0,
        regdate DATETIME NOT NULL CONSTRAINT DF_Tb_ItemTab_regdate DEFAULT GETDATE(),
        updated_at DATETIME NULL
    );
END;
GO

IF COL_LENGTH('dbo.Tb_ItemTab', 'name') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemTab ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemTab_name_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_ItemTab', 'sort_order') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemTab ADD sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemTab_sort_order_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemTab', 'is_deleted') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemTab ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemTab_is_deleted_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemTab', 'regdate') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemTab ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_ItemTab_regdate_A DEFAULT GETDATE();
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemTab')
      AND name = N'IX_Tb_ItemTab_Sort'
)
BEGIN
    CREATE INDEX IX_Tb_ItemTab_Sort ON dbo.Tb_ItemTab(sort_order, idx);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Tb_ItemTab)
BEGIN
    INSERT INTO dbo.Tb_ItemTab (name, sort_order, is_deleted, regdate)
    VALUES (N'기본', 1, 0, GETDATE());
END;
GO

/* ============================================================
   Tb_ItemCategory (최소 스키마 + option_1~10)
   ============================================================ */
IF OBJECT_ID(N'dbo.Tb_ItemCategory', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ItemCategory (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        tab_idx INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_tab_idx DEFAULT 0,
        name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_name DEFAULT N'',
        parent_idx INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_parent_idx DEFAULT 0,
        depth INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_depth DEFAULT 1,
        sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_sort_order DEFAULT 0,
        option_1 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_1 DEFAULT N'',
        option_2 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_2 DEFAULT N'',
        option_3 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_3 DEFAULT N'',
        option_4 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_4 DEFAULT N'',
        option_5 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_5 DEFAULT N'',
        option_6 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_6 DEFAULT N'',
        option_7 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_7 DEFAULT N'',
        option_8 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_8 DEFAULT N'',
        option_9 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_9 DEFAULT N'',
        option_10 NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_option_10 DEFAULT N'',
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemCategory_is_deleted DEFAULT 0,
        regdate DATETIME NOT NULL CONSTRAINT DF_Tb_ItemCategory_regdate DEFAULT GETDATE(),
        updated_at DATETIME NULL
    );
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'tab_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD tab_idx INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_tab_idx_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'name') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_ItemCategory_name_A DEFAULT N'';
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'parent_idx') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD parent_idx INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_parent_idx_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'depth') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD depth INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_depth_A DEFAULT 1;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'sort_order') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD sort_order INT NOT NULL CONSTRAINT DF_Tb_ItemCategory_sort_order_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'is_deleted') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ItemCategory_is_deleted_A DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_ItemCategory', 'regdate') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_ItemCategory ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_ItemCategory_regdate_A DEFAULT GETDATE();
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemCategory')
      AND name = N'IX_Tb_ItemCategory_ParentSort'
)
BEGIN
    CREATE INDEX IX_Tb_ItemCategory_ParentSort ON dbo.Tb_ItemCategory(parent_idx, sort_order, idx);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_ItemCategory')
      AND name = N'IX_Tb_ItemCategory_TabParentSort'
)
BEGIN
    CREATE INDEX IX_Tb_ItemCategory_TabParentSort ON dbo.Tb_ItemCategory(tab_idx, parent_idx, sort_order, idx);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Tb_ItemCategory)
BEGIN
    INSERT INTO dbo.Tb_ItemCategory (name, parent_idx, depth, sort_order, is_deleted, regdate)
    VALUES (N'기본분류', 0, 1, 1, 0, GETDATE());
END;
GO

PRINT N'[OK] Wave1 MAT item bootstrap migration completed';
