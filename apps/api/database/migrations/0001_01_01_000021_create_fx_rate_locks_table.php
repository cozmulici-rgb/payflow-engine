<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rate_locks', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->index();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->decimal('settlement_amount', 18, 4);
            $table->dateTimeTz('expires_at', 6)->index();
            $table->dateTimeTz('used_at', 6)->nullable();
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_locks');
    }
};
