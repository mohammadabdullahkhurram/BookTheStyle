<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * The public demo entry. The demo is a LOGGED-OUT guest preview of the one
 * canonical showcase salon on the static demo.{domain} host: entering never
 * authenticates anyone, provisions nothing, and touches no session — it is
 * a plain redirect onto the demo host, landing on the calendar (the most
 * alive screen). ResolveSalon supplies the showcase context there.
 *
 * URL scheme: entry stays at app.{domain}/demo (a static, cert-valid
 * hostname the marketing site links to); the tour runs on demo.{domain}.
 * NO per-visitor hostname exists anywhere (this hosting cannot mint
 * subdomains at runtime — docs/DEPLOY.md).
 */
class DemoController extends Controller
{
    /** The retired apex entry URL (bookmarks, backlinks) → the real entry. */
    public function redirectToEntry(): RedirectResponse
    {
        return redirect()->route('demo.enter');
    }

    public function enter(): RedirectResponse
    {
        // Land on the calendar of the showcase. The literal "demo" domain
        // param is the static demo host — never a minted hostname; if the
        // showcase is not seeded yet, the demo host answers 404 there
        // (run demo:seed-showcase).
        return redirect()->route('salon.calendar', ['salon' => 'demo']);
    }
}
