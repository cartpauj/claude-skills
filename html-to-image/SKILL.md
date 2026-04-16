---
name: html-to-image
description: Generate pixel-perfect PNGs from code-drawn HTML/CSS using Playwright + ImageMagick. Ideal for banners, icons, logos, social cards, plugin assets, or any flat/typographic design. Does NOT generate photorealistic or illustrated imagery — this is deterministic code-drawn output, not AI image generation. Trigger when the user asks to make/design/generate a banner, icon, logo, hero, social card, OG image, plugin asset, or thumbnail.
---

# HTML to Image

Render HTML/CSS to PNG at exact target dimensions. Sharp, deterministic, iterable.

Good fit: flat/geometric designs, wordmarks, typographic icons, card layouts, data viz, WP.org plugin assets, social/OG cards.
Bad fit: photorealistic imagery, complex illustration, painted artwork — refuse these.

## Ask only what you need (max 2 questions)

1. **What the image is about** — theme, purpose, required copy, brand colors. Skip if the prompt already says it.
2. **Dimensions** — `WxH` per output. Infer from context when possible:
   - "WP plugin banner" → `1544x500` + `772x250`
   - "WP plugin icon" → `256x256` + `128x128`
   - "OG card" / "social card" → `1200x630`
   - "favicon" → `32x32` (ask if multi-size wanted)

Do NOT ask about colors, style, fonts, or layout up front. Propose something, render, iterate. Do NOT ask where to save unless the user named a destination (default: the work dir).

## Reusable tools in this skill

All three are executable and live at `$CLAUDE_SKILL_DIR`:

- **`setup.sh [work-dir]`** — creates/enters the work dir, installs Playwright if missing, verifies ImageMagick. Prints the absolute work dir path.
- **`render.mjs`** — reads `preview.html` + `jobs.json` from `cwd`, renders each asset at 4× DSR, downsamples with Catrom + unsharp. Optional env: `H2I_VIEWPORT="WxH"` (default 2400x6000), `H2I_UNSHARP="0.7"`.
- **`strip-white.sh <in> [out] [fuzz%]`** — turns a white-background JPG into a transparent PNG (for dropping brand logos on dark designs).

You do not need to re-emit the render pipeline or setup commands — call these scripts.

## Workflow

### 1. Bootstrap

```bash
WORK=$(bash "$CLAUDE_SKILL_DIR/setup.sh")
cd "$WORK"
```

### 2. Write `preview.html`

One file, all requested assets side by side. Each asset must:

- Have a unique `id` matching its intended output (`#banner`, `#icon256`, etc.)
- Be sized to its **exact target dimensions** (`width: 1544px; height: 500px`)
- Load Inter from Google Fonts — sharper on Linux/Chromium than system fallback

Minimal skeleton:

```html
<!doctype html><html><head>
<meta charset="utf-8">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { background: #fff; font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  body { padding: 32px; display: flex; flex-direction: column; gap: 24px; }
  .asset { position: relative; overflow: hidden; }
  /* your assets, e.g.: */
  #banner   { width: 1544px; height: 500px; }
  #icon256  { width: 256px;  height: 256px; }
</style></head><body>
  <div id="banner"  class="asset">…</div>
  <div id="icon256" class="asset">…</div>
</body></html>
```

### 3. Write `jobs.json`

```json
[
  { "sel": "#banner",  "out": "banner-1544x500.png", "w": 1544, "h": 500 },
  { "sel": "#icon256", "out": "icon-256x256.png",    "w": 256,  "h": 256 }
]
```

### 4. Render, show, iterate

```bash
node "$CLAUDE_SKILL_DIR/render.mjs"
```

Read each output PNG so the user sees it inline. They'll respond with tweaks ("bigger", "move left", "change color"). Edit the CSS in `preview.html` in place, re-run render, show again. Don't rebuild `preview.html` from scratch on each tweak.

### 5. Finalize

When the user approves:

1. If they named a destination (e.g. `.wordpress-org/`), `cp` the PNG(s) there.
2. If replacing existing files with a different extension (existing `.jpg` → new `.png`), ask before deleting the originals.
3. If no destination, leave files in the work dir and print the absolute path.

## Landmines

- **Border-radius bleeds the page background.** Playwright doesn't paint pixels outside the rounded path; they get whatever the body bg is. For solid-square assets (WP.org icons/banners etc.), **don't round corners** — hosts apply their own mask. For genuinely transparent rounded PNGs: `body { background: transparent }` AND pass `omitBackground: true` to `page.locator(sel).screenshot(...)` (would require a custom render call, not this skill's `render.mjs`).
- **Flex `flex: 1` absorbs negative margins.** A negative `margin-left` on a flex child does nothing visible when a sibling has `flex: 1` — the grow sibling eats the freed space. Use `transform: translateX(-Npx)` instead.
- **`box-shadow` bleeds outside the element.** Drop it on the final asset, or wrap in a fixed-size container with `overflow: hidden`.
- **WP.org small banners are NOT crops of the retina.** Both `1544x500` and `772x250` must show the same content. Easiest: wrap a scaled copy — `<div style="width:772px;height:250px;overflow:hidden"><div style="transform:scale(0.5);transform-origin:top left;width:1544px;height:500px">…same markup…</div></div>`.

## Verifying solid corners

To confirm an asset has solid corners (no page-bg leak):

```bash
identify -format "TL=%[pixel:p{0,0}] TR=%[pixel:p{W-1,0}] BL=%[pixel:p{0,H-1}] BR=%[pixel:p{W-1,H-1}]\n" OUT.png
```

Substitute literal W-1 / H-1 with width-1 / height-1.
