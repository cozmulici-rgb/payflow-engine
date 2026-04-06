<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('journal_entry_id')->index();
            $table->uuid('account_id');
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->uuid('transaction_id')->nullable()->index();
            $table->uuid('settlement_batch_id')->nullable()->index();
            $table->string('description', 500);
            $table->date('effective_date')->index();
            $table->dateTimeTz('created_at', 6);

            $table->index(['account_id', 'effective_date'], 'idx_account_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
