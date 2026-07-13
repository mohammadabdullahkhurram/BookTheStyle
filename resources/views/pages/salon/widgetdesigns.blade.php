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

        /* ═══ 6 · VITRINE — full liquid glass ═══ */
        .w-vitrine { background:
            radial-gradient(36rem 24rem at 12% -8%, rgb(130 76 113 / .3), transparent 58%),
            radial-gradient(32rem 22rem at 88% 0%, rgb(91 146 189 / .28), transparent 58%),
            radial-gradient(40rem 26rem at 50% 115%, rgb(212 154 78 / .24), transparent 60%),
            #eceae8; color: #262126; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .vit-card { background: rgb(255 255 255 / .38); -webkit-backdrop-filter: blur(24px) saturate(1.7); backdrop-filter: blur(24px) saturate(1.7);
            border: 1px solid rgb(255 255 255 / .75); border-radius: 22px; padding: 18px;
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .95), inset 1px 0 0 rgb(255 255 255 / .45), 0 14px 38px rgb(63 47 70 / .18); }
        .vit-t { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; font-size: 16px; }
        .vit-dots { display: flex; gap: 5px; margin-top: 7px; }
        .vit-dots i { width: 20px; height: 5px; border-radius: 99px; background: rgb(255 255 255 / .5); border: 1px solid rgb(255 255 255 / .6); }
        .vit-dots .on { background: rgb(130 76 113 / .85); }
        .vit-opt { display: flex; justify-content: space-between; align-items: center; padding: 11px 13px; border-radius: 14px; margin-top: 8px; font-size: 13.5px;
            background: rgb(255 255 255 / .4); border: 1px solid rgb(255 255 255 / .7); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8); }
        .vit-opt.sel { background: rgb(130 76 113 / .2); border-color: rgb(130 76 113 / .5); }
        .vit-chip { padding: 8px 0; text-align: center; border-radius: 11px; font-size: 12.5px; font-weight: 600;
            background: rgb(255 255 255 / .4); border: 1px solid rgb(255 255 255 / .7); }
        .vit-chip.sel { background: rgb(130 76 113 / .85); color: #fff; border-color: transparent; }
        .vit-field { background: rgb(255 255 255 / .55); border: 1px solid rgb(255 255 255 / .8); border-radius: 12px; padding: 9px 12px; font-size: 13px; color: #57504f; margin-top: 8px; }
        .vit-cta { height: 44px; border-radius: 99px; background: rgb(130 76 113 / .88); color: #fff; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; border: 1px solid rgb(255 255 255 / .4);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .35), 0 8px 24px rgb(130 76 113 / .4); }
        .vit-check { width: 46px; height: 46px; border-radius: 50%; background: rgb(255 255 255 / .55); border: 1px solid rgb(255 255 255 / .85); color: #6b3358;
            display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; margin: 4px auto 10px;
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .9); }

        /* ═══ 7 · STACK — card stack wizard ═══ */
        .w-stack { background: #f1eff3; color: #241f2b; font-family: 'Schibsted Grotesk', sans-serif; border-radius: 18px; }
        .sta-card { background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 2px 6px rgb(36 31 43 / .06), 0 12px 30px rgb(36 31 43 / .08); position: relative; }
        .sta-card::before { content: ''; position: absolute; left: 10px; right: 10px; top: -7px; height: 10px; background: #e6e2ec; border-radius: 12px 12px 0 0; z-index: -1; }
        .sta-t { font-weight: 700; font-size: 16px; letter-spacing: -.01em; }
        .sta-count { font-size: 11.5px; font-weight: 700; color: #824c71; }
        .sta-opt { display: flex; justify-content: space-between; align-items: center; padding: 14px 15px; border-radius: 14px;
            border: 2px solid #eeebf2; margin-top: 8px; font-size: 14px; font-weight: 600; }
        .sta-opt.sel { border-color: #824c71; background: #f8f1f6; }
        .sta-chip { padding: 11px 0; text-align: center; border-radius: 12px; border: 2px solid #eeebf2; font-size: 13px; font-weight: 700; }
        .sta-chip.sel { border-color: #824c71; background: #824c71; color: #fff; }
        .sta-field { background: #f5f3f7; border: 0; border-radius: 12px; padding: 12px 14px; font-size: 13.5px; color: #55495c; margin-top: 8px; }
        .sta-cta { height: 48px; border-radius: 14px; background: #824c71; color: #fff; font-weight: 700; font-size: 15px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; }
        .sta-check { width: 48px; height: 48px; border-radius: 16px; background: #824c71; color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; margin: 6px auto 10px; }

        /* ═══ 8 · SCROLL — single-page scroll ═══ */
        .w-scroll { background: #f4f2ee; color: #22201c; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .scr-card { background: #fff; border: 1px solid #e8e5df; border-radius: 16px; width: 340px; max-width: 100%; }
        .scr-sec { padding: 16px 18px; border-top: 1px solid #efece6; }
        .scr-sec:first-child { border-top: 0; }
        .scr-h { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #824c71; margin-bottom: 9px; }
        .scr-opt { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px; border-radius: 99px; border: 1.5px solid #e2ded6; font-size: 12.5px; font-weight: 600; margin: 0 6px 6px 0; }
        .scr-opt.sel { border-color: #824c71; background: #f6ecf2; color: #6b3358; }
        .scr-chip { padding: 7px 0; text-align: center; border-radius: 9px; border: 1.5px solid #e2ded6; font-size: 12px; font-weight: 600; }
        .scr-chip.sel { background: #22201c; color: #fff; border-color: #22201c; }
        .scr-field { background: #faf8f4; border: 1.5px solid #e2ded6; border-radius: 10px; padding: 9px 12px; font-size: 13px; color: #56524a; margin-top: 7px; }
        .scr-cta { height: 46px; border-radius: 12px; background: #824c71; color: #fff; font-weight: 700; font-size: 14.5px;
            display: flex; align-items: center; justify-content: center; margin: 4px 18px 18px; }

        /* ═══ 9 · DUET — split layout ═══ */
        .w-duet { background: #efece7; color: #29251f; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .due-card { display: flex; width: 620px; max-width: 100%; border-radius: 18px; overflow: hidden; box-shadow: 0 10px 30px rgb(41 37 31 / .12); flex-wrap: wrap; }
        .due-info { background: #3d2f3a; color: #f2e9ee; padding: 22px 20px; flex: 1 1 220px; }
        .due-info .hd2 { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 600; }
        .due-main { background: #fff; padding: 20px; flex: 1.4 1 300px; }
        .due-h { font-size: 15px; font-weight: 700; }
        .due-opt { display: flex; justify-content: space-between; padding: 10px 12px; border-radius: 11px; border: 1.5px solid #eae6df; margin-top: 7px; font-size: 13px; }
        .due-opt.sel { border-color: #824c71; background: #f8f1f6; }
        .due-chip { padding: 7px 0; text-align: center; border-radius: 9px; border: 1.5px solid #eae6df; font-size: 12px; font-weight: 600; }
        .due-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .due-field { background: #faf9f6; border: 1.5px solid #e4dfd6; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin-top: 7px; color: #58534b; }
        .due-cta { height: 44px; border-radius: 11px; background: #824c71; color: #fff; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 12px; }
        .due-sum { font-size: 12.5px; line-height: 1.7; opacity: .85; margin-top: 12px; }

        /* ═══ 10 · KALEND — calendar-first ═══ */
        .w-kalend { background: #f3f1ed; color: #25221d; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .kal-card { background: #fff; border: 1px solid #e7e4dd; border-radius: 18px; padding: 18px; width: 360px; max-width: 100%; }
        .kal-sel { display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid #e2ded6; border-radius: 99px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; margin: 0 6px 6px 0; }
        .kal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-top: 12px; text-align: center; }
        .kal-grid .dow { font-size: 10px; font-weight: 700; letter-spacing: .06em; color: #9a938a; padding: 4px 0; }
        .kal-grid .day { font-size: 12.5px; padding: 8px 0; border-radius: 9px; }
        .kal-grid .day.av { background: #f4eef6; color: #6b3358; font-weight: 600; }
        .kal-grid .day.sel { background: #824c71; color: #fff; font-weight: 700; }
        .kal-grid .day.off { color: #c9c3b8; }
        .kal-chip { padding: 8px 0; text-align: center; border-radius: 9px; border: 1.5px solid #e2ded6; font-size: 12.5px; font-weight: 600; }
        .kal-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .kal-field { background: #faf9f5; border: 1.5px solid #e2ded6; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin-top: 8px; color: #57534b; }
        .kal-cta { height: 44px; border-radius: 12px; background: #25221d; color: #fff; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; }

        /* ═══ 11 · PARLA — conversational ═══ */
        .w-parla { background: #f6f1ec; color: #3c332c; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .par-card { background: #fffdfa; border: 1px solid #ece2d5; border-radius: 20px; padding: 18px; width: 340px; max-width: 100%; }
        .par-q { background: #f1e7dc; border-radius: 16px 16px 16px 4px; padding: 10px 14px; font-size: 13.5px; max-width: 85%; margin-top: 10px; }
        .par-a { background: #824c71; color: #fff; border-radius: 16px 16px 4px 16px; padding: 10px 14px; font-size: 13.5px; max-width: 75%; margin: 10px 0 0 auto; font-weight: 600; }
        .par-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .par-chip { border: 1.5px solid #d9c9b6; border-radius: 99px; padding: 7px 13px; font-size: 12.5px; font-weight: 600; color: #6b584a; }
        .par-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .par-field { background: #fff; border: 1.5px solid #e0d4c4; border-radius: 12px; padding: 9px 13px; font-size: 13px; color: #6b584a; margin-top: 8px; }
        .par-cta { height: 44px; border-radius: 99px; background: #3c332c; color: #fffdfa; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; }

        /* ═══ 12 · MONO — minimal mono ═══ */
        .w-mono { background: #fbfaf8; color: #191816; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .mon-card { background: #fff; border: 1px solid #e9e7e3; border-radius: 10px; padding: 18px; }
        .mon-t { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 700; font-size: 15px; letter-spacing: -.02em; }
        .mon-lbl { font-size: 10px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #98948c; }
        .mon-opt { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #efedea; font-size: 13.5px; }
        .mon-opt.sel span:first-child { font-weight: 700; }
        .mon-opt.sel::after { content: '—'; color: #824c71; font-weight: 700; }
        .mon-chip { padding: 8px 0; text-align: center; border: 1px solid #e4e2dd; border-radius: 7px; font-size: 12.5px; }
        .mon-chip.sel { border-color: #191816; font-weight: 700; }
        .mon-field { border: 0; border-bottom: 1px solid #d5d2cb; padding: 9px 0; font-size: 13.5px; color: #565349; margin-top: 10px; }
        .mon-cta { height: 42px; border-radius: 8px; background: #191816; color: #fbfaf8; font-weight: 600; font-size: 13.5px;
            display: flex; align-items: center; justify-content: center; margin-top: 16px; }
        .mon-check { font-size: 22px; font-weight: 700; color: #824c71; text-align: center; margin: 4px 0 8px; }

        /* ═══ 13 · ATELIER — warm boutique arch ═══ */
        .w-atelier { background: #f3e8df; color: #4a332c; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .ate-card { background: #fdf8f2; border: 1.5px solid #dfc7b4; border-radius: 130px 130px 22px 22px; padding: 34px 20px 20px; text-align: center; }
        .ate-t { font-family: 'Fraunces', serif; font-weight: 600; font-size: 18px; color: #6d3f2e; }
        .ate-opt { display: flex; justify-content: space-between; padding: 11px 14px; border-radius: 99px; border: 1.5px solid #e6d3c2; margin-top: 8px; font-size: 13px; text-align: left; }
        .ate-opt.sel { border-color: #a0522d; background: #f6e9de; }
        .ate-chip { padding: 8px 0; text-align: center; border-radius: 99px; border: 1.5px solid #e6d3c2; font-size: 12px; font-weight: 600; }
        .ate-chip.sel { background: #a0522d; color: #fdf8f2; border-color: #a0522d; }
        .ate-field { background: #fdf8f2; border: 0; border-bottom: 1.5px solid #d3b39c; padding: 9px 4px; font-size: 13.5px; color: #6d564a; margin-top: 9px; text-align: left; }
        .ate-cta { height: 44px; border-radius: 99px; background: #6d3f2e; color: #fdf8f2; font-weight: 600; font-size: 13.5px; letter-spacing: .06em;
            display: flex; align-items: center; justify-content: center; margin-top: 15px; }
        .ate-check { font-family: 'Fraunces', serif; font-size: 26px; color: #a0522d; margin: 2px 0 8px; }

        /* ═══ 14 · BLOCKS — bold color block ═══ */
        .w-blocks { background: #efe9df; color: #1e1a14; font-family: 'Schibsted Grotesk', sans-serif; border-radius: 18px; }
        .blo-card { border-radius: 0; overflow: hidden; }
        .blo-head { padding: 14px 16px; color: #fff; font-weight: 800; font-size: 15px; letter-spacing: -.01em; }
        .blo-body { background: #fffdf8; padding: 14px 16px 16px; }
        .blo-opt { display: flex; justify-content: space-between; padding: 13px 14px; margin-top: 8px; font-size: 13.5px; font-weight: 700; background: #f1ece2; }
        .blo-opt.sel { background: #1e1a14; color: #fffdf8; }
        .blo-chip { padding: 10px 0; text-align: center; font-size: 13px; font-weight: 800; background: #f1ece2; }
        .blo-chip.sel { background: #d3541f; color: #fff; }
        .blo-field { background: #f1ece2; border: 0; padding: 12px 14px; font-size: 13.5px; font-weight: 600; color: #55503f; margin-top: 8px; }
        .blo-cta { height: 48px; background: #d3541f; color: #fff; font-weight: 800; font-size: 15px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; }
        .blo-check { width: 48px; height: 48px; background: #2e5d43; color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800; margin: 8px auto 10px; }

        /* ═══ 15 · NOCTURNE — dark elegant ═══ */
        .w-nocturne { background: #171419; color: #e9e2e6; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .noc-card { background: #201b23; border: 1px solid #322b36; border-radius: 16px; padding: 18px; }
        .noc-t { font-family: 'Fraunces', serif; font-weight: 500; font-size: 17px; color: #f2ebef; }
        .noc-lbl { font-size: 10.5px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: #c990b4; }
        .noc-opt { display: flex; justify-content: space-between; padding: 11px 13px; border-radius: 12px; border: 1px solid #322b36; margin-top: 8px; font-size: 13.5px; background: #241f28; }
        .noc-opt.sel { border-color: #c990b4; background: rgb(201 144 180 / .12); }
        .noc-chip { padding: 8px 0; text-align: center; border-radius: 10px; border: 1px solid #322b36; font-size: 12.5px; font-weight: 600; background: #241f28; }
        .noc-chip.sel { background: #c990b4; color: #201317; border-color: #c990b4; }
        .noc-field { background: #191521; border: 1px solid #372f3c; border-radius: 11px; padding: 10px 13px; font-size: 13px; color: #b9aeb6; margin-top: 8px; }
        .noc-cta { height: 44px; border-radius: 12px; background: #c990b4; color: #201317; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; box-shadow: 0 6px 20px rgb(201 144 180 / .3); }
        .noc-check { width: 44px; height: 44px; border-radius: 50%; border: 1px solid #c990b4; color: #c990b4;
            display: flex; align-items: center; justify-content: center; font-size: 19px; font-weight: 700; margin: 4px auto 10px; }

        /* ═══ 16 · INLINE — compact inline ═══ */
        .w-inline { background: #f4f3f0; color: #23211d; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .inl-card { background: #fff; border: 1px solid #e6e4de; border-radius: 12px; padding: 12px; width: 260px !important; flex-basis: 260px !important; }
        .inl-t { font-weight: 700; font-size: 13px; }
        .inl-opt { display: flex; justify-content: space-between; padding: 8px 9px; border-radius: 8px; border: 1px solid #eae8e2; margin-top: 5px; font-size: 12px; }
        .inl-opt.sel { border-color: #824c71; background: #f7f0f4; }
        .inl-chip { padding: 6px 0; text-align: center; border-radius: 7px; border: 1px solid #eae8e2; font-size: 11px; font-weight: 600; }
        .inl-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .inl-field { background: #f8f7f4; border: 1px solid #e6e4de; border-radius: 8px; padding: 7px 9px; font-size: 11.5px; color: #57544c; margin-top: 5px; }
        .inl-cta { height: 34px; border-radius: 8px; background: #824c71; color: #fff; font-weight: 700; font-size: 12px;
            display: flex; align-items: center; justify-content: center; margin-top: 9px; }
        .inl-check { font-size: 18px; font-weight: 700; color: #824c71; text-align: center; margin: 2px 0 6px; }

        /* ═══ 17 · GALLERY — editorial luxe ═══ */
        .w-gallery { background: #f7f4ed; color: #211d17; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .gal-card { background: #fcfaf4; border: 1px solid #211d17; padding: 26px 20px; text-align: center; }
        .gal-t { font-family: 'Fraunces', serif; font-weight: 500; font-size: 19px; }
        .gal-lbl { font-size: 10px; font-weight: 600; letter-spacing: .34em; text-transform: uppercase; color: #9a8f7a; }
        .gal-opt { padding: 11px 0; border-bottom: 1px solid #e5dcc8; font-size: 13.5px; display: flex; justify-content: space-between; text-align: left; }
        .gal-opt.sel span:first-child { font-family: 'Fraunces', serif; font-style: normal; font-weight: 600; font-size: 15px; }
        .gal-chip { padding: 8px 0; text-align: center; font-size: 12.5px; font-family: 'Fraunces', serif; border: 1px solid transparent; }
        .gal-chip.sel { border: 1px solid #211d17; }
        .gal-field { background: transparent; border: 0; border-bottom: 1px solid #b7ab8e; padding: 9px 2px; font-size: 13.5px; color: #57504a; margin-top: 10px; text-align: left; }
        .gal-cta { height: 44px; background: transparent; border: 1px solid #211d17; color: #211d17; font-weight: 600; font-size: 11.5px; letter-spacing: .26em; text-transform: uppercase;
            display: flex; align-items: center; justify-content: center; margin-top: 16px; }
        .gal-check { font-family: 'Fraunces', serif; font-size: 28px; margin: 0 0 8px; }

        /* ═══ 18 · BOUNCE — playful rounded ═══ */
        .w-bounce { background: #fdf6ec; color: #453629; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .bou-card { background: #fff; border-radius: 24px; padding: 18px; box-shadow: 0 6px 0 #f0dfc8; }
        .bou-t { font-family: 'Fraunces', serif; font-weight: 700; font-size: 17px; }
        .bou-dots { display: flex; gap: 5px; margin-top: 7px; }
        .bou-dots i { width: 9px; height: 9px; border-radius: 50%; background: #f0dfc8; }
        .bou-dots .on { background: #f08a5d; }
        .bou-opt { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-radius: 99px; margin-top: 8px; font-size: 13.5px; font-weight: 700; background: #faf1e4; }
        .bou-opt.sel { background: #ffe1d1; color: #a34a20; }
        .bou-chip { padding: 10px 0; text-align: center; border-radius: 99px; font-size: 12.5px; font-weight: 800; background: #faf1e4; }
        .bou-chip.sel { background: #824c71; color: #fff; }
        .bou-field { background: #faf1e4; border: 0; border-radius: 99px; padding: 11px 16px; font-size: 13px; font-weight: 600; color: #6b584a; margin-top: 8px; }
        .bou-cta { height: 48px; border-radius: 99px; background: #f08a5d; color: #fff; font-weight: 800; font-size: 15px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; box-shadow: 0 5px 0 #c96a41; }
        .bou-check { width: 50px; height: 50px; border-radius: 50%; background: #dff0d5; color: #4c7a3c;
            display: flex; align-items: center; justify-content: center; font-size: 23px; font-weight: 800; margin: 4px auto 10px; }

        /* ═══ 19 · LADDER — vertical stepper ═══ */
        .w-ladder { background: #f2f0eb; color: #26231d; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .lad-card { background: #fff; border: 1px solid #e7e4dc; border-radius: 16px; padding: 20px; width: 380px; max-width: 100%; }
        .lad-step { display: flex; gap: 14px; position: relative; padding-bottom: 20px; }
        .lad-step::before { content: ''; position: absolute; left: 13px; top: 30px; bottom: 0; width: 2px; background: #eae6dd; }
        .lad-step:last-child::before { display: none; }
        .lad-n { flex: 0 0 28px; width: 28px; height: 28px; border-radius: 50%; background: #eae6dd; color: #79715f;
            display: flex; align-items: center; justify-content: center; font-size: 12.5px; font-weight: 700; }
        .lad-step.done .lad-n, .lad-step.now .lad-n { background: #824c71; color: #fff; }
        .lad-h { font-size: 13.5px; font-weight: 700; padding-top: 5px; }
        .lad-sub { font-size: 12px; color: #7c7565; margin-top: 2px; }
        .lad-chiprow { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
        .lad-chip { border: 1.5px solid #e2ded4; border-radius: 8px; padding: 6px 10px; font-size: 11.5px; font-weight: 600; }
        .lad-chip.sel { background: #824c71; color: #fff; border-color: #824c71; }
        .lad-field { background: #f8f6f1; border: 1.5px solid #e2ded4; border-radius: 9px; padding: 8px 11px; font-size: 12px; color: #56524a; margin-top: 6px; }
        .lad-cta { height: 42px; border-radius: 10px; background: #26231d; color: #fff; font-weight: 700; font-size: 13.5px;
            display: flex; align-items: center; justify-content: center; margin-top: 6px; }

        /* ═══ 20 · AURA — gradient modern ═══ */
        .w-aura { background:
            radial-gradient(34rem 22rem at 8% -10%, rgb(110 86 207 / .22), transparent 55%),
            radial-gradient(30rem 20rem at 92% 0%, rgb(199 106 140 / .2), transparent 55%),
            #fefdfb; color: #241e2b; font-family: 'Hanken Grotesk', sans-serif; border-radius: 18px; }
        .aua-card { position: relative; background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 8px 26px rgb(60 40 110 / .1); }
        .aua-card::before { content: ''; position: absolute; inset: 0; border-radius: 18px; padding: 1.5px;
            background: linear-gradient(130deg, rgb(110 86 207 / .5), rgb(199 106 140 / .35), rgb(212 154 78 / .35));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .aua-t { font-family: 'Schibsted Grotesk', sans-serif; font-weight: 800; font-size: 16px; letter-spacing: -.02em; }
        .aua-grad { background: linear-gradient(110deg, #6e56cf, #b04a7d); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .aua-opt { display: flex; justify-content: space-between; padding: 11px 13px; border-radius: 12px; border: 1.5px solid #ece9f2; margin-top: 8px; font-size: 13.5px; }
        .aua-opt.sel { border-color: #6e56cf; background: linear-gradient(110deg, rgb(110 86 207 / .08), rgb(199 106 140 / .07)); }
        .aua-chip { padding: 8px 0; text-align: center; border-radius: 10px; border: 1.5px solid #ece9f2; font-size: 12.5px; font-weight: 600; }
        .aua-chip.sel { background: linear-gradient(110deg, #6e56cf, #b04a7d); color: #fff; border-color: transparent; }
        .aua-field { background: #faf9fc; border: 1.5px solid #e6e2ef; border-radius: 11px; padding: 9px 12px; font-size: 13px; color: #56506b; margin-top: 8px; }
        .aua-cta { height: 44px; border-radius: 12px; background: linear-gradient(110deg, #6e56cf, #b04a7d); color: #fff; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; margin-top: 14px; box-shadow: 0 8px 22px rgb(110 86 207 / .35); }
        .aua-check { width: 46px; height: 46px; border-radius: 50%; background: linear-gradient(110deg, #6e56cf, #b04a7d); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; margin: 4px auto 10px; }
    </style>

    {{-- ————— Intro ————— --}}
    <div class="wg-band" style="padding-bottom: 28px;">
        <div class="wg-inner">
            <div style="font-family:'Fraunces',serif; font-size:15px; letter-spacing:.14em; text-transform:uppercase; color:#d9a9c6;">Widget designs</div>
            <h1 style="font-family:'Fraunces',serif; font-size:32px; font-weight:600; margin-top:12px;">Twenty takes on the booking widget, full flow each.</h1>
            <p style="font-size:14px; opacity:.75; max-width:620px; line-height:1.6; margin-top:10px;">Every design shows the complete embed flow — service, stylist, date and time, details, confirmation — as five step cards. Each card is rendered at 300px, the width the widget occupies on a phone, so what you see IS the mobile experience; embedded on desktop the card simply centres in its container. Scroll a row horizontally to walk the flow.</p>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:20px;">
                @foreach ([['#frost', 'A · Frost'], ['#swift', 'B · Swift'], ['#maison', 'C · Maison'], ['#punch', 'D · Punch'], ['#folio', 'E · Folio'], ['#vitrine', '6 · Vitrine'], ['#stack', '7 · Stack'], ['#scroll', '8 · Scroll'], ['#duet', '9 · Duet'], ['#kalend', '10 · Kalend'], ['#parla', '11 · Parla'], ['#mono', '12 · Mono'], ['#atelier', '13 · Atelier'], ['#blocks', '14 · Blocks'], ['#nocturne', '15 · Nocturne'], ['#inline', '16 · Inline'], ['#gallery', '17 · Gallery'], ['#bounce', '18 · Bounce'], ['#ladder', '19 · Ladder'], ['#aura', '20 · Aura']] as [$href, $label])
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

    <section id="vitrine" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">6 · Vitrine — full liquid glass</h2>
                <p style="font-size:14px; opacity:.7;">All-in visionOS: every step a floating frosted panel with refraction edges over a colourful gradient.</p>
            </div>
            <div class="w-vitrine" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="vit-card">
                        <div class="vit-t">Choose a service</div>
                        <div class="vit-dots"><i class="on"></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($services as $i => $sv)
                            <div class="vit-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="vit-card">
                        <div class="vit-t">Choose a stylist</div>
                        <div class="vit-dots"><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($stylists as $i => $st)
                            <div class="vit-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="vit-card">
                        <div class="vit-t">Pick a time</div>
                        <div class="vit-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i></div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="vit-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="vit-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="vit-card">
                        <div class="vit-t">Your details</div>
                        <div class="vit-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i></div>
                        <div class="vit-field">Amelia Hart</div>
                        <div class="vit-field">07700 900123</div>
                        <div class="vit-field" style="opacity:.6;">Email (optional)</div>
                        <div class="vit-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="vit-card">
                        <div class="vit-t">All set</div>
                        <div class="vit-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i></div>
                        <div class="vit-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="stack" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">7 · Stack — card stack wizard</h2>
                <p style="font-size:14px; opacity:.7;">App-like wizard: each step a clean full card sliding off a stack, big friendly targets, plum commitment.</p>
            </div>
            <div class="w-stack" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="sta-card">
                        <div class="sta-count">Step 1 of 5</div>
                        <div class="sta-t" style="margin-top:3px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="sta-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="sta-card">
                        <div class="sta-count">Step 2 of 5</div>
                        <div class="sta-t" style="margin-top:3px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="sta-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="sta-card">
                        <div class="sta-count">Step 3 of 5</div>
                        <div class="sta-t" style="margin-top:3px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="sta-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="sta-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="sta-card">
                        <div class="sta-count">Step 4 of 5</div>
                        <div class="sta-t" style="margin-top:3px;">Your details</div>
                        <div class="sta-field">Amelia Hart</div>
                        <div class="sta-field">07700 900123</div>
                        <div class="sta-field" style="opacity:.6;">Email (optional)</div>
                        <div class="sta-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="sta-card">
                        <div class="sta-count">Step 5 of 5</div>
                        <div class="sta-t" style="margin-top:3px;">All set</div>
                        <div class="sta-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="scroll" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">8 · Scroll — single-page</h2>
                <p style="font-size:14px; opacity:.7;">The whole booking on one scrollable card — no steps, everything visible, fast.</p>
            </div>
            <div class="w-scroll" style="padding:24px; margin-top:16px;">
                <div style="display:flex; justify-content:center;">
                    <div class="scr-card">
                        <div class="scr-sec">
                            <div class="scr-h">Choose a service</div>
                            @foreach ($services as $i => $sv)
                                <span class="scr-opt {{ $i === 0 ? 'sel' : '' }}">{{ $sv['name'] }} <span style="opacity:.5; font-size:11px;">{{ $sv['meta'] }}</span></span>
                            @endforeach
                        </div>
                        <div class="scr-sec">
                            <div class="scr-h">Choose a stylist</div>
                            @foreach ($stylists as $i => $st)
                                <span class="scr-opt {{ $i === 1 ? 'sel' : '' }}">{{ $st }}</span>
                            @endforeach
                        </div>
                        <div class="scr-sec">
                            <div class="scr-h">Pick a time</div>
                            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:5px;">
                                @foreach ($days as $i => [$d, $n])
                                    <div class="scr-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.2;">{{ $d }}<br><span style="font-size:13px;">{{ $n }}</span></div>
                                @endforeach
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:5px; margin-top:8px;">
                                @foreach ($times as $i => $tm)
                                    <div class="scr-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                                @endforeach
                            </div>
                        </div>
                        <div class="scr-sec">
                            <div class="scr-h">Your details</div>
                            <div class="scr-field">Amelia Hart</div>
                            <div class="scr-field">07700 900123</div>
                        </div>
                        <div class="scr-cta">Confirm booking — you're booked in one tap</div>
                    </div>
                </div>
                <p style="font-size:12.5px; opacity:.55; text-align:center; margin-top:12px;">One page, no steps — after confirming, the card swaps to "You're booked" with the summary.</p>
            </div>
        </div>
    </section>

    <section id="duet" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">9 · Duet — split layout</h2>
                <p style="font-size:14px; opacity:.7;">Two panes: the salon and a live summary on the left, controls on the right; stacks on phones.</p>
            </div>
            <div class="w-duet" style="padding:24px; margin-top:16px;">
                <div style="display:flex; justify-content:center;">
                    <div class="due-card">
                        <div class="due-info">
                            <div style="font-size:10.5px; font-weight:600; letter-spacing:.18em; text-transform:uppercase; color:#d9a9c6;">Glow Bar</div>
                            <div class="hd2" style="margin-top:8px;">Book your visit</div>
                            <div class="due-sum">Full colour · 2 h · £95<br>with Maya<br>Saturday 12 July, 1:00 PM</div>
                            <div style="margin-top:14px; font-size:12px; opacity:.6;">1 King Street · open Tue–Sat</div>
                        </div>
                        <div class="due-main">
                            <div class="due-h">Choose a service</div>
                            @foreach ($services as $i => $sv)
                                <div class="due-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.5; font-size:11.5px;">{{ $sv['meta'] }}</span></div>
                            @endforeach
                            <div class="due-h" style="margin-top:14px;">Pick a time</div>
                            <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:5px; margin-top:8px;">
                                @foreach ($times as $i => $tm)
                                    <div class="due-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                                @endforeach
                            </div>
                            <div class="due-h" style="margin-top:14px;">Your details</div>
                            <div class="due-field">Amelia Hart · 07700 900123</div>
                            <div class="due-cta">Confirm booking</div>
                        </div>
                    </div>
                </div>
                <p style="font-size:12.5px; opacity:.55; text-align:center; margin-top:12px;">Summary pane updates live as you choose; the panes stack vertically on a phone. Confirmation replaces the right pane with "You're booked".</p>
            </div>
        </div>
    </section>

    <section id="kalend" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">10 · Kalend — calendar-first</h2>
                <p style="font-size:14px; opacity:.7;">A beautiful month grid as the hero; service and stylist tuck into compact selectors.</p>
            </div>
            <div class="w-kalend" style="padding:24px; margin-top:16px;">
                <div style="display:flex; justify-content:center;">
                    <div class="kal-card">
                        <div style="font-weight:700; font-size:15px;">Pick a time</div>
                        <div style="margin-top:9px;">
                            <span class="kal-sel">Full colour · £95 <span style="opacity:.4;">▾</span></span>
                            <span class="kal-sel">Maya <span style="opacity:.4;">▾</span></span>
                        </div>
                        <div class="kal-grid">
                            @foreach (['M','T','W','T','F','S','S'] as $dw)<div class="dow">{{ $dw }}</div>@endforeach
                            @foreach (range(1, 28) as $d)
                                <div class="day {{ $d === 12 ? 'sel' : (in_array($d % 7, [2, 4, 6]) ? 'av' : ($d < 12 ? 'off' : '')) }}">{{ $d }}</div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:12px;">
                            @foreach ($times as $i => $tm)
                                <div class="kal-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                        <div class="kal-field">Amelia Hart · 07700 900123</div>
                        <div class="kal-cta">Confirm booking</div>
                    </div>
                </div>
                <p style="font-size:12.5px; opacity:.55; text-align:center; margin-top:12px;">The calendar is the hero; service and stylist are compact selectors above it. Confirmation swaps the card body for "You're booked".</p>
            </div>
        </div>
    </section>

    <section id="parla" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">11 · Parla — conversational</h2>
                <p style="font-size:14px; opacity:.7;">A warm guided chat: one question at a time, answers as bubbles, times as quick replies.</p>
            </div>
            <div class="w-parla" style="padding:24px; margin-top:16px;">
                <div style="display:flex; justify-content:center;">
                    <div class="par-card">
                        <div style="font-weight:700; font-size:14px;">Glow Bar</div>
                        <div class="par-q">Hi! What would you like to book?</div>
                        <div class="par-a">Full colour · 2 h · £95</div>
                        <div class="par-q">Lovely. Who would you like it with?</div>
                        <div class="par-a">Maya</div>
                        <div class="par-q">Maya has these times on Saturday 12 July —</div>
                        <div class="par-chips">
                            @foreach ($times as $i => $tm)
                                <span class="par-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</span>
                            @endforeach
                        </div>
                        <div class="par-q" style="margin-top:12px;">Perfect. And your name and number?</div>
                        <div class="par-field">Amelia Hart</div>
                        <div class="par-field">07700 900123</div>
                        <div class="par-cta">Book it — you're all set</div>
                    </div>
                </div>
                <p style="font-size:12.5px; opacity:.55; text-align:center; margin-top:12px;">One warm question at a time; the thread ends with the booked summary bubble.</p>
            </div>
        </div>
    </section>

    <section id="mono" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">12 · Mono — minimal mono</h2>
                <p style="font-size:14px; opacity:.7;">Near-monochrome and effortless: tiny tracked labels, an em-dash selection mark, one plum accent.</p>
            </div>
            <div class="w-mono" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="mon-card">
                        <div class="mon-lbl">Step 1 of 5</div>
                        <div class="mon-t" style="margin-top:5px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="mon-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="mon-card">
                        <div class="mon-lbl">Step 2 of 5</div>
                        <div class="mon-t" style="margin-top:5px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="mon-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="mon-card">
                        <div class="mon-lbl">Step 3 of 5</div>
                        <div class="mon-t" style="margin-top:5px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="mon-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="mon-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="mon-card">
                        <div class="mon-lbl">Step 4 of 5</div>
                        <div class="mon-t" style="margin-top:5px;">Your details</div>
                        <div class="mon-field">Amelia Hart</div>
                        <div class="mon-field">07700 900123</div>
                        <div class="mon-field" style="opacity:.6;">Email (optional)</div>
                        <div class="mon-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="mon-card">
                        <div class="mon-lbl">Step 5 of 5</div>
                        <div class="mon-t" style="margin-top:5px;">All set</div>
                        <div class="mon-check">—</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="atelier" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">13 · Atelier — boutique arch</h2>
                <p style="font-size:14px; opacity:.7;">A salon-mirror arch card in cream and terracotta with serif warmth. The most interior-design one.</p>
            </div>
            <div class="w-atelier" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="ate-card">
                        <div class="ate-t">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="ate-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="ate-card">
                        <div class="ate-t">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="ate-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="ate-card">
                        <div class="ate-t">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="ate-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="ate-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="ate-card">
                        <div class="ate-t">Your details</div>
                        <div class="ate-field">Amelia Hart</div>
                        <div class="ate-field">07700 900123</div>
                        <div class="ate-field" style="opacity:.6;">Email (optional)</div>
                        <div class="ate-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="ate-card">
                        <div class="ate-t">All set</div>
                        <div class="ate-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="blocks" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">14 · Blocks — bold color block</h2>
                <p style="font-size:14px; opacity:.7;">A different flat colour block heads each step; strong type, zero decoration. Energetic clarity.</p>
            </div>
            <div class="w-blocks" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="blo-card">
                        <div class="blo-head" style="background:#6b3358;">Choose a service <span style="opacity:.6; font-weight:600; font-size:11px;">· 1/5</span></div><div class="blo-body">
                        @foreach ($services as $i => $sv)
                            <div class="blo-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="blo-card">
                        <div class="blo-head" style="background:#2e5d43;">Choose a stylist <span style="opacity:.6; font-weight:600; font-size:11px;">· 2/5</span></div><div class="blo-body">
                        @foreach ($stylists as $i => $st)
                            <div class="blo-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="blo-card">
                        <div class="blo-head" style="background:#b3661f;">Pick a time <span style="opacity:.6; font-weight:600; font-size:11px;">· 3/5</span></div><div class="blo-body">
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="blo-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="blo-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="blo-card">
                        <div class="blo-head" style="background:#274690;">Your details <span style="opacity:.6; font-weight:600; font-size:11px;">· 4/5</span></div><div class="blo-body">
                        <div class="blo-field">Amelia Hart</div>
                        <div class="blo-field">07700 900123</div>
                        <div class="blo-field" style="opacity:.6;">Email (optional)</div>
                        <div class="blo-cta">Confirm booking</div>
                    </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="blo-card">
                        <div class="blo-head" style="background:#1e1a14;">All set <span style="opacity:.6; font-weight:600; font-size:11px;">· 5/5</span></div><div class="blo-body">
                        <div class="blo-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="nocturne" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">15 · Nocturne — dark elegant</h2>
                <p style="font-size:14px; opacity:.7;">The dark option: deep aubergine night, a rose-gold accent, serif headings. Premium and moody.</p>
            </div>
            <div class="w-nocturne" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="noc-card">
                        <div class="noc-lbl">Step 1 of 5</div>
                        <div class="noc-t" style="margin-top:5px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="noc-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="noc-card">
                        <div class="noc-lbl">Step 2 of 5</div>
                        <div class="noc-t" style="margin-top:5px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="noc-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="noc-card">
                        <div class="noc-lbl">Step 3 of 5</div>
                        <div class="noc-t" style="margin-top:5px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="noc-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="noc-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="noc-card">
                        <div class="noc-lbl">Step 4 of 5</div>
                        <div class="noc-t" style="margin-top:5px;">Your details</div>
                        <div class="noc-field">Amelia Hart</div>
                        <div class="noc-field">07700 900123</div>
                        <div class="noc-field" style="opacity:.6;">Email (optional)</div>
                        <div class="noc-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="noc-card">
                        <div class="noc-lbl">Step 5 of 5</div>
                        <div class="noc-t" style="margin-top:5px;">All set</div>
                        <div class="noc-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="inline" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">16 · Inline — compact</h2>
                <p style="font-size:14px; opacity:.7;">A small-footprint embed for sidebars: dense, quiet, quick. Books in a 260px column.</p>
            </div>
            <div class="w-inline" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="inl-card">
                        <div class="inl-t">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="inl-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="inl-card">
                        <div class="inl-t">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="inl-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="inl-card">
                        <div class="inl-t">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="inl-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="inl-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="inl-card">
                        <div class="inl-t">Your details</div>
                        <div class="inl-field">Amelia Hart</div>
                        <div class="inl-field">07700 900123</div>
                        <div class="inl-field" style="opacity:.6;">Email (optional)</div>
                        <div class="inl-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="inl-card">
                        <div class="inl-t">All set</div>
                        <div class="inl-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="gallery" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">17 · Gallery — editorial luxe</h2>
                <p style="font-size:14px; opacity:.7;">A framed lookbook plate: centred serif, 34%-tracked capitals, hairline choices. Understated luxury.</p>
            </div>
            <div class="w-gallery" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="gal-card">
                        <div class="gal-lbl">Step 1 of 5</div>
                        <div class="gal-t" style="margin-top:5px;">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="gal-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="gal-card">
                        <div class="gal-lbl">Step 2 of 5</div>
                        <div class="gal-t" style="margin-top:5px;">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="gal-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="gal-card">
                        <div class="gal-lbl">Step 3 of 5</div>
                        <div class="gal-t" style="margin-top:5px;">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="gal-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="gal-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="gal-card">
                        <div class="gal-lbl">Step 4 of 5</div>
                        <div class="gal-t" style="margin-top:5px;">Your details</div>
                        <div class="gal-field">Amelia Hart</div>
                        <div class="gal-field">07700 900123</div>
                        <div class="gal-field" style="opacity:.6;">Email (optional)</div>
                        <div class="gal-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="gal-card">
                        <div class="gal-lbl">Step 5 of 5</div>
                        <div class="gal-t" style="margin-top:5px;">All set</div>
                        <div class="gal-check">fin</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="bounce" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">18 · Bounce — playful rounded</h2>
                <p style="font-size:14px; opacity:.7;">Everything a soft pill: cheerful cream, coral, and plum, big bouncy targets. Marblism-warm and friendly.</p>
            </div>
            <div class="w-bounce" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="bou-card">
                        <div class="bou-t">Choose a service</div>
                        <div class="bou-dots"><i class="on"></i><i class=""></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($services as $i => $sv)
                            <div class="bou-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="bou-card">
                        <div class="bou-t">Choose a stylist</div>
                        <div class="bou-dots"><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i><i class=""></i></div>
                        @foreach ($stylists as $i => $st)
                            <div class="bou-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="bou-card">
                        <div class="bou-t">Pick a time</div>
                        <div class="bou-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i><i class=""></i></div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="bou-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="bou-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="bou-card">
                        <div class="bou-t">Your details</div>
                        <div class="bou-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class=""></i></div>
                        <div class="bou-field">Amelia Hart</div>
                        <div class="bou-field">07700 900123</div>
                        <div class="bou-field" style="opacity:.6;">Email (optional)</div>
                        <div class="bou-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="bou-card">
                        <div class="bou-t">All set</div>
                        <div class="bou-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i></div>
                        <div class="bou-check">✓</div>
                        <div style="text-align:center; font-weight:700; font-size:15px;">You're booked</div>
                        <p style="text-align:center; font-size:12.5px; opacity:.7; margin-top:6px; line-height:1.55;">Full colour with Maya<br>Saturday 12 July · 1:00 PM<br>A confirmation is on its way to your phone.</p>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    <section id="ladder" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">19 · Ladder — vertical stepper</h2>
                <p style="font-size:14px; opacity:.7;">A numbered timeline runs down the card; answered steps collapse, the active one expands.</p>
            </div>
            <div class="w-ladder" style="padding:24px; margin-top:16px;">
                <div style="display:flex; justify-content:center;">
                    <div class="lad-card">
                        <div class="lad-step done">
                            <div class="lad-n">1</div>
                            <div style="flex:1;"><div class="lad-h">Choose a service</div><div class="lad-sub">Full colour · 2 h · £95</div></div>
                        </div>
                        <div class="lad-step done">
                            <div class="lad-n">2</div>
                            <div style="flex:1;"><div class="lad-h">Choose a stylist</div><div class="lad-sub">Maya</div></div>
                        </div>
                        <div class="lad-step now">
                            <div class="lad-n">3</div>
                            <div style="flex:1;">
                                <div class="lad-h">Pick a time — Saturday 12 July</div>
                                <div class="lad-chiprow">
                                    @foreach ($times as $i => $tm)
                                        <span class="lad-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="lad-step">
                            <div class="lad-n">4</div>
                            <div style="flex:1;">
                                <div class="lad-h">Your details</div>
                                <div class="lad-field">Amelia Hart · 07700 900123</div>
                            </div>
                        </div>
                        <div class="lad-step">
                            <div class="lad-n">5</div>
                            <div style="flex:1;"><div class="lad-h">You're booked</div><div class="lad-sub">Confirmation by text</div><div class="lad-cta" style="margin-top:8px;">Confirm booking</div></div>
                        </div>
                    </div>
                </div>
                <p style="font-size:12.5px; opacity:.55; text-align:center; margin-top:12px;">Progress runs down the rail; earlier steps collapse to their answers and stay tappable to edit.</p>
            </div>
        </div>
    </section>

    <section id="aura" class="wg-band">
        <div class="wg-inner">
            <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
                <h2 style="font-family:'Fraunces',serif; font-size:22px; font-weight:600;">20 · Aura — gradient modern</h2>
                <p style="font-size:14px; opacity:.7;">Aurora ring borders and a violet-rose gradient CTA over clean white. Stripe/Cosmos energy.</p>
            </div>
            <div class="w-aura" style="padding:24px; margin-top:16px;">
                <div class="wg-row">
                <div class="wg-step">
                    <div class="wg-stepname">Step 1 · Service</div>
                    <div class="aua-card">
                        <div class="aua-t">Choose a service</div>
                        @foreach ($services as $i => $sv)
                            <div class="aua-opt {{ $i === 0 ? 'sel' : '' }}"><span>{{ $sv['name'] }}</span><span style="opacity:.55; font-size:12px;">{{ $sv['meta'] }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 2 · Stylist</div>
                    <div class="aua-card">
                        <div class="aua-t">Choose a stylist</div>
                        @foreach ($stylists as $i => $st)
                            <div class="aua-opt {{ $i === 1 ? 'sel' : '' }}"><span>{{ $st }}</span><span style="opacity:.45; font-size:11px;">{{ $i === 0 ? 'first available' : 'stylist' }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 3 · Date & time</div>
                    <div class="aua-card">
                        <div class="aua-t">Pick a time</div>
                        <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:6px; margin-top:10px;">
                            @foreach ($days as $i => [$d, $n])
                                <div class="aua-chip {{ $i === 0 ? 'sel' : '' }}" style="line-height:1.25;">{{ $d }}<br><span style="font-size:14px;">{{ $n }}</span></div>
                            @endforeach
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px;">
                            @foreach ($times as $i => $tm)
                                <div class="aua-chip {{ $i === 3 ? 'sel' : '' }}">{{ $tm }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 4 · Your details</div>
                    <div class="aua-card">
                        <div class="aua-t">Your details</div>
                        <div class="aua-field">Amelia Hart</div>
                        <div class="aua-field">07700 900123</div>
                        <div class="aua-field" style="opacity:.6;">Email (optional)</div>
                        <div class="aua-cta">Confirm booking</div>
                    </div>
                </div>
                <div class="wg-step">
                    <div class="wg-stepname">Step 5 · Confirmation</div>
                    <div class="aua-card">
                        <div class="aua-t">All set</div>
                        <div class="aua-check">✓</div>
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
