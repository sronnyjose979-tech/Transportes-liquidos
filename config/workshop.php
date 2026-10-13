<?php

return [
    'max_attempts' => 3,
    'transaction_budget_ms' => 10000,
    'lock_timeout_ms' => 2000,
    'retry_wait_ms' => [[100, 300], [300, 900]],
];
