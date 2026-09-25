// Public runtime configuration. This file is safe to ship with the app.
// Set apiOrigin to the HTTPS origin of your own PHP backend before building
// the native app. Leave the existing value when using the hosted service.
window.ZCOC_CONFIG = Object.freeze({
    // Leave empty to keep the website using its current same-origin backend.
    // Set this only when the website should use the side-by-side API service.
    webApiOrigin: '',
    // The native Capacitor app always needs an absolute API origin.
    apiOrigin: 'https://z-coc.zeabur.app',
});
