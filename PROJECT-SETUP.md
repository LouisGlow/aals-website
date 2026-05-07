# Project Setup Reference


Use this file as a checklist when starting a new website project. Follow the steps in order and you will have the full stack ready before writing a single line of code.

---

## 1. Claude Plugins to Install

### `anthropic-design` — Frontend Design Skill
The main skill that generates production-quality single-page websites.

**Install:**
```
@path/to/anthropic-design.plugin
```
Or if already cached, enable it in `~/.claude/settings.json`:
```json
{
  "enabledPlugins": {
    "anthropic-design@local": true
  }
}
```

**How to invoke:**
```
/frontend-design
```
Then paste your full content brief (see Section 6 below for brief template).

---

### `media-and-external-apis` — AI Image Generation
Used for generating lifestyle/service card images via fal.ai or Pollinations.

**Plugin file location:**
```
C:\Users\louis\.claude\plugins\cache\local\media-and-external-apis\0.1.0\
```

**Enable in `~/.claude/settings.json`:**
```json
{
  "enabledPlugins": {
    "media-and-external-apis@local": true
  }
}
```

**Enable in `~/.claude/plugins/installed_plugins.json`:**
```json
[
  {
    "id": "media-and-external-apis@local",
    "path": "C:\\Users\\louis\\.claude\\plugins\\cache\\local\\media-and-external-apis\\0.1.0",
    "enabled": true,
    "scope": "project"
  }
]
```

**How to invoke:**
```
/fal-ai-media
```
Then provide your fal.ai API key when prompted.

**fal.ai API key (check balance before use):**
- Key stored separately — check fal.ai dashboard for credits
- Fallback: Pollinations.ai (free, no key needed, sequential requests only)
- Fallback: Gemini Imagen (requires paid Google AI plan)

---

## 2. MCP Server — fal.ai

Add to `C:\Users\louis\AppData\Roaming\Claude\claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "fal-ai": {
      "command": "npx",
      "args": ["-y", "fal-ai-mcp-server"],
      "env": {
        "FAL_KEY": "YOUR_FAL_KEY_HERE"
      }
    }
  }
}
```

**Restart Claude Desktop after editing this file.**

---

## 3. GitHub + Cloudflare Pages Pipeline

### Step 1 — Create GitHub repo
1. Go to github.com → New repository
2. Name it (e.g. `client-name-website`)
3. Set to Public (required for free Cloudflare Pages)
4. Do NOT initialise with README

### Step 2 — Push local project
```bash
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

### Step 3 — Connect Cloudflare Pages
1. Go to cloudflare.com → Workers & Pages → Create
2. Connect to Git → select your GitHub repo
3. Build settings:
   - **Framework preset:** None
   - **Build command:** *(leave blank)*
   - **Build output directory:** `/` (root)
4. Deploy

**Result:** Every `git push origin main` auto-deploys to `your-project.pages.dev`

### Step 4 — Share with client
Send the `*.pages.dev` URL for approval. No custom domain needed for review stage.

---

## 4. Project File Structure

```
project-root/
├── index.html                  ← Single file: all HTML + CSS + JS inline
├── favicon.svg                 ← 64×64 SVG badge mark
├── favicon-16.png
├── favicon-32.png
├── favicon-96.png
├── apple-touch-icon.png        ← 180×180px
├── PROJECT-SETUP.md            ← This file
├── site-content-reference.md   ← Content scraped from client's existing site
├── .gitignore
└── assets/
    └── images/
        ├── logo.svg            ← Colour logo (navy + gold)
        ├── logo-white.svg      ← White logo variant (for dark header/footer)
        ├── logo.jpeg           ← JPEG export for email/docs
        ├── hero-doctors.jpg    ← Hero section team photo
        ├── svc_gp.jpg          ← Service card images (x6)
        ├── svc_procedure.jpg
        ├── svc_diabetes.jpg
        ├── svc_skin.jpg
        ├── svc_lancet.jpg
        ├── svc_mental.jpg
        ├── Dr_[Name].jpg       ← Doctor headshots
        └── Staff_[Name].jpg    ← Support staff headshots
```

---

## 5. Favicon Generation (Node.js / sharp)

If you have an SVG logo and need PNG favicons:

```bash
# In a temp folder
mkdir /tmp/favicons && cd /tmp/favicons
npm init -y
npm install sharp

node -e "
const sharp = require('sharp');
const fs = require('fs');
const svg = fs.readFileSync('PATH_TO/favicon.svg');
const sizes = [16, 32, 96, 180];
sizes.forEach(s => {
  sharp(svg).resize(s,s).png().toFile(
    s === 180 ? 'apple-touch-icon.png' : 'favicon-' + s + '.png',
    (e,i) => console.log(s+'px done')
  );
});
"
```

Copy the output `.png` files to the project root.

---

## 6. New Project Brief Template

When invoking `/frontend-design` for a new site, paste this structure:

```
Build a complete, modern, production-quality single-page website.
Use ALL the real content below — do not use placeholder text.

## BRAND DIRECTION
- Feel: [warm/professional/minimal/bold — describe the vibe]
- NOT: [what to avoid]
- Colour palette: [primary, background, accent]
- Typography: [style preference]
- Mobile-first, fully responsive

## REAL CONTENT
Practice/Business name: 
Address: 
Phone: 
WhatsApp: 
Email: 
Hours: 

## SECTIONS
1. Sticky header / nav — logo, nav links, CTA button
2. Hero — headline, subheadline, 2 CTAs, hours badge
3. Services grid — 6 cards with icons, titles, descriptions
4. Our People — team cards with photos, qualifications
5. Contact & Map — contact details + Google Maps embed
6. Footer — logo, tagline, quick links, hours, copyright

## TECHNICAL REQUIREMENTS
- Single HTML file, CSS in <style> tags (no external dependencies except Google Fonts)
- Smooth scroll, sticky header, hover states
- Mobile hamburger menu
- WhatsApp floating button (bottom right, green)
- Accessible: semantic HTML, aria-labels, sufficient contrast
- Dark mode toggle (light default, dark opt-in via localStorage)
```

---

## 7. Workflow — Starting From a Client's Existing Site

### Step 1 — Scrape content into reference file
Tell Claude:
> "Go to [CLIENT URL] and create a `site-content-reference.md` file with all the real content — team names, qualifications, services, hours, contact details, address, everything."

Claude will read the live site and save a structured markdown file.

### Step 2 — Generate the site
Tell Claude:
> "Use the content in `site-content-reference.md` and invoke `/frontend-design` to build the full single-page website."

### Step 3 — Images
- **Service cards:** Ask Claude to generate Gemini prompts per service, feed to Gemini Imagen, save as `assets/images/svc_[name].jpg`
- **Hero photo:** Ask Claude for a team photo prompt, feed to Gemini, save as `assets/images/hero-doctors.jpg`
- **Staff/Doctor photos:** Ask client to supply headshots, drop into `assets/images/`

### Step 4 — Logo
Tell Claude:
> "Design an SVG logo for [Business Name] — badge mark + wordmark. Save as `logo.svg` (colour) and `logo-white.svg` (white variant for dark backgrounds)."

### Step 5 — Review & deploy
1. Push to GitHub (auto-deploys to Cloudflare Pages)
2. Share `*.pages.dev` link with client
3. Iterate on feedback in this Claude Code session

---

## 8. Design Tokens Used on This Project

Adapt these for new projects:

```css
/* Navy/teal primary scale */
--t900: #0f1525   /* darkest — footer, hero dark */
--t800: #23315a
--t700: #2a3d6b
--t600: #3a5ba0   /* primary brand colour */
--t500: #4a6db5
--t400: #6ea3c1   /* accent teal */
--t200: #b8cde3
--t100: #d4e4f0
--t50:  #edf3f8   /* lightest tint */

/* Gold accent scale */
--a600: #c4920a   /* hover/dark gold */
--a500: #f7c873   /* primary gold — CTAs, highlights */
--a400: #fad690
--a100: #fef7e3

/* Typography */
--fd: 'Playfair Display', Georgia, serif   /* display/headings */
--fb: 'Libre Baskerville', Georgia, serif  /* body */
```

**Google Fonts import:**
```html
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,400&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
```

---

## 9. Common Fixes & Known Issues

| Problem | Fix |
|---|---|
| Site not updating in browser | Hard refresh: `Ctrl+Shift+R` |
| Nav pill jumping on hover | Use `transform: translateX()` not `left`; fix specificity with `li:not(.nav-pill)` |
| Dark mode showing on light default | Remove OS `prefers-color-scheme` detection; use `localStorage` only |
| Service card images have blue tint | CSS filter: `brightness(1.04) saturate(1.15) sepia(0.08)` |
| fal.ai returns 403 | Account balance exhausted — top up or use Pollinations fallback |
| Pollinations returns 429 | Rate limit — use sequential requests with 20s delay between each |
| SVG → PNG conversion (Windows, no Inkscape) | Use Node.js `sharp` package in a temp folder via Bash |
| Image not showing on Cloudflare | File was never staged — run `git add assets/images/filename.jpg` then push |
| Contact card and map different heights | `align-items: stretch` on grid + `height: 100%` on both children |

---

## 10. Useful Commands

```bash
# Push a single new image file
git add assets/images/new-image.jpg
git commit -m "Add new-image.jpg"
git push origin main

# Push all changes
git add -A
git commit -m "Description of changes"
git push origin main

# Check what's staged / unstaged
git status

# See recent commits
git log --oneline -10
```

---

*Generated during the Krugersdorp Medical Centre build session — May 2026*
