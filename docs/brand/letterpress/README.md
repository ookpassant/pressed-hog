# Letterpress banner

Source for the wordpress.org banners in `.wordpress-org/banner-*.png`:
the Pressed Hog artwork (`docs/brand/icon.png`) with IM Fell English, a
typeface cut from 17th-century letterpress type, to match the press.

- `banner.html` is the layout, drawn at 1544x500.
- `fonts/` holds IM Fell English (Roman, Italic, Small Caps) by Igino
  Marini, under the SIL Open Font License (`fonts/OFL.txt`).
- `render.mjs` renders both banner sizes. From the repo root:
  `node docs/brand/letterpress/render.mjs` (needs Playwright).

Because `docs/brand/brand.json` sets `"banner": "letterpress"`,
`bin/render-brand.py` still renders the icons but leaves these banners
alone.
