<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 100)->index();
            $table->string('actor_id', 100);
            $table->string('action', 50);
            $table->string('resource_type', 100);
            $table->string('resource_id', 100)->index();
            $table->uuid('correlation_id');
            $table->json('context');
            $table->dateTimeTz('created_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
