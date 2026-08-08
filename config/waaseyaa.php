<?php

declare(strict_types=1);

return [
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'environment' => getenv('APP_ENV') ?: 'production',
    'log_level' => getenv('LOG_LEVEL') ?: 'warning',
    'database' => null,
    'community_id' => getenv('ANOKII_COMMUNITY_ID') ?: null,
    'config_dir' => getenv('WAASEYAA_CONFIG_DIR') ?: __DIR__ . '/sync',
    'files_dir' => getenv('WAASEYAA_FILES_DIR') ?: dirname(__DIR__) . '/storage/files',
    'jwt_secret' => getenv('WAASEYAA_JWT_SECRET') ?: '',
    'api_keys' => [],
    'api' => ['entity_type_allowlist' => []],
    'auth' => [
        'dev_fallback_account' => filter_var(
            getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
        'token_secret' => getenv('AUTH_TOKEN_SECRET') ?: '',
    ],
    // Empty is the safe direct-connection default. Behind a proxy, set the
    // TRUSTED_PROXIES environment variable to operator-owned IPs/CIDRs.
    'trusted_proxies' => [],
    'cors_origins' => [],
    'classification' => [
        'role_clearance' => [
            'admin' => 30,
            'nation-steward' => 30,
            'editor' => 10,
            'viewer' => 10,
            'anonymous' => 0,
        ],
    ],
    'upload_max_bytes' => 10 * 1024 * 1024,
    'upload_allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],
];
