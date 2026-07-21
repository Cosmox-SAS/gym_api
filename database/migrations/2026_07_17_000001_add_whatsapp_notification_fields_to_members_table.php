<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('allow_whatsapp_notifications')->default(false)->after('phone');
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('allow_whatsapp_notifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['allow_whatsapp_notifications', 'whatsapp_opt_in_at']);
        });
    }
};
