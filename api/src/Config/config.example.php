<?php

declare(strict_types=1);

return [
    'app_env' => 'production',
    'db_host' => 'localhost',
    'db_name' => 'karmotor_medisa',
    'db_user' => 'CHANGE_ME_DB_USER',
    'db_password' => 'CHANGE_ME_DB_PASSWORD',
    'jwt_secret' => 'CHANGE_ME_JWT_SECRET_MIN_32_CHARS',
    'jwt_ttl_seconds' => 86400,
    // S3C: workplace QR HMAC secret (min 32 chars). Missing => QR endpoints fail closed only.
    'qr_signing_secret' => 'CHANGE_ME_QR_SIGNING_SECRET_MIN_32_CHARS',
    // Allowed range 30–120; invalid values fall back to 60.
    'qr_ttl_seconds' => 60,
    'cors_allowed_origins' => '',
    'personel_belge_storage_root' => '',
    // Physical destruction OPS gate — default OFF. Tests may override via env.
    'retention_physical_destruction_enabled' => false,
];
