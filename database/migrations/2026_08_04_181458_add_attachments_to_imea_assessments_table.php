<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imea_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('imea_assessments', 'status')) {
                $table->string('status')->default('Pending');
            }
            if (!Schema::hasColumn('imea_assessments', 'attachments')) {
                $table->json('attachments')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('imea_assessments', function (Blueprint $table) {
            $table->dropColumn(['status', 'attachments']);
        });
    }
};
