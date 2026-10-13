<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workshop\Application\WorkshopAvailability;
use App\Modules\Workshop\Application\WorkshopCore;
use App\Modules\Workshop\Http\Requests\WorkshopCoreRequest;
use App\Modules\Workshop\Http\Resources\WorkshopResultResource;
use Illuminate\Http\JsonResponse;

final class WorkshopCoreController extends Controller
{
    public function __invoke(WorkshopCoreRequest $request, WorkshopCore $core, WorkshopAvailability $availability): JsonResponse
    {
        $action = str_replace('workshop.core.', '', $request->route()->getName());
        $data = $request->validated();
        $key = $data['request_key'];
        unset($data['request_key']);
        $parameters = $request->route()->parameters();
        $target = $parameters === [] ? null : reset($parameters);
        $result = $action === 'admission-link'
            ? $availability->linkOrder($request->user(), $target, $data['admission_id'], $key, $data['reason'])
            : $core->execute($request->user(), $action, $target, $key, $data);

        return (new WorkshopResultResource($result))->response()->setStatusCode(in_array($action, ['order-update', 'report-link-invalidate', 'admission-link-invalidate'], true) ? 200 : 201);
    }
}
