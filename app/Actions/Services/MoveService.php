<?php

namespace App\Actions\Services;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Move a service one step up or down in the salon's menu order.
 * Authorisation (SalonPolicy::manageServices) is enforced by the caller.
 *
 * The whole menu is renumbered 1..n on every move: the display order is
 * sort_order-then-name, so legacy rows all sitting at the default 0 get
 * materialised into explicit positions the first time the owner reorders —
 * one nudge and the order they were LOOKING at becomes the stored truth.
 */
class MoveService
{
    public function handle(Salon $salon, Service $service, int $direction): void
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        $ordered = $salon->services()->displayOrder()->get()->values();
        $index = $ordered->search(fn (Service $s): bool => $s->id === $service->id);

        if ($index === false) {
            return;
        }

        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= $ordered->count()) {
            return; // already at the edge — nothing to do
        }

        $items = $ordered->all();
        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];

        DB::transaction(function () use ($items): void {
            foreach ($items as $position => $item) {
                if ($item->sort_order !== $position + 1) {
                    $item->update(['sort_order' => $position + 1]);
                }
            }
        });
    }
}
