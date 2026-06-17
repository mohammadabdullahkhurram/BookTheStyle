import '@toast-ui/calendar/dist/toastui-calendar.min.css';
import '../css/calendar.css';

import bookingCalendar from './calendar.js';

// Register the booking-calendar Alpine component. Livewire ships Alpine and
// fires `alpine:init` before it boots, so this runs before any x-data mounts.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('bookingCalendar', bookingCalendar);
});
