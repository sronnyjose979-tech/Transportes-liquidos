<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentConfigurationSnapshot extends Model
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['tracks_expiration' => 'boolean', 'required_for_tractor_units' => 'boolean', 'required_for_tankers' => 'boolean'];
    }
}
