<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->longText('foto1')->nullable()->after('fingerprint_data');
            $table->longText('foto2')->nullable()->after('foto1');
            $table->longText('foto3')->nullable()->after('foto2');
        });

        if (Schema::hasColumn('members', 'initial_photos')) {
            Schema::table('members', function (Blueprint $table) {
                $table->dropColumn('initial_photos');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('members', 'initial_photos')) {
            Schema::table('members', function (Blueprint $table) {
                $table->json('initial_photos')->nullable()->after('fingerprint_data');
            });
        }

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['foto1', 'foto2', 'foto3']);
        });
    }
};
