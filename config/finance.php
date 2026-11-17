<?php

declare(strict_types=1);

return [

    /*
    | Financial data is stored in UTC; dates shown to users and "today" for transaction dates
    | are evaluated in this timezone.
    */
    'display_timezone' => env('FINANCE_DISPLAY_TIMEZONE', 'Asia/Jakarta'),

    'default_currency' => env('FINANCE_DEFAULT_CURRENCY', 'IDR'),

    /* How long idempotency keys are honoured before `finance:prune` may delete them. */
    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 72),

    /* Data-retention for audit logs (ISO/IEC 27001 A.8.10). 0 = keep forever. */
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 2555), // ~7 years

];
