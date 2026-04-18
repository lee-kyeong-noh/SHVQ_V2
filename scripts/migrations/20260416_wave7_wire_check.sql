/*
  SHVQ V2 - WireCheck Table
  Target DB: CSM_C004732_V2
*/
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.Tb_WireCheck', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_WireCheck
    (
        idx         INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        tenant_id   VARCHAR(20) NOT NULL,
        created_by  INT NOT NULL,
        site        NVARCHAR(50) NOT NULL DEFAULT N'',
        dtype       NVARCHAR(10) NOT NULL DEFAULT N'',
        [struct]    NVARCHAR(30) NOT NULL DEFAULT N'',
        region      NVARCHAR(10) NOT NULL DEFAULT N'지방',
        division    VARCHAR(2) NOT NULL DEFAULT 'NI',
        usage       NVARCHAR(20) NOT NULL DEFAULT N'아파트',
        max_dist    DECIMAL(10,1) NOT NULL DEFAULT 0,
        min_dist    DECIMAL(10,1) NOT NULL DEFAULT 0,
        basement    INT NOT NULL DEFAULT 0,
        units       INT NOT NULL DEFAULT 0,
        wiring      DECIMAL(4,1) NOT NULL DEFAULT 0.9,
        estimate    DECIMAL(10,1) NULL,
        memo        NVARCHAR(200) NULL,
        surcharge   DECIMAL(4,2) NOT NULL DEFAULT 1.05,
        inline_m    INT NOT NULL DEFAULT 10,
        fire_room   INT NOT NULL DEFAULT 10,
        mat_unit    INT NOT NULL DEFAULT 400,
        group_id    INT NULL,
        created_at  DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at  DATETIME NULL
    );
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_WireCheck')
      AND name = N'IX_Tb_WireCheck_TenantGroup'
)
BEGIN
    CREATE INDEX IX_Tb_WireCheck_TenantGroup
    ON dbo.Tb_WireCheck (tenant_id, group_id, idx);
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_WireCheck')
      AND name = N'IX_Tb_WireCheck_TenantCreatedAt'
)
BEGIN
    CREATE INDEX IX_Tb_WireCheck_TenantCreatedAt
    ON dbo.Tb_WireCheck (tenant_id, created_at DESC, idx DESC);
END
GO

