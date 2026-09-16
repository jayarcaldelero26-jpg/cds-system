<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_routing_attachments', function (Blueprint $table): void {
            $table->string('purpose', 40)->default('routing_copy')->after('action_key');
        });
    }

    public function down(): void
    {
        Schema::table('submission_routing_attachments', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
