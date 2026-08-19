{{--
    Google tag (gtag.js) for GA4. Included as the first child of <head>
    on every layout. Empty GOOGLE_ANALYTICS_ID disables the snippet.
--}}
@php
    $measurementId = config('services.google.analytics_id');
@endphp
@if (filled($measurementId))
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $measurementId }}"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', '{{ $measurementId }}');
    </script>
@endif
