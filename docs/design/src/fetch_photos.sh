#!/usr/bin/env bash
# Скачивает временные фото моделей для макетов (GSMArena). В репозиторий фото не кладём:
# это чужие картинки, в системе будут фото из sfon или собственные фото магазина.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p img
base=https://fdn2.gsmarena.com/vv/bigpic
for n in apple-iphone-16-pro apple-iphone-16 apple-iphone-15 apple-iphone-15-pro-max apple-iphone-14-pro \
         apple-iphone-13 apple-ipad-air-11-2024 samsung-galaxy-a55 xiaomi-redmi-note-14-pro-5g; do
  curl -fsS -A "Mozilla/5.0" -o "img/$n.jpg" "$base/$n.jpg"
done
curl -fsS -A "Mozilla/5.0" -o img/samsung-galaxy-s25-ultra.jpg "$base/samsung-galaxy-s25-ultra-sm-s938.jpg"
echo "готово: $(ls img | wc -l) фото"
