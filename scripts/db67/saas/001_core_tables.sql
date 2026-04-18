/*
  SHVQ_V2 - SaaS Core Tables
  실행 전 DB명 확인: USE [CSM_C004732_V2]
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_SvcService', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcService (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        service_name NVARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcService_ServiceCode ON dbo.Tb_SvcService(service_code);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcTenant', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcTenant (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_code VARCHAR(60) NOT NULL,
        tenant_name NVARCHAR(120) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        plan_code VARCHAR(30) NOT NULL DEFAULT 'basic',
        timezone_name VARCHAR(60) NOT NULL DEFAULT 'Asia/Seoul',
        is_default BIT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcTenant_ServiceTenantCode
        ON dbo.Tb_SvcTenant(service_code, tenant_code);
    CREATE INDEX IX_Tb_SvcTenant_ServiceStatus
        ON dbo.Tb_SvcTenant(service_code, status, idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcRole', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcRole (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        role_key VARCHAR(40) NOT NULL,
        role_name NVARCHAR(80) NOT NULL,
        priority INT NOT NULL DEFAULT 100,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcRole_RoleKey ON dbo.Tb_SvcRole(role_key);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcRolePermission', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcRolePermission (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        role_id INT NOT NULL,
        permission_key VARCHAR(80) NOT NULL,
        allow_yn BIT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcRolePermission_RolePerm
        ON dbo.Tb_SvcRolePermission(role_id, permission_key);
    CREATE INDEX IX_Tb_SvcRolePermission_Perm
        ON dbo.Tb_SvcRolePermission(permission_key, allow_yn);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcTenantUser', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcTenantUser (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_idx INT NOT NULL,
        role_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcTenantUser_TenantUser
        ON dbo.Tb_SvcTenantUser(tenant_id, user_idx);
    CREATE INDEX IX_Tb_SvcTenantUser_UserStatus
        ON dbo.Tb_SvcTenantUser(user_idx, status, tenant_id);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcAuditLog', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcAuditLog (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        actor_user_idx INT NOT NULL DEFAULT 0,
        action_key VARCHAR(80) NOT NULL,
        target_type VARCHAR(60) NOT NULL,
        target_id NVARCHAR(120) NOT NULL DEFAULT '',
        request_id VARCHAR(80) NOT NULL DEFAULT '',
        before_json NVARCHAR(MAX) NULL,
        after_json NVARCHAR(MAX) NULL,
        client_ip VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_SvcAuditLog_ServiceTenantCreated
        ON dbo.Tb_SvcAuditLog(service_code, tenant_id, created_at DESC);
    CREATE INDEX IX_Tb_SvcAuditLog_RequestId
        ON dbo.Tb_SvcAuditLog(request_id);
END;
GO

