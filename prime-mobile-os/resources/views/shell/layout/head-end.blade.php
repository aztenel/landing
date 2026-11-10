{{-- iPhone: safe areas need viewport-fit=cover (the last viewport meta wins). PWA «На экран Домой» without offline data. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FAFAFA" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0B0B0D" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Prime Mobile">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
@vite('resources/js/erp.js')
{{-- PWA: a service worker for the «Нет связи» page and static files only (public/sw.js) — no data is cached --}}
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); }); }</script>
