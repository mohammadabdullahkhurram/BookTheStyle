<?php

namespace App\Http\Controllers;

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Support\AccentPalette;
use App\Support\Money;
use App\Support\WidgetBranding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The embeddable public booking widget (SPEC: client-facing booking surface).
 *
 * Four public surfaces, all tenant-scoped by the salon SLUG (subdomain —
 * a public identifier, never a secret): the iframe-able widget page, and
 * three JSON endpoints (services / availability / book) the page calls
 * same-origin, so no CORS surface exists at all. Booking goes through the
 * SAME shared engine as the voice AI (VoiceBookingApi → SlotEngine /
 * CreateBooking / client upsert / GHL push) with source=web_widget.
 *
 * Public-safety: only bookable data is ever returned (services, stylists'
 * names, open slots — never clients, notes or reports); every endpoint is
 * rate-limited per IP + salon; the book submit carries a honeypot field and
 * an encrypted page token that must be plausibly human-aged (not instant,
 * not stale). The widget page alone is framable by any site — see
 * SecurityHeaders.
 */
class WidgetController extends Controller
{
    public function __construct(private VoiceBookingApi $api) {}

    /**
     * The self-contained booking page the embed iframe loads. A widget
     * public id in the path picks ONE of the salon's widgets (each has its
     * own branding + theme); without one, the salon's default widget renders
     * — so pre-multi-widget embeds keep working unchanged.
     */
    public function page(Request $request, string $salon, ?string $widget = null): Response
    {
        $salon = $this->salon($salon);

        $widgetModel = $widget !== null
            ? $salon->widgets()->where('public_id', $widget)->firstOrFail()
            : $salon->defaultWidget();

        return response()->view('widget.page', [
            'salon' => $salon,
            'widget' => $widgetModel,
            // Full widget branding (accent set, secondary, surface, font,
            // logo) — the WIDGET's own values over the salon defaults, with
            // the validated ?accent= override still honoured.
            'branding' => WidgetBranding::for($salon, $this->accentOverride($request), $widgetModel),
            'catalogue' => $this->catalogue($salon),
            'currency' => $salon->currency,
            'preselectService' => ctype_digit((string) $request->query('service')) ? (int) $request->query('service') : null,
            'widgetToken' => $this->issueToken($salon),
            'minDate' => now($salon->timezone)->format('Y-m-d'),
            'maxDate' => now($salon->timezone)
                ->addDays(min((int) config('booking_api.widget_days_ahead'), $salon->max_advance_days))
                ->format('Y-m-d'),
        ]);
    }

    /** The tiny dependency-free loader external sites include as widget.js. */
    public function script(): Response
    {
        return response()
            ->view('widget.loader')
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /** Public catalogue: bookable services (+ their stylists) only. */
    public function services(string $salon): JsonResponse
    {
        $salon = $this->salon($salon);

        return response()->json([
            'salon' => ['name' => $salon->name, 'timezone' => $salon->timezone],
            'services' => $this->catalogue($salon),
        ]);
    }

    /**
     * The public service catalogue: active services that at least one active
     * stylist performs — name, base duration, display price, and the
     * qualified stylists. Nothing else is ever public.
     *
     * @return list<array{id: int, name: string, duration_minutes: int, price: string|null, price_cents: int|null, stylists: non-empty-array<int, array{id: int, name: string}>}>
     */
    private function catalogue(Salon $salon): array
    {
        $stylistIds = $salon->stylistUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        return array_values($salon->services()
            ->where('active', true)
            ->orderBy('name')
            ->with('stylists:id,name')
            ->get()
            ->map(function (Service $service) use ($stylistIds, $salon): ?array {
                $stylists = $service->stylists
                    ->filter(fn (User $u): bool => in_array((int) $u->id, $stylistIds, true))
                    ->sortBy(fn (User $u) => mb_strtolower($u->name))
                    ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => $u->name])
                    ->values()
                    ->all();

                if ($stylists === []) {
                    return null; // unbookable — not shown to the public
                }

                return [
                    'id' => (int) $service->id,
                    'name' => $service->name,
                    'duration_minutes' => (int) $service->duration_min,
                    'price' => Money::format($service->price_cents, $salon->currency),
                    // Raw cents so the widget can sum a running visit total
                    // client-side (display prices are already public).
                    'price_cents' => $service->price_cents !== null ? (int) $service->price_cents : null,
                    'stylists' => $stylists,
                ];
            })
            ->filter()
            ->all());
    }

    /**
     * Open slots for a visit (one OR MORE services) on a date — straight from
     * the shared engine, always for the FULL visit span. Accepts the legacy
     * single `service` param or the multi-select `services[]`.
     */
    public function availability(Request $request, string $salon): JsonResponse
    {
        $salon = $this->salon($salon);

        try {
            $input = $request->validate([
                'service' => ['required_without:services', 'integer'],
                'services' => ['required_without:service', 'array', 'min:1', 'max:6'],
                'services.*' => ['integer'],
                'stylist' => ['nullable', 'string', 'max:40'],
                'stylists' => ['nullable', 'array', 'max:6'],
                'stylists.*' => ['nullable', 'string', 'max:60'],
                'date' => ['required', 'string', 'max:40'],
            ]);

            [$serviceIds, $assigned] = $this->visitSelection($input);

            return response()->json($this->api->visitAvailability($salon, [
                'services' => $serviceIds,
                'stylist' => $input['stylist'] ?? null,
                'stylists' => $assigned,
                'date' => $input['date'],
            ]));
        } catch (ApiError $e) {
            return $this->refused($salon, 'availability', $e);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    /**
     * Which dates of a calendar month can host the WHOLE visit — feeds the
     * widget's inline calendar. The window is clamped to [today, booking
     * horizon] in the salon's timezone, so past days and days beyond the
     * salon's advance limit are never offered; one engine sweep serves the
     * whole month (no per-day round-trips from the browser).
     */
    public function month(Request $request, string $salon): JsonResponse
    {
        $salon = $this->salon($salon);

        try {
            $input = $request->validate([
                'service' => ['required_without:services', 'integer'],
                'services' => ['required_without:service', 'array', 'min:1', 'max:6'],
                'services.*' => ['integer'],
                'stylist' => ['nullable', 'string', 'max:40'],
                'stylists' => ['nullable', 'array', 'max:6'],
                'stylists.*' => ['nullable', 'string', 'max:60'],
                'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            ]);

            $today = CarbonImmutable::now($salon->timezone)->startOfDay();
            $horizon = $today->addDays(min((int) config('booking_api.widget_days_ahead'), $salon->max_advance_days));
            $monthStart = CarbonImmutable::createFromFormat('!Y-m', $input['month'], $salon->timezone);

            $from = $monthStart->max($today);
            $to = $monthStart->endOfMonth()->startOfDay()->min($horizon);

            if ($from->gt($to)) {
                return response()->json(['success' => true, 'month' => $input['month'], 'dates' => [], 'timezone' => $salon->timezone]);
            }

            [$serviceIds, $assigned] = $this->visitSelection($input);

            $result = $this->api->visitAvailableDates($salon, [
                'services' => $serviceIds,
                'stylist' => $input['stylist'] ?? null,
                'stylists' => $assigned,
            ], $from, $to);

            return response()->json(['success' => true, 'month' => $input['month']] + $result);
        } catch (ApiError $e) {
            return $this->refused($salon, 'month', $e);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    /** Create the booking through the shared engine (source: web_widget). */
    public function book(Request $request, string $salon): JsonResponse
    {
        $salon = $this->salon($salon);

        try {
            $input = $request->validate([
                // Three visit shapes: the per-service loop (`items`, each with
                // its own stylist + start), the back-to-back multi-select
                // (`services[]` + one date/time), or the legacy single
                // `service`.
                'items' => ['required_without_all:service,services', 'array', 'min:1', 'max:6'],
                'items.*.service' => ['required_with:items', 'integer'],
                'items.*.stylist' => ['nullable', 'string', 'max:60'],
                'items.*.date' => ['required_with:items', 'string', 'max:40'],
                'items.*.time' => ['required_with:items', 'string', 'max:20'],
                'service' => ['required_without_all:services,items', 'integer'],
                'services' => ['required_without_all:service,items', 'array', 'min:1', 'max:6'],
                'services.*' => ['integer'],
                'stylist' => ['nullable', 'string', 'max:40'],
                'stylists' => ['nullable', 'array', 'max:6'],
                'stylists.*' => ['nullable', 'string', 'max:60'],
                'date' => ['required_without:items', 'string', 'max:40'],
                'time' => ['required_without:items', 'string', 'max:20'],
                'client' => ['required', 'array'],
                'client.name' => ['required', 'string', 'max:255'],
                'client.phone' => ['required', 'string', 'max:50'],
                'client.email' => ['nullable', 'email', 'max:255'],
                'notes' => ['nullable', 'string', 'max:500'],
                'token' => ['required', 'string', 'max:2048'],
                'website' => ['nullable', 'string', 'max:255'], // honeypot
            ]);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        if ($reason = $this->botCheck($salon, $input)) {
            Log::info('Widget booking refused by bot gate', [
                'category' => 'widget',
                'salon_id' => $salon->id,
                'reason' => $reason,
            ]);

            // Deliberately unspecific to the caller.
            return response()->json([
                'success' => false,
                'error' => 'rejected',
                'message' => __('The booking could not be submitted. Reload the page and try again.'),
            ], 422);
        }

        try {
            if (array_key_exists('items', $input) && is_array($input['items'])) {
                $result = $this->api->createItinerary(
                    $salon,
                    [
                        'items' => array_values(array_map(fn (array $item): array => [
                            'service' => (int) $item['service'],
                            'stylist' => $item['stylist'] ?? null,
                            'date' => $item['date'],
                            'time' => $item['time'],
                        ], $input['items'])),
                        'client' => $input['client'],
                        'notes' => $input['notes'] ?? null,
                    ],
                    BookingSource::WebWidget,
                    BookedByType::WebWidget,
                );

                return response()->json($result, $result['success'] ? 201 : 409);
            }

            [$serviceIds, $assigned] = $this->visitSelection($input);

            $result = $this->api->createVisit(
                $salon,
                [
                    'services' => $serviceIds,
                    'stylist' => $input['stylist'] ?? null,
                    'stylists' => $assigned,
                    'date' => $input['date'],
                    'time' => $input['time'],
                    'client' => $input['client'],
                    'notes' => $input['notes'] ?? null,
                ],
                BookingSource::WebWidget,
                BookedByType::WebWidget,
            );

            return response()->json($result, $result['success'] ? 201 : 409);
        } catch (ApiError $e) {
            return $this->refused($salon, 'book', $e);
        }
    }

    /**
     * The requested visit: `services[]` (multi-select) or the legacy single
     * `service`, plus the optional per-service `stylists[]` assignment
     * (manual mode) kept ALIGNED through de-duplication — dropping a repeated
     * service drops its stylist entry too, so the arrays never desync.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: list<int>, 1: list<int|string|null>|null}
     */
    private function visitSelection(array $input): array
    {
        $ids = array_key_exists('services', $input) && is_array($input['services'])
            ? array_values($input['services'])
            : [$input['service']];

        $assigned = array_key_exists('stylists', $input) && is_array($input['stylists'])
            ? array_values($input['stylists'])
            : null;

        $services = [];
        $stylists = [];
        foreach ($ids as $i => $id) {
            $id = (int) $id;
            if (in_array($id, $services, true)) {
                continue;
            }
            $services[] = $id;
            $stylists[] = $assigned[$i] ?? null;
        }

        return [$services, $assigned === null ? null : $stylists];
    }

    // -- Bot gate -----------------------------------------------------------

    /**
     * The widget page embeds an encrypted, salon-bound, timestamped token.
     * A submission fails the gate when the honeypot is filled, the token is
     * missing/garbled/foreign, or its age is implausible for a human (too
     * fresh) or stale (too old / replayed much later). Returns the refusal
     * reason for the log, or null when the submission passes.
     *
     * @param  array<string, mixed>  $input
     */
    private function botCheck(Salon $salon, array $input): ?string
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return 'honeypot';
        }

        try {
            $payload = json_decode(Crypt::decryptString((string) $input['token']), true);
        } catch (DecryptException) {
            return 'bad_token';
        }

        $iat = is_array($payload) ? ($payload['iat'] ?? null) : null;

        if (! is_array($payload) || ($payload['salon'] ?? null) !== $salon->id || ! is_int($iat)) {
            return 'foreign_token';
        }

        $age = now()->getTimestamp() - $iat;

        if ($age < (int) config('booking_api.widget_min_seconds')) {
            return 'too_fast';
        }

        if ($age > (int) config('booking_api.widget_token_ttl_hours') * 3600) {
            return 'stale_token';
        }

        return null;
    }

    private function issueToken(Salon $salon): string
    {
        return Crypt::encryptString((string) json_encode([
            'salon' => $salon->id,
            'iat' => now()->timestamp,
        ]));
    }

    // -- Helpers --------------------------------------------------------------

    /** Resolve the ACTIVE salon from the subdomain slug; 404 otherwise. */
    private function salon(string $slug): Salon
    {
        return Salon::query()->where('slug', $slug)->where('active', true)->firstOrFail();
    }

    /** A validated ?accent= override (hex or preset name), else null. */
    private function accentOverride(Request $request): ?string
    {
        $accent = trim((string) $request->query('accent', ''));

        if ($accent === '') {
            return null;
        }

        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $accent) === 1) {
            return str_starts_with($accent, '#') ? $accent : '#'.$accent;
        }

        return array_key_exists($accent, AccentPalette::PRESETS) ? $accent : null;
    }

    private function refused(Salon $salon, string $endpoint, ApiError $e): JsonResponse
    {
        Log::info('Widget API request refused', [
            'category' => 'widget',
            'endpoint' => $endpoint,
            'salon_id' => $salon->id,
            'error' => $e->errorCode,
        ]);

        return response()->json($e->toResponse(), $e->status);
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'invalid_request',
            'message' => collect($e->errors())->flatten()->first() ?? __('The request was invalid.'),
            'fields' => array_keys($e->errors()),
        ], 422);
    }
}
