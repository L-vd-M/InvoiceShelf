<?php

use App\Services\Document\DealLifecycleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_lifecycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            // one row per quote; no foreign keys, a deleted draft keeps its history like its revisions
            $table->unsignedBigInteger('first_quote_id')->unique();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('first_paid_at')->nullable();
            $table->timestamp('fully_paid_at')->nullable();
            $table->string('outcome', 10)->default('open');
            $table->string('lost_reason', 30)->nullable();
            $table->unsignedInteger('revision_count')->default(0);
            $table->unsignedInteger('price_change_count')->default(0);
            $table->bigInteger('first_total_minor')->nullable();
            $table->bigInteger('final_total_minor')->nullable();
            $table->decimal('discount_pct_first_to_final', 8, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('built_at')->nullable();

            $table->index(['company_id', 'outcome']);
            $table->index(['company_id', 'owner_user_id']);
            $table->index(['company_id', 'customer_id']);
        });

        DealLifecycleService::rebuildAll(emit: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_lifecycles');
    }
};
