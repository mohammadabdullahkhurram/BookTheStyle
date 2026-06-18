import '@toast-ui/calendar/dist/toastui-calendar.min.css';
import '../css/calendar.css';

import bookingCalendar from './calendar.js';

// Register the booking-calendar Alpine component. Livewire ships Alpine and
// fires `alpine:init` before it boots, so this runs before any x-data mounts.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('bookingCalendar', bookingCalendar);
});

// wire:navigate is single-origin SPA navigation. BookTheStyle spans the apex
// (e.g. lvh.me / bookthestyle.com) and per-salon subdomains ({slug}.…), so a
// wire:navigate link that crosses that boundary — the salon picker, "All
// salons", the sidebar's account/agency links — would be fetched cross-origin
// by Livewire, dropping the session cookie / hitting CORS and bouncing the user
// back to the landing page. For any cross-origin destination, cancel Livewire's
// SPA fetch and do a real, credentialed top-level navigation instead. Same-origin
// links keep their SPA behaviour. `alpine:navigate` is cancelable and Livewire
// honours preventDefault by skipping its own navigation.
document.addEventListener('alpine:navigate', (event) => {
    const destination = event.detail?.url;

    if (destination && destination.origin !== window.location.origin) {
        event.preventDefault();
        window.location.assign(destination.href);
    }
});
