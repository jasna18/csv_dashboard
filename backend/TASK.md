# Backend TASK — CSV Dashboard API

**Stack:** Laravel 12.69 (PHP 8.2.12) · **MariaDB 10.4.32** · database queue · XAMPP (local)
**Input:** one uploaded CSV — **12 known work-order columns**, ~5,000 rows (see §2a)
**Output:** JSON API that the Nuxt frontend uses to render KPI cards, charts and a data table

**Progress:** 87 / 194 complete. `- [ ]` = not started, `- [x]` = done. Tick each box as the task lands, and bump the count on this line.

---

## 1. Goals

1. Accept a CSV upload, store the raw file, and return an id immediately.
2. Parse it in the background: infer each column's type, then bulk-insert rows.
3. Expose the inferred schema so the frontend can build charts without hardcoding column names.
4. Serve fast aggregations (group-by + sum/avg/count/min/max) and paginated raw rows.
5. Never load the whole CSV into memory — stream it.

**Non-goals for v1:** multi-tenant auth, joins across datasets, scheduled refresh, CSV editing.

---

## 2. Core design decision — storing 40 unknown columns

The CSV's columns are not known at build time, so a fixed migration is impossible. Three options:

| Option | Verdict |
| --- | --- |
| EAV (one row per cell) | Rejected — 200k rows per upload, every query becomes a self-join. |
| A physical table created per dataset | Rejected for v1 — DDL at runtime, migration drift, messy cleanup. |
| **One `dataset_rows` table with a JSON `data` column** | **Chosen.** One row per CSV row, columns live inside the JSON. |

**Why JSON is safe here:** a single dataset is only 5,000 rows. Even a full scan with
`JSON_UNQUOTE(JSON_EXTRACT(data, '$.region'))` over 5,000 rows filtered by `dataset_id`
takes a few milliseconds. The `dataset_id` index does the real work.

**When to revisit:** if one dataset passes ~500k rows, or aggregates pass ~300 ms, add MariaDB
*stored generated columns* for the 3–5 most-filtered fields and index those, or move to a
per-dataset physical table. Leave this as a code comment; do not build it now.

---

## 2a. ⚠️ The schema turned out to be known — §2 is superseded

Everything above solves a problem this project no longer has. §2 assumed ~40 **unknown** columns
that had to be inferred at upload time. The actual CSV has **12 fixed work-order columns**, and
the table for them, `add_csv`, is already built (§4a). That collapses a large part of this spec:

| Planned for unknown columns | Status now |
| --- | --- |
| `dataset_columns` metadata table | **Not needed** — the columns are declared in a migration |
| JSON `data` column in `dataset_rows` | **Not needed** — real typed columns, indexable and faster |
| Type inference pass (§6 Pass 1) | **Not needed** — types are fixed |
| `role` (dimension/measure/date) derivation | **Not needed** — assignable by hand, see §4a |
| `/aggregate` building JSON paths | **Simplified** — plain `GROUP BY status`, no `->>`, no injection boundary |

**What this buys:** the aggregation endpoints become ordinary SQL against indexed columns.
No `CAST(data->>'$.x')`, no key whitelisting, no MariaDB LONGTEXT overhead. Faster and far
less code.

**What it costs:** the app now only accepts *this* CSV layout. A file with different headers
must be rejected with a clear error rather than imported. Header validation replaces inference.

**Open question — this needs your call.** Two readings are possible and they lead to different builds:

1. **Single-purpose tool** (what `add_csv` implies) — one fixed work-order schema. Sections 2, 4,
   6-Pass-1 and the dynamic parts of 7 should be deleted, not just skipped. Simplest, and matches
   what exists today.
2. **Generic CSV dashboard** (what §1 and the frontend's "zero hardcoded column names" goal say) —
   `add_csv` is one specific dataset and the dynamic path still gets built alongside it.

Until this is settled, §4a is the live schema and §4 is on hold.

---

## 3. Phase 0 — Project setup

> **This is MariaDB 10.4, not MySQL 8.** The design still holds, but three differences matter:
>
> 1. **`JSON` is an alias for `LONGTEXT`** plus a validation CHECK constraint — not a native binary
>    type. `$table->json('data')` therefore produces `LONGTEXT`. Rows are larger and there is no
>    partial in-place update, which is irrelevant at 5,000 rows but would matter at millions.
> 2. **`->>` extraction still works** (MariaDB 10.2+), so every `/aggregate` query in §7 is valid
>    as written. Keep the explicit `CAST(... AS DECIMAL)` — text-vs-number coercion is the same trap.
> 3. **`performance_schema` is disabled in XAMPP**, so `php artisan db:show` throws
>    `Table 'performance_schema.session_status' doesn't exist`. That is a reporting-command
>    failure only, not a broken connection — verify with a real query instead.

- [x] `composer create-project laravel/laravel` → **Laravel 12.69.1**, moved into `backend/`
- [x] Configure `.env`: `DB_CONNECTION=mysql`, `DB_DATABASE=db_csv`, `DB_USERNAME=root`, empty password (XAMPP default)
- [x] Database **`db_csv`** already existed and is empty — nothing to create
- [x] Connection verified — `db=db_csv`, `driver=mysql`, `server=10.4.32-MariaDB`, 0 tables
- [x] `composer require league/csv`
- [ ] Set `QUEUE_CONNECTION=database` (already the Laravel 12 default), then `php artisan queue:table && php artisan migrate`
      — **not yet run.** `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` all point at `database`,
      so the app needs these tables before it will serve a request. Held back deliberately: running it
      writes `users`, `cache`, `jobs` and `sessions` into `db_csv`, and the schema for this project is
      still being decided.
- [ ] Add Sanctum only if v1 needs auth; otherwise skip it
- [x] `config/cors.php` — allow `http://localhost:3000` (Nuxt dev server), `supports_credentials` false
- [ ] Raise XAMPP `php.ini` limits: `upload_max_filesize=32M`, `post_max_size=32M`, `memory_limit=512M`, `max_execution_time=120`
- [ ] Store raw CSVs on the `local` disk under `storage/app/datasets/`

---

## 3b. Reliability API — `GET /api/reliability/dashboard` ✅ BUILT

`app/Services/ReliabilityReport.php` + `app/Http/Controllers/Api/ReliabilityController.php`.
One request returns every widget's data, so no two cards on the dashboard can be showing
different slices. Throttled at 120/min (still unauthenticated — see §3a).

**Operating model:** 24/7 cargo operation —

```
runtime_hours = fleet_size × days × 24
```

No shift calendar or planned-idle window to subtract, which keeps months of different
lengths comparable. Verified against every filter: 90 × 242 × 24 = 522,720 unfiltered,
90 × 31 × 24 = 66,960 for a single month, 5 × 242 × 24 = 29,040 for fleet STC.

- [x] **The denominator is the roster, not the rows.** `fleet_size` counts equipment matching
      only the *equipment-scoping* filters — fleet, location, unit — because those are the ones
      that genuinely change what is on the roster. Fault cause, work type and month select a
      subset of *work orders* while the same equipment keeps running. Counting distinct assets
      in the filtered rows instead measured March against the 28 units that raised a work order
      rather than the 90 that were running, reporting **76.1% availability where it is 92.6%**.
      A unit with no work orders never failed — the best case, not an absent one. Each
      `asset_number` maps to exactly one `asset_type` and one `location` (checked: zero assets
      span either), so the roster is unambiguous.
- [x] **`days` is the selected window, not the span of surviving rows.** Filtering to work type
      PM previously shrank the period to the 213 days between the first and last PM job, as
      though the fleet had stopped running either side of them. It is now the requested window,
      falling back to the dataset's full range.
- [x] Monthly runtime in `trend` is clipped to the window — a window ending on the 30th does not
      buy the fleet a 31st day.
- [x] `assets_reporting` ships beside `assets` so the dashboard can say "26 of 90 units affected"
      rather than silently redefining the fleet.

- [x] Filters, all bound as parameters: `from`, `to`, `month` (expands to that month and
      overrides from/to), `asset_type`, `asset_number`, `location`, `work_type`,
      `fault_cause`, `basis`
- [x] KPIs: availability, serviceability, MTBF, MTTR (+ median), downtime, repair hours,
      response time, work orders, assets, runtime
- [x] `trend` — the same rates recomputed per calendar month
- [x] `by_cause`, `by_work_type`, `by_asset_type`, `by_location`, `top_assets`,
      `top_fault_types` (top-N with the tail folded into a labelled "Other", never dropped)
- [x] `repair_buckets` — repair-duration histogram

**Three decisions the numbers depend on:**

- [x] **Downtime is the union of intervals, not the sum of durations.** Assets carry several
      open work orders at once — one has 1,873 overlapping pairs — so summing durations
      counts the same wall-clock hour repeatedly. Summing gave asset type STC 33,019 repair
      hours against 28,968 asset-hours of exposure, i.e. availability 0% only because it was
      clamped. Merging the intervals per asset gives 26,783 down hours and 7.8%.
      Both figures ship: `repair_hours` is workload, `down_hours` is unavailability.
- [x] **Availability and serviceability are different metrics, not two names for one.**
      Availability is time-based (share of runtime not under an open order, to the minute);
      serviceability is unit-based (share of the fleet fit for service on an average day, so
      a ten-minute job costs a whole unit-day). Fleet-wide: 91.4% vs 87.5%.
- [ ] **The 95% availability target is a placeholder.** Nothing in the source states an SLA;
      the gauge bands and the trend line all read from `AVAILABILITY_TARGET` in the frontend.
      Replace it with the real one.

**Response time — taken from the columns as they stand:**

```
response_minutes = act_start − report_date
```

- [x] Every row, no exclusions and no sign correction. Both the mean and the median are
      reported, and the sign split (`rows_started_before_report` / `rows_started_after_report`)
      travels with them.
- [x] **The figure is normally negative**: on 5,433 of 5,563 rows (97.7%) `act_start` precedes
      `report_date`, because the order is entered after work has begun. Fleet-wide the median
      is −20.3 hrs and the mean −17.3 hrs. Read it as how long work had been under way by the
      time the order was logged. The dashboard says exactly that beside the number, and
      `fmt.duration` picks the unit from the magnitude before restoring the sign, so it reads
      "−20.3 hrs" rather than "−1220 min".
- [x] **The sign tracks work type, which makes it genuinely useful as a filter.** Planned work
      is logged before it starts, reactive work after:

      | Work type | Rows | Started before report | Mean |
      | --- | --- | --- | --- |
      | PM (planned) | 64 | 1 | **+248.6 hrs** |
      | IJ | 62 | 27 | +11.1 hrs |
      | CM (corrective) | 98 | 71 | +11.0 hrs |
      | BD (breakdown) | 22 | 20 | −5.2 hrs |
      | EV (reactive) | 5,315 | 5,312 | −21.4 hrs |

      So filtering to PM gives a positive, meaningful lead time (median 9.0 days); the
      fleet-wide figure is dominated by EV, where the timestamp order is reversed.
- [ ] A true "fault raised" timestamp would separate response from logging lag. Worth asking
      whoever owns the export whether Maximo holds one that is not in this extract.
---

## 3a. Security review of the import path — 2026-09-10

Reviewed the whole upload → parse → insert path. Findings below, each verified
against the running app rather than inferred. Ranked by severity.

**Fixed**

- [x] **The entire application tree was being served over HTTP.** The project sits under
      XAMPP's DocumentRoot, and only `backend/public/.htaccess` existed, so
      `GET /csv-dashboard/backend/.env` returned **200** with `APP_KEY` and the database
      credentials in plaintext. So did `storage/logs/laravel.log`, `composer.json`, and
      `storage/app/private/datasets/` — the last with a directory index, so every uploaded
      spreadsheet could be listed and downloaded without knowing its name.
      **Fix:** deny-all `backend/.htaccess`, with `backend/public/.htaccess` taking the grant
      back for the one directory that is a document root. All of the above now return 403 and
      `public/` still serves normally.
      **Still owed:** this is a guard, not the cure — point a vhost at `backend/public`, or move
      the project out of `htdocs`. Treat the `APP_KEY` and any credentials in `.env` as
      disclosed and rotate them.
- [x] **`truncate=1` destroyed data that no rollback could restore.** The clear ran *before*
      `beginTransaction()` and used `TRUNCATE`, which is DDL — MariaDB commits it implicitly.
      Any later failure (a bad row, the row cap, a dropped connection) left the table empty with
      nothing put back, from a request that never had to supply a valid file. Reproduced: 5,563
      rows → 0. **Fix:** the clear moved inside the transaction and switched to `DELETE`, so it
      rolls back with everything else. Re-tested: the same failure now leaves all 5,563 rows.
- [x] **500 responses quoted internal detail.** The handler returned `$e->getMessage()` verbatim
      — absolute filesystem paths, driver internals, and from a `QueryException` the entire SQL
      statement with table and column names, on an unauthenticated endpoint. **Fix:** the message
      goes to the log with a UUID reference; the caller gets the reference only.
- [x] **Uploads were stored under a content-sniffed extension.** `store()` derives the extension
      from the file's *contents*, not the name the validator checked, so an HTML or SVG payload
      uploaded as `report.csv` was written as `.html` / `.svg` — active content in a directory
      that was, per the first finding, web-readable. **Fix:** `storeAs()` with an extension taken
      from `ImportCsvRequest::ALLOWED_EXTENSIONS`. Verified: an HTML payload named `report.csv`
      now lands as `.csv`. (PHP content maps to no extension in Symfony's MIME table, so there
      was no RCE path here — checked, not assumed.)
- [x] **A spreadsheet extension alone routed bytes into the ZIP/OLE2 parsers.** `detectFileType()`
      fell back to the client-supplied extension, so anything named `.xls` was handed to
      `SimpleXLS::parseFile()` regardless of content. **Fix:** the magic-byte signature decides;
      a spreadsheet extension without the matching signature is rejected with a clear message.
- [x] **Temp files survived a failed import.** All three temp paths were registered for cleanup
      only *after* the work that could throw, so `import()`'s `finally` never learned about them.
      Reproduced with a corrupt `.xlsx`. Each stray holds the full contents of somebody's upload.
      **Fix:** `createTempFile()` registers at creation. Re-tested: nothing left behind.
      *(Aside: on Windows `tempnam()` honours only the first 3 characters of the prefix, so these
      are all `xls*.tmp` / `csv*.tmp` — the descriptive prefixes never reach disk.)*
- [x] **No bound on work or on echoed content.** Added a 200,000-row ceiling (the real files are
      ~5,500) covering both the CSV path and the spreadsheet converters, so a compressed upload
      cannot buy millions of rows, an unbounded temp CSV, and a transaction long enough to hold
      the table. The header-mismatch error also echoed file content unbounded: a 1 MB single-line
      header produced a 1,049,119-byte response. Now bounded — same file, 609 bytes, message still
      diagnostic. Both endpoints are rate limited (`throttle:10,1` import, `throttle:60,1`
      summary); verified 429 with `Retry-After` on the 11th import.

**Checked, not vulnerable** — recorded so nobody re-audits them:

- XXE through the `.xlsx` XML. OpenSpout opens with `LIBXML_NONET` and never enables
  `SUBST_ENTITIES`. Tested with a crafted workbook whose header cell referenced an external
  file entity, aimed at the one sink that echoes headers back (the 422): no expansion, canary
  absent.
- SQL injection. Column names come from constants, never from the file; values go through the
  query builder's bindings. `TOTAL_NUMERIC` is a constant expression.
- Reflected XSS from the 422 message or the echoed filename. Responses are JSON and the Nuxt app
  renders through `{{ }}`; no `v-html` anywhere in `frontend/app`.
- CORS. Restricted to `http://localhost:3000` with `supports_credentials` false.

**Multi-select filters ✅ BUILT**

Every dimension — month, fleet, equipment, location, work type, fault cause — is a checkbox
dropdown taking a set rather than one value. Sent as `asset_type[]=STC&asset_type[]=ETV`;
a scalar still works, so `?asset_type=STC` keeps functioning.

- [x] An empty set means **no constraint**, not "nothing". The "All" row is a shortcut that
      clears the selection rather than a value of its own, so the API has one meaning for
      unfiltered instead of two.
- [x] **Months are a set, not a range.** Picking January and March must not drag February in
      with them, which is exactly what a min/max range would do. The observation window is now a
      *list of ranges*, and days are summed across them: Jan + Mar is 62 days and 133,920
      runtime hours (90 × 62 × 24), not the 90 days Jan-to-March would imply. Verified against
      the API.
- [x] **Downtime is clipped to the window.** Previously a repair that ran past the selected
      months was charged in full against the months' runtime — a 51-day repair against 31 days
      of March. `overlap()` now counts only the part inside the window, for the total, the
      per-month trend and the serviceability day-marking alike.
- [x] The equipment/work-order split still holds: picking two fleets changes the roster
      (STC + ETV = 7 units, 40,656 runtime hours), picking two fault causes does not
      (roster stays 90). Verified in the browser.
- [x] The header names the selected months rather than showing a range across them —
      "Feb 2026, Apr 2026 · 58 days", because "1 Feb – 30 Apr · 58 days" reads as a contradiction.
- [x] Export mirrors the same serialised query, so a filtered export matches the screen.
- [x] **A dropped-click bug, found and fixed.** Deriving each toggle from `props.modelValue`
      lost selections: the prop only updates after the parent re-renders, so two checkboxes
      ticked in the same tick both read the old value and the second emit discarded the first —
      ticking January then March sent only March. The component now holds the selection locally
      and mirrors it out.
**Export — `GET /api/reliability/export` ✅ BUILT**

- [x] The current slice as a workbook: `format=xlsx` gives one sheet per widget,
      `format=csv` the same sections as labelled blocks in one file. It takes the same filters
      as the dashboard read, so an export always matches what was on screen — verified with
      `fault_cause=Technical`, where the export's Availability row reads 99.38%, the same figure
      the dashboard reports.
- [x] Streamed through a `StreamedResponse`. The payload is small today; streaming costs nothing
      extra and stops a larger fleet turning this into a memory limit.
- [x] **This is the formula-injection sink §3a predicted.** Values that came out of an uploaded
      spreadsheet are going back into one, and a cell beginning `=`, `+`, `-`, `@`, tab or CR is
      executed on open — `=cmd|'/c calc'!A1` is working command execution through Excel's DDE.
      Not theoretical here: the source file already carries `=L32-K32` in 248 cells.
      `ReliabilityExporter::escape()` prefixes such cells with an apostrophe, on the way out
      only, so the stored value stays faithful. Leading whitespace is skipped before the check,
      because Excel trims before evaluating.
      Verified by inserting `=cmd|'/c calc'!A1`, `@SUM(1+1)*cmd` and
      `  =HYPERLINK("http://evil","click")` as fault types and exporting: all three came back
      apostrophe-prefixed in the CSV, and OpenSpout reads them out of the XLSX as `StringCell`
      rather than `FormulaCell` — inert. Test rows removed afterwards.
- [x] Numbers and nulls pass through unescaped, or every figure would arrive as text and the
      workbook would be useless for charting.
- [x] Throttled at 30/min, below the dashboard read: building a file is the more expensive of
      the two and nothing clicks Export in a loop.
- [x] UI: an "Export CSV/Excel" button in the dashboard header with a two-item menu. Plain
      anchors rather than fetch-and-blob, so the browser handles the save, the file never passes
      through JS, and a large export streams. Verified the href carries the active filters.
**Clear-database action — `DELETE /api/csv/rows` ✅ BUILT**

- [x] Empties `add_csv` with TRUNCATE, which is the opposite of the choice inside an import and
      for the opposite reason: there the clear has to roll back with the rows that follow it, so
      it is a DELETE inside the transaction; here nothing follows it, so TRUNCATE is free to
      reset AUTO_INCREMENT and skip the undo log. Verified: 5,563 rows removed,
      `AUTO_INCREMENT` back to 1.
- [x] Guarded against being triggered by accident, since it is irreversible and the API has no
      auth:
      - `DELETE`, not `GET` or a form `POST`. A form POST is CORS-safelisted, so any page could
        fire one and the browser would send it; DELETE with a JSON body forces a preflight that
        the CORS config refuses for every origin but the dashboard's.
      - an exact `confirm: "CLEAR"` value is required. Verified: missing → 422, wrong value →
        422, `GET`/`POST` → 405, and the table was untouched by all four.
      - `throttle:20,1`. Still the tightest limit here, but not as tight as it looks: a
        browser DELETE is preceded by a CORS preflight, and the OPTIONS request passes through
        the same throttle, so every click from the UI costs two. At 5/min that left a user two
        attempts before a genuine confirm started failing with 429 — which it duly did in
        testing, and the dialog surfaced it correctly rather than failing silently.
      - logged at warning level with the row count and IP, since a truncate leaves no trace in
        the data itself.
- [x] UI sits below the import form on `/`, styled as a hazard rather than an action, and names
      the number of rows at stake. The confirm is a second, differently-worded button rather
      than `confirm()` — it can state the row count, and it is not the dialog muscle memory
      dismisses. Verified in a browser: Cancel leaves all 5,563 rows, Confirm empties the table
      and the panel switches to "The table is already empty" with the button disabled.
- [ ] None of this is a substitute for authentication (§3a). It raises the bar for an accident,
      not for someone who means it.
**Follow-up — 2026-09-20, protecting `.env`**

- [x] **`.env` is clean on the git side.** Never committed, absent from history, ignored by
      `backend/.gitignore:3`, and invisible to `git status -uall`. Only `.env.example` is
      tracked. The same now holds for `frontend/.env`.
- [x] **`APP_KEY` rotated.** The old key was served over HTTP before the `.htaccess` fix, so it
      was treated as disclosed. `php artisan key:generate` replaced it and the `cache`,
      `cache_locks` and `sessions` rows encrypted under the old key were cleared. Nothing else
      depended on it — `users` is empty and `add_csv` holds no encrypted columns.
- [x] **The `.git` directory was web-readable, and that was self-inflicted.** Initialising the
      repo at `csv-dashboard/` put `.git/` one level *above* `backend/.htaccess`, so
      `/csv-dashboard/.git/config`, `HEAD`, `index`, `refs/heads/main` and `logs/HEAD` all
      returned 200 — enough for `git-dumper` to reconstruct the entire repository and its
      history. `frontend/` source was served too. Fixed with a deny-all `csv-dashboard/.htaccess`
      that also blocks dot-paths explicitly (`FilesMatch "^\."` plus a rewrite rule, since
      `FilesMatch` only sees the filename and would miss `.git/refs/heads/main`).
      `backend/public/` still re-grants, and the API through Apache still returns 200.
- [ ] **Rotate the database credentials too.** `DB_USERNAME=root` with an empty password was in
      the same disclosed file. It is the XAMPP default and only reachable from localhost, but
      setting a MySQL root password would affect every other project under `htdocs`, so it is
      left as your call rather than changed here.

**Environment note:** port 8000 is held by the uvicorn service from
`vue-portfolio-new/portfolio-rag`, not Laravel — the `artisan serve` process had exited and
uvicorn took the port, which is why the dashboard briefly 500'd. The API now runs on 8001
(`php artisan serve --port=8001`) and `frontend/.env` points at it. Nothing to do with the key
rotation; the app was healthy through Apache throughout.

**Open — needs a decision**

- [ ] **No authentication.** Anyone who can reach the API can replace the dataset
      (`truncate=1`) or read `/api/csv/summary`. Rate limiting narrows this; it does not close it.
      Note the CSRF angle: `api/*` is exempt from CSRF and `multipart/form-data` is a
      CORS-safelisted content type, so any web page a logged-in user visits can silently POST an
      import — CORS blocks *reading* the response, not the side effect. §3 lists auth as a v1
      non-goal, so this is deliberate; it stops being acceptable the moment this is reachable by
      anything but localhost.
- [ ] **`APP_DEBUG=true`.** Correct for local work, but it renders full stack traces with source
      excerpts on any unhandled error. Must be `false` wherever this is not a dev box.
- [ ] **Uploaded files accumulate forever.** Nothing prunes `storage/app/private/datasets/`, and
      the endpoint is unauthenticated — so disk growth is attacker-controlled. Decide a retention
      rule (keep the last N, or delete after a successful import).
- [ ] **Formula injection has no sink yet — keep it that way.** Cells are stored verbatim, which
      is right for the database. The moment a CSV/Excel *export* is added, a value beginning
      `=`, `+`, `-`, `@`, tab or CR must be neutralised there, or the dashboard becomes a delivery
      mechanism for whatever the source file carried. Note the irony: this importer already reads
      `=L32-K32` cells out of the real file.

---

## 4a. Phase 1 — `add_csv` table ✅ BUILT

Migration: `database/migrations/2026_09_07_120237_create_add_csv_table.php`
Live in `db_csv`. Column order matches the CSV header order.

- [x] `id` — bigint unsigned, auto-increment PK
- [x] `work_order` — `bigint unsigned`, nullable, indexed *(spec: number)*
- [x] `fault_description` — `text`, nullable *(spec: text)*
- [x] `asset_number` — `varchar(100)`, nullable *(spec: number + text)*
- [x] `asset_type` — `varchar(255)`, nullable *(spec: text)*
- [x] `location` — `varchar(100)`, nullable, indexed *(spec: text + number)*
- [x] `workshop` — `varchar(100)`, nullable, indexed *(spec: number + text)*
- [x] `status` — `varchar(50)`, nullable, indexed *(spec: text)*
- [x] `report_date` — `datetime`, nullable, indexed *(spec: date + time)*
- [x] `fault_type` — `varchar(100)`, nullable *(spec: number + text)*
- [x] `work_type` — `text`, nullable *(spec: text)*
- [x] `act_start` — `datetime`, nullable *(spec: date + time)*
- [x] `act_finish` — `datetime`, nullable *(spec: date + time)*
- [x] `total_downtime` — `varchar(50)`, nullable *(spec: number + text)*
- [x] `fault_cause` — `text`, nullable *(spec: text)*
- [x] `created_at` / `updated_at` timestamps
- [x] Indexes on `work_order`, `status`, `location`, `workshop`, `report_date` — the dashboard's
      grouping and filtering fields
- [x] All data columns nullable — a 5,000-row CSV will have blank cells, and `NOT NULL` would
      turn each one into a failed row

**Chart roles for this table** (replaces the inferred `role` column):

| Role | Columns |
| --- | --- |
| Dimension | `status`, `location`, `workshop`, `asset_number`, `asset_type`, `fault_type`, `work_type`, `fault_cause` |
| Date | `report_date`, `act_start`, `act_finish` |
| Measure | `total_downtime` — **but see the caveat below** |
| Ignored | `fault_description` (free text), `work_order` (identifier, not a measure) |

**Two traps carried by this schema — both settled against the real file:**

- [x] `total_downtime` is `varchar`, so `SUM(total_downtime)` returns **0 silently** rather than erroring —
      MariaDB coerces non-numeric text to zero. Every aggregate over it must use
      `AddCsv::TOTAL_NUMERIC`, which skips non-numeric rows.
      **Decided:** the column stores a **number of minutes**. The source keeps it as an Excel
      duration (a fraction of a day, so 15 minutes is `0.0104`), which the importer converts on the
      way in; a row whose value is genuinely text is kept verbatim, reported in the import summary,
      and contributes nothing to the total. Verified: the converted minutes match
      `TIMESTAMPDIFF(MINUTE, act_start, act_finish)` on every row that carries the spreadsheet's
      `=act_finish-act_start` formula.
- [x] `work_order` is `bigint` per the field spec. A work order id containing a letter (`WO4471`) or
      a meaningful leading zero (`0004471`) will fail to import or lose the zero.
      **Confirmed against the real file:** all 5,563 values are plain 8-digit integers, no letters and
      no leading zeros, so `bigint` stays. Revisit if a future export introduces either — the importer
      already logs such a value as a row error and stores NULL rather than failing the import.

**Excel quirks the importer has to absorb** (all three were silently corrupting `add_csv`):

- [x] Header cells carry stray padding (`"work_order "`, `" total_downtime"`). The header *check*
      normalises, so the file passed validation while the record lookups missed and 9 of 14 columns
      imported as NULL for every row. Records are now keyed by the normalised header.
- [x] `total_downtime` is a formula (`=L32-K32`) in 248 rows. OpenSpout hands back the formula text,
      not the result — the cached computed value is what gets read now.
- [x] Excel has no duration type, so a downtime reads back as a datetime near 1900
      (`1899-12-30 00:15` for 15 minutes, `1900-01-28 00:15` for 29 days 15 minutes). Duration columns
      are converted to elapsed time measured from Excel's epoch, so multi-day downtime survives.

**Import code — ✅ BUILT and tested end to end:**

- [x] `composer require league/csv` → 9.28
- [x] `app/Models/AddCsv.php` — datetime casts, plus a `TOTAL_NUMERIC` constant holding the
      mandatory cast expression so nobody writes a bare `SUM(total)`
- [x] `app/Services/CsvImporter.php` — the whole parse/cast/insert pipeline
- [x] `app/Http/Requests/ImportCsvRequest.php` — 32 MB cap, mimetype check, `truncate` flag
- [x] `app/Http/Controllers/Api/CsvImportController.php`
- [x] `routes/api.php` + registered in `bootstrap/app.php` (Laravel 12 ships no api.php;
      added by hand rather than `install:api`, which would pull in Sanctum)
- [x] `config/cors.php` published, `allowed_origins` restricted to `http://localhost:3000`
- [x] Header validation — a CSV whose headers are not exactly these 12 gets a 422 listing
      what is missing and what is unexpected
- [x] Delimiter detection (`,` `;` tab `|`) and UTF-8 BOM stripping
- [x] **Encoding conversion — the source file is Windows-1252, not UTF-8.** Three bugs were
      found here in sequence, all now fixed and regression-tested:
  - [x] *No conversion at all.* The first build never transcoded. A CP1252 byte reaching a 422
        message made `json_encode()` throw, so the error response itself 500'd and the UI showed
        neither success nor failure.
  - [x] *Sampling missed it.* The first fix checked only the first 64 KB. A real export is ASCII
        for hundreds of rows before the first en-dash, so the file was declared UTF-8, conversion
        was skipped, and `\x96` hit the INSERT — MariaDB strict mode aborted the batch and rolled
        back every row. **Now every line is scanned**; a multi-byte sequence never spans a newline,
        so line-by-line reading is safe.
  - [x] *Blanket conversion would corrupt mixed files.* Only lines that fail `mb_check_encoding`
        are transcoded; already-valid UTF-8 lines are passed through untouched. `converted_lines`
        in the response reports how many were changed.
- [x] `sanitize()` applied to every value, every error message and the client filename — nothing
      derived from the upload can reach MariaDB or `json_encode()` as invalid UTF-8
- [x] Regression test: 177 KB file, pure ASCII through row 899, CP1252 en-dash at row 900 (past
      the old 64 KB window) → 1200/1200 imported, 1 line converted, stored as a correct `–`
- [x] Date-format detection per column from a 200-row sample, **including d/m/Y vs m/d/Y
      disambiguation** — a value above 12 in either position settles it; when the whole sample
      is ambiguous the column is named in `ambiguous_dates` rather than silently guessed
- [x] Rejects `createFromFormat`'s lenient rollover (`31/02/2024` → March) via `getLastErrors()`
- [x] Per-cell failures degrade to NULL and a logged error; the row still imports
- [x] Overlong varchar values truncated to the column width instead of throwing under strict mode
- [x] Chunked insert, 500 rows per statement, inside a transaction
- [x] Error list capped at 200 entries with a full `error_count`
- [x] Verified: 6-row fixture with a quoted comma, non-numeric `wonum`, unparseable date, empty
      dates and mixed number/text columns → 6 inserted, 2 cell errors, correct line numbers

**Endpoints live:**

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/csv/import` | multipart `file`, optional `truncate` — returns a full import summary |
| `GET` | `/api/csv/summary` | row count, counts by status, date range, `total` sum (cast) |

**Still open on this table:**

- [ ] Confirm the real CSV's date format matches what detection finds — the fixture used
      `Y-m-d H:i:s`; a `d/m/Y` file that never exceeds day 12 will be flagged ambiguous
- [ ] Decide what a non-numeric `total` (e.g. `N/A`) should mean in a chart — it currently
      contributes 0 to sums
- [ ] Import runs **synchronously**. Fine at 5,000 rows (~50 ms for 6, well under a second for
      5,000). Move into a queued job only if the row count grows by an order of magnitude.

---

## 4. Phase 1 — Database schema (⏸ ON HOLD — dynamic-schema path, see §2a)

Four migrations. Keep them small and reversible.

### `datasets`

- [ ] `id` — bigint PK
- [ ] `uuid` — char(36), unique — **this is the only id the API exposes**
- [ ] `original_filename` — string
- [ ] `stored_path` — string (path on the `local` disk)
- [ ] `size_bytes` — unsigned bigint
- [ ] `delimiter` — char(1), default `,`
- [ ] `row_count` — unsigned int, nullable (filled after import)
- [ ] `column_count` — unsigned smallint, nullable
- [ ] `status` — enum: `pending`, `processing`, `ready`, `failed`
- [ ] `error_message` — text, nullable
- [ ] `imported_at` — timestamp, nullable
- [ ] timestamps

### `dataset_columns`

One row per CSV column — this is the schema the frontend reads.

- [ ] `id`, `dataset_id` (FK, cascade delete)
- [ ] `name` — the raw CSV header text
- [ ] `key` — slugified, safe JSON key (`Total Sales (AED)` → `total_sales_aed`)
- [ ] `position` — smallint, original column order
- [ ] `type` — enum: `integer`, `decimal`, `date`, `datetime`, `boolean`, `string`
- [ ] `role` — enum: `dimension`, `measure`, `date`, `ignored` — the frontend populates its chart pickers from this
- [ ] `date_format` — string nullable, the format that won during inference (so ingest doesn't re-guess per row)
- [ ] `distinct_count`, `null_count` — unsigned int, nullable
- [ ] `min_value`, `max_value` — string nullable (numeric/date range, for filter sliders)
- [ ] `sample_values` — json nullable (first ~10 distinct values, for filter dropdowns)
- [ ] unique index on `(dataset_id, key)`

### `dataset_rows`

- [ ] `id`, `dataset_id` (FK, cascade delete)
- [ ] `row_number` — unsigned int, 1-based line number in the source file (for error reporting)
- [ ] `data` — **json**, keyed by `dataset_columns.key`
- [ ] index on `dataset_id`; composite index on `(dataset_id, row_number)`
- [ ] **No** `created_at` / `updated_at` — 5,000 rows × 2 timestamps is dead weight

### `dataset_import_errors`

- [ ] `id`, `dataset_id`, `row_number`, `column_key` nullable, `message`, `raw_value` nullable
- [ ] Cap at 200 recorded errors per import, then only increment a counter — a malformed file must not write 5,000 error rows

---

## 5. Phase 2 — Upload endpoint

`POST /api/datasets` (multipart/form-data, field `file`)

- [ ] `StoreDatasetRequest` validating `required|file|mimes:csv,txt|max:32768` (32 MB)
- [ ] Check the extension **and** sniff the first bytes — `mimes` alone is spoofable
- [ ] Store via `$request->file('file')->store('datasets')` — never build the path from the client filename
- [ ] Detect the delimiter by sampling the first 5 lines and counting `,` `;` tab `|`; persist the winner
- [ ] Insert the `datasets` row with `status = pending`
- [ ] Dispatch `ImportCsvDataset`
- [ ] Return **202 Accepted** with `{ uuid, status, original_filename }` — never block the request on parsing

**Guards:**

- [ ] Reject a file with zero data rows
- [ ] Duplicate header names → auto-suffix (`region`, `region_2`) rather than fail
- [ ] Reject > 100 columns or > 200,000 rows with a clear message
- [ ] Strip a UTF-8 BOM from the first header cell — it silently corrupts the first column name otherwise
- [ ] Detect encoding; convert non-UTF-8 with `mb_convert_encoding` (Windows exports are often CP1252)

---

## 6. Phase 3 — The import job

`app/Jobs/ImportCsvDataset.php` — `ShouldQueue`, `timeout = 300`, `tries = 1`
(a retry against a half-imported dataset would duplicate rows).

- [ ] Set `status = processing`
- [ ] Open with `League\Csv\Reader::createFromPath(...)`, `setHeaderOffset(0)`, `setDelimiter(...)`

### Pass 1 — type inference (sample, don't scan everything)

- [ ] Read the first 500 non-empty rows
- [ ] Per column, test in order and take the first match covering ≥ 95% of non-null values:
  1. `integer` — `/^-?\d+$/`
  2. `decimal` — `/^-?\d+([.,]\d+)?$/`
  3. `boolean` — `true/false/yes/no/1/0/y/n`, case-insensitive
  4. `date` / `datetime` — try `Y-m-d`, `d/m/Y`, `m/d/Y`, `d-m-Y`, `Y-m-d H:i:s`, ISO 8601, and **store the winning format** on the column
  5. otherwise `string`
- [ ] Assign `role`: `integer`/`decimal` → `measure`; `date`/`datetime` → `date`; else `dimension`
- [ ] Guard the classic false positive — a numeric ID or postcode should stay a `dimension`, not become a `measure`. Rule: if `distinct_count > 0.9 * row_count` **and** the name matches `/(^|_)(id|code|zip|postcode|phone)($|_)/i`, force `dimension`.
- [ ] Write the `dataset_columns` rows

### Pass 2 — ingest

- [ ] Stream with `Reader::getRecords()` — a generator; never `fetchAll()`
- [ ] Cast each value to its column type: trim whitespace, strip thousands separators from numerics, normalize dates to `Y-m-d H:i:s`, empty string → `null`
- [ ] On a cast failure store `null` and log a `dataset_import_errors` row — one bad cell must never abort the import
- [ ] Buffer 500 rows, then `DB::table('dataset_rows')->insert($chunk)` — 10 inserts total, not 5,000
- [ ] Wrap in a transaction; on exception roll back and set `status = failed` with `error_message`

### Pass 3 — column statistics

- [ ] One aggregate query per column to fill `distinct_count`, `null_count`, `min_value`, `max_value`, `sample_values`
- [ ] Set `row_count`, `column_count`, `imported_at`, `status = ready`

---

## 7. Phase 4 — Read API

All routes in `routes/api.php` under `/api`, dataset addressed by `{uuid}`.

### Status & schema

- [ ] `GET /api/datasets` — paginated list: uuid, filename, row_count, status, created_at
- [ ] `GET /api/datasets/{uuid}` — dataset meta plus the full `columns[]` array (name, key, type, role, distinct_count, min, max, sample_values)
- [ ] `GET /api/datasets/{uuid}/status` — `{ status, progress_percent, error_message }`. Index-only and cheap; the frontend polls it every 1.5 s during import.
- [ ] `DELETE /api/datasets/{uuid}` — cascade-deletes rows and removes the stored file

### Summary — drives the KPI cards

- [ ] `GET /api/datasets/{uuid}/summary`
  - [ ] total rows, total columns, counts of measures / dimensions / date columns
  - [ ] per measure: `sum`, `avg`, `min`, `max`, `null_count`
  - [ ] overall date range across date columns
  - [ ] **Cache it** — `Cache::remember("dataset:{$uuid}:summary", 3600, ...)`; it is identical for every viewer until the dataset changes

### Aggregation — drives every chart

- [ ] `GET /api/datasets/{uuid}/aggregate`

  | Param | Meaning |
  | --- | --- |
  | `group_by` | column key — the X-axis dimension (required) |
  | `metric` | column key — the measure (required unless `agg=count`) |
  | `agg` | `sum` \| `avg` \| `count` \| `min` \| `max` \| `count_distinct` |
  | `series_by` | optional second dimension → multi-series charts |
  | `date_trunc` | `day` \| `week` \| `month` \| `quarter` \| `year` (when `group_by` is a date column) |
  | `filters` | JSON array of `{column, operator, value}` |
  | `sort` | `label_asc` \| `value_desc` (default `value_desc`) |
  | `limit` | default 20, max 100 — remainder folded into an "Other" bucket |

  Response: `{ labels: [...], series: [{ name, data: [...] }], total, truncated: bool }` —
  shaped so the frontend hands it straight to ApexCharts with no reshaping.

- [ ] `GET /api/datasets/{uuid}/rows` — server-side table paging: `page`, `per_page` (max 100), `sort`, `direction`, `filters`, `columns` (subset to return). **Never return all 5,000 rows in one response.**

### Query-building rules

- [ ] Resolve every `group_by` / `metric` / `filters[].column` against `dataset_columns` for that dataset **before** touching the query builder. An unknown key is a 422, not a query.
- [ ] Build JSON paths as `data->>'$.key'` only from keys that survived that lookup — this is the SQL-injection boundary, since a JSON path cannot be a bound parameter.
- [ ] Cast in SQL for numeric aggregates: `SUM(CAST(data->>'$.amount' AS DECIMAL(18,4)))`. MariaDB returns JSON extracts as text, so `SUM` on the raw value silently yields garbage.
- [ ] Whitelist `agg` and `date_trunc` against a PHP array — never interpolate raw input.
- [ ] Filter operators to support: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `contains`, `between`, `is_null`, `is_not_null`

---

## 8. Phase 5 — Hardening

- [ ] API Resources (`DatasetResource`, `DatasetColumnResource`) — never return Eloquent models raw
- [ ] One error envelope: `{ message, errors?, code }` — 422 validation, 404 unknown uuid, 409 querying a dataset that isn't `ready`
- [ ] Rate-limit uploads: `throttle:10,1` on the POST route
- [ ] Cache aggregate responses keyed by uuid + a hash of the query string, 10 min TTL; flush the `dataset:{uuid}:*` prefix on delete or re-import
- [ ] Scheduled `datasets:prune` command — delete datasets older than N days plus their files, or `storage/app/datasets` fills the disk
- [ ] Log aggregate queries slower than 500 ms with their full parameter set

---

## 9. Phase 6 — Tests

- [ ] Feature: upload → job runs → status `ready` → row count matches the fixture
- [ ] Feature: aggregate totals match a hand-checked 20-row fixture
- [ ] Feature: unknown `group_by` → 422; querying a `pending` dataset → 409
- [ ] Unit: type inference over `"1,234.50"`, `"2024-03-01"`, `"01/03/2024"`, and a mixed column
- [ ] Unit: delimiter detection on comma / semicolon / tab files
- [ ] Fixtures to keep in `tests/fixtures/`: BOM header, quoted commas inside values, embedded newline in a quoted field, ragged rows (fewer cells than the header), empty file, header-only file, duplicate header names, CP1252 accents
- [ ] Performance: a real 5,000 × 40 file imports in < 20 s; `/aggregate` responds in < 200 ms

---

## 10. Definition of done

- [ ] A 5,000 × 40 CSV uploads, imports in the background, and reports `ready`.
- [ ] `GET /datasets/{uuid}` returns 40 columns with sensible inferred types and roles.
- [ ] `/aggregate` answers any dimension × measure × agg combination the frontend asks for.
- [ ] `/rows` pages through the data without ever loading it all.
- [ ] A malformed row degrades to a logged error rather than failing the import.
- [ ] No endpoint interpolates user input into SQL.

---

## 11. Contract notes for the frontend

- [ ] The frontend never hardcodes a column name — it reads `columns[]` and filters by `role`.
- [ ] `uuid` is the only dataset identifier crossing the wire.
- [ ] Import is async: upload returns 202, then poll `/status` until `ready` or `failed`.
- [ ] `/aggregate` returns `{ labels, series }` already in ApexCharts shape.
- [ ] All timestamps are ISO 8601 UTC.

See [frontend/TASK.md](../frontend/TASK.md) for the consuming side.
