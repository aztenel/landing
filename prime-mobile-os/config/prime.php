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
];
