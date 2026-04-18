/*
  SHVQ_V2 - Integration Tables
  실행 전 DB명 확인: USE [CSM_C004732_V2]
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_IntProviderAccount', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntProviderAccount (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,           -- mail/onvif/smartthings/tuya
        account_key NVARCHAR(120) NOT NULL,      -- provider user id/email 등
        user_pk INT NULL,                         -- 소유 사용자(PK)
        display_name NVARCHAR(200) NOT NULL DEFAULT '',
        is_primary BIT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        raw_json NVARCHAR(MAX) NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_IntProviderAccount_TenantProviderKey
        ON dbo.Tb_IntProviderAccount(service_code, tenant_id, provider, account_key);
    CREATE INDEX IX_Tb_IntProviderAccount_TenantProvider
        ON dbo.Tb_IntProviderAccount(service_code, tenant_id, provider, status);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntProviderAccount', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_IntProviderAccount', 'user_pk') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_IntProviderAccount
        ADD user_pk INT NULL;
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntProviderAccount', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_IntProviderAccount', 'user_pk') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE object_id = OBJECT_ID(N'dbo.Tb_IntProviderAccount')
         AND name = N'IX_Tb_IntProviderAccount_UserScope'
   )
BEGIN
    CREATE INDEX IX_Tb_IntProviderAccount_UserScope
        ON dbo.Tb_IntProviderAccount(service_code, tenant_id, provider, user_pk, status, idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntCredential', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntCredential (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        provider_account_idx INT NOT NULL,
        secret_type VARCHAR(40) NOT NULL,      -- access_token, refresh_token, password, pat
        secret_value_enc NVARCHAR(MAX) NOT NULL,
        expires_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_IntCredential_AccountType
        ON dbo.Tb_IntCredential(provider_account_idx, secret_type, status);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntDevice', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntDevice (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,
        provider_account_idx INT NOT NULL,
        external_id NVARCHAR(140) NOT NULL,
        device_label NVARCHAR(200) NOT NULL DEFAULT '',
        location_id NVARCHAR(120) NOT NULL DEFAULT '',
        location_name NVARCHAR(200) NOT NULL DEFAULT '',
        room_id NVARCHAR(120) NOT NULL DEFAULT '',
        room_name NVARCHAR(200) NOT NULL DEFAULT '',
        capability_json NVARCHAR(MAX) NULL,
        last_state NVARCHAR(100) NOT NULL DEFAULT '',
        health_state NVARCHAR(30) NOT NULL DEFAULT '',
        is_active BIT NOT NULL DEFAULT 1,
        last_sync_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_IntDevice_TenantProviderExternal
        ON dbo.Tb_IntDevice(service_code, tenant_id, provider, external_id);
    CREATE INDEX IX_Tb_IntDevice_AccountActive
        ON dbo.Tb_IntDevice(provider_account_idx, is_active, idx);
    CREATE INDEX IX_Tb_IntDevice_TenantUpdated
        ON dbo.Tb_IntDevice(service_code, tenant_id, updated_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntDeviceMap', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntDeviceMap (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        device_idx BIGINT NOT NULL,
        domain_type VARCHAR(40) NOT NULL,      -- site/member/pjt/space/user...
        domain_idx INT NOT NULL DEFAULT 0,
        map_note NVARCHAR(300) NOT NULL DEFAULT '',
        is_deleted BIT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_IntDeviceMap_TenantDomain
        ON dbo.Tb_IntDeviceMap(service_code, tenant_id, domain_type, domain_idx, is_deleted);
    CREATE INDEX IX_Tb_IntDeviceMap_Device
        ON dbo.Tb_IntDeviceMap(device_idx, is_deleted);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntSyncCheckpoint', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntSyncCheckpoint (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider_account_idx INT NOT NULL,
        sync_scope VARCHAR(40) NOT NULL,         -- inbox/devices/events/health
        cursor_value NVARCHAR(300) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'IDLE',
        last_success_at DATETIME NULL,
        last_error NVARCHAR(1000) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_IntSyncCheckpoint_AccountScope
        ON dbo.Tb_IntSyncCheckpoint(provider_account_idx, sync_scope);
END;
GO

IF OBJECT_ID(N'dbo.Tb_IntErrorQueue', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntErrorQueue (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,
        job_type VARCHAR(40) NOT NULL,           -- sync/control/webhook/notification
        payload_json NVARCHAR(MAX) NOT NULL,
        error_message NVARCHAR(1000) NOT NULL DEFAULT '',
        retry_count INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT GETDATE(),
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_IntErrorQueue_Pending
        ON dbo.Tb_IntErrorQueue(status, next_retry_at, idx);
    CREATE INDEX IX_Tb_IntErrorQueue_Tenant
        ON dbo.Tb_IntErrorQueue(service_code, tenant_id, provider, created_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Mail_FolderSyncState (
        account_idx INT NOT NULL,
        folder NVARCHAR(255) NOT NULL,
        last_uid BIGINT NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_LastUid DEFAULT (0),
        uidvalidity BIGINT NULL,
        last_synced_at DATETIME NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_UpdatedAt DEFAULT (GETDATE()),
        CONSTRAINT PK_Tb_Mail_FolderSyncState PRIMARY KEY CLUSTERED (account_idx, folder)
    );
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'last_uid') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD last_uid BIGINT NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_LastUid_Alt DEFAULT (0);

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'uidvalidity') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD uidvalidity BIGINT NULL;

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'last_synced_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD last_synced_at DATETIME NULL;

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_CreatedAt_Alt DEFAULT (GETDATE());

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_UpdatedAt_Alt DEFAULT (GETDATE());

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState')
          AND name = N'IX_Tb_Mail_FolderSyncState_LastSynced'
    )
    BEGIN
        CREATE INDEX IX_Tb_Mail_FolderSyncState_LastSynced
            ON dbo.Tb_Mail_FolderSyncState(account_idx, last_synced_at DESC);
    END
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_MessageCache', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'body_preview') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD body_preview NVARCHAR(1000) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'thread_id') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD thread_id NVARCHAR(200) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'in_reply_to') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD in_reply_to NVARCHAR(300) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'references') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD [references] NVARCHAR(MAX) NULL;
END;
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
