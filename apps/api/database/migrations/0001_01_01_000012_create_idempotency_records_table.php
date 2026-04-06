<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_key', 320)->unique();
            $table->smallInteger('response_code')->unsigned();
            $table->json('response_body');
            $table->dateTimeTz('expires_at', 6)->index();
            $table->dateTimeTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
