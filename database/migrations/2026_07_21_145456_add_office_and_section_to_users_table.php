<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'office_designated')) {
                $table->string('office_designated')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'section')) {
                $table->string('section')->nullable()->after('office_designated');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['office_designated', 'section']);
        });
    }
};
