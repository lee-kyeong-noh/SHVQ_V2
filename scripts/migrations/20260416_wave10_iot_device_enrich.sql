-- SHVQ V2 Wave10: IoT device enrich patch
-- Target DB: CSM_C004732_V2
-- 목적:
--   1) Tb_IntDevice 누락 컬럼 추가
--   2) V1(teniq_db.dbo.Tb_IotDevice) 기준으로 type/name/adapter/capabilities 재반영
--      매칭 키: service_code + tenant_id + external_id(device_id/external_id)

USE CSM_C004732_V2;
GO

IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NULL
BEGIN
    PRINT 'SKIP: Tb_IntDevice table not found.';
END;
GO

IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_IntDevice', 'device_type') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD device_type VARCHAR(50) NOT NULL CONSTRAINT DF_Tb_IntDevice_device_type_wave10 DEFAULT ('switch') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'device_name') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD device_name NVARCHAR(200) NOT NULL CONSTRAINT DF_Tb_IntDevice_device_name_wave10 DEFAULT ('') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'adapter') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD adapter VARCHAR(50) NOT NULL CONSTRAINT DF_Tb_IntDevice_adapter_wave10 DEFAULT ('smartthings') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_IntDevice_is_deleted_wave10 DEFAULT (0) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'manufacturer') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD manufacturer NVARCHAR(100) NOT NULL CONSTRAINT DF_Tb_IntDevice_manufacturer_wave10 DEFAULT ('') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'model') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD model NVARCHAR(100) NOT NULL CONSTRAINT DF_Tb_IntDevice_model_wave10 DEFAULT ('') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'firmware_version') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD firmware_version NVARCHAR(100) NOT NULL CONSTRAINT DF_Tb_IntDevice_firmware_wave10 DEFAULT ('') WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'last_event_at') IS NULL
        ALTER TABLE dbo.Tb_IntDevice ADD last_event_at DATETIME NULL;
END;
GO

IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NOT NULL
   AND DB_ID('teniq_db') IS NOT NULL
   AND OBJECT_ID('teniq_db.dbo.Tb_IotDevice', 'U') IS NOT NULL
BEGIN
    DECLARE @TargetIdColumn SYSNAME = N'';
    DECLARE @TargetCapabilityColumn SYSNAME = N'';
    DECLARE @SetClause NVARCHAR(MAX) = N'';
    DECLARE @Sql NVARCHAR(MAX) = N'';

    IF COL_LENGTH('dbo.Tb_IntDevice', 'device_id') IS NOT NULL
        SET @TargetIdColumn = N'device_id';
    ELSE IF COL_LENGTH('dbo.Tb_IntDevice', 'external_id') IS NOT NULL
        SET @TargetIdColumn = N'external_id';

    IF COL_LENGTH('dbo.Tb_IntDevice', 'capability_json') IS NOT NULL
        SET @TargetCapabilityColumn = N'capability_json';
    ELSE IF COL_LENGTH('dbo.Tb_IntDevice', 'capabilities_json') IS NOT NULL
        SET @TargetCapabilityColumn = N'capabilities_json';

    IF (@TargetIdColumn <> N'')
    BEGIN
        IF COL_LENGTH('dbo.Tb_IntDevice', 'device_type') IS NOT NULL
            SET @SetClause = @SetClause + N',
                t.device_type = COALESCE(NULLIF(LTRIM(RTRIM(s.device_type)), ''''), t.device_type)';

        IF COL_LENGTH('dbo.Tb_IntDevice', 'device_name') IS NOT NULL
            SET @SetClause = @SetClause + N',
                t.device_name = COALESCE(NULLIF(LTRIM(RTRIM(s.device_name)), ''''), NULLIF(LTRIM(RTRIM(s.device_label)), ''''), t.device_name)';

        IF COL_LENGTH('dbo.Tb_IntDevice', 'adapter') IS NOT NULL
        BEGIN
            IF COL_LENGTH('teniq_db.dbo.Tb_IotDevice', 'adapter') IS NOT NULL
                SET @SetClause = @SetClause + N',
                    t.adapter = COALESCE(NULLIF(LTRIM(RTRIM(s.adapter)), ''''), NULLIF(LTRIM(RTRIM(s.provider)), ''''), t.adapter)';
            ELSE
                SET @SetClause = @SetClause + N',
                    t.adapter = COALESCE(NULLIF(LTRIM(RTRIM(s.provider)), ''''), t.adapter)';
        END

        IF (@TargetCapabilityColumn <> N'')
           AND COL_LENGTH('teniq_db.dbo.Tb_IotDevice', 'capabilities_json') IS NOT NULL
            SET @SetClause = @SetClause + N',
                t.' + QUOTENAME(@TargetCapabilityColumn) + N' =
                    CASE
                        WHEN NULLIF(LTRIM(RTRIM(ISNULL(t.' + QUOTENAME(@TargetCapabilityColumn) + N', ''''))), '''') IS NULL
                             AND NULLIF(LTRIM(RTRIM(ISNULL(s.capabilities_json, ''''))), '''') IS NOT NULL
                        THEN s.capabilities_json
                        ELSE t.' + QUOTENAME(@TargetCapabilityColumn) + N'
                    END';

        IF @SetClause <> N''
        BEGIN
            SET @Sql = N'
                UPDATE t
                   SET ' + STUFF(@SetClause, 1, 2, N'') + N'
                  FROM dbo.Tb_IntDevice t
                  INNER JOIN teniq_db.dbo.Tb_IotDevice s
                          ON t.service_code = s.service_code
                         AND t.tenant_id = s.tenant_id
                         AND t.' + QUOTENAME(@TargetIdColumn) + N' = s.external_id
                 WHERE ISNULL(t.is_deleted, 0) = 0;';

            EXEC sp_executesql @Sql;
            PRINT 'V1 re-enrich updated rows: ' + CAST(@@ROWCOUNT AS VARCHAR(20));
        END
        ELSE
        BEGIN
            PRINT 'SKIP: no target columns to enrich.';
        END
    END
    ELSE
    BEGIN
        PRINT 'SKIP: no device key column (device_id/external_id) found in Tb_IntDevice.';
    END
END;
GO

IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NOT NULL
BEGIN
    SELECT
        COUNT(*) AS total_devices,
        SUM(CASE WHEN ISNULL(device_type, '') <> '' THEN 1 ELSE 0 END) AS typed_devices,
        SUM(CASE WHEN ISNULL(device_name, '') <> '' THEN 1 ELSE 0 END) AS named_devices
    FROM dbo.Tb_IntDevice;

    SELECT TOP 20
        ISNULL(NULLIF(device_type, ''), 'unknown') AS device_type,
        COUNT(*) AS cnt
    FROM dbo.Tb_IntDevice
    GROUP BY ISNULL(NULLIF(device_type, ''), 'unknown')
    ORDER BY cnt DESC, device_type ASC;
END;
GO
