<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number')->nullable();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->bigInteger('total')->nullable();
            $table->string('status_at_deletion', 40)->nullable();
            $table->string('paid_status_at_deletion', 40)->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deletions');
    }
};
