<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use App\Modules\FleetAndDocuments\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fleet-documents') ?? false;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'document_type_id' => [$this->routeIs('fleet.documents.renew') ? 'prohibited' : 'required', 'uuid', Rule::exists('document_types', 'id')],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'file' => ['required', 'file', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    $fail('El archivo no es una carga válida.');

                    return;
                }
                $extension = strtolower($value->getClientOriginalExtension());
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$extension] ?? null;
                if ($value->getSize() > Document::MAX_FILE_SIZE_BYTES || $value->getSize() < 1) {
                    $fail('El archivo debe contener entre 1 y 10.000.000 bytes.');

                    return;
                }
                if ($mime === null || $value->getMimeType() !== $mime) {
                    $fail('El contenido, MIME y extensión deben corresponder a PDF, JPG o PNG.');

                    return;
                }
                $contents = file_get_contents($value->getRealPath());
                if ($mime === 'application/pdf') {
                    $valid = preg_match('/^%PDF-(1\.[0-7]|2\.0)/', $contents) === 1
                        && preg_match('/%%EOF\s*$/', $contents) === 1;
                } else {
                    $image = @getimagesize($value->getRealPath());
                    $valid = $image !== false && ($image['mime'] ?? null) === $mime
                        && ($extension === 'jpg' ? str_ends_with($contents, "\xff\xd9") : str_ends_with($contents, "IEND\xae\x42\x60\x82"));
                }
                if (! $valid) {
                    $fail('La estructura del archivo no corresponde al formato indicado.');
                }
            }],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('issued_on') && ! $validator->errors()->has('expires_on')
                && $this->filled('issued_on') && $this->filled('expires_on') && $this->input('issued_on') > $this->input('expires_on')) {
                $validator->errors()->add('expires_on', 'El vencimiento no puede ser anterior a la emisión.');
            }
            foreach (array_diff(array_keys($this->all()), ['request_key', 'document_type_id', 'issued_on', 'expires_on', 'file', '_token']) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
        }];
    }
}
