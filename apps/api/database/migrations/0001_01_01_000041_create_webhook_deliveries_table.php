<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('webhook_endpoint_id')->index();
            $table->uuid('event_id')->index();
            $table->string('event_type', 100)->index();
            $table->uuid('merchant_id')->index();
            $table->string('url', 2048);
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 50)->index();
            $table->string('signature');
            $table->json('payload');
            $table->dateTimeTz('delivered_at', 6)->nullable();
            $table->dateTimeTz('created_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
