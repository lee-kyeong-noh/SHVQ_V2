/*
  SHVQ_V2 - Event/Notification Tables
  실행 전 DB명 확인: USE [CSM_C004732_V2]
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_EventRaw', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_EventRaw (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,
        source_type VARCHAR(40) NOT NULL,         -- webhook/poll/api
        source_key NVARCHAR(140) NOT NULL DEFAULT '',
        payload_json NVARCHAR(MAX) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_EventRaw_ServiceTenantCreated
        ON dbo.Tb_EventRaw(service_code, tenant_id, created_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.Tb_EventStream', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_EventStream (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,
        event_id NVARCHAR(140) NOT NULL,
        event_type VARCHAR(60) NOT NULL,          -- mail.new, iot.state, iot.alert...
        actor_type VARCHAR(40) NOT NULL DEFAULT '',
        actor_id NVARCHAR(140) NOT NULL DEFAULT '',
        resource_type VARCHAR(40) NOT NULL DEFAULT '',
        resource_id NVARCHAR(140) NOT NULL DEFAULT '',
        event_time DATETIME NOT NULL DEFAULT GETDATE(),
        event_value NVARCHAR(1000) NOT NULL DEFAULT '',
        raw_idx BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_EventStream_EventId
        ON dbo.Tb_EventStream(service_code, tenant_id, provider, event_id);
    CREATE INDEX IX_Tb_EventStream_TypeCreated
        ON dbo.Tb_EventStream(service_code, tenant_id, event_type, created_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.Tb_NotifyRule', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_NotifyRule (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        rule_name NVARCHAR(120) NOT NULL,
        event_type VARCHAR(60) NOT NULL,
        channel VARCHAR(20) NOT NULL,            -- ws/sse/push/mail
        condition_json NVARCHAR(MAX) NULL,
        target_json NVARCHAR(MAX) NULL,
        is_active BIT NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_NotifyRule_TenantEvent
        ON dbo.Tb_NotifyRule(service_code, tenant_id, event_type, is_active);
END;
GO

IF OBJECT_ID(N'dbo.Tb_NotifyQueue', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_NotifyQueue (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        event_stream_idx BIGINT NOT NULL,
        notify_rule_idx BIGINT NOT NULL,
        recipient_key NVARCHAR(140) NOT NULL,     -- user_idx/device token
        channel VARCHAR(20) NOT NULL,
        payload_json NVARCHAR(MAX) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        retry_count INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT GETDATE(),
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_NotifyQueue_Idempotent
        ON dbo.Tb_NotifyQueue(event_stream_idx, notify_rule_idx, recipient_key, channel);
    CREATE INDEX IX_Tb_NotifyQueue_Pending
        ON dbo.Tb_NotifyQueue(status, next_retry_at, idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_NotifyDeliveryLog', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_NotifyDeliveryLog (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        notify_queue_idx BIGINT NOT NULL,
        channel VARCHAR(20) NOT NULL,
        recipient_key NVARCHAR(140) NOT NULL,
        result_code VARCHAR(30) NOT NULL DEFAULT '',
        result_message NVARCHAR(500) NOT NULL DEFAULT '',
        latency_ms INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_Tb_NotifyDeliveryLog_Queue
        ON dbo.Tb_NotifyDeliveryLog(notify_queue_idx, created_at DESC);
END;
GO

