<?php

use App\Services\Document\RevisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            // nullable like company_id on the documents themselves
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('revision_no');
            $table->string('reason_code', 20);
            $table->text('note')->nullable();
            $table->string('status', 20)->nullable();
            $table->json('snapshot');
            $table->char('totals_hash', 64);
            $table->json('diff_from_previous')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('source', 20);
            $table->timestamp('created_at')->useCurrent();

            // no foreign key to the document: a deleted draft keeps its history
            $table->unique(['document_type', 'document_id', 'revision_no'], 'document_revisions_document_no_unique');
            $table->index(['company_id', 'document_type', 'document_id'], 'document_revisions_document_index');
            $table->index(['company_id', 'created_at'], 'document_revisions_company_created_index');
        });

        RevisionService::backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
