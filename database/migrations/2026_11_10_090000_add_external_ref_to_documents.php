<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['estimates', 'invoices', 'customers'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('external_ref_source', 50)->nullable();
                $table->string('external_ref_id', 191)->nullable();
                $table->unique(['company_id', 'external_ref_source', 'external_ref_id'], "{$name}_external_ref_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique("{$name}_external_ref_unique");
                $table->dropColumn(['external_ref_source', 'external_ref_id']);
            });
        }
    }
};
