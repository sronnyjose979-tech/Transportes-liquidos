<?php

namespace App\Modules\AccessAndPersonnel\Application;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Representation only: route middleware authorizes the destination first. */
final class OfficePage
{
    public function render(Request $request, string $page): View|JsonResponse
    {
        $actor = $request->user();
        $navigation = app(SystemNavigation::class);
        $links = $navigation->links($actor);
        $context = [
            'page' => $page,
            'session' => ['id' => (string) $actor->id, 'login' => $actor->login,
                'roles' => ReadAuthorizationContext::forActor($actor)?->roleNames() ?? $actor->roles()->pluck('name')->all()],
            'navigation' => ['home' => $navigation->homeFromLinks($links), 'modules' => $links],
        ];

        return $request->header('X-Office-Navigation') === '1'
            ? response()->json(['data' => $context])->header('Cache-Control', 'private, no-store')
            : view('office', compact('context'));
    }
}
