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
        Schema::table('transportation_expenses', function (Blueprint $table) {
            //        
            $table->integer('display_order')->after('expense_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transportation_expenses', function (Blueprint $table) {
            //
            $table->dropColumn('display_order');
        });
    }
};
