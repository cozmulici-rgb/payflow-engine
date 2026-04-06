<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_batches', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('processor_id', 100)->index();
            $table->char('currency', 3);
            $table->date('batch_date')->index();
            $table->string('status', 50)->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total_amount', 18, 4);
            $table->string('artifact_key')->nullable();
            $table->string('exception_reason')->nullable();
            $table->dateTimeTz('submitted_at', 6)->nullable();
            $table->dateTimeTz('created_at', 6)->index();
            $table->dateTimeTz('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_batches');
    }
};
