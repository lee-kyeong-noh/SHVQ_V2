/*
  SHVQ_V2 - Wave 1 Member Branch Table Bootstrap
  DB: CSM_C004732_V2 (67번 개발DB)
  목적: Tb_Members 누락/부분 스키마를 Member.php 요구사항 기준으로 보정
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Members (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        name NVARCHAR(120) NOT NULL,
        ceo NVARCHAR(80) NULL,
        card_number NVARCHAR(20) NULL,
        tel NVARCHAR(40) NULL,
        email NVARCHAR(120) NULL,
        address NVARCHAR(255) NULL,
        address_detail NVARCHAR(255) NULL,
        zipcode NVARCHAR(20) NULL,
        cooperation_contract NVARCHAR(50) NULL,
        manager_name NVARCHAR(80) NULL,
        manager_tel NVARCHAR(40) NULL,
        memo NVARCHAR(2000) NULL,
        region NVARCHAR(50) NULL,
        head_idx INT NULL,
        member_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Members_member_status DEFAULT (N'예정'),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Members_is_deleted DEFAULT (0),
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Members_created_at DEFAULT (GETDATE()),
        created_by INT NULL,
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Members_updated_at DEFAULT (GETDATE()),
        updated_by INT NULL,
        deleted_at DATETIME NULL,
        deleted_by INT NULL,
        regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Members_regdate DEFAULT (GETDATE()),
        registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_Members_registered_date DEFAULT (GETDATE())
    );
END;
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Members', 'name') IS NULL
        ALTER TABLE dbo.Tb_Members ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_Members_name_patch DEFAULT (N'') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'ceo') IS NULL
        ALTER TABLE dbo.Tb_Members ADD ceo NVARCHAR(80) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'card_number') IS NULL
        ALTER TABLE dbo.Tb_Members ADD card_number NVARCHAR(20) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'tel') IS NULL
        ALTER TABLE dbo.Tb_Members ADD tel NVARCHAR(40) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'email') IS NULL
        ALTER TABLE dbo.Tb_Members ADD email NVARCHAR(120) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'address') IS NULL
        ALTER TABLE dbo.Tb_Members ADD address NVARCHAR(255) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'address_detail') IS NULL
        ALTER TABLE dbo.Tb_Members ADD address_detail NVARCHAR(255) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'zipcode') IS NULL
        ALTER TABLE dbo.Tb_Members ADD zipcode NVARCHAR(20) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'cooperation_contract') IS NULL
        ALTER TABLE dbo.Tb_Members ADD cooperation_contract NVARCHAR(50) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'manager_name') IS NULL
        ALTER TABLE dbo.Tb_Members ADD manager_name NVARCHAR(80) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'manager_tel') IS NULL
        ALTER TABLE dbo.Tb_Members ADD manager_tel NVARCHAR(40) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'memo') IS NULL
        ALTER TABLE dbo.Tb_Members ADD memo NVARCHAR(2000) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'region') IS NULL
        ALTER TABLE dbo.Tb_Members ADD region NVARCHAR(50) NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'head_idx') IS NULL
        ALTER TABLE dbo.Tb_Members ADD head_idx INT NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'member_status') IS NULL
        ALTER TABLE dbo.Tb_Members ADD member_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Members_member_status_patch DEFAULT (N'예정') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_Members ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Members_is_deleted_patch DEFAULT (0) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_Members ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Members_created_at_patch DEFAULT (GETDATE()) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_Members ADD created_by INT NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_Members ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Members_updated_at_patch DEFAULT (GETDATE()) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_Members ADD updated_by INT NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_Members ADD deleted_at DATETIME NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'deleted_by') IS NULL
        ALTER TABLE dbo.Tb_Members ADD deleted_by INT NULL;

    IF COL_LENGTH('dbo.Tb_Members', 'regdate') IS NULL
        ALTER TABLE dbo.Tb_Members ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Members_regdate_patch DEFAULT (GETDATE()) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_Members', 'registered_date') IS NULL
        ALTER TABLE dbo.Tb_Members ADD registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_Members_registered_date_patch DEFAULT (GETDATE()) WITH VALUES;
END;
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_Members', 'member_status') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.check_constraints
        WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'CK_Tb_Members_member_status'
   )
BEGIN
    ALTER TABLE dbo.Tb_Members WITH NOCHECK
    ADD CONSTRAINT CK_Tb_Members_member_status
    CHECK (member_status IN (N'예정', N'운영', N'중지', N'종료'));
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
BEGIN
    ALTER TABLE dbo.Tb_Members WITH NOCHECK
    ADD CONSTRAINT FK_Tb_Members_HeadOffice_HeadIdx
    FOREIGN KEY (head_idx) REFERENCES dbo.Tb_HeadOffice(idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'IX_Tb_Members_IsDeleted_Status'
    )
    BEGIN
        CREATE INDEX IX_Tb_Members_IsDeleted_Status
            ON dbo.Tb_Members(is_deleted, member_status, idx DESC);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'IX_Tb_Members_HeadIdx'
    )
    BEGIN
        CREATE INDEX IX_Tb_Members_HeadIdx
            ON dbo.Tb_Members(head_idx, is_deleted, idx DESC);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'IX_Tb_Members_CardNumber'
    )
    BEGIN
        CREATE INDEX IX_Tb_Members_CardNumber
            ON dbo.Tb_Members(card_number, is_deleted, idx DESC);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'IX_Tb_Members_Name'
    )
    BEGIN
        CREATE INDEX IX_Tb_Members_Name
            ON dbo.Tb_Members(name, is_deleted, idx DESC);
    END;
END;
GO

SELECT
    OBJECT_ID(N'dbo.Tb_Members', N'U') AS table_object_id,
    (SELECT COUNT(1) FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')) AS column_count,
    (SELECT COUNT(1) FROM sys.foreign_keys WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_Members')) AS fk_count,
    (SELECT COUNT(1) FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.Tb_Members') AND is_hypothetical = 0) AS index_count;
GO

SELECT
    c.column_id,
    c.name,
    t.name AS data_type,
    c.max_length,
    c.is_nullable
FROM sys.columns c
JOIN sys.types t ON c.user_type_id = t.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.Tb_Members')
ORDER BY c.column_id;
GO
