<?php

return [
    /*
     | HMAC key of the IIN blind index (TZ 03 §17). Must differ from APP_KEY in production.
     */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
     | Advisory lock key of the per-organization journal hash chain. Keep in sync with the migration
     | 2026_11_09_160800_ledger_create_guard_triggers (CHAIN_LOCK_KEY).
     */
    'chain_lock_key' => 7700101,

    /*
     | Shell (module «shell»): one application, two addresses (TZ 05 §1). The ERP (Filament panel with an empty
     | path) answers on erp_domain, the storefront on shop_domain. Locally: http://erp.localhost:8101 and
     | http://localhost:8101 (Chrome resolves *.localhost to 127.0.0.1 by itself). Both must be set.
     */
    'erp_domain' => env('PRIME_ERP_DOMAIN', 'erp.localhost'),
    'shop_domain' => env('PRIME_SHOP_DOMAIN', 'localhost'),

    /*
     | Login protection (TZ 04 Э1.1): N wrong passwords in a row for one phone → pause. Text of the error is
     | the TZ text («…через 10 минут…»), keep the minutes in sync with it.
     */
    'login' => [
        'max_attempts' => 5,
        'lockout_minutes' => 10,
        'ip_max_attempts' => 20,          // TZ 03 §7.4: 20 errors from one IP per hour
    ],

    /* Invitation / password reset links (TZ 03 §14.2, §7.5). */
    'invitation_hours' => 72,
    'password_reset_hours' => 24,

    /*
     | Screen lock after idle, minutes (README «Единые решения»; TZ 03 §9): store computer 10, iPhone 5,
     | admin/accountant/owner laptop 15. The firm may change them in Settings → Безопасность.
     */
    'lock' => [
        'store_computer' => 10,
        'iphone' => 5,
        'laptop' => 15,
        'warning_seconds' => 30,
    ],
];
