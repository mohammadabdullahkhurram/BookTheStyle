<?php

use App\Models\Salon;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * TEMPORARY widget-design gallery. Five complete visual takes on the SAME
 * embeddable booking flow (service -> stylist -> date/time -> details ->
 * confirmation), rendered as self-contained mockup step cards so the owner
 * can judge each design end to end. No real data or logic — sample content
 * only, fully isolated from the live widget. Once a design is chosen it
 * gets applied to the real widget and this page + its nav tab are deleted.
 */
new #[Title('Widget designs')] class extends Component {
    public Salon $salon;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;
    }
}; ?>

@php
    // Shared sample booking data — identical across designs.
    $services = [
        ['name' => 'Cut & finish', 'meta' => '45 min · £42'],
        ['name' => 'Full colour', 'meta' => '2 h · £95'],
        ['name' => 'Blow dry', 'meta' => '30 min · £28'],
    ];
    $stylists = ['Any stylist', 'Maya', 'Simone', 'Jonah'];
    $days = [['Sat', '12'], ['Sun', '13'], ['Mon', '14'], ['Tue', '15'], ['Wed', '16']];
    $times = ['9:00', '10:30', '11:15', '1:00', '2:30', '4:15'];
@endphp

<div class="wg-root">
    <style>
        /* ————— Gallery shell (neutral, matches the UI/UX gallery) ————— */
        .wg-root { background: #14110e; color: #efeae4; }
        .wg-band { padding: 64px 24px; }
        .wg-inner { max-width: 1180px; margin: 0 auto; }
        .wg-label { font-size: 11px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; opacity: .55; margin: 22px 0 12px; }
        .wg-row { display: flex; gap: 18px; overflow-x: auto; padding-bottom: 14px; align-items: flex-start; }
        .wg-step { flex: 0 0 300px; width: 300px; }
        .wg-stepname { font-size: 11px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; opacity: .5; margin-bottom: 8px; }

        /* ═══ A · FROST — light glass premium ═══ */
        .w-frost { background:
            radial-gradient(40rem 26rem at 10% -10%, rgb(130 76 113 / .22), transparent 58%),
            radial-gradient(36rem 24rem at 90% 0%, rgb(91 146 189 / .2), transparent 58%),
            #f1efec; color: #262126; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .fro-card { background: rgb(255 255 255 / .55); -webkit-backdrop-filter: blur(20px) saturate(1.5); backdrop-filter: blur(20px) saturate(1.5);
            border: 1px solid rgb(255 255 255 / .7); border-radius: 20px; padding: 18px;
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .9), 0 10px 30px rgb(63 47 70 / .14); }
        .fro-t { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; font-size: 16px; letter-spacing: -.01em; }
        .fro-dots { display: flex; gap: 5px; margin-top: 6px; }
        .fro-dots i { width: 18px; height: 4px; border-radius: 99px; background: rgb(38 33 38 / .12); }
        .fro-dots .on { background: #824c71; }
        .fro-opt { display: flex; justify-content: space-between; align-items: center; padding: 11px 13px; border-radius: 13px;
            background: rgb(255 255 255 / .5); border: 1px solid rgb(255 255 255 / .7); margin-top: 8px; font-size: 13.5px; }
        .fro-opt.sel { border: 1.5px solid #824c71; background: rgb(130 76 113 / .1); }
        .fro-chip { padding: 8px 0; text-align: center; border-radius: 10px; background: rgb(255 255 255 / .55); border: 1px solid rgb(255 255 255 / .7); font-size: 12.5px; font-weight: 600; }
        .fro-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .fro-field { background: rgb(255 255 255 / .7); border: 1px solid rgb(63 47 38 / .12); border-radius: 11px; padding: 9px 12px; font-size: 13px; color: #57504f; margin-top: 8px; }
        .fro-cta { height: 42px; border-radius: 99px; background: #824c71; color: #fff; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; box-shadow: 0 6px 18px rgb(130 76 113 / .35); }
        .fro-check { width: 44px; height: 44px; border-radius: 50%; background: rgb(130 76 113 / .14); color: #6b3358;
            display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; margin: 4px auto 10px; }

        /* ═══ B · SWIFT — clean minimal ═══ */
        .w-swift { background: #f2f1ef; color: #1e1c1a; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .swi-card { background: #fff; border: 1px solid #e8e6e2; border-radius: 14px; padding: 18px; }
        .swi-t { font-weight: 700; font-size: 15.5px; letter-spacing: -.01em; }
        .swi-bar { height: 3px; background: #eeece8; border-radius: 99px; margin-top: 8px; overflow: hidden; }
        .swi-bar i { display: block; height: 100%; background: #824c71; border-radius: 99px; }
        .swi-opt { display: flex; justify-content: space-between; align-items: center; padding: 12px 2px; border-bottom: 1px solid #f0eeea; font-size: 13.5px; }
        .swi-opt.sel span:first-child { color: #824c71; font-weight: 600; }
        .swi-chip { padding: 8px 0; text-align: center; border-radius: 8px; border: 1px solid #e2dfd9; font-size: 12.5px; font-weight: 500; }
        .swi-chip.sel { background: #1e1c1a; color: #fff; border-color: #1e1c1a; }
        .swi-field { border: 0; border-bottom: 1.5px solid #d8d5cf; padding: 9px 2px; font-size: 13.5px; color: #57534d; margin-top: 10px; }
        .swi-cta { height: 42px; border-radius: 9px; background: #824c71; color: #fff; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 16px; }
        .swi-check { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #824c71; color: #824c71;
            display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; margin: 4px auto 10px; }

        /* ═══ C · MAISON — warm boutique ═══ */
        .w-maison { background: #f6efe6; color: #43352c; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .mai-card { background: #fffdf8; border: 1px solid #eadfce; border-radius: 22px; padding: 19px; box-shadow: 0 2px 0 #eadfce; }
        .mai-t { font-family: 'Fraunces', serif; font-weight: 600; font-size: 17px; }
        .mai-over { font-size: 10.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #6b3358; }
        .mai-opt { display: flex; justify-content: space-between; align-items: center; padding: 11px 14px; border-radius: 15px;
            border: 1.5px solid #eadfce; margin-top: 8px; font-size: 13.5px; background: #fffdf8; }
        .mai-opt.sel { border-color: #824c71; background: #f6e9f1; }
        .mai-chip { padding: 8px 0; text-align: center; border-radius: 12px 12px 12px 3px; border: 1.5px solid #eadfce; font-size: 12.5px; font-weight: 600; background: #fffdf8; }
        .mai-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .mai-field { background: #fffdf8; border: 1.5px solid #e4d6c1; border-radius: 13px; padding: 9px 13px; font-size: 13px; color: #6b584a; margin-top: 8px; }
        .mai-cta { height: 44px; border-radius: 99px; background: #824c71; color: #fff; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; box-shadow: 0 3px 0 #5c2547; }
        .mai-check { width: 46px; height: 46px; border-radius: 50% 50% 50% 6px; background: #e9f0e2; color: #4c6b3c;
            display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; margin: 4px auto 10px; }

        /* ═══ D · PUNCH — bold modern ═══ */
        .w-punch { background: #efe9f0; color: #241a2b; font-family: 'Schibsted Grotesk', sans-serif; border-radius: 18px; }
        .pun-card { background: #fff; border-radius: 18px; box-shadow: 0 8px 24px rgb(50 30 65 / .14); }
        .pun-head { background: #5c2547; color: #fff; padding: 14px 16px 12px; }
        .pun-t { font-weight: 800; font-size: 16px; letter-spacing: -.02em; }
        .pun-seg { display: flex; gap: 4px; margin-top: 8px; }
        .pun-seg i { flex: 1; height: 5px; border-radius: 99px; background: rgb(255 255 255 / .25); }
        .pun-seg .on { background: #ff8a5c; }
        .pun-body { padding: 12px 16px 0; }
        .pun-card { padding: 16px; }
        .pun-dots { display: flex; gap: 4px; margin-top: 8px; }
        .pun-dots i { flex: 1; height: 5px; border-radius: 99px; background: #eee6f0; }
        .pun-dots .on { background: #ff8a5c; }
        .pun-opt { display: flex; justify-content: space-between; align-items: center; padding: 14px 15px; border-radius: 13px;
            background: #f4f1f6; margin-top: 8px; font-size: 14px; font-weight: 700; }
        .pun-opt.sel { background: #5c2547; color: #fff; }
        .pun-chip { padding: 11px 0; text-align: center; border-radius: 11px; background: #f4f1f6; font-size: 13px; font-weight: 800; }
        .pun-chip.sel { background: #ff8a5c; color: #3d1500; }
        .pun-field { background: #f4f1f6; border: 0; border-radius: 11px; padding: 12px 14px; font-size: 13.5px; font-weight: 600; color: #55465c; margin-top: 8px; }
        .pun-cta { height: 48px; border-radius: 13px; background: #ff8a5c; color: #3d1500; font-weight: 800; font-size: 15px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; }
        .pun-check { width: 48px; height: 48px; border-radius: 14px; background: #ff8a5c; color: #3d1500;
            display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; margin: 12px auto 10px; }

        /* ═══ E · FOLIO — elegant editorial ═══ */
        .w-folio { background: #f8f4ea; color: #262019; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .fol-card { background: #fdfaf2; border-top: 2px solid #262019; padding: 18px 16px; }
        .fol-t { font-family: 'Fraunces', serif; font-weight: 600; font-size: 17px; }
        .fol-num { font-size: 10.5px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: #8a7a60; }
        .fol-opt { display: flex; justify-content: space-between; align-items: baseline; padding: 11px 0; border-bottom: 1px solid #e6dbc4; font-size: 13.5px; }
        .fol-opt.sel span:first-child { font-weight: 700; border-bottom: 2px solid #824c71; padding-bottom: 2px; }
        .fol-chip { padding: 8px 0; text-align: center; border-bottom: 1px solid #e6dbc4; font-size: 12.5px; font-family: 'Fraunces', serif; }
        .fol-chip.sel { border-bottom: 2px solid #262019; font-weight: 700; }
        .fol-field { background: transparent; border: 0; border-bottom: 1px solid #b8a98e; padding: 9px 2px; font-size: 13.5px; color: #57504a; margin-top: 10px; }
        .fol-cta { height: 42px; background: #262019; color: #f8f4ea; font-weight: 600; font-size: 12px; letter-spacing: .14em; text-transform: uppercase;
            display: flex; align-items: center; justify-content: center; margin-top: 16px; }
        .fol-check { font-family: 'Fraunces', serif; font-size: 26px; text-align: center; margin: 2px 0 8px; }
    </style>

    {{-- ————— Intro ————— --}}
    <div class="wg-band" style="padding-bottom: 28px;">
        <div class="wg-inner">
            <div style="font-family:'Fraunces',serif; font-size:15px; letter-spacing:.14em; text-transform:uppercase; color:#d9a9c6;">Widget designs</div>
            <h1 style="font-family:'Fraunces',serif; font-size:32px; font-weight:600; margin-top:12px;">Five takes on the booking widget, full flow each.</h1>
            <p style="font-size:14px; opacity:.75; max-width:620px; line-height:1.6; margin-top:10px;">Every design shows the complete embed flow — service, stylist, date and time, details, confirmation — as five step cards. Each card is rendered at 300px, the width the widget occupies on a phone, so what you see IS the mobile experience; embedded on desktop the card simply centres in its container. Scroll a row horizontally to walk the flow.</p>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:20px;">
                @foreach ([['#frost', 'A · Frost'], ['#swift', 'B · Swift'], ['#maison', 'C · Maison'], ['#punch', 'D · Punch'], ['#folio', 'E · Folio']] as [$href, $label])
                    <a href="{{ $href }}" style="border:1px solid rgb(255 255 255 / .2); border-radius:99px; padding:7px 16px; font-size:13.5px; color:#efeae4;">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    <section id="frost" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">A · Frost — light glass premium</h2>
                <p style="font-size:14px; opacity:.7;">An Apple liquid-glass booking card: frosted panel over a soft gradient, plum jewel, gentle depth.</p>
            </div>
            <div class="w-frost" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="fro-card">
                        <div class="fro-t">Choose a service</div>
                        <div class="fro-dots"><i class=""></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($services as $i => $sv)
                            <div class="fro-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="fro-card">
                        <div class="fro-t">Choose a stylist</div>
                        <div class="fro-dots"><i class="on"></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($stylists as $i => $st)
                            <div class="fro-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="fro-card">
                        <div class="fro-t">Pick a time</div>
                        <div class="fro-dots"><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i><i class=""></i></div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="fro-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="fro-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="fro-card">
                        <div class="fro-t">Your details</div>
                        <div class="fro-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i></div>
                        <div class="fro-field">Amelia Hart</div>
                        <div class="fro-field">07700 900123</div>
                        <div class="fro-field" style="opacity:.6;">Email (optional)</div>
                        <div class="fro-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="fro-card">
                        <div class="fro-t">All set</div>
                        <div class="fro-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i></div>
                        <div class="fro-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
            
        </div>
    </section>

    <section id="swift" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">B · Swift — clean minimal</h2>
                <p style="font-size:14px; opacity:.7;">Effortless and fast: white, spacious, one plum accent, a thin progress bar. Calendly-clean but warmer.</p>
            </div>
            <div class="w-swift" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="swi-card">
                        <div class="swi-t">Choose a service</div>
                        <div class="swi-bar"><i style="width:20%;"></i></div>
                        @foreach ($services as $i => $sv)
                            <div class="swi-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="swi-card">
                        <div class="swi-t">Choose a stylist</div>
                        <div class="swi-bar"><i style="width:40%;"></i></div>
                        @foreach ($stylists as $i => $st)
                            <div class="swi-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="swi-card">
                        <div class="swi-t">Pick a time</div>
                        <div class="swi-bar"><i style="width:60%;"></i></div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="swi-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="swi-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="swi-card">
                        <div class="swi-t">Your details</div>
                        <div class="swi-bar"><i style="width:80%;"></i></div>
                        <div class="swi-field">Amelia Hart</div>
                        <div class="swi-field">07700 900123</div>
                        <div class="swi-field" style="opacity:.6;">Email (optional)</div>
                        <div class="swi-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="swi-card">
                        <div class="swi-t">All set</div>
                        <div class="swi-bar"><i style="width:100%;"></i></div>
                        <div class="swi-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
            
        </div>
    </section>

    <section id="maison" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">C · Maison — warm boutique</h2>
                <p style="font-size:14px; opacity:.7;">Salon-brand warmth: cream, serif headings, rounded organic corners, a pressable plum button. Inviting and premium.</p>
            </div>
            <div class="w-maison" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="mai-card">
                        <div class="mai-over">Step 1 of 5</div>
                        <div class="mai-t" style="margin-top:4px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="mai-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="mai-card">
                        <div class="mai-over">Step 2 of 5</div>
                        <div class="mai-t" style="margin-top:4px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="mai-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="mai-card">
                        <div class="mai-over">Step 3 of 5</div>
                        <div class="mai-t" style="margin-top:4px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="mai-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="mai-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="mai-card">
                        <div class="mai-over">Step 4 of 5</div>
                        <div class="mai-t" style="margin-top:4px;">Your details</div>
                        <div class="mai-field">Amelia Hart</div>
                        <div class="mai-field">07700 900123</div>
                        <div class="mai-field" style="opacity:.6;">Email (optional)</div>
                        <div class="mai-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="mai-card">
                        <div class="mai-over">Step 5 of 5</div>
                        <div class="mai-t" style="margin-top:4px;">All set</div>
                        <div class="mai-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
            
        </div>
    </section>

    <section id="punch" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">D · Punch — bold modern</h2>
                <p style="font-size:14px; opacity:.7;">App-like confidence: deep plum header, coral action colour, big 44px+ touch targets. Built thumb-first.</p>
            </div>
            <div class="w-punch" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="pun-card">
                        <div class="pun-t">Choose a service</div>
                        <div class="pun-dots"><i class=""></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($services as $i => $sv)
                            <div class="pun-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="pun-card">
                        <div class="pun-t">Choose a stylist</div>
                        <div class="pun-dots"><i class="on"></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($stylists as $i => $st)
                            <div class="pun-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="pun-card">
                        <div class="pun-t">Pick a time</div>
                        <div class="pun-dots"><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i><i class=""></i></div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="pun-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="pun-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="pun-card">
                        <div class="pun-t">Your details</div>
                        <div class="pun-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i></div>
                        <div class="pun-field">Amelia Hart</div>
                        <div class="pun-field">07700 900123</div>
                        <div class="pun-field" style="opacity:.6;">Email (optional)</div>
                        <div class="pun-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="pun-card">
                        <div class="pun-t">All set</div>
                        <div class="pun-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i></div>
                        <div class="pun-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
            
        </div>
    </section>

    <section id="folio" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">E · Folio — elegant editorial</h2>
                <p style="font-size:14px; opacity:.7;">Understated luxury: paper, hairline rules, serif hierarchy, numbered folios, an ink smallcaps button.</p>
            </div>
            <div class="w-folio" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="fol-card">
                        <div class="fol-num">01 · Service</div>
                        <div class="fol-t" style="margin-top:5px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="fol-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="fol-card">
                        <div class="fol-num">02 · Stylist</div>
                        <div class="fol-t" style="margin-top:5px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="fol-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="fol-card">
                        <div class="fol-num">03 · Time</div>
                        <div class="fol-t" style="margin-top:5px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="fol-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="fol-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="fol-card">
                        <div class="fol-num">04 · Details</div>
                        <div class="fol-t" style="margin-top:5px;">Your details</div>
                        <div class="fol-field">Amelia Hart</div>
                        <div class="fol-field">07700 900123</div>
                        <div class="fol-field" style="opacity:.6;">Email (optional)</div>
                        <div class="fol-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="fol-card">
                        <div class="fol-num">05 · Booked</div>
                        <div class="fol-t" style="margin-top:5px;">All set</div>
                        <div class="fol-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
            
        </div>
    </section>

    {{-- ————— Footer note ————— --}}
    <div class="wg-band" style="padding-top: 24px; padding-bottom: 52px;">
        <div class="wg-inner">
            <p style="font-size:14px; opacity:.7; max-width:560px; line-height:1.6;">Temporary exploration surface. Once a design is chosen it will be applied to the real embeddable widget and this tab removed.</p>
        </div>
    </div>
</div>
