# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Small resellers and independent sellers who buy limited/unique batches of physical goods from suppliers and resell individual units — originally sneaker/jersey resellers, but the product is built to fit any resale category (clothing, sneakers, cosmetics, electronics, collectibles). They are hands-on operators: they capture their own purchases, register their own sales, and want to understand their own numbers without hiring anyone. The product must feel like a real tool other resellers would adopt and pay for, not a school-project demo.

## Product Purpose

StockFlow is a general-purpose inventory control system for resellers who track cost, shipping, and profit per physical unit — not per SKU/quantity. It lets an owner capture a supplier purchase order (splitting shared shipping cost across units, converting currency), record sales, browse/search/edit their stock, see sales analytics with a short-term sales projection, and ask a chat assistant natural-language questions about their own purchases/sales/projections.

## Positioning

Unlike inventory software built around SKU + quantity counts (built for warehouses with fungible stock), StockFlow tracks each physical unit as its own record with its own cost, shipping share, sale price, and profit — matching how small resellers who buy unique or limited batches (a jersey lot, a sneaker drop, a boutique clothing shipment) actually operate. Every user defines their own custom fields (no code changes needed) so the same tool fits jerseys, sneakers, makeup, or anything else. A built-in chat assistant answers business questions directly from the owner's own data via a safety-checked natural-language-to-SQL pipeline, rather than making them learn a reporting UI or write SQL themselves.

## Operating Context

Browser-based web app, used on desktop and mobile. Backend is PHP 8.2 (plain, no framework, no Composer) with MariaDB, currently self-hosted via XAMPP for local development; deployable to standard LAMP-style hosting. UI is Spanish-language throughout. Each user logs into their own account (multi-tenant, fully isolated data) and works across five areas: Entradas (capturar compras por lote), Salidas (ventas registradas), Inventario (listado/búsqueda/edición), Reportes (KPIs, gráficas, proyección de ventas), and Asistente (chat de preguntas y respuestas sobre su propio inventario).

## Capabilities and Constraints

- Multi-tenant accounts with email/password login; each user's inventory, purchase orders, suppliers, and chat history are fully isolated.
- A single per-business FIELD REGISTRY (`attribute_definitions`) is the only source of truth for what fields exist and where they appear. Each field declares its stable key, editable label, type (text/number/date/select/boolean/computed), options, whether it is required/editable/filterable/analytics-enabled, independent visibility AND ordering for each of the four screens (Entradas, Inventario, Salidas, Exportación), whether it is canonical or custom, its semantic role, and whether it is archived.
- Three classes of field, with different rules: INTERNAL (id, business_id, foreign keys, timestamps — never configurable), CANONICAL (cost, sale_price, dates, supplier, order — relabelable and hideable, never deletable, computed ones never editable), and CUSTOM (stored in `inventory_items.attributes`, treated identically by every layer).
- Each business designates one field as the product's principal identifier; it cannot be hidden or archived while it holds that role.
- Deleting a custom field archives it instead: the captured values stay in the JSON and return intact when it is restored.
- The "Campos y vistas" screen manages all of the above; onboarding offers starter templates that the user can freely edit afterward.
- Batch capture separates three levels — order header, values SHARED by every unit, and values that change per unit — so a 20-piece order of the same product is typed once and only the differing field (e.g. size) is filled per unit. Includes quantity generation, row duplication, fill-down, tabular paste from Excel/Sheets with header recognition, and a preview before saving. Each unit still becomes its own `inventory_items` row.
- Purchase-order capture splits a single shipping total across the batch's line items in whole cents, distributing the remainder so the per-unit costs sum exactly to what was paid; converts USD→MXN at a manually entered exchange rate; MXN is the working currency (no full multi-currency ledger yet).
- Excel import is an explicit flow: read headers → the server PROPOSES a mapping (by field label, internal key, then legacy aliases) → the user confirms or corrects it column by column → dry run reports how many rows would be inserted/updated/skipped → confirm. Two modes (add / sync); sync matches on a stable identity that excludes sale price and date, so selling a piece never duplicates it.
- Excel export draws its columns from the field registry with the business's own labels; the user can override the selection per download, choose the scope (all/in-stock/sold), and include the ID column that makes re-import an exact sync.
- Dashboard: KPI cards, Chart.js charts (top products, monthly trend, category profit/share), and a linear-trend/moving-average sales projection (1-3 months) computed server-side.
- Chat assistant calls the Anthropic Claude API to translate a question into a single read-only SQL query, validated by a strict allow-list guard (table allow-list, no DDL/DML, mandatory user-scoping token) before execution against a read-only DB credential; projection-style questions are routed to the same trend calculation the dashboard uses instead of trusting the model to compute forecasts.
- No native mobile app; no other LLM provider wired in besides Anthropic; no automated test suite yet.

## Brand Commitments

Name: **StockFlow**. No existing logo or visual identity yet — this redesign establishes it from scratch. Confirmed direction: white background with a royal-blue accent color, minimalist, with purposeful motion/animation (not a static, flat admin-panel feel). The prior visual identity (a soccer-pitch green/gold theme inherited from the product's jersey-only predecessor) is being fully replaced, not refined.

## Evidence on Hand

No real customers, testimonials, case studies, or press yet — this is a pre-launch product. A local demo/test account exists with one fabricated sample sale for development purposes only; future work must not present it as real customer evidence.

## Product Principles

- One unit, one story — every physical piece keeps its own cost, shipping share, and profit; nothing is averaged away into a SKU-level count.
- Configure once, fits anything — custom fields let the same product serve jerseys, sneakers, cosmetics, or any other resale category without touching code.
- Answers, not just logging — the dashboard and chat assistant exist so an owner can act on trends (slow movers, profit by category, sales projection), not just record transactions.
- Ask your data, don't query it — the chat assistant should feel like asking a knowledgeable partner about your own numbers, always safely scoped to that owner's data alone.
