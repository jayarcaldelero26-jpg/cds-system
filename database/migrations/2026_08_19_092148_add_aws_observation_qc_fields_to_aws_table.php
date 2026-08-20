<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            if (!Schema::hasColumn('aws', 'observation_count')) {
                $table->unsignedSmallInteger('observation_count')
                    ->nullable()
                    ->after('timestamps');
            }

            if (!Schema::hasColumn('aws', 'expected_observations')) {
                $table->unsignedSmallInteger('expected_observations')
                    ->nullable()
                    ->after('observation_count');
            }

            if (!Schema::hasColumn('aws', 'data_completeness')) {
                $table->decimal('data_completeness', 5, 1)
                    ->nullable()
                    ->after('expected_observations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('aws', 'data_completeness')) {
                $columns[] = 'data_completeness';
            }

            if (Schema::hasColumn('aws', 'expected_observations')) {
                $columns[] = 'expected_observations';
            }

            if (Schema::hasColumn('aws', 'observation_count')) {
                $columns[] = 'observation_count';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
