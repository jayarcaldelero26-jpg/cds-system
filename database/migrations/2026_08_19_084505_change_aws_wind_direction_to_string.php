<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            $table->string('wind_direction', 20)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            $table->decimal('wind_direction', 8, 2)
                ->nullable()
                ->change();
        });
    }
};
