/*
  SHVQ V2 — Wave4 Approval Core Schema
  DB: CSM_C004732_V2
  Domain: 전자결재 전용 테이블 (문서/결재선/프리셋)
*/
SET NOCOUNT ON;

/* -------------------------------------------------
   Tb_ApprDoc
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_ApprDoc', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ApprDoc (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        doc_no NVARCHAR(50) NOT NULL,
        doc_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprDoc_Type DEFAULT ('GENERAL'),
        title NVARCHAR(300) NOT NULL,
        body_html NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprDoc_BodyHtml DEFAULT (''),
        body_text NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprDoc_BodyText DEFAULT (''),
        writer_user_idx INT NOT NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprDoc_Status DEFAULT ('DRAFT'),
        current_line_order INT NULL,
        submitted_at DATETIME NULL,
        completed_at DATETIME NULL,
        recalled_at DATETIME NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprDoc_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprDoc_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprDoc_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF OBJECT_ID(N'dbo.Tb_ApprDoc', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'doc_type') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD doc_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprDoc_Type DEFAULT ('GENERAL') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'body_html') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD body_html NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprDoc_BodyHtml DEFAULT ('') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'body_text') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD body_text NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprDoc_BodyText DEFAULT ('') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'status') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprDoc_Status DEFAULT ('DRAFT') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'current_line_order') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD current_line_order INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'submitted_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD submitted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'completed_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD completed_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'recalled_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD recalled_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprDoc_IsDeleted DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'deleted_by') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprDoc_CreatedAt DEFAULT (GETDATE()) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprDoc', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_ApprDoc ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprDoc_UpdatedAt DEFAULT (GETDATE()) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.Tb_ApprDoc', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprDoc')
          AND name = N'UX_Tb_ApprDoc_DocNo'
    )
    BEGIN
        CREATE UNIQUE INDEX UX_Tb_ApprDoc_DocNo
            ON dbo.Tb_ApprDoc(service_code, tenant_id, doc_no);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprDoc')
          AND name = N'IX_Tb_ApprDoc_ScopeStatus'
    )
    BEGIN
        CREATE INDEX IX_Tb_ApprDoc_ScopeStatus
            ON dbo.Tb_ApprDoc(service_code, tenant_id, is_deleted, status, updated_at, idx);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprDoc')
          AND name = N'IX_Tb_ApprDoc_Writer'
    )
    BEGIN
        CREATE INDEX IX_Tb_ApprDoc_Writer
            ON dbo.Tb_ApprDoc(service_code, tenant_id, writer_user_idx, is_deleted, idx);
    END;
END;

/* -------------------------------------------------
   Tb_ApprLine
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_ApprLine', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ApprLine (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        doc_idx INT NOT NULL,
        line_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLine_Type DEFAULT ('APPROVER'),
        line_order INT NOT NULL CONSTRAINT DF_Tb_ApprLine_Order DEFAULT (1),
        actor_user_idx INT NOT NULL,
        decision_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLine_Status DEFAULT ('PENDING'),
        decided_at DATETIME NULL,
        decision_comment NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_ApprLine_Comment DEFAULT (''),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprLine_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLine_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLine_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF OBJECT_ID(N'dbo.Tb_ApprLine', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_ApprLine', 'line_type') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD line_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLine_Type DEFAULT ('APPROVER') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'line_order') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD line_order INT NOT NULL CONSTRAINT DF_Tb_ApprLine_Order DEFAULT (1) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'decision_status') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD decision_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLine_Status DEFAULT ('PENDING') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'decided_at') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD decided_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'decision_comment') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD decision_comment NVARCHAR(1000) NOT NULL CONSTRAINT DF_Tb_ApprLine_Comment DEFAULT ('') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprLine_IsDeleted DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLine_CreatedAt DEFAULT (GETDATE()) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLine', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_ApprLine ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLine_UpdatedAt DEFAULT (GETDATE()) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.Tb_ApprLine', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprLine')
          AND name = N'IX_Tb_ApprLine_DocOrder'
    )
    BEGIN
        CREATE INDEX IX_Tb_ApprLine_DocOrder
            ON dbo.Tb_ApprLine(service_code, tenant_id, doc_idx, line_type, line_order, is_deleted, idx);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprLine')
          AND name = N'IX_Tb_ApprLine_Actor'
    )
    BEGIN
        CREATE INDEX IX_Tb_ApprLine_Actor
            ON dbo.Tb_ApprLine(service_code, tenant_id, actor_user_idx, line_type, decision_status, is_deleted, doc_idx, idx);
    END;
END;

/* -------------------------------------------------
   Tb_ApprLinePreset
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_ApprLinePreset', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_ApprLinePreset (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        preset_name NVARCHAR(120) NOT NULL,
        doc_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_Type DEFAULT ('GENERAL'),
        line_json NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_LineJson DEFAULT ('{}'),
        is_shared BIT NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_IsShared DEFAULT (0),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF OBJECT_ID(N'dbo.Tb_ApprLinePreset', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'doc_type') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD doc_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_Type DEFAULT ('GENERAL') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'line_json') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD line_json NVARCHAR(MAX) NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_LineJson DEFAULT ('{}') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'is_shared') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD is_shared BIT NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_IsShared DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_IsDeleted DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_CreatedAt DEFAULT (GETDATE()) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_ApprLinePreset', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_ApprLinePreset ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_ApprLinePreset_UpdatedAt DEFAULT (GETDATE()) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.Tb_ApprLinePreset', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_ApprLinePreset')
          AND name = N'IX_Tb_ApprLinePreset_Scope'
    )
    BEGIN
        CREATE INDEX IX_Tb_ApprLinePreset_Scope
            ON dbo.Tb_ApprLinePreset(service_code, tenant_id, is_deleted, created_by, is_shared, updated_at, idx);
    END;
END;

