<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_archives', function (Blueprint $table): void {
            $table->string('remote_availability', 16)->default('unknown')->after('archive_status');
            $table->timestamp('last_verified_at')->nullable()->after('remote_availability');
            $table->string('last_verification_error_class', 32)->nullable()->after('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_archives', function (Blueprint $table): void {
            $table->dropColumn(['remote_availability', 'last_verified_at', 'last_verification_error_class']);
        });
    }
};
