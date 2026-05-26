<?php

return [
    'enabled' => env('BACKUP_ENABLED', true),
    'path' => env('BACKUP_PATH', storage_path('backups')),
    'keep_days' => env('BACKUP_KEEP_DAYS', 30),
    'file_paths' => [
        storage_path('app/public'),
        storage_path('app/tickets'),
    ],
    'cloud_storage' => [
        'enabled' => env('BACKUP_CLOUD_ENABLED', false),
        'disk' => env('BACKUP_CLOUD_DISK', 's3'),
    ],
];
