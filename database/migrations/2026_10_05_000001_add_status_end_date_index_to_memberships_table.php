<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La lista de membresías, las estadísticas y la tarea diaria filtran por estado y fecha de fin.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->index(['status', 'end_date'], 'memberships_status_end_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex('memberships_status_end_date_index');
        });
    }
};
