<?php

namespace App\Services\Onboarding;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlAvailabilityPusher;

/**
 * The salon onboarding wizard's brain. Step COMPLETION is computed from live
 * data wherever the app can verify it (stylists exist, connection tested,
 * every stylist mapped, availability synced…) so progress is always truthful
 * and resumes for free. Only two things persist in salons.onboarding: the
 * current step pointer, and self-attestations for the steps that happen
 * inside GHL's own UI and cannot be observed directly (the inbound-webhook
 * workflow — until a real event arrives — and the voice-AI custom actions).
 * Marking the salon live requires every step to be done.
 */
class SalonOnboarding
{
    public const STATUS_DONE = 'done';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_NOT_STARTED = 'not_started';

    /** The steps that complete by "I've done this in GHL" attestation. */
    public const ATTESTABLE = ['webhook', 'voice_actions'];

    /**
     * Ordered step definitions: key => [title, whether the work happens in
     * the app or in GHL's UI]. Rendering copy lives in the wizard view.
     *
     * @return array<string, array{title: string, where: string}>
     */
    public static function steps(): array
    {
        return [
            'basics' => ['title' => __('Salon basics'), 'where' => 'app'],
            'staff' => ['title' => __('Staff and stylists'), 'where' => 'app'],
            'services' => ['title' => __('Services'), 'where' => 'app'],
            'availability' => ['title' => __('Availability'), 'where' => 'app'],
            'ghl_connect' => ['title' => __('Connect GoHighLevel'), 'where' => 'ghl'],
            'ghl_mapping' => ['title' => __('Calendar and stylist mapping'), 'where' => 'ghl'],
            'webhook' => ['title' => __('Inbound webhook'), 'where' => 'ghl'],
            'api_token' => ['title' => __('Booking API token'), 'where' => 'app'],
            'voice_actions' => ['title' => __('Voice AI custom actions'), 'where' => 'ghl'],
            'availability_sync' => ['title' => __('Sync availability to GoHighLevel'), 'where' => 'app'],
        ];
    }

    /**
     * Every step with its computed status, in wizard order.
     *
     * @return array<string, string> step key => status
     */
    public function statuses(Salon $salon): array
    {
        $out = [];
        foreach (array_keys(self::steps()) as $key) {
            $out[$key] = $this->status($salon, $key);
        }

        return $out;
    }

    public function status(Salon $salon, string $step): string
    {
        return match ($step) {
            'basics' => $this->basicsStatus($salon),
            'staff' => $this->staffStatus($salon),
            'services' => $this->servicesStatus($salon),
            'availability' => $this->availabilityStatus($salon),
            'ghl_connect' => $this->connectStatus($salon),
            'ghl_mapping' => $this->mappingStatus($salon),
            'webhook' => $this->webhookStatus($salon),
            'api_token' => $salon->api_token_hash !== null ? self::STATUS_DONE : self::STATUS_NOT_STARTED,
            'voice_actions' => $this->voiceActionsStatus($salon),
            'availability_sync' => $this->availabilitySyncStatus($salon),
            default => self::STATUS_NOT_STARTED,
        };
    }

    public function allDone(Salon $salon): bool
    {
        return ! in_array(false, array_map(
            fn (string $status): bool => $status === self::STATUS_DONE,
            $this->statuses($salon),
        ), true);
    }

    /**
     * Mark the salon live. Refuses (returns false) unless every step is done
     * — the button should be disabled anyway; this is the authoritative gate.
     */
    public function markLive(Salon $salon): bool
    {
        if ($salon->onboarded_at !== null) {
            return true;
        }

        if (! $this->allDone($salon)) {
            return false;
        }

        $salon->forceFill(['onboarded_at' => now()])->save();

        return true;
    }

    // -- Persisted state (step pointer + GHL self-attestations) ---------------

    /** The step to open on load: the persisted pointer, else the first not-done step. */
    public function currentStep(Salon $salon): string
    {
        $stored = $salon->onboarding['step'] ?? null;

        if (is_string($stored) && array_key_exists($stored, self::steps())) {
            return $stored;
        }

        foreach ($this->statuses($salon) as $key => $status) {
            if ($status !== self::STATUS_DONE) {
                return $key;
            }
        }

        return array_key_last(self::steps());
    }

    public function rememberStep(Salon $salon, string $step): void
    {
        if (! array_key_exists($step, self::steps())) {
            return;
        }

        $salon->forceFill(['onboarding' => array_merge($salon->onboarding ?? [], ['step' => $step])])->save();
    }

    /** "I've done this in GHL" — only for the steps the app cannot observe. */
    public function attest(Salon $salon, string $step, bool $done = true): void
    {
        if (! in_array($step, self::ATTESTABLE, true)) {
            return;
        }

        $state = $salon->onboarding ?? [];
        $attested = $state['attested'] ?? [];

        if ($done) {
            $attested[$step] = now()->toIso8601String();
        } else {
            unset($attested[$step]);
        }

        $state['attested'] = $attested;
        $salon->forceFill(['onboarding' => $state])->save();
    }

    public function isAttested(Salon $salon, string $step): bool
    {
        return isset($salon->onboarding['attested'][$step]);
    }

    // -- Per-step verification --------------------------------------------------

    private function basicsStatus(Salon $salon): string
    {
        return (trim($salon->name) !== '' && trim($salon->slug) !== ''
            && trim($salon->timezone) !== '' && trim($salon->currency) !== '')
            ? self::STATUS_DONE
            : self::STATUS_IN_PROGRESS;
    }

    private function staffStatus(Salon $salon): string
    {
        if ($salon->stylistUsers()->exists()) {
            return self::STATUS_DONE;
        }

        return $salon->memberships()->where('active', true)->exists()
            ? self::STATUS_IN_PROGRESS
            : self::STATUS_NOT_STARTED;
    }

    private function servicesStatus(Salon $salon): string
    {
        $active = $salon->services()->where('active', true);

        if ((clone $active)->whereHas('stylists')->exists()) {
            return self::STATUS_DONE;
        }

        return $active->exists() ? self::STATUS_IN_PROGRESS : self::STATUS_NOT_STARTED;
    }

    /** Done when EVERY booking stylist has at least one weekly work window. */
    private function availabilityStatus(Salon $salon): string
    {
        $stylistIds = $salon->stylistUsers()->pluck('users.id');

        if ($stylistIds->isEmpty()) {
            return self::STATUS_NOT_STARTED;
        }

        $withHours = Availability::forSalon($salon)
            ->where('kind', AvailabilityKind::Work->value)
            ->whereIn('user_id', $stylistIds)
            ->distinct()
            ->pluck('user_id');

        if ($withHours->count() >= $stylistIds->count()) {
            return self::STATUS_DONE;
        }

        return $withHours->isEmpty() ? self::STATUS_NOT_STARTED : self::STATUS_IN_PROGRESS;
    }

    /** Done when location + token are stored AND the connection test passed. */
    private function connectStatus(Salon $salon): string
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null) {
            return self::STATUS_NOT_STARTED;
        }

        if (filled($connection->location_id) && $connection->hasToken() && $connection->last_verified_at !== null) {
            return self::STATUS_DONE;
        }

        return (filled($connection->location_id) || $connection->hasToken())
            ? self::STATUS_IN_PROGRESS
            : self::STATUS_NOT_STARTED;
    }

    /** Done when a master calendar is chosen AND every booking stylist is mapped. */
    private function mappingStatus(Salon $salon): string
    {
        $connection = $salon->ghlConnection()->first();
        $hasCalendar = filled($connection?->calendar_id);

        $unmapped = $this->unmappedStylists($salon);
        $anyMapped = StylistProfile::forSalon($salon)->whereNotNull('ghl_user_id')->exists();

        if ($hasCalendar && $unmapped === [] && $salon->stylistUsers()->exists()) {
            return self::STATUS_DONE;
        }

        return ($hasCalendar || $anyMapped) ? self::STATUS_IN_PROGRESS : self::STATUS_NOT_STARTED;
    }

    /**
     * Names of booking stylists without a GHL provider mapping — the mapping
     * step's verify detail.
     *
     * @return list<string>
     */
    public function unmappedStylists(Salon $salon): array
    {
        $mapped = StylistProfile::forSalon($salon)
            ->whereNotNull('ghl_user_id')
            ->pluck('user_id')
            ->all();

        return array_values($salon->stylistUsers()
            ->whereNotIn('users.id', $mapped)
            ->orderBy('name')
            ->pluck('users.name')
            ->map(fn ($name): string => (string) $name)
            ->all());
    }

    /**
     * Done when a secret exists AND either a real inbound event has arrived
     * (observed proof) or the person attested the GHL workflow is in place.
     */
    private function webhookStatus(Salon $salon): string
    {
        $connection = $salon->ghlConnection()->first();

        if (! filled($connection?->webhook_secret)) {
            return self::STATUS_NOT_STARTED;
        }

        if ($this->webhookEventReceived($salon) || $this->isAttested($salon, 'webhook')) {
            return self::STATUS_DONE;
        }

        return self::STATUS_IN_PROGRESS;
    }

    /** Whether any inbound GHL event has ever been recorded for this salon. */
    public function webhookEventReceived(Salon $salon): bool
    {
        return WebhookEvent::query()->where('salon_id', $salon->id)->exists();
    }

    private function voiceActionsStatus(Salon $salon): string
    {
        if ($this->isAttested($salon, 'voice_actions')) {
            return self::STATUS_DONE;
        }

        // The token existing means they've started this stage.
        return $salon->api_token_hash !== null ? self::STATUS_IN_PROGRESS : self::STATUS_NOT_STARTED;
    }

    /** Done when at least one stylist is mapped and every mapped one is synced. */
    private function availabilitySyncStatus(Salon $salon): string
    {
        $states = StylistProfile::forSalon($salon)
            ->whereNotNull('ghl_user_id')
            ->pluck('ghl_availability_status');

        if ($states->isEmpty()) {
            return self::STATUS_NOT_STARTED;
        }

        if ($states->every(fn (?string $s): bool => $s === GhlAvailabilityPusher::STATUS_SYNCED)) {
            return self::STATUS_DONE;
        }

        return $states->contains(fn (?string $s): bool => $s !== null)
            ? self::STATUS_IN_PROGRESS
            : self::STATUS_NOT_STARTED;
    }
}
