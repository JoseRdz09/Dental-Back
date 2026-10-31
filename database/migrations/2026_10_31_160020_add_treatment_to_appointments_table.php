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
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('treatment_id')
                ->nullable()
                ->after('dentist_user_id')
                ->constrained('treatments')
                ->restrictOnDelete();

            $table->decimal('treatment_price', 10, 2)
                ->nullable()
                ->after('treatment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['treatment_id']);
            $table->dropColumn([
                'treatment_id',
                'treatment_price',
            ]);
        });
    }
};
