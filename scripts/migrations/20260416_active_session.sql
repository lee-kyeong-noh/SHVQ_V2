/* ============================================================
   Wave 1 — 중복 로그인 방지: 활성 세션 추적 테이블
   DB: CSM_C004732_V2 (67번 개발DB)
   날짜: 2026-04-16
   ============================================================ */

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tb_UserActiveSession'
)
BEGIN
    CREATE TABLE dbo.Tb_UserActiveSession (
        user_pk      INT           NOT NULL  PRIMARY KEY,
        session_id   VARCHAR(128)  NOT NULL,
        client_ip    VARCHAR(50)   NULL,
        user_agent   NVARCHAR(500) NULL,
        login_at     DATETIME      NOT NULL  DEFAULT GETDATE(),
        last_seen_at DATETIME      NOT NULL  DEFAULT GETDATE()
    );
END
GO
