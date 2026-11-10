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
            $table->timestamp('issued_at')->nullable()->after('sequence_number');
            $table->index(['company_id', 'issued_at']);
        });

        // Everything already sent, viewed, completed or paid is final: it keeps its number and is frozen.
        DB::table('invoices')
            ->where(function ($query) {
                $query->where('status', '<>', 'DRAFT')->orWhere('sent', true);
            })
            ->update(['issued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'issued_at']);
            $table->dropColumn('issued_at');
        });
    }
};
