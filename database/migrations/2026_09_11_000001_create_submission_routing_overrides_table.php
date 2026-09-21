<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('submission_routing_overrides')) {
            Schema::table('submission_routing_overrides', function (Blueprint $table) {
                $table->index(['actual_actor_user_id', 'created_at'], 'sro_actor_created_idx');
            });

            return;
        }

        Schema::create('submission_routing_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('source_record_id');
            $table->string('engine');
            $table->string('action_key');
            $table->string('event_key')->nullable();
            $table->foreignId('actual_actor_user_id')->constrained('users');
            $table->string('actual_actor_category')->nullable();
            $table->string('overridden_accountable_category')->nullable();
            $table->string('overridden_office')->nullable();
            $table->unsignedBigInteger('protected_area_id')->nullable();
            $table->text('reason');
            $table->string('authentication_method');
            $table->unsignedBigInteger('passkey_id')->nullable();
            $table->string('previous_stage')->nullable();
            $table->string('resulting_stage')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source', 'source_record_id']);
            $table->index(['actual_actor_user_id', 'created_at'], 'sro_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_routing_overrides');
    }
};