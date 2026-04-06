<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_status_history', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->string('status', 50);
            $table->string('reason')->nullable();
            $table->dateTimeTz('created_at', 6);

            $table->index(['transaction_id', 'created_at'], 'idx_transaction_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_status_history');
    }
};
