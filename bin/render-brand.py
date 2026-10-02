#!/usr/bin/env python3
"""Render the wordpress.org icon and banner images from docs/brand/.

Source: docs/brand/icon.png (square artwork, white on black, 256px or larger)
        docs/brand/brand.json (name and the two banner lines)
Output: .wordpress-org/icon-256x256.png, icon-128x128.png,
        banner-1544x500.png, banner-772x250.png,
        and the plugin's own copy of the icon, if brand.json names a
        plugin_icon path.

Needs Python 3 with Pillow, and the Poppins font (set POPPINS_DIR if it
is not in /usr/share/fonts/truetype/google-fonts).

Run from the repo root: python3 bin/render-brand.py
"""
import json
import os
import sys

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BRAND = os.path.join(ROOT, "docs", "brand")
ORG = os.path.join(ROOT, ".wordpress-org")
FONTS = os.environ.get("POPPINS_DIR", "/usr/share/fonts/truetype/google-fonts")

BG = (0, 0, 0)
INK = (255, 255, 255)
SOFT = (200, 200, 200)


def font(weight, size):
    path = os.path.join(FONTS, f"Poppins-{weight}.ttf")
    if not os.path.exists(path):
        sys.exit(f"Missing font {path}. Install Poppins or set POPPINS_DIR.")
    return ImageFont.truetype(path, size)


def main():
    with open(os.path.join(BRAND, "brand.json"), encoding="utf-8") as f:
        brand = json.load(f)
    icon = Image.open(os.path.join(BRAND, "icon.png")).convert("RGB")
    if icon.width != icon.height or icon.width < 256:
        sys.exit("docs/brand/icon.png must be square and at least 256px.")
    os.makedirs(ORG, exist_ok=True)

    icon.resize((256, 256), Image.LANCZOS).save(os.path.join(ORG, "icon-256x256.png"), optimize=True)
    icon.resize((128, 128), Image.LANCZOS).save(os.path.join(ORG, "icon-128x128.png"), optimize=True)
    if brand.get("plugin_icon"):
        size = brand["plugin_icon"]["size"]
        icon.resize((size, size), Image.LANCZOS).save(os.path.join(ROOT, brand["plugin_icon"]["path"]), optimize=True)

    # Banner, drawn at 1544x500 and halved for 772x250.
    w, h = 1544, 500
    banner = Image.new("RGB", (w, h), BG)
    art = 400
    banner.paste(icon.resize((art, art), Image.LANCZOS), (70, (h - art) // 2))

    draw = ImageDraw.Draw(banner)
    x = 70 + art + 70
    name_font = font("Bold", 104)
    line_font = font("Regular", 42)
    name_box = draw.textbbox((0, 0), brand["name"], font=name_font)
    line_h = draw.textbbox((0, 0), "Ag", font=line_font)[3]
    gap = 26
    block = (name_box[3] - name_box[1]) + gap + len(brand["lines"]) * (line_h + 10)
    y = (h - block) // 2 - name_box[1]
    draw.text((x, y), brand["name"], font=name_font, fill=INK)
    y += name_box[3] + gap
    for line in brand["lines"]:
        draw.text((x + 4, y), line, font=line_font, fill=SOFT)
        y += line_h + 10

    banner.save(os.path.join(ORG, "banner-1544x500.png"), optimize=True)
    banner.resize((772, 250), Image.LANCZOS).save(os.path.join(ORG, "banner-772x250.png"), optimize=True)
    print("Rendered icons and banners into .wordpress-org/")


if __name__ == "__main__":
    main()
