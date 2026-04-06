<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_type', 100);
            $table->uuid('reference_id');
            $table->string('description', 500);
            $table->date('effective_date')->index();
            $table->dateTimeTz('created_at', 6);

            $table->index(['reference_type', 'reference_id'], 'idx_journal_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
