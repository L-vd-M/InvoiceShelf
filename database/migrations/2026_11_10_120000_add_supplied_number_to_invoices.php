<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('supplied_number', 40)->nullable();
            $table->unique(['company_id', 'supplied_number'], 'invoices_supplied_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_supplied_number_unique');
            $table->dropColumn('supplied_number');
        });
    }
};
