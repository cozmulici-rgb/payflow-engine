<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('url', 2048);
            $table->string('signing_secret');
            $table->json('event_types');
            $table->string('status', 50)->default('active');
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
