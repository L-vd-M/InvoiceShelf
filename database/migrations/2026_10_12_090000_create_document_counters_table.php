<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['invoices' => 'invoice', 'estimates' => 'estimate', 'payments' => 'payment'];

    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('document_type', 40);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'document_type']);
        });

        foreach (self::TABLES as $tableName => $type) {
            $rows = DB::table($tableName)
                ->whereNotNull('sequence_number')
                ->groupBy('company_id')
                ->selectRaw('company_id, max(sequence_number) as highest')
                ->get();

            foreach ($rows as $row) {
                DB::table('document_counters')->insert([
                    'company_id' => $row->company_id,
                    'document_type' => $type,
                    'last_sequence' => $row->highest,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Older data (the 2021 sequence back-fill) may already hold duplicates. Never fail the
            // upgrade over that: skip the unique index for that table and say so in the log.
            $duplicates = DB::table($tableName)
                ->whereNotNull('sequence_number')
                ->groupBy('company_id', 'sequence_number')
                ->havingRaw('count(*) > 1')
                ->count();

            if ($duplicates > 0) {
                Log::warning("document numbering: {$duplicates} duplicate sequence numbers in {$tableName}; unique index not created");

                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unique(['company_id', 'sequence_number'], "{$tableName}_company_sequence_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (collect(Schema::getIndexes($tableName))->contains('name', "{$tableName}_company_sequence_unique")) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique("{$tableName}_company_sequence_unique"));
            }
        }

        Schema::dropIfExists('document_counters');
    }
};
