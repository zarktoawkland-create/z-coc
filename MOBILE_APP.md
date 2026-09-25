# z-coc Android App

The Android app uses Capacitor 8 with a locally bundled frontend. Cloud accounts,
cloud saves, multiplayer rooms, and the public library use the PHP API origin
configured in `assets/js/runtime-config.js`.

## Build prerequisites

- Node.js 22 or newer
- Android Studio 2025.2.1 or newer
- JDK 21 for Gradle builds (newer IDE-bundled JDKs may not be supported by the
  project's Gradle version yet)
- An Android SDK platform (API 24 or newer; API 36 is recommended)

Android Studio uses its bundled runtime for the IDE. Command-line Gradle builds
should use JDK 21 through `JAVA_HOME`. iOS builds require macOS and Xcode.

## Self-hosted API origin

Before building the native app, edit the public runtime configuration:

```js
// assets/js/runtime-config.js
webApiOrigin: 'https://api.your-domain.com',
apiOrigin: 'https://api.your-domain.com'
```

Both values should point to the HTTPS origin of the server that exposes `db_api.php`,
`room_api.php`, `library_api.php`, and `health.php`. Then run:

```text
pnpm mobile:sync
```

If the frontend and API share one domain, web requests are same-origin. For a
separate frontend domain or a native Capacitor build, configure exact origins
on the server:

```text
APP_ALLOWED_ORIGINS=https://app.your-domain.com,https://localhost,capacitor://localhost
```

## Commands

```text
pnpm install
pnpm mobile:sync
pnpm mobile:open
```

To build a debug APK from the command line after Android Studio and the SDK are
installed:

```text
pnpm mobile:build:debug
```

The resulting APK is written under `android/app/build/outputs/apk/debug/`.
