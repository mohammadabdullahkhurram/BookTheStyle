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
    </style>

    {{-- ————— Gallery intro + quick nav ————— --}}
    <div class="g-band" style="padding-bottom: 40px;">
        <div class="g-band-inner">
            <div class="g-title" style="color:#d9a9c6;">Design directions</div>
            <h1 style="font-family:'Fraunces',serif; font-size:34px; font-weight:600; margin-top:14px;">Ten complete languages, same components.</h1>
            <p class="g-caption" style="margin-top:12px;">Each band below renders the identical sample set — header and stats, a table, buttons and pills, a panel and modal preview, form fields, and the nav treatment — fully styled in one direction. Pick the one that feels like BookTheStyle; it will be implemented app-wide and this temporary tab removed.</p>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:22px;">
                @foreach ([['#lumen', '1 · Lumen'], ['#journal', '2 · Journal'], ['#meridian', '3 · Meridian'], ['#halo', '4 · Halo'], ['#velvet', '5 · Velvet'], ['#studio', '6 · Studio'], ['#aurora', '7 · Aurora'], ['#manor', '8 · Manor'], ['#bloom', '9 · Bloom'], ['#vertex', '10 · Vertex']] as [$href, $label])
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

    {{-- ————— Footer note ————— --}}
    <div class="g-band" style="padding-top:44px; padding-bottom:56px;">
        <div class="g-band-inner">
            <p class="g-caption">Temporary exploration surface. Once a direction is chosen it will be implemented across the whole app — tokens, components, every screen — and this tab will be removed.</p>
        </div>
    </div>
</div>
