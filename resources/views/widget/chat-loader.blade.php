{{-- The chat-bubble loader served as /chat.js from the app host. Script-only
     embed (no container div, like Intercom/GHL): the salon pastes ONE script
     tag; this injects a fixed bottom-corner bubble and, on first open, an
     iframe panel pointing at the salon's chat page. Dependency-free and
     defensive — safe to run on ANY external site, never throws into the
     host page. --}}(function () {
    'use strict';

    var DOMAIN = @json(config('app.domain'));
    var SCHEME = @json(app()->environment('local') ? 'http' : 'https');

    try {
        if (window.__btsChatLoaded) { return; }

        // Our own script tag carries the config attributes.
        var tag = document.currentScript || (function () {
            var all = document.querySelectorAll('script[data-bookthestyle-chat]');
            return all.length ? all[all.length - 1] : null;
        })();
        if (!tag) { return; }

        var slug = (tag.getAttribute('data-bookthestyle-chat') || '').trim().toLowerCase();
        if (!/^[a-z0-9-]+$/.test(slug)) { return; }
        window.__btsChatLoaded = true;

        var origin = SCHEME + '://' + slug + '.' + DOMAIN;
        var src = origin + '/chat-widget';
        var widget = (tag.getAttribute('data-bookthestyle-widget') || '').trim().toLowerCase();
        if (/^[a-z0-9]{6,32}$/.test(widget)) { src += '/' + widget; }
        var accent = (tag.getAttribute('data-accent') || '').trim();
        if (/^#?[0-9a-fA-F]{6}$/.test(accent)) {
            src += '?accent=' + encodeURIComponent(accent);
            accent = accent.charAt(0) === '#' ? accent : '#' + accent;
        } else {
            accent = '#824C71';
        }

        var open = false;
        var frame = null;

        // The bubble: a fixed bottom-right circle in the accent colour.
        var bubble = document.createElement('button');
        bubble.type = 'button';
        bubble.setAttribute('aria-label', 'Chat with us');
        bubble.style.cssText = 'position:fixed;bottom:20px;right:20px;width:56px;height:56px;' +
            'border-radius:50%;border:none;cursor:pointer;z-index:2147483000;' +
            'background:' + accent + ';color:#fff;display:flex;align-items:center;justify-content:center;' +
            'box-shadow:0 6px 24px rgba(12,12,18,.28);transition:transform .15s ease;padding:0;';
        bubble.onmouseenter = function () { bubble.style.transform = 'scale(1.06)'; };
        bubble.onmouseleave = function () { bubble.style.transform = 'scale(1)'; };

        var CHAT_ICON = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '<path d="M4.5 5.5h15A1.5 1.5 0 0 1 21 7v8a1.5 1.5 0 0 1-1.5 1.5H12l-4.6 3.68A.6.6 0 0 1 6.4 19.7V16.5h-1.9A1.5 1.5 0 0 1 3 15V7a1.5 1.5 0 0 1 1.5-1.5Z" fill="currentColor"/></svg>';
        var CLOSE_ICON = '<svg width="22" height="22" viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
            '<path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        bubble.innerHTML = CHAT_ICON;

        function panelStyle() {
            var mobile = window.innerWidth < 480;
            return 'position:fixed;z-index:2147483000;border:0;background:transparent;' +
                'box-shadow:0 12px 48px rgba(12,12,18,.3);border-radius:16px;overflow:hidden;' +
                (mobile
                    ? 'left:10px;right:10px;bottom:88px;top:10px;width:auto;height:auto;'
                    : 'right:20px;bottom:88px;width:382px;height:min(640px, calc(100vh - 110px));');
        }

        function toggle(show) {
            open = show;
            bubble.innerHTML = open ? CLOSE_ICON : CHAT_ICON;
            bubble.setAttribute('aria-label', open ? 'Close chat' : 'Chat with us');
            if (open && !frame) {
                frame = document.createElement('iframe');
                frame.src = src;
                frame.title = 'Chat with us';
                frame.style.cssText = panelStyle();
                document.body.appendChild(frame);
            } else if (frame) {
                frame.style.display = open ? 'block' : 'none';
                if (open) { frame.style.cssText = panelStyle(); }
            }
        }

        bubble.addEventListener('click', function () { toggle(!open); });

        window.addEventListener('message', function (event) {
            if (event.origin !== origin) { return; }
            if (event.data && event.data.type === 'bts:chat:close') { toggle(false); }
        });

        window.addEventListener('resize', function () {
            if (frame && open) { frame.style.cssText = panelStyle(); }
        });

        function boot() { document.body.appendChild(bubble); }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    } catch (e) { /* never break the host page */ }
})();
