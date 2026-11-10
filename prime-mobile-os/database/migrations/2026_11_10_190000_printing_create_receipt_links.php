<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Electronic receipt and warranty by a secret link (TZ 06 §6.5, §8): /r/<token>, lives 3 years. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('document_id')->constrained();
            $t->string('token', 32)->unique();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_links');
    }
};
