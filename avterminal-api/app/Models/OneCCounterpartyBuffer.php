<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneCCounterpartyBuffer extends Model
{
    protected $table = 'onec_counterparty_buffers';

    protected $fillable = [
        'request_id',
        'lead_id',
        'contact_id',
        'company_id',
        'client_type',
        'vin',
        'payload_hash',
        'payload_json',
        'status',
        'pull_attempts',
        'pulled_at',
        'onec_counterparty_id',
        'onec_processing_status',
        'last_error',
        'processed_at',
    ];

    protected $casts = [
        'pulled_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
