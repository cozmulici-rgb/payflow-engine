<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('idempotency_key');
            $table->string('type', 50);
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->char('settlement_currency', 3);
            $table->string('payment_method_type', 50);
            $table->string('payment_method_token');
            $table->string('capture_mode', 50);
            $table->string('reference')->nullable();
            $table->string('status', 50)->index();
            $table->string('processor_id', 100)->nullable();
            $table->string('processor_reference')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->decimal('settlement_amount', 18, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTimeTz('created_at', 6)->index();
            $table->dateTimeTz('updated_at', 6);

            $table->unique(['merchant_id', 'idempotency_key'], 'uk_merchant_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
