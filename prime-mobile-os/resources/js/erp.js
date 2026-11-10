/*
 * ERP frame of Prime Mobile OS (module «shell»): hotkeys, USB and camera scanner, screen lock, «старый ноутбук».
 * Runs inside the Filament panel next to Livewire's Alpine. Target: Chrome 109 (Windows 7) and Safari 15+ (iPhone),
 * built by Vite with target chrome109/safari15 — no syntax newer than ES2020 reaches the browser.
 *
 * Public API for modules (window.PM):
 *   PM.scanner.open()                — camera sheet (iPhone); a USB scanner works everywhere without it
 *   window event «pm-scan» {code, kind: 'imei'|'barcode'|'text', source: 'usb'|'camera'|'manual'}
 *   PM.isImei(code)                  — 15 digits with a valid Luhn check digit
 *   PM.autoAdd(callback, ms = 800)   — runs callback 0.8 s after the last scan unless a new scan comes (TZ 04 Э3)
 *   PM.hotkeys.showHelp()            — list of page hotkeys («?»)
 */
const config = window.PM_CONFIG || {};

function csrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function post(url, body) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body || {}),
    });
}

// ------------------------------------------------------------------ IMEI

function isImei(code) {
    if (!/^\d{15}$/.test(code)) return false;
    let sum = 0;
    for (let i = 0; i < 14; i++) {
        let d = Number(code[i]);
        if (i % 2 === 1) {
            d *= 2;
            if (d > 9) d -= 9;
        }
        sum += d;
    }
    return (10 - (sum % 10)) % 10 === Number(code[14]);
}

function classify(raw) {
    const code = String(raw).trim().replace(/\s+/g, '');
    if (/^\d{15}$/.test(code)) return { code, kind: 'imei', valid: isImei(code) };
    if (/^\d{8,14}$/.test(code)) return { code, kind: 'barcode', valid: true };
    return { code, kind: 'text', valid: code.length > 0 };
}

function emitScan(raw, source) {
    const scan = classify(raw);
    if (!scan.code) return;
    window.dispatchEvent(new CustomEvent('pm-scan', { detail: Object.assign({ source }, scan) }));
}

// ------------------------------------------------------------------ USB scanner = fast keyboard input ending with Enter

const usb = { buffer: '', last: 0, startedAt: 0 };
const USB_MAX_GAP_MS = 35;   // a scanner types a character every few ms; a person — every 100+ ms
const USB_MIN_LENGTH = 8;

function isTypingField(el) {
    if (!el) return false;
    const tag = el.tagName;
    return el.isContentEditable || tag === 'TEXTAREA' || (tag === 'INPUT' && !el.hasAttribute('data-pm-scan-target'));
}

document.addEventListener('keydown', (e) => {
    const now = Date.now();
    if (e.key === 'Enter') {
        const fast = usb.buffer.length >= USB_MIN_LENGTH && (now - usb.startedAt) / usb.buffer.length < USB_MAX_GAP_MS;
        if (fast) {
            e.preventDefault();
            e.stopPropagation();
            // the scanner typed into a field: clean it so the code is not left in a search box
            const el = document.activeElement;
            if (el && 'value' in el && typeof el.value === 'string' && el.value.endsWith(usb.buffer)) {
                el.value = el.value.slice(0, el.value.length - usb.buffer.length);
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
            emitScan(usb.buffer, 'usb');
        }
        usb.buffer = '';
        return;
    }
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (now - usb.last > USB_MAX_GAP_MS * 3) {
            usb.buffer = '';
            usb.startedAt = now;
        }
        usb.buffer += e.key;
        usb.last = now;
    }
}, true);

// ------------------------------------------------------------------ auto-add after a scan (0.8 s, TZ 04 Э3)

let autoTimer = null;
function autoAdd(callback, ms) {
    clearTimeout(autoTimer);
    autoTimer = setTimeout(callback, typeof ms === 'number' ? ms : 800);
}

// ------------------------------------------------------------------ hotkeys (TZ 04 §0.6)

const KEY_NAMES = { Escape: 'Esc' };
function keyName(e) {
    const base = KEY_NAMES[e.key] || e.key;
    if (e.ctrlKey && (base === 'Enter' || base.toLowerCase() === 'p')) return 'Ctrl+' + (base === 'Enter' ? 'Enter' : 'P');
    return base;
}

function pageHotkeys() {
    return Array.isArray(window.PM_PAGE_HOTKEYS) ? window.PM_PAGE_HOTKEYS : [];
}

function runHotkey(hk) {
    if (hk.action === 'click' || hk.action === 'focus') {
        const el = document.querySelector(hk.target);
        if (!el) return false;
        hk.action === 'click' ? el.click() : el.focus();
        return true;
    }
    if (hk.action === 'dispatch') {
        window.dispatchEvent(new CustomEvent(hk.target));
        return true;
    }
    if (hk.action === 'call' && window.Livewire) {
        const root = document.querySelector('[wire\\:id]');
        if (!root) return false;
        const component = window.Livewire.find(root.getAttribute('wire:id'));
        if (component) component.call(hk.target);
        return Boolean(component);
    }
    return false;
}

document.addEventListener('keydown', (e) => {
    const name = keyName(e);
    if (name === '?' && !isTypingField(document.activeElement)) {
        e.preventDefault();
        hotkeys.showHelp();
        return;
    }
    const hk = pageHotkeys().find((h) => h.key === name);
    if (hk && runHotkey(hk)) {
        e.preventDefault();
    }
});

const hotkeys = {
    showHelp() {
        if (window.Alpine) window.Alpine.store('pmHotkeys').help = true;
    },
};

// ------------------------------------------------------------------ hints

const hints = {
    reset() {
        try {
            Object.keys(localStorage).filter((k) => k.indexOf('pm.hint.') === 0).forEach((k) => localStorage.removeItem(k));
        } catch (e) { /* storage may be closed (private mode) */ }
        window.location.reload();
    },
};

// ------------------------------------------------------------------ camera scanner (iPhone)

let reader = null;
let controls = null;

async function startCamera(store) {
    store.message = '';
    const video = document.getElementById('pm-scan-video');
    if (!video || !navigator.mediaDevices || !window.isSecureContext) {
        store.message = 'Камера доступна только по защищённой ссылке (https). Введите IMEI вручную';
        return;
    }
    try {
        const zxing = await import('@zxing/browser');
        reader = reader || new zxing.BrowserMultiFormatReader();
        controls = await reader.decodeFromConstraints(
            { video: { facingMode: 'environment' } },
            video,
            (result) => {
                if (!result) return;
                const scan = classify(result.getText());
                if (scan.kind === 'imei' && !scan.valid) {
                    store.message = 'IMEI с ошибкой: проверьте последнюю цифру';
                    return;
                }
                if (navigator.vibrate) navigator.vibrate(60);
                store.close();
                emitScan(scan.code, 'camera');
            },
        );
        const track = video.srcObject && video.srcObject.getVideoTracks ? video.srcObject.getVideoTracks()[0] : null;
        const caps = track && track.getCapabilities ? track.getCapabilities() : {};
        store.torchAvailable = Boolean(caps && caps.torch);
        store.track = track;
    } catch (err) {
        store.message = 'Камера недоступна. Разрешите доступ к камере или введите IMEI вручную';
    }
}

function stopCamera() {
    if (controls) {
        controls.stop();
        controls = null;
    }
}

// ------------------------------------------------------------------ screen lock (TZ 03 §9, Э1.3)

function lockStore() {
    return {
        locked: false,
        warning: false,
        secondsLeft: 0,
        lastActivity: Date.now(),
        lastPing: 0,
        init() {
            const minutes = Number(config.lockMinutes || 0);
            if (!minutes || !config.routes) return;
            const touch = () => {
                if (this.locked) return;
                this.lastActivity = Date.now();
                this.warning = false;
                if (Date.now() - this.lastPing > 60000) {
                    this.lastPing = Date.now();
                    post(config.routes.activity).catch(() => {});
                }
            };
            ['keydown', 'pointerdown', 'touchstart', 'wheel'].forEach((ev) => document.addEventListener(ev, touch, { passive: true }));
            setInterval(() => {
                if (this.locked) return;
                const idle = (Date.now() - this.lastActivity) / 1000;
                const limit = minutes * 60;
                const warn = Number(config.warnSeconds || 30);
                this.secondsLeft = Math.max(0, Math.ceil(limit - idle));
                this.warning = idle >= limit - warn && idle < limit;
                if (idle >= limit) {
                    this.locked = true;
                    this.warning = false;
                    post(config.routes.lock).catch(() => {});
                }
            }, 1000);
        },
    };
}

// ------------------------------------------------------------------ Alpine registration

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('pm', { legacy: document.documentElement.classList.contains('legacy') });
    Alpine.store('pmHotkeys', {
        help: false,
        list() {
            return pageHotkeys().map((h) => ({ key: h.key, label: h.label }));
        },
    });
    Alpine.store('pmLock', lockStore());
    Alpine.store('pmScan', {
        open: false,
        message: '',
        torchAvailable: false,
        torchOn: false,
        track: null,
        show() {
            this.open = true;
            startCamera(this);
        },
        close() {
            this.open = false;
            stopCamera();
        },
        toggleTorch() {
            if (!this.track || !this.track.applyConstraints) return;
            this.torchOn = !this.torchOn;
            this.track.applyConstraints({ advanced: [{ torch: this.torchOn }] }).catch(() => {});
        },
        manual(value) {
            const scan = classify(value || '');
            if (scan.kind === 'imei' && !scan.valid) {
                this.message = 'IMEI с ошибкой: проверьте последнюю цифру';
                return;
            }
            if (!scan.code) return;
            this.close();
            emitScan(scan.code, 'manual');
        },
    });

    Alpine.data('pmLockScreen', () => ({
        password: '',
        error: '',
        busy: false,
        async unlock() {
            this.busy = true;
            this.error = '';
            try {
                const res = await post(config.routes.unlock, { password: this.password });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.password = '';
                    const store = Alpine.store('pmLock');
                    store.locked = false;
                    store.lastActivity = Date.now();
                } else if (data.logout) {
                    window.location.href = data.redirect || '/login';
                } else {
                    this.error = data.message || 'Пароль не подходит';
                }
            } catch (e) {
                this.error = 'Нет связи с сервером. Попробуйте ещё раз';
            }
            this.busy = false;
        },
    }));

    // «Электроника / Аксессуары» — a catalog filter remembered on the device (TZ 04 §0.2 п. 1). Pages read
    // localStorage «pm.catalog» or listen to the window event «pm-catalog» {value}.
    Alpine.data('pmCatalogSwitch', () => ({
        value: 'phones',
        init() {
            try { this.value = localStorage.getItem('pm.catalog') || 'phones'; } catch (e) { /* ignore */ }
        },
        set(value) {
            this.value = value;
            try { localStorage.setItem('pm.catalog', value); } catch (e) { /* ignore */ }
            window.dispatchEvent(new CustomEvent('pm-catalog', { detail: { value } }));
        },
    }));

    // Chrome ≤ 109 (Windows 7): offer «старый ноутбук» once (TZ 04 Э29)
    Alpine.data('pmLegacyOffer', () => ({
        show: false,
        init() {
            let asked = false;
            try { asked = localStorage.getItem('pm.legacy.asked') === '1'; } catch (e) { asked = true; }
            this.show = Boolean(config.oldChrome) && config.legacy === null && !asked
                && !document.documentElement.classList.contains('legacy');
        },
        remember() {
            try { localStorage.setItem('pm.legacy.asked', '1'); } catch (e) { /* ignore */ }
            this.show = false;
        },
        enable() {
            this.remember();
            window.dispatchEvent(new CustomEvent('pm-open-device'));
        },
        later() {
            this.remember();
        },
    }));
});

window.PM = {
    isImei,
    autoAdd,
    hotkeys,
    hints,
    scanner: {
        open() {
            if (window.Alpine) window.Alpine.store('pmScan').show();
        },
    },
};
