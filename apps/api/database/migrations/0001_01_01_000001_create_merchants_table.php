<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->string('display_name');
            $table->char('country', 2);
            $table->char('default_currency', 3);
            $table->string('status', 50);
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
