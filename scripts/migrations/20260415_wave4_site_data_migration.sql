SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave4 Tb_Site data migration start ===';

IF OBJECT_ID(N'dbo.Tb_Site', N'U') IS NULL
BEGIN
    RAISERROR('Target table dbo.Tb_Site is missing. Run table bootstrap first.', 16, 1);
    RETURN;
END

IF OBJECT_ID(N'[CSM_C004732].dbo.Tb_Site', N'U') IS NULL
BEGIN
    RAISERROR('Source table [CSM_C004732].dbo.Tb_Site is missing.', 16, 1);
    RETURN;
END

DECLARE @today DATE = CAST(GETDATE() AS DATE);
DECLARE @ST_PLANNED NVARCHAR(20) = NCHAR(50696) + NCHAR(51221);
DECLARE @ST_ACTIVE NVARCHAR(20) = NCHAR(51652) + NCHAR(54665);
DECLARE @ST_STOPPED NVARCHAR(20) = NCHAR(51473) + NCHAR(51648);
DECLARE @ST_DONE NVARCHAR(20) = NCHAR(50756) + NCHAR(47308);
DECLARE @mergeResult TABLE (action_name NVARCHAR(10), idx INT);

SET IDENTITY_INSERT dbo.Tb_Site ON;

;WITH src AS (
    SELECT
        CAST(s.idx AS INT) AS idx,
        CAST(ISNULL(s.member_idx, 0) AS INT) AS src_member_idx,
        CAST(ISNULL(v2m.idx, NULL) AS INT) AS member_idx,
        CAST(ISNULL(v2m.head_idx, NULL) AS INT) AS raw_head_idx,
        CAST(ISNULL(v2h.idx, NULL) AS INT) AS head_idx,

        CAST(ISNULL(NULLIF(LTRIM(RTRIM(s.name)), ''), N'') AS NVARCHAR(150)) AS site_name,
        CAST(ISNULL(NULLIF(LTRIM(RTRIM(s.name)), ''), N'') AS NVARCHAR(150)) AS name,

        CAST(
            CASE
                WHEN TRY_CONVERT(DATE, s.completion_date) IS NOT NULL
                     AND TRY_CONVERT(DATE, s.completion_date) < @today THEN @ST_DONE
                WHEN TRY_CONVERT(DATE, s.construction_date) IS NOT NULL
                     AND TRY_CONVERT(DATE, s.construction_date) > @today THEN @ST_PLANNED
                WHEN ISNULL(CAST(s.deadline_status AS INT), 0) = 0 THEN @ST_PLANNED
                WHEN ISNULL(CAST(s.deadline_status AS INT), 0) IN (2, 3, 4) THEN @ST_STOPPED
                ELSE @ST_ACTIVE
            END
            AS NVARCHAR(20)
        ) AS site_status,
        CAST(
            CASE
                WHEN TRY_CONVERT(DATE, s.completion_date) IS NOT NULL
                     AND TRY_CONVERT(DATE, s.completion_date) < @today THEN @ST_DONE
                WHEN TRY_CONVERT(DATE, s.construction_date) IS NOT NULL
                     AND TRY_CONVERT(DATE, s.construction_date) > @today THEN @ST_PLANNED
                WHEN ISNULL(CAST(s.deadline_status AS INT), 0) = 0 THEN @ST_PLANNED
                WHEN ISNULL(CAST(s.deadline_status AS INT), 0) IN (2, 3, 4) THEN @ST_STOPPED
                ELSE @ST_ACTIVE
            END
            AS NVARCHAR(20)
        ) AS status,

        CAST(NULLIF(LTRIM(RTRIM(s.site_number)), '') AS NVARCHAR(60)) AS site_code,
        CAST(NULLIF(LTRIM(RTRIM(s.address)), '') AS NVARCHAR(255)) AS address,
        CAST(N'' AS NVARCHAR(255)) AS address_detail,
        CAST(NULLIF(LTRIM(RTRIM(s.zip_code)), '') AS NVARCHAR(20)) AS zipcode,

        CAST(NULLIF(LTRIM(RTRIM(pb.name)), '') AS NVARCHAR(80)) AS manager_name,
        CAST(NULLIF(LTRIM(RTRIM(pb.hp)), '') AS NVARCHAR(40)) AS manager_tel,

        TRY_CONVERT(DATE, s.construction_date) AS start_date,
        TRY_CONVERT(DATE, s.completion_date) AS end_date,

        CAST(ISNULL(NULLIF(LTRIM(RTRIM(s.memo)), ''), N'') AS NVARCHAR(2000)) AS memo,

        TRY_CONVERT(FLOAT, s.map_lat) AS latitude,
        TRY_CONVERT(FLOAT, s.map_lng) AS longitude,
        TRY_CONVERT(FLOAT, s.map_lat) AS lat,
        TRY_CONVERT(FLOAT, s.map_lng) AS lng,

        CAST(0 AS BIT) AS is_deleted,

        CAST(ISNULL(s.registered_date, GETDATE()) AS DATETIME) AS created_at,
        CAST(CASE WHEN ISNULL(s.reg_employee_idx, 0) > 0 THEN s.reg_employee_idx ELSE NULL END AS INT) AS created_by,
        CAST(ISNULL(s.update_date, ISNULL(s.registered_date, GETDATE())) AS DATETIME) AS updated_at,
        CAST(CASE WHEN ISNULL(s.employee_idx, 0) > 0 THEN s.employee_idx ELSE NULL END AS INT) AS updated_by,

        CAST(NULL AS DATETIME) AS deleted_at,
        CAST(NULL AS INT) AS deleted_by,

        CAST(ISNULL(s.registered_date, GETDATE()) AS DATETIME) AS regdate,
        CAST(ISNULL(s.registered_date, GETDATE()) AS DATETIME) AS registered_date
    FROM [CSM_C004732].dbo.Tb_Site s
    LEFT JOIN [CSM_C004732].dbo.Tb_PhoneBook pb
        ON pb.idx = s.phonebook_idx
    LEFT JOIN dbo.Tb_Members v2m
        ON v2m.idx = s.member_idx
    LEFT JOIN dbo.Tb_HeadOffice v2h
        ON v2h.idx = v2m.head_idx
), normalized AS (
    SELECT
        idx,
        CASE WHEN LTRIM(RTRIM(site_name)) = '' THEN CAST(N'(NO_SITE_NAME)' AS NVARCHAR(150)) ELSE site_name END AS site_name,
        CASE WHEN LTRIM(RTRIM(name)) = '' THEN CAST(N'(NO_SITE_NAME)' AS NVARCHAR(150)) ELSE name END AS name,
        CASE WHEN site_status IN (@ST_PLANNED, @ST_ACTIVE, @ST_STOPPED, @ST_DONE) THEN site_status ELSE @ST_ACTIVE END AS site_status,
        CASE WHEN status IN (@ST_PLANNED, @ST_ACTIVE, @ST_STOPPED, @ST_DONE) THEN status ELSE @ST_ACTIVE END AS status,
        member_idx,
        head_idx,
        site_code,
        address,
        address_detail,
        zipcode,
        manager_name,
        manager_tel,
        start_date,
        end_date,
        memo,
        latitude,
        longitude,
        lat,
        lng,
        is_deleted,
        created_at,
        created_by,
        updated_at,
        updated_by,
        deleted_at,
        deleted_by,
        regdate,
        registered_date,
        src_member_idx,
        raw_head_idx
    FROM src
)
MERGE dbo.Tb_Site AS tgt
USING normalized AS src
ON tgt.idx = src.idx
WHEN MATCHED THEN
    UPDATE SET
        tgt.site_name = src.site_name,
        tgt.name = src.name,
        tgt.site_status = src.site_status,
        tgt.status = src.status,
        tgt.member_idx = src.member_idx,
        tgt.head_idx = src.head_idx,
        tgt.site_code = src.site_code,
        tgt.address = src.address,
        tgt.address_detail = src.address_detail,
        tgt.zipcode = src.zipcode,
        tgt.manager_name = src.manager_name,
        tgt.manager_tel = src.manager_tel,
        tgt.start_date = src.start_date,
        tgt.end_date = src.end_date,
        tgt.memo = src.memo,
        tgt.latitude = src.latitude,
        tgt.longitude = src.longitude,
        tgt.lat = src.lat,
        tgt.lng = src.lng,
        tgt.is_deleted = src.is_deleted,
        tgt.created_at = ISNULL(tgt.created_at, src.created_at),
        tgt.created_by = ISNULL(tgt.created_by, src.created_by),
        tgt.updated_at = src.updated_at,
        tgt.updated_by = src.updated_by,
        tgt.deleted_at = src.deleted_at,
        tgt.deleted_by = src.deleted_by,
        tgt.regdate = ISNULL(tgt.regdate, src.regdate),
        tgt.registered_date = ISNULL(tgt.registered_date, src.registered_date)
WHEN NOT MATCHED THEN
    INSERT (
        idx,
        site_name,
        name,
        site_status,
        status,
        member_idx,
        head_idx,
        site_code,
        address,
        address_detail,
        zipcode,
        manager_name,
        manager_tel,
        start_date,
        end_date,
        memo,
        latitude,
        longitude,
        lat,
        lng,
        is_deleted,
        created_at,
        created_by,
        updated_at,
        updated_by,
        deleted_at,
        deleted_by,
        regdate,
        registered_date
    ) VALUES (
        src.idx,
        src.site_name,
        src.name,
        src.site_status,
        src.status,
        src.member_idx,
        src.head_idx,
        src.site_code,
        src.address,
        src.address_detail,
        src.zipcode,
        src.manager_name,
        src.manager_tel,
        src.start_date,
        src.end_date,
        src.memo,
        src.latitude,
        src.longitude,
        src.lat,
        src.lng,
        src.is_deleted,
        src.created_at,
        src.created_by,
        src.updated_at,
        src.updated_by,
        src.deleted_at,
        src.deleted_by,
        src.regdate,
        src.registered_date
    )
OUTPUT $action, inserted.idx INTO @mergeResult(action_name, idx);

SET IDENTITY_INSERT dbo.Tb_Site OFF;

DECLARE @maxIdx INT = (SELECT ISNULL(MAX(idx), 0) FROM dbo.Tb_Site);
DBCC CHECKIDENT ('dbo.Tb_Site', RESEED, @maxIdx) WITH NO_INFOMSGS;

DECLARE @sourceTotal INT = (SELECT COUNT(*) FROM [CSM_C004732].dbo.Tb_Site);
DECLARE @targetTotal INT = (SELECT COUNT(*) FROM dbo.Tb_Site);
DECLARE @unresolvedMember INT = (
    SELECT COUNT(*)
    FROM [CSM_C004732].dbo.Tb_Site s
    LEFT JOIN dbo.Tb_Members m ON m.idx = s.member_idx
    WHERE ISNULL(s.member_idx, 0) > 0
      AND m.idx IS NULL
);
DECLARE @unresolvedHead INT = (
    SELECT COUNT(*)
    FROM [CSM_C004732].dbo.Tb_Site s
    LEFT JOIN dbo.Tb_Members m ON m.idx = s.member_idx
    LEFT JOIN dbo.Tb_HeadOffice h ON h.idx = m.head_idx
    WHERE ISNULL(m.head_idx, 0) > 0
      AND h.idx IS NULL
);

SELECT
    @sourceTotal AS source_total,
    @targetTotal AS target_total,
    (SELECT COUNT(*) FROM @mergeResult WHERE action_name = 'INSERT') AS inserted_count,
    (SELECT COUNT(*) FROM @mergeResult WHERE action_name = 'UPDATE') AS updated_count,
    @unresolvedMember AS unresolved_member_idx_count,
    @unresolvedHead AS unresolved_head_idx_count;

PRINT '=== Wave4 Tb_Site data migration done ===';
