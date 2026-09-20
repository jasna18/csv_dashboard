# Frontend TASK — CSV Dashboard UI

**Stack:** Nuxt 4.5 (Vue 3, `<script setup>`, TypeScript, SSR) · Pinia · Tailwind CSS · ApexCharts
**Input:** the Laravel API in [backend/TASK.md](../backend/TASK.md)

> **⚠️ Premise change.** §1 goal 2 and §8 assume the CSV's columns are unknown and read from a
> `columns[]` API response. That is no longer true: the backend now has a fixed 12-column
> `add_csv` table (work-order data), so the dimensions and measures are known at build time.
> See backend §2a — the single-purpose vs generic question is still open, and it decides whether
> the chart builder in §8 gets built at all. Until then, treat §4's schema-driven typing and §8's
> dynamic pickers as **on hold**, and hardcode against `add_csv`'s columns where it unblocks work.
**Output:** upload a CSV, watch it import, then explore it as KPI cards, charts and a paged table

**Progress:** 24 / 136 complete. `- [ ]` = not started, `- [x]` = done. Tick each box as the task lands, and bump the count on this line.

---

## 1. Goals

1. Upload a CSV with real progress feedback, then poll until the backend reports `ready`.
2. Read the inferred schema and build the entire UI from it — **zero hardcoded column names**.
3. Let the user compose charts by picking a dimension, a measure and an aggregation.
4. Show 5,000 × 40 raw rows without ever holding them all in the browser.
5. Stay usable while data is loading, empty, or broken.

**Non-goals for v1:** auth screens, saved dashboards per user, real-time collaboration, CSV editing.

---

## 1a. Fleet reliability dashboard — `/dashboard` ✅ BUILT

Light ground, no sidebar, blue-and-green. Reads `GET /api/reliability/dashboard` once per
filter change and drives all fourteen widgets from that single payload.

- [x] `npm i apexcharts vue3-apexcharts` → apexcharts 7.1, binding 1.11
      (pinning `apexcharts@^4` fails: the binding needs ≥5.10)
- [x] `app/plugins/apexcharts.client.ts` — `.client.ts` is mandatory, ApexCharts reads
      `window` at import time and crashes SSR otherwise
- [x] `app/composables/useChartTheme.ts` — palette, chrome, formatters, shared options.
      One file owns chart colour; it is also the ECharts swap point.
- [x] `app/components/charts/BaseChart.client.vue` — the single wrapper every chart uses
- [x] `app/components/dashboard/` — `WidgetCard`, `StatTile`, `DataTable`
- [x] `app/pages/dashboard.vue`
- [x] KPI row: serviceability, availability, MTBF, MTTR, downtime, response time — each with
      a definition tooltip and a status dot **plus a word**, never colour alone
- [x] Filters in one row scoping the whole grid: month, fleet, equipment, location, work
      type, fault cause, and the measured/reported basis toggle
- [x] Widgets: 2 gauges, 2 cause pies, availability/MTBF/MTTR/downtime trends, repair-time
      histogram, and by-fleet / by-location / by-work-type / worst-equipment / top-fault bars
- [x] Every chart has a table twin behind a per-card toggle — the WCAG-clean equivalent, and
      the documented relief for the series colours that sit under 3:1 on white
- [x] Verified: SSR renders the KPI values, filters re-drive all six (fault cause Technical →
      availability 97.8%, MTBF 139 hrs, MTTR 3.1 hrs), 390 px viewport has zero overflow
      (measured via CDP, `scrollWidth === clientWidth`), light and even at 1500 px

**Chart rules this page follows deliberately** — worth not undoing:

- **No dual-axis charts.** Downtime and work orders are separate cards, not two y-scales.
- **Palette validated, not eyeballed.** The five categorical hues clear the lightness band,
  chroma floor, colour-vision separation (worst adjacent pair ΔE 9.1 protan against an ≥8
  gate) and normal-vision floor. Blue and green lead because that is the brief; the tail
  exists so a 4th and 5th slice stay distinguishable. Re-run the check before changing one.
- **One hue per single-series chart.** Colouring bars darker-where-bigger would double-encode
  the length. The only ramp on the page is the repair-time histogram, whose bands are ordered.
- **Filters above the grid, never inside a card.** The reference screenshot puts a dropdown in
  every card; that shows several slices at once with no way to tell which card is which.
- [ ] Dark mode is not built. The tokens are in place for it, but a half-dark app is worse
      than a consistently light one — do it as one pass, with its own validated steps.

---
## 2. Chart library decision

| | ApexCharts | ECharts |
| --- | --- | --- |
| API surface | Small, declarative, good defaults | Large, more configuration |
| Bundle | ~140 KB gzipped | ~330 KB full, ~120 KB tree-shaken |
| Ceiling | Comfortable to ~5k points per series | Handles 100k+ with canvas rendering |
| Vue binding | `vue3-apexcharts` | `vue-echarts` |

**Chosen: ApexCharts.** Every chart here is served pre-aggregated by `/aggregate` with `limit ≤ 100`,
so the point counts are small and ApexCharts' defaults save real time.

**Switch to ECharts if** a requirement lands for a scatter plot of all 5,000 raw rows, a geo map,
or a treemap. Keep that door open by putting every chart behind a `<BaseChart>` wrapper (§6) so
the swap touches one file, not thirty.

**Critical Nuxt caveat:** ApexCharts touches `window` at import time and will crash SSR. Every
chart component must be `*.client.vue` or wrapped in `<ClientOnly>` with a skeleton `#fallback`.
Do not skip this — it fails only on the server render, so it can pass local dev and break on build.

---

## 3. Phase 0 — Project setup

> **Nuxt 4 directory layout.** Application code lives under `app/` — `app/pages/`,
> `app/components/`, `app/composables/`, `app/stores/`, `app/plugins/`, `app/utils/`,
> `app/app.vue`, `app/error.vue`. Only `nuxt.config.ts`, `public/`, `server/` and
> `shared/` sit at the project root. Every path below already reflects this; Nuxt 3
> tutorials that put `pages/` at the root do not apply.

- [x] `npx nuxi@latest init` — **Nuxt 4.5.2** installed, `minimal` template, 584 packages, 0 vulnerabilities
- [x] **SSR confirmed working** — `ssr: true` set explicitly in `nuxt.config.ts`; a production build served from `.output/server/index.mjs` returns fully rendered HTML (non-empty `#__nuxt`), not an SPA shell
- [x] `nitro.preset: 'node-server'` and `devServer.port: 3000` set
- [x] `npm i -D tailwindcss @tailwindcss/vite` and register the Vite plugin in `nuxt.config.ts` — **not** `@nuxtjs/tailwindcss`, which still targets Tailwind 3
- [ ] `npm i @pinia/nuxt` and add to `modules`
- [x] `npm i apexcharts vue3-apexcharts` — see §1a for the version constraint
- [x] Register ApexCharts in `app/plugins/apexcharts.client.ts` — **`.client.ts` suffix is mandatory**
- [x] `runtimeConfig.public.apiBase` from `NUXT_PUBLIC_API_BASE`, defaulting to `http://localhost:8000/api`
- [ ] `.env` with `NUXT_PUBLIC_API_BASE=http://localhost:8000/api`
- [ ] Confirm the Laravel CORS config allows `http://localhost:3000` before writing any fetch code
- [ ] Set up ESLint + Prettier

---

## 4. Phase 1 — API layer

Keep every network call in one place. Components never call `$fetch` directly.

- [ ] `app/composables/useApi.ts` — a `$fetch` instance bound to `apiBase`, with a response interceptor that normalises the backend's `{ message, errors, code }` envelope into a thrown `ApiError`
- [ ] `app/types/dataset.ts` — mirror the backend contract exactly:
  - [ ] `Dataset` — `uuid`, `original_filename`, `row_count`, `column_count`, `status`, `error_message`, `imported_at`
  - [ ] `DatasetColumn` — `name`, `key`, `type`, `role`, `distinct_count`, `null_count`, `min_value`, `max_value`, `sample_values`
  - [ ] `ColumnRole = 'dimension' | 'measure' | 'date' | 'ignored'`
  - [ ] `AggregateResponse` — `{ labels: string[], series: { name: string, data: number[] }[], total: number, truncated: boolean }`
  - [ ] `Filter` — `{ column: string, operator: FilterOperator, value: unknown }`
- [ ] `app/composables/useDatasets.ts` — one function per endpoint: `uploadDataset`, `listDatasets`, `getDataset`, `getStatus`, `getSummary`, `getAggregate`, `getRows`, `deleteDataset`
- [ ] `app/stores/dataset.ts` (Pinia) — the active dataset, its `columns[]`, and derived getters `dimensions`, `measures`, `dateColumns`, all filtered by `role`
- [ ] `app/stores/filters.ts` (Pinia) — the active filter array, shared by every chart and the table

---

## 5. Phase 2 — Upload flow

Route: `app/pages/index.vue`

- [x] Drag-and-drop zone plus a click-to-browse fallback, with a visible hover/drag state
- [x] Client-side pre-checks before any request: extension is `.csv` or `.txt`, size ≤ 32 MB, exactly one file
- [x] Show the picked filename and human-readable size, with a way to clear it
- [x] Upload with `XMLHttpRequest` (or `axios`) so `onUploadProgress` gives a real percentage — **plain `fetch` cannot report upload progress**, only download
- [x] Determinate progress bar 0–100% during transfer
- [ ] On the 202 response, store the `uuid` and move to the processing state
- [ ] Poll `GET /datasets/{uuid}/status` every 1.5 s
  - [ ] Show an indeterminate "Analyzing 40 columns…" state with `progress_percent` when the backend supplies it
  - [ ] `ready` → navigate to `/dashboard/{uuid}`
  - [ ] `failed` → show `error_message` with a Try another file action
  - [ ] Stop after ~120 polls (3 min) and offer a manual retry — a dead queue worker must not poll forever
  - [ ] **Clear the interval in `onUnmounted`** or navigating away leaks a timer that keeps firing
- [ ] Recent uploads list from `GET /datasets`, each row linking to its dashboard, with a delete action behind a confirm

---

## 6. Phase 3 — Chart foundation

- [ ] `app/components/charts/BaseChart.client.vue` — the single wrapper every chart goes through
  - [ ] props: `type`, `labels`, `series`, `height`, `loading`, `error`, `title`
  - [ ] owns the shared theme: palette, font, grid, tooltip, legend, number formatting, no chart toolbar
  - [ ] renders a skeleton while `loading`, an inline retry on `error`, and an empty state when `series` is empty
  - [ ] **this is the ECharts escape hatch** — swapping libraries means rewriting this file only
- [ ] `app/composables/useChartTheme.ts` — one exported options object merged into every chart, so all charts share axis, legend and tooltip styling
- [ ] Thin wrappers over `BaseChart`: `BarChart`, `LineChart`, `AreaChart`, `PieChart`, `DonutChart`, `StackedBarChart`
- [ ] `app/utils/format.ts` — compact numbers (`12.4k`, `1.2M`), percentages, currency, date labels. Long dimension labels must truncate with an ellipsis and show the full text in the tooltip; 20 untruncated category labels will otherwise collide on the X axis.

---

## 7. Phase 4 — Dashboard page

Route: `app/pages/dashboard/[uuid].vue`

- [ ] Fetch dataset meta + columns, then `/summary`, on mount; render a full-page skeleton until both land
- [ ] Handle `404` (unknown uuid) and `409` (not `ready`) with distinct, actionable screens
- [ ] **Header** — filename, row and column counts, import date, a Delete action, a Re-upload action
- [ ] **KPI cards** — a responsive row of 4–6 `StatCard`s from `/summary`: total rows, total columns, and the sum/avg of the first few measures. Each shows the metric name, the formatted value, and the column it came from.
- [ ] **Default chart grid** — auto-generate a starting dashboard so a fresh upload is never an empty page:
  - [ ] If a date column exists: a line chart of the first measure over time, `date_trunc=month`
  - [ ] A bar chart of the first measure grouped by the lowest-cardinality dimension
  - [ ] A donut chart of row counts by that same dimension
  - [ ] A horizontal bar of the top 10 values of the highest-cardinality useful dimension
  - [ ] Skip any chart whose required column role is absent, and say so rather than rendering a broken card
- [ ] Responsive grid: 1 column on mobile, 2 on tablet, 3 on desktop
- [ ] Every chart fetches independently — one failing `/aggregate` must not blank the whole page

---

## 8. Phase 5 — Chart builder

The part that makes 40 unknown columns actually explorable.

- [ ] `app/components/ChartBuilder.vue` — a panel or modal with:
  - [ ] Chart type picker (bar, line, area, pie, donut, stacked bar)
  - [ ] **Dimension** select, populated from `store.dimensions` and `store.dateColumns`
  - [ ] **Measure** select, populated from `store.measures` — disabled when `agg` is `count`
  - [ ] **Aggregation** select: sum, avg, count, min, max, count distinct
  - [ ] **Series by** — an optional second dimension for multi-series charts
  - [ ] **Date granularity** — shown only when the chosen dimension has `role: 'date'`
  - [ ] **Limit** — top N, default 20
- [ ] Warn before rendering when the chosen dimension's `distinct_count` is very high (e.g. > 50) — a 3,000-category bar chart is unreadable; suggest a lower top-N instead of just drawing it
- [ ] Disallow combinations the backend will reject, in the UI, before the request goes out
- [ ] Live preview that re-fetches on change, debounced ~300 ms
- [ ] Add to dashboard appends the chart to the grid
- [ ] Persist the user's chart layout in `localStorage`, keyed by dataset uuid, so a refresh doesn't discard their work
- [ ] Per-chart actions: edit, duplicate, remove, download as PNG (ApexCharts' `dataURI()`)

---

## 9. Phase 6 — Filters

- [ ] `app/components/FilterBar.vue` — add, edit and remove filters, each rendered as a removable chip
- [ ] Control type driven by `column.type`:
  - [ ] `string` → multi-select from `sample_values`, plus a contains text input for long tails
  - [ ] `integer` / `decimal` → min/max range inputs, bounded by `min_value` / `max_value`
  - [ ] `date` / `datetime` → a date-range picker, bounded the same way
  - [ ] `boolean` → a tri-state toggle (true / false / any)
- [ ] Filters live in the Pinia filter store; **every** chart and the table read from it, so one change refreshes them all
- [ ] Mirror active filters into the URL query string so a filtered view is shareable and survives a refresh
- [ ] A Clear all action, and a count badge showing how many filters are active
- [ ] Debounce filter changes ~300 ms — dragging a range slider must not fire a request per pixel

---

## 10. Phase 7 — Data table

5,000 rows × 40 columns is 200,000 cells. Rendering that as DOM will lock the browser.

- [ ] `app/components/DataTable.vue` backed by `GET /datasets/{uuid}/rows` — **server-side paging, 50 rows per page**
- [ ] Sticky header; sticky first column on horizontal scroll
- [ ] Horizontal scroll inside the table's own container — the page body must never scroll sideways
- [ ] **Column visibility picker** — default to the first ~10 columns and let the user enable the rest. 40 visible columns is unusable out of the box, and the `columns` query param means hidden ones aren't even fetched.
- [ ] Sort by clicking a header (server-side, via `sort` + `direction`)
- [ ] Right-align numerics, format by column type, render `null` as a muted `—` so it is distinguishable from an empty string
- [ ] Respect the shared filter store
- [ ] Page-size selector (25 / 50 / 100), pagination controls, and an "showing X–Y of Z" readout
- [ ] Loading skeleton rows on page change — do not blank the table
- [ ] Consider `@tanstack/vue-virtual` **only** if a requirement to scroll unpaged rows appears; server paging is the simpler correct answer for v1

---

## 11. Phase 8 — Polish

- [ ] Every async surface has all four states: loading, empty, error, success. No spinner-only screens.
- [ ] Skeleton loaders shaped like the real content, not centred spinners
- [ ] Toast notifications for upload success, delete, and API errors
- [ ] Confirm dialog before deleting a dataset
- [ ] Dark mode via Tailwind's `class` strategy — pass the matching theme into the chart options too, or charts will stay light-on-dark
- [ ] Responsive down to 375 px; charts get a fixed height and their own horizontal scroll on mobile
- [ ] Accessibility: keyboard-reachable upload zone, labelled form controls, visible focus rings, `aria-live` on the import status region, and a data table alternative for each chart (charts alone are not accessible)
- [ ] Global error boundary via `app/error.vue`
- [ ] `<Head>` titles per route

---

## 12. Phase 9 — Performance

- [ ] Charts render only when scrolled into view (`useIntersectionObserver`) — twelve simultaneous `/aggregate` calls on mount will stall the page
- [ ] Cache aggregate responses in-memory keyed by their full parameter set; identical requests within a session shouldn't refetch
- [ ] Abort in-flight requests on route change or rapid filter changes (`AbortController`)
- [ ] Debounce all filter and builder inputs
- [ ] Confirm ApexCharts is client-bundle-only and absent from the server bundle
- [ ] Budget: dashboard interactive within 2 s on a warm API

---

## 13. Phase 10 — Tests

- [ ] Component (Vitest + Testing Library): `BaseChart` renders each state; `ChartBuilder` disables the measure select when `agg=count`; `FilterBar` emits the right filter shape
- [ ] Unit: number/date formatters, including `null` and zero
- [ ] Unit: the URL ↔ filter-state round trip
- [ ] E2E (Playwright): upload a fixture CSV → wait for `ready` → dashboard renders KPIs and charts → add a chart via the builder → apply a filter → the table updates
- [ ] Manual check with a real 5,000 × 40 file — the dashboard must stay responsive while scrolling and filtering

---

## 14. Component inventory

| Component | Responsibility |
| --- | --- |
| `app/layouts/default.vue` | ✅ Navbar, footer, mobile menu — **Report View link currently commented out**, page still routable at `/report` |
| `app/pages/index.vue` | ✅ Landing page — browse-CSV input, validation, submit |
| `app/pages/report.vue` | Report View — currently an empty-state placeholder |
| `FileUploader.vue` | Drag-drop, validation, progress — **currently inline in `index.vue`**, extract when it grows |
| `ImportStatus.vue` | Poll `/status`, show progress or failure |
| `StatCard.vue` | One KPI tile |
| `BaseChart.client.vue` | Shared chart wrapper, theme, states |
| `BarChart` / `LineChart` / `PieChart` / … | Thin typed wrappers |
| `ChartBuilder.vue` | Dimension × measure × agg picker |
| `ChartCard.vue` | Chart plus its title and per-chart actions |
| `FilterBar.vue` | Active filter chips and editors |
| `DataTable.vue` | Server-paged raw rows |
| `ColumnPicker.vue` | Toggle visible table columns |
| `DatasetHeader.vue` | Filename, counts, delete, re-upload |
| `EmptyState.vue` / `ErrorState.vue` | Reused across every async surface |

---

## 15. Definition of done

- [ ] A 5,000 × 40 CSV uploads with visible progress and lands on a populated dashboard.
- [ ] Not one column name is hardcoded — swapping in a different CSV produces a working dashboard.
- [ ] The user can build a new chart from any dimension × measure × aggregation combination.
- [ ] Filters apply to every chart and the table at once, and survive a page refresh via the URL.
- [ ] The table pages through all 5,000 rows without freezing, with column visibility under user control.
- [ ] Nothing renders ApexCharts during SSR.
- [ ] Every async surface has a real loading, empty and error state.

---

## 16. Contract notes from the backend

- [ ] Upload returns **202**, not the parsed data — poll `/status` until `ready` or `failed`.
- [ ] `uuid` is the only dataset identifier; the numeric id is never exposed.
- [ ] `columns[]` with its `role` field is the single source of truth for what can go on which axis.
- [ ] `/aggregate` already returns `{ labels, series }` in ApexCharts shape — do not reshape it.
- [ ] Querying a dataset that isn't `ready` returns **409**; unknown uuid returns **404**.
- [ ] All timestamps are ISO 8601 UTC — convert for display.

See [backend/TASK.md](../backend/TASK.md) for the producing side.
