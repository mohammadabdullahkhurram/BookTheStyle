<?php

namespace App\Http\Controllers;

use App\Models\Availability;
use App\Models\Salon;
use App\Support\WidgetBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The conversational booking chat widget — a bottom-corner bubble a salon
 * embeds on its own website (script-only snippet, like GHL/Intercom).
 * Fully native and LLM-free: a GUIDED scripted flow (messages, quick-reply
 * chips, typed inputs) that walks a visitor through the same booking steps
 * as the classic widget.
 *
 * Deliberately thin: the chat page calls the EXISTING widget JSON endpoints
 * (services / availability / month / book on the salon's own subdomain —
 * same origin from inside the iframe, so no CORS surface exists) and books
 * through the same shared engine with source=chat_widget. Rate limiting
 * (throttle:widget-api), the honeypot + timestamped-token bot gate, tenant
 * scoping by slug, and the public-data-only catalogue are all inherited
 * from the parent controller — no parallel booking path, no second set of
 * rules to drift.
 */
class ChatWidgetController extends WidgetController
{
    /** The chat panel page the embed bubble's iframe loads (slug host). */
    public function page(Request $request, string $salon, ?string $widget = null): Response
    {
        return $this->renderChat($request, $this->salon($salon), $widget, preview: false);
    }

    /** In-app preview twin — tenant-scoped, works for demo salons too. */
    public function previewPage(Request $request, Salon $salon, ?string $widget = null): Response
    {
        return $this->renderChat($request, $salon, $widget, preview: true);
    }

    private function renderChat(Request $request, Salon $salon, ?string $widgetId, bool $preview): Response
    {
        $widgetModel = $widgetId !== null
            ? $salon->widgets()->where('public_id', $widgetId)->firstOrFail()
            : null;

        return response()->view('widget.chat', [
            'salon' => $salon,
            'widget' => $widgetModel,
            'branding' => WidgetBranding::for($salon, $this->accentOverride($request), $widgetModel),
            'catalogue' => $this->catalogue($salon, includeTest: $preview),
            'currency' => $salon->currency,
            'widgetToken' => $this->issueToken($salon),
            'info' => $this->salonInfo($salon),
            'endpoints' => $preview ? [
                'availability' => route('salon.widget.preview.availability', $salon),
                'month' => route('salon.widget.preview.month', $salon),
                'book' => route('salon.widget.preview.book', $salon),
            ] : null,
        ]);
    }

    /** The bubble loader external sites include as /chat.js (app host). */
    public function chatScript(): Response
    {
        return response()
            ->view('widget.chat-loader')
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * The light "about us" facts the info quick-replies answer from — all
     * already public on the salon's own website/booking page: opening hours
     * (the union of active stylists' working windows per weekday), the
     * address, and the public phone. Never anything internal.
     *
     * @return array{hours: list<array{day: string, open: string|null}>, address: string|null, phone: string|null}
     */
    private function salonInfo(Salon $salon): array
    {
        $stylistIds = $salon->stylistUsers()->where('users.is_test', false)->pluck('users.id');

        $byDay = Availability::query()
            ->where('salon_id', $salon->id)
            ->where('kind', 'work')
            ->whereIn('user_id', $stylistIds)
            ->get(['weekday', 'start_minute', 'end_minute'])
            ->groupBy('weekday');

        $hours = [];
        foreach ([__('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday'), __('Sunday')] as $weekday => $label) {
            $rows = $byDay->get($weekday);
            $hours[] = [
                'day' => $label,
                'open' => $rows === null ? null : self::clock((int) $rows->min('start_minute')).' - '.self::clock((int) $rows->max('end_minute')),
            ];
        }

        $address = collect([
            $salon->address_line1,
            $salon->address_line2,
            trim(implode(' ', array_filter([$salon->city, $salon->postal_code]))),
        ])->filter(fn ($part) => trim((string) $part) !== '')->implode(', ');

        return [
            'hours' => $hours,
            'address' => $address !== '' ? $address : null,
            'phone' => $salon->business_phone ?: null,
        ];
    }

    /** Minutes-since-midnight to a 12-hour clock label (9:00 AM). */
    private static function clock(int $minutes): string
    {
        $hour24 = intdiv($minutes, 60);
        $hour = $hour24 % 12 === 0 ? 12 : $hour24 % 12;

        return sprintf('%d:%02d %s', $hour, $minutes % 60, $hour24 >= 12 ? 'PM' : 'AM');
    }
}
