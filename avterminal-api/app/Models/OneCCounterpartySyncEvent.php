<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneCCounterpartySyncEvent extends Model
{
    protected $table = 'onec_counterparty_sync_events';

    protected $fillable = [
        'buffer_id',
        'event_type',
        'attempt_no',
        'request_id',
        'http_status',
        'request_payload',
        'response_payload',
        'result',
        'error_message',
    ];
}
