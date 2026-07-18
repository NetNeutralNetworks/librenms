<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RemotePoller extends Model
{
    protected $table = 'remote_pollers';
    protected $fillable = [
        'poller_name',
        'url',
        'enabled',
        'version',
        'last_contact',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_contact' => 'datetime',
    ];

    // ---- Query Scopes ----

    public function scopeIsEnabled($query)
    {
        return $query->where('enabled', 1);
    }

    // ---- Define Relationships ----

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'remote_poller_services', 'remote_poller_id', 'service_id')
            ->withPivot(['last_status', 'last_message', 'last_perf', 'last_checked']);
    }
}
