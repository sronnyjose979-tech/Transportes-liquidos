<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Application\WorkshopAttachments;
use App\Modules\Workshop\Http\Requests\WorkshopAttachmentRequest;
use App\Modules\Workshop\Http\Resources\WorkshopRecordResource;
use App\Modules\Workshop\Http\Resources\WorkshopResultResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkshopAttachmentController extends Controller
{
    public function __construct(private readonly WorkshopAttachments $attachments) {}

    public function reserve(WorkshopAttachmentRequest $request, string $order): WorkshopResultResource
    {
        $data = $request->validated();
        if ($data['category'] === 'invoice' || ! empty($data['external_repair_id'])) {
            app(WorkshopAccess::class)->office($request->user());
        }
        $key = $data['request_key'];
        unset($data['request_key']);

        return new WorkshopResultResource($this->attachments->reserve($request->user(), $order, $key, $data));
    }

    public function confirm(WorkshopAttachmentRequest $request, string $reservation): WorkshopResultResource
    {
        return new WorkshopResultResource($this->attachments->confirm($request->user(), $reservation, $request->validated('request_key'), $request->file('file')?->getRealPath()));
    }

    public function release(WorkshopAttachmentRequest $request, string $reservation): WorkshopResultResource
    {
        return new WorkshopResultResource($this->attachments->release($request->user(), $reservation, $request->validated('request_key'), $request->validated('reason')));
    }

    public function index(WorkshopAttachmentRequest $request, string $order)
    {
        return WorkshopRecordResource::collection($this->attachments->listForOrder($request->user(), $order, (int) $request->validated('page', 1)));
    }

    public function receipt(WorkshopAttachmentRequest $request, string $reservation): WorkshopResultResource
    {
        return new WorkshopResultResource($this->attachments->receipt($request->user(), $reservation));
    }

    public function download(WorkshopAttachmentRequest $request, string $attachment): BinaryFileResponse
    {
        return $this->attachments->download($request->user(), $attachment);
    }
}
