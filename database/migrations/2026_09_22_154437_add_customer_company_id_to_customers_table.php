<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_company_id')->nullable();
            $table->foreign('customer_company_id')->references('id')->on('customer_companies')->onDelete('set null');
            $table->boolean('company_auto_update')->default(false);
            $table->timestamp('company_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_company_id']);
            $table->dropColumn(['customer_company_id', 'company_auto_update', 'company_synced_at']);
        });
    }
};
