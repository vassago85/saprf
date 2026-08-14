{{--
    PWA + mobile browser chrome meta tags.

    Included from the head of both app.blade.php and guest.blade.php so
    every page — authed dashboard and public landing/standings — is
    installable and behaves like a native app once added to the home
    screen. Keep the two layouts in sync by editing this one file.

    theme-color drives the browser address-bar tint on Android Chrome and
    the status-bar area when the installed PWA launches full-screen. Use
    the same SAPRF green as the manifest theme_color.
--}}

{{-- Web App Manifest --}}
<link rel="manifest" href="/manifest.webmanifest">

{{-- Browser chrome / status bar tint --}}
<meta name="theme-color" content="#0e6b2f">

{{-- iOS: enable full-screen "web app" mode when launched from home
     screen (removes Safari's address bar + bottom bar). --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="SAPRF">

{{-- iOS uses this instead of the manifest icons array. --}}
<link rel="apple-touch-icon" href="/images/pwa/apple-touch-icon.png">

{{-- Windows / older MS Edge tile colour. --}}
<meta name="msapplication-TileColor" content="#0e6b2f">
