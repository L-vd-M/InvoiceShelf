<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedInteger('creator_id')->nullable();
            $table->string('credit_note_number');
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->unsignedBigInteger('customer_sequence_number')->nullable();
            $table->date('credit_note_date');
            $table->string('status', 20)->default('ISSUED');
            $table->string('reason_code', 40);
            $table->text('reason')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 15, 2)->nullable();
            $table->unsignedBigInteger('discount_val')->default(0);
            $table->unsignedBigInteger('sub_total');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('applied_amount')->default(0);
            $table->unsignedBigInteger('unapplied_amount')->default(0);
            $table->unsignedInteger('currency_id')->nullable();
            $table->decimal('exchange_rate', 19, 6)->nullable();
            $table->string('template_name')->nullable();
            $table->string('unique_hash')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sequence_number'], 'credit_notes_company_sequence_unique');
            $table->index(['company_id', 'invoice_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices');
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('credit_note_id');
            $table->unsignedInteger('invoice_item_id')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_name')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount_val')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->timestamps();

            $table->foreign('credit_note_id')->references('id')->on('credit_notes')->onDelete('cascade');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('credited_total')->default(0)->after('due_amount');
        });

        // existing companies get the default number format; new ones get it from CompanyService
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('company_settings')->insertOrIgnore([
                'company_id' => $companyId,
                'option' => 'creditnote_number_format',
                'value' => '{{SERIES:CN}}{{DELIMITER:-}}{{SEQUENCE:6}}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credited_total');
        });
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
        DB::table('company_settings')->where('option', 'creditnote_number_format')->delete();
    }
};
