<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('type', 50);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->char('currency', 3)->nullable();
            $table->dateTimeTz('created_at', 6);
            $table->dateTimeTz('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
