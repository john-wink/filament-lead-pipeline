<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Report' }}</title>
    @livewireStyles
    <link rel="stylesheet" href="{{ asset('css/john-wink/filament-lead-pipeline/lead-pipeline-reports.css') }}">
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
