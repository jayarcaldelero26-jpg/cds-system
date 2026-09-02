<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('protected_area_id')->nullable()->after('section')->constrained('protected_areas')->nullOnDelete();
            $table->index('protected_area_id', 'users_protected_area_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_protected_area_index');
            $table->dropForeign(['protected_area_id']);
            $table->dropColumn('protected_area_id');
        });
    }
};
