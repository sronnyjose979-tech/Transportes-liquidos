<?php

namespace App\Modules\Workshop\Http\Requests;

class WorkshopAttachmentRequest extends WorkshopInput
{
    public static function reservationFields(): array
    {
        return ['category' => ['required', 'in:photo,document,invoice'], 'extension' => ['required', 'in:pdf,jpg,jpeg,png'], 'mime' => ['required', 'in:application/pdf,image/jpeg,image/png'], 'size_bytes' => ['required', 'integer', 'min:1', 'max:10000000'], 'checksum' => ['required', 'regex:/^[0-9a-f]{64}$/D'], 'reason' => ['required', 'string', 'max:10000'], 'external_repair_id' => ['nullable', 'uuid'], 'previous_attachment_id' => ['nullable', 'uuid'], 'decision_id' => ['nullable', 'uuid']];
    }

    public function rules(): array
    {
        $base = ['request_key' => ['required', 'uuid']];

        return match ($this->route()->getActionMethod()) {
            'reserve' => $base + self::reservationFields(),
            'confirm' => $base + ['file' => ['sometimes', 'file', 'max:9766', 'mimetypes:application/pdf,image/jpeg,image/png']],
            'release' => $base + ['reason' => $this->reason()],
            'index' => ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']],
            default => [],
        };
    }
}
