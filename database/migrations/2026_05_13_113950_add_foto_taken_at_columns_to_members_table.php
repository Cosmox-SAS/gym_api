<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('foto1_taken_at')->nullable()->after('foto1');
            $table->timestamp('foto2_taken_at')->nullable()->after('foto2');
            $table->timestamp('foto3_taken_at')->nullable()->after('foto3');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['foto1_taken_at', 'foto2_taken_at', 'foto3_taken_at']);
        });
    }
};
