SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave4 Tb_Site table bootstrap start ===';

IF OBJECT_ID(N'dbo.Tb_Site', N'U') IS NULL
BEGIN
    PRINT 'Create dbo.Tb_Site';
    CREATE TABLE dbo.Tb_Site (
        idx              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        site_name        NVARCHAR(150) NOT NULL CONSTRAINT DF_Tb_Site_site_name DEFAULT(N''),
        name             NVARCHAR(150) NOT NULL CONSTRAINT DF_Tb_Site_name DEFAULT(N''),
        site_status      NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_site_status DEFAULT(NCHAR(50696)+NCHAR(51221)),
        status           NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_status DEFAULT(NCHAR(50696)+NCHAR(51221)),
        member_idx       INT NULL,
        head_idx         INT NULL,
        site_code        NVARCHAR(60) NULL,
        address          NVARCHAR(255) NULL,
        address_detail   NVARCHAR(255) NULL,
        zipcode          NVARCHAR(20) NULL,
        manager_name     NVARCHAR(80) NULL,
        manager_tel      NVARCHAR(40) NULL,
        start_date       DATE NULL,
        end_date         DATE NULL,
        memo             NVARCHAR(2000) NULL,
        latitude         FLOAT NULL,
        longitude        FLOAT NULL,
        lat              FLOAT NULL,
        lng              FLOAT NULL,
        is_deleted       BIT NOT NULL CONSTRAINT DF_Tb_Site_is_deleted DEFAULT(0),
        created_at       DATETIME NOT NULL CONSTRAINT DF_Tb_Site_created_at DEFAULT(GETDATE()),
        created_by       INT NULL,
        updated_at       DATETIME NOT NULL CONSTRAINT DF_Tb_Site_updated_at DEFAULT(GETDATE()),
        updated_by       INT NULL,
        deleted_at       DATETIME NULL,
        deleted_by       INT NULL,
        regdate          DATETIME NOT NULL CONSTRAINT DF_Tb_Site_regdate DEFAULT(GETDATE()),
        registered_date  DATETIME NOT NULL CONSTRAINT DF_Tb_Site_registered_date DEFAULT(GETDATE())
    );
END
ELSE
BEGIN
    PRINT 'Patch dbo.Tb_Site missing columns';

    IF COL_LENGTH('dbo.Tb_Site', 'site_name') IS NULL ALTER TABLE dbo.Tb_Site ADD site_name NVARCHAR(150) NOT NULL CONSTRAINT DF_Tb_Site_site_name_patch DEFAULT(N'');
    IF COL_LENGTH('dbo.Tb_Site', 'name') IS NULL ALTER TABLE dbo.Tb_Site ADD name NVARCHAR(150) NOT NULL CONSTRAINT DF_Tb_Site_name_patch DEFAULT(N'');

    IF COL_LENGTH('dbo.Tb_Site', 'site_status') IS NULL ALTER TABLE dbo.Tb_Site ADD site_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_site_status_patch DEFAULT(NCHAR(50696)+NCHAR(51221));
    IF COL_LENGTH('dbo.Tb_Site', 'status') IS NULL ALTER TABLE dbo.Tb_Site ADD status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Site_status_patch DEFAULT(NCHAR(50696)+NCHAR(51221));

    IF COL_LENGTH('dbo.Tb_Site', 'member_idx') IS NULL ALTER TABLE dbo.Tb_Site ADD member_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'head_idx') IS NULL ALTER TABLE dbo.Tb_Site ADD head_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'site_code') IS NULL ALTER TABLE dbo.Tb_Site ADD site_code NVARCHAR(60) NULL;

    IF COL_LENGTH('dbo.Tb_Site', 'address') IS NULL ALTER TABLE dbo.Tb_Site ADD address NVARCHAR(255) NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'address_detail') IS NULL ALTER TABLE dbo.Tb_Site ADD address_detail NVARCHAR(255) NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'zipcode') IS NULL ALTER TABLE dbo.Tb_Site ADD zipcode NVARCHAR(20) NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'manager_name') IS NULL ALTER TABLE dbo.Tb_Site ADD manager_name NVARCHAR(80) NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'manager_tel') IS NULL ALTER TABLE dbo.Tb_Site ADD manager_tel NVARCHAR(40) NULL;

    IF COL_LENGTH('dbo.Tb_Site', 'start_date') IS NULL ALTER TABLE dbo.Tb_Site ADD start_date DATE NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'end_date') IS NULL ALTER TABLE dbo.Tb_Site ADD end_date DATE NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'memo') IS NULL ALTER TABLE dbo.Tb_Site ADD memo NVARCHAR(2000) NULL;

    IF COL_LENGTH('dbo.Tb_Site', 'latitude') IS NULL ALTER TABLE dbo.Tb_Site ADD latitude FLOAT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'longitude') IS NULL ALTER TABLE dbo.Tb_Site ADD longitude FLOAT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'lat') IS NULL ALTER TABLE dbo.Tb_Site ADD lat FLOAT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'lng') IS NULL ALTER TABLE dbo.Tb_Site ADD lng FLOAT NULL;

    IF COL_LENGTH('dbo.Tb_Site', 'is_deleted') IS NULL ALTER TABLE dbo.Tb_Site ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Site_is_deleted_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_Site', 'created_at') IS NULL ALTER TABLE dbo.Tb_Site ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_created_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Site', 'created_by') IS NULL ALTER TABLE dbo.Tb_Site ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'updated_at') IS NULL ALTER TABLE dbo.Tb_Site ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Site_updated_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Site', 'updated_by') IS NULL ALTER TABLE dbo.Tb_Site ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'deleted_at') IS NULL ALTER TABLE dbo.Tb_Site ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'deleted_by') IS NULL ALTER TABLE dbo.Tb_Site ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Site', 'regdate') IS NULL ALTER TABLE dbo.Tb_Site ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Site_regdate_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Site', 'registered_date') IS NULL ALTER TABLE dbo.Tb_Site ADD registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_Site_registered_date_patch DEFAULT(GETDATE());
END

/* status alias sync (best effort for existing rows) */
UPDATE dbo.Tb_Site
SET
    site_name = CASE WHEN LTRIM(RTRIM(ISNULL(site_name, ''))) = '' THEN ISNULL(name, '') ELSE site_name END,
    name = CASE WHEN LTRIM(RTRIM(ISNULL(name, ''))) = '' THEN ISNULL(site_name, '') ELSE name END,
    site_status = CASE WHEN LTRIM(RTRIM(ISNULL(site_status, ''))) = '' THEN ISNULL(status, NCHAR(50696)+NCHAR(51221)) ELSE site_status END,
    status = CASE WHEN LTRIM(RTRIM(ISNULL(status, ''))) = '' THEN ISNULL(site_status, NCHAR(50696)+NCHAR(51221)) ELSE status END;

/* foreign keys (only when clean) */
IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_Site', 'member_idx') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_Tb_Site_Tb_Members_member_idx')
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.Tb_Site s
        LEFT JOIN dbo.Tb_Members m ON m.idx = s.member_idx
        WHERE s.member_idx IS NOT NULL
          AND s.member_idx > 0
          AND m.idx IS NULL
    )
    BEGIN
        ALTER TABLE dbo.Tb_Site WITH CHECK
            ADD CONSTRAINT FK_Tb_Site_Tb_Members_member_idx
                FOREIGN KEY (member_idx) REFERENCES dbo.Tb_Members(idx);
    END
    ELSE
    BEGIN
        PRINT 'Skip FK_Tb_Site_Tb_Members_member_idx: orphan member_idx exists';
    END
END

IF OBJECT_ID(N'dbo.Tb_HeadOffice', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_Site', 'head_idx') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_Tb_Site_Tb_HeadOffice_head_idx')
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.Tb_Site s
        LEFT JOIN dbo.Tb_HeadOffice h ON h.idx = s.head_idx
        WHERE s.head_idx IS NOT NULL
          AND s.head_idx > 0
          AND h.idx IS NULL
    )
    BEGIN
        ALTER TABLE dbo.Tb_Site WITH CHECK
            ADD CONSTRAINT FK_Tb_Site_Tb_HeadOffice_head_idx
                FOREIGN KEY (head_idx) REFERENCES dbo.Tb_HeadOffice(idx);
    END
    ELSE
    BEGIN
        PRINT 'Skip FK_Tb_Site_Tb_HeadOffice_head_idx: orphan head_idx exists';
    END
END

/* indexes */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Site')
      AND name = N'IX_Tb_Site_member_deleted_status'
)
BEGIN
    CREATE INDEX IX_Tb_Site_member_deleted_status
        ON dbo.Tb_Site(member_idx, is_deleted, site_status, idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Site')
      AND name = N'IX_Tb_Site_head_idx'
)
BEGIN
    CREATE INDEX IX_Tb_Site_head_idx
        ON dbo.Tb_Site(head_idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Site')
      AND name = N'IX_Tb_Site_site_name'
)
BEGIN
    CREATE INDEX IX_Tb_Site_site_name
        ON dbo.Tb_Site(site_name);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Site')
      AND name = N'IX_Tb_Site_site_code'
)
BEGIN
    CREATE INDEX IX_Tb_Site_site_code
        ON dbo.Tb_Site(site_code);
END

PRINT '=== Wave4 Tb_Site table bootstrap done ===';

SELECT
    COUNT(*) AS target_total,
    SUM(CASE WHEN ISNULL(is_deleted, 0) = 0 THEN 1 ELSE 0 END) AS active_total
FROM dbo.Tb_Site;
