---
name: StockFlow
description: Control de inventario general para revendedores — fintech premium, blanco y azul rey, minimalista con movimiento
colors:
  royal: "#3049f0"
  royal-deep: "#2136c4"
  royal-soft: "#eef0ff"
  ink: "#0b0f1a"
  ink-soft: "#5b6472"
  ink-faint: "#5f6b7a"
  paper: "#ffffff"
  surface: "#f6f7fb"
  surface-2: "#eef0f6"
  line: "#e7e9f0"
  success: "#12875a"
  success-soft: "#e7f6ee"
  danger: "#d1332f"
  danger-soft: "#fdeceb"
  warning: "#8a5807"
  warning-soft: "#fbf1de"
typography:
  display:
    fontFamily: "Inter, system-ui, -apple-system, sans-serif"
    fontSize: "1.6rem"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Inter, system-ui, -apple-system, sans-serif"
    fontSize: "0.85rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Inter, system-ui, -apple-system, sans-serif"
    fontSize: "0.72rem"
    fontWeight: 600
    letterSpacing: "0.04em"
rounded:
  sm: "8px"
  md: "12px"
  lg: "18px"
components:
  button-primary:
    backgroundColor: "{colors.royal}"
    textColor: "#ffffff"
    rounded: "{rounded.sm}"
    padding: "9px 15px"
  button-primary-hover:
    backgroundColor: "{colors.royal-deep}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "9px 15px"
  kpi-card:
    backgroundColor: "{colors.paper}"
    rounded: "{rounded.md}"
    padding: "16px 18px"
  sidebar-item-active:
    backgroundColor: "{colors.royal-soft}"
    textColor: "{colors.royal-deep}"
    rounded: "{rounded.sm}"
---

# Design System: StockFlow

## Overview

**Creative North Star: "The Ledger Wallet"**

StockFlow reads like a premium banking app that happens to track sneakers and jerseys instead of transactions — the quality bar named directly by the product owner is Mercury (banking) and Arc Browser: white ground, one confident royal-blue accent, restrained chrome, and motion that feels precise rather than decorative. The system deliberately refuses two category defaults: the cluttered, colorful "marketplace dashboard" look (badges everywhere, rainbow charts, cartoon icons) and the flat, static admin-panel look (dense gray tables with no signature moment). Every screen earns its motion — a sidebar indicator that slides instead of snapping, KPI numbers that count up instead of appearing instantly, views that cross-fade instead of hard-cutting — so the app feels alive without ever feeling playful.

This is an Operate-mode surface (task completion, not persuasion): expression stays subordinate to scanability. Color is restrained — neutrals plus exactly one accent — and that accent is spent on the things that matter most: the primary action, the active nav state, money that's flowing in.

**Key Characteristics:**
- White paper ground, near-black ink text, one royal-blue accent — no gradients, no second competing hue.
- Fixed left sidebar with a sliding active-state indicator (the signature interaction).
- KPI cards read as data, not decoration: no colored left borders, numbers count up on load.
- Every icon is a hand-authored SVG line icon (1.75px stroke) — zero emoji, zero unicode glyphs standing in for icons.
- Motion is one coherent language (shared easing curve) across sidebar, tabs, modals, and KPI numbers — never scattered per-element hover tricks.

## Colors

Restrained strategy: neutrals carry the interface, and royal blue is spent only on what the user should notice first (primary actions, the active nav item, money flowing in).

### Primary
- **Royal Blue** (`#3049f0`): the one accent. Primary buttons, active sidebar item, focus rings, links, KPI figures that represent "your own money" (invested, potential revenue).
- **Royal Blue Deep** (`#2136c4`): hover/active state for royal blue elements. Never used at rest.
- **Royal Blue Soft** (`#eef0ff`): tint background for the active sidebar pill and the bulk-selection toolbar — signals "this is selected/active," never used for large surfaces.

### Neutral
- **Ink** (`#0b0f1a`): primary text, headings, the dark brand panel on auth screens.
- **Ink Soft** (`#5b6472`): secondary text — labels, table headers, muted captions.
- **Ink Faint** (`#5f6b7a`): tertiary text and default icon color (KPI sub-labels, hints, empty-state icons). Tuned to hold ≥4.5:1 contrast on white — the original `#9aa2b1` draft only cleared 2.57:1 and was rejected during finish review.
- **Paper** (`#ffffff`): page and card background.
- **Surface** (`#f6f7fb`): sidebar background, table header background, hover background for rows/buttons — one step off white, never a second "dark mode" region.
- **Line** (`#e7e9f0`): all hairline borders and dividers. StockFlow has no heavy borders; every border in the system is this 1px hairline.

### Semantic
- **Success** (`#12875a`) / **Success Soft** (`#e7f6ee`): profit, in-stock status, positive deltas.
- **Danger** (`#d1332f`) / **Danger Soft** (`#fdeceb`): losses, destructive actions, form errors.
- **Warning** (`#8a5807`) / **Warning Soft** (`#fbf1de`): aging stock (45–90 days), cautionary states. Darkened from an initial `#b5720a` during finish review once it was used as small bold table text (`#b5720a` cleared only ~3.9:1 at that weight/size).

### Named Rules
**The One Accent Rule.** Royal blue is the only saturated color in the interface. Every other color is a neutral or a semantic status color (success/danger/warning) reserved strictly for meaning, never decoration. If a new element wants a second "brand" color, it's wrong — route it through royal blue, ink, or a semantic color.

## Typography

**Body/Display/Label Font:** Inter (with `system-ui, -apple-system, sans-serif` fallback), loaded via Google Fonts, weights 400–800.

**Character:** A single grounded geometric-sans family carries every role — deliberately a "workhorse" choice rather than a display face with a point of view, because this is an Operate surface where legibility and density outrank personality. Money and counts are set with `font-variant-numeric: tabular-nums` everywhere (KPI values, table cells) so figures align in a column and don't jitter during the count-up animation.

### Hierarchy
- **Display** (800, 1.6rem, -0.02em tracking): KPI values and modal/auth headlines — the only place weight 800 appears.
- **Title** (700, ~1.02–1.1rem): section headings, card titles, sidebar brand.
- **Body** (400–600, 0.83–0.9rem): table cells, form inputs, paragraph copy.
- **Label** (600, 0.7–0.74rem, 0.04em tracking, often uppercase): table headers, KPI labels, field labels — always paired with `color: ink-soft` or `ink-faint`.

### Named Rules
**The Tabular Money Rule.** Any element displaying a monetary amount or a count that animates sets `font-variant-numeric: tabular-nums`. Never let a currency figure reflow its neighbors.

## Layout

Fixed-sidebar application shell: a 248px left sidebar (brand mark, nav, user/settings footer) and a fluid main column (sticky topbar + scrolling content, `max-width: 1180px`, centered). Content padding is generous (26px vertical, 28px horizontal on desktop) and drops to ~18px on mobile. Card and section gaps run 12–16px; the rhythm is consistently "more space above a heading than below it."

**Responsive rule (≤900px):** the sidebar collapses from a vertical rail into a horizontal icon-only bar (labels hidden, sliding indicator disabled — a snapping highlight would be a worse signal at that width than no indicator at all), and the two-panel auth screens drop their dark brand panel entirely, showing only the form. Wide tables never widen the page: every table lives inside its own `overflow-x:auto` scroll container (`.tscroll` / `.iw-scroll`), confirmed to hold zero page-level horizontal overflow at 375px width even with a 920px-wide capture table underneath.

## Elevation & Depth

Hybrid: mostly flat surfaces on a white ground, with soft, real shadows (offset + blur, never a hard block shadow) reserved for elements that float above the page — cards resting on `--surface`/`--paper`, and anything in a layer above content (modals, the toast).

### Shadow Vocabulary
- **shadow-sm** (`0 1px 2px rgba(11,15,26,.05), 0 1px 1px rgba(11,15,26,.03)`): resting elevation for KPI cards, tables, chart cards — barely-there separation from the page.
- **shadow-md** (`0 4px 10px rgba(11,15,26,.06), 0 1px 2px rgba(11,15,26,.04)`): hover elevation (onboarding template cards lift 2px + this shadow on hover).
- **shadow-lg** (`0 16px 40px rgba(11,15,26,.10), 0 4px 10px rgba(11,15,26,.05)`): modals and the toast — the only elements that sit in a layer clearly above the page.

### Named Rules
**The Earned Shadow Rule.** A shadow's blur must be at least 2× its vertical offset. A flat color block with a hard-edged offset (`Npx Npx 0`) never appears anywhere in this system — that belongs to a neobrutalist world StockFlow explicitly isn't.

## Shapes

Three-step radius scale, applied by surface size: **sm (8px)** for buttons, inputs, badges, and small controls; **md (12px)** for cards, KPI tiles, and table containers; **lg (18px)** for modals only. No sharp corners anywhere, and no radius above 18px — StockFlow never fully pills a rectangular surface (only genuinely round elements — avatars, the sidebar's active-state pill given its small height, status badges — use `border-radius: 50%`/`20px`+).

## Components

### Buttons
- **Shape:** 8px radius, all variants.
- **Primary** (`.btn.primary`): royal-blue background, white text, a subtle colored shadow (`0 1px 2px rgba(48,73,240,.25), 0 4px 10px rgba(48,73,240,.18)`) that makes it read as "lifted" even at rest. Used for exactly one action per view (save order, register sale, add field).
- **Ghost** (`.btn.ghost`): transparent background, ink text, 1px line border; hover fills with `surface` and darkens the border to `ink-faint`. Used for every secondary/cancel action.
- **Success** (`.btn.in`): green background, reserved specifically for "commit this to inventory" actions (guardar pedido).
- All buttons scale to `0.97` on `:active` (the only micro-interaction on buttons) and share the system's `cubic-bezier(.16,1,.3,1)` easing.

### Cards / Containers (KPI tiles)
- **Corner Style:** 12px.
- **Background:** paper, 1px `line` border, `shadow-sm`.
- **No colored left border** — the previous jersey-era system marked KPI variants with a 4px colored `::before` bar; that pattern is explicitly retired (a colored border-left/right above 1px is a banned pattern in this system).
- **Internal Padding:** 16px 18px, label/value/sub-label stacked with a 6px gap.
- **Signature behavior:** values carrying `data-raw`/`data-format` count up from 0 on every render via `animateKpis()` — a 650ms ease-out, shared across every KPI panel in the app (Entradas, Salidas, Inventario, Reportes).

### Inputs / Fields
- **Style:** 1px `line` border, 8px radius, `paper` background, 9–11px padding.
- **Focus:** border turns royal blue plus a 3px `royal-soft`-tinted ring (`box-shadow: 0 0 0 3px var(--royal-ring)`) — no color-only focus indicator; the ring is always present alongside the border-color change.
- **Error:** surfaced via a full-width `.errbox` banner above the form (red-soft background, red text), not per-field red borders — StockFlow currently treats form failure at the form level, not the field level.

### Navigation (Sidebar)
- Icon (18px, `ink-faint`) + label, 40px row height, 8px radius.
- **Default:** `ink-soft` text, `ink-faint` icon.
- **Hover:** text darkens to `ink`, icon darkens to `ink-soft` — no background change on hover, only on active.
- **Active:** text and icon both switch to `royal-deep`, and a `royal-soft` pill (`.sb-indicator`) slides underneath via `transform: translateY()` with a 0.32s ease-out — the pill is one shared element that moves, never a background swapped per-button.
- **Mobile (≤900px):** collapses to a horizontal icon-only bar; labels and the sliding indicator are both hidden rather than compressed.

### Chat bubbles (signature component)
User questions render as right-aligned royal-blue bubbles with a sharp bottom-right corner (`border-bottom-right-radius: 3px`); assistant answers render as left-aligned `surface`-background bubbles with a matching sharp bottom-left corner and a 1px `line` border — a lightweight, non-skeuomorphic take on the messaging-bubble convention that stays inside the system's flat, hairline-bordered material language rather than borrowing a phone-OS chat style.

## Do's and Don'ts

### Do:
- **Do** spend royal blue only on the primary action, the active nav state, and figures representing the user's own money — everywhere else, use ink/surface neutrals.
- **Do** give every icon a hand-authored SVG from `assets/js/icons.js` (client) / `src/Support/icons.php` (server) — both mirrors must stay in sync when a new icon is added.
- **Do** use `data-raw`/`data-format` + `animateKpis()` for any new KPI number so the count-up motion stays consistent app-wide.
- **Do** keep every shadow a real offset+blur pair (see Shadow Vocabulary) — never a flat colored halo.
- **Do** contain wide tables in their own `overflow-x:auto` wrapper so the page itself never scrolls horizontally.

### Don't:
- **Don't** add a colored `border-left`/`border-right` above 1px to any card, list item, or KPI tile — that's the retired jersey-era pattern.
- **Don't** use an emoji or unicode glyph (✓, ▲, ✕, ⚙, etc.) as a functional icon anywhere in the UI — always route through the icon system.
- **Don't** introduce a second saturated accent color. If something needs to stand out, it's either royal blue (action/emphasis) or a semantic color (success/danger/warning) — never a new hue.
- **Don't** give a button, input, or badge a radius outside the 8/12/18px scale, and never a fully square corner.
- **Don't** ship a new interactive element with only a hover effect and no `:focus-visible` state — every interactive element in this system defines both.
