<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class TransportRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['customer_id', 'liquid_product_id', 'route_id', 'requested_liters', 'requested_at', 'registered_by_id', 'version'];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string) $request->getAttributes()['requested_liters'])) {
                throw new InvalidArgumentException('El volumen debe expresarse con hasta dos decimales.');
            }
        });
    }

    protected function casts(): array
    {
        return ['requested_liters' => 'decimal:2', 'requested_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function liquidProduct(): BelongsTo
    {
        return $this->belongsTo(LiquidProduct::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TransportChange::class, 'transport_request_id');
    }

    public function authorizationRequests(): HasMany
    {
        return $this->hasMany(TransportAuthorizationRequest::class, 'transport_request_id');
    }
}
