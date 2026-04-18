/**
 * pm2 ecosystem 설정 — SHVQ V2 Mail Worker
 * 실행: pm2 start ecosystem.config.js
 *
 * ⚠️  보안 주의:
 *   아래 모든 환경변수는 Windows 시스템 환경변수(setx ... /M)로 설정하거나
 *   서버 전용 ecosystem.local.js(git 미추적)에서 override할 것.
 *   이 파일에 실제 값을 직접 쓰지 말 것.
 *
 *   Windows 환경변수 설정 예:
 *     setx SHV_MAIL_DB_SERVER    "211.116.112.67" /M
 *     setx SHV_MAIL_DB_USER      "jjd" /M
 *     setx SHV_MAIL_DB_PASSWORD  "실제비밀번호" /M
 *     setx SHV_MAIL_DB_NAME      "CSM_C004732_V2" /M
 *     setx BROADCAST_SECRET      "실제시크릿" /M
 *     setx FCM_SERVICE_ACCOUNT_PATH "D:\SHV_ERP\SHVQ_V2\node\fcm_service_account.json" /M
 */
module.exports = {
    apps: [{
        name:        'shv-v2-mail-worker',
        script:      'worker.js',
        cwd:         'D:\\SHV_ERP\\SHVQ_V2\\node',
        instances:   2,
        exec_mode:   'cluster',
        autorestart: true,
        min_uptime:  '10s',
        max_restarts: 10,
        watch:       false,
        max_memory_restart: '4G',
        node_args:   '--max-old-space-size=3500',
        env: {
            NODE_ENV:                 'production',
            SHV_MAIL_DB_SERVER:       process.env.SHV_MAIL_DB_SERVER   || '211.116.112.67',
            SHV_MAIL_DB_PORT:         process.env.SHV_MAIL_DB_PORT     || '1433',
            SHV_MAIL_DB_USER:         process.env.SHV_MAIL_DB_USER     || 'jjd',
            /* SHV_MAIL_DB_PASSWORD — 반드시 Windows 시스템 환경변수로만 주입 (여기 직접 입력 금지) */
            SHV_MAIL_DB_NAME:         process.env.SHV_MAIL_DB_NAME     || 'CSM_C004732_V2',
            REDIS_HOST:               process.env.REDIS_HOST            || '127.0.0.1',
            REDIS_PORT:               process.env.REDIS_PORT            || '6379',
            /* BROADCAST_SECRET — 반드시 Windows 시스템 환경변수로만 주입 (여기 직접 입력 금지) */
            FCM_SERVICE_ACCOUNT_PATH: process.env.FCM_SERVICE_ACCOUNT_PATH || 'D:\\SHV_ERP\\SHVQ_V2\\node\\fcm_service_account.json',
        },
        error_file:      'logs/err.log',
        out_file:        'logs/out.log',
        log_date_format: 'YYYY-MM-DD HH:mm:ss',
        kill_timeout:    5000,
    }],
};
