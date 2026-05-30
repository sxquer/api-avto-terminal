<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('onec_counterparty_buffers', function (Blueprint $table) {
            $table->string('environment', 32)
                ->default('production')
                ->after('request_id');

            $table->index(['environment', 'status', 'created_at'], 'onec_buffer_env_status_created_idx');
            $table->index(['environment', 'lead_id', 'payload_hash'], 'onec_buffer_env_lead_hash_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onec_counterparty_buffers', function (Blueprint $table) {
            $table->dropIndex('onec_buffer_env_status_created_idx');
            $table->dropIndex('onec_buffer_env_lead_hash_idx');
            $table->dropColumn('environment');
        });
    }
};
