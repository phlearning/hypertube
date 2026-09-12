import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

window.Pusher = Pusher;

// Only imported by pages that actually need real-time updates, so the
// WebSocket connection this opens is scoped to those page bundles rather
// than established on every page load.
const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    // A missing/misconfigured env var fails toward requiring TLS rather than
    // toward plaintext, so a misconfiguration surfaces as a loud connection
    // error instead of a silent mixed-content block on an https page.
    forceTLS: import.meta.env.VITE_REVERB_SCHEME !== 'http',
    enabledTransports: ['ws', 'wss'],
});

export default echo;
