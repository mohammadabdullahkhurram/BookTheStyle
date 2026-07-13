{{-- The embed loader served as /widget.js from the app host. Dependency-free
     and defensive: it must be safe to run on ANY external site. Finds every
     div[data-bookthestyle-salon], injects a responsive iframe pointing at
     that salon's widget page, and auto-resizes the iframe from the page's
     bts:resize postMessage (origin-checked against the injected src). --}}(function () {
    'use strict';

    var DOMAIN = @json(config('app.domain'));
    var SCHEME = @json(app()->environment('local') ? 'http' : 'https');
    var MARKER = 'data-bookthestyle-salon';
    var frames = [];

    function inject(container) {
        if (container.getAttribute('data-bts-loaded') === '1') { return; }
        container.setAttribute('data-bts-loaded', '1');

        var slug = (container.getAttribute(MARKER) || '').trim().toLowerCase();
        if (!/^[a-z0-9-]+$/.test(slug)) { return; }

        var origin = SCHEME + '://' + slug + '.' + DOMAIN;
        var src = origin + '/widget';
        // Optional specific widget (multi-widget salons): its public id.
        var widget = (container.getAttribute('data-bookthestyle-widget') || '').trim().toLowerCase();
        if (/^[a-z0-9]{6,32}$/.test(widget)) { src += '/' + widget; }
        var params = [];
        var accent = container.getAttribute('data-accent');
        var service = container.getAttribute('data-service');
        if (accent) { params.push('accent=' + encodeURIComponent(accent)); }
        if (service) { params.push('service=' + encodeURIComponent(service)); }
        if (params.length) { src += '?' + params.join('&'); }

        var iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.title = 'Book an appointment';
        iframe.loading = 'lazy';
        iframe.style.width = '100%';
        iframe.style.border = '0';
        iframe.style.display = 'block';
        iframe.style.minHeight = '480px';
        iframe.setAttribute('scrolling', 'no');

        container.appendChild(iframe);
        frames.push({ origin: origin, iframe: iframe });
    }

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== 'bts:resize') { return; }
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].origin === event.origin && frames[i].iframe.contentWindow === event.source) {
                var height = parseInt(event.data.height, 10);
                if (height > 0 && height < 10000) {
                    frames[i].iframe.style.height = height + 'px';
                    frames[i].iframe.style.minHeight = '0';
                }
            }
        }
    });

    function boot() {
        var containers = document.querySelectorAll('[' + MARKER + ']');
        for (var i = 0; i < containers.length; i++) { inject(containers[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
