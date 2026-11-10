<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('key', 80);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->text('response_headers')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
