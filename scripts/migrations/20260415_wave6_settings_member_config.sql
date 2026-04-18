SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave6 member settings schema start ===';

IF OBJECT_ID(N'dbo.Tb_UserSettings', N'U') IS NULL
BEGIN
    PRINT 'Create dbo.Tb_UserSettings (key-value compatible)';
    CREATE TABLE dbo.Tb_UserSettings (
        idx            INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code   NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_UserSettings_service_code DEFAULT(''),
        tenant_id      INT NOT NULL CONSTRAINT DF_Tb_UserSettings_tenant_id DEFAULT(0),
        setting_group  NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_group DEFAULT('member'),
        setting_key    NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_key DEFAULT(''),
        setting_value  NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_value DEFAULT(''),
        setting_type   NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_type DEFAULT('json'),
        user_id        NVARCHAR(80) NOT NULL CONSTRAINT DF_Tb_UserSettings_user_id DEFAULT(''),
        user_idx       INT NOT NULL CONSTRAINT DF_Tb_UserSettings_user_idx DEFAULT(0),
        employee_idx   INT NOT NULL CONSTRAINT DF_Tb_UserSettings_employee_idx DEFAULT(0),
        member_idx     INT NOT NULL CONSTRAINT DF_Tb_UserSettings_member_idx DEFAULT(0),
        created_by     INT NULL,
        created_at     DATETIME NULL,
        updated_by     INT NOT NULL CONSTRAINT DF_Tb_UserSettings_updated_by DEFAULT(0),
        updated_at     DATETIME NULL,
        is_deleted     BIT NOT NULL CONSTRAINT DF_Tb_UserSettings_is_deleted DEFAULT(0),
        regdate        DATETIME NOT NULL CONSTRAINT DF_Tb_UserSettings_regdate DEFAULT(GETDATE())
    );
END
ELSE
BEGIN
    PRINT 'Patch dbo.Tb_UserSettings missing columns';
    IF COL_LENGTH('dbo.Tb_UserSettings', 'service_code') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD service_code NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_UserSettings_service_code_patch DEFAULT('');
    IF COL_LENGTH('dbo.Tb_UserSettings', 'tenant_id') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD tenant_id INT NOT NULL CONSTRAINT DF_Tb_UserSettings_tenant_id_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_UserSettings', 'setting_group') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD setting_group NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_group_patch DEFAULT('member');
    IF COL_LENGTH('dbo.Tb_UserSettings', 'setting_key') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD setting_key NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_key_patch DEFAULT('');
    IF COL_LENGTH('dbo.Tb_UserSettings', 'setting_value') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD setting_value NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_value_patch DEFAULT('');
    IF COL_LENGTH('dbo.Tb_UserSettings', 'setting_type') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD setting_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_UserSettings_setting_type_patch DEFAULT('json');

    IF COL_LENGTH('dbo.Tb_UserSettings', 'user_id') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD user_id NVARCHAR(80) NOT NULL CONSTRAINT DF_Tb_UserSettings_user_id_patch DEFAULT('');
    IF COL_LENGTH('dbo.Tb_UserSettings', 'user_idx') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD user_idx INT NOT NULL CONSTRAINT DF_Tb_UserSettings_user_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_UserSettings', 'employee_idx') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD employee_idx INT NOT NULL CONSTRAINT DF_Tb_UserSettings_employee_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_UserSettings', 'member_idx') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD member_idx INT NOT NULL CONSTRAINT DF_Tb_UserSettings_member_idx_patch DEFAULT(0);

    IF COL_LENGTH('dbo.Tb_UserSettings', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_UserSettings', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD created_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_UserSettings', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD updated_by INT NOT NULL CONSTRAINT DF_Tb_UserSettings_updated_by_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_UserSettings', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD updated_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_UserSettings', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_UserSettings_is_deleted_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_UserSettings', 'regdate') IS NULL
        ALTER TABLE dbo.Tb_UserSettings ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_UserSettings_regdate_patch DEFAULT(GETDATE());
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_UserSettings')
      AND name = N'IX_Tb_UserSettings_scope_key'
)
BEGIN
    CREATE INDEX IX_Tb_UserSettings_scope_key
        ON dbo.Tb_UserSettings(service_code, tenant_id, setting_group, setting_key, is_deleted, idx);
END

PRINT '=== Wave6 member settings schema done ===';
