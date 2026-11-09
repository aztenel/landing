@php
    $accent = \App\Support\Ui\Shell::accent();
    $cfg = \App\Support\Ui\Shell::jsConfig();
@endphp
{{-- Accent of the firm + «старый ноутбук» of this device on <html> before the first paint (TZ 04 Э26, Э29). --}}
<style>{!! $accent->css() !!}</style>
<script>
    (function () {
        var d = document.documentElement, legacy = @json($cfg['legacy']);
        d.className = d.className.replace(/\baccent-\w+/g, '') + ' accent-{{ $accent->value }}';
        try {
            if (legacy === null) { legacy = localStorage.getItem('pm.legacy') === '1'; }
            else { localStorage.setItem('pm.legacy', legacy ? '1' : '0'); }
        } catch (e) {}
        if (legacy) { d.classList.add('legacy'); }
        window.PM_CONFIG = @json($cfg);
    })();
</script>
