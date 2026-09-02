<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'unit_assignment')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('unit_assignment', 32)->nullable()->after('section');
                $table->index('unit_assignment', 'users_unit_assignment_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'unit_assignment')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_unit_assignment_index');
                $table->dropColumn('unit_assignment');
            });
        }
    }
};
