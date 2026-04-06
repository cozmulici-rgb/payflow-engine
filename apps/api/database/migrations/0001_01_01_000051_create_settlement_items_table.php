<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_items', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->uuid('transaction_id')->index();
            $table->string('processor_id', 100);
            $table->string('processor_reference')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('status', 50)->default('pending_submission');
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
    }
};
