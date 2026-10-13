<?php

namespace App\Modules\Workshop\Policies;

use App\Models\User;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Models\WorkshopWorkOrder;

class WorkshopWorkOrderPolicy
{
    public function __construct(private readonly WorkshopAccess $access) {}

    public function view(User $actor, WorkshopWorkOrder $order): bool
    {
        $this->access->readOrder($actor, $order->id);

        return true;
    }

    public function execute(User $actor, WorkshopWorkOrder $order): bool
    {
        $this->access->writeOrder($actor, $order->id);

        return true;
    }
}
