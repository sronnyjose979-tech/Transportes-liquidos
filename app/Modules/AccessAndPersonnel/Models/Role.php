<?php

namespace App\Modules\AccessAndPersonnel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPPORTED_NAMES = ['administrator', 'secretary', 'driver', 'mechanic'];

    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
