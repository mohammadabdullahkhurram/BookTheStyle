<?php

use App\Models\Salon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * TEMPORARY design-direction gallery. Five fully-realised aesthetics, each
 * rendering the SAME set of mockup components (header + stats, table,
 * buttons + pills, card + modal preview, form fields, nav sample) so the
 * owner can compare and pick one. Self-contained sample markup only — no
 * real data, no logic, styles scoped to this page. Once a direction is
 * chosen it gets implemented app-wide and this page + its nav tab are
 * deleted.
 */
new #[Title('Design directions')] class extends Component {
    public Salon $salon;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;
    }
}; ?>

@php
    // Shared fake sample data — identical across directions so they compare fairly.
    $rows = [
        ['time' => '9:00 AM', 'client' => 'Amelia Hart', 'service' => 'Cut & finish', 'stylist' => 'Maya', 'status' => 'Checked in', 'kind' => 'ok'],
        ['time' => '10:30 AM', 'client' => 'Ruth Okafor', 'service' => 'Full colour', 'stylist' => 'Simone', 'status' => 'Booked', 'kind' => 'muted'],
        ['time' => '1:15 PM', 'client' => 'Daniel Reyes', 'service' => 'Beard trim', 'stylist' => 'Jonah', 'status' => 'No-show', 'kind' => 'bad'],
    ];
    $stats = [
        ['label' => 'Bookings today', 'value' => '14', 'sub' => 'across 4 stylists'],
        ['label' => 'Waiting', 'value' => '2', 'sub' => 'arrived, not started'],
        ['label' => 'Completed', 'value' => '6', 'sub' => 'so far today'],
        ['label' => 'Est. revenue', 'value' => '£640', 'sub' => 'priced services'],
    ];
    $nav = ['Today', 'Calendar', 'Clients', 'Reports'];
@endphp

<div class="gallery-root">
    <style>
        /* ————— Gallery shell (neutral) ————— */
        .gallery-root { background: #14110e; color: #efeae4; }
        .g-band { padding: 72px 24px; }
        .g-band-inner { max-width: 1080px; margin: 0 auto; }
        .g-title { font-family: 'Fraunces', serif; font-size: 15px; letter-spacing: .14em; text-transform: uppercase; }
        .g-caption { font-size: 14px; opacity: .75; max-width: 560px; line-height: 1.6; }
        .g-grid { display: grid; gap: 28px; margin-top: 36px; }
        @media (min-width: 900px) { .g-grid { grid-template-columns: 1.25fr .75fr; } }
        .g-label { font-size: 11px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; opacity: .55; margin: 26px 0 10px; }
        .g-stage { border-radius: 14px; overflow: hidden; }

        /* ═══════ 1 · LUMEN — light glass premium ═══════ */
        .d-lumen { background:
            radial-gradient(48rem 30rem at 8% -10%, rgb(130 76 113 / .09), transparent 60%),
            radial-gradient(40rem 26rem at 95% 0%, rgb(214 138 111 / .08), transparent 60%),
            #f6f3ee; color: #241f1a; font-family: 'Hanken Grotesk', sans-serif; }
        .d-lumen .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; letter-spacing: -.02em; }
        .lum-tile { background: rgb(255 255 255 / .55); -webkit-backdrop-filter: blur(18px) saturate(1.5); backdrop-filter: blur(18px) saturate(1.5);
            border: 1px solid rgb(63 47 38 / .08); border-radius: 16px; padding: 15px 17px;
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8), 0 6px 22px rgb(63 47 38 / .07); transition: box-shadow .2s ease; }
        .lum-tile:hover { box-shadow: inset 0 1px 0 rgb(255 255 255 / .8), 0 10px 30px rgb(63 47 38 / .1); }
        .lum-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 30px; font-weight: 700; letter-spacing: -.02em; }
        .lum-glass { background: rgb(255 255 255 / .55); -webkit-backdrop-filter: blur(18px) saturate(1.5); backdrop-filter: blur(18px) saturate(1.5);
            border: 1px solid rgb(63 47 38 / .08); box-shadow: inset 0 1px 0 rgb(255 255 255 / .8), 0 8px 30px rgb(63 47 38 / .08); }
        .lum-btn { height: 40px; padding: 0 18px; border-radius: 11px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
        .lum-btn-p { background: #824c71; color: #fff; box-shadow: 0 4px 16px rgb(130 76 113 / .3); }
        .lum-btn-s { background: rgb(255 255 255 / .6); border: 1px solid rgb(63 47 38 / .12); color: #3d362f; -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
        .lum-btn-d { background: rgb(255 255 255 / .6); border: 1px solid rgb(162 58 58 / .3); color: #a23a3a; }
        .lum-pill { font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 99px; -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
        .lum-row { border-top: 1px solid rgb(63 47 38 / .07); }
        .lum-field { background: rgb(255 255 255 / .7); border: 1px solid rgb(63 47 38 / .12); border-radius: 11px; padding: 10px 13px; font-size: 14px; color: #57504a; }
        .lum-nav a { border-radius: 10px; padding: 8px 12px; font-size: 14px; font-weight: 500; color: #6c645c; display: block; }
        .lum-nav .on { background: rgb(130 76 113 / .12); color: #6b3358; font-weight: 600; }

        /* ═══════ 2 · JOURNAL — editorial warm ═══════ */
        .d-journal { background: #faf6ed; color: #262019; font-family: 'Hanken Grotesk', sans-serif; }
        .d-journal .hd { font-family: 'Fraunces', serif; font-weight: 600; letter-spacing: -.005em; }
        .jou-rule { border-top: 1px solid #ded1bc; }
        .jou-over { font-size: 11.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #6b3358; }
        .jou-num { font-family: 'Fraunces', serif; font-size: 42px; font-weight: 500; letter-spacing: -.01em; line-height: 1; }
        .jou-btn { height: 42px; padding: 0 22px; border-radius: 3px; font-weight: 600; font-size: 13.5px; letter-spacing: .04em; display: inline-flex; align-items: center; text-transform: uppercase; }
        .jou-btn-p { background: #262019; color: #faf6ed; }
        .jou-btn-s { background: transparent; border: 1px solid #262019; color: #262019; }
        .jou-btn-d { background: transparent; border-bottom: 1px solid #a23a3a; border-radius: 0; color: #a23a3a; padding: 0 2px; height: 34px; }
        .jou-pill { font-size: 11.5px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; padding: 4px 0; border-bottom: 2px solid currentColor; }
        .jou-field { background: transparent; border: 0; border-bottom: 1px solid #b8a98e; border-radius: 0; padding: 9px 2px; font-size: 15px; color: #57504a; width: 100%; }
        .jou-nav a { padding: 9px 2px; font-size: 14.5px; color: #6c645c; display: block; border-bottom: 1px solid #eee3cf; }
        .jou-nav .on { color: #262019; font-weight: 600; border-bottom-color: #262019; }

        /* ═══════ 3 · MERIDIAN — clean modern dashboard ═══════ */
        .d-meridian { background: #fafaf8; color: #1a1a17; font-family: 'Hanken Grotesk', sans-serif; }
        .d-meridian .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; letter-spacing: -.025em; }
        .mer-card { background: #fff; border: 1px solid #e9e7e2; border-radius: 10px; box-shadow: 0 1px 2px rgb(20 18 15 / .04); }
        .mer-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 26px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .mer-delta { font-size: 12px; font-weight: 600; padding: 2px 7px; border-radius: 6px; }
        .mer-btn { height: 36px; padding: 0 14px; border-radius: 8px; font-weight: 600; font-size: 13.5px; display: inline-flex; align-items: center; }
        .mer-btn-p { background: linear-gradient(135deg, #6e56cf 0%, #824c71 100%); color: #fff; box-shadow: 0 1px 2px rgb(20 18 15 / .2); }
        .mer-btn-s { background: #fff; border: 1px solid #dcd9d2; color: #3d3a34; box-shadow: 0 1px 2px rgb(20 18 15 / .04); }
        .mer-btn-d { background: #fff; border: 1px solid #eccfcf; color: #b03636; }
        .mer-pill { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 6px; }
        .mer-row td, .mer-row th { padding: 9px 14px; font-size: 13.5px; }
        .mer-row { border-top: 1px solid #f0eee9; }
        .mer-field { background: #fff; border: 1px solid #dcd9d2; border-radius: 8px; padding: 8px 12px; font-size: 13.5px; color: #4a463f;
            box-shadow: 0 1px 2px rgb(20 18 15 / .03); }
        .mer-nav a { border-radius: 7px; padding: 6px 10px; font-size: 13.5px; font-weight: 500; color: #6a655c; display: block; }
        .mer-nav .on { background: #efeaf5; color: #5b3a7e; font-weight: 600; }

        /* ═══════ 4 · HALO — soft depth, Cosmos-lite ═══════ */
        .d-halo { background:
            radial-gradient(44rem 30rem at 15% 0%, rgb(130 76 113 / .14), transparent 55%),
            radial-gradient(40rem 30rem at 85% 10%, rgb(216 142 85 / .12), transparent 55%),
            radial-gradient(50rem 36rem at 50% 110%, rgb(91 146 189 / .1), transparent 58%),
            #fdfbf7; color: #262024; font-family: 'Hanken Grotesk', sans-serif; }
        .d-halo .hd { font-family: 'Fraunces', serif; font-weight: 600; letter-spacing: -.01em; }
        .halo-float { background: rgb(255 255 255 / .82); border: 0; border-radius: 22px;
            box-shadow: 0 20px 50px rgb(74 44 66 / .1), 0 2px 8px rgb(74 44 66 / .05); }
        .halo-num { font-family: 'Fraunces', serif; font-size: 36px; font-weight: 600;
            background: linear-gradient(120deg, #6b3358, #a2542a); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .halo-btn { height: 42px; padding: 0 20px; border-radius: 99px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
        .halo-btn-p { background: linear-gradient(120deg, #824c71, #a2542a); color: #fff; box-shadow: 0 10px 26px rgb(130 76 113 / .3); }
        .halo-btn-s { background: rgb(255 255 255 / .85); color: #4a3d46; box-shadow: 0 6px 18px rgb(74 44 66 / .1); }
        .halo-btn-d { background: rgb(255 255 255 / .85); color: #b03636; box-shadow: 0 6px 18px rgb(176 54 54 / .12); }
        .halo-pill { font-size: 12.5px; font-weight: 600; padding: 5px 13px; border-radius: 99px; box-shadow: 0 4px 12px rgb(74 44 66 / .08); background: rgb(255 255 255 / .85); }
        .halo-row { border-top: 1px solid rgb(74 44 66 / .08); }
        .halo-field { background: rgb(255 255 255 / .9); border: 0; border-radius: 14px; padding: 11px 15px; font-size: 14px; color: #57504a;
            box-shadow: inset 0 0 0 1px rgb(74 44 66 / .1), 0 4px 14px rgb(74 44 66 / .05); }
        .halo-nav a { border-radius: 99px; padding: 8px 16px; font-size: 14px; font-weight: 500; color: #6c5f66; display: block; }
        .halo-nav .on { background: rgb(255 255 255 / .9); color: #6b3358; font-weight: 600; box-shadow: 0 6px 16px rgb(74 44 66 / .1); }

        /* ═══════ 5 · VELVET — boutique tactile ═══════ */
        .d-velvet { background: #f8f1ea; color: #3a2233; font-family: 'Hanken Grotesk', sans-serif; }
        .d-velvet .hd { font-family: 'Fraunces', serif; font-weight: 600; letter-spacing: 0; }
        .vel-panel { background: #fffdf9; border: 1px solid #ecdccd; border-radius: 26px; box-shadow: 0 2px 0 #ecdccd; }
        .vel-num { font-family: 'Fraunces', serif; font-size: 38px; font-weight: 600; color: #6b3358; }
        .vel-btn { height: 44px; padding: 0 22px; border-radius: 99px; font-weight: 600; font-size: 14.5px; display: inline-flex; align-items: center; }
        .vel-btn-p { background: #6b3358; color: #f8f1ea; box-shadow: inset 0 1px 0 rgb(255 255 255 / .25), 0 3px 0 #4e2440; }
        .vel-btn-p:active { transform: translateY(2px); box-shadow: inset 0 1px 0 rgb(255 255 255 / .25), 0 1px 0 #4e2440; }
        .vel-btn-s { background: #fffdf9; border: 1.5px solid #d8bfae; color: #7c4a2d; box-shadow: 0 2px 0 #e4d2c2; }
        .vel-btn-d { background: #fffdf9; border: 1.5px solid #dfb9b9; color: #a23a3a; box-shadow: 0 2px 0 #ecd3d3; }
        .vel-pill { font-size: 12.5px; font-weight: 600; padding: 5px 14px; border-radius: 99px; border: 1px solid currentColor; }
        .vel-row { border-top: 1px dashed #e2cdbb; }
        .vel-field { background: #fffdf9; border: 1.5px solid #e0c9b7; border-radius: 16px; padding: 11px 16px; font-size: 14.5px; color: #5c4a3d; }
        .vel-nav a { border-radius: 14px; padding: 9px 14px; font-size: 14.5px; font-weight: 500; color: #7d6a5d; display: block; }
        .vel-nav .on { background: #f0dfe9; color: #6b3358; font-weight: 600; }

        /* ═══════ 6 · STUDIO — mono minimal ═══════ */
        .d-studio { background: #fcfbf8; color: #1a1918; font-family: 'Hanken Grotesk', sans-serif; }
        .d-studio .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; letter-spacing: -.03em; }
        .stu-rule { border-top: 1px solid #e6e4de; }
        .stu-lbl { font-size: 10.5px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: #8a877f; }
        .stu-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 40px; font-weight: 400; letter-spacing: -.03em; line-height: 1; }
        .stu-btn { height: 38px; padding: 0 18px; border-radius: 6px; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; }
        .stu-btn-p { background: #1a1918; color: #fcfbf8; }
        .stu-btn-s { background: transparent; border: 1px solid #c9c6be; color: #1a1918; }
        .stu-btn-d { background: transparent; border: 1px solid #c9c6be; color: #8a2e2e; }
        .stu-pill { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; padding: 3px 9px; border: 1px solid #d9d6cf; border-radius: 4px; }
        .stu-field { background: transparent; border: 1px solid #c9c6be; border-radius: 6px; padding: 9px 12px; font-size: 13.5px; color: #4a4741; }
        .stu-nav a { padding: 7px 0; font-size: 13.5px; color: #8a877f; display: block; letter-spacing: .01em; }
        .stu-nav .on { color: #1a1918; font-weight: 600; }
        .stu-nav .on::before { content: '—'; color: #824c71; margin-right: 8px; }

        /* ═══════ 7 · AURORA — vibrant gradient ═══════ */
        .d-aurora { background:
            radial-gradient(42rem 26rem at 5% -8%, rgb(110 86 207 / .2), transparent 55%),
            radial-gradient(38rem 24rem at 70% -12%, rgb(199 106 140 / .18), transparent 55%),
            radial-gradient(46rem 30rem at 100% 30%, rgb(212 154 78 / .14), transparent 55%),
            #fefdfb; color: #221d26; font-family: 'Hanken Grotesk', sans-serif; }
        .d-aurora .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; letter-spacing: -.03em; }
        .aur-grad-text { background: linear-gradient(100deg, #6e56cf 0%, #b04a7d 55%, #d4702e 100%); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .aur-card { position: relative; background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgb(52 33 70 / .06); }
        .aur-card::before { content: ''; position: absolute; inset: 0; border-radius: 14px; padding: 1px;
            background: linear-gradient(120deg, rgb(110 86 207 / .45), rgb(199 106 140 / .3), rgb(212 154 78 / .3));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .aur-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 30px; font-weight: 800; letter-spacing: -.02em; }
        .aur-btn { height: 40px; padding: 0 18px; border-radius: 10px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
        .aur-btn-p { background: linear-gradient(100deg, #6e56cf, #b04a7d); color: #fff; box-shadow: 0 6px 18px rgb(110 86 207 / .3); }
        .aur-btn-s { background: #fff; border: 1px solid #e4dfe8; color: #453a50; }
        .aur-btn-d { background: #fff; border: 1px solid #ecd3d9; color: #b03652; }
        .aur-pill { font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 99px; }
        .aur-row { border-top: 1px solid #f1edf2; }
        .aur-field { background: #fff; border: 1px solid #e4dfe8; border-radius: 10px; padding: 10px 13px; font-size: 14px; color: #57504a; }
        .aur-nav a { border-radius: 9px; padding: 7px 12px; font-size: 14px; font-weight: 500; color: #6d6377; display: block; }
        .aur-nav .on { background: linear-gradient(100deg, rgb(110 86 207 / .14), rgb(199 106 140 / .12)); color: #5b3a7e; font-weight: 600; }

        /* ═══════ 8 · MANOR — warm neutral pro ═══════ */
        .d-manor { background: #eeeae3; color: #2e2a24; font-family: 'Hanken Grotesk', sans-serif; }
        .d-manor .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 600; letter-spacing: -.01em; }
        .man-panel { background: #f7f4ee; border: 1px solid #e0dad0; border-radius: 8px; box-shadow: 0 1px 2px rgb(46 42 36 / .04); }
        .man-lbl { font-size: 11px; font-weight: 600; letter-spacing: .22em; text-transform: uppercase; color: #8d8377; }
        .man-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 32px; font-weight: 600; letter-spacing: -.01em; color: #2e2a24; }
        .man-btn { height: 42px; padding: 0 22px; border-radius: 4px; font-weight: 600; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .man-btn-p { background: #2e2a24; color: #f2efe8; }
        .man-btn-s { background: transparent; border: 1px solid #b5aa99; color: #2e2a24; }
        .man-btn-d { background: transparent; border: 1px solid #b5aa99; color: #8f4636; }
        .man-pill { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; padding: 4px 10px; background: #e6e0d5; border-radius: 3px; }
        .man-row { border-top: 1px solid #e0dad0; }
        .man-field { background: #f7f4ee; border: 1px solid #cfc6b8; border-radius: 4px; padding: 10px 14px; font-size: 14px; color: #574f45; }
        .man-nav a { padding: 8px 0; font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: #8d8377; display: block; border-bottom: 1px solid #e0dad0; }
        .man-nav .on { color: #6b3358; font-weight: 600; }

        /* ═══════ 9 · BLOOM — playful boutique ═══════ */
        .d-bloom { background: #fdf8f2; color: #43332c; font-family: 'Hanken Grotesk', sans-serif; }
        .d-bloom .hd { font-family: 'Fraunces', serif; font-weight: 700; letter-spacing: 0; }
        .blm-card { background: #fff; border-radius: 20px; box-shadow: inset -2px -2px 6px rgb(214 138 111 / .08), 0 4px 14px rgb(122 74 44 / .08); }
        .blm-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 700; color: #824c71; }
        .blm-btn { height: 44px; padding: 0 22px; border-radius: 99px; font-weight: 700; font-size: 14.5px; display: inline-flex; align-items: center; transition: transform .15s cubic-bezier(.34,1.56,.64,1); }
        .blm-btn:hover { transform: translateY(-2px); }
        .blm-btn-p { background: #824c71; color: #fff; box-shadow: 0 5px 0 rgb(107 51 88 / .35); }
        .blm-btn-s { background: #ffe9d9; color: #9a5a2a; }
        .blm-btn-d { background: #ffe3e3; color: #a23a3a; }
        .blm-pill { font-size: 12.5px; font-weight: 700; padding: 5px 13px; border-radius: 99px; }
        .blm-row { border-top: 2px dotted #f0dcc8; }
        .blm-field { background: #fff; border: 2px solid #f0dcc8; border-radius: 16px; padding: 10px 15px; font-size: 14.5px; color: #6b584c; }
        .blm-squig { display: inline-block; width: 46px; height: 6px; background: radial-gradient(circle 3px at 3px 3px, #d49a4e 90%, transparent) 0 0/12px 6px repeat-x; }
        .blm-nav a { border-radius: 99px; padding: 8px 16px; font-size: 14.5px; font-weight: 600; color: #8d7365; display: block; }
        .blm-nav .on { background: #f6e3ef; color: #824c71; }

        /* ═══════ 10 · VERTEX — sharp contemporary ═══════ */
        .d-vertex { background: #fcfcfb; color: #16150f; font-family: 'Hanken Grotesk', sans-serif; }
        .d-vertex .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; letter-spacing: -.04em; }
        .vtx-card { background: #fff; border: 1px solid #e9e8e4; border-radius: 8px; }
        .vtx-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 28px; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
        .vtx-btn { height: 34px; padding: 0 14px; border-radius: 7px; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; }
        .vtx-btn-p { background: #6b2e57; color: #fff; }
        .vtx-btn-p:hover { background: #55233f; }
        .vtx-btn-s { background: #fff; border: 1px solid #d8d7d1; color: #33322c; }
        .vtx-btn-d { background: #fff; border: 1px solid #d8d7d1; color: #b03636; }
        .vtx-pill { font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 5px; border: 1px solid; }
        .vtx-row { border-top: 1px solid #efeeea; }
        .vtx-field { background: #fff; border: 1px solid #d8d7d1; border-radius: 7px; padding: 8px 11px; font-size: 13.5px; color: #45443e;
            box-shadow: 0 1px 2px rgb(22 21 15 / .03); }
        .vtx-nav { background: rgb(255 255 255 / .65); -webkit-backdrop-filter: blur(14px) saturate(1.3); backdrop-filter: blur(14px) saturate(1.3);
            border: 1px solid #e9e8e4; border-radius: 10px; }
        .vtx-nav a { border-radius: 6px; padding: 6px 10px; font-size: 13px; font-weight: 500; color: #6a685f; display: block; }
        .vtx-nav .on { background: #16150f; color: #fcfcfb; font-weight: 600; }

        /* ═══════ 11 · GLACIER — full liquid glass ═══════ */
        .d-glacier { background:
            radial-gradient(46rem 30rem at 8% -10%, rgb(130 76 113 / .28), transparent 58%),
            radial-gradient(42rem 28rem at 92% -4%, rgb(91 146 189 / .26), transparent 58%),
            radial-gradient(52rem 34rem at 45% 115%, rgb(212 154 78 / .22), transparent 60%),
            #f3f1ee; color: #262126; font-family: 'Hanken Grotesk', sans-serif; }
        .d-glacier .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; letter-spacing: -.02em; }
        .gla-glass, .gla-stat, .gla-panel, .gla-modal, .gla-nav, .gla-field, .gla-btn-s {
            background: rgb(255 255 255 / .42);
            -webkit-backdrop-filter: blur(22px) saturate(1.6); backdrop-filter: blur(22px) saturate(1.6);
            border: 1px solid rgb(255 255 255 / .65);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .9), inset 1px 0 0 rgb(255 255 255 / .4), 0 10px 34px rgb(63 47 70 / .12); }
        .gla-stat { border-radius: 20px; padding: 16px 18px; transition: transform .2s ease, box-shadow .2s ease; }
        .gla-stat:hover { transform: translateY(-2px); }
        .gla-glass { border-radius: 20px; }
        .gla-panel { border-radius: 20px; }
        .gla-modal { border-radius: 22px; background: rgb(255 255 255 / .6); }
        .gla-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 30px; font-weight: 700; letter-spacing: -.02em; }
        .gla-btn { height: 42px; padding: 0 19px; border-radius: 99px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
        .gla-btn-p { background: rgb(130 76 113 / .85); color: #fff; -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            border: 1px solid rgb(255 255 255 / .35); box-shadow: inset 0 1px 0 rgb(255 255 255 / .35), 0 8px 22px rgb(130 76 113 / .35); }
        .gla-btn-s { border-radius: 99px; color: #453a48; }
        .gla-btn-d { background: rgb(255 255 255 / .42); border: 1px solid rgb(176 54 54 / .4); color: #a23a3a; border-radius: 99px;
            -webkit-backdrop-filter: blur(22px); backdrop-filter: blur(22px); }
        .gla-pill { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 99px; border: 1px solid rgb(255 255 255 / .6);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
        .gla-ok { background: rgb(62 92 58 / .16); color: #33502f; } .gla-bad { background: rgb(162 58 58 / .14); color: #8f2f2f; } .gla-mut { background: rgb(255 255 255 / .45); color: #57504f; }
        .gla-row { border-top: 1px solid rgb(255 255 255 / .55); }
        .gla-th { padding: 12px 16px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: #6d6068; }
        .gla-field { border-radius: 14px; padding: 10px 14px; font-size: 14px; color: #57504f; }
        .gla-nav { border-radius: 20px; padding: 10px; }
        .gla-nav a { border-radius: 12px; padding: 8px 13px; font-size: 14px; font-weight: 500; color: #5d5259; display: block; }
        .gla-nav .on { background: rgb(255 255 255 / .7); color: #6b3358; font-weight: 600; box-shadow: inset 0 1px 0 rgb(255 255 255 / .9), 0 4px 12px rgb(63 47 70 / .1); }

        /* ═══════ 12 · MARBLE — the Marblism theme ═══════ */
        .d-marble { background: #fff8ef; color: #4a382e; font-family: 'Hanken Grotesk', sans-serif; }
        .d-marble .hd { font-family: 'Fraunces', serif; font-weight: 700; }
        .mar-bloom { display: inline-flex; gap: 5px; }
        .mar-bloom i { width: 10px; height: 10px; border-radius: 50% 50% 50% 0; }
        .mar-stat, .mar-panel, .mar-modal, .mar-nav, .mar-glass { background: #fffdf8; border: 2px solid #f2e2cd; border-radius: 22px;
            box-shadow: 0 3px 0 #f2e2cd; }
        .mar-stat { padding: 16px 19px; }
        .mar-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 700; }
        .mar-btn { height: 44px; padding: 0 22px; border-radius: 99px; font-weight: 700; font-size: 14.5px; display: inline-flex; align-items: center; }
        .mar-btn-p { background: #f08a5d; color: #fff; box-shadow: 0 4px 0 #c96a41; }
        .mar-btn-s { background: #f7d774; color: #6b4d15; box-shadow: 0 4px 0 #d9b74e; }
        .mar-btn-d { background: #fffdf8; border: 2px solid #e8b1b1; color: #b04545; box-shadow: 0 4px 0 #e8b1b1; }
        .mar-pill { font-size: 12.5px; font-weight: 700; padding: 5px 13px; border-radius: 99px; }
        .mar-ok { background: #e3efdb; color: #4c6b3c; } .mar-bad { background: #fbe0e0; color: #b04545; } .mar-mut { background: #f7ecd9; color: #8a6a3a; }
        .mar-row { border-top: 2px solid #f7ecd9; }
        .mar-th { padding: 13px 18px; font-size: 12.5px; font-weight: 700; color: #b08a5e; }
        .mar-field { background: #fffdf8; border: 2px solid #f2e2cd; border-radius: 16px; padding: 10px 15px; font-size: 14.5px; color: #6b5546; }
        .mar-nav { padding: 10px; }
        .mar-nav a { border-radius: 14px; padding: 9px 15px; font-size: 14.5px; font-weight: 600; color: #a0805e; display: block; }
        .mar-nav .on { background: #fbe9d7; color: #c96a41; }

        /* ═══════ 13 · BOLT — neo-brutalist ═══════ */
        .d-bolt { background: #fffdf5; color: #111; font-family: 'Hanken Grotesk', sans-serif; }
        .d-bolt .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: -.01em; }
        .bol-stat, .bol-panel, .bol-modal, .bol-nav, .bol-glass { background: #fff; border: 3px solid #111; border-radius: 0;
            box-shadow: 5px 5px 0 #111; }
        .bol-stat { padding: 14px 16px; }
        .bol-stat:nth-child(1) { background: #ffdb58; } .bol-stat:nth-child(2) { background: #b7e4f0; }
        .bol-stat:nth-child(3) { background: #c8ecc4; } .bol-stat:nth-child(4) { background: #f6c6dd; }
        .bol-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 34px; font-weight: 800; }
        .bol-btn { height: 44px; padding: 0 18px; border: 3px solid #111; border-radius: 0; font-weight: 800; font-size: 13.5px;
            text-transform: uppercase; display: inline-flex; align-items: center; box-shadow: 4px 4px 0 #111; }
        .bol-btn:active { transform: translate(3px,3px); box-shadow: 1px 1px 0 #111; }
        .bol-btn-p { background: #ffdb58; color: #111; }
        .bol-btn-s { background: #fff; color: #111; }
        .bol-btn-d { background: #ff6b6b; color: #111; }
        .bol-pill { font-size: 11.5px; font-weight: 800; text-transform: uppercase; padding: 4px 9px; border: 2px solid #111; }
        .bol-ok { background: #c8ecc4; } .bol-bad { background: #ff6b6b; } .bol-mut { background: #eee; }
        .bol-row { border-top: 3px solid #111; }
        .bol-th { padding: 11px 14px; font-size: 12px; font-weight: 800; text-transform: uppercase; background: #111; color: #fffdf5; }
        .bol-field { background: #fff; border: 3px solid #111; border-radius: 0; padding: 10px 13px; font-size: 14px; font-weight: 600; }
        .bol-nav { padding: 8px; }
        .bol-nav a { padding: 9px 12px; font-size: 14px; font-weight: 800; text-transform: uppercase; color: #111; display: block; border: 2px solid transparent; }
        .bol-nav .on { background: #ffdb58; border-color: #111; box-shadow: 3px 3px 0 #111; }

        /* ═══════ 14 · CHROME — retro-futuristic Y2K ═══════ */
        .d-chrome { background: #f3f3fa; color: #201d2c; font-family: 'Hanken Grotesk', sans-serif; }
        .d-chrome .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; letter-spacing: -.03em; }
        .chr-irid { position: relative; background: #fff; border-radius: 16px; box-shadow: 0 4px 16px rgb(60 40 120 / .1); }
        .chr-irid::before { content: ''; position: absolute; inset: 0; border-radius: 16px; padding: 2px;
            background: linear-gradient(120deg, #ff69b4, #9f6bff, #00d5e0, #ff69b4);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .chr-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 32px; font-weight: 800;
            background: linear-gradient(160deg, #7d7a8c 0%, #d9d7e4 30%, #55506b 55%, #c9c6da 80%);
            -webkit-background-clip: text; background-clip: text; color: transparent; }
        .chr-btn { height: 42px; padding: 0 20px; border-radius: 99px; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; }
        .chr-btn-p { background: linear-gradient(120deg, #9f6bff, #ff69b4); color: #fff; box-shadow: 0 6px 20px rgb(159 107 255 / .4); }
        .chr-btn-s { background: linear-gradient(160deg, #ececf4, #ffffff 40%, #d8d6e6 90%); border: 1px solid #c9c6da; color: #3c3752; }
        .chr-btn-d { background: #fff; border: 2px solid #ff69b4; color: #c23a76; }
        .chr-glass { background: #fff; border: 1px solid #e3e1ee; border-radius: 16px; box-shadow: 0 4px 16px rgb(60 40 120 / .1); }
        .chr-pill { font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 99px; }
        .chr-ok { background: #d8fbef; color: #0e7a5a; } .chr-bad { background: #ffe0ef; color: #c23a76; } .chr-mut { background: #ebeaf3; color: #55506b; }
        .chr-row { border-top: 1px solid #ebeaf3; }
        .chr-th { padding: 12px 16px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .14em; color: #8a86a0; }
        .chr-field { background: #fff; border: 1px solid #c9c6da; border-radius: 12px; padding: 10px 14px; font-size: 14px; color: #55506b;
            box-shadow: inset 0 1px 2px rgb(60 40 120 / .06); }
        .chr-nav { padding: 9px; }
        .chr-nav a { border-radius: 99px; padding: 8px 15px; font-size: 14px; font-weight: 600; color: #6d6884; display: block; }
        .chr-nav .on { background: linear-gradient(120deg, rgb(159 107 255 / .18), rgb(255 105 180 / .16)); color: #6d33b8; }

        /* ═══════ 15 · GAZETTE — newspaper broadsheet ═══════ */
        .d-gazette { background: #f9f5ea; color: #171310; font-family: Georgia, 'Times New Roman', serif; }
        .d-gazette .hd { font-family: 'Fraunces', serif; font-weight: 700; letter-spacing: -.005em; }
        .gaz-mast { font-family: 'Fraunces', serif; font-weight: 700; font-size: 26px; letter-spacing: .02em; text-transform: uppercase;
            border-top: 3px double #171310; border-bottom: 3px double #171310; padding: 8px 0; text-align: center; }
        .gaz-rule { border-top: 1px solid #171310; }
        .gaz-lbl { font-family: 'Hanken Grotesk', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #171310; }
        .gaz-num { font-family: 'Fraunces', serif; font-size: 40px; font-weight: 700; line-height: 1; }
        .gaz-btn { height: 40px; padding: 0 18px; border-radius: 0; font-family: 'Hanken Grotesk', sans-serif; font-weight: 700;
            font-size: 12.5px; letter-spacing: .1em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .gaz-btn-p { background: #171310; color: #f9f5ea; }
        .gaz-btn-s { background: transparent; border: 1px solid #171310; color: #171310; }
        .gaz-btn-d { background: transparent; border: 1px solid #171310; color: #8a2e2e; text-decoration: line-through; text-decoration-color: transparent; }
        .gaz-pill { font-family: 'Hanken Grotesk', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; padding: 3px 8px; border: 1px solid currentColor; }
        .gaz-ok { color: #171310; } .gaz-bad { color: #8a2e2e; } .gaz-mut { color: #6d675c; }
        .gaz-row { border-top: 1px dotted #171310; }
        .gaz-th { padding: 10px 0; font-family: 'Hanken Grotesk', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; border-bottom: 2px solid #171310; }
        .gaz-field { background: transparent; border: 0; border-bottom: 2px solid #171310; border-radius: 0; padding: 8px 2px; font-size: 15px; font-family: Georgia, serif; color: #3d372f; width: 100%; }
        .gaz-nav a { padding: 8px 0; font-size: 14.5px; color: #3d372f; display: block; border-bottom: 1px dotted #171310; }
        .gaz-nav .on { font-weight: 700; color: #171310; }
        .gaz-nav .on::after { content: ' ¶'; color: #8a2e2e; }

        /* ═══════ 16 · FERN — organic botanical ═══════ */
        .d-fern { background: #f6f2e8; color: #35402f; font-family: 'Hanken Grotesk', sans-serif; }
        .d-fern .hd { font-family: 'Fraunces', serif; font-weight: 600; }
        .fer-leaf { display: inline-block; width: 16px; height: 16px; background: #7e9670; border-radius: 0 60% 0 60%; transform: rotate(45deg); }
        .fer-stat { background: #fdfbf4; border-radius: 46% 54% 52% 48% / 42% 44% 56% 58%; padding: 22px 24px;
            box-shadow: 0 6px 18px rgb(76 107 76 / .1); }
        .fer-panel, .fer-modal, .fer-nav, .fer-glass { background: #fdfbf4; border-radius: 26px; box-shadow: 0 6px 18px rgb(76 107 76 / .1); }
        .fer-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 600; color: #4c6b4c; }
        .fer-btn { height: 44px; padding: 0 22px; border-radius: 99px; font-weight: 600; font-size: 14.5px; display: inline-flex; align-items: center; }
        .fer-btn-p { background: #4c6b4c; color: #f6f2e8; }
        .fer-btn-s { background: #eae3d0; color: #5c5643; }
        .fer-btn-d { background: transparent; border: 1.5px solid #b3603c; color: #b3603c; }
        .fer-pill { font-size: 12.5px; font-weight: 600; padding: 5px 13px; border-radius: 99px 99px 99px 4px; }
        .fer-ok { background: #e4ecd9; color: #4c6b4c; } .fer-bad { background: #f4ddd2; color: #a04f2e; } .fer-mut { background: #efe9d8; color: #77704f; }
        .fer-row { border-top: 1px solid #e7e0cb; }
        .fer-th { padding: 13px 18px; font-size: 11.5px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #90a077; }
        .fer-field { background: #fdfbf4; border: 1.5px solid #d9d0b4; border-radius: 18px 18px 18px 4px; padding: 11px 15px; font-size: 14.5px; color: #5c5643; }
        .fer-nav { padding: 10px; }
        .fer-nav a { border-radius: 99px 99px 99px 6px; padding: 9px 15px; font-size: 14.5px; font-weight: 500; color: #77704f; display: block; }
        .fer-nav .on { background: #e4ecd9; color: #4c6b4c; font-weight: 600; }

        /* ═══════ 17 · WERKSTATT — Bauhaus geometric ═══════ */
        .d-werkstatt { background: #f5f1e8; color: #17150f; font-family: 'Hanken Grotesk', sans-serif; }
        .d-werkstatt .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: .01em; }
        .wer-shapes { display: inline-flex; gap: 8px; align-items: center; }
        .wer-shapes .c { width: 16px; height: 16px; border-radius: 50%; background: #c33124; }
        .wer-shapes .s { width: 14px; height: 14px; background: #274690; }
        .wer-shapes .t { width: 0; height: 0; border-left: 9px solid transparent; border-right: 9px solid transparent; border-bottom: 15px solid #e8b72e; }
        .wer-stat, .wer-panel, .wer-modal, .wer-nav, .wer-glass { background: #fdfaf2; border: 2px solid #17150f; border-radius: 0; }
        .wer-stat { padding: 14px 16px; }
        .wer-stat:nth-child(1) { border-top: 8px solid #c33124; } .wer-stat:nth-child(2) { border-top: 8px solid #274690; }
        .wer-stat:nth-child(3) { border-top: 8px solid #e8b72e; } .wer-stat:nth-child(4) { border-top: 8px solid #17150f; }
        .wer-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 34px; font-weight: 800; }
        .wer-btn { height: 42px; padding: 0 20px; border-radius: 0; font-weight: 800; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .wer-btn-p { background: #274690; color: #f5f1e8; }
        .wer-btn-s { background: transparent; border: 2px solid #17150f; color: #17150f; }
        .wer-btn-d { background: #c33124; color: #f5f1e8; }
        .wer-pill { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: 4px 10px; border-radius: 99px; border: 2px solid #17150f; }
        .wer-ok { background: #e8b72e; } .wer-bad { background: #c33124; color: #f5f1e8 !important; border-color: #c33124; } .wer-mut { background: #fdfaf2; }
        .wer-row { border-top: 2px solid #17150f; }
        .wer-th { padding: 11px 15px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; }
        .wer-field { background: #fdfaf2; border: 2px solid #17150f; border-radius: 0; padding: 10px 13px; font-size: 14px; }
        .wer-nav { padding: 0; }
        .wer-nav a { padding: 10px 14px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #17150f; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #17150f; }
        .wer-nav a:last-child { border-bottom: 0; }
        .wer-nav a::before { content: ''; width: 10px; height: 10px; background: #d9d3c4; }
        .wer-nav .on::before { background: #c33124; border-radius: 50%; }

        /* ═══════ 18 · GILT — luxe dark gold ═══════ */
        .d-gilt { background: #131110; color: #e9e2d4; font-family: 'Hanken Grotesk', sans-serif; }
        .d-gilt .hd { font-family: 'Fraunces', serif; font-weight: 500; letter-spacing: .01em; color: #f0e8d8; }
        .gil-rule { border-top: 1px solid rgb(216 185 106 / .35); }
        .gil-lbl { font-size: 10.5px; font-weight: 600; letter-spacing: .24em; text-transform: uppercase; color: #d8b96a; }
        .gil-num { font-family: 'Fraunces', serif; font-size: 40px; font-weight: 500; color: #f0e8d8; line-height: 1; }
        .gil-panel, .gil-modal, .gil-nav, .gil-glass { background: #1b1815; border: 1px solid rgb(216 185 106 / .25); border-radius: 4px; }
        .gil-btn { height: 44px; padding: 0 24px; border-radius: 2px; font-weight: 600; font-size: 12.5px; letter-spacing: .14em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .gil-btn-p { background: #d8b96a; color: #131110; }
        .gil-btn-s { background: transparent; border: 1px solid rgb(216 185 106 / .5); color: #d8b96a; }
        .gil-btn-d { background: transparent; border: 1px solid rgb(196 120 120 / .45); color: #d99a9a; }
        .gil-pill { font-size: 10.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; padding: 4px 11px; border: 1px solid currentColor; border-radius: 2px; }
        .gil-ok { color: #b8cfa4; } .gil-bad { color: #d99a9a; } .gil-mut { color: #9d968a; }
        .gil-row { border-top: 1px solid rgb(216 185 106 / .16); }
        .gil-th { padding: 12px 18px; font-size: 10.5px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: #d8b96a; }
        .gil-field { background: #1b1815; border: 1px solid rgb(216 185 106 / .3); border-radius: 2px; padding: 11px 15px; font-size: 14px; color: #cfc6b6; }
        .gil-nav { padding: 8px; }
        .gil-nav a { padding: 9px 14px; font-size: 13px; letter-spacing: .1em; text-transform: uppercase; color: #9d968a; display: block; }
        .gil-nav .on { color: #d8b96a; border-left: 1px solid #d8b96a; padding-left: 13px; }

        /* ═══════ 19 · SKETCH — hand-drawn ═══════ */
        .d-sketch { background: #fdfaf3; color: #2e2a25; font-family: 'Hanken Grotesk', sans-serif; }
        .d-sketch .hd { font-family: 'Fraunces', serif; font-weight: 700; }
        .ske-wob { border: 2px solid #2e2a25; border-radius: 255px 15px 225px 15px / 15px 225px 15px 255px; background: #fffefa; }
        .ske-stat { padding: 15px 18px; transform: rotate(-.35deg); }
        .ske-stat:nth-child(even) { transform: rotate(.35deg); }
        .ske-hi { background: linear-gradient(transparent 55%, #ffe9a8 55%, #ffe9a8 92%, transparent 92%); }
        .ske-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 700; }
        .ske-btn { height: 42px; padding: 0 20px; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center;
            border: 2px solid #2e2a25; border-radius: 225px 15px 255px 15px / 15px 255px 15px 225px; background: #fffefa; }
        .ske-btn-p { background: #ffe9a8; }
        .ske-btn-s { background: #fffefa; }
        .ske-btn-d { background: #fffefa; color: #a23a3a; border-color: #a23a3a; border-style: dashed; }
        .ske-pill { font-size: 12px; font-weight: 700; padding: 3px 11px; border: 2px solid currentColor; border-radius: 200px 12px 200px 12px / 12px 200px 12px 200px; }
        .ske-ok { color: #4c6b3c; } .ske-bad { color: #a23a3a; } .ske-mut { color: #7a7264; }
        .ske-row { border-top: 2px dashed #cfc4ac; }
        .ske-th { padding: 12px 15px; font-size: 12px; font-weight: 700; color: #7a7264; }
        .ske-field { border: 2px solid #2e2a25; border-radius: 15px 225px 15px 255px / 255px 15px 225px 15px; background: #fffefa; padding: 10px 14px; font-size: 14px; color: #5c5546; }
        .ske-tape { position: absolute; top: -10px; left: 28px; width: 74px; height: 20px; background: rgb(216 185 106 / .4); transform: rotate(-3deg); }
        .ske-nav { padding: 10px; }
        .ske-nav a { padding: 8px 13px; font-size: 14.5px; font-weight: 600; color: #7a7264; display: block; }
        .ske-nav .on { color: #2e2a25; }
        .ske-nav .on span { background: linear-gradient(transparent 55%, #f6cfe0 55%, #f6cfe0 92%, transparent 92%); }

        /* ═══════ 20 · GRID — Swiss precision ═══════ */
        .d-grid { background: #ffffff; color: #111; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; }
        .d-grid .hd { font-weight: 700; letter-spacing: -.02em; }
        .gri-rule { border-top: 1px solid #e2e2e2; }
        .gri-idx { font-size: 10px; font-weight: 500; letter-spacing: .08em; color: #9b9b9b; font-variant-numeric: tabular-nums; }
        .gri-lbl { font-size: 11px; font-weight: 500; letter-spacing: .04em; color: #6f6f6f; }
        .gri-num { font-size: 38px; font-weight: 700; letter-spacing: -.03em; line-height: 1; font-variant-numeric: tabular-nums; }
        .gri-btn { height: 40px; padding: 0 20px; border-radius: 0; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; }
        .gri-btn-p { background: #111; color: #fff; }
        .gri-btn-s { background: #fff; border: 1px solid #111; color: #111; }
        .gri-btn-d { background: #fff; border: 1px solid #e0301e; color: #e0301e; }
        .gri-pill { font-size: 11px; font-weight: 500; padding: 3px 8px; border: 1px solid #ccc; color: #444; }
        .gri-ok { border-color: #111; color: #111; } .gri-bad { border-color: #e0301e; color: #e0301e; } .gri-mut { }
        .gri-row { border-top: 1px solid #ececec; }
        .gri-th { padding: 10px 0; font-size: 11px; font-weight: 500; letter-spacing: .04em; color: #6f6f6f; border-bottom: 1px solid #111; }
        .gri-field { background: #fff; border: 1px solid #ccc; border-radius: 0; padding: 10px 12px; font-size: 13.5px; color: #333; }
        .gri-nav a { padding: 8px 0; font-size: 13.5px; color: #6f6f6f; display: flex; gap: 14px; border-bottom: 1px solid #ececec; }
        .gri-nav .on { color: #111; font-weight: 700; }
        .gri-nav .on .gri-idx { color: #e0301e; }

        /* ═══════ 21 · DOUGH — claymorphism ═══════ */
        .d-dough { background: #f2eef7; color: #4a4356; font-family: 'Hanken Grotesk', sans-serif; }
        .d-dough .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; letter-spacing: -.01em; }
        .dou-clay, .dou-stat, .dou-panel, .dou-modal, .dou-nav, .dou-glass { background: #faf8fd; border-radius: 26px;
            box-shadow: inset -4px -4px 10px rgb(163 146 194 / .18), inset 4px 4px 10px rgb(255 255 255 / .9), 8px 10px 22px rgb(163 146 194 / .28); }
        .dou-stat { padding: 18px 20px; }
        .dou-stat:nth-child(1) { background: #efe6fb; } .dou-stat:nth-child(2) { background: #e2f2ec; }
        .dou-stat:nth-child(3) { background: #fdeee2; } .dou-stat:nth-child(4) { background: #fde8ef; }
        .dou-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 32px; font-weight: 800; color: #5c4a78; }
        .dou-btn { height: 46px; padding: 0 24px; border-radius: 99px; font-weight: 700; font-size: 14.5px; display: inline-flex; align-items: center;
            box-shadow: inset -3px -3px 7px rgb(0 0 0 / .12), inset 3px 3px 7px rgb(255 255 255 / .45), 6px 8px 16px rgb(163 146 194 / .3); }
        .dou-btn:active { box-shadow: inset 3px 3px 7px rgb(0 0 0 / .15), inset -2px -2px 5px rgb(255 255 255 / .3), 2px 3px 8px rgb(163 146 194 / .2); }
        .dou-btn-p { background: #8f6cc9; color: #fff; }
        .dou-btn-s { background: #faf8fd; color: #5c4a78; }
        .dou-btn-d { background: #f6d9de; color: #a34255; }
        .dou-pill { font-size: 12.5px; font-weight: 700; padding: 6px 14px; border-radius: 99px;
            box-shadow: inset -2px -2px 5px rgb(163 146 194 / .18), inset 2px 2px 5px rgb(255 255 255 / .85), 3px 4px 9px rgb(163 146 194 / .2); }
        .dou-ok { background: #e2f2ec; color: #3d7a5e; } .dou-bad { background: #fde3e8; color: #a34255; } .dou-mut { background: #efeaf6; color: #6d6482; }
        .dou-row { border-top: 2px solid #ece6f4; }
        .dou-th { padding: 13px 18px; font-size: 12px; font-weight: 700; color: #9a8fb0; }
        .dou-field { background: #f2eef7; border: 0; border-radius: 18px; padding: 12px 16px; font-size: 14.5px; color: #5c5468;
            box-shadow: inset 4px 4px 9px rgb(163 146 194 / .22), inset -3px -3px 7px rgb(255 255 255 / .9); }
        .dou-nav { padding: 12px; }
        .dou-nav a { border-radius: 99px; padding: 9px 16px; font-size: 14.5px; font-weight: 600; color: #7d7292; display: block; }
        .dou-nav .on { background: #efe6fb; color: #5c4a78;
            box-shadow: inset -2px -2px 5px rgb(163 146 194 / .2), inset 2px 2px 5px rgb(255 255 255 / .9); }

        /* ═══════ 22 · CONSOLE — terminal monospace ═══════ */
        .d-console { background: #f7f4ea; color: #24261f; font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; }
        .d-console .hd { font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-weight: 700; letter-spacing: -.02em; }
        .con-prompt::before { content: '$ '; color: #2e7d43; font-weight: 700; }
        .con-cursor::after { content: '▍'; color: #2e7d43; animation: con-blink 1.1s steps(1) infinite; }
        @keyframes con-blink { 50% { opacity: 0; } }
        .con-box, .con-stat, .con-panel, .con-modal, .con-nav, .con-glass { background: #fdfbf3; border: 1px solid #24261f; border-radius: 0; }
        .con-stat { padding: 13px 15px; }
        .con-num { font-size: 30px; font-weight: 700; color: #2e7d43; }
        .con-lbl { font-size: 11px; text-transform: lowercase; color: #6d7060; }
        .con-btn { height: 38px; padding: 0 16px; border-radius: 0; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center;
            font-family: inherit; border: 1px solid #24261f; }
        .con-btn-p { background: #24261f; color: #f7f4ea; }
        .con-btn-p::before { content: '> '; color: #7ee29a; }
        .con-btn-s { background: #fdfbf3; color: #24261f; }
        .con-btn-d { background: #fdfbf3; color: #9c3b2e; border-color: #9c3b2e; }
        .con-pill { font-size: 12px; font-weight: 700; padding: 2px 6px; }
        .con-ok { color: #2e7d43; } .con-ok::before { content: '[ '; } .con-ok::after { content: ' ]'; }
        .con-bad { color: #9c3b2e; } .con-bad::before { content: '[ '; } .con-bad::after { content: ' ]'; }
        .con-mut { color: #6d7060; } .con-mut::before { content: '[ '; } .con-mut::after { content: ' ]'; }
        .con-row { border-top: 1px dashed #b9b8a5; }
        .con-th { padding: 10px 14px; font-size: 11px; text-transform: lowercase; color: #6d7060; border-bottom: 1px solid #24261f; }
        .con-field { background: #fdfbf3; border: 1px solid #24261f; border-radius: 0; padding: 9px 12px; font-size: 13px; color: #3d4034; font-family: inherit; }
        .con-nav { padding: 10px 12px; }
        .con-nav a { padding: 6px 0; font-size: 13.5px; color: #6d7060; display: block; }
        .con-nav a::before { content: '  '; white-space: pre; }
        .con-nav .on { color: #24261f; font-weight: 700; }
        .con-nav .on::before { content: '❯ '; color: #2e7d43; }

        /* ═══════ 23 · CONFETTI — Memphis 80s postmodern ═══════ */
        .d-confetti { background: #fffdf6; color: #23204a; font-family: 'Hanken Grotesk', sans-serif; position: relative; }
        .d-confetti .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; letter-spacing: -.01em; }
        .cof-squig { display: inline-block; width: 54px; height: 8px;
            background: radial-gradient(circle 4px at 4px 8px, transparent 3.4px, #ff5d8f 3.4px, #ff5d8f 4.6px, transparent 4.6px) 0 0/14px 8px repeat-x; }
        .cof-stat, .cof-panel, .cof-modal, .cof-nav, .cof-glass { background: #fff; border: 2.5px solid #23204a; }
        .cof-stat { padding: 15px 17px; position: relative; }
        .cof-stat:nth-child(1) { border-radius: 18px 2px 18px 2px; background: #fff3c9; }
        .cof-stat:nth-child(2) { border-radius: 2px 18px 2px 18px; background: #d8f2ee; }
        .cof-stat:nth-child(3) { border-radius: 18px 18px 2px 18px; background: #ffe3ee; }
        .cof-stat:nth-child(4) { border-radius: 2px 2px 18px 18px; background: #e6e0ff; }
        .cof-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 33px; font-weight: 800; }
        .cof-btn { height: 44px; padding: 0 20px; font-weight: 800; font-size: 14px; display: inline-flex; align-items: center; border: 2.5px solid #23204a; }
        .cof-btn-p { background: #00b8a2; color: #fff; border-radius: 16px 3px 16px 3px; box-shadow: 4px 4px 0 #ffb03a; }
        .cof-btn-s { background: #fff; color: #23204a; border-radius: 3px 16px 3px 16px; box-shadow: 4px 4px 0 #d8f2ee; }
        .cof-btn-d { background: #ff5d8f; color: #fff; border-radius: 16px 3px 16px 3px; box-shadow: 4px 4px 0 #ffe3ee; }
        .cof-pill { font-size: 12px; font-weight: 800; padding: 4px 11px; border: 2px solid #23204a; border-radius: 12px 2px 12px 2px; }
        .cof-ok { background: #d8f2ee; } .cof-bad { background: #ff5d8f; color: #fff !important; } .cof-mut { background: #fff3c9; }
        .cof-row { border-top: 2px solid #23204a; }
        .cof-th { padding: 11px 15px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; background: #23204a; color: #fffdf6; }
        .cof-field { background: #fff; border: 2.5px solid #23204a; border-radius: 2px 14px 2px 14px; padding: 10px 14px; font-size: 14px; }
        .cof-nav { padding: 10px; border-radius: 18px 2px 18px 2px; }
        .cof-nav a { padding: 8px 13px; font-size: 14px; font-weight: 800; color: #55517a; display: block; }
        .cof-nav .on { color: #23204a; background: #fff3c9; border: 2px solid #23204a; border-radius: 10px 2px 10px 2px; }

        /* ═══════ 24 · DECO — Art Deco Gatsby luxe ═══════ */
        .d-deco { background: #f6f1e5; color: #241f18; font-family: 'Hanken Grotesk', sans-serif; }
        .d-deco .hd { font-family: 'Fraunces', serif; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
        .dec-frame { border: 1px solid #a8823c; outline: 1px solid #a8823c; outline-offset: 3px; background: #fdfaf1; }
        .dec-fan { height: 10px; background: repeating-conic-gradient(from -90deg at 50% 100%, #a8823c 0deg 6deg, transparent 6deg 18deg); width: 84px; margin: 0 auto; }
        .dec-panel, .dec-modal, .dec-nav, .dec-glass { border: 1px solid #a8823c; outline: 1px solid #a8823c; outline-offset: 3px; background: #fdfaf1; }
        .dec-lbl { font-size: 10.5px; font-weight: 600; letter-spacing: .3em; text-transform: uppercase; color: #a8823c; }
        .dec-num { font-family: 'Fraunces', serif; font-size: 38px; font-weight: 600; }
        .dec-stat { padding: 16px 18px; text-align: center; }
        .dec-btn { height: 44px; padding: 0 26px; border-radius: 0; font-weight: 600; font-size: 12px; letter-spacing: .22em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .dec-btn-p { background: #241f18; color: #e9d9ae; border: 1px solid #a8823c; }
        .dec-btn-s { background: transparent; border: 1px solid #a8823c; color: #241f18; }
        .dec-btn-d { background: transparent; border: 1px solid #8f3d3d; color: #8f3d3d; }
        .dec-pill { font-size: 10px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; padding: 4px 12px; border: 1px solid currentColor; }
        .dec-ok { color: #241f18; } .dec-bad { color: #8f3d3d; } .dec-mut { color: #a8823c; }
        .dec-row { border-top: 1px solid #d9c691; }
        .dec-th { padding: 11px 16px; font-size: 10px; font-weight: 600; letter-spacing: .26em; text-transform: uppercase; color: #a8823c; border-bottom: 3px double #a8823c; }
        .dec-field { background: #fdfaf1; border: 1px solid #a8823c; border-radius: 0; padding: 11px 15px; font-size: 14px; color: #4a4335; }
        .dec-nav { padding: 12px; text-align: center; }
        .dec-nav a { padding: 8px 0; font-size: 12px; letter-spacing: .22em; text-transform: uppercase; color: #776a4e; display: block; }
        .dec-nav .on { color: #241f18; font-weight: 600; }
        .dec-nav .on span { border-bottom: 1px solid #a8823c; padding-bottom: 3px; }

        /* ═══════ 25 · FJORD — Scandinavian hygge ═══════ */
        .d-fjord { background: #f1ede6; color: #3f3b36; font-family: 'Hanken Grotesk', sans-serif; }
        .d-fjord .hd { font-family: 'Hanken Grotesk', sans-serif; font-weight: 600; letter-spacing: -.01em; }
        .fjo-stat, .fjo-panel, .fjo-modal, .fjo-nav, .fjo-glass { background: #faf7f1; border-radius: 18px; box-shadow: 0 1px 3px rgb(63 59 54 / .06); }
        .fjo-stat { padding: 17px 19px; }
        .fjo-stat:nth-child(1) { background: #e5e9e8; } .fjo-stat:nth-child(2) { background: #ece5dc; }
        .fjo-stat:nth-child(3) { background: #e8ebe2; } .fjo-stat:nth-child(4) { background: #ede4e2; }
        .fjo-num { font-size: 30px; font-weight: 600; color: #57636b; }
        .fjo-btn { height: 44px; padding: 0 22px; border-radius: 14px; font-weight: 600; font-size: 14.5px; display: inline-flex; align-items: center; }
        .fjo-btn-p { background: #57636b; color: #f6f3ec; }
        .fjo-btn-s { background: #e7e1d6; color: #4d483f; }
        .fjo-btn-d { background: #ead9d5; color: #8a5247; }
        .fjo-pill { font-size: 12.5px; font-weight: 600; padding: 5px 13px; border-radius: 10px; }
        .fjo-ok { background: #dfe7dc; color: #4d6349; } .fjo-bad { background: #ead9d5; color: #8a5247; } .fjo-mut { background: #e9e3d8; color: #6f6a5e; }
        .fjo-row { border-top: 1px solid #e6dfd3; }
        .fjo-th { padding: 13px 18px; font-size: 12px; font-weight: 600; color: #92897a; }
        .fjo-field { background: #faf7f1; border: 1px solid #ddd4c4; border-radius: 12px; padding: 11px 15px; font-size: 14.5px; color: #5c564c; }
        .fjo-nav { padding: 10px; }
        .fjo-nav a { border-radius: 12px; padding: 9px 14px; font-size: 14.5px; font-weight: 500; color: #837b6d; display: block; }
        .fjo-nav .on { background: #e5e9e8; color: #57636b; font-weight: 600; }

        /* ═══════ 26 · NEON — cyberpunk noir ═══════ */
        .d-neon { background: #0b0e14; color: #d7e3ea; font-family: 'Hanken Grotesk', sans-serif; }
        .d-neon .hd { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .neo-box, .neo-stat, .neo-panel, .neo-modal, .neo-nav, .neo-glass { background: #10141d; border: 1px solid #1f2733; border-radius: 6px; }
        .neo-stat { padding: 14px 16px; border-top: 2px solid #22e0e8; box-shadow: 0 -6px 18px -8px rgb(34 224 232 / .5); }
        .neo-stat:nth-child(2) { border-top-color: #ff3d81; box-shadow: 0 -6px 18px -8px rgb(255 61 129 / .5); }
        .neo-stat:nth-child(3) { border-top-color: #9df23a; box-shadow: 0 -6px 18px -8px rgb(157 242 58 / .5); }
        .neo-stat:nth-child(4) { border-top-color: #ffb03a; box-shadow: 0 -6px 18px -8px rgb(255 176 58 / .5); }
        .neo-num { font-family: 'Schibsted Grotesk', sans-serif; font-size: 30px; font-weight: 800; color: #22e0e8; text-shadow: 0 0 14px rgb(34 224 232 / .55); }
        .neo-stat:nth-child(2) .neo-num { color: #ff3d81; text-shadow: 0 0 14px rgb(255 61 129 / .55); }
        .neo-stat:nth-child(3) .neo-num { color: #9df23a; text-shadow: 0 0 14px rgb(157 242 58 / .5); }
        .neo-stat:nth-child(4) .neo-num { color: #ffb03a; text-shadow: 0 0 14px rgb(255 176 58 / .5); }
        .neo-btn { height: 40px; padding: 0 18px; border-radius: 4px; font-weight: 700; font-size: 12.5px; letter-spacing: .12em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .neo-btn-p { background: #22e0e8; color: #0b0e14; box-shadow: 0 0 18px rgb(34 224 232 / .45); }
        .neo-btn-s { background: transparent; border: 1px solid #3a4657; color: #d7e3ea; }
        .neo-btn-d { background: transparent; border: 1px solid #ff3d81; color: #ff3d81; box-shadow: 0 0 14px rgb(255 61 129 / .25); }
        .neo-pill { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; padding: 3px 9px; border-radius: 3px; border: 1px solid currentColor; }
        .neo-ok { color: #9df23a; } .neo-bad { color: #ff3d81; } .neo-mut { color: #7d8a99; }
        .neo-row { border-top: 1px solid #1f2733; }
        .neo-th { padding: 11px 15px; font-size: 10.5px; font-weight: 700; letter-spacing: .18em; text-transform: uppercase; color: #22e0e8; }
        .neo-field { background: #0d1119; border: 1px solid #2a3441; border-radius: 4px; padding: 10px 13px; font-size: 13.5px; color: #aebbc7; }
        .neo-nav { padding: 8px; }
        .neo-nav a { border-radius: 4px; padding: 8px 13px; font-size: 12.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #7d8a99; display: block; }
        .neo-nav .on { color: #22e0e8; background: rgb(34 224 232 / .08); box-shadow: inset 2px 0 0 #22e0e8; }

        /* ═══════ 27 · WASH — watercolor painterly ═══════ */
        .d-wash { background:
            radial-gradient(34rem 22rem at 12% 6%, rgb(199 106 140 / .16), transparent 55%),
            radial-gradient(30rem 20rem at 88% 12%, rgb(140 127 224 / .14), transparent 55%),
            radial-gradient(38rem 26rem at 55% 105%, rgb(91 146 189 / .13), transparent 58%),
            #fdfcf9; color: #4a4046; font-family: 'Hanken Grotesk', sans-serif; }
        .d-wash .hd { font-family: 'Fraunces', serif; font-weight: 500; letter-spacing: .01em; }
        .was-stat, .was-panel, .was-modal, .was-nav, .was-glass { background: rgb(255 255 255 / .65); border-radius: 18px 22px 16px 24px; box-shadow: 0 4px 18px rgb(120 90 120 / .08); }
        .was-stat { padding: 17px 19px; position: relative; overflow: hidden; }
        .was-stat::after { content: ''; position: absolute; right: -18px; bottom: -18px; width: 74px; height: 74px; border-radius: 50%;
            filter: blur(16px); opacity: .5; }
        .was-stat:nth-child(1)::after { background: #c76a8c; } .was-stat:nth-child(2)::after { background: #8c7fe0; }
        .was-stat:nth-child(3)::after { background: #5b92bd; } .was-stat:nth-child(4)::after { background: #d49a4e; }
        .was-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 500; color: #7c4468; }
        .was-btn { height: 42px; padding: 0 22px; border-radius: 18px 22px 16px 24px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
        .was-btn-p { background: linear-gradient(120deg, rgb(199 106 140 / .9), rgb(140 127 224 / .85)); color: #fff; box-shadow: 0 6px 16px rgb(160 90 140 / .3); }
        .was-btn-s { background: rgb(255 255 255 / .8); color: #6b5762; box-shadow: 0 3px 12px rgb(120 90 120 / .12); }
        .was-btn-d { background: rgb(255 255 255 / .8); color: #a4506a; box-shadow: 0 3px 12px rgb(164 80 106 / .16); }
        .was-pill { font-size: 12px; font-weight: 600; padding: 4px 13px; border-radius: 14px 18px 12px 18px; }
        .was-ok { background: rgb(126 150 112 / .18); color: #55663f; } .was-bad { background: rgb(164 80 106 / .14); color: #a4506a; } .was-mut { background: rgb(120 90 120 / .1); color: #6b5762; }
        .was-row { border-top: 1px solid rgb(120 90 120 / .12); }
        .was-th { padding: 12px 17px; font-size: 11.5px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #a08a98; }
        .was-field { background: rgb(255 255 255 / .8); border: 1px solid rgb(120 90 120 / .18); border-radius: 14px 18px 12px 18px; padding: 10px 15px; font-size: 14px; color: #6b5762; }
        .was-nav { padding: 10px; }
        .was-nav a { border-radius: 14px 18px 12px 18px; padding: 8px 15px; font-size: 14px; font-weight: 500; color: #8a7583; display: block; }
        .was-nav .on { background: rgb(199 106 140 / .16); color: #7c4468; font-weight: 600; }

        /* ═══════ 28 · FIELD — flat 2.0 ═══════ */
        .d-field { background: #f4efe6; color: #221d16; font-family: 'Schibsted Grotesk', sans-serif; }
        .d-field .hd { font-weight: 800; letter-spacing: -.02em; }
        .fie-stat, .fie-panel, .fie-modal, .fie-nav, .fie-glass { border-radius: 0; border: 0; box-shadow: none; }
        .fie-stat { padding: 18px 20px; color: #f4efe6; }
        .fie-stat:nth-child(1) { background: #6b3358; } .fie-stat:nth-child(2) { background: #274d3d; }
        .fie-stat:nth-child(3) { background: #b3661f; } .fie-stat:nth-child(4) { background: #221d16; }
        .fie-stat > div { opacity: 1 !important; }
        .fie-num { font-size: 34px; font-weight: 800; }
        .fie-panel, .fie-modal { background: #fffdf8; }
        .fie-glass { background: #fffdf8; }
        .fie-btn { height: 44px; padding: 0 24px; border-radius: 0; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; }
        .fie-btn-p { background: #6b3358; color: #fff; }
        .fie-btn-s { background: #e3dccd; color: #221d16; }
        .fie-btn-d { background: #b3402e; color: #fff; }
        .fie-pill { font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 0; color: #fff; }
        .fie-ok { background: #274d3d; } .fie-bad { background: #b3402e; } .fie-mut { background: #8a8171; }
        .fie-row { border-top: 4px solid #f4efe6; }
        .fie-th { padding: 12px 18px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; background: #221d16; color: #f4efe6; }
        .fie-field { background: #fffdf8; border: 0; border-bottom: 4px solid #221d16; border-radius: 0; padding: 11px 15px; font-size: 14.5px; }
        .fie-nav { padding: 0; overflow: hidden; }
        .fie-nav a { padding: 11px 16px; font-size: 14px; font-weight: 700; color: #6f6552; display: block; background: #ece5d5; margin-bottom: 3px; }
        .fie-nav .on { background: #6b3358; color: #fff; }

        /* ═══════ 29 · VOLUME — maximalist editorial ═══════ */
        .d-volume { background: #f3ede2; color: #1c1712; font-family: 'Hanken Grotesk', sans-serif; }
        .d-volume .hd { font-family: 'Fraunces', serif; font-weight: 700; letter-spacing: -.01em; }
        .vol-mono { font-family: ui-monospace, Menlo, monospace; font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; }
        .vol-stat { border-top: 6px solid #1c1712; padding-top: 10px; position: relative; }
        .vol-num { font-family: 'Fraunces', serif; font-size: 56px; font-weight: 700; line-height: .95; letter-spacing: -.02em; }
        .vol-stat:nth-child(2) .vol-num { color: #c2372c; }
        .vol-panel, .vol-modal, .vol-nav, .vol-glass { background: #fbf7ec; border: 2px solid #1c1712; }
        .vol-btn { height: 46px; padding: 0 24px; border-radius: 0; font-weight: 800; font-size: 15px; display: inline-flex; align-items: center; border: 2px solid #1c1712; }
        .vol-btn-p { background: #c2372c; color: #fbf7ec; box-shadow: 5px 5px 0 #1c1712; }
        .vol-btn-s { background: #fbf7ec; color: #1c1712; }
        .vol-btn-d { background: #1c1712; color: #fbf7ec; }
        .vol-pill { font-family: ui-monospace, Menlo, monospace; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 4px 10px; border: 2px solid #1c1712; }
        .vol-ok { background: #e8dfc8; } .vol-bad { background: #c2372c; color: #fbf7ec !important; border-color: #c2372c; } .vol-mut { background: #fbf7ec; }
        .vol-row { border-top: 2px solid #1c1712; }
        .vol-th { padding: 10px 16px; font-family: ui-monospace, Menlo, monospace; font-size: 10.5px; letter-spacing: .14em; text-transform: uppercase; border-bottom: 6px solid #1c1712; }
        .vol-field { background: #fbf7ec; border: 2px solid #1c1712; border-radius: 0; padding: 11px 15px; font-size: 15px; }
        .vol-nav { padding: 0; }
        .vol-nav a { padding: 10px 16px; font-family: 'Fraunces', serif; font-size: 18px; font-weight: 700; color: #6f6350; display: block; border-bottom: 2px solid #1c1712; }
        .vol-nav a:last-child { border-bottom: 0; }
        .vol-nav .on { color: #1c1712; background: #e8dfc8; }
        .vol-nav .on::after { content: ' →'; color: #c2372c; }

        /* ═══════ 30 · KYOTO — Zen Japandi ═══════ */
        .d-kyoto { background: #f5f2ec; color: #45413a; font-family: 'Hanken Grotesk', sans-serif; }
        .d-kyoto .hd { font-family: 'Fraunces', serif; font-weight: 500; letter-spacing: .02em; }
        .kyo-enso { display: inline-block; width: 26px; height: 26px; border: 2px solid #8a7a63; border-radius: 50%;
            border-top-color: transparent; transform: rotate(-40deg); }
        .kyo-lbl { font-size: 11px; font-weight: 500; letter-spacing: .28em; text-transform: uppercase; color: #8a7a63; }
        .kyo-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 500; color: #45413a; }
        .kyo-stat { border-left: 2px solid #c9beab; padding: 4px 0 4px 18px; }
        .kyo-panel, .kyo-modal, .kyo-nav, .kyo-glass { background: #faf8f3; border: 1px solid #e5dfd2; border-radius: 2px; }
        .kyo-btn { height: 42px; padding: 0 24px; border-radius: 2px; font-weight: 500; font-size: 13.5px; letter-spacing: .08em; display: inline-flex; align-items: center; }
        .kyo-btn-p { background: #514a3f; color: #f5f2ec; }
        .kyo-btn-s { background: transparent; border: 1px solid #b5aa94; color: #514a3f; }
        .kyo-btn-d { background: transparent; border: 1px solid #b5aa94; color: #92564a; }
        .kyo-pill { font-size: 11.5px; font-weight: 500; letter-spacing: .12em; padding: 4px 12px; border-radius: 2px; background: #ece7db; }
        .kyo-ok { color: #5c6b4f; } .kyo-bad { color: #92564a; } .kyo-mut { color: #7d7362; }
        .kyo-row { border-top: 1px solid #e5dfd2; }
        .kyo-th { padding: 12px 18px; font-size: 10.5px; font-weight: 500; letter-spacing: .24em; text-transform: uppercase; color: #8a7a63; }
        .kyo-field { background: #faf8f3; border: 0; border-bottom: 1px solid #b5aa94; border-radius: 0; padding: 10px 4px; font-size: 14.5px; color: #5c564a; }
        .kyo-nav { padding: 14px 16px; }
        .kyo-nav a { padding: 9px 0; font-size: 14px; letter-spacing: .06em; color: #8a8172; display: block; }
        .kyo-nav .on { color: #45413a; }
        .kyo-nav .on::before { content: ''; display: inline-block; width: 18px; height: 1px; background: #92564a; vertical-align: middle; margin-right: 10px; }
    </style>

    {{-- ————— Gallery intro + quick nav ————— --}}
    <div class="g-band" style="padding-bottom: 40px;">
        <div class="g-band-inner">
            <div class="g-title" style="color:#d9a9c6;">Design directions</div>
            <h1 style="font-family:'Fraunces',serif; font-size:34px; font-weight:600; margin-top:14px;">Thirty complete languages, same components.</h1>
            <p class="g-caption" style="margin-top:12px;">Each band below renders the identical sample set — header and stats, a table, buttons and pills, a panel and modal preview, form fields, and the nav treatment — fully styled in one direction. Pick the one that feels like BookTheStyle; it will be implemented app-wide and this temporary tab removed.</p>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:22px;">
                @foreach ([['#lumen', '1 · Lumen'], ['#journal', '2 · Journal'], ['#meridian', '3 · Meridian'], ['#halo', '4 · Halo'], ['#velvet', '5 · Velvet'], ['#studio', '6 · Studio'], ['#aurora', '7 · Aurora'], ['#manor', '8 · Manor'], ['#bloom', '9 · Bloom'], ['#vertex', '10 · Vertex'], ['#glacier', '11 · Glacier'], ['#marble', '12 · Marble'], ['#bolt', '13 · Bolt'], ['#chrome', '14 · Chrome'], ['#gazette', '15 · Gazette'], ['#fern', '16 · Fern'], ['#werkstatt', '17 · Werkstatt'], ['#gilt', '18 · Gilt'], ['#sketch', '19 · Sketch'], ['#grid', '20 · Grid'], ['#dough', '21 · Dough'], ['#console', '22 · Console'], ['#confetti', '23 · Confetti'], ['#deco', '24 · Deco'], ['#fjord', '25 · Fjord'], ['#neon', '26 · Neon'], ['#wash', '27 · Wash'], ['#field', '28 · Field'], ['#volume', '29 · Volume'], ['#kyoto', '30 · Kyoto']] as [$href, $label])
                    <a href="{{ $href }}" style="border:1px solid rgb(255 255 255 / .2); border-radius:99px; padding:7px 16px; font-size:13.5px; color:#efeae4;">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ═══════════ 1 · LUMEN ═══════════ --}}
    <section id="lumen" class="g-band d-lumen">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">1 · Lumen — light glass premium</h2>
                <p style="font-size:14px; color:#6c645c;">Warm light base, Apple liquid-glass chrome and widget tiles, soft depth, plum jewel. Linear craft × Apple glass × Arc warmth.</p>
            </div>

            <div class="g-label" style="color:#746c62;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#6b3358;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:6px;">Today at the salon</div>
                </div>
                <span class="lum-btn lum-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-top:18px;">
                @foreach ($stats as $s)
                    <div class="lum-tile">
                        <div style="font-size:13px; font-weight:500; color:#6c645c;">{{ $s['label'] }}</div>
                        <div class="lum-num" style="margin-top:8px; color:#241f1a;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#746c62; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#746c62;">Table</div>
                    <div class="lum-glass" style="border-radius:16px; overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr style="font-size:11.5px; text-transform:uppercase; letter-spacing:.06em; color:#746c62;">
                                <th scope="col" style="padding:12px 16px;">Time</th><th scope="col" style="padding:12px 8px;">Client</th><th scope="col" style="padding:12px 8px;">Service</th><th scope="col" style="padding:12px 16px;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="lum-row">
                                        <td style="padding:12px 16px; color:#746c62;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; color:#57504a;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px;">
                                            <span class="lum-pill" style="background:{{ $r['kind'] === 'ok' ? 'rgb(62 92 58 / .12)' : ($r['kind'] === 'bad' ? 'rgb(162 58 58 / .1)' : 'rgb(63 47 38 / .07)') }}; color:{{ $r['kind'] === 'ok' ? '#3e5c3a' : ($r['kind'] === 'bad' ? '#a23a3a' : '#57504a') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#746c62;">Buttons + pills</div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="lum-btn lum-btn-p">Confirm booking</span>
                        <span class="lum-btn lum-btn-s">Reschedule</span>
                        <span class="lum-btn lum-btn-d">Cancel booking</span>
                        <span class="lum-pill" style="background:rgb(130 76 113 / .12); color:#6b3358;">New client</span>
                        <span class="lum-pill" style="background:rgb(53 96 136 / .1); color:#356088;">Arrived</span>
                    </div>

                    <div class="g-label" style="color:#746c62;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:480px;">
                        <div class="lum-field">Amelia Hart</div>
                        <div class="lum-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#746c62;">Panel + modal</div>
                    <div class="lum-glass" style="border-radius:16px; padding:18px;">
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; color:#57504a; margin-top:6px; line-height:1.55;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(31 22 17 / .3); padding:26px; margin-top:14px;">
                        <div class="lum-glass" style="border-radius:16px; padding:20px; background:rgb(252 250 246 / .92);">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#57504a; margin:8px 0 14px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <span class="lum-btn lum-btn-s" style="height:34px;">Keep it</span>
                                <span class="lum-btn lum-btn-d" style="height:34px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#746c62;">Nav treatment</div>
                    <div class="lum-glass lum-nav" style="border-radius:16px; padding:10px; max-width:220px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 2 · JOURNAL ═══════════ --}}
    <section id="journal" class="g-band d-journal">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">2 · Journal — editorial warm</h2>
                <p style="font-size:14px; color:#6c645c;">Cream paper, display serif, hairline rules, almost no boxes. A well-set magazine page. Kinfolk energy, Marblism warmth.</p>
            </div>

            <div class="g-label" style="color:#8a7a60;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="jou-over">Saturday, 12 July</div>
                    <div class="hd" style="font-size:34px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="jou-btn jou-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:26px; margin-top:26px;">
                @foreach ($stats as $s)
                    <div class="jou-rule" style="padding-top:12px;">
                        <div style="font-size:11.5px; font-weight:600; letter-spacing:.12em; text-transform:uppercase; color:#8a7a60;">{{ $s['label'] }}</div>
                        <div class="jou-num" style="margin-top:10px;">{{ $s['value'] }}</div>
                        <div style="font-size:13px; color:#746c62; margin-top:7px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a7a60;">Table</div>
                    <table style="width:100%; text-align:left; font-size:14.5px; border-top:2px solid #262019;">
                        <thead><tr style="font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:#8a7a60;">
                            <th scope="col" style="padding:12px 0;">Time</th><th scope="col" style="padding:12px 0;">Client</th><th scope="col" style="padding:12px 0;">Service</th><th scope="col" style="padding:12px 0; text-align:right;">Status</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="jou-rule">
                                    <td style="padding:15px 0; font-family:'Fraunces',serif; font-size:15px;">{{ $r['time'] }}</td>
                                    <td style="padding:15px 0; font-weight:600;">{{ $r['client'] }}</td>
                                    <td style="padding:15px 0; color:#57504a;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                    <td style="padding:15px 0; text-align:right;">
                                        <span class="jou-pill" style="color:{{ $r['kind'] === 'ok' ? '#3e5c3a' : ($r['kind'] === 'bad' ? '#a23a3a' : '#8a7a60') }};">{{ $r['status'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="g-label" style="color:#8a7a60;">Buttons + pills</div>
                    <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
                        <span class="jou-btn jou-btn-p">Confirm booking</span>
                        <span class="jou-btn jou-btn-s">Reschedule</span>
                        <span class="jou-btn jou-btn-d">Cancel booking</span>
                        <span class="jou-pill" style="color:#6b3358;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8a7a60;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:22px; max-width:480px;">
                        <div>
                            <div class="jou-over" style="font-size:10.5px;">Client</div>
                            <div class="jou-field">Amelia Hart</div>
                        </div>
                        <div>
                            <div class="jou-over" style="font-size:10.5px;">Service</div>
                            <div class="jou-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a7a60;">Panel + modal</div>
                    <div class="jou-rule" style="border-top-width:2px; border-color:#262019; padding-top:14px;">
                        <div class="jou-over">Next up</div>
                        <p class="hd" style="font-size:19px; margin-top:8px; line-height:1.4;">Ruth Okafor, full colour with Simone at 10:30.</p>
                        <p style="font-size:13.5px; color:#746c62; margin-top:6px;">Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(38 32 25 / .35); padding:26px; margin-top:18px;">
                        <div style="background:#faf6ed; padding:24px; border-top:3px solid #262019;">
                            <div class="hd" style="font-size:19px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#57504a; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:14px;">
                                <span class="jou-btn jou-btn-s" style="height:36px;">Keep it</span>
                                <span class="jou-btn jou-btn-d">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a7a60;">Nav treatment</div>
                    <div class="jou-nav" style="max-width:200px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 3 · MERIDIAN ═══════════ --}}
    <section id="meridian" class="g-band d-meridian">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">3 · Meridian — clean modern dashboard</h2>
                <p style="font-size:14px; color:#6a655c;">Stripe/Linear: crisp surfaces, precise grid, tabular numbers, quiet gradient accent. The beautiful SaaS dashboard.</p>
            </div>

            <div class="g-label" style="color:#8a8577;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="hd" style="font-size:20px;">Today</div>
                    <div style="font-size:13px; color:#6a655c; margin-top:3px;">Saturday, 12 July · 4 stylists on</div>
                </div>
                <span class="mer-btn mer-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-top:16px;">
                @foreach ($stats as $i => $s)
                    <div class="mer-card" style="padding:13px 15px;">
                        <div style="font-size:12.5px; font-weight:500; color:#6a655c;">{{ $s['label'] }}</div>
                        <div style="display:flex; align-items:baseline; gap:8px; margin-top:7px;">
                            <span class="mer-num">{{ $s['value'] }}</span>
                            <span class="mer-delta" style="background:{{ $i === 3 ? '#eaf3ea' : '#f1eef8' }}; color:{{ $i === 3 ? '#2e6b34' : '#5b3a7e' }};">{{ $i === 3 ? '+12%' : '+2' }}</span>
                        </div>
                        <div style="font-size:12px; color:#8a8577; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a8577;">Table</div>
                    <div class="mer-card" style="overflow:hidden;">
                        <table style="width:100%; text-align:left;">
                            <thead><tr style="font-size:11.5px; text-transform:uppercase; letter-spacing:.05em; color:#8a8577; background:#fcfcfa;">
                                <th scope="col" style="padding:9px 14px;">Time</th><th scope="col" style="padding:9px 14px;">Client</th><th scope="col" style="padding:9px 14px;">Service</th><th scope="col" style="padding:9px 14px; text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="mer-row">
                                        <td style="padding:9px 14px; font-size:13px; color:#6a655c; font-variant-numeric:tabular-nums;">{{ $r['time'] }}</td>
                                        <td style="padding:9px 14px; font-size:13.5px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:9px 14px; font-size:13.5px; color:#57534a;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:9px 14px; text-align:right;">
                                            <span class="mer-pill" style="background:{{ $r['kind'] === 'ok' ? '#eaf3ea' : ($r['kind'] === 'bad' ? '#fbeaea' : '#f2f0eb') }}; color:{{ $r['kind'] === 'ok' ? '#2e6b34' : ($r['kind'] === 'bad' ? '#b03636' : '#6a655c') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a8577;">Buttons + pills</div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <span class="mer-btn mer-btn-p">Confirm booking</span>
                        <span class="mer-btn mer-btn-s">Reschedule</span>
                        <span class="mer-btn mer-btn-d">Cancel booking</span>
                        <span class="mer-pill" style="background:#f1eef8; color:#5b3a7e;">New client</span>
                        <span class="mer-pill" style="background:#e9f1f8; color:#33618c;">Arrived</span>
                    </div>

                    <div class="g-label" style="color:#8a8577;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; max-width:440px;">
                        <div class="mer-field">Amelia Hart</div>
                        <div class="mer-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a8577;">Panel + modal</div>
                    <div class="mer-card" style="padding:15px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div class="hd" style="font-size:14.5px;">Next up</div>
                            <span class="mer-pill" style="background:#fdf3e4; color:#96660f;">Allergy on file</span>
                        </div>
                        <p style="font-size:13px; color:#57534a; margin-top:7px; line-height:1.55;">Ruth Okafor · Full colour with Simone, 10:30 AM.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(20 18 15 / .4); padding:26px; margin-top:14px;">
                        <div class="mer-card" style="padding:18px; border-radius:12px; box-shadow:0 16px 40px rgb(20 18 15 / .18);">
                            <div class="hd" style="font-size:15px;">Cancel this booking?</div>
                            <p style="font-size:13px; color:#57534a; margin:7px 0 14px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <span class="mer-btn mer-btn-s" style="height:32px;">Keep it</span>
                                <span class="mer-btn mer-btn-d" style="height:32px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a8577;">Nav treatment</div>
                    <div class="mer-card mer-nav" style="padding:8px; max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 4 · HALO ═══════════ --}}
    <section id="halo" class="g-band d-halo">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">4 · Halo — soft depth</h2>
                <p style="font-size:14px; color:#6c5f66;">Aura gradients, floating borderless layers, gradient figures, dreamy but light. Cosmos beauty in daylight.</p>
            </div>

            <div class="g-label" style="color:#8a7a84;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:#6b3358;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:28px; margin-top:6px;">Today at the salon</div>
                </div>
                <span class="halo-btn halo-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="halo-float" style="padding:17px 19px;">
                        <div style="font-size:13px; font-weight:500; color:#6c5f66;">{{ $s['label'] }}</div>
                        <div class="halo-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#8a7a84; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a7a84;">Table</div>
                    <div class="halo-float" style="overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr style="font-size:11.5px; text-transform:uppercase; letter-spacing:.08em; color:#8a7a84;">
                                <th scope="col" style="padding:13px 18px;">Time</th><th scope="col" style="padding:13px 8px;">Client</th><th scope="col" style="padding:13px 8px;">Service</th><th scope="col" style="padding:13px 18px;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="halo-row">
                                        <td style="padding:13px 18px; color:#8a7a84;">{{ $r['time'] }}</td>
                                        <td style="padding:13px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:13px 8px; color:#57504a;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:13px 18px;">
                                            <span class="halo-pill" style="color:{{ $r['kind'] === 'ok' ? '#3e5c3a' : ($r['kind'] === 'bad' ? '#b03636' : '#6c5f66') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a7a84;">Buttons + pills</div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="halo-btn halo-btn-p">Confirm booking</span>
                        <span class="halo-btn halo-btn-s">Reschedule</span>
                        <span class="halo-btn halo-btn-d">Cancel booking</span>
                        <span class="halo-pill" style="color:#6b3358;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8a7a84;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:480px;">
                        <div class="halo-field">Amelia Hart</div>
                        <div class="halo-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a7a84;">Panel + modal</div>
                    <div class="halo-float" style="padding:18px;">
                        <div class="hd" style="font-size:17px;">Next up</div>
                        <p style="font-size:13.5px; color:#57504a; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(50 34 46 / .35); padding:26px; margin-top:14px; border-radius:22px;">
                        <div class="halo-float" style="padding:22px;">
                            <div class="hd" style="font-size:17px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#57504a; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end;">
                                <span class="halo-btn halo-btn-s" style="height:36px;">Keep it</span>
                                <span class="halo-btn halo-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a7a84;">Nav treatment</div>
                    <div class="halo-nav" style="max-width:200px; display:flex; flex-direction:column; gap:6px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 5 · VELVET ═══════════ --}}
    <section id="velvet" class="g-band d-velvet">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">5 · Velvet — boutique tactile</h2>
                <p style="font-size:14px; color:#7d6a5d;">Plum, clay, and cream; organic rounded shapes; pressable, tactile controls. The salon interior as an interface.</p>
            </div>

            <div class="g-label" style="color:#a08874;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:#a2542a;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:28px; margin-top:6px; color:#3a2233;">Today at the salon</div>
                </div>
                <span class="vel-btn vel-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="vel-panel" style="padding:17px 20px;">
                        <div style="font-size:13px; font-weight:500; color:#7d6a5d;">{{ $s['label'] }}</div>
                        <div class="vel-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#a08874; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#a08874;">Table</div>
                    <div class="vel-panel" style="overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14.5px;">
                            <thead><tr style="font-size:11.5px; text-transform:uppercase; letter-spacing:.08em; color:#a08874;">
                                <th scope="col" style="padding:13px 20px;">Time</th><th scope="col" style="padding:13px 8px;">Client</th><th scope="col" style="padding:13px 8px;">Service</th><th scope="col" style="padding:13px 20px;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="vel-row">
                                        <td style="padding:13px 20px; font-family:'Fraunces',serif; color:#7d6a5d;">{{ $r['time'] }}</td>
                                        <td style="padding:13px 8px; font-weight:600; color:#3a2233;">{{ $r['client'] }}</td>
                                        <td style="padding:13px 8px; color:#5c4a3d;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:13px 20px;">
                                            <span class="vel-pill" style="color:{{ $r['kind'] === 'ok' ? '#3e5c3a' : ($r['kind'] === 'bad' ? '#a23a3a' : '#7d6a5d') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#a08874;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="vel-btn vel-btn-p">Confirm booking</span>
                        <span class="vel-btn vel-btn-s">Reschedule</span>
                        <span class="vel-btn vel-btn-d">Cancel booking</span>
                        <span class="vel-pill" style="color:#6b3358;">New client</span>
                        <span class="vel-pill" style="color:#a2542a;">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#a08874;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:480px;">
                        <div class="vel-field">Amelia Hart</div>
                        <div class="vel-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#a08874;">Panel + modal</div>
                    <div class="vel-panel" style="padding:20px;">
                        <div class="hd" style="font-size:17px; color:#3a2233;">Next up</div>
                        <p style="font-size:13.5px; color:#5c4a3d; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(58 34 51 / .4); padding:26px; margin-top:14px; border-radius:26px;">
                        <div class="vel-panel" style="padding:22px;">
                            <div class="hd" style="font-size:17px; color:#3a2233;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#5c4a3d; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end;">
                                <span class="vel-btn vel-btn-s" style="height:36px;">Keep it</span>
                                <span class="vel-btn vel-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#a08874;">Nav treatment</div>
                    <div class="vel-panel vel-nav" style="padding:10px; max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 6 · STUDIO ═══════════ --}}
    <section id="studio" class="g-band d-studio">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">6 · Studio — mono minimal</h2>
                <p style="font-size:14px; color:#8a877f;">Near-monochrome, ultra-restrained, one quiet plum accent, tiny precise labels. Muji and Braun calm — the anti-loud.</p>
            </div>

            <div class="g-label" style="color:#8a877f;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="stu-lbl">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="stu-btn stu-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:32px; margin-top:28px;">
                @foreach ($stats as $s)
                    <div class="stu-rule" style="padding-top:14px;">
                        <div class="stu-lbl">{{ $s['label'] }}</div>
                        <div class="stu-num" style="margin-top:12px;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#8a877f; margin-top:8px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a877f;">Table</div>
                    <table style="width:100%; text-align:left; font-size:13.5px; border-top:1px solid #1a1918;">
                        <thead><tr>
                            <th scope="col" class="stu-lbl" style="padding:12px 0; font-size:10px;">Time</th><th scope="col" class="stu-lbl" style="padding:12px 0; font-size:10px;">Client</th><th scope="col" class="stu-lbl" style="padding:12px 0; font-size:10px;">Service</th><th scope="col" class="stu-lbl" style="padding:12px 0; font-size:10px; text-align:right;">Status</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="stu-rule">
                                    <td style="padding:14px 0; color:#8a877f; font-variant-numeric:tabular-nums;">{{ $r['time'] }}</td>
                                    <td style="padding:14px 0; font-weight:600;">{{ $r['client'] }}</td>
                                    <td style="padding:14px 0; color:#4a4741;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                    <td style="padding:14px 0; text-align:right;"><span class="stu-pill" style="color:{{ $r['kind'] === 'ok' ? '#1a1918' : ($r['kind'] === 'bad' ? '#8a2e2e' : '#8a877f') }};">{{ $r['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="g-label" style="color:#8a877f;">Buttons + pills</div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="stu-btn stu-btn-p">Confirm booking</span>
                        <span class="stu-btn stu-btn-s">Reschedule</span>
                        <span class="stu-btn stu-btn-d">Cancel booking</span>
                        <span class="stu-pill" style="color:#824c71; border-color:#824c71;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8a877f;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:460px;">
                        <div class="stu-field">Amelia Hart</div>
                        <div class="stu-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a877f;">Panel + modal</div>
                    <div class="stu-rule" style="border-top:1px solid #1a1918; padding-top:14px;">
                        <div class="stu-lbl">Next up</div>
                        <p style="font-size:15px; margin-top:10px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM.<br><span style="color:#8a877f;">Allergy on file — review before mixing.</span></p>
                    </div>
                    <div class="g-stage" style="background:rgb(26 25 24 / .4); padding:26px; margin-top:18px;">
                        <div style="background:#fcfbf8; padding:22px; border-radius:8px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13px; color:#4a4741; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end;">
                                <span class="stu-btn stu-btn-s" style="height:32px;">Keep it</span>
                                <span class="stu-btn stu-btn-d" style="height:32px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a877f;">Nav treatment</div>
                    <div class="stu-nav" style="max-width:180px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 7 · AURORA ═══════════ --}}
    <section id="aurora" class="g-band d-aurora">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">7 · Aurora — vibrant gradient</h2>
                <p style="font-size:14px; color:#6d6377;">Light and clean with bold aurora gradients as the expressive moments. Stripe energy, Cosmos colour — lively yet refined.</p>
            </div>

            <div class="g-label" style="color:#8d8195;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;" class="aur-grad-text">Saturday, 12 July</div>
                    <div class="hd" style="font-size:28px; margin-top:6px;">Today at the salon</div>
                </div>
                <span class="aur-btn aur-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-top:18px;">
                @foreach ($stats as $s)
                    <div class="aur-card" style="padding:15px 17px;">
                        <div style="font-size:13px; font-weight:500; color:#6d6377;">{{ $s['label'] }}</div>
                        <div class="aur-num aur-grad-text" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#8d8195; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8d8195;">Table</div>
                    <div class="aur-card" style="overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr style="font-size:11.5px; text-transform:uppercase; letter-spacing:.06em; color:#8d8195;">
                                <th scope="col" style="padding:12px 16px;">Time</th><th scope="col" style="padding:12px 8px;">Client</th><th scope="col" style="padding:12px 8px;">Service</th><th scope="col" style="padding:12px 16px; text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="aur-row">
                                        <td style="padding:12px 16px; color:#8d8195;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; color:#57504a;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="aur-pill" style="background:{{ $r['kind'] === 'ok' ? 'rgb(110 86 207 / .12)' : ($r['kind'] === 'bad' ? 'rgb(176 54 82 / .1)' : 'rgb(52 33 70 / .06)') }}; color:{{ $r['kind'] === 'ok' ? '#5b3a7e' : ($r['kind'] === 'bad' ? '#b03652' : '#6d6377') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8d8195;">Buttons + pills</div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="aur-btn aur-btn-p">Confirm booking</span>
                        <span class="aur-btn aur-btn-s">Reschedule</span>
                        <span class="aur-btn aur-btn-d">Cancel booking</span>
                        <span class="aur-pill" style="background:linear-gradient(100deg, rgb(110 86 207 / .16), rgb(199 106 140 / .14)); color:#5b3a7e;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8d8195;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:460px;">
                        <div class="aur-field">Amelia Hart</div>
                        <div class="aur-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8d8195;">Panel + modal</div>
                    <div class="aur-card" style="padding:17px;">
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; color:#57504a; margin-top:6px; line-height:1.55;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(34 29 38 / .45); padding:26px; margin-top:14px;">
                        <div class="aur-card" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#57504a; margin:8px 0 14px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <span class="aur-btn aur-btn-s" style="height:34px;">Keep it</span>
                                <span class="aur-btn aur-btn-d" style="height:34px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8d8195;">Nav treatment</div>
                    <div class="aur-card aur-nav" style="padding:9px; max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 8 · MANOR ═══════════ --}}
    <section id="manor" class="g-band d-manor">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">8 · Manor — warm neutral pro</h2>
                <p style="font-size:14px; color:#8d8377;">Greige and taupe, wide-tracked labels, quiet depth. A high-end interior studio — calm, adult, quietly expensive.</p>
            </div>

            <div class="g-label" style="color:#8d8377;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="man-lbl">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="man-btn man-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="man-panel" style="padding:16px 18px;">
                        <div class="man-lbl" style="font-size:10px;">{{ $s['label'] }}</div>
                        <div class="man-num" style="margin-top:10px;">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#8d8377; margin-top:6px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8d8377;">Table</div>
                    <div class="man-panel" style="overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="man-lbl" style="padding:12px 18px; font-size:10px;">Time</th><th scope="col" class="man-lbl" style="padding:12px 8px; font-size:10px;">Client</th><th scope="col" class="man-lbl" style="padding:12px 8px; font-size:10px;">Service</th><th scope="col" class="man-lbl" style="padding:12px 18px; font-size:10px; text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="man-row">
                                        <td style="padding:13px 18px; color:#8d8377;">{{ $r['time'] }}</td>
                                        <td style="padding:13px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:13px 8px; color:#574f45;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:13px 18px; text-align:right;"><span class="man-pill" style="color:{{ $r['kind'] === 'ok' ? '#4c5a43' : ($r['kind'] === 'bad' ? '#8f4636' : '#6f675c') }};">{{ $r['status'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8d8377;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="man-btn man-btn-p">Confirm booking</span>
                        <span class="man-btn man-btn-s">Reschedule</span>
                        <span class="man-btn man-btn-d">Cancel booking</span>
                        <span class="man-pill" style="color:#6b3358;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8d8377;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:460px;">
                        <div class="man-field">Amelia Hart</div>
                        <div class="man-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8d8377;">Panel + modal</div>
                    <div class="man-panel" style="padding:18px;">
                        <div class="man-lbl" style="font-size:10px;">Next up</div>
                        <p style="font-size:14px; margin-top:9px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. <span style="color:#8d8377;">Allergy on file.</span></p>
                    </div>
                    <div class="g-stage" style="background:rgb(46 42 36 / .4); padding:26px; margin-top:14px;">
                        <div class="man-panel" style="padding:22px;">
                            <div class="hd" style="font-size:17px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#574f45; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end;">
                                <span class="man-btn man-btn-s" style="height:34px;">Keep it</span>
                                <span class="man-btn man-btn-d" style="height:34px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8d8377;">Nav treatment</div>
                    <div class="man-nav" style="max-width:190px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 9 · BLOOM ═══════════ --}}
    <section id="bloom" class="g-band d-bloom">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">9 · Bloom — playful boutique</h2>
                <p style="font-size:14px; color:#8d7365;">Marblism-forward: warm, friendly, softly rounded, gently springy. The most human one — approachable but polished.</p>
            </div>

            <div class="g-label" style="color:#a08874;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="blm-squig"></span>
                    <div class="hd" style="font-size:28px; margin-top:8px;">Today at the salon</div>
                    <div style="font-size:13.5px; color:#8d7365; margin-top:4px;">Saturday, 12 July · a lovely full book</div>
                </div>
                <span class="blm-btn blm-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $i => $s)
                    <div class="blm-card" style="padding:17px 19px;">
                        <div style="font-size:13px; font-weight:600; color:#8d7365;">{{ $s['label'] }}</div>
                        <div class="blm-num" style="margin-top:8px; color:{{ ['#824c71','#356088','#3e7a4e','#9a5a2a'][$i] }};">{{ $s['value'] }}</div>
                        <div style="font-size:12.5px; color:#a08874; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#a08874;">Table</div>
                    <div class="blm-card" style="overflow:hidden;">
                        <table style="width:100%; text-align:left; font-size:14.5px;">
                            <thead><tr style="font-size:12px; font-weight:700; color:#a08874;">
                                <th scope="col" style="padding:13px 18px;">Time</th><th scope="col" style="padding:13px 8px;">Client</th><th scope="col" style="padding:13px 8px;">Service</th><th scope="col" style="padding:13px 18px; text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="blm-row">
                                        <td style="padding:13px 18px; color:#8d7365;">{{ $r['time'] }}</td>
                                        <td style="padding:13px 8px; font-weight:700; color:#43332c;">{{ $r['client'] }}</td>
                                        <td style="padding:13px 8px; color:#6b584c;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:13px 18px; text-align:right;">
                                            <span class="blm-pill" style="background:{{ $r['kind'] === 'ok' ? '#e2f0e2' : ($r['kind'] === 'bad' ? '#ffe3e3' : '#f5ead9') }}; color:{{ $r['kind'] === 'ok' ? '#3e7a4e' : ($r['kind'] === 'bad' ? '#a23a3a' : '#9a5a2a') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#a08874;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="blm-btn blm-btn-p">Confirm booking</span>
                        <span class="blm-btn blm-btn-s">Reschedule</span>
                        <span class="blm-btn blm-btn-d">Cancel booking</span>
                        <span class="blm-pill" style="background:#f6e3ef; color:#824c71;">New client</span>
                        <span class="blm-pill" style="background:#e3edf6; color:#356088;">Arrived</span>
                    </div>

                    <div class="g-label" style="color:#a08874;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="blm-field">Amelia Hart</div>
                        <div class="blm-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#a08874;">Panel + modal</div>
                    <div class="blm-card" style="padding:19px;">
                        <div class="hd" style="font-size:17px; color:#43332c;">Next up</div>
                        <p style="font-size:13.5px; color:#6b584c; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — a gentle heads-up before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(67 51 44 / .4); padding:26px; margin-top:14px; border-radius:20px;">
                        <div class="blm-card" style="padding:22px;">
                            <div class="hd" style="font-size:17px; color:#43332c;">Cancel this booking?</div>
                            <p style="font-size:13.5px; color:#6b584c; margin:8px 0 16px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end;">
                                <span class="blm-btn blm-btn-s" style="height:36px;">Keep it</span>
                                <span class="blm-btn blm-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#a08874;">Nav treatment</div>
                    <div class="blm-card blm-nav" style="padding:10px; max-width:200px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ 10 · VERTEX ═══════════ --}}
    <section id="vertex" class="g-band d-vertex">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">10 · Vertex — sharp contemporary</h2>
                <p style="font-size:14px; color:#6a685f;">Crisp near-white, tight geometry, a deep bold plum, glass only on the nav. Linear and Vercel confidence in light.</p>
            </div>

            <div class="g-label" style="color:#8a887e;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="hd" style="font-size:22px;">Today</div>
                    <div style="font-size:13px; color:#6a685f; margin-top:2px;">Saturday, 12 July · 4 stylists on</div>
                </div>
                <span class="vtx-btn vtx-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:10px; margin-top:16px;">
                @foreach ($stats as $s)
                    <div class="vtx-card" style="padding:12px 14px;">
                        <div style="font-size:12px; font-weight:600; color:#6a685f;">{{ $s['label'] }}</div>
                        <div class="vtx-num" style="margin-top:6px;">{{ $s['value'] }}</div>
                        <div style="font-size:11.5px; color:#8a887e; margin-top:4px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a887e;">Table</div>
                    <div class="vtx-card" style="overflow:hidden;">
                        <table style="width:100%; text-align:left;">
                            <thead><tr style="font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#8a887e; border-bottom:1px solid #16150f;">
                                <th scope="col" style="padding:9px 13px;">Time</th><th scope="col" style="padding:9px 13px;">Client</th><th scope="col" style="padding:9px 13px;">Service</th><th scope="col" style="padding:9px 13px; text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="vtx-row">
                                        <td style="padding:9px 13px; font-size:13px; color:#6a685f; font-variant-numeric:tabular-nums;">{{ $r['time'] }}</td>
                                        <td style="padding:9px 13px; font-size:13.5px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:9px 13px; font-size:13.5px; color:#45443e;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:9px 13px; text-align:right;">
                                            <span class="vtx-pill" style="border-color:{{ $r['kind'] === 'ok' ? '#bcd4bc' : ($r['kind'] === 'bad' ? '#e6c1c1' : '#dcdbd5') }}; color:{{ $r['kind'] === 'ok' ? '#2e6b34' : ($r['kind'] === 'bad' ? '#b03636' : '#6a685f') }};">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a887e;">Buttons + pills</div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <span class="vtx-btn vtx-btn-p">Confirm booking</span>
                        <span class="vtx-btn vtx-btn-s">Reschedule</span>
                        <span class="vtx-btn vtx-btn-d">Cancel booking</span>
                        <span class="vtx-pill" style="border-color:#d9c6d3; color:#6b2e57;">New client</span>
                    </div>

                    <div class="g-label" style="color:#8a887e;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; max-width:430px;">
                        <div class="vtx-field">Amelia Hart</div>
                        <div class="vtx-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a887e;">Panel + modal</div>
                    <div class="vtx-card" style="padding:14px;">
                        <div class="hd" style="font-size:14px;">Next up</div>
                        <p style="font-size:13px; color:#45443e; margin-top:6px; line-height:1.55;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(22 21 15 / .45); padding:26px; margin-top:14px;">
                        <div class="vtx-card" style="padding:18px; box-shadow:0 16px 40px rgb(22 21 15 / .2);">
                            <div class="hd" style="font-size:15px;">Cancel this booking?</div>
                            <p style="font-size:13px; color:#45443e; margin:7px 0 14px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <span class="vtx-btn vtx-btn-s">Keep it</span>
                                <span class="vtx-btn vtx-btn-d">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a887e;">Nav treatment</div>
                    <div class="vtx-nav" style="padding:7px; max-width:200px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}">{{ $n }}</a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ GLACIER ═══════════ --}}
    <section id="glacier" class="g-band d-glacier">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">11 · Glacier — full liquid glass</h2>
                <p style="font-size:14px; opacity:.75;">All-in Apple liquid glass: every surface a layered frosted panel over a colourful soft gradient. visionOS in a salon.</p>
            </div>

            <div class="g-label" style="color:#6d6068;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#6b3358;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="gla-btn gla-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="gla-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="gla-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#6d6068;">Table</div>
                    <div class="gla-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="gla-th">Time</th><th scope="col" class="gla-th">Client</th><th scope="col" class="gla-th">Service</th><th scope="col" class="gla-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="gla-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="gla-pill gla-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#6d6068;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="gla-btn gla-btn-p">Confirm booking</span>
                        <span class="gla-btn gla-btn-s">Reschedule</span>
                        <span class="gla-btn gla-btn-d">Cancel booking</span>
                        <span class="gla-pill gla-ok">New client</span>
                        <span class="gla-pill gla-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#6d6068;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="gla-field">Amelia Hart</div>
                        <div class="gla-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#6d6068;">Panel + modal</div>
                    <div class="gla-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(38 33 38 / .4); padding:26px; margin-top:14px;">
                        <div class="gla-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="gla-btn gla-btn-s" style="height:36px;">Keep it</span>
                                <span class="gla-btn gla-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#6d6068;">Nav treatment</div>
                    <div class="gla-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ MARBLE ═══════════ --}}
    <section id="marble" class="g-band d-marble">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">12 · Marble — the Marblism theme</h2>
                <p style="font-size:14px; opacity:.75;">Warm, friendly, characterful: butter and coral, chunky rounded shapes, little bloom motifs. Story-book human, still polished.</p>
            </div>

            <div class="g-label" style="color:#b08a5e;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="mar-bloom"><i style="background:#f08a5d;"></i><i style="background:#f7d774; transform:rotate(90deg);"></i><i style="background:#9cb78f; transform:rotate(180deg);"></i></span>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="mar-btn mar-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="mar-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="mar-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#b08a5e;">Table</div>
                    <div class="mar-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="mar-th">Time</th><th scope="col" class="mar-th">Client</th><th scope="col" class="mar-th">Service</th><th scope="col" class="mar-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="mar-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="mar-pill mar-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#b08a5e;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="mar-btn mar-btn-p">Confirm booking</span>
                        <span class="mar-btn mar-btn-s">Reschedule</span>
                        <span class="mar-btn mar-btn-d">Cancel booking</span>
                        <span class="mar-pill mar-ok">New client</span>
                        <span class="mar-pill mar-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#b08a5e;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="mar-field">Amelia Hart</div>
                        <div class="mar-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#b08a5e;">Panel + modal</div>
                    <div class="mar-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(74 56 46 / .4); padding:26px; margin-top:14px;">
                        <div class="mar-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="mar-btn mar-btn-s" style="height:36px;">Keep it</span>
                                <span class="mar-btn mar-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#b08a5e;">Nav treatment</div>
                    <div class="mar-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ BOLT ═══════════ --}}
    <section id="bolt" class="g-band d-bolt">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">13 · Bolt — neo-brutalist</h2>
                <p style="font-size:14px; opacity:.75;">Thick black borders, hard offset shadows, flat candy blocks, shouty type. Raw, confident, zero softness.</p>
            </div>

            <div class="g-label" style="color:#555;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:800; text-transform:uppercase; background:#111; color:#fffdf5; display:inline-block; padding:3px 8px;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="bol-btn bol-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="bol-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="bol-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#555;">Table</div>
                    <div class="bol-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="bol-th">Time</th><th scope="col" class="bol-th">Client</th><th scope="col" class="bol-th">Service</th><th scope="col" class="bol-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="bol-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="bol-pill bol-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#555;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="bol-btn bol-btn-p">Confirm booking</span>
                        <span class="bol-btn bol-btn-s">Reschedule</span>
                        <span class="bol-btn bol-btn-d">Cancel booking</span>
                        <span class="bol-pill bol-ok">New client</span>
                        <span class="bol-pill bol-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#555;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="bol-field">Amelia Hart</div>
                        <div class="bol-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#555;">Panel + modal</div>
                    <div class="bol-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(17 17 17 / .5); padding:26px; margin-top:14px;">
                        <div class="bol-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="bol-btn bol-btn-s" style="height:36px;">Keep it</span>
                                <span class="bol-btn bol-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#555;">Nav treatment</div>
                    <div class="bol-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ CHROME ═══════════ --}}
    <section id="chrome" class="g-band d-chrome">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">14 · Chrome — retro-futuristic</h2>
                <p style="font-size:14px; opacity:.75;">Y2K refined: iridescent ring borders, chrome-gradient numbers, bubble pills. Techno-optimism kept clean enough to use.</p>
            </div>

            <div class="g-label" style="color:#8a86a0;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; background:linear-gradient(120deg,#9f6bff,#ff69b4); -webkit-background-clip:text; background-clip:text; color:transparent;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="chr-btn chr-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="chr-irid" style="padding:15px 17px;">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="chr-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a86a0;">Table</div>
                    <div class="chr-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="chr-th">Time</th><th scope="col" class="chr-th">Client</th><th scope="col" class="chr-th">Service</th><th scope="col" class="chr-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="chr-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="chr-pill chr-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a86a0;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="chr-btn chr-btn-p">Confirm booking</span>
                        <span class="chr-btn chr-btn-s">Reschedule</span>
                        <span class="chr-btn chr-btn-d">Cancel booking</span>
                        <span class="chr-pill chr-ok">New client</span>
                        <span class="chr-pill chr-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#8a86a0;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="chr-field">Amelia Hart</div>
                        <div class="chr-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a86a0;">Panel + modal</div>
                    <div class="chr-irid" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(32 29 44 / .45); padding:26px; margin-top:14px;">
                        <div class="chr-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="chr-btn chr-btn-s" style="height:36px;">Keep it</span>
                                <span class="chr-btn chr-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a86a0;">Nav treatment</div>
                    <div class="chr-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ GAZETTE ═══════════ --}}
    <section id="gazette" class="g-band d-gazette">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">15 · Gazette — newspaper broadsheet</h2>
                <p style="font-size:14px; opacity:.75;">Ink on warm paper: masthead, double rules, dotted leaders, small-caps folios. Text-forward, structured, timeless.</p>
            </div>

            <div class="g-label" style="color:#171310;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="gaz-mast" style="min-width:320px;">The BookTheStyle</div><div class="gaz-lbl" style="margin-top:6px;">Vol. 1 · Saturday, 12 July · Daily edition</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="gaz-btn gaz-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="gaz-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="gaz-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#171310;">Table</div>
                    <div>
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="gaz-th">Time</th><th scope="col" class="gaz-th">Client</th><th scope="col" class="gaz-th">Service</th><th scope="col" class="gaz-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="gaz-row">
                                        <td style="padding:12px 0; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 0; text-align:right;">
                                            <span class="gaz-pill gaz-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#171310;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="gaz-btn gaz-btn-p">Confirm booking</span>
                        <span class="gaz-btn gaz-btn-s">Reschedule</span>
                        <span class="gaz-btn gaz-btn-d">Cancel booking</span>
                        <span class="gaz-pill gaz-ok">New client</span>
                        <span class="gaz-pill gaz-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#171310;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="gaz-field">Amelia Hart</div>
                        <div class="gaz-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#171310;">Panel + modal</div>
                    <div class="gaz-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(23 19 16 / .45); padding:26px; margin-top:14px;">
                        <div class="gaz-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="gaz-btn gaz-btn-s" style="height:36px;">Keep it</span>
                                <span class="gaz-btn gaz-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#171310;">Nav treatment</div>
                    <div class="gaz-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ FERN ═══════════ --}}
    <section id="fern" class="g-band d-fern">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">16 · Fern — organic botanical</h2>
                <p style="font-size:14px; opacity:.75;">Earthy greens, terracotta, and sand; blob and leaf shapes; calm, hand-tended warmth. Spa-adjacent nature.</p>
            </div>

            <div class="g-label" style="color:#90a077;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="fer-leaf"></span>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="fer-btn fer-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="fer-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="fer-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#90a077;">Table</div>
                    <div class="fer-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="fer-th">Time</th><th scope="col" class="fer-th">Client</th><th scope="col" class="fer-th">Service</th><th scope="col" class="fer-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="fer-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="fer-pill fer-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#90a077;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="fer-btn fer-btn-p">Confirm booking</span>
                        <span class="fer-btn fer-btn-s">Reschedule</span>
                        <span class="fer-btn fer-btn-d">Cancel booking</span>
                        <span class="fer-pill fer-ok">New client</span>
                        <span class="fer-pill fer-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#90a077;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="fer-field">Amelia Hart</div>
                        <div class="fer-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#90a077;">Panel + modal</div>
                    <div class="fer-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(53 64 47 / .4); padding:26px; margin-top:14px;">
                        <div class="fer-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="fer-btn fer-btn-s" style="height:36px;">Keep it</span>
                                <span class="fer-btn fer-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#90a077;">Nav treatment</div>
                    <div class="fer-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ WERKSTATT ═══════════ --}}
    <section id="werkstatt" class="g-band d-werkstatt">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">17 · Werkstatt — Bauhaus geometric</h2>
                <p style="font-size:14px; opacity:.75;">Refined primaries, circles, squares, and triangles on a strict black grid. Functional, iconic, art-school confident.</p>
            </div>

            <div class="g-label" style="color:#6f675c;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="wer-shapes"><span class="c"></span><span class="s"></span><span class="t"></span></span>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="wer-btn wer-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="wer-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="wer-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#6f675c;">Table</div>
                    <div class="wer-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="wer-th">Time</th><th scope="col" class="wer-th">Client</th><th scope="col" class="wer-th">Service</th><th scope="col" class="wer-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="wer-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="wer-pill wer-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#6f675c;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="wer-btn wer-btn-p">Confirm booking</span>
                        <span class="wer-btn wer-btn-s">Reschedule</span>
                        <span class="wer-btn wer-btn-d">Cancel booking</span>
                        <span class="wer-pill wer-ok">New client</span>
                        <span class="wer-pill wer-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#6f675c;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="wer-field">Amelia Hart</div>
                        <div class="wer-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#6f675c;">Panel + modal</div>
                    <div class="wer-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(23 21 15 / .5); padding:26px; margin-top:14px;">
                        <div class="wer-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="wer-btn wer-btn-s" style="height:36px;">Keep it</span>
                                <span class="wer-btn wer-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#6f675c;">Nav treatment</div>
                    <div class="wer-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ GILT ═══════════ --}}
    <section id="gilt" class="g-band d-gilt">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">18 · Gilt — luxe dark gold</h2>
                <p style="font-size:14px; opacity:.75;">The one dark option: near-black, champagne gold, thin elegant serif. Jewellery-house opulence, restrained and expensive.</p>
            </div>

            <div class="g-label" style="color:#d8b96a;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="gil-lbl">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="gil-btn gil-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="gil-rule" style="padding-top:14px;">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="gil-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#d8b96a;">Table</div>
                    <div class="gil-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="gil-th">Time</th><th scope="col" class="gil-th">Client</th><th scope="col" class="gil-th">Service</th><th scope="col" class="gil-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="gil-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="gil-pill gil-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#d8b96a;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="gil-btn gil-btn-p">Confirm booking</span>
                        <span class="gil-btn gil-btn-s">Reschedule</span>
                        <span class="gil-btn gil-btn-d">Cancel booking</span>
                        <span class="gil-pill gil-ok">New client</span>
                        <span class="gil-pill gil-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#d8b96a;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="gil-field">Amelia Hart</div>
                        <div class="gil-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#d8b96a;">Panel + modal</div>
                    <div class="gil-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(0 0 0 / .6); padding:26px; margin-top:14px;">
                        <div class="gil-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="gil-btn gil-btn-s" style="height:36px;">Keep it</span>
                                <span class="gil-btn gil-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#d8b96a;">Nav treatment</div>
                    <div class="gil-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ SKETCH ═══════════ --}}
    <section id="sketch" class="g-band d-sketch">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">19 · Sketch — hand-drawn</h2>
                <p style="font-size:14px; opacity:.75;">Wobbly inked borders, marker highlights, a strip of washi tape. Notebook warmth — human and one-of-a-kind, not childish.</p>
            </div>

            <div class="g-label" style="color:#7a7264;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:13px; font-weight:700;"><span class="ske-hi">Saturday, 12 July</span></div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="ske-btn ske-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="ske-wob ske-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="ske-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#7a7264;">Table</div>
                    <div>
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="ske-th">Time</th><th scope="col" class="ske-th">Client</th><th scope="col" class="ske-th">Service</th><th scope="col" class="ske-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="ske-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="ske-pill ske-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#7a7264;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="ske-btn ske-btn-p">Confirm booking</span>
                        <span class="ske-btn ske-btn-s">Reschedule</span>
                        <span class="ske-btn ske-btn-d">Cancel booking</span>
                        <span class="ske-pill ske-ok">New client</span>
                        <span class="ske-pill ske-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#7a7264;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="ske-field">Amelia Hart</div>
                        <div class="ske-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#7a7264;">Panel + modal</div>
                    <div class="ske-wob" style="padding:18px; position:relative;">
                        <span class="ske-tape"></span>
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(46 42 37 / .45); padding:26px; margin-top:14px;">
                        <div class="ske-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="ske-btn ske-btn-s" style="height:36px;">Keep it</span>
                                <span class="ske-btn ske-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#7a7264;">Nav treatment</div>
                    <div class="ske-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ GRID ═══════════ --}}
    <section id="grid" class="g-band d-grid">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">20 · Grid — Swiss precision</h2>
                <p style="font-size:14px; opacity:.75;">International Typographic Style: rigorous grid, neutral Helvetica, one red mark, zero ornament. Pure information design.</p>
            </div>

            <div class="g-label" style="color:#6f6f6f;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="display:flex; gap:14px; align-items:baseline;"><span class="gri-idx">01</span><span class="gri-lbl">Saturday, 12 July</span></div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="gri-btn gri-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="gri-rule" style="padding-top:12px;">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="gri-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#6f6f6f;">Table</div>
                    <div>
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="gri-th">Time</th><th scope="col" class="gri-th">Client</th><th scope="col" class="gri-th">Service</th><th scope="col" class="gri-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="gri-row">
                                        <td style="padding:12px 0; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 0; text-align:right;">
                                            <span class="gri-pill gri-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#6f6f6f;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="gri-btn gri-btn-p">Confirm booking</span>
                        <span class="gri-btn gri-btn-s">Reschedule</span>
                        <span class="gri-btn gri-btn-d">Cancel booking</span>
                        <span class="gri-pill gri-ok">New client</span>
                        <span class="gri-pill gri-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#6f6f6f;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="gri-field">Amelia Hart</div>
                        <div class="gri-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#6f6f6f;">Panel + modal</div>
                    <div class="gri-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(17 17 17 / .45); padding:26px; margin-top:14px;">
                        <div class="gri-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="gri-btn gri-btn-s" style="height:36px;">Keep it</span>
                                <span class="gri-btn gri-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#6f6f6f;">Nav treatment</div>
                    <div class="gri-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ DOUGH ═══════════ --}}
    <section id="dough" class="g-band d-dough">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">21 · Dough — claymorphism</h2>
                <p style="font-size:14px; opacity:.72;">Puffy inflated clay surfaces in pastel lilac, mint, and peach — inner and outer soft shadows, toy-like but refined and tactile.</p>
            </div>

            <div class="g-label" style="color:#9a8fb0;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12.5px; font-weight:700; color:#8f6cc9;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="dou-btn dou-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="dou-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="dou-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#9a8fb0;">Table</div>
                    <div class="dou-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="dou-th">Time</th><th scope="col" class="dou-th">Client</th><th scope="col" class="dou-th">Service</th><th scope="col" class="dou-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="dou-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="dou-pill dou-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#9a8fb0;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="dou-btn dou-btn-p">Confirm booking</span>
                        <span class="dou-btn dou-btn-s">Reschedule</span>
                        <span class="dou-btn dou-btn-d">Cancel booking</span>
                        <span class="dou-pill dou-ok">New client</span>
                        <span class="dou-pill dou-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#9a8fb0;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="dou-field">Amelia Hart</div>
                        <div class="dou-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#9a8fb0;">Panel + modal</div>
                    <div class="dou-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(74 67 86 / .4); padding:26px; margin-top:14px;">
                        <div class="dou-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="dou-btn dou-btn-s" style="height:36px;">Keep it</span>
                                <span class="dou-btn dou-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#9a8fb0;">Nav treatment</div>
                    <div class="dou-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ CONSOLE ═══════════ --}}
    <section id="console" class="g-band d-console">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">22 · Console — terminal monospace</h2>
                <p style="font-size:14px; opacity:.72;">A beautiful command line: ink-on-paper mono, prompts and brackets, a blinking cursor, dashed rules. Structured and nerdy-chic.</p>
            </div>

            <div class="g-label" style="color:#6d7060;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="con-prompt con-cursor" style="font-size:13px; font-weight:700;">bookthestyle --today</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="con-btn con-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="con-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="con-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#6d7060;">Table</div>
                    <div class="con-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="con-th">Time</th><th scope="col" class="con-th">Client</th><th scope="col" class="con-th">Service</th><th scope="col" class="con-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="con-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="con-pill con-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#6d7060;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="con-btn con-btn-p">Confirm booking</span>
                        <span class="con-btn con-btn-s">Reschedule</span>
                        <span class="con-btn con-btn-d">Cancel booking</span>
                        <span class="con-pill con-ok">New client</span>
                        <span class="con-pill con-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#6d7060;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="con-field">Amelia Hart</div>
                        <div class="con-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#6d7060;">Panel + modal</div>
                    <div class="con-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(36 38 31 / .5); padding:26px; margin-top:14px;">
                        <div class="con-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="con-btn con-btn-s" style="height:36px;">Keep it</span>
                                <span class="con-btn con-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#6d7060;">Nav treatment</div>
                    <div class="con-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ CONFETTI ═══════════ --}}
    <section id="confetti" class="g-band d-confetti">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">23 · Confetti — Memphis postmodern</h2>
                <p style="font-size:14px; opacity:.72;">Curated 80s chaos: squiggles, mismatched corner radii, clashing teal, pink, butter, and lilac on inked outlines. Fun with discipline.</p>
            </div>

            <div class="g-label" style="color:#55517a;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="cof-squig"></span>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="cof-btn cof-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="cof-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="cof-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#55517a;">Table</div>
                    <div class="cof-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="cof-th">Time</th><th scope="col" class="cof-th">Client</th><th scope="col" class="cof-th">Service</th><th scope="col" class="cof-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="cof-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="cof-pill cof-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#55517a;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="cof-btn cof-btn-p">Confirm booking</span>
                        <span class="cof-btn cof-btn-s">Reschedule</span>
                        <span class="cof-btn cof-btn-d">Cancel booking</span>
                        <span class="cof-pill cof-ok">New client</span>
                        <span class="cof-pill cof-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#55517a;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="cof-field">Amelia Hart</div>
                        <div class="cof-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#55517a;">Panel + modal</div>
                    <div class="cof-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(35 32 74 / .45); padding:26px; margin-top:14px;">
                        <div class="cof-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="cof-btn cof-btn-s" style="height:36px;">Keep it</span>
                                <span class="cof-btn cof-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#55517a;">Nav treatment</div>
                    <div class="cof-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ DECO ═══════════ --}}
    <section id="deco" class="g-band d-deco">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">24 · Deco — Gatsby luxe</h2>
                <p style="font-size:14px; opacity:.72;">Nineteen-twenties opulence: gold double frames, sunburst fans, thirty-percent-tracked capitals, symmetric jewel-box glamour.</p>
            </div>

            <div class="g-label" style="color:#a8823c;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="dec-fan" style="margin:0;"></div><div class="dec-lbl" style="margin-top:8px;">Saturday · 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="dec-btn dec-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="dec-frame dec-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="dec-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#a8823c;">Table</div>
                    <div class="dec-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="dec-th">Time</th><th scope="col" class="dec-th">Client</th><th scope="col" class="dec-th">Service</th><th scope="col" class="dec-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="dec-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="dec-pill dec-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#a8823c;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="dec-btn dec-btn-p">Confirm booking</span>
                        <span class="dec-btn dec-btn-s">Reschedule</span>
                        <span class="dec-btn dec-btn-d">Cancel booking</span>
                        <span class="dec-pill dec-ok">New client</span>
                        <span class="dec-pill dec-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#a8823c;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="dec-field">Amelia Hart</div>
                        <div class="dec-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#a8823c;">Panel + modal</div>
                    <div class="dec-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(36 31 24 / .5); padding:26px; margin-top:14px;">
                        <div class="dec-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="dec-btn dec-btn-s" style="height:36px;">Keep it</span>
                                <span class="dec-btn dec-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#a8823c;">Nav treatment</div>
                    <div class="dec-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ FJORD ═══════════ --}}
    <section id="fjord" class="g-band d-fjord">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">25 · Fjord — Scandinavian hygge</h2>
                <p style="font-size:14px; opacity:.72;">Cozy Nordic calm: dusty blue, clay, and oat; soft rounded surfaces; nothing raised more than a whisper. Warm minimalism, homey.</p>
            </div>

            <div class="g-label" style="color:#92897a;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12.5px; font-weight:600; color:#57636b;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="fjo-btn fjo-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="fjo-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="fjo-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#92897a;">Table</div>
                    <div class="fjo-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="fjo-th">Time</th><th scope="col" class="fjo-th">Client</th><th scope="col" class="fjo-th">Service</th><th scope="col" class="fjo-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="fjo-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="fjo-pill fjo-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#92897a;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="fjo-btn fjo-btn-p">Confirm booking</span>
                        <span class="fjo-btn fjo-btn-s">Reschedule</span>
                        <span class="fjo-btn fjo-btn-d">Cancel booking</span>
                        <span class="fjo-pill fjo-ok">New client</span>
                        <span class="fjo-pill fjo-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#92897a;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="fjo-field">Amelia Hart</div>
                        <div class="fjo-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#92897a;">Panel + modal</div>
                    <div class="fjo-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(63 59 54 / .4); padding:26px; margin-top:14px;">
                        <div class="fjo-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="fjo-btn fjo-btn-s" style="height:36px;">Keep it</span>
                                <span class="fjo-btn fjo-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#92897a;">Nav treatment</div>
                    <div class="fjo-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ NEON ═══════════ --}}
    <section id="neon" class="g-band d-neon">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">26 · Neon — cyberpunk noir</h2>
                <p style="font-size:14px; opacity:.72;">The dramatic dark option: electric cyan, magenta, and lime glowing off near-black panels. High-tech grit, kept legible.</p>
            </div>

            <div class="g-label" style="color:#7d8a99;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:11px; font-weight:700; letter-spacing:.22em; text-transform:uppercase; color:#ff3d81; text-shadow:0 0 12px rgb(255 61 129 / .5);">SAT 12.07 // GRID ONLINE</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="neo-btn neo-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="neo-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="neo-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#7d8a99;">Table</div>
                    <div class="neo-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="neo-th">Time</th><th scope="col" class="neo-th">Client</th><th scope="col" class="neo-th">Service</th><th scope="col" class="neo-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="neo-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="neo-pill neo-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#7d8a99;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="neo-btn neo-btn-p">Confirm booking</span>
                        <span class="neo-btn neo-btn-s">Reschedule</span>
                        <span class="neo-btn neo-btn-d">Cancel booking</span>
                        <span class="neo-pill neo-ok">New client</span>
                        <span class="neo-pill neo-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#7d8a99;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="neo-field">Amelia Hart</div>
                        <div class="neo-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#7d8a99;">Panel + modal</div>
                    <div class="neo-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(0 0 0 / .6); padding:26px; margin-top:14px;">
                        <div class="neo-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="neo-btn neo-btn-s" style="height:36px;">Keep it</span>
                                <span class="neo-btn neo-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#7d8a99;">Nav treatment</div>
                    <div class="neo-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ WASH ═══════════ --}}
    <section id="wash" class="g-band d-wash">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">27 · Wash — watercolor painterly</h2>
                <p style="font-size:14px; opacity:.72;">Pigment blooms bleeding at the corners of soft irregular panels; delicate serif figures. Gallery-atelier softness, art-forward.</p>
            </div>

            <div class="g-label" style="color:#a08a98;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:#a4506a;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="was-btn was-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="was-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="was-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#a08a98;">Table</div>
                    <div class="was-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="was-th">Time</th><th scope="col" class="was-th">Client</th><th scope="col" class="was-th">Service</th><th scope="col" class="was-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="was-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="was-pill was-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#a08a98;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="was-btn was-btn-p">Confirm booking</span>
                        <span class="was-btn was-btn-s">Reschedule</span>
                        <span class="was-btn was-btn-d">Cancel booking</span>
                        <span class="was-pill was-ok">New client</span>
                        <span class="was-pill was-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#a08a98;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="was-field">Amelia Hart</div>
                        <div class="was-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#a08a98;">Panel + modal</div>
                    <div class="was-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(74 64 70 / .4); padding:26px; margin-top:14px;">
                        <div class="was-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="was-btn was-btn-s" style="height:36px;">Keep it</span>
                                <span class="was-btn was-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#a08a98;">Nav treatment</div>
                    <div class="was-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ FIELD ═══════════ --}}
    <section id="field" class="g-band d-field">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">28 · Field — flat 2.0</h2>
                <p style="font-size:14px; opacity:.72;">No shadows, no borders, no gradients: bold flat colour fields doing all the structure. Confident geometric flatness, contemporary-clean.</p>
            </div>

            <div class="g-label" style="color:#8a8171;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; background:#221d16; color:#f4efe6; display:inline-block; padding:4px 10px;">Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="fie-btn fie-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="fie-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="fie-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a8171;">Table</div>
                    <div class="fie-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="fie-th">Time</th><th scope="col" class="fie-th">Client</th><th scope="col" class="fie-th">Service</th><th scope="col" class="fie-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="fie-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="fie-pill fie-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a8171;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="fie-btn fie-btn-p">Confirm booking</span>
                        <span class="fie-btn fie-btn-s">Reschedule</span>
                        <span class="fie-btn fie-btn-d">Cancel booking</span>
                        <span class="fie-pill fie-ok">New client</span>
                        <span class="fie-pill fie-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#8a8171;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="fie-field">Amelia Hart</div>
                        <div class="fie-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a8171;">Panel + modal</div>
                    <div class="fie-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(34 29 22 / .5); padding:26px; margin-top:14px;">
                        <div class="fie-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="fie-btn fie-btn-s" style="height:36px;">Keep it</span>
                                <span class="fie-btn fie-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a8171;">Nav treatment</div>
                    <div class="fie-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ VOLUME ═══════════ --}}
    <section id="volume" class="g-band d-volume">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">29 · Volume — maximalist editorial</h2>
                <p style="font-size:14px; opacity:.72;">The anti-minimal: 56px serif figures on six-point rules, mono folio labels, red ink, hard offset shadows. Magazine-cover drama.</p>
            </div>

            <div class="g-label" style="color:#6f6350;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <div class="vol-mono" style="color:#c2372c;">Issue 001 — Saturday, 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="vol-btn vol-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="vol-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="vol-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#6f6350;">Table</div>
                    <div class="vol-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="vol-th">Time</th><th scope="col" class="vol-th">Client</th><th scope="col" class="vol-th">Service</th><th scope="col" class="vol-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="vol-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="vol-pill vol-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#6f6350;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="vol-btn vol-btn-p">Confirm booking</span>
                        <span class="vol-btn vol-btn-s">Reschedule</span>
                        <span class="vol-btn vol-btn-d">Cancel booking</span>
                        <span class="vol-pill vol-ok">New client</span>
                        <span class="vol-pill vol-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#6f6350;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="vol-field">Amelia Hart</div>
                        <div class="vol-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#6f6350;">Panel + modal</div>
                    <div class="vol-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(28 23 18 / .5); padding:26px; margin-top:14px;">
                        <div class="vol-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="vol-btn vol-btn-s" style="height:36px;">Keep it</span>
                                <span class="vol-btn vol-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#6f6350;">Nav treatment</div>
                    <div class="vol-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════ KYOTO ═══════════ --}}
    <section id="kyoto" class="g-band d-kyoto">
        <div class="g-band-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 class="hd" style="font-size:24px;">30 · Kyoto — Zen Japandi</h2>
                <p style="font-size:14px; opacity:.72;">Wood, stone, and paper; an enso ring; immense whitespace and asymmetric balance. Wabi-sabi quiet — the calmest of all thirty.</p>
            </div>

            <div class="g-label" style="color:#8a7a63;">Header + stats</div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
                <div>
                    <span class="kyo-enso"></span><div class="kyo-lbl" style="margin-top:10px;">Saturday · 12 July</div>
                    <div class="hd" style="font-size:26px; margin-top:8px;">Today at the salon</div>
                </div>
                <span class="kyo-btn kyo-btn-p">Add booking</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin-top:20px;">
                @foreach ($stats as $s)
                    <div class="kyo-stat">
                        <div style="font-size:12.5px; font-weight:600; opacity:.65;">{{ $s['label'] }}</div>
                        <div class="kyo-num" style="margin-top:8px;">{{ $s['value'] }}</div>
                        <div style="font-size:12px; opacity:.55; margin-top:5px;">{{ $s['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="g-grid">
                <div>
                    <div class="g-label" style="color:#8a7a63;">Table</div>
                    <div class="kyo-glass" style="overflow:hidden; position:relative;">
                        <table style="width:100%; text-align:left; font-size:14px;">
                            <thead><tr>
                                <th scope="col" class="kyo-th">Time</th><th scope="col" class="kyo-th">Client</th><th scope="col" class="kyo-th">Service</th><th scope="col" class="kyo-th" style="text-align:right;">Status</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="kyo-row">
                                        <td style="padding:12px 16px; opacity:.6;">{{ $r['time'] }}</td>
                                        <td style="padding:12px 8px; font-weight:600;">{{ $r['client'] }}</td>
                                        <td style="padding:12px 8px; opacity:.8;">{{ $r['service'] }} · {{ $r['stylist'] }}</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <span class="kyo-pill kyo-{{ $r['kind'] === 'ok' ? 'ok' : ($r['kind'] === 'bad' ? 'bad' : 'mut') }}">{{ $r['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="g-label" style="color:#8a7a63;">Buttons + pills</div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                        <span class="kyo-btn kyo-btn-p">Confirm booking</span>
                        <span class="kyo-btn kyo-btn-s">Reschedule</span>
                        <span class="kyo-btn kyo-btn-d">Cancel booking</span>
                        <span class="kyo-pill kyo-ok">New client</span>
                        <span class="kyo-pill kyo-mut">Walk-in</span>
                    </div>

                    <div class="g-label" style="color:#8a7a63;">Form fields</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:470px;">
                        <div class="kyo-field">Amelia Hart</div>
                        <div class="kyo-field" style="display:flex; justify-content:space-between;">Full colour <span style="opacity:.4;">▾</span></div>
                    </div>
                </div>
                <div>
                    <div class="g-label" style="color:#8a7a63;">Panel + modal</div>
                    <div class="kyo-panel" style="padding:18px; position:relative;">
                        
                        <div class="hd" style="font-size:16px;">Next up</div>
                        <p style="font-size:13.5px; opacity:.8; margin-top:6px; line-height:1.6;">Ruth Okafor · Full colour with Simone, 10:30 AM. Allergy on file — review before mixing.</p>
                    </div>
                    <div class="g-stage" style="background:rgb(69 65 58 / .45); padding:26px; margin-top:14px;">
                        <div class="kyo-modal" style="padding:20px;">
                            <div class="hd" style="font-size:16px;">Cancel this booking?</div>
                            <p style="font-size:13.5px; opacity:.8; margin:8px 0 15px;">The client's appointment is removed and GoHighLevel is updated.</p>
                            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                <span class="kyo-btn kyo-btn-s" style="height:36px;">Keep it</span>
                                <span class="kyo-btn kyo-btn-d" style="height:36px;">Cancel booking</span>
                            </div>
                        </div>
                    </div>
                    <div class="g-label" style="color:#8a7a63;">Nav treatment</div>
                    <div class="kyo-nav" style="max-width:210px;">
                        @foreach ($nav as $i => $n)<a class="{{ $i === 0 ? 'on' : '' }}"><span>{{ $n }}</span></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ————— Footer note ————— --}}
    <div class="g-band" style="padding-top:44px; padding-bottom:56px;">
        <div class="g-band-inner">
            <p class="g-caption">Temporary exploration surface. Once a direction is chosen it will be implemented across the whole app — tokens, components, every screen — and this tab will be removed.</p>
        </div>
    </div>
</div>
