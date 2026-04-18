-- SHVQ V2 Wave8: ONVIF camera tenant ownership
-- Target DB: CSM_C004732_V2

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_OnvifCameras')
BEGIN
    CREATE TABLE dbo.Tb_OnvifCameras (
        idx             INT IDENTITY(1,1) PRIMARY KEY,
        tenant_id       INT NOT NULL CONSTRAINT DF_OnvifCameras_TenantId DEFAULT 0,
        created_by      INT NOT NULL CONSTRAINT DF_OnvifCameras_CreatedBy DEFAULT 0,
        camera_id       NVARCHAR(80) NOT NULL,
        name            NVARCHAR(200) NOT NULL,
        channel         NVARCHAR(50) DEFAULT '',
        ip              NVARCHAR(100) NOT NULL,
        port            INT NOT NULL DEFAULT 80,
        login_user      NVARCHAR(100) DEFAULT '',
        login_pass      NVARCHAR(200) DEFAULT '',
        memo            NVARCHAR(500) DEFAULT '',
        conn_method     NVARCHAR(20) DEFAULT 'onvif',
        manufacturer    NVARCHAR(50) DEFAULT '',
        rtsp_port       INT NOT NULL DEFAULT 554,
        default_stream  NVARCHAR(10) DEFAULT 'sub',
        is_ptz          TINYINT NOT NULL DEFAULT 0,
        rtsp_main       NVARCHAR(1000) DEFAULT '',
        rtsp_sub        NVARCHAR(1000) DEFAULT '',
        status          NVARCHAR(20) DEFAULT 'unknown',
        snapshot        NVARCHAR(MAX) DEFAULT '',
        created_at      DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at      DATETIME NOT NULL DEFAULT GETDATE(),
        is_deleted      TINYINT NOT NULL DEFAULT 0
    );
END;
GO

IF COL_LENGTH('dbo.Tb_OnvifCameras', 'tenant_id') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_OnvifCameras
    ADD tenant_id INT NOT NULL CONSTRAINT DF_OnvifCameras_TenantId DEFAULT 0;
END;
GO

IF COL_LENGTH('dbo.Tb_OnvifCameras', 'created_by') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_OnvifCameras
    ADD created_by INT NOT NULL CONSTRAINT DF_OnvifCameras_CreatedBy DEFAULT 0;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_OnvifCameras_Tenant' AND object_id = OBJECT_ID('dbo.Tb_OnvifCameras'))
BEGIN
    CREATE INDEX IX_OnvifCameras_Tenant
        ON dbo.Tb_OnvifCameras(tenant_id, is_deleted, created_at);
END;
GO

BEGIN TRY
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UQ_OnvifCameras_TenantCamera' AND object_id = OBJECT_ID('dbo.Tb_OnvifCameras'))
    BEGIN
        CREATE UNIQUE INDEX UQ_OnvifCameras_TenantCamera
            ON dbo.Tb_OnvifCameras(tenant_id, camera_id);
    END;
END TRY
BEGIN CATCH
    -- Ignore duplicate-key issue on legacy rows.
END CATCH;
GO
