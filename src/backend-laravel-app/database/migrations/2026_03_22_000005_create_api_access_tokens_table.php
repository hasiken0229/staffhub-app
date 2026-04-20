<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 20)->index();
            $table->unsignedBigInteger('tokenable_id')->index();
            $table->string('role', 20)->index();
            $table->string('token_kind', 20)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('pair_key', 36)->index();
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
            $table->index(['pair_key', 'token_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_access_tokens');
    }
};
