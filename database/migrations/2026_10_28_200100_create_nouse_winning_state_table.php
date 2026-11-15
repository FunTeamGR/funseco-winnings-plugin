<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // XAseco's nouse_winning_state: row `map_counter` survives restarts.
        Schema::create('nouse_winning_state', function (Blueprint $table) {
            $table->string('name', 30)->primary();
            $table->string('value', 50)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nouse_winning_state');
    }
};
