/*
  SHVQ_V2 - Wave 1 Tb_Members Data Migration (V1 -> V2)
  Source : [CSM_C004732].dbo.Tb_Members
  Target : [CSM_C004732_V2].dbo.Tb_Members
  방식   : idx 보존 MERGE(UPSERT)
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'[CSM_C004732].dbo.Tb_Members', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table [CSM_C004732].dbo.Tb_Members not found.', 16, 1);
    RETURN;
END;
GO

IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table dbo.Tb_Members not found.', 16, 1);
    RETURN;
END;
GO

BEGIN TRY
    BEGIN TRAN;

    DECLARE @mergeLog TABLE(action_type NVARCHAR(10));

    ;WITH v1h AS (
        SELECT idx, name, card_number, head_number
        FROM [CSM_C004732].dbo.Tb_HeadOffice
    ),
    v2h AS (
        SELECT idx, name, card_number, head_number
        FROM dbo.Tb_HeadOffice
    ),
    headmap AS (
        SELECT
            a.idx AS v1_head_idx,
            COALESCE(h1.idx, h2.idx, h3.idx, h4.idx) AS v2_head_idx
        FROM v1h a
        OUTER APPLY (SELECT TOP 1 idx FROM v2h b WHERE b.idx = a.idx ORDER BY b.idx) h1
        OUTER APPLY (
            SELECT TOP 1 b.idx
            FROM v2h b
            WHERE REPLACE(REPLACE(ISNULL(a.card_number, ''), '-', ''), ' ', '') = REPLACE(REPLACE(ISNULL(b.card_number, ''), '-', ''), ' ', '')
              AND REPLACE(REPLACE(ISNULL(a.card_number, ''), '-', ''), ' ', '') <> ''
            ORDER BY b.idx
        ) h2
        OUTER APPLY (
            SELECT TOP 1 b.idx
            FROM v2h b
            WHERE ISNULL(a.head_number, '') = ISNULL(b.head_number, '')
              AND ISNULL(a.head_number, '') <> ''
            ORDER BY b.idx
        ) h3
        OUTER APPLY (
            SELECT TOP 1 b.idx
            FROM v2h b
            WHERE LTRIM(RTRIM(ISNULL(a.name, ''))) = LTRIM(RTRIM(ISNULL(b.name, '')))
              AND LTRIM(RTRIM(ISNULL(a.name, ''))) <> ''
            ORDER BY b.idx
        ) h4
    ),
    src AS (
        SELECT
            TRY_CONVERT(INT, m.idx) AS src_idx,
            LEFT(COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(400), m.name))), ''), N'미지정-' + CONVERT(NVARCHAR(20), m.idx)), 120) AS name_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), m.ceo))), ''), 80) AS ceo_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), m.card_number))), ''), 20) AS card_number_val,
            LEFT(COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.tel))), ''), NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.hp))), '')), 40) AS tel_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), m.email))), ''), 120) AS email_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(1000), m.address))), ''), 255) AS address_val,
            CAST(NULL AS NVARCHAR(255)) AS address_detail_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.zip_code))), ''), 20) AS zipcode_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(400), m.cooperation_contract))), ''), 50) AS cooperation_contract_val,
            CAST(NULL AS NVARCHAR(80)) AS manager_name_val,
            CAST(NULL AS NVARCHAR(40)) AS manager_tel_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(MAX), m.memo))), ''), 2000) AS memo_val,
            LEFT(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.region_idx))), ''), 50) AS region_val,
            TRY_CONVERT(INT, m.head_idx) AS head_idx_src,
            hm.v2_head_idx AS head_idx_val,
            COALESCE(
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.member_status))), ''),
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), m.link_status))), '')
            ) AS raw_status,
            COALESCE(TRY_CONVERT(DATETIME, m.registered_date), TRY_CONVERT(DATETIME, m.update_date), GETDATE()) AS created_at_val,
            COALESCE(TRY_CONVERT(DATETIME, m.update_date), TRY_CONVERT(DATETIME, m.registered_date), GETDATE()) AS updated_at_val,
            COALESCE(TRY_CONVERT(INT, m.reg_employee_idx), TRY_CONVERT(INT, m.employee_idx), 0) AS created_by_val,
            COALESCE(TRY_CONVERT(INT, m.employee_idx), TRY_CONVERT(INT, m.reg_employee_idx), 0) AS updated_by_val
        FROM [CSM_C004732].dbo.Tb_Members m
        LEFT JOIN headmap hm
          ON hm.v1_head_idx = TRY_CONVERT(INT, m.head_idx)
    ),
    norm AS (
        SELECT
            s.src_idx,
            s.name_val,
            s.ceo_val,
            s.card_number_val,
            s.tel_val,
            s.email_val,
            s.address_val,
            s.address_detail_val,
            s.zipcode_val,
            s.cooperation_contract_val,
            s.manager_name_val,
            s.manager_tel_val,
            s.memo_val,
            s.region_val,
            s.head_idx_src,
            s.head_idx_val,
            s.raw_status,
            CASE
                WHEN s.raw_status IN (N'종료', N'해지', N'탈퇴', N'삭제') THEN N'종료'
                WHEN s.raw_status IN (N'중지', N'정지', N'휴면', N'비활성') THEN N'중지'
                WHEN s.raw_status IN (N'예정', N'대기', N'신규') THEN N'예정'
                WHEN s.raw_status IN (N'운영', N'정상', N'연결', N'활성') THEN N'운영'
                ELSE N'운영'
            END AS member_status_val,
            CASE
                WHEN s.raw_status IN (N'종료', N'해지', N'탈퇴', N'삭제') THEN CAST(1 AS BIT)
                ELSE CAST(0 AS BIT)
            END AS is_deleted_val,
            s.created_at_val,
            s.created_by_val,
            s.updated_at_val,
            s.updated_by_val,
            CASE
                WHEN s.raw_status IN (N'종료', N'해지', N'탈퇴', N'삭제') THEN COALESCE(s.updated_at_val, s.created_at_val, GETDATE())
                ELSE NULL
            END AS deleted_at_val,
            CASE
                WHEN s.raw_status IN (N'종료', N'해지', N'탈퇴', N'삭제') THEN COALESCE(NULLIF(s.updated_by_val, 0), NULLIF(s.created_by_val, 0), 0)
                ELSE NULL
            END AS deleted_by_val,
            COALESCE(s.created_at_val, GETDATE()) AS regdate_val,
            COALESCE(s.created_at_val, GETDATE()) AS registered_date_val
        FROM src s
        WHERE s.src_idx IS NOT NULL
    )
    SELECT * INTO #norm FROM norm;

    SET IDENTITY_INSERT dbo.Tb_Members ON;

    MERGE dbo.Tb_Members AS t
    USING #norm AS s
       ON t.idx = s.src_idx
    WHEN MATCHED THEN
        UPDATE SET
            t.name = s.name_val,
            t.ceo = s.ceo_val,
            t.card_number = s.card_number_val,
            t.tel = s.tel_val,
            t.email = s.email_val,
            t.address = s.address_val,
            t.address_detail = s.address_detail_val,
            t.zipcode = s.zipcode_val,
            t.cooperation_contract = s.cooperation_contract_val,
            t.manager_name = s.manager_name_val,
            t.manager_tel = s.manager_tel_val,
            t.memo = s.memo_val,
            t.region = s.region_val,
            t.head_idx = s.head_idx_val,
            t.member_status = s.member_status_val,
            t.is_deleted = s.is_deleted_val,
            t.created_at = s.created_at_val,
            t.created_by = s.created_by_val,
            t.updated_at = s.updated_at_val,
            t.updated_by = s.updated_by_val,
            t.deleted_at = s.deleted_at_val,
            t.deleted_by = s.deleted_by_val,
            t.regdate = s.regdate_val,
            t.registered_date = s.registered_date_val
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (
            idx, name, ceo, card_number, tel, email, address, address_detail, zipcode,
            cooperation_contract, manager_name, manager_tel, memo, region, head_idx,
            member_status, is_deleted, created_at, created_by, updated_at, updated_by,
            deleted_at, deleted_by, regdate, registered_date
        )
        VALUES (
            s.src_idx, s.name_val, s.ceo_val, s.card_number_val, s.tel_val, s.email_val, s.address_val, s.address_detail_val, s.zipcode_val,
            s.cooperation_contract_val, s.manager_name_val, s.manager_tel_val, s.memo_val, s.region_val, s.head_idx_val,
            s.member_status_val, s.is_deleted_val, s.created_at_val, s.created_by_val, s.updated_at_val, s.updated_by_val,
            s.deleted_at_val, s.deleted_by_val, s.regdate_val, s.registered_date_val
        )
    OUTPUT $action INTO @mergeLog(action_type);

    SET IDENTITY_INSERT dbo.Tb_Members OFF;

    DECLARE @maxIdx INT = (SELECT ISNULL(MAX(idx), 0) FROM dbo.Tb_Members);
    DBCC CHECKIDENT ('dbo.Tb_Members', RESEED, @maxIdx) WITH NO_INFOMSGS;

    SELECT
        (SELECT COUNT(1) FROM [CSM_C004732].dbo.Tb_Members) AS source_total,
        (SELECT COUNT(1) FROM #norm) AS source_normalized_total,
        (SELECT COUNT(1) FROM @mergeLog WHERE action_type = 'INSERT') AS inserted_count,
        (SELECT COUNT(1) FROM @mergeLog WHERE action_type = 'UPDATE') AS updated_count,
        (SELECT COUNT(1) FROM #norm WHERE head_idx_src IS NOT NULL AND head_idx_val IS NULL) AS unresolved_head_idx_count,
        (SELECT COUNT(1) FROM #norm WHERE raw_status IS NULL) AS null_status_source_count,
        (SELECT COUNT(1)
         FROM (
            SELECT card_number_val
            FROM #norm
            WHERE card_number_val IS NOT NULL AND LTRIM(RTRIM(card_number_val)) <> ''
            GROUP BY card_number_val
            HAVING COUNT(1) > 1
         ) d) AS source_card_number_duplicate_key_count,
        (SELECT COUNT(1)
         FROM (
            SELECT card_number
            FROM dbo.Tb_Members
            WHERE card_number IS NOT NULL AND LTRIM(RTRIM(card_number)) <> '' AND ISNULL(is_deleted,0)=0
            GROUP BY card_number
            HAVING COUNT(1) > 1
         ) d) AS target_card_number_duplicate_key_count,
        (SELECT COUNT(1) FROM dbo.Tb_Members) AS target_total_after,
        N'employee1_idx,site_employee_idx,budget_employee_idx,region_idx(target_missing)' AS optional_source_columns_skipped;

    DROP TABLE #norm;

    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK;

    IF EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID('tempdb..#norm'))
        DROP TABLE #norm;

    DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @line INT = ERROR_LINE();
    DECLARE @num INT = ERROR_NUMBER();
    RAISERROR(N'[FAIL] Tb_Members migration failed (%d:%d) %s', 16, 1, @num, @line, @msg);
END CATCH;
GO
