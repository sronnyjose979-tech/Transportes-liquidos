<?php

return [
    'max_attempts' => 3,
    'transaction_budget_ms' => 10000,
    'lock_timeout_ms' => 2000,
    'retry_wait_ms' => [[100, 300], [300, 900]],
    'attachment_limit' => 20,
    'storage_root' => storage_path('app/private/workshop'),
    'flock_timeout_ms' => 2000,
];
