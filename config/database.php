<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

return [
    'host' => shvEnv('DB_HOST', '127.0.0.1'),
    'port' => shvEnvInt('DB_PORT', 1433),
    'database' => shvEnv('DB_NAME', 'CSM_C004732_V2'),
    'username' => shvEnv('DB_USER', 'sa'),
    'password' => shvEnv('DB_PASS', ''),
    'encrypt' => shvEnvBool('DB_ENCRYPT', false),
    'trust_server_certificate' => shvEnvBool('DB_TRUST_SERVER_CERT', true),
];
