/*
  SHVQ_V2 - Wave 1 Shadow Write Queue Bootstrap
  DB: CSM_C004732_V2 (67번 개발DB 기준)
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_IntErrorQueue', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntErrorQueue (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        service_code VARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL,
        job_type VARCHAR(40) NOT NULL,
        payload_json NVARCHAR(MAX) NOT NULL,
        error_message NVARCHAR(1000) NOT NULL DEFAULT '',
        retry_count INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT GETDATE(),
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_Tb_IntErrorQueue_ShadowPending'
      AND object_id = OBJECT_ID(N'dbo.Tb_IntErrorQueue')
)
BEGIN
    CREATE INDEX IX_Tb_IntErrorQueue_ShadowPending
        ON dbo.Tb_IntErrorQueue(provider, job_type, status, next_retry_at, idx);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_Tb_IntErrorQueue_ShadowScope'
      AND object_id = OBJECT_ID(N'dbo.Tb_IntErrorQueue')
)
BEGIN
    CREATE INDEX IX_Tb_IntErrorQueue_ShadowScope
        ON dbo.Tb_IntErrorQueue(service_code, tenant_id, provider, job_type, status, idx DESC);
END;
GO
