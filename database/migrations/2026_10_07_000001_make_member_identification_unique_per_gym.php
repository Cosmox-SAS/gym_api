<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una misma persona puede ser cliente de varios gimnasios: la cédula debe ser única
     * dentro de cada gimnasio, no en toda la plataforma.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unique(['gimnasio_id', 'identification'], 'members_gimnasio_identification_unique');
            $table->dropUnique('members_identification_unique');
            // Se mantiene un índice simple para las búsquedas por cédula (kiosco).
            $table->index('identification', 'members_identification_index');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // MySQL usa el índice compuesto para la llave foránea de gimnasio_id;
            // hay que darle uno propio antes de quitarlo.
            $table->index('gimnasio_id', 'members_gimnasio_id_foreign');
            $table->dropIndex('members_identification_index');
            $table->dropUnique('members_gimnasio_identification_unique');
            // Falla si para entonces ya hay cédulas repetidas entre gimnasios.
            $table->unique('identification', 'members_identification_unique');
        });
    }
};
