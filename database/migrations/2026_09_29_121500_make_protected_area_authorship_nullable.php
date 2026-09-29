<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protected_areas', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('protected_areas')->whereNull('created_by')->orWhereNull('updated_by')->exists()) {
            throw new LogicException('Cannot make protected-area authorship required while unknown authorship values remain.');
        }

        Schema::table('protected_areas', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable(false)->change();
            $table->foreignId('updated_by')->nullable(false)->change();
        });
    }
};
