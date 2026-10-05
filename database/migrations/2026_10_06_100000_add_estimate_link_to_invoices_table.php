<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('estimate_id')->nullable()->unique();
            $table->string('source_estimate_number')->nullable();
            $table->foreign('estimate_id')->references('id')->on('estimates')->nullOnDelete();
        });

        DB::table('company_settings')
            ->where('option', 'estimate_convert_action')
            ->where('value', 'delete_estimate')
            ->update(['value' => 'no_action']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropUnique(['estimate_id']);
            $table->dropColumn(['estimate_id', 'source_estimate_number']);
        });
    }
};
