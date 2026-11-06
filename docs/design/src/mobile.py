#!/usr/bin/env python3
"""Prime Mobile — мобильные экраны владельца (iPhone 17 Pro Max, 440×956 pt). Tailwind 3.4, та же тема."""
import pathlib
import erp as gen4
from erp import ic, logo_mark, photo, badge, delta

OUT = pathlib.Path(__file__).parent

def head(title, mode=""):
    return gen4.HEAD.replace("{title}", title).replace("{mode}", mode)

def tabbar(active):
    items = [("chart", "Статистика"), ("box", "Склад"), ("scan", "Сканер"), ("receipt", "Долги"), ("dots", "Ещё")]
    out = ""
    for icon, label in items:
        if icon == "scan":
            out += f'''<div class="flex-1 grid place-items-center"><span class="w-14 h-14 -mt-6 rounded-2xl bg-primary text-primary-foreground grid place-items-center shadow-lg">{ic("scan","w-6 h-6","1.8")}</span></div>'''
            continue
        on = label == active
        glyph = ic(icon, "w-6 h-6", "1.7") if icon != "dots" else '<svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>'
        out += f'<div class="flex-1 flex flex-col items-center gap-1 {"text-foreground" if on else "text-muted-foreground"}">{glyph}<span class="text-[11px] font-medium">{label}</span></div>'
    return f'<nav class="absolute bottom-0 inset-x-0 h-[92px] pb-[30px] pt-2 bg-card border-t border-border flex items-start px-2">{out}</nav>'

def statusbar(dark_text=True):
    c = "text-foreground" if dark_text else "text-white"
    return f'''<div class="h-[59px] px-8 flex items-center justify-between text-[17px] font-semibold {c}">
      <span>14:56</span><span class="w-[126px] h-[37px] rounded-full bg-black absolute left-1/2 -translate-x-1/2 top-[11px]"></span>
      <span class="flex items-center gap-1.5"><svg class="w-[18px] h-3" viewBox="0 0 18 12" fill="currentColor"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="5.5" width="3" height="6.5" rx="1"/><rect x="10" y="3" width="3" height="9" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></svg>
      <svg class="w-[27px] h-[13px]" viewBox="0 0 27 13"><rect x=".5" y=".5" width="23" height="12" rx="3.5" fill="none" stroke="currentColor" opacity=".4"/><rect x="2" y="2" width="17" height="9" rx="2" fill="currentColor"/><rect x="25" y="4" width="1.5" height="5" rx=".75" fill="currentColor" opacity=".4"/></svg></span></div>'''

def frame(inner, bg="bg-background"):
    return f'<div class="relative w-[440px] h-[956px] overflow-hidden {bg}">{inner}</div>'

# ------------------------------------------------------------------ Главная владельца
def owner_home(mode=""):
    seg = "".join(f'<span class="flex-1 h-9 grid place-items-center rounded-[9px] text-[14px] font-medium {"bg-card text-foreground shadow-sm" if on else "text-muted-foreground"}">{t}</span>'
                  for t, on in [("Оба", True), ("Керуен", False), ("Арбат", False)])
    todo = [("bad", "2 телефона без госбазы", "продать нельзя до отметки"),
            ("warn", "2 заказа ждут выдачи", "клиенты внесли предоплату"),
            ("warn", "Просрочены долги 3 клиентов", "1 284 000 ₸"),
            ("ok", "Сверка с sfon: всё сходится", "сегодня в 03:00")]
    dot = dict(bad="bg-bad", warn="bg-warn", ok="bg-ok")
    todo_html = "".join(f'''<div class="flex items-center gap-3 px-4 h-[60px] {"border-t border-border" if i else ""}">
        <span class="w-2.5 h-2.5 rounded-full {dot[k]}"></span>
        <div class="flex-1 min-w-0"><div class="text-[15px] font-medium truncate">{a}</div><div class="text-[13px] text-muted-foreground truncate">{b}</div></div>
        <svg class="w-4 h-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></div>'''
        for i, (k, a, b) in enumerate(todo))
    money = [("Наличные", "1 236 500 ₸"), ("Безнал и Kaspi", "1 608 500 ₸"), ("Доллары", "$2 400")]
    money_html = "".join(f'<div class="flex justify-between py-2.5 {"border-t border-border" if i else ""} text-[15px]"><span class="text-muted-foreground">{a}</span><span class="font-semibold num">{b}</span></div>'
                         for i, (a, b) in enumerate(money))
    inner = f'''{statusbar()}
<div class="px-5 pb-[110px] h-[897px] overflow-hidden">
  <div class="flex items-center justify-between pt-2">
    <span class="flex items-center gap-2.5">{logo_mark("m", "w-[20px] h-[29px]")}<span class="text-[17px] tracking-[-.01em]"><span class="font-semibold">Prime</span> <span class="text-muted-foreground">Mobile</span></span></span>
    <span class="w-10 h-10 rounded-full bg-muted grid place-items-center text-[14px] font-semibold">КА</span>
  </div>
  <div class="mt-5 text-[15px] text-muted-foreground">Пятница, 6 ноября · ТРЦ «Керуен»</div>

  <section class="mt-4 rounded-2xl bg-card border border-border p-5">
    <div class="flex items-center justify-between text-[15px] text-muted-foreground">Выручка сегодня {delta("+12%", True)}</div>
    <div class="mt-2 text-[38px] leading-none font-semibold tracking-tight num">2 845 000 ₸</div>
    <div class="mt-2 text-[14px] text-muted-foreground">вчера 2 540 000 ₸</div>
    <div class="mt-4 grid grid-cols-2 gap-3">
      <div class="rounded-xl bg-muted px-4 py-3"><div class="text-[13px] text-muted-foreground">Прибыль</div><div class="text-[19px] font-semibold num mt-0.5">412 600 ₸</div></div>
      <div class="rounded-xl bg-muted px-4 py-3"><div class="text-[13px] text-muted-foreground">Продано</div><div class="text-[19px] font-semibold num mt-0.5">23 шт.</div></div>
    </div>
  </section>

  <section class="mt-4 rounded-2xl bg-card border border-border">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between"><h2 class="text-[17px] font-semibold">Ждёт вашего решения</h2>{badge("1", "neutral")}</div>
    <div class="px-4 pb-4">
      <div class="flex gap-3">{photo("apple-iphone-15","w-12 h-12 shrink-0")}
        <div class="flex-1 min-w-0"><div class="text-[15px] font-medium">Скидка 15 000 ₸ на iPhone 15</div>
          <div class="text-[13px] text-muted-foreground mt-0.5">Айгерим, Керуен · лимит продавца 5 000 ₸</div></div></div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <span class="h-11 rounded-xl border border-border grid place-items-center text-[15px] font-medium">Отклонить</span>
        <span class="h-11 rounded-xl bg-primary text-primary-foreground grid place-items-center text-[15px] font-semibold">Разрешить</span>
      </div>
    </div>
  </section>

  <section class="mt-4 rounded-2xl bg-card border border-border overflow-hidden">
    <div class="px-4 pt-4 pb-1"><h2 class="text-[17px] font-semibold">Нужно сделать</h2></div>
    {todo_html}
  </section>

  <section class="mt-4 rounded-2xl bg-card border border-border px-4 py-3">
    <div class="flex items-center justify-between pb-1"><h2 class="text-[17px] font-semibold">Деньги в кассах</h2><span class="text-[13px] text-muted-foreground">$1 = 512,40 ₸</span></div>
    {money_html}
  </section>
</div>
{tabbar("Статистика")}
<div class="absolute bottom-2 left-1/2 -translate-x-1/2 w-[140px] h-[5px] rounded-full bg-foreground"></div>'''
    return head("Prime Mobile — iPhone, главная", mode) + '<div class="p-0">' + frame(inner) + '</div></body></html>'

# ------------------------------------------------------------------ Сканер IMEI + приход
def scanner(mode=""):
    bars = ""; x = 0
    widths = [2,1,3,1,1,2,1,3,2,1,1,2,3,1,2,1,1,3,1,2,2,1,3,1,1,2,1,2,3,1,1,2,1,3,2,1,2,1,1,3,1,2]
    for i, w in enumerate(widths):
        if i % 2 == 0:
            bars += f'<rect x="{x}" y="0" width="{w*2}" height="58" fill="#111"/>'
        x += w * 2
    barcode = f'<svg viewBox="0 0 {x} 58" class="w-[230px] h-[58px]">{bars}</svg>'
    field = lambda l, v, extra="": f'<div class="py-3 {"border-t border-border" if extra != "first" else ""} flex items-center justify-between"><span class="text-[15px] text-muted-foreground">{l}</span><span class="text-[15px] font-medium">{v}</span></div>'
    inner = f'''
<div class="absolute inset-0 bg-[#1a1a1d]">
  <div class="absolute inset-x-0 top-0 h-[520px]" style="background:radial-gradient(120% 80% at 50% 40%, #3a3a40 0%, #141416 70%)"></div>
  <!-- коробка телефона с наклейкой IMEI -->
  <div class="absolute left-1/2 top-[160px] -translate-x-1/2 -rotate-3 w-[300px] h-[196px] rounded-[14px] bg-[#f3f3f1] shadow-2xl px-6 pt-5">
    <div class="text-[11px] text-[#333] font-mono">iPhone 16 Pro · 256GB</div>
    <div class="mt-2 text-[11px] text-[#333] font-mono">IMEI/MEID</div>
    <div class="mt-1">{barcode}</div>
    <div class="text-[12px] text-[#111] font-mono tracking-wider">356938124471206</div>
    <div class="mt-3 text-[11px] text-[#333] font-mono">Serial No. F4GXK2LQ7F</div>
  </div>
</div>
<div class="absolute inset-x-0 top-0">{statusbar(False)}</div>
<div class="absolute top-[70px] inset-x-0 px-5 flex items-center justify-between text-white">
  <span class="w-10 h-10 rounded-full bg-white/15 grid place-items-center">{ic("x","w-5 h-5","2")}</span>
  <span class="text-[17px] font-semibold">Приход · сканер</span>
  <span class="w-10 h-10 rounded-full bg-white/15 grid place-items-center"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
</div>
<!-- рамка сканирования -->
<div class="absolute left-1/2 -translate-x-1/2 top-[222px] w-[280px] h-[96px]">
  <span class="absolute left-0 top-0 w-7 h-7 border-l-[3px] border-t-[3px] border-white rounded-tl-xl"></span>
  <span class="absolute right-0 top-0 w-7 h-7 border-r-[3px] border-t-[3px] border-white rounded-tr-xl"></span>
  <span class="absolute left-0 bottom-0 w-7 h-7 border-l-[3px] border-b-[3px] border-white rounded-bl-xl"></span>
  <span class="absolute right-0 bottom-0 w-7 h-7 border-r-[3px] border-b-[3px] border-white rounded-br-xl"></span>
  <span class="absolute left-3 right-3 top-1/2 h-[2px] bg-[#4ADE80] shadow-[0_0_12px_#4ADE80]"></span>
</div>
<div class="absolute left-1/2 -translate-x-1/2 top-[372px] px-4 h-9 rounded-full bg-[#157F3D] text-white text-[14px] font-medium flex items-center gap-2 whitespace-nowrap">
  {ic("check","w-4 h-4","2.6")}IMEI считан · контрольная цифра верна</div>

<!-- нижняя карточка -->
<div class="absolute inset-x-0 bottom-0 h-[530px] rounded-t-[28px] bg-card px-5 pt-3">
  <div class="mx-auto w-10 h-1.5 rounded-full bg-muted"></div>
  <div class="mt-4 flex items-center gap-3">
    {photo("apple-iphone-16-pro","w-14 h-14 shrink-0")}
    <div class="flex-1 min-w-0"><div class="text-[19px] font-semibold">iPhone 16 Pro</div>
      <div class="font-mono text-[14px] text-muted-foreground mt-0.5">35 693812 4471 206</div></div>
    {badge("новый в базе", "ok")}
  </div>
  <div class="mt-3 rounded-2xl border border-border px-4">
    {field("Память", "256 ГБ", "first")}{field("Цвет", "Desert Titanium")}{field("Состояние", "Новый")}
    {field("Поставщик", "Dubai Mobile · в долг")}
    <div class="py-3 border-t border-border flex items-center justify-between"><span class="text-[15px] text-muted-foreground">Себестоимость</span>
      <span class="text-right"><span class="block text-[15px] font-semibold num">$1 140</span><span class="block text-[12.5px] text-muted-foreground num">= 584 140 ₸ по 512,40</span></span></div>
  </div>
  <div class="mt-3 flex items-center gap-2 text-[14px] text-muted-foreground">
    <span class="w-5 h-5 rounded-md border border-input"></span>Проверен в госбазе IMEI</div>
  <div class="mt-4 grid grid-cols-[1fr_2fr] gap-2">
    <span class="h-[52px] rounded-2xl border border-border grid place-items-center text-[16px] font-medium">Ещё скан</span>
    <span class="h-[52px] rounded-2xl bg-primary text-primary-foreground grid place-items-center text-[16px] font-semibold">Оприходовать</span>
  </div>
  <div class="absolute bottom-2 left-1/2 -translate-x-1/2 w-[140px] h-[5px] rounded-full bg-foreground"></div>
</div>'''
    return head("Prime Mobile — iPhone, сканер", mode) + frame(inner, "bg-black") + '</body></html>'

if __name__ == "__main__":
    for mode, suf in [("", ""), ("dark", "-dark"), ("accent", "-accent")]:
        (OUT / f"m-1-glavnaya{suf}.html").write_text(owner_home(mode), encoding="utf-8")
        (OUT / f"m-2-skaner{suf}.html").write_text(scanner(mode), encoding="utf-8")
    print("ok")


# ================================================================== Касса продавца (iPhone 12–16, 390×844)
def seller_tabbar():
    items = [("cart", "Продажа", True), ("box", "Склад", False), ("scan", "", False), ("users", "Клиенты", False), ("dots", "Ещё", False)]
    out = ""
    for icon, label, on in items:
        if icon == "scan":
            out += f'<div class="flex-1 grid place-items-center"><span class="w-14 h-14 -mt-6 rounded-2xl bg-primary text-primary-foreground grid place-items-center shadow-lg">{ic("scan","w-6 h-6","1.8")}</span></div>'
            continue
        glyph = ic(icon, "w-6 h-6", "1.7") if icon != "dots" else '<svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>'
        out += f'<div class="flex-1 flex flex-col items-center gap-1 {"text-foreground" if on else "text-muted-foreground"}">{glyph}<span class="text-[11px] font-medium">{label}</span></div>'
    return f'<nav class="absolute bottom-0 inset-x-0 h-[84px] pb-[26px] pt-2 bg-card border-t border-border flex items-start px-2">{out}</nav>'

def seller_status():
    return '''<div class="h-[47px] px-7 flex items-center justify-between text-[16px] font-semibold">
      <span>11:42</span><span class="w-[120px] h-[34px] rounded-full bg-black absolute left-1/2 -translate-x-1/2 top-[8px]"></span>
      <span class="flex items-center gap-1.5"><svg class="w-[18px] h-3" viewBox="0 0 18 12" fill="currentColor"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="5.5" width="3" height="6.5" rx="1"/><rect x="10" y="3" width="3" height="9" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></svg>
      <svg class="w-[25px] h-[12px]" viewBox="0 0 27 13"><rect x=".5" y=".5" width="23" height="12" rx="3.5" fill="none" stroke="currentColor" opacity=".4"/><rect x="2" y="2" width="15" height="9" rx="2" fill="currentColor"/></svg></span></div>'''

def seller_header():
    return f'''<div class="px-4 h-[52px] flex items-center gap-3">
      <span class="text-[22px] font-semibold tracking-tight">Продажа</span>
      <span class="ml-auto flex p-0.5 rounded-lg bg-muted text-[12.5px] font-medium">
        <span class="h-7 px-2.5 rounded-md bg-card shadow-sm grid place-items-center">Электроника</span>
        <span class="h-7 px-2.5 grid place-items-center text-muted-foreground">Аксессуары</span></span>
    </div>'''

def scan_field():
    return f'''<div class="mx-4 mt-1 flex items-center gap-2 h-[52px] pl-4 pr-1.5 rounded-2xl border-2 border-primary bg-card">
      <span class="flex-1 text-[15px] text-muted-foreground truncate">IMEI, артикул или модель</span>
      <span class="w-10 h-10 rounded-xl bg-primary text-primary-foreground grid place-items-center">{ic("camera","w-5 h-5","1.8")}</span></div>
    <div class="mx-4 mt-2 flex items-center justify-between text-[13px] text-muted-foreground">
      <span class="flex items-center gap-2">Автодобавление после скана · 0,8 с</span>
      <span class="relative inline-block w-10 h-6 rounded-full bg-primary"><span class="absolute top-0.5 left-[18px] w-5 h-5 rounded-full bg-card shadow"></span></span></div>'''

def extras(selected_source=None):
    items = [("repeat", "Trade-In"), ("truck", "Доставка"), ("users", selected_source or "Источник"), ("receipt", "Комментарий")]
    return '<div class="mx-4 grid grid-cols-4 gap-2">' + "".join(
        f'<span class="h-[62px] rounded-xl border border-border bg-card flex flex-col items-center justify-center gap-1 text-[11.5px] {"text-foreground font-medium" if (selected_source and t == selected_source) else "text-muted-foreground"}">{ic(i,"w-[18px] h-[18px]")}{t}</span>'
        for i, t in items) + '</div>'

def seller_pos_empty(mode=""):
    inner = f'''{seller_status()}{seller_header()}{scan_field()}
<div class="mx-4 mt-4 rounded-2xl border border-dashed border-input py-8 px-6 text-center">
  <span class="w-12 h-12 mx-auto rounded-2xl bg-muted grid place-items-center text-muted-foreground">{ic("scan","w-6 h-6")}</span>
  <div class="mt-3 text-[17px] font-semibold">Чек пустой</div>
  <div class="mt-1 text-[14px] text-muted-foreground leading-snug">Нажмите на камеру и наведите на штрихкод IMEI — телефон сам попадёт в чек</div>
</div>
<div class="mx-4 mt-5 mb-2 text-[13px] font-medium text-muted-foreground">Недавно смотрели</div>
<div class="mx-4 grid grid-cols-3 gap-2">
  {"".join(f'<div class="rounded-xl border border-border bg-card p-2">{photo(sl,"w-full h-16")}<div class="mt-1.5 text-[12.5px] font-medium truncate">{m}</div><div class="text-[12px] font-semibold num">{pr} ₸</div></div>' for sl, m, pr in [("apple-iphone-15","iPhone 15","389 000"),("apple-iphone-13","iPhone 13","214 000"),("samsung-galaxy-s25-ultra","S25 Ultra","719 000")])}
</div>
<div class="mt-4">{extras()}</div>
{seller_tabbar()}
<div class="absolute bottom-2 left-1/2 -translate-x-1/2 w-[134px] h-[5px] rounded-full bg-foreground"></div>'''
    return head("Prime Mobile — касса на iPhone, пустой чек", mode) + f'<div class="relative w-[390px] h-[844px] overflow-hidden bg-background">{inner}</div></body></html>'

def seller_pos_cart(mode=""):
    line = lambda ph, t, sub, price, extra="": f'''<div class="flex gap-3 py-3 border-t border-border first:border-0">
        {ph}<div class="flex-1 min-w-0"><div class="text-[15px] font-medium truncate">{t}</div><div class="text-[12.5px] text-muted-foreground mt-0.5 truncate">{sub}</div>{extra}</div>
        <div class="text-[15px] font-semibold num whitespace-nowrap">{price}</div></div>'''
    gov = f'<div class="mt-1 text-[12px] text-ok font-medium flex items-center gap-1">{ic("check","w-3.5 h-3.5","2.6")}проверен в госбазе</div>'
    box = lambda i: f'<div class="w-12 h-12 shrink-0 rounded-xl bg-muted grid place-items-center text-muted-foreground">{ic(i,"w-5 h-5")}</div>'
    pay = "".join(f'<span class="h-10 grid place-items-center rounded-xl text-[13.5px] font-medium {"bg-primary text-primary-foreground" if p=="Сплит" else "border border-border bg-card"}">{p}</span>'
                  for p in ["Нал", "Карта", "Kaspi", "Долг", "Сплит"])
    inner = f'''{seller_status()}{seller_header()}{scan_field()}
<div class="mx-4 mt-3 flex items-center gap-3 px-3 h-14 rounded-2xl border border-border bg-card">
  <span class="w-9 h-9 rounded-full bg-muted grid place-items-center text-[12px] font-semibold">ЕС</span>
  <span class="flex-1 min-w-0"><span class="block text-[15px] font-medium">Ербол Сапаров</span><span class="block text-[12.5px] text-muted-foreground num">+7 701 482 19 07 · бонусы 4 200 ₸</span></span>
  {ic("chev","w-4 h-4 text-muted-foreground")}</div>
<div class="mx-4 mt-2 px-3 rounded-2xl border border-border bg-card">
  {line(photo("apple-iphone-16-pro","w-12 h-12 shrink-0"), "iPhone 16 Pro 256 ГБ", "35 693812 4471 206", "649 000 ₸", gov)}
  {line(box("box"), "Чехол MagSafe Clear", "1 шт.", "12 500 ₸")}
  {line(box("repeat"), "Trade-In: iPhone 12", "128 ГБ · акт № 77 · в зачёт", "−150 000 ₸")}
</div>
<div class="mx-4 mt-2 grid grid-cols-3 gap-2 text-[12.5px]">
  <span class="h-9 rounded-xl border border-border bg-card flex items-center justify-center gap-1.5 text-muted-foreground">{ic("truck","w-4 h-4")}Доставка</span>
  <span class="h-9 rounded-xl border border-border bg-card flex items-center justify-center gap-1.5 font-medium">{ic("users","w-4 h-4")}Instagram</span>
  <span class="h-9 rounded-xl border border-border bg-card flex items-center justify-center gap-1.5 text-muted-foreground">{ic("receipt","w-4 h-4")}Коммент.</span>
</div>
<div class="absolute inset-x-0 bottom-[84px] bg-card border-t border-border px-4 pt-3 pb-3">
  <div class="flex items-baseline justify-between"><span class="text-[14px] text-muted-foreground">Скидка −11 500 ₸</span>
    <span class="text-[22px] font-semibold num">500 000 ₸</span></div>
  <div class="mt-2.5 grid grid-cols-5 gap-1.5">{pay}</div>
  <div class="mt-2 text-[12.5px] text-muted-foreground flex justify-between"><span>Нал 200 000 · Kaspi 300 000</span><span>сдача 0 ₸</span></div>
  <span class="mt-2.5 h-[52px] rounded-2xl bg-primary text-primary-foreground grid place-items-center text-[17px] font-semibold">Оплатить 500 000 ₸</span>
</div>
{seller_tabbar()}
<div class="absolute bottom-2 left-1/2 -translate-x-1/2 w-[134px] h-[5px] rounded-full bg-foreground"></div>'''
    return head("Prime Mobile — касса на iPhone, чек", mode) + f'<div class="relative w-[390px] h-[844px] overflow-hidden bg-background">{inner}</div></body></html>'

if __name__ == "__main__":
    for mode, suf in [("", ""), ("accent", "-accent"), ("dark", "-dark")]:
        (OUT / f"ms-1-pusto{suf}.html").write_text(seller_pos_empty(mode), encoding="utf-8")
        (OUT / f"ms-2-chek{suf}.html").write_text(seller_pos_cart(mode), encoding="utf-8")
    print("ok seller")
