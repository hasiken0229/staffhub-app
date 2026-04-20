<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'google_chat_user_id')) {
                $table->string('google_chat_user_id', 100)
                    ->nullable()
                    ->after('retired_on');
                $table->unique('google_chat_user_id', 'employees_google_chat_user_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'google_chat_user_id')) {
                $table->dropUnique('employees_google_chat_user_id_unique');
                $table->dropColumn('google_chat_user_id');
            }
        });
    }
};
