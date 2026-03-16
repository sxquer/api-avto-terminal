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
        Schema::create('onec_counterparty_buffers', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('client_type', 32);
            $table->string('vin')->nullable();
            $table->string('payload_hash', 64);
            $table->longText('payload_json');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('pull_attempts')->default(0);
            $table->timestamp('pulled_at')->nullable();
            $table->string('onec_counterparty_id')->nullable();
            $table->string('onec_processing_status', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('lead_id');
            $table->index('vin');
        });

        Schema::create('onec_counterparty_sync_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buffer_id');
            $table->string('event_type', 32);
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('request_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->string('result', 32);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['buffer_id', 'event_type']);
            $table->index('request_id');
            $table->foreign('buffer_id')
                ->references('id')
                ->on('onec_counterparty_buffers')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onec_counterparty_sync_events');
        Schema::dropIfExists('onec_counterparty_buffers');
    }
};
