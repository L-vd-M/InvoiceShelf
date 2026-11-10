<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            // null owner means the creator owns the deal; assigning writes a revision
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('creator_id');
            $table->string('lost_reason', 30)->nullable();
            $table->timestamp('lost_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('sales_team', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sales_team');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['owner_user_id', 'lost_reason', 'lost_at']);
        });
    }
};
