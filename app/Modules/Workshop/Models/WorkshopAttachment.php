<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopAttachment extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'category', 'external_repair_id', 'storage_key', 'mime', 'extension', 'size_bytes', 'checksum', 'uploaded_by_id', 'root_record_id', 'previous_attachment_id', 'revision', 'reservation_operation_id'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'size_bytes' => 'integer', 'revision' => 'integer'];
    }
}
