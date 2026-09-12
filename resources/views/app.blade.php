<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if ($cspNonce = Vite::cspNonce())
            <meta name="csp-nonce" content="{{ $cspNonce }}">
        @endif
        <title>Horizon</title>

        <script @if ($cspNonce !== null)nonce="{{ $cspNonce }}"@endif>
            (() => {
                try {
                    const scheme = localStorage.getItem('horizonColorScheme') ?? 'system';
                    const dark = scheme === 'dark'
                        || (scheme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);

                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch {
                }
            })();
        </script>

        @inject('assets', 'DevactionLabs\Zenith\Assets\AssetManifest')
        <link rel="icon" href="{{ $assets->favicon() }}" type="image/svg+xml" sizes="any" data-horizon-favicon>
        {!! $assets->tags() !!}

        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
