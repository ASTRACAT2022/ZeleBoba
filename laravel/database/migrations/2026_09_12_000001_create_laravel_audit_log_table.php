<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laravel_audit_log', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('actor_type', 32);
            $table->string('actor_id', 100);
            $table->string('action', 100);
            $table->string('entity_type', 50);
            $table->string('entity_id', 100);
            $table->text('before_json')->nullable();
            $table->text('after_json')->nullable();
            $table->text('metadata_json');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_audit_log');
    }
};
