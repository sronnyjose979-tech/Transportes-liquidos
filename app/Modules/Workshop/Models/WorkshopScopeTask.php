<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopScopeTask extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['scope_id', 'description', 'line_number', 'supplier_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'line_number' => 'integer'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'scope_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkshopScopePart::class, 'task_id');
    }
}
