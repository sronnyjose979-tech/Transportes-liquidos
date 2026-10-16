<?php

return [
    // Opt-in: a server must supply a private destination, separate encryption key and maintenance window.
    'destination' => env('BACKUP_DESTINATION'),
    'key_file' => env('BACKUP_KEY_FILE'),
    'pg_bin' => env('BACKUP_PG_BIN', ''),
    'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 600),
    'retention_days' => env('BACKUP_RETENTION_DAYS'),
    'schedule_time' => env('BACKUP_SCHEDULE_TIME'),
    'writers_stopped' => filter_var(env('BACKUP_WRITERS_STOPPED', false), FILTER_VALIDATE_BOOLEAN),
    // Only a connection explicitly designated for an isolated recovery server may restore.
    'restore_connection' => env('BACKUP_RESTORE_CONNECTION'),
];
