<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopAdmissionExit extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['admission_id', 'authorization_application_id', 'exited_at', 'recorded_by_id', 'registered_at', 'reason', 'reviewed_content'];

    protected function casts(): array
    {
        return ['exited_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'reviewed_content' => 'array'];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function authorizationApplication(): BelongsTo
    {
        return $this->belongsTo(WorkshopAuthorizationApplication::class, 'authorization_application_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
