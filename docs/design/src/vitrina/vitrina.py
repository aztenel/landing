#!/usr/bin/env python3
"""Prime Mobile — витрина v2: «нормальный магазин», телефон 390 pt. Tailwind 3.4. Значки — Lucide."""
import pathlib

OUT = pathlib.Path(__file__).parent

HEAD = """<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.17"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['Onest', 'system-ui', 'sans-serif'] },
  colors: { ink: '#16181D', mute: '#69707C', line: '#E3E7EC', soft: '#F3F5F8', blue: '#2A4BD7', bluesoft: '#EAEFFF', wa: '#1FA855', ok: '#1E7A4A' }
} } };
</script>
<style>
  body { -webkit-font-smoothing: antialiased; }
  .num { font-variant-numeric: tabular-nums; }
  .nw { white-space: nowrap; }
  .scroll-x { overflow-x: auto; scrollbar-width: none; } .scroll-x::-webkit-scrollbar { display: none; }
</style></head>
<body class="bg-soft text-ink font-sans"><div class="w-[390px] mx-auto bg-soft relative">"""
TAIL = "</div></body></html>"

# Значки: только набор Lucide, одна толщина 2.
L = {
    "search": '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    "phone": '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    "menu": '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
    "heart": '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    "pin": '<path d="M20 10c0 4.99-5.54 10.19-7.4 11.8a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    "home": '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .71-1.53l7-6a2 2 0 0 1 2.58 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    "grid": '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    "left": '<path d="m15 18-6-6 6-6"/>', "right": '<path d="m9 18 6-6-6-6"/>',
    "share": '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98"/><path d="m15.41 6.51-6.82 3.98"/>',
}
def ic(name, cls="w-5 h-5"):
    return f'<svg class="{cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{L[name]}</svg>'

WA = ('<svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.8-1.4a.5.5 0 0 0 0-.5c0-.1-.6-1.4-.8-1.9s-.4-.5-.6-.5h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.4.8 3.2.6a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>')

def mark(cls="w-[20px] h-[29px]"):
    return f'''<svg viewBox="0 0 44 64" class="{cls}" aria-hidden="true"><defs><linearGradient id="pm" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#3A3F48"/><stop offset=".55" stop-color="#9AA1AB"/><stop offset="1" stop-color="#16181D"/></linearGradient></defs>
      <rect x="3" y="2.5" width="38" height="59" rx="9" fill="none" stroke="url(#pm)" stroke-width="3.6"/>
      <rect x="17" y="8" width="10" height="2.6" rx="1.3" fill="#9AA1AB"/>
      <path d="M12 46 32 18" stroke="url(#pm)" stroke-width="4" stroke-linecap="round"/>
      <path d="M21 49 32 33.5" stroke="url(#pm)" stroke-width="2.4" stroke-linecap="round" opacity=".55"/></svg>'''

def money(v):
    return f"{v:,}".replace(",", " ")

def header(top_bar=True):
    bar = ('<div class="h-[47px] bg-ink"></div><div class="bg-ink text-white text-[13px] text-center px-4 pb-2.5">Trade-in: сдайте старый телефон в зачёт нового</div>'
           if top_bar else '<div class="h-[47px] bg-white"></div>')
    return f'''{bar}
<header class="bg-white px-4 pt-3 pb-3 border-b border-line">
  <div class="flex items-center gap-2">
    <span class="flex items-center gap-2 mr-auto">{mark()}<span class="text-[19px] tracking-[-.02em] leading-none"><b class="font-bold">Prime</b> <span class="text-mute font-medium">Mobile</span></span></span>
    <span class="w-10 h-10 grid place-items-center text-ink">{ic("phone","w-[22px] h-[22px]")}</span>
    <span class="h-10 px-3.5 rounded-xl bg-blue text-white text-[14px] font-semibold grid place-items-center">Рассрочка</span>
    <span class="w-10 h-10 grid place-items-center">{ic("menu","w-7 h-7")}</span>
  </div>
  <label class="mt-3 flex h-12 rounded-xl border border-line overflow-hidden">
    <span class="flex-1 px-4 flex items-center text-[16px] text-mute">Поиск по модели</span>
    <span class="w-14 bg-ink text-white grid place-items-center">{ic("search")}</span>
  </label>
</header>'''

def bottom_nav(active="Главная"):
    items = [("home", "Главная"), ("grid", "Каталог"), ("heart", "Избранное")]
    out = "".join(f'<span class="flex-1 flex flex-col items-center gap-1 {"text-ink" if l == active else "text-mute"}">{ic(i,"w-6 h-6")}<span class="text-[11px] font-medium">{l}</span></span>' for i, l in items)
    out += f'<span class="flex-1 flex flex-col items-center gap-1 text-wa">{WA.replace("w-5 h-5","w-6 h-6")}<span class="text-[11px] font-medium">WhatsApp</span></span>'
    return f'<nav class="sticky bottom-0 bg-white border-t border-line h-[84px] pt-2.5 flex px-2">{out}</nav>'

PRODUCTS = [
    ("back-17-pro", "iPhone 17 Pro 256GB Deep Blue", 759000, None, "10498"),
    ("back-17-pro-max", "iPhone 17 Pro Max 256GB Cosmic Orange", 869000, None, "10497"),
    ("apple-iphone-15-pro-max-1", "iPhone 15 Pro Max 256GB Natural Titanium", 489000, (93, "A"), "10482"),
    ("back-air", "iPhone Air 256GB Sky Blue", 619000, None, "10491"),
    ("apple-iphone-16-pro-1", "iPhone 16 Pro 256GB White Titanium", 539000, (91, "A"), "10477"),
    ("back-s25-ultra", "Galaxy S25 Ultra 512GB Titanium Silverblue", 719000, None, "10470"),
]

def card(img, name, price, used, pid):
    per = money(round(price / 24))
    u = (f'<div class="mt-1.5 text-[13px] text-mute">Б/у, батарея <span class="text-ok font-semibold">{used[0]}%</span>, оценка {used[1]}</div>' if used
         else '<div class="mt-1.5 text-[13px] text-mute">Новый</div>')
    return f'''<div class="bg-white border border-line rounded-2xl overflow-hidden flex flex-col">
      <div class="h-[168px] bg-white grid place-items-center p-3 border-b border-line"><img src="img/{img}.png" alt="" class="max-h-[146px] max-w-full object-contain"></div>
      <div class="p-3 flex-1 flex flex-col">
        <div class="text-[20px] font-bold tracking-[-.01em] num nw">{money(price)} ₸</div>
        <div class="mt-1 flex items-center gap-1.5"><span class="px-1.5 rounded-md bg-bluesoft text-blue text-[14px] font-bold num">{per}</span><span class="text-[13px] text-mute">× 24 мес</span></div>
        <div class="mt-2.5 text-[14.5px] leading-snug font-medium">{name}</div>
        {u}
        <div class="mt-auto pt-3 flex gap-2">
          <span class="flex-1 h-10 rounded-xl bg-ink text-white text-[14px] font-semibold grid place-items-center">Выбрать</span>
          <span class="w-10 h-10 rounded-xl border border-line grid place-items-center">{ic("heart","w-[18px] h-[18px]")}</span>
        </div>
      </div></div>'''

# ------------------------------------------------------------------ Главная
def home():
    slides = [("tradein", "Trade-in вашего iPhone"), ("novinka", "iPhone 17 Pro Max уже в магазине"), ("bu", "Б/у с гарантией")]
    banner = ('<div class="scroll-x -mx-4" style="scroll-snap-type:x mandatory;scroll-padding-left:16px"><div class="flex gap-2.5 w-max pl-4 pr-4">'
              + "".join(f'<img src="banner-{k}-square.jpg" alt="{alt}" class="w-[330px] h-[330px] rounded-2xl block" style="scroll-snap-align:start">' for k, alt in slides)
              + '</div></div>')
    dots = '<div class="mt-3 flex justify-center gap-1.5">' + "".join(f'<span class="h-1.5 rounded-full {"w-5 bg-ink" if i == 0 else "w-1.5 bg-line"}"></span>' for i in range(3)) + '</div>'
    cats = [("Новые", "back-17-pro"), ("Б/у с гарантией", "apple-iphone-15-pro-max-1"), ("Trade-in", "apple-iphone-16-pro-1")]
    cat_html = "".join(f'''<div class="bg-white border border-line rounded-2xl p-3 pb-3.5 flex flex-col items-center">
        <div class="h-[86px] grid place-items-center"><img src="img/{im}.png" class="max-h-[80px] max-w-[96px] object-contain" alt=""></div>
        <div class="mt-2 text-[14px] font-semibold text-center leading-tight">{t}</div></div>''' for t, im in cats)
    grid = "".join(card(*p) for p in PRODUCTS)
    body = f'''{header()}
<section class="px-4 pt-4">{banner}{dots}</section>
<section class="px-4 mt-5 grid grid-cols-3 gap-2.5">{cat_html}</section>
<section class="px-4 mt-7">
  <div class="flex gap-6 border-b border-line">
    <span class="pb-2.5 text-[17px] font-bold border-b-[3px] border-ink -mb-px">Новые поступления</span>
    <span class="pb-2.5 text-[17px] font-medium text-mute">Б/у</span>
    <span class="pb-2.5 text-[17px] font-medium text-mute">Популярное</span>
  </div>
  <div class="mt-4 grid grid-cols-2 gap-2.5">{grid}</div>
  <span class="mt-4 h-12 rounded-xl border border-ink grid place-items-center text-[15px] font-semibold">Показать ещё 306</span>
</section>
<section class="px-4 mt-8 pb-8">
  <div class="bg-white border border-line rounded-2xl p-4">
    <div class="text-[18px] font-bold">Prime Mobile, ТРЦ «Керуен»</div>
    <div class="mt-1 text-[14.5px] text-mute leading-relaxed">Астана, Достык 9, 2 этаж<br>Каждый день 10:00–22:00</div>
    <div class="mt-3 grid grid-cols-2 gap-2">
      <span class="h-11 rounded-xl border border-line flex items-center justify-center gap-2 text-[14.5px] font-semibold">{ic("pin","w-[18px] h-[18px]")}Маршрут</span>
      <span class="h-11 rounded-xl border border-line flex items-center justify-center gap-2 text-[14.5px] font-semibold">{ic("phone","w-[18px] h-[18px]")}Позвонить</span>
    </div>
  </div>
</section>
{bottom_nav()}'''
    return HEAD.replace("{title}", "Prime Mobile — смартфоны в Астане") + body + TAIL

# ------------------------------------------------------------------ Товар
def product():
    rows = [("Состояние", "Б/у, оценка A — почти как новый"), ("Батарея", "93%, оригинальная, не менялась"),
            ("Дисплей", "Без царапин"), ("Корпус", "Тонкая царапина на рамке слева"),
            ("Память", "256 ГБ"), ("Цвет", "Natural Titanium"),
            ("Комплект", "Кабель USB-C, без коробки"), ("Гарантия", "3 месяца от магазина"),
            ("IMEI", "Проверен в госбазе 3 ноября")]
    table = "".join(f'<div class="flex gap-4 py-3 border-t border-line first:border-0 text-[15px]"><span class="w-[108px] shrink-0 text-mute">{k}</span><span class="font-medium">{v}</span></div>' for k, v in rows)
    thumbs = "".join(f'<span class="w-[70px] h-[70px] rounded-xl bg-white border {"border-ink border-2" if i == 0 else "border-line"} grid place-items-center overflow-hidden"><img src="img/{s}.png" class="max-h-[58px] object-contain" alt=""></span>'
                     for i, s in enumerate(["apple-iphone-15-pro-max-1", "apple-iphone-16-pro-3", "apple-iphone-15-pro-max-1", "apple-iphone-16-pro-3"]))
    similar = "".join(card(*p) for p in [PRODUCTS[4], PRODUCTS[0]])
    price = 489000
    body = f'''{header(top_bar=False)}
<section class="px-4 pt-4">
  <div class="flex items-start gap-3">
    <h1 class="flex-1 text-[24px] leading-[1.15] font-bold tracking-[-.02em]">iPhone 15 Pro Max 256GB Natural Titanium</h1>
    <span class="w-11 h-11 rounded-full bg-white border border-line grid place-items-center shrink-0">{ic("share","w-5 h-5")}</span>
  </div>
  <div class="mt-2 text-[14px] text-mute">ID 10482</div>
  <div class="mt-1 flex items-center gap-1.5 text-[14.5px]">{ic("pin","w-4 h-4 text-mute")}ТРЦ «Керуен», Достык 9</div>
</section>

<section class="px-4 mt-4">
  <div class="relative bg-white border border-line rounded-2xl h-[320px] grid place-items-center">
    <img src="img/apple-iphone-15-pro-max-1.png" class="max-h-[270px] object-contain" alt="">
    <span class="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-white border border-line grid place-items-center">{ic("left","w-5 h-5")}</span>
    <span class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-white border border-line grid place-items-center">{ic("right","w-5 h-5")}</span>
  </div>
  <div class="mt-2.5 flex gap-2">{thumbs}</div>
</section>

<section class="px-4 mt-4">
  <div class="bg-white border border-line rounded-2xl p-4">
    <div class="text-[34px] font-extrabold tracking-[-.02em] num nw">{money(price)} ₸</div>
    <div class="mt-1.5 flex items-center gap-2"><span class="px-2 rounded-md bg-bluesoft text-blue text-[17px] font-bold num">{money(round(price/24))}</span><span class="text-[15px] text-mute">× 24 мес</span></div>
    <div class="mt-1.5 text-[14px] underline underline-offset-2 text-mute">Рассрочка: все банки и сроки</div>
    <div class="mt-4 h-11 px-4 rounded-xl bg-ok/10 text-ok text-[15px] font-semibold flex items-center">В наличии, 1 шт.</div>
    <span class="mt-3 h-[52px] rounded-xl bg-wa text-white flex items-center justify-center gap-2 text-[16px] font-semibold">{WA}Написать в WhatsApp</span>
    <span class="mt-2.5 h-[52px] rounded-xl bg-blue text-white grid place-items-center text-[16px] font-semibold">Купить в рассрочку</span>
    <span class="mt-2.5 h-[52px] rounded-xl border border-ink grid place-items-center text-[16px] font-semibold">Забронировать</span>
  </div>
</section>

<section class="px-4 mt-4">
  <div class="bg-white border border-line rounded-2xl px-4 pt-4 pb-1">
    <div class="text-[19px] font-bold pb-2">Характеристики</div>
    {table}
  </div>
</section>

<section class="px-4 mt-7 pb-8">
  <div class="text-[19px] font-bold">Похожие</div>
  <div class="mt-3 grid grid-cols-2 gap-2.5">{similar}</div>
</section>
{bottom_nav("Каталог")}'''
    return HEAD.replace("{title}", "iPhone 15 Pro Max б/у — Prime Mobile") + body + TAIL

if __name__ == "__main__":
    (OUT / "v2-1-glavnaya.html").write_text(home(), encoding="utf-8")
    (OUT / "v2-2-tovar.html").write_text(product(), encoding="utf-8")
    print("ok")
