@php
    $font = fn (string $file) => str_replace('\\', '/', resource_path('fonts/certificates/'.$file));
@endphp
<style>
    @font-face {
        font-family: 'Saira Condensed';
        font-style: normal;
        font-weight: 600;
        src: url('{{ $font('SairaCondensed-SemiBold.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Saira Condensed';
        font-style: normal;
        font-weight: 700;
        src: url('{{ $font('SairaCondensed-Bold.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Saira';
        font-style: normal;
        font-weight: 600;
        src: url('{{ $font('Saira-SemiBold.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'IBM Plex Mono';
        font-style: normal;
        font-weight: 400;
        src: url('{{ $font('IBMPlexMono-Regular.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'IBM Plex Mono';
        font-style: normal;
        font-weight: 500;
        src: url('{{ $font('IBMPlexMono-Medium.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'IBM Plex Mono';
        font-style: normal;
        font-weight: 600;
        src: url('{{ $font('IBMPlexMono-SemiBold.ttf') }}') format('truetype');
    }
</style>
