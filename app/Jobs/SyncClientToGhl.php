<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Ghl\GhlContactSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued push of one client's basic contact fields to its GHL contact
 * (create-and-link when unlinked). Pushes the client's CURRENT state, so it
 * is idempotent and re-dispatchable; an unchanged state makes no API call.
 * Same queue/retry model as the booking push; when every attempt fails the
 * failure stays visible on the client row instead of vanishing.
 */
class SyncClientToGhl implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $clientId) {}

    public static function queueFor(Client $client): void
    {
        self::dispatch($client->id)->afterCommit();
    }

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(GhlContactSync $sync): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $sync->push($client);
    }

    public function failed(?Throwable $exception): void
    {
        Client::query()->whereKey($this->clientId)->toBase()->update([
            'ghl_sync_status' => GhlContactSync::STATUS_FAILED,
            'ghl_sync_error' => mb_substr($exception?->getMessage() ?? 'Unknown error.', 0, 500),
        ]);
    }
}
