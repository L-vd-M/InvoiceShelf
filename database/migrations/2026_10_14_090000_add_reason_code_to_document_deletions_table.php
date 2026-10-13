<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_deletions', function (Blueprint $table) {
            $table->string('reason_code', 40)->nullable()->after('paid_status_at_deletion');
        });
    }

    public function down(): void
    {
        Schema::table('document_deletions', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });
    }
};
