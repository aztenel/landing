#!/usr/bin/env python3
"""Баннеры Prime Mobile в стиле «постер»: крупный текст, синий круг, телефоны на подиуме.
Два формата: широкий 1672×941 (сайт на компьютере) и квадрат 1080×1080 (телефон, Instagram).
Телефоны — настоящие фото, текст — живой текст (меняется и переводится без перерисовки)."""
import math
import pathlib

OUT = pathlib.Path(__file__).parent
ASPECT = {"back-17-pro-max": 0.4862, "back-17-pro": 0.4908, "old-12-blue": 0.4778, "dark-15-pro-max": 0.9288}

CSS = """
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: {W}px; height: {H}px; overflow: hidden; }
body { font-family: Onest, sans-serif; -webkit-font-smoothing: antialiased; color: #0E0F12; position: relative;
  background: radial-gradient(60% 70% at 18% 35%, rgba(255,255,255,.9) 0%, rgba(255,255,255,0) 70%),
              linear-gradient(100deg, #F1F3F9 0%, #E7EBF4 48%, #D9DFEC 100%); }
.abs { position: absolute; }
.disc { border-radius: 50%; background: radial-gradient(circle at 32% 28%, #4A6BFF 0%, #2A4BD7 46%, #1A35B0 100%);
  box-shadow: inset -40px -50px 120px rgba(8,20,90,.35), 0 40px 120px -30px rgba(42,75,215,.45); }
.ring { border-radius: 50%; }
.pod-top { border-radius: 50%; background: radial-gradient(120% 160% at 42% 30%, #FFFFFF 0%, #F4F6FA 45%, #DCE2EB 100%);
  box-shadow: inset 0 -2px 0 #fff, inset 0 3px 14px rgba(20,28,60,.08); }
.pod-side { background: linear-gradient(90deg, #C9D0DC 0%, #EEF1F6 22%, #FFFFFF 40%, #E3E8EF 62%, #BCC4D1 100%); }
.floor { border-radius: 50%; background: radial-gradient(closest-side, rgba(20,28,60,.30), rgba(20,28,60,0)); filter: blur(4px); }
.contact { border-radius: 50%; background: radial-gradient(closest-side, rgba(14,18,34,.55), rgba(14,18,34,0)); }
.ph { transform-origin: 50% 100%; }
h1 { font-weight: 900; text-transform: uppercase; letter-spacing: -.015em; line-height: .88; }
.sub { font-weight: 400; color: #2B2F36; line-height: 1.25; }
.price { font-weight: 900; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.pill { display: inline-flex; align-items: center; border-radius: 12px; background: #E6ECFF; color: #2A4BD7; font-weight: 800; font-variant-numeric: tabular-nums; }
.card { background: #fff; border-radius: 26px; box-shadow: 0 30px 60px -20px rgba(14,18,40,.35); }
.row { display: flex; justify-content: space-between; gap: 20px; border-top: 1.5px solid #E6E9EE; }
.row:first-of-type { border-top: 0; }
.brand { display: flex; align-items: center; gap: 12px; letter-spacing: -.02em; }
"""

MARK = ('<svg viewBox="0 0 44 64" width="{w}" height="{h}"><rect x="3" y="2.5" width="38" height="59" rx="9" fill="none" stroke="#2A2F38" stroke-width="3.6"/>'
        '<rect x="17" y="8" width="10" height="2.6" rx="1.3" fill="#9AA1AB"/><path d="M12 46 32 18" stroke="#2A2F38" stroke-width="4" stroke-linecap="round"/></svg>')


def box(cls, x, y, w, h, style=""):
    return f'<div class="abs {cls}" style="left:{x:.0f}px;top:{y:.0f}px;width:{w:.0f}px;height:{h:.0f}px;{style}"></div>'


def stage(cx, cy, pw, disc_c, disc_r):
    """Синий круг с кольцами и подиум. cx, cy — центр верхней грани подиума, pw — ширина подиума."""
    dx, dy = disc_c
    ph = pw * 0.13
    out = [
        box("ring", dx - disc_r * 1.15, dy - disc_r * 1.15, disc_r * 2.3, disc_r * 2.3, "border:2px solid rgba(42,75,215,.22)"),
        box("disc", dx - disc_r, dy - disc_r, disc_r * 2, disc_r * 2),
        box("ring", dx - disc_r * .82, dy - disc_r * .82, disc_r * 1.64, disc_r * 1.64, "border:2px solid rgba(255,255,255,.22)"),
        box("floor", cx - pw * .58, cy + ph * .75, pw * 1.16, ph * 1.05),
        box("pod-side", cx - pw / 2, cy, pw, ph, f"border-radius:0 0 {pw/2:.0f}px {pw/2:.0f}px / 0 0 {ph*.47:.0f}px {ph*.47:.0f}px"),
        box("pod-top", cx - pw / 2, cy - ph / 2, pw, ph),
    ]
    return "".join(out)


def phone(src, h, cx, base, deg, shadow_dir=1):
    """Ставит телефон нижним углом на поверхность base: учитывает, насколько угол опускается при наклоне."""
    w = h * ASPECT[src]
    drop = (w / 2) * math.sin(math.radians(abs(deg)))
    bottom = base - drop
    sx = 26 * shadow_dir
    contact = box("contact", cx - w * .55, base - 12, w * 1.1, 24)
    img = (f'<img class="abs ph" src="img/{src}.png" alt="" style="left:{cx - w/2:.0f}px;top:{bottom - h:.0f}px;height:{h:.0f}px;'
           f'transform:rotate({deg}deg);filter:drop-shadow({sx}px 30px 30px rgba(14,18,40,.30))">')
    return contact + img


def page(W, H, body, title):
    return (f'<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>{title}</title>'
            '<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;800;900&display=swap" rel="stylesheet">'
            f'<style>{CSS.replace("{W}", str(W)).replace("{H}", str(H))}</style></head><body>{body}'
            '<script>document.fonts.ready.then(() => { window.READY = true; });</script></body></html>')


def brand(x, y, size):
    return (f'<div class="abs brand" style="left:{x}px;top:{y}px;font-size:{size}px">{MARK.format(w=round(size*.85), h=round(size*1.24))}'
            f'<span><b style="font-weight:900">Prime</b> <span style="color:#69707C;font-weight:500">Mobile</span></span></div>')


# ------------------------------------------------------------------ содержание баннеров
BANNERS = {
    "tradein": {
        "title": "Trade-in<br>вашего<br>iPhone",
        "sub": "Сдайте старый iPhone и заберите новый.<br>Доплачиваете только разницу.",
        "sub_m": "Сдайте старый iPhone<br>и заберите новый.<br>Доплачиваете<br>только разницу.",
        "phones": [("old-12-blue", .82, -.22, -11, -1), ("back-17-pro-max", 1.0, .20, 8, 1)],
        "tw": 150, "ts": 124,
    },
    "novinka": {
        "title": "iPhone 17<br>Pro Max",
        "price": ("869 000 ₸", "36 208", "× 24 мес"),
        "sub": "Уже в магазине. Все цвета и объёмы памяти.",
        "sub_m": "Уже в магазине.<br>Все цвета и объёмы.",
        "phones": [("back-17-pro", .86, -.22, -10, -1), ("back-17-pro-max", 1.0, .20, 7, 1)],
        "tw": 150, "ts": 124,
    },
    "bu": {
        "title": "Б/у<br>с гарантией",
        "sub": "Каждый телефон проверяем: батарея,<br>камеры, IMEI в госбазе. Гарантия 3 месяца.",
        "sub_m": "Проверяем батарею,<br>камеры и IMEI<br>в госбазе.<br>Гарантия 3 месяца.",
        "phones": [("dark-15-pro-max", .82, -.12, 0, 1)],
        "tw": 124, "ts": 100,
        "card": [("Батарея", "93%"), ("Оценка", "A"), ("IMEI", "проверен"), ("Гарантия", "3 мес")],
    },
}


def wide(key):
    b = BANNERS[key]
    W, H = 1672, 941
    cx, top_y, pw = 1280, 773, 660
    body = stage(cx, top_y, pw, (1355, 315), 450)
    base_h = 610
    for src, k, dx, deg, sd in b["phones"]:
        body += phone(src, base_h * k, cx + dx * pw / 1.3, top_y - 2, deg, sd)
    if "card" in b:
        rows = "".join(f'<div class="row" style="padding:16px 0;font-size:26px"><span style="color:#69707C">{a}</span><b style="font-weight:800">{v}</b></div>' for a, v in b["card"])
        body += f'<div class="abs card" style="left:1360px;top:330px;width:290px;padding:12px 26px">{rows}</div>'
    tw = b["tw"]
    body += f'<h1 class="abs" style="left:92px;top:148px;font-size:{tw}px">{b["title"]}</h1>'
    y = 148 + tw * .88 * b["title"].count("<br>") + tw + 40
    if "price" in b:
        p, per, n = b["price"]
        body += (f'<div class="abs" style="left:96px;top:{y:.0f}px"><div class="price" style="font-size:74px">{p}</div>'
                 f'<div style="margin-top:10px;display:flex;align-items:center;gap:14px;font-size:30px;color:#2B2F36">'
                 f'<span class="pill" style="padding:4px 14px;font-size:32px">{per}</span>{n}</div></div>')
        y += 170
    body += f'<p class="abs sub" style="left:96px;top:{y:.0f}px;font-size:36px">{b["sub"]}</p>'
    body += brand(96, 845, 26)
    return page(W, H, body, key)


def square(key):
    b = BANNERS[key]
    W, H = 1080, 1080
    cx, top_y, pw = 800, 968, 470
    body = stage(cx, top_y, pw, (850, 660), 340)
    base_h = 440
    for src, k, dx, deg, sd in b["phones"]:
        body += phone(src, base_h * k, cx + dx * pw / 1.25, top_y - 2, deg, sd)
    if "card" in b:
        rows = "".join(f'<div class="row" style="padding:12px 0;font-size:22px"><span style="color:#69707C">{a}</span><b style="font-weight:800">{v}</b></div>' for a, v in b["card"])
        body += f'<div class="abs card" style="left:780px;top:520px;width:250px;padding:8px 22px">{rows}</div>'
    ts = b["ts"]
    body += f'<h1 class="abs" style="left:72px;top:84px;font-size:{ts}px">{b["title"]}</h1>'
    y = 84 + ts * .88 * b["title"].count("<br>") + ts + 40
    if "price" in b:
        p, per, n = b["price"]
        body += (f'<div class="abs" style="left:76px;top:{y:.0f}px"><div class="price" style="font-size:64px">{p}</div>'
                 f'<div style="margin-top:8px;display:flex;align-items:center;gap:12px;font-size:28px;color:#2B2F36">'
                 f'<span class="pill" style="padding:3px 12px;font-size:30px">{per}</span>{n}</div></div>')
        y += 150
    body += f'<p class="abs sub" style="left:76px;top:{y:.0f}px;font-size:34px">{b["sub_m"]}</p>'
    body += brand(76, 990, 26)
    return page(W, H, body, key)


if __name__ == "__main__":
    for k in BANNERS:
        (OUT / f"pb-{k}-wide.html").write_text(wide(k), encoding="utf-8")
        (OUT / f"pb-{k}-square.html").write_text(square(k), encoding="utf-8")
    print("ok")
