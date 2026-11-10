<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Prime Mobile' }}</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-background font-sans text-foreground antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-6 flex justify-center"><x-ui::logo size="lg" /></div>
        <div class="rounded-2xl border border-border bg-card p-6 shadow-sm">
            {{ $slot }}
        </div>
    </main>
</body>
</html>
