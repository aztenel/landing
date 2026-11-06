#!/usr/bin/env python3
"""Prime Mobile OS — макеты ERP для компьютера (Tailwind 3.4, Chrome 109+)."""
import pathlib

OUT = pathlib.Path(__file__).parent

ICONS = {
 "home": '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
 "cart": '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
 "box": '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
 "inbox": '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
 "swap": '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
 "users": '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
 "receipt": '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
 "wallet": '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
 "chart": '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
 "repeat": '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
 "settings": '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
 "search": '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
 "scan": '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/>',
 "chev": '<path d="m6 9 6 6 6-6"/>',
 "bell": '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
 "plus": '<path d="M5 12h14"/><path d="M12 5v14"/>',
 "x": '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
 "printer": '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
 "shield": '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
 "download": '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
 "check": '<path d="M20 6 9 17l-5-5"/>',
 "arrow": '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
 "truck": '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
 "calendar": '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
 "clock": '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
 "logout": '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
 "coins": '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
 "phone": '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
 "headphones": '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
 "camera": '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
 "lock": '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
}

def ic(name, cls="w-[18px] h-[18px]", sw="1.6"):
    return (f'<svg class="{cls} shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{sw}" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{ICONS[name]}</svg>')

def logo_mark(uid, cls="w-[22px] h-[32px]"):
    # Корпус телефона + динамик + блик стекла по диагонали. Без «семёрки».
    return f'''<svg viewBox="0 0 44 64" class="{cls}" aria-hidden="true">
  <defs><linearGradient id="lg{uid}" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" style="stop-color:rgb(var(--logo-1))"/><stop offset=".55" style="stop-color:rgb(var(--logo-2))"/><stop offset="1" style="stop-color:rgb(var(--logo-3))"/></linearGradient></defs>
  <rect x="3" y="2.5" width="38" height="59" rx="9" fill="none" stroke="url(#lg{uid})" stroke-width="3.2"/>
  <rect x="17" y="8" width="10" height="2.6" rx="1.3" style="fill:rgb(var(--logo-2))"/>
  <path d="M12 46 32 18" stroke="url(#lg{uid})" stroke-width="3.6" stroke-linecap="round"/>
  <path d="M21 49 32 33.5" stroke="url(#lg{uid})" stroke-width="2.2" stroke-linecap="round" opacity=".55"/>
</svg>'''

def wordmark(uid, size="text-[15px]"):
    return (f'<span class="flex items-center gap-2.5">{logo_mark(uid)}'
            f'<span class="leading-none {size} tracking-[-.01em]"><span class="font-semibold">Prime</span> '
            f'<span class="font-normal text-muted-foreground">Mobile</span></span></span>')

HEAD = """<!doctype html><html lang="ru" class="{mode}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.17"></script>
<script>
tailwind.config = { darkMode: 'class', theme: { extend: {
  fontFamily: { sans: ['Onest','Segoe UI','Arial','sans-serif'], mono: ['"JetBrains Mono"','Consolas','monospace'] },
  colors: { 'background':'rgb(var(--background) / <alpha-value>)','foreground':'rgb(var(--foreground) / <alpha-value>)','card':'rgb(var(--card) / <alpha-value>)','primary':'rgb(var(--primary) / <alpha-value>)','primary-foreground':'rgb(var(--primary-foreground) / <alpha-value>)','muted':'rgb(var(--muted) / <alpha-value>)','muted-foreground':'rgb(var(--muted-foreground) / <alpha-value>)','border':'rgb(var(--border) / <alpha-value>)','input':'rgb(var(--input) / <alpha-value>)','ring':'rgb(var(--ring) / <alpha-value>)','store-a':'rgb(var(--store-a) / <alpha-value>)','store-b':'rgb(var(--store-b) / <alpha-value>)','ok':'rgb(var(--ok) / <alpha-value>)','warn':'rgb(var(--warn) / <alpha-value>)','bad':'rgb(var(--bad) / <alpha-value>)','photo':'rgb(var(--photo) / <alpha-value>)' } } } };
</script>
<style>
/* Prime Mobile: графит + серебро. Цвета — каналы RGB, чтобы работали прозрачности в Tailwind 3.4 (Chrome 109+). */
:root { --background:250 250 250; --foreground:17 17 19; --card:255 255 255; --primary:24 24 27; --primary-foreground:250 250 250; --muted:244 244 245; --muted-foreground:110 110 118; --border:230 230 233; --input:217 217 222; --ring:24 24 27; --store-a:24 24 27; --store-b:180 180 188; --ok:21 127 61; --warn:165 90 8; --bad:200 38 31; --photo:244 244 245; --logo-1:58 58 64; --logo-2:140 140 148; --logo-3:17 17 19; }
.dark { --background:11 11 13; --foreground:242 242 243; --card:20 20 23; --primary:242 242 243; --primary-foreground:17 17 19; --muted:29 29 33; --muted-foreground:154 154 163; --border:38 38 43; --input:51 51 58; --ring:242 242 243; --store-a:232 232 234; --store-b:92 92 100; --ok:74 222 128; --warn:245 181 68; --bad:248 113 113; --photo:237 237 239; --logo-1:250 251 252; --logo-2:174 178 184; --logo-3:233 234 236; }
/* Режим для старого ноутбука (Windows 7, Chrome 109, слабый экран) */
.accent { --primary:42 75 215; --ring:42 75 215; --store-a:42 75 215; }
.dark.accent { --primary:110 138 255; --primary-foreground:255 255 255; --store-a:110 138 255; }
.legacy { --muted-foreground:74 74 82; --border:200 200 208; --input:180 180 189; --store-b:154 154 163; }
.legacy .text-\\[11px\\], .legacy .text-\\[11\\.5px\\], .legacy .text-\\[12px\\] { font-size:13px !important; }
.legacy .text-\\[12\\.5px\\], .legacy .text-\\[13px\\] { font-size:13.5px !important; }
.legacy .text-\\[13\\.5px\\] { font-size:14.5px !important; }
.legacy * { box-shadow:none !important; transition:none !important; animation:none !important; }
.legacy .ring-2 { outline:2.5px solid rgb(var(--primary)); outline-offset:-1px; }
.legacy .text-muted-foreground { font-weight:500; }
.legacy .pay { font-size:13px !important; }
</style>
<style>
  body { -webkit-font-smoothing:antialiased; }
  span, a, button, th, td.nw { white-space:nowrap; }
  .num { font-variant-numeric:tabular-nums; }
  .photo img { mix-blend-mode:multiply; }
</style></head>
<body class="bg-background text-foreground font-sans antialiased">"""

OWNER_NAV = [
    ("Работа", [("chart", "Статистика", "dashboard"), ("cart", "Продажа", "pos"), ("box", "Склад", "stock"),
                ("inbox", "Приход", None), ("repeat", "Trade-in", None)]),
    ("Учёт", [("users", "Клиенты", None), ("receipt", "Долги", None), ("wallet", "Баланс", None),
              ("coins", "Расходы", None), ("shield", "Сотрудники и роли", "roles"), ("settings", "Настройки", None)]),
]
SELLER_NAV = [
    ("Смена", [("cart", "Продажа", "pos"), ("truck", "Заказы", None), ("box", "Склад", "stock"),
               ("repeat", "Trade-in", None)]),
    ("Клиенты", [("users", "Клиенты", None), ("receipt", "Долги клиентов", None)]),
]
BADGES = {"Перемещения": "3", "Заказы": "2"}

def sidebar(active, role):
    nav = OWNER_NAV if role == "owner" else SELLER_NAV
    groups = ""
    for title, items in nav:
        links = ""
        for icon, label, key in items:
            on = key == active
            cls = "bg-card text-foreground shadow-[0_0_0_1px_rgb(var(--border))]" if on else "text-muted-foreground hover:text-foreground hover:bg-muted"
            b = (f'<span class="ml-auto text-[11px] font-medium num min-w-[20px] h-5 px-1.5 grid place-items-center rounded-full bg-primary text-primary-foreground">{BADGES[label]}</span>'
                 if label in BADGES else "")
            links += f'<a class="flex items-center gap-2.5 h-9 px-2.5 rounded-lg text-[13.5px] font-medium transition-colors duration-150 cursor-pointer {cls}">{ic(icon,"w-[17px] h-[17px]")}<span>{label}</span>{b}</a>'
        groups += f'<div class="px-2.5 pt-5 pb-1.5 text-[11px] font-medium text-muted-foreground/80">{title}</div>{links}'
    if role == "owner":
        who = ("КА", "Кайрат А.", "Владелец")
    else:
        who = ("АЖ", "Айгерим Ж.", "Продавец")
    store = ("Prime Mobile", "ТРЦ «Керуен» · 1 магазин") if role == "owner" else ("ТРЦ «Керуен»", "Смена с 09:00")
    switch = ic("lock", "w-3.5 h-3.5 text-muted-foreground")
    return f'''
<aside class="w-[216px] min-[1440px]:w-[236px] shrink-0 bg-background border-r border-border flex flex-col px-3">
  <div class="h-16 flex items-center px-1.5">{wordmark("s")}</div>
  <button class="flex items-center gap-2.5 px-2.5 h-12 rounded-lg border border-border bg-card text-left cursor-pointer hover:bg-muted transition-colors">
    <span class="flex-1 min-w-0"><span class="block text-[13px] font-medium truncate">{store[0]}</span>
      <span class="block text-[11.5px] text-muted-foreground truncate">{store[1]}</span></span>{switch}
  </button>
  <div class="mt-3 grid grid-cols-2 p-1 rounded-lg bg-muted text-[12.5px] font-medium">
    <span class="h-8 rounded-md bg-card shadow-[0_0_0_1px_rgb(var(--border))] flex items-center justify-center">Электроника</span>
    <span class="h-8 rounded-md text-muted-foreground flex items-center justify-center">Аксессуары</span>
  </div>
  <nav class="flex flex-col gap-0.5">{groups}</nav>
  <div class="mt-auto py-3 border-t border-border">
    <div class="flex items-center gap-2.5 px-1.5">
      <span class="w-8 h-8 rounded-full bg-muted grid place-items-center text-[11.5px] font-semibold">{who[0]}</span>
      <span class="flex-1 min-w-0 leading-tight"><span class="block text-[13px] font-medium">{who[1]}</span>
        <span class="block text-[11.5px] text-muted-foreground truncate">{who[2]}</span></span>
      {ic("logout","w-4 h-4 text-muted-foreground")}
    </div>
  </div>
</aside>'''

def topbar(role):
    LEGACY_CHIP = '<span class="h-10 px-3 rounded-lg bg-muted text-[12px] font-medium grid place-items-center">Режим: старый ноутбук</span>' if 'legacy' in MODE else ''
    rate = ('<span class="font-semibold num">$1 = 512,40 ₸</span><span class="text-[11.5px] text-muted-foreground">Айгерим, 09:12</span>')
    return f'''
<header class="h-16 shrink-0 border-b border-border flex items-center gap-3 px-6 min-[1440px]:px-8">
  <label class="flex items-center gap-2.5 h-10 w-[380px] px-3.5 rounded-lg border border-input bg-card text-muted-foreground">
    {ic("search","w-4 h-4")}<span class="text-[13.5px] flex-1">IMEI, модель, клиент или № чека</span>
    <kbd class="text-[11px] font-mono px-1.5 rounded border border-border">/</kbd>
  </label>
  <div class="ml-auto flex items-center gap-2">
    {LEGACY_CHIP}<div class="flex items-center gap-2 h-10 px-3 rounded-lg border border-border bg-card text-[13px]"><span class="text-muted-foreground">Курс</span>{rate}</div>
    <button class="relative w-10 h-10 grid place-items-center rounded-lg border border-border bg-card cursor-pointer hover:bg-muted" aria-label="Уведомления">
      {ic("bell","w-[17px] h-[17px]")}<span class="absolute top-2 right-2.5 w-1.5 h-1.5 rounded-full bg-bad"></span></button>
  </div>
</header>'''

MODE = ""

def page(title, active, role, body, main_cls="px-6 min-[1440px]:px-8 py-6"):
    return (HEAD.replace("{title}", title).replace("{mode}", MODE) +
            f'<div class="flex min-h-screen">{sidebar(active, role)}'
            f'<div class="flex-1 min-w-0 flex flex-col">{topbar(role)}<main class="flex-1 min-h-0 {main_cls}">{body}</main></div></div>'
            '</body></html>')

def badge(text, kind="neutral", dot=False):
    m = {"ok": "bg-ok/10 text-ok", "warn": "bg-warn/10 text-warn", "bad": "bg-bad/10 text-bad",
         "neutral": "border border-border text-foreground", "muted": "bg-muted text-muted-foreground"}[kind]
    d = '<span class="w-1.5 h-1.5 rounded-full bg-current"></span>' if dot else ""
    return f'<span class="inline-flex items-center gap-1.5 h-[22px] px-2 rounded-md text-[12px] font-medium {m}">{d}{text}</span>'

def btn(text, kind="primary", icon=None, extra=""):
    m = {"primary": "bg-primary text-primary-foreground hover:opacity-90",
         "outline": "bg-card border border-border hover:bg-muted"}[kind]
    i = ic(icon, "w-4 h-4") if icon else ""
    return f'<button class="inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13.5px] font-medium cursor-pointer transition {m} {extra}">{i}{text}</button>'

def delta(text, up):
    return f'<span class="inline-flex items-center h-5 px-1.5 rounded-md text-[12px] font-semibold num {"bg-ok/10 text-ok" if up else "bg-bad/10 text-bad"}">{text}</span>'

def photo(slug, cls="w-full h-24"):
    return f'<div class="photo {cls} rounded-lg bg-photo grid place-items-center overflow-hidden"><img src="img/{slug}.jpg" alt="" class="h-[86%] w-auto object-contain"></div>'

# ------------------------------------------------------------------ Главная (владелец)
def dashboard():
    kpis = [
        ("Выручка", "2 845 000 ₸", delta("+12%", True), "вчера 2 540 000 ₸"),
        ("Прибыль", "412 600 ₸", delta("+8%", True), "маржа 14,5% · вчера 381 900 ₸"),
        ("Продано", "23 шт.", delta("−2", False), "средний чек 123 700 ₸ · вчера 25 шт."),
        ("Деньги в кассах", "4 075 000 ₸", "", "нал 1,24 млн · безнал 1,61 млн · $2 400"),
    ]
    kpi_html = "".join(f'''
      <div class="bg-card rounded-xl border border-border p-4 min-[1440px]:p-5">
        <div class="flex items-center justify-between text-[13px] text-muted-foreground">{k}{d}</div>
        <div class="mt-2.5 text-[24px] min-[1440px]:text-[26px] leading-none font-semibold tracking-tight num">{v}</div>
        <div class="mt-2.5 text-[12.5px] text-muted-foreground truncate">{s}</div>
      </div>''' for k, v, d, s in kpis)

    a = [1.05,1.32,0.98,1.48,1.90,2.21,0.70,1.22,1.46,1.10,1.71,2.03,2.31,1.85]
    b = [0.57,0.78,0.50,0.83,1.02,1.19,0.35,0.66,0.79,0.61,0.93,1.09,1.25,1.00]
    a = [x + y for x, y in zip(a, b)]
    days = ["24","25","26","27","28","29","30","31","1","2","3","4","5","6"]
    W, H = 760, 200; mx = 4.0; bw = 30; gap = (W - 40 - 14 * bw) / 13
    g = ""
    for t in (1, 2, 3, 4):
        y = H - t / mx * H
        g += f'<line x1="0" x2="{W-40}" y1="{y:.1f}" y2="{y:.1f}" style="stroke:rgb(var(--border))"/><text x="{W-32}" y="{y+4:.1f}" font-size="11" style="fill:rgb(var(--muted-foreground))">{t} млн</text>'
    bars = ""
    for i, (va, vb) in enumerate(zip(a, b)):
        x = i * (bw + gap); ha = va / mx * H; hb = vb / mx * H; last = i == 13
        op = "1" if last else ".9"
        bars += f'<rect x="{x:.1f}" y="{H-ha:.1f}" width="{bw}" height="{ha:.1f}" style="fill:rgb(var(--store-a))" opacity="{op}"/>'
        bars += f'<text x="{x+bw/2:.1f}" y="{H+18}" text-anchor="middle" font-size="11" font-weight="{600 if last else 400}" style="fill:{"var(--foreground)" if last else "var(--muted-foreground)"}">{days[i]}</text>'
    chart = f'<svg viewBox="0 -6 {W} {H+30}" class="w-full h-auto">{g}{bars}</svg>'

    pays = [("Наличные", "1 236 500 ₸", 43), ("Kaspi", "1 012 000 ₸", 36), ("Карта", "596 500 ₸", 21)]
    store_html = "".join(f'''
      <div class="py-2.5 border-t border-border first:border-0 first:pt-0">
        <div class="flex items-center text-[13.5px]"><span>{n}</span><span class="ml-auto font-semibold num">{r}</span></div>
        <div class="mt-2 h-1.5 rounded-full bg-muted overflow-hidden"><div class="h-full bg-store-a" style="width:{pct}%"></div></div>
      </div>''' for n, r, pct in pays)
    sellers = [("АЖ", "Айгерим", "14 чеков", "1 712 000 ₸"), ("ДК", "Данияр", "9 чеков", "1 133 000 ₸")]
    store_html += '<div class="mt-3 pt-3 border-t border-border text-[12.5px] text-muted-foreground">Продавцы</div>' + "".join(f'''
      <div class="mt-2 flex items-center gap-2.5 text-[13.5px]"><span class="w-7 h-7 rounded-full bg-muted grid place-items-center text-[11px] font-semibold">{i}</span>
        <span>{n}</span><span class="text-muted-foreground text-[12.5px]">{k}</span><span class="ml-auto font-semibold num">{r}</span></div>''' for i, n, k, r in sellers)

    sales = [
        ("14:52", "apple-iphone-16-pro", "iPhone 16 Pro 256 ГБ", "Desert Titanium · новый", "Айгерим", ("Kaspi", "neutral"), "649 000"),
        ("14:31", None, "AirPods Pro 2 + чехол", "аксессуары · 2 шт.", "Данияр", ("Наличные", "neutral"), "117 500"),
        ("13:58", "apple-iphone-13", "iPhone 13 128 ГБ", "Midnight · б/у, A, 89%", "Айгерим", ("Сплит", "neutral"), "214 000"),
        ("13:10", "samsung-galaxy-s25-ultra", "Galaxy S25 Ultra 512 ГБ", "Titanium Gray · новый", "Данияр", ("В долг", "warn"), "719 000"),
        ("12:44", "xiaomi-redmi-note-14-pro-5g", "Redmi Note 14 Pro 256 ГБ", "Black · новый", "Айгерим", ("Безнал", "neutral"), "164 900"),
    ]
    def thumb(s):
        return photo(s, "w-9 h-9 shrink-0") if s else f'<div class="w-9 h-9 shrink-0 rounded-lg bg-muted grid place-items-center text-muted-foreground">{ic("box","w-4 h-4")}</div>'
    rows = "".join(f'''<tr class="border-t border-border">
        <td class="py-2.5 pl-5 text-muted-foreground num w-16">{t}</td>
        <td class="py-2.5"><div class="flex items-center gap-3">{thumb(sl)}<div class="min-w-0"><div class="font-medium truncate">{m}</div><div class="text-[12px] text-muted-foreground truncate">{d}</div></div></div></td>
        <td class="py-2.5 nw">{who}</td>
        <td class="py-2.5">{badge(p[0], p[1])}</td>
        <td class="py-2.5 pr-5 text-right font-semibold num nw">{s} ₸</td></tr>''' for t, sl, m, d, who, p, s in sales)

    attn = [
        ("2 заказа ждут выдачи", "клиенты внесли предоплату", "warn", "Открыть"),
        ("2 телефона без госбазы", "продать нельзя до отметки", "bad", "Проверить"),
        ("Просрочены долги 3 клиентов", "1 284 000 ₸ всего", "warn", "Список"),
        ("27 телефонов лежат 30+ дней", "9,8 млн ₸ по себестоимости", "muted", "Открыть"),
        ("Сверка с sfon: всё сходится", "сегодня в 03:00", "ok", "Отчёт"),
    ]
    dot = dict(warn="bg-warn", bad="bg-bad", ok="bg-ok", muted="bg-muted-foreground/50")
    attn_html = "".join(f'''<div class="flex items-center gap-3 py-3 border-t border-border first:border-0 first:pt-0">
        <span class="w-2 h-2 rounded-full shrink-0 {dot[k]}"></span>
        <div class="min-w-0 flex-1"><div class="text-[13.5px] font-medium truncate">{a}</div><div class="text-[12px] text-muted-foreground truncate">{b}</div></div>
        <button class="h-8 px-3 rounded-lg border border-border text-[12.5px] font-medium hover:bg-muted cursor-pointer">{act}</button></div>''' for a, b, k, act in attn)

    seg = "".join(f'<span class="h-8 px-3 grid place-items-center rounded-md text-[13px] font-medium {"bg-card text-foreground shadow-[0_0_0_1px_rgb(var(--border))]" if on else "text-muted-foreground"}">{t}</span>'
                  for t, on in [("Оба магазина", True), ("Керуен", False), ("Арбат", False)])
    legend = ('<div class="flex items-center gap-4 text-[12.5px] text-muted-foreground">'
              '<span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-store-a"></span>Керуен</span>'
              '<span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-store-b"></span>Арбат</span></div>')

    body = f'''
<div class="flex items-end justify-between gap-4">
  <div><div class="text-[13px] text-muted-foreground">Пятница, 6 ноября · ТРЦ «Керуен»</div>
    <h1 class="text-[24px] font-semibold tracking-tight mt-0.5">Статистика</h1></div>
  <div class="flex items-center gap-2">
    {btn("Сегодня","outline","calendar")}
  </div>
</div>
<div class="grid grid-cols-4 gap-3 min-[1440px]:gap-4 mt-5">{kpi_html}</div>
<div class="grid grid-cols-3 gap-3 min-[1440px]:gap-4 mt-3 min-[1440px]:mt-4">
  <section class="col-span-2 bg-card rounded-xl border border-border p-5">
    <div class="flex items-start justify-between"><div><h2 class="text-[15px] font-semibold">Выручка по дням</h2>
      <p class="text-[12.5px] text-muted-foreground mt-0.5">последние 14 дней</p></div><span class="text-[12.5px] text-muted-foreground">в среднем 2,1 млн ₸ в день</span></div>
    <div class="mt-4">{chart}</div>
  </section>
  <section class="bg-card rounded-xl border border-border p-5">
    <h2 class="text-[15px] font-semibold">Оплаты сегодня</h2>
    <p class="text-[12.5px] text-muted-foreground mt-0.5">как платили и кто продавал</p>
    <div class="mt-3">{store_html}</div>
  </section>
</div>
<div class="grid grid-cols-3 gap-3 min-[1440px]:gap-4 mt-3 min-[1440px]:mt-4">
  <section class="col-span-2 bg-card rounded-xl border border-border overflow-hidden">
    <div class="flex items-center justify-between px-5 pt-5 pb-3"><h2 class="text-[15px] font-semibold">Последние продажи</h2>
      <a class="text-[13px] font-medium text-muted-foreground hover:text-foreground cursor-pointer flex items-center gap-1">Все продажи {ic("arrow","w-3.5 h-3.5")}</a></div>
    <table class="w-full text-[13.5px] table-fixed"><colgroup><col class="w-16"><col><col class="w-24"><col class="w-28"><col class="w-32"></colgroup>
      <tbody>{rows}</tbody></table>
  </section>
  <section class="bg-card rounded-xl border border-border p-5">
    <h2 class="text-[15px] font-semibold">Нужно сделать</h2>
    <div class="mt-3.5">{attn_html}</div>
  </section>
</div>'''
    return page("Prime Mobile — Главная", "dashboard", "owner", body)

# ------------------------------------------------------------------ Касса (продавец)
UNITS = [
    ("apple-iphone-16-pro", "iPhone 16 Pro", "256 ГБ · Desert Titanium", "4471 206", None, "649 000", "cart"),
    ("apple-iphone-15", "iPhone 15", "128 ГБ · Black", "8834 520", None, "389 000", ""),
    ("apple-iphone-13", "iPhone 13", "128 ГБ · Midnight", "9032 118", "б/у · A · 89%", "214 000", ""),
    ("samsung-galaxy-s25-ultra", "Galaxy S25 Ultra", "512 ГБ · Titanium Gray", "2210 574", None, "719 000", ""),
    ("apple-iphone-14-pro", "iPhone 14 Pro", "256 ГБ · Deep Purple", "7716 093", "б/у · B · 84%", "329 000", "other"),
    ("xiaomi-redmi-note-14-pro-5g", "Redmi Note 14 Pro", "256 ГБ · Black", "6618 340", None, "164 900", "nogov"),
]

def pos():
    cards = ""
    for slug, m, spec, imei, used, price, st in UNITS:
        ring = "ring-2 ring-primary border-transparent" if st == "cart" else "hover:border-input"
        tag = {"cart": badge("в чеке", "neutral"), "other": badge("резерв", "muted"),
               "nogov": badge("нет госбазы", "bad")}.get(st, "")
        cond = badge(used, "muted") if used else badge("новый", "muted")
        cards += f'''<button class="text-left bg-card rounded-xl border border-border {ring} p-3 cursor-pointer transition">
          <div class="relative">{photo(slug, "w-full h-[92px] min-[1440px]:h-28")}<div class="absolute top-2 left-2">{cond}</div></div>
          <div class="mt-2.5 px-0.5">
            <div class="text-[14px] font-semibold leading-tight">{m}</div>
            <div class="text-[12.5px] text-muted-foreground mt-0.5 truncate">{spec}</div>
            <div class="mt-2 flex items-center justify-between gap-2"><span class="font-mono text-[11.5px] text-muted-foreground">…{imei}</span>{tag}</div>
            <div class="mt-1.5 text-[16px] font-semibold num">{price} ₸</div>
          </div></button>'''

    pay = "".join(f'<span class="pay h-9 grid place-items-center rounded-lg text-[13px] font-medium {"bg-primary text-primary-foreground" if p=="Сплит" else "border border-border bg-card"}">{p}</span>'
                  for p in ["Наличные", "Карта", "Kaspi", "В долг", "Сплит"])
    body = f'''
<div class="grid grid-cols-[1fr_400px] min-[1440px]:grid-cols-[1fr_440px] h-[calc(100vh-64px)]">
  <section class="px-6 min-[1440px]:px-8 py-5 overflow-hidden flex flex-col min-w-0">
    <div class="flex items-center justify-between">
      <h1 class="text-[24px] font-semibold tracking-tight">Продажа</h1>
      <div class="flex gap-2">{btn('Возврат <kbd class="ml-1 text-[11px] font-mono text-muted-foreground">F9</kbd>',"outline","repeat")}{btn('Trade-in <kbd class="ml-1 text-[11px] font-mono text-muted-foreground">F8</kbd>',"outline","inbox")}</div>
    </div>
    <label class="mt-4 flex items-center gap-3 h-14 px-4 rounded-xl border-2 border-primary bg-card">
      {ic("scan","w-5 h-5")}<span class="text-[15px] text-muted-foreground flex-1 truncate">Сканируйте IMEI или штрихкод</span>
      <span class="text-[12.5px] text-muted-foreground flex items-center gap-2">Автодобавление 0,8 с<span class="relative inline-block w-9 h-5 rounded-full bg-primary"><span class="absolute top-0.5 left-[18px] w-4 h-4 rounded-full bg-card"></span></span></span>
    </label>
    <div class="mt-4 flex items-center gap-2 text-[13px]">
      <span class="h-8 px-3 rounded-lg bg-primary text-primary-foreground grid place-items-center font-medium">Все</span>
      <span class="h-8 px-3 rounded-lg border border-border bg-card grid place-items-center">Apple</span>
      <span class="h-8 px-3 rounded-lg border border-border bg-card grid place-items-center">Samsung</span>
      <span class="h-8 px-3 rounded-lg border border-border bg-card grid place-items-center">Xiaomi</span>
      <span class="w-px h-5 bg-border mx-1"></span>
      <span class="h-8 px-3 rounded-lg border border-border bg-card flex items-center gap-1">Новые и б/у {ic("chev","w-3.5 h-3.5 text-muted-foreground")}</span>
    </div>
    <div class="mt-3 grid grid-cols-3 gap-3 overflow-hidden">{cards}</div>
  </section>

  <aside class="bg-card border-l border-border flex flex-col min-h-0">
    <div class="px-5 pt-5 pb-4 border-b border-border">
      <div class="flex items-center justify-between"><h2 class="text-[16px] font-semibold">Чек № 1048</h2>
        <span class="text-[12px] text-muted-foreground flex items-center gap-1">{ic("clock","w-3.5 h-3.5")}резерв 28 мин</span></div>
      <button class="mt-3 w-full flex items-center gap-3 p-2.5 rounded-lg border border-border text-left cursor-pointer hover:bg-muted">
        <span class="w-8 h-8 rounded-full bg-muted grid place-items-center text-[11.5px] font-semibold">ЕС</span>
        <span class="flex-1 min-w-0"><span class="block text-[13.5px] font-medium">Ербол Сапаров</span>
          <span class="block text-[12px] text-muted-foreground num">+7 701 482 19 07 · бонусы 4 200 ₸</span></span>
        {ic("chev","w-4 h-4 text-muted-foreground")}</button>
    </div>
    <div class="px-5 flex-1 min-h-0 overflow-hidden">
      <div class="py-3 border-b border-border flex gap-3">
        {photo("apple-iphone-16-pro","w-11 h-11 shrink-0")}
        <div class="flex-1 min-w-0"><div class="text-[13.5px] font-medium">iPhone 16 Pro 256 ГБ</div>
          <div class="mt-1 flex items-center gap-2"><span class="font-mono text-[11.5px] text-muted-foreground">35 693812 4471 206</span>
            <span class="text-[11.5px] text-ok flex items-center gap-0.5 font-medium">{ic("check","w-3 h-3","2.6")}госбаза</span></div></div>
        <div class="text-[14px] font-semibold num">649 000 ₸</div>
      </div>
      <div class="py-3 border-b border-border flex gap-3 items-center">
        <div class="w-11 h-11 shrink-0 rounded-lg bg-muted grid place-items-center text-muted-foreground">{ic("box","w-4 h-4")}</div>
        <div class="flex-1"><div class="text-[13.5px] font-medium">Чехол MagSafe Clear</div><div class="text-[12px] text-muted-foreground">1 шт.</div></div>
        <div class="text-[14px] font-semibold num">12 500 ₸</div>
      </div>
      <div class="py-3 border-b border-border flex gap-3 items-center">
        <div class="w-11 h-11 shrink-0 rounded-lg bg-muted grid place-items-center text-muted-foreground">{ic("repeat","w-4 h-4")}</div>
        <div class="flex-1"><div class="text-[13.5px] font-medium">Trade-in: iPhone 12 128 ГБ</div><div class="text-[12px] text-muted-foreground">акт № 77 · в зачёт</div></div>
        <div class="text-[14px] font-semibold num">−150 000 ₸</div>
      </div>
      <dl class="py-3 text-[13.5px] space-y-1.5">
        <div class="flex justify-between text-muted-foreground"><dt>Скидка</dt><dd class="num">−11 500 ₸</dd></div>
        <div class="flex justify-between text-[16px] font-semibold pt-1"><dt>К оплате</dt><dd class="num">500 000 ₸</dd></div>
      </dl>
      <div class="grid grid-cols-5 gap-1.5">{pay}</div>
      <div class="mt-2.5 grid grid-cols-2 gap-2">
        <div class="rounded-lg border border-border px-3 py-2"><div class="text-[11.5px] text-muted-foreground">Наличными внесено</div><div class="text-[15px] font-semibold num">200 000 ₸</div></div>
        <div class="rounded-lg border border-border px-3 py-2"><div class="text-[11.5px] text-muted-foreground">Kaspi</div><div class="text-[15px] font-semibold num">300 000 ₸</div></div>
      </div>
      <div class="mt-2.5 grid grid-cols-3 gap-1.5 text-[12.5px]">
        <span class="h-8 rounded-lg border border-border flex items-center justify-center gap-1.5 text-muted-foreground">{ic("truck","w-3.5 h-3.5")}Доставка</span>
        <span class="h-8 rounded-lg border border-border flex items-center justify-center gap-1.5">{ic("users","w-3.5 h-3.5")}Instagram</span>
        <span class="h-8 rounded-lg border border-border flex items-center justify-center gap-1.5 text-muted-foreground">{ic("receipt","w-3.5 h-3.5")}Комментарий</span>
      </div>
    </div>
    <div class="px-5 py-4 border-t border-border">
      <div class="flex items-center gap-4 text-[12.5px] text-muted-foreground mb-3">
        <span class="flex items-center gap-1.5"><span class="w-4 h-4 rounded bg-primary text-primary-foreground grid place-items-center">{ic("check","w-3 h-3","3")}</span>Чек</span>
        <span class="flex items-center gap-1.5"><span class="w-4 h-4 rounded bg-primary text-primary-foreground grid place-items-center">{ic("check","w-3 h-3","3")}</span>Гарантия</span>
        <span class="flex items-center gap-1.5"><span class="w-4 h-4 rounded border border-input"></span>на казахском</span>
        <span class="ml-auto">сдача 0 ₸</span>
      </div>
      <button class="w-full py-3.5 rounded-xl bg-primary text-primary-foreground text-[16px] font-semibold flex items-center justify-center gap-2 cursor-pointer hover:opacity-90">
        Оплатить 500 000 ₸</button>
    </div>
  </aside>
</div>'''
    return page("Prime Mobile — Касса", "pos", "seller", body, main_cls="")

# ------------------------------------------------------------------ Склад (владелец)
def stock():
    rows = [
        ("apple-iphone-16-pro", "iPhone 16 Pro", "256 ГБ · Desert Titanium", "35 693812 4471 206", None, "Керуен", ("в чеке", "neutral"), True, "584 140", "$1 140", "649 000", 6),
        ("apple-iphone-16-pro", "iPhone 16 Pro", "256 ГБ · Black Titanium", "35 693812 4472 881", None, "Керуен", ("в наличии", "ok"), True, "584 140", "$1 140", "649 000", 6),
        ("apple-iphone-15", "iPhone 15", "128 ГБ · Black", "35 412907 8834 520", None, "Арбат", ("в наличии", "ok"), True, "338 180", "$660", "389 000", 21),
        ("apple-iphone-13", "iPhone 13", "128 ГБ · Midnight", "35 288104 9032 118", "A · 89%", "Керуен", ("в наличии", "ok"), True, "165 000", "", "214 000", 12),
        ("samsung-galaxy-s25-ultra", "Galaxy S25 Ultra", "512 ГБ · Titanium Gray", "35 901244 2210 574", None, "Керуен", ("в наличии", "ok"), True, "645 620", "$1 260", "719 000", 9),
        ("apple-iphone-14-pro", "iPhone 14 Pro", "256 ГБ · Deep Purple", "35 774310 7716 093", "B · 84%", "Арбат", ("резерв", "warn"), True, "262 000", "", "329 000", 4),
        ("xiaomi-redmi-note-14-pro-5g", "Redmi Note 14 Pro", "256 ГБ · Black", "86 330415 6618 340", None, "Керуен", ("в наличии", "ok"), False, "140 910", "$275", "164 900", 2),
        ("apple-iphone-15-pro-max", "iPhone 15 Pro Max", "256 ГБ · Natural", "35 120988 1405 762", "A · 93%", "Керуен", ("в наличии", "ok"), True, "410 000", "", "489 000", 17),
        ("samsung-galaxy-a55", "Galaxy A55", "256 ГБ · Navy", "35 667201 3390 418", None, "Арбат", ("в наличии", "ok"), False, "163 970", "$320", "189 000", 1),
        ("apple-iphone-16", "iPhone 16", "128 ГБ · Ultramarine", "35 693811 0927 445", None, "Керуен", ("в наличии", "ok"), True, "404 800", "$790", "459 000", 33),
        ("apple-ipad-air-11-2024", "iPad Air 11 M2", "128 ГБ · Space Gray", "DMPXK2LQ7F", None, "Арбат", ("в наличии", "ok"), True, "286 940", "$560", "329 000", 45),
    ]
    tr = ""
    for i, (sl, m, spec, imei, used, store, status, gov, cost, usd, price, days) in enumerate(rows):
        sel = "bg-muted/60" if i == 0 else ""
        chk = (f'<span class="w-4 h-4 rounded bg-primary text-primary-foreground grid place-items-center">{ic("check","w-3 h-3","3")}</span>' if i == 0
               else '<span class="w-4 h-4 rounded border border-input block"></span>')
        cond = f'<span class="text-muted-foreground"> · б/у {used}</span>' if used else ""
        govc = f'<span class="text-ok inline-flex items-center gap-1">{ic("check","w-3.5 h-3.5","2.4")}есть</span>' if gov else '<span class="text-bad font-medium">нет</span>'
        dcls = "text-warn font-semibold" if days > 30 else "text-muted-foreground"
        tr += f'''<tr class="border-t border-border {sel}">
          <td class="pl-5 py-2.5 w-10">{chk}</td>
          <td class="py-2.5"><div class="flex items-center gap-3">{photo(sl,"w-9 h-9 shrink-0")}<div class="min-w-0">
            <div class="font-medium truncate">{m}</div><div class="text-[12px] text-muted-foreground truncate">{spec}{cond}</div></div></div></td>
          <td class="py-2.5 font-mono text-[12px] nw">{imei}</td>
          <td class="py-2.5 nw text-muted-foreground">{("Trade-in" if used else ("Dubai Mobile" if store == "Керуен" else "ТехноОпт"))}</td>
          <td class="py-2.5">{badge(status[0], status[1], True)}</td>
          <td class="py-2.5 text-[13px] nw">{govc}</td>
          <td class="py-2.5 text-right num nw">{cost} ₸<div class="text-[11.5px] text-muted-foreground">{usd or "&nbsp;"}</div></td>
          <td class="py-2.5 text-right font-semibold num nw">{price} ₸</td>
          <td class="py-2.5 pr-5 text-right num nw {dcls}">{days} дн.</td></tr>'''

    summary = [("Телефонов в наличии", "312"), ("По себестоимости", "41,6 млн ₸"), ("По ценам продажи", "48,2 млн ₸"),
               ("Без проверки госбазы", "2"), ("Дольше 30 дней", "27")]
    sum_html = "".join(f'<div class="px-4 py-3 {"border-l border-border" if i else ""}"><div class="text-[12px] text-muted-foreground truncate">{a}</div>'
                       f'<div class="text-[18px] font-semibold num mt-0.5 {"text-bad" if "госбазы" in a else ("text-warn" if "30" in a else "")}">{b}</div></div>'
                       for i, (a, b) in enumerate(summary))
    tabs = "".join(
        f'<span class="relative h-10 flex items-center gap-1.5 text-[13.5px] font-medium {"text-foreground" if on else "text-muted-foreground"}">{t}'
        + (f'<span class="text-[12px] text-muted-foreground num">{n}</span>' if n else "")
        + ('<span class="absolute left-0 right-0 -bottom-px h-0.5 bg-primary rounded"></span>' if on else "") + '</span>'
        for t, n, on in [("Телефоны", "312", True), ("Аксессуары", "1 140", False), ("Резерв", "2", False), ("Приёмки", None, False), ("Ревизии", None, False)])
    filt = "".join(f'<span class="h-9 px-3 rounded-lg border border-border bg-card flex items-center gap-1.5 text-[13px] cursor-pointer hover:bg-muted">{f} {ic("chev","w-3.5 h-3.5 text-muted-foreground")}</span>'
                   for f in ["Модель", "Состояние", "Статус", "Поставщик"])
    body = f'''
<div class="flex items-center justify-between">
  <h1 class="text-[24px] font-semibold tracking-tight">Склад</h1>
  <div class="flex gap-2">{btn("Ревизия сканером","outline","scan")}{btn("Excel","outline","download")}{btn("Приход","primary","plus")}</div>
</div>
<div class="mt-3 flex gap-6 border-b border-border">{tabs}</div>
<div class="mt-4 bg-card rounded-xl border border-border grid grid-cols-5">{sum_html}</div>
<div class="mt-3 flex items-center gap-2">
  <label class="h-9 w-[260px] px-3 rounded-lg border border-input bg-card flex items-center gap-2 text-[13px] text-muted-foreground">{ic("search","w-4 h-4")}IMEI или модель</label>
  {filt}
  <span class="ml-auto text-[12.5px] text-muted-foreground flex items-center gap-1.5">{ic("lock","w-3.5 h-3.5")}себестоимость видят владелец и бухгалтер</span>
</div>
<section class="mt-3 bg-card rounded-xl border border-border overflow-hidden">
  <div class="h-11 px-5 flex items-center gap-5 bg-primary text-primary-foreground text-[13px]">
    <span class="font-semibold">Выбран 1</span>
    <span class="flex items-center gap-1.5 opacity-85 cursor-pointer">{ic("printer","w-4 h-4")}Этикетка</span>
    <span class="flex items-center gap-1.5 opacity-85 cursor-pointer">{ic("swap","w-4 h-4")}Переместить</span>
    <span class="flex items-center gap-1.5 opacity-85 cursor-pointer">{ic("shield","w-4 h-4")}Отметить госбазу</span>
  </div>
  <table class="w-full text-[13.5px]">
    <thead><tr class="text-left text-[12.5px] text-muted-foreground">
      <th class="pl-5 h-10 font-medium"></th><th class="h-10 font-medium">Модель</th><th class="h-10 font-medium">IMEI</th><th class="h-10 font-medium">Поставщик</th>
      <th class="h-10 font-medium">Статус</th><th class="h-10 font-medium">Госбаза</th><th class="h-10 font-medium text-right">Себестоимость</th>
      <th class="h-10 font-medium text-right">Цена</th><th class="h-10 font-medium pr-5 text-right">На складе</th></tr></thead>
    <tbody>{tr}</tbody>
  </table>
</section>'''
    return page("Prime Mobile — Склад", "stock", "owner", body)

# ------------------------------------------------------------------ Сотрудники и роли (владелец)
def toggle(on):
    return (f'<span class="relative inline-block w-9 h-5 rounded-full {"bg-primary" if on else "bg-input"}">'
            f'<span class="absolute top-0.5 {"left-[18px]" if on else "left-0.5"} w-4 h-4 rounded-full bg-card shadow"></span></span>')

def roles():
    role_list = [("Владелец", "все права, нельзя изменить", "1", False, True),
                 ("Администратор", "приход, возвраты, курс, отчёты", "1", False, False),
                 ("Продавец", "касса и клиенты", "2", True, False),
                 ("Старший продавец", "своя роль Кайрата", "0", False, False)]
    rl = "".join(f'''<button class="w-full text-left px-4 py-3 rounded-lg {"bg-muted" if sel else "hover:bg-muted"} cursor-pointer flex items-center gap-3">
        <span class="flex-1 min-w-0"><span class="block text-[13.5px] font-medium">{n}{" " + ic("lock","w-3 h-3 inline -mt-0.5 text-muted-foreground") if lock else ""}</span>
          <span class="block text-[12px] text-muted-foreground truncate">{d}</span></span>
        <span class="text-[12.5px] text-muted-foreground num">{c} чел.</span></button>''' for n, d, c, sel, lock in role_list)
    groups = [
        ("Касса", [("Продавать и пробивать чеки", True, None), ("Продажа в долг", True, None),
                   ("Скидка без согласования", True, "до 5 000 ₸"), ("Возврат покупателю", False, "только заявка владельцу")]),
        ("Склад", [("Видеть остатки и цены", True, None), ("Видеть себестоимость и поставщиков", False, None),
                   ("Приход товара", True, "без себестоимости"), ("Провести ревизию", False, None)]),
        ("Деньги и клиенты", [("Расходы из кассы", False, None), ("Менять курс доллара", False, None),
                   ("Видеть ИИН полностью", False, "только 4 последние цифры"), ("Выгрузка в Excel", False, None)]),
    ]
    gh = ""
    for g, items in groups:
        rows = "".join(f'''<div class="flex items-center gap-3 py-3 border-t border-border first:border-0">
            <span class="flex-1 text-[13.5px]">{t}</span>{f'<span class="text-[12.5px] text-muted-foreground">{note}</span>' if note else ""}{toggle(on)}</div>'''
            for t, on, note in items)
        gh += f'<div class="mt-5 first:mt-0"><div class="text-[12.5px] font-medium text-muted-foreground mb-1">{g}</div><div class="rounded-lg border border-border px-4">{rows}</div></div>'
    people = [("АЖ", "Айгерим Жумабаева", "+7 707 ··· 41 18", "09:02"), ("ДК", "Данияр Касымов", "+7 776 ··· 90 35", "09:47")]
    ph = "".join(f'''<div class="flex items-center gap-3 py-2.5 border-t border-border first:border-0">
        <span class="w-8 h-8 rounded-full bg-muted grid place-items-center text-[11.5px] font-semibold">{i}</span>
        <span class="flex-1 min-w-0"><span class="block text-[13.5px] font-medium truncate">{n}</span><span class="block text-[12px] text-muted-foreground num truncate">{t} · вход {w}</span></span></div>''' for i, n, t, w in people)
    tabs = "".join(
        f'<span class="relative h-10 flex items-center gap-1.5 text-[13.5px] font-medium {"text-foreground" if on else "text-muted-foreground"}">{t}'
        + (f'<span class="text-[12px] text-muted-foreground num">{n}</span>' if n else "")
        + ('<span class="absolute left-0 right-0 -bottom-px h-0.5 bg-primary rounded"></span>' if on else "") + '</span>'
        for t, n, on in [("Роли", "4", True), ("Сотрудники", "4", False), ("Журнал входов", None, False)])
    body = f'''
<div class="flex items-end justify-between">
  <div><div class="text-[13px] text-muted-foreground">Prime Mobile · аккаунт Кайрата</div>
    <h1 class="text-[24px] font-semibold tracking-tight mt-0.5">Сотрудники и роли</h1></div>
  <div class="flex gap-2">{btn("Новая роль","outline","plus")}{btn("Пригласить сотрудника","primary","plus")}</div>
</div>
<div class="mt-3 flex gap-6 border-b border-border">{tabs}</div>
<div class="mt-4 rounded-xl border border-border bg-muted/60 px-4 py-3 text-[13px] text-muted-foreground flex items-center gap-2">
  {ic("lock","w-4 h-4 shrink-0")}Роли и сотрудники действуют только внутри вашей фирмы. Другие владельцы на этой системе их не видят, а вы не видите их.</div>
<div class="mt-4 grid grid-cols-[300px_1fr] min-[1440px]:grid-cols-[320px_1fr] gap-4 items-start">
  <section class="bg-card rounded-xl border border-border p-2">{rl}</section>
  <section class="bg-card rounded-xl border border-border p-5">
    <div class="flex items-start justify-between">
      <div><h2 class="text-[17px] font-semibold">Продавец</h2>
        <p class="text-[12.5px] text-muted-foreground mt-0.5">Работает только в ТРЦ «Керуен». Себестоимость и деньги фирмы не видит.</p></div>
      {btn("Сохранить","primary")}
    </div>
    <div class="mt-4 grid grid-cols-[1fr_280px] min-[1440px]:grid-cols-[1fr_320px] gap-5">
      <div>{gh}</div>
      <div><div class="text-[12.5px] font-medium text-muted-foreground mb-1">С этой ролью</div>
        <div class="rounded-lg border border-border px-4">{ph}</div>
        <div class="mt-3 text-[12.5px] text-muted-foreground leading-relaxed">Изменения прав записываются в журнал: кто, когда и что поменял.</div></div>
    </div>
  </section>
</div>'''
    return page("Prime Mobile — Сотрудники и роли", "roles", "owner", body)

if __name__ == "__main__":
    for mode, suf in [("", ""), ("dark", "-dark"), ("legacy", "-legacy"), ("accent", "-accent")]:
        MODE = mode
        for name, fn in [("1-glavnaya", dashboard), ("2-kassa", pos), ("3-sklad", stock), ("4-roli", roles)]:
            (OUT / f"v4-{name}{suf}.html").write_text(fn(), encoding="utf-8")
    print("ok")
