<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemotePollerService extends Model
{
    public $timestamps = false;
    protected $table = 'remote_poller_services';
    protected $fillable = [
        'remote_poller_id',
        'service_id',
        'last_status',
        'last_message',
        'last_perf',
        'last_checked',
    ];

    protected $casts = [
        'last_checked' => 'datetime',
    ];

    // ---- Define Relationships ----

    public function remotePoller(): BelongsTo
    {
        return $this->belongsTo(RemotePoller::class, 'remote_poller_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
