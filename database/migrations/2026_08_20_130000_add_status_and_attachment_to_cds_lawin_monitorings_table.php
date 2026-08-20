<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cds_lawin_monitorings', 'status')) {
            Schema::table('cds_lawin_monitorings', function (Blueprint $table): void {
                $table->string('status')->default('Under Review')->after('remarks');
            });
        }

        if (! Schema::hasColumn('cds_lawin_monitorings', 'attachment')) {
            Schema::table('cds_lawin_monitorings', function (Blueprint $table): void {
                $table->string('attachment')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        // Keep rollback non-destructive because either column may have existed
        // before this compatibility migration was applied.
    }
};
