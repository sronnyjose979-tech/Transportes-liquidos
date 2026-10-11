<?php

namespace App\Modules\TransportAndDispatch\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['origin_site_id', 'destination_site_id', 'name'];

    public function originSite(): BelongsTo
    {
        return $this->belongsTo(OperationSite::class, 'origin_site_id');
    }

    public function destinationSite(): BelongsTo
    {
        return $this->belongsTo(OperationSite::class, 'destination_site_id');
    }

    public function transportRequests(): HasMany
    {
        return $this->hasMany(TransportRequest::class, 'route_id');
    }
}
