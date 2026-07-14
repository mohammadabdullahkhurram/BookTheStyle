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

// Themed in-app confirmation — replaces the native browser confirm() that
// wire:confirm used. Any element inside a Livewire component calls
// $store.confirm.ask({title, message, confirmLabel, danger}, () => $wire...)
// and the single global modal (x-ui.confirm-modal in the app layout) renders
// it on-theme. Confirming runs the captured action; Cancel/Esc/scrim abort.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('confirm', {
        show: false,
        title: '',
        message: '',
        confirmLabel: '',
        danger: true,
        onConfirm: null,
        ask(options, onConfirm) {
            this.title = options.title ?? 'Are you sure?';
            this.message = options.message ?? '';
            this.confirmLabel = options.confirmLabel ?? 'Confirm';
            this.danger = options.danger ?? true;
            this.onConfirm = onConfirm;
            this.show = true;
        },
        cancel() {
            this.show = false;
            this.onConfirm = null;
        },
        proceed() {
            const run = this.onConfirm;
            this.cancel();
            if (run) run();
        },
    });
});
