<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_credentials', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('key_id', 64)->unique();
            $table->string('secret_hash');
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credentials');
    }
};
