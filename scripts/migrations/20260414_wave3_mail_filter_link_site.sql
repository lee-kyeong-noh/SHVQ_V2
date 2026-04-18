/*
  SHVQ_V2 - Wave3 Mail FilterRule / SiteMail Patch
  목적:
  1) Tb_Mail_FilterRule 테이블 생성/보완 (account_idx + service_code + tenant_id 스코프)
  2) Tb_Site_Mail(V1 매핑 테이블) V2 스코프 컬럼 생성/보완

  실행 DB: CSM_C004732_V2
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FilterRule', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Mail_FilterRule (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        account_idx INT NOT NULL,
        service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ServiceCode DEFAULT ('shvq'),
        tenant_id INT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_TenantId DEFAULT (0),
        from_address NVARCHAR(300) NOT NULL,
        target_folder NVARCHAR(200) NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_IsActive DEFAULT (1),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_UpdatedAt DEFAULT (GETDATE())
    );
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FilterRule', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'account_idx') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD account_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'service_code') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_ServiceCode_Alt DEFAULT ('shvq');
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'tenant_id') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD tenant_id INT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_TenantId_Alt DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'from_address') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD from_address NVARCHAR(300) NULL;
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'target_folder') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD target_folder NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'is_active') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD is_active BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_IsActive_Alt DEFAULT (1);
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_IsDeleted_Alt DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_CreatedAt_Alt DEFAULT (GETDATE());
    IF COL_LENGTH('dbo.Tb_Mail_FilterRule', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FilterRule ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FilterRule_UpdatedAt_Alt DEFAULT (GETDATE());

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_FilterRule')
          AND name = N'IX_Tb_Mail_FilterRule_AccountScope'
    )
    BEGIN
        CREATE INDEX IX_Tb_Mail_FilterRule_AccountScope
            ON dbo.Tb_Mail_FilterRule(account_idx, service_code, tenant_id, is_active, is_deleted, id DESC);
    END
END;
GO

IF OBJECT_ID(N'dbo.Tb_Site_Mail', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Site_Mail (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Site_Mail_ServiceCode DEFAULT ('shvq'),
        tenant_id INT NOT NULL CONSTRAINT DF_Tb_Site_Mail_TenantId DEFAULT (0),
        account_idx INT NOT NULL,
        site_idx INT NOT NULL,
        mail_cache_id INT NULL,
        mail_uid BIGINT NULL,
        mail_folder NVARCHAR(255) NULL,
        message_id NVARCHAR(500) NULL,
        subject NVARCHAR(500) NULL,
        from_address NVARCHAR(320) NULL,
        employee_idx INT NULL,
        status VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_Mail_Status DEFAULT ('ACTIVE'),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Site_Mail_IsDeleted DEFAULT (0),
        linked_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_UpdatedAt DEFAULT (GETDATE())
    );
END;
GO

IF OBJECT_ID(N'dbo.Tb_Site_Mail', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'service_code') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD service_code VARCHAR(30) NOT NULL CONSTRAINT DF_Tb_Site_Mail_ServiceCode_Alt DEFAULT ('shvq');
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'tenant_id') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD tenant_id INT NOT NULL CONSTRAINT DF_Tb_Site_Mail_TenantId_Alt DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'account_idx') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD account_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'site_idx') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD site_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'mail_cache_id') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD mail_cache_id INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'mail_uid') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD mail_uid BIGINT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'mail_folder') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD mail_folder NVARCHAR(255) NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'message_id') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD message_id NVARCHAR(500) NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'subject') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD subject NVARCHAR(500) NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'from_address') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD from_address NVARCHAR(320) NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'employee_idx') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD employee_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'status') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD status VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_Mail_Status_Alt DEFAULT ('ACTIVE');
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Site_Mail_IsDeleted_Alt DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'linked_at') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD linked_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_CreatedAt_Alt DEFAULT (GETDATE());
    IF COL_LENGTH('dbo.Tb_Site_Mail', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_Site_Mail ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_Mail_UpdatedAt_Alt DEFAULT (GETDATE());

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Site_Mail')
          AND name = N'IX_Tb_Site_Mail_AccountSiteUid'
    )
    BEGIN
        CREATE INDEX IX_Tb_Site_Mail_AccountSiteUid
            ON dbo.Tb_Site_Mail(service_code, tenant_id, account_idx, site_idx, mail_uid, is_deleted, idx DESC);
    END
END;
GO

PRINT N'[OK] Wave3 mail filter/site-link schema patch complete.';
