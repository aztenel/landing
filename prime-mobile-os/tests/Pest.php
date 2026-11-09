<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/*
 | Feature tests run on PostgreSQL (prime_mobile_test, see phpunit.xml): partial indexes, triggers and
 | FOR UPDATE are part of what is tested. RefreshDatabase migrates once and wraps every test in a transaction.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** A fresh organization «Prime Mobile» with roles, store, accounts, catalog bits and users (see Tests\Support\World). */
function world(array $settings = [], string $name = 'Prime Mobile'): World
{
    return World::create($settings, $name);
}
