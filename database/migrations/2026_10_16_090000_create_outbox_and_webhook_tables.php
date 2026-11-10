<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 40)->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 60);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('subject_kind', 40);
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('subject_ref')->nullable();
            $table->json('data');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'id']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('url', 2048);
            $table->string('description')->nullable();
            $table->text('secret');
            $table->json('event_types')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbox_event_id');
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->unsignedBigInteger('company_id');
            $table->string('state', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['outbox_event_id', 'webhook_endpoint_id']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['company_id', 'state']);
            $table->foreign('outbox_event_id')->references('id')->on('outbox_events');
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('outbox_events');
    }
};
