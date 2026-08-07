<?php

namespace App\Services\Health;

use App\Enums\AgencyRole;
use App\Mail\HealthAlertMail;
use App\Models\HealthCheckRun;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Records every health-check run (manual page run or the scheduled monitor)
 * and watches for green→red transitions: a check that was not failing on
 * the previous run and fails now. Regressions are stored on the run row
 * (the page's history view flags them) and emailed to the salon's agency
 * owners/admins — never to salon staff, never to clients. Mail is fail-safe:
 * a mail hiccup never breaks the run that detected the problem.
 */
class HealthMonitor
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SCHEDULED = 'scheduled';

    /** Runs shown in the page's history list. */
    public const HISTORY_SHOWN = 10;

    /**
     * @param  array{categories: list<array{key: string, label: string, checks: list<array{key: string, label: string, status: string, message: string, fix: string|null}>}>, summary: array{pass: int, warn: int, fail: int}}  $report
     */
    public function record(Salon $salon, array $report, string $source): HealthCheckRun
    {
        $results = [];
        foreach ($report['categories'] as $category) {
            foreach ($category['checks'] as $line) {
                $results[$line['key']] = [
                    'label' => $line['label'],
                    'status' => $line['status'],
                    'message' => $line['message'],
                ];
            }
        }

        $previous = HealthCheckRun::query()
            ->where('salon_id', $salon->id)
            ->latest('id')
            ->first();

        // Green→red: failing NOW, was known and NOT failing on the previous
        // run. A check absent from either run (the monitor skips the
        // test-booking checks) is never a transition; a check failing twice
        // in a row alerts once, on the run that flipped.
        $regressions = [];
        if ($previous !== null) {
            foreach ($results as $key => $line) {
                $was = $previous->results[$key]['status'] ?? null;

                if ($line['status'] === 'fail' && $was !== null && $was !== 'fail') {
                    $regressions[] = [
                        'key' => $key,
                        'label' => $line['label'],
                        'message' => $line['message'],
                        'was' => $was,
                    ];
                }
            }
        }

        $run = HealthCheckRun::create([
            'salon_id' => $salon->id,
            'source' => $source,
            'pass_count' => $report['summary']['pass'],
            'warn_count' => $report['summary']['warn'],
            'fail_count' => $report['summary']['fail'],
            'results' => $results,
            'regressions' => $regressions !== [] ? $regressions : null,
        ]);

        if ($regressions !== []) {
            $this->alert($salon, $regressions);
        }

        return $run;
    }

    /**
     * The latest runs for the page's history view, newest first.
     *
     * @return Collection<int, HealthCheckRun>
     */
    public function history(Salon $salon, int $limit = self::HISTORY_SHOWN): Collection
    {
        return HealthCheckRun::query()
            ->where('salon_id', $salon->id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<array{key: string, label: string, message: string, was: string}>  $regressions
     */
    private function alert(Salon $salon, array $regressions): void
    {
        $recipients = User::query()
            ->where('agency_id', $salon->agency_id)
            ->whereIn('agency_role', [AgencyRole::Owner->value, AgencyRole::Admin->value])
            ->get(['id', 'name', 'email']);

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->queue(new HealthAlertMail($recipient->name, $salon, $regressions));
            } catch (\Throwable $e) {
                report($e); // alerting must never break the run that found the problem
            }
        }
    }
}
