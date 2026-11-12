<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['invoices', 'estimates', 'credit_notes'];

    public function up(): void
    {
        Schema::create('document_number_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('document_type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'year'], 'document_number_series_unique');
        });

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unsignedSmallInteger('number_year')->nullable();
                $table->unsignedBigInteger('series_number')->nullable();
                $table->unique(['company_id', 'number_year', 'series_number'], "{$tableName}_series_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_series_unique");
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['number_year', 'series_number']);
            });
        }

        Schema::dropIfExists('document_number_series');
    }
};
