<?php

namespace App\Http\Controllers;

use App\Actions\Demo\DeleteDemoSalon;
use App\Actions\Demo\ProvisionDemoSalon;
use App\Enums\SalonRole;
use App\Models\Salon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public, no-signup demo. First visit provisions a fresh isolated salon
 * for THIS visitor (session-bound) and signs them in as its owner — no
 * credentials shown, nothing shared between visitors. A refresh keeps their
 * session (they don't lose their place mid-tour); the banner's Reset demo
 * reprovisions instantly, and the hourly sweeper removes expired demos.
 *
 * URL scheme: entry is app.{domain}/demo; the tour itself runs on the static
 * demo.{domain} host, where ResolveSalon picks the visitor's salon from the
 * session — never from a hostname. NO per-visitor hostname exists anywhere
 * (this hosting cannot mint subdomains at runtime — Salon::getRouteKey()).
 *
 * Public endpoint = blast radius capped: provisioning is rate-limited per
 * IP and globally ceilinged (a bot spamming /demo cannot fill the disk).
 */
class DemoController extends Controller
{
    /** The retired apex entry URL (bookmarks, backlinks) → the real entry. */
    public function redirectToEntry(): RedirectResponse
    {
        return redirect()->route('demo.enter');
    }

    public function enter(Request $request, ProvisionDemoSalon $provision): RedirectResponse
    {
        // Session already owns a live demo? Walk back in (refresh-friendly).
        $existing = $this->sessionSalon($request);
        if ($existing !== null) {
            $this->signInto($request, $existing);

            return redirect()->route('salon.show', $existing);
        }

        return $this->provisionAndEnter($request, $provision, 'demo-provision:');
    }

    /**
     * Reset demo (from the in-demo banner): discard THIS session's salon and
     * start a fresh one. Its own (slightly looser) limiter — resetting is a
     * legitimate tour action; provisioning floods are not.
     */
    public function reset(Request $request, ProvisionDemoSalon $provision, DeleteDemoSalon $delete): RedirectResponse
    {
        // No Salon type-hint: on the demo host the {salon} domain param is the
        // literal "demo" (implicit binding would 404 it). resolve.salon already
        // ran and bound THIS session's salon; the checks below stay as belt
        // and braces — on a real salon's subdomain currentSalon is that real
        // salon and the is_demo assert refuses it.
        $salon = app('currentSalon');
        abort_unless($salon instanceof Salon && $salon->is_demo, 403);
        abort_unless($request->session()->get('demo_salon_id') === $salon->id, 403);

        Auth::logout();
        $delete->handle($salon);
        $request->session()->forget('demo_salon_id');

        return $this->provisionAndEnter($request, $provision, 'demo-reset:');
    }

    private function provisionAndEnter(Request $request, ProvisionDemoSalon $provision, string $limiterPrefix): RedirectResponse
    {
        $limits = ['demo-provision:' => 3, 'demo-reset:' => 6];

        if (! RateLimiter::attempt($limiterPrefix.$request->ip(), $limits[$limiterPrefix], fn () => true, 3600)) {
            abort(429, __('The demo is busy right now — please try again in a little while.'));
        }

        if (ProvisionDemoSalon::activeCount() >= ProvisionDemoSalon::MAX_ACTIVE) {
            abort(503, __('The demo is at capacity right now — please try again shortly.'));
        }

        $result = $provision->handle();

        $request->session()->put('demo_salon_id', $result['salon']->id);
        Auth::login($result['owner']);
        $request->session()->regenerate();
        // regenerate() keeps session data; re-assert the binding explicitly.
        $request->session()->put('demo_salon_id', $result['salon']->id);

        return redirect()->route('salon.show', $result['salon']);
    }

    private function sessionSalon(Request $request): ?Salon
    {
        $id = $request->session()->get('demo_salon_id');

        if ($id === null) {
            return null;
        }

        return Salon::query()
            ->whereKey($id)
            ->where('is_demo', true)
            ->where('active', true)
            ->where('demo_expires_at', '>', now())
            ->first();
    }

    private function signInto(Request $request, Salon $salon): void
    {
        if (Auth::check() && Auth::user()->membershipFor($salon) !== null) {
            return;
        }

        $owner = $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->with('user')
            ->firstOrFail()
            ->user;

        Auth::login($owner);
        $request->session()->regenerate();
        $request->session()->put('demo_salon_id', $salon->id);
    }
}
