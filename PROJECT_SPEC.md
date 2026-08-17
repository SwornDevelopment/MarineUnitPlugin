# Marine Unit Plugin — Project Specification

> **Purpose of this document.** This is the authoritative project brief. It is written so that a
> fresh AI session (or a new developer) can read this file alone and understand the entire project
> without the client re-explaining anything. Every decision below was explicitly confirmed by the
> client. Anything not yet confirmed is listed in [§14 Open Items](#14-open-items) — do not invent
> answers for those; use the stated default and surface the question.

- **Repository:** `SwornDevelopment/MarineUnitPlugin`
- **Working branch:** `claude/police-boat-unit-plugin-r9yzwe`
- **Status:** Specification complete. Implementation not yet started.
- **Spec date:** 2026-08-17

---

## 1. What we are building

A self-contained WordPress plugin for the **Charles County Sheriff's Office Marine Unit** (a police
boat unit). It does two things:

1. **A patrol calendar.** Operators post patrols they intend to run. Operators and Crewmates sign
   up to crew those patrols. Every signed-in member can see the calendar.
2. **A Marine Unit Mission Report.** A digital replica of the unit's existing 3-page Adobe
   LiveCycle form. Any signed-in user can file one. On submission it is saved in WordPress and a
   generated PDF is emailed to a configured address.

There is no public-facing component. Everything requires a login.

### Non-goals

- No payment, ticketing, or public booking.
- No native mobile app.
- No third-party form plugin (Forminator, Gravity Forms, etc.) — see §3.
- No recurring/repeating events.
- No real-time push or websockets.

---

## 2. Key context about the source form

The client supplied `BMR_1.08.26.pdf`, a **completed** Marine Unit Mission Report.

**Critical detail:** it is an **XFA dynamic form** (Adobe LiveCycle Designer, XFA template 2.8).
Standard PDF text extraction returns only the "Please wait… if this message is not eventually
replaced…" placeholder. The real content lives in the `/AcroForm /XFA` array. To re-extract it:

```python
from pypdf import PdfReader
r = PdfReader("BMR_1.08.26.pdf")
xfa = r.trailer["/Root"]["/AcroForm"]["/XFA"]
# xfa is a flat [name, streamRef, name, streamRef, ...] array.
# Parts: config, template, localeSet, xmpmeta, xfdf, form, datasets
# - "template" = field definitions, captions, geometry, dropdown options
# - "datasets" = the filled-in values from the completed sample
```

`template` uses namespace `http://www.xfa.org/schema/xfa-template/2.8/`. Fields carry `x`/`y`
geometry in mm; labels are either a field's `<caption><value>` or a sibling `<draw>` element's
`<value>`. The template contains **three top-level page subforms** whose coordinates overlap, so
any parse must group by page subform before sorting by `y`.

**Privacy:** the sample PDF contains real officers' names and ID numbers. It is used as a layout
reference only. **Do not commit the sample PDF or any of its personnel data to the repository**, and
do not use those names in test fixtures, docs, or seed data.

### Defects in the original form to fix (do not replicate)

| Defect | Fix |
|---|---|
| `CheckBox14` / `CheckBox15` are reused for **both** "Calls for Service Y/N" **and** "Vessels Boarded Y/N", so the two questions share one answer. | Give each question its own independent field. |
| `CheckBox16` is reused for all nine Training checkboxes, so they cannot be independently recorded. | Give each training topic its own field. |
| `TextField31` (crew ID) and `TextField35`/`TextField36`/`TextField37` are reused across rows. | Model as proper repeating rows. |
| Engine-hour totals (`NumericField1/2/3`) were not reliably saved. | Compute totals server-side from Start/Finish; never trust a submitted total. |

---

## 3. Decision: build the form in the plugin, not Forminator

**Confirmed with client.** The form is built natively into the plugin. Rationale:

- Reports need role-scoped visibility (author + Supervisors/Admins only). Forminator entries are
  all-or-nothing to whoever can see the entries screen.
- Reports must lock on submission and be amendable only by Supervisors. Forminator has no such
  lifecycle.
- The PDF must match the CCSO layout precisely, including a computed GAR score and computed engine
  totals. Forminator's PDF add-ons render generic field lists.
- Optional crew autofill pulls from calendar events and the WP user directory — an integration that
  would have to be custom-coded against Forminator anyway.
- One plugin, one data model, one thing to maintain and version.

---

## 4. Roles and permissions

The plugin **registers three roles on activation** (they do not currently exist on the site). If a
role already exists, its capabilities are updated rather than the role being clobbered. Roles and
capabilities are removed only on explicit uninstall, never on deactivation.

| Role | Slug | Purpose |
|---|---|---|
| Crewmate | `marine_crewmate` | Can view the calendar, sign up for patrols, file reports. |
| Operator | `marine_operator` | Everything a Crewmate can do, plus create and run patrols. |
| Supervisor | `marine_supervisor` | Oversight. Manages vessels, patrol types, settings; sees all reports; cancels any patrol; views the roster. Not a WP Administrator. |

WordPress **Administrator** receives every capability below. All three roles inherit the standard
`read` capability.

### Capability map

| Capability | Crewmate | Operator | Supervisor | Admin |
|---|:--:|:--:|:--:|:--:|
| `marine_view_calendar` | ✅ | ✅ | ✅ | ✅ |
| `marine_signup_patrol` | ✅ | ✅ | ✅ | ✅ |
| `marine_create_patrol` | — | ✅ | ✅ | ✅ |
| `marine_edit_own_patrol` | — | ✅ | ✅ | ✅ |
| `marine_edit_any_patrol` | — | — | ✅ | ✅ |
| `marine_manage_signups` (remove others from a patrol) | — | own patrols | ✅ | ✅ |
| `marine_submit_report` | ✅ | ✅ | ✅ | ✅ |
| `marine_view_own_reports` | ✅ | ✅ | ✅ | ✅ |
| `marine_view_all_reports` | — | — | ✅ | ✅ |
| `marine_edit_any_report` (amend a locked report) | — | — | ✅ | ✅ |
| `marine_manage_vessels` | — | — | ✅ | ✅ |
| `marine_manage_settings` | — | — | ✅ | ✅ |
| `marine_view_roster` | — | — | ✅ | ✅ |

**Every** front-end and AJAX entry point checks the relevant capability server-side. Hiding a button
in the UI is never the security boundary.

### User profile additions

The plugin adds fields to the WordPress user profile screen:

- **Officer ID #** (`marine_officer_id`) — e.g. `644`. Text, not numeric (may contain letters).
  Auto-fills on mission reports and shows next to names on the roster.
- **Display rank/name override** (`marine_display_name`) — optional, e.g. `Cpl. Wynne`. Falls back
  to the WP display name.

Officer ID is editable by the user on their own profile and by Supervisors/Admins on any profile.

---

## 5. The patrol calendar

### 5.1 Behaviour summary

| Question | Decision |
|---|---|
| Who creates patrols? | Operators (and Supervisors/Admins). |
| Where do they create them? | **Front end**, from a button on the calendar page. Operators never need wp-admin. |
| Who signs up? | Operators and Crewmates. |
| Approval required? | **No.** Publishes instantly. Supervisors/Admins can cancel any patrol afterwards. |
| Crew limit? | **Yes, with a waitlist.** Operator sets max crew. |
| Do signups pick a position? | **No.** Everyone is simply listed as crew. |
| Can a user be on two overlapping patrols? | **No — blocked.** |
| Can the same boat be double-booked? | **Warn but allow.** Operator can override. |
| Signup cutoff? | **None.** Signups remain open even after a patrol has started or passed (supports backfilling records). |
| Can crew withdraw? | **Yes, any time before the patrol starts.** After start, only the Operator/Supervisor can remove them. |
| Is the creating Operator auto-added as crew? | **Yes**, and they occupy one of the crew slots. |
| Editing/cancelling with crew attached? | **Allowed, no notification emails.** Crew simply see the updated calendar. |
| Recurring patrols? | **No.** One at a time. |
| "Live" means? | Dynamic, not statically hand-edited. Fresh on page load; signup/withdraw updates the UI via AJAX without a full reload. **No polling, no auto-refresh timer.** |

### 5.2 Calendar UI

- **Placement:** shortcode only — `[marine_calendar]`. No Gutenberg block, no Elementor widget, no
  auto-created page.
- **View:** **month grid only.** No week view, no agenda view. Month grid must collapse gracefully
  to a usable stacked layout on phones (see §9).
- **Interaction:** clicking a patrol opens a **modal** on the same page showing full details and the
  Sign Up / Withdraw button. No per-patrol pages, no inline expansion.
- **Navigation:** previous / next month, plus a "Today" control.
- **Logged-out visitors:** **redirected to the site's custom login page** (configurable URL, see
  §10), with a `redirect_to` parameter so they land back on the calendar after logging in.

### 5.3 Patrol fields

Set by the Operator when creating a patrol:

| Field | Type | Required | Notes |
|---|---|:--:|---|
| Date | date | ✅ | |
| Start time | time | ✅ | |
| End time | time | ✅ | May be **earlier** than the start time, which means the patrol runs past midnight (22:00–02:00 is a four-hour night patrol). Night operations are routine for a marine unit, so rejecting this would have been wrong. |
| Launch point | text | ✅ | e.g. `Smallwood State Park`. |
| Vessel | select | ✅ | From the admin-managed vessel list (§7). Out-of-service vessels are not selectable. |
| Patrol type | select | ✅ | From the admin-managed patrol type list (§7). |
| Max crew | number | ✅ | Minimum 1. The creating Operator occupies one slot. |
| Notes | textarea | — | Free text. |

### 5.4 Signup and waitlist rules

- Signup is capability-checked and **idempotent** — a double-submit cannot create two signups.
- If confirmed crew count < max crew → status `confirmed`. Otherwise → status `waitlisted`, with a
  monotonically increasing `waitlist_position`.
- On withdrawal or removal of a **confirmed** crew member, the **lowest-positioned waitlisted**
  member is automatically promoted to `confirmed` in the same database transaction.
- If an Operator **raises** max crew, waitlisted members are promoted in order until the new limit
  is met.
- If an Operator **lowers** max crew below the current confirmed count, existing confirmed members
  are **never** demoted. The patrol is simply over capacity and shows as full. This is deliberate —
  silently bumping someone who has already committed is worse than a temporary overage.
- **Overlap check:** before confirming a signup, reject if the user has any existing signup
  (`confirmed` or `waitlisted`) whose `[start, end)` interval intersects this patrol's. The error
  message names the conflicting patrol. Waitlisted signups count because a waitlisted member may be
  promoted at any moment — letting them hold a clashing place only defers the problem.
- **Intervals are half-open.** A patrol ending at 17:00 and one starting at 17:00 do **not** clash.
  Crew hand a boat straight over; treating a shared boundary as a conflict would block a legitimate
  signup. Cancelled patrols never block anything.
- **Vessel double-booking check:** on patrol create/edit, if the chosen vessel is already assigned to
  an overlapping patrol, return a **warning** with a confirm-and-proceed path. Never a hard block.
- All signup mutations run inside a transaction with a row-level lock on the patrol to prevent a
  race from over-filling the boat.

---

## 6. The Marine Unit Mission Report

### 6.1 Behaviour summary

| Question | Decision |
|---|---|
| Linked to a calendar event? | **Standalone.** A report is never required to reference a patrol. |
| Crew autofill? | **Optional.** A "Pull crew from a patrol" action can populate the crew table from a calendar event the user attended. Purely a typing shortcut — no stored relationship is required. Crew rows may also be picked from the user directory, which auto-fills Officer ID. |
| Who can file one? | Any signed-in user. |
| Who can view a filed report? | The **author**, plus **Supervisors** and **WP Admins**. Nobody else — not even the Operator who ran the patrol. |
| Editing after submission? | **Locked immediately.** Only Supervisors/Admins can amend, and amendments are recorded in an audit trail. |
| Drafts? | Not required. (If added later, a draft is not a submission and is not emailed.) |
| Storage | **Saved in WordPress AND emailed.** |
| Email | A generated **PDF is emailed to a fixed address** set in plugin settings. Single address; no copy to submitter, no per-Supervisor fan-out. |
| Attachments / photos / signature | **None.** Text fields only. |
| PDF appearance | **Match the existing CCSO form closely** — same sections, headings, order, header text and form number. Not a pixel-perfect clone, not a redesign. |
| Access route | Its own page via shortcode `[marine_mission_report]`. No link from the calendar. |

### 6.2 Complete field map

This is the definitive field list, reverse-engineered from the XFA template. The `XFA` column gives
the original field name for traceability. Types: `text`, `textarea`, `date`, `time`, `number`,
`select`, `checkbox`, `bool` (Yes/No pair), `computed`.

#### Header

| Field key | Label | Type | XFA |
|---|---|---|---|
| — | `Charles County Sheriff's Office Marine Unit Mission Report` | static title | `Text1` |

#### Section I — General Mission Information

| Field key | Label | Type | XFA | Notes |
|---|---|---|---|---|
| `mission_date` | Mission Date | date | `DateTimeField1` | |
| `depart_time` | Depart Time | time | `TextField1` | Stored 24h (`1700`). |
| `return_time` | Return Time | time | `TextField2` | |
| `launch_point` | Launch Point | text | `TextField3` | |
| `mission_type[]` | Mission Type | checkbox group, multi-select | `CheckBox1`–`CheckBox9` | Options in order: **LE Response, TRT Response, MTOG Response, Marine Patrol, Marine Rescue, Dive Support Ops., TRT Training, Training, Other**. |
| `mission_type_other` | Other (free text) | text | — | Shown only when `Other` is checked. |
| `incident_location` | Incident Location or Area/s of Operation | textarea | `TextField4` | Caption note: *"May insert GPS coordinates or general area."* |
| `gar_risk_score` | GAR Risk Score | computed | `_7` | Mirrors the GAR worksheet total (§6.3). Read-only. |

#### Vessel & engine block

| Field key | Label | Type | XFA | Notes |
|---|---|---|---|---|
| `vessel` | Boat / Vessel Launched | select | `DropDownList1` | Seeded options: **SeaArk SB1, Zodiac SB2, Zodiac B16A, Parker B16, Other, Other Agency**. Sourced from the vessel list in §7. |
| `engine_port_start` | Engine Hours Port — Start | number | `NumericField4` | Decimal, 1–2 dp. |
| `engine_port_finish` | Engine Hours Port — Finish | number | `NumericField5` | |
| `engine_port_total` | Engine Hours Port — Total | computed | `NumericField1` | `finish − start`, server-side. |
| `engine_stbd_start` | Engine Hours Starboard — Start | number | `TextField9` | |
| `engine_stbd_finish` | Engine Hours Starboard — Finish | number | `TextField10` | |
| `engine_stbd_total` | Engine Hours Starboard — Total | computed | `NumericField2` | |
| `generator_start` | Generator Hours — Start | number | `TextField11` | |
| `generator_finish` | Generator Hours — Finish | number | `TextField12` | |
| `generator_total` | Generator Hours — Total | computed | `NumericField3` | |
| `fuel_usage` | Fuel Usage | text | `TextField13` | Free text (sample value `.50`). |
| `oil_usage` | Oil Usage | text | `TextField14` | Free text (sample value `.25`). |

All three totals are computed live in the browser for feedback **and** recomputed server-side on
save. If finish < start the field is flagged and the report cannot be submitted.

#### Section II — Operational Weather Conditions

| Field key | Label | Type | XFA |
|---|---|---|---|
| `weather_conditions` | Weather Conditions | text | `TextField15` |
| `air_temp` | Air Temp | text | `TextField16` |
| `water_temp` | Water Temp | text | `TextField17` |
| `tide_high` | Tides — H | text | `TextField18` |
| `tide_low` | Tides — L | text | `TextField19` |
| `wind_conditions` | Wind Conditions | text | `TextField20` |

The original form has a "Tide Tables Website" link next to this section (`RadioButtonList`) and a
"Google Maps" link next to the location section. Reproduce these as **plain external hyperlinks**,
with their URLs configurable in settings.

#### Section III — Crew Information

Repeating table, **10 rows**, columns **Name / ID / Position**.

| Column | Type | XFA | Notes |
|---|---|---|---|
| Name | text (with user-directory autocomplete) | `TextField21`–`TextField30` | |
| ID | text | `TextField31` (×10) | Auto-fills from the selected user's `marine_officer_id`. |
| Position | select | `DropDownList2`–`DropDownList10` | Options: **Operator, Crewman, Medic, Passenger**. |

**Row 1 is special:** it has no Position dropdown. It is fixed-labelled **"Primary Operator"** on the
form and must render that way in both the web form and the PDF.

> Note: these Position values are *report* metadata only. They are unrelated to calendar signups,
> which deliberately have no position selection (§5.1).

#### Section IV — Mission Information

*"Check and Answer all Categories that Apply"*

| Field key | Label | Type | XFA | Notes |
|---|---|---|---|---|
| `pretrip_inspection` | Pre-Trip Inspection completed? | bool (Yes/No) | `CheckBox10`/`CheckBox11` | |
| `notifications_made` | Proper notifications made? | bool (Yes/No) | `CheckBox12`/`CheckBox13` | |
| `unit_supervisor` | Unit Supervisor | text | `TextField33` | Sample value is an officer ID (`644`). |
| `communications` | Communications | text | `TextField34` | Sample value `D1`. |
| `calls_for_service` | Calls for Service? | bool (Yes/No) | `CheckBox14`/`CheckBox15` ⚠️ | **Give this its own field** — see §2 defects. |
| `calls[]` | Event # + description | repeating, **4 rows** | `TextField35` (Event #) / `TextField36` (description) | |
| `vessels_boarded` | Vessels Boarded and/or Inspected? | bool (Yes/No) | `CheckBox14`/`CheckBox15` ⚠️ | **Own field.** Sub-label: *"List Vessel Name and or Registration Number."* |
| `boarded[]` | Vessel name / registration | repeating, **4 rows** | `TextField37` | |
| `mission_notes` | Notes | textarea | `TextField38` | Large. |

#### Section V — Maintenance Information

| Field key | Label | Type | XFA |
|---|---|---|---|
| `maintenance_problems` | Maintenance Problems | textarea | `TextField39` |

Static note beneath: *"Attach CCSO Form 310 if completed"* (`Text33`). Render as text only — there
are no file uploads.

#### Section VI — Training

| Field key | Label | Type | XFA | Notes |
|---|---|---|---|---|
| `training[]` | Training topics | checkbox group, multi-select | `CheckBox16` ×9 ⚠️ | **Nine independent fields.** Layout order on the form is column-major: **MOB, Towing, GPS** \| **FLIR, Radar, Anchoring** \| **Search Patterns, Boat Handling, Other**. |
| `training_description` | Training description | textarea | `TextField40` | |

#### Signature block

| Field key | Label | Type | XFA | Notes |
|---|---|---|---|---|
| `completed_by` | Report Completed By | text | `TextField41` | Defaults to the current user's display name. |
| `completed_by_id` | ID # | text | `TextField42` | Defaults to the current user's `marine_officer_id`. |
| `completed_date` | Date | date | `DateTimeField2` | Defaults to today. |

Footer on both report pages: **`CCSO Form #`** (`Text34`) — the exact form number is an open item
(§14) and is a configurable setting.

### 6.3 GAR Risk Calculation Worksheet (page 3)

**Confirmed: built into the form, with a live total and colour band.**

Six inputs, each an integer **0–10**:

| Field key | Element | XFA |
|---|---|---|
| `gar_supervision` | Supervision | `_1s` |
| `gar_planning` | Planning | `_2p` |
| `gar_team_selection` | Team Selection | `_3t` |
| `gar_team_fitness` | Team Fitness | `_4t` |
| `gar_environment` | Environment | `_5e` |
| `gar_complexity` | Event/Evolution Complexity | `_6e` |
| `gar_total` | **Total Risk Score** (computed) | `_7` |

`gar_total` = sum of the six. It is computed live in the browser, recomputed server-side on save,
and **mirrored into `gar_risk_score` on page 1** — the two are the same value and must never
diverge.

**Colour banding** (display in the form and the PDF):

| Band | Range | Meaning |
|---|---|---|
| 🟢 GREEN | 1 – 23 | Low risk |
| 🟠 AMBER | 24 – 44 | Caution — consider procedures to minimise risk |
| 🔴 RED | 45 – 60 | High risk — implement measures to reduce risk before starting |

The worksheet's full explanatory text (definitions of Supervision, Planning, Team Selection, Team
Fitness, Environment, and Event/Evolution Complexity, plus the GAR Evaluation Scale narrative) is
present verbatim in the XFA template `draw` elements `T0`–`T63`. **Reproduce this text verbatim in
the PDF.** In the web form, present it as collapsible help next to each input rather than a wall of
text.

### 6.4 Submission lifecycle

1. Client-side validation runs (required fields, finish ≥ start, GAR values 0–10).
2. POST with a nonce. Server re-validates **everything** — client validation is a convenience only.
3. Computed fields (`*_total`, `gar_total`, `gar_risk_score`) are recalculated server-side; any
   submitted values for them are discarded.
4. Report is saved with status `submitted` and is immediately **locked**.
5. PDF is generated.
6. PDF is emailed to the configured address.
7. Email success/failure is recorded on the report. **A failed email never fails the submission** —
   the record is already saved. Failures are surfaced to Supervisors in the admin list with a
   "Resend" action.
8. User sees a confirmation with a link to view their filed report (read-only).

Supervisor amendments write a new entry in the report's audit trail (who, when, which fields
changed, before/after values). Original values are never destroyed.

---

## 7. Admin-managed lists

Both lists are managed by Supervisors/Admins in the plugin's settings area and are seeded on first
activation with the values below (taken from the original form).

### Vessels

Full records, not just names:

| Property | Notes |
|---|---|
| Name | e.g. `Zodiac SB2` |
| Registration / hull number | Optional |
| Capacity | Optional; informational |
| Status | `active` / `out of service` — out-of-service vessels cannot be selected for new patrols |

**Seed:** `SeaArk SB1`, `Zodiac SB2`, `Zodiac B16A`, `Parker B16`, `Other`, `Other Agency`.

### Patrol types

Editable list, seeded from the form's Mission Type checkboxes:

`LE Response`, `TRT Response`, `MTOG Response`, `Marine Patrol`, `Marine Rescue`,
`Dive Support Ops.`, `TRT Training`, `Training`, `Other`.

Deleting a vessel or patrol type that is referenced by existing patrols/reports must **not** break
those records — soft-delete (archive) rather than hard-delete.

---

## 8. Screens and shortcodes

### Shortcodes

| Shortcode | Purpose | Access |
|---|---|---|
| `[marine_calendar]` | Month-grid patrol calendar, event modal, signup/withdraw, and the "Add Patrol" button for Operators. | Signed in; otherwise redirect to login. |
| `[marine_mission_report]` | The full 3-section mission report form including the GAR worksheet. | Signed in. |
| `[marine_my_patrols]` | **Past patrols** the current user crewed. | Signed in. |

Note: the client chose *past* patrols only. An upcoming-patrols view and a my-reports list were
explicitly **not** requested. `[marine_my_patrols]` should still link a user to their own filed
reports where one exists, but a standalone reports list is out of scope.

### WordPress admin screens (Supervisors + Admins)

| Screen | Contents |
|---|---|
| **Patrols** | All patrols, filterable by date range, vessel, patrol type, and Operator. Each row expands to its crew list. Cancel/edit any patrol. **CSV export.** |
| **Roster** | Per-user activity: look up one member and see every patrol they have crewed, with dates, vessels, and totals. |
| **Mission Reports** | All reports (Supervisors/Admins only). View, amend (with audit trail), re-download PDF, resend email. Filter by date, author, vessel, mission type. |
| **Vessels** | Manage the vessel list (§7). |
| **Settings** | All configuration in §10. |

Operators do **not** need the admin area for any part of their normal workflow.

---

## 9. Front-end design

- **Styling approach:** self-contained plugin styling **plus accent-colour settings**, so the unit
  can match its branding without a developer. The plugin does not rely on theme styles, but it must
  not fight them either — all CSS is scoped under a plugin root class, and no global element
  selectors are used.
- **Devices:** **desktop and mobile equally.** Fully responsive. The month grid must remain usable
  on a phone — below the breakpoint, switch the grid to a compact stacked day list rather than
  shrinking cells into illegibility.
- Accessible: keyboard-navigable modal with focus trapping and Escape to close, proper form labels,
  visible focus states, and colour that is never the sole carrier of meaning (the GAR band shows its
  name as well as its colour).
- No external CDNs, no Google Fonts fetch, no bundled jQuery UI. Vanilla JS. The plugin ships
  everything it needs.

---

## 10. Settings

| Setting | Default | Notes |
|---|---|---|
| Report recipient email | *(unset — must be configured)* | Single address. Submissions warn Supervisors if unset. |
| Email "from" name / address | WordPress defaults | |
| Login page URL | `wp-login.php` | Client uses a **custom login page** — the URL is an open item (§14). Stored as a setting so it can be changed any time. |
| Accent colour (primary) | TBD from branding | |
| Accent colour (secondary) | TBD from branding | |
| Unit name shown in headings | `Charles County Sheriff's Office Marine Unit` | |
| CCSO form number (PDF footer) | *(unset)* | Open item §14. |
| Google Maps link URL | Google Maps | Used by the location helper link. |
| Tide tables link URL | *(unset)* | Used by the weather section helper link. |
| Timezone | Site timezone | Open item §14. |
| Time format | 24-hour | The source form uses 24-hour (`1700`, `2100`). Confirm — open item §14. |
| Date format | Site default | Open item §14. |

---

## 11. Technical design

### Stack and conventions

- **Target environment (confirmed):** **WordPress 7.0.4, PHP 8.2.**
- **Declared minimum:** `Requires at least: 6.5`, `Requires PHP: 8.1`. Building against a slightly
  lower floor than the target costs nothing and keeps the plugin installable if the unit ever runs a
  second site on an older stack.
- PHP 8.2 is available, so **enums, readonly properties, constructor promotion, `never` return
  types, and first-class callable syntax are all fair game** — no polyfills or defensive
  version-sniffing needed. Use enums for signup status, report status, vessel status, and GAR band.
- Avoid PHP 8.2 *deprecations*: no dynamic properties on classes (declare every property, or the
  plugin will emit deprecation notices under WP 7's stricter debug defaults).
- Prefix everything: constant `MUP_`, functions/tables `mup_`, text domain `marine-unit-plugin`.
- Namespaced PHP classes under `MarineUnit\`, PSR-4 autoloaded via a small custom autoloader (no
  Composer runtime requirement for the shipped zip).
- Fully internationalised — all user-facing strings wrapped for translation.
- No direct `$_POST` access without sanitisation; nonce on every state-changing request;
  capability check on every entry point; `$wpdb->prepare()` on every query.
- Uninstall (not deactivate) removes roles, capabilities, tables, and options, gated behind an
  explicit "delete all data" setting so an accidental uninstall doesn't destroy the unit's records.

### Data model

**Custom post types**

| CPT | Purpose | Notes |
|---|---|---|
| `marine_patrol` | One patrol. | Non-public, `show_ui` false (managed by the plugin's own screens). Meta: date, start, end, launch point, vessel ID, patrol type, max crew, notes, status (`active`/`cancelled`). |
| `marine_report` | One mission report. | Non-public. Payload stored as a single JSON meta blob (`_mup_report_data`) plus **indexed** meta keys for the fields that get filtered on: mission date, author, vessel, mission types, GAR total. Status: `submitted`. |

Rationale for the JSON blob: the report has ~90 fields including three repeating tables. Exploding
that into ~90 meta rows makes queries slower and migrations painful, while only five fields are ever
filtered on. The blob is versioned (`_mup_schema_version`) so future field changes can be migrated.

**Custom tables**

| Table | Purpose |
|---|---|
| `{prefix}mup_signups` | `id, patrol_id, user_id, status ('confirmed'\|'waitlisted'), waitlist_position, created_at`. Unique index on `(patrol_id, user_id)` — this is what makes signup idempotent. Index on `(user_id)` for the overlap check and the roster. |
| `{prefix}mup_vessels` | `id, name, registration, capacity, status, sort_order`. |
| `{prefix}mup_report_audit` | `id, report_id, user_id, changed_at, changes (JSON)`. Append-only. |

Patrol types are stored as a plugin option (a simple ordered list) — they have no properties beyond
a name and don't warrant a table.

**Why custom tables for signups:** waitlist ordering, the overlap check, and the roster are all
relational queries that post-meta handles badly. Signups are also high-churn.

### PDF generation

- **Library: Dompdf**, bundled in the plugin. HTML/CSS → PDF, which suits reproducing a
  section-based form and keeps the template maintainable and translatable. No external service, no
  API key, no network call at generation time.
- The PDF template lives as a PHP/HTML view mirroring the section order in §6.2, so the form and the
  PDF stay in sync by construction.
- **Page 3 (GAR worksheet) reproduces the explanatory text verbatim** from the XFA `draw` elements.
- Generated PDFs are written to a temp file, attached to the email, and deleted. They are **not**
  stored in the Media Library (the client chose "saved and emailed", not "PDF file kept") — the
  report data is the record, and the PDF is regenerable on demand from the admin screen.

### Email

- Sent via `wp_mail()`. On a site without proper SMTP this lands in spam or vanishes — recommend an
  SMTP plugin to the client and detect/warn if `wp_mail` is unfiltered.
- Failures are logged on the report and never block submission (§6.4).

---

## 12. Delivery

- **Installable `.zip`**, uploaded via *Plugins → Add New → Upload*.
- Built from the repo with a build script that excludes dev files (`.git`, tests, `PROJECT_SPEC.md`,
  node/dev tooling) and produces `marine-unit-plugin-x.y.z.zip`.
- Semantic versioning. Changelog maintained in the repo.
- No staging site is available yet. Until one exists, verification is limited to PHP syntax/lint,
  static analysis, and unit tests of pure logic (waitlist promotion, overlap detection, GAR
  totalling, engine-hour computation). **The client will provide a staging URL later** — at that
  point, drive it with Playwright end-to-end: log in as an Operator, create a patrol, sign up as a
  Crewmate, trigger the waitlist, and submit a report.

---

## 13. Suggested build order

Each phase should end in a committed, working state.

1. **Foundation.** Plugin scaffold, activation/deactivation/uninstall, roles and capabilities, user
   profile fields, settings page, vessel and patrol-type management with seeds.
2. **Patrols, data layer.** CPT, signups table, and the pure logic: overlap detection, vessel
   conflict detection, waitlist promotion. Unit-tested before any UI exists.
3. **Calendar UI.** Shortcode, month grid, modal, create/edit patrol form, signup/withdraw AJAX,
   login redirect. Responsive pass.
4. **Mission report form.** All sections from §6.2, repeating crew/calls/boarded tables, live
   computed totals, GAR worksheet with live banding, crew autofill from patrols and user directory.
5. **Persistence, PDF, email.** Save + lock, Dompdf template matching the CCSO layout, email
   delivery, failure handling and resend.
6. **Admin screens.** Patrols list with CSV export, per-user roster, reports list with
   view/amend/audit trail, resend.
7. **Polish and package.** Accessibility pass, i18n pass, accent colour settings, build script and
   zip.

---

## 14. Open items

None of these block the start of implementation. Each has a stated default; use it and flag the
question rather than inventing an answer.

| # | Question | Default until answered |
|---|---|---|
| 1 | **Custom login page URL** — the client confirmed they have one but has not given the path. | Setting defaults to `wp_login_url()`. |
| 2 | **Report recipient email address.** | Unset; Supervisors see a warning until configured. |
| 3 | **Accent colours / branding** (hex codes, logo file). | Neutral navy/slate palette. |
| 4 | ~~WordPress version, PHP version~~ — **answered: WP 7.0.4, PHP 8.2.** Still unknown: **active theme**, and whether the site is **live or a fresh build**. | Assume a live site: no destructive migrations, no assumptions about theme markup. |
| 5 | **Timezone, date format, 12- vs 24-hour time.** | Site timezone; 24-hour (matches the source form); site date format. |
| 6 | **Exact CCSO form number** for the PDF footer. | Blank. |
| 7 | **Tide tables URL** used by the weather-section link. | Blank; link hidden if unset. |
| 8 | **Unit Supervisor / Communications fields** (§6.2, Section IV) — the sample contains an officer ID and `D1`. Are these free text, or should they be pickers? | Free text. |
| 9 | **Fuel & oil usage units** — sample values `.50` / `.25` are ambiguous (tanks? gallons? hours?). | Free text, no unit enforcement. |

---

## 15. Confirmed-decision index

Quick reference for anything that might otherwise be re-litigated. All of these were explicitly
chosen by the client.

- Form built into the plugin, **not** Forminator.
- Roles created by the plugin: Crewmate, Operator, **Supervisor** (separate from WP Admin).
- Patrols publish **instantly**; Admins can cancel afterwards.
- Crew cap **with waitlist**; creating Operator **auto-added** and occupies a slot.
- Signups have **no position selection**.
- Overlapping signups for a user: **blocked**. Overlapping vessel bookings: **warn, allow**.
- **No** signup cutoff. Withdrawal allowed **any time before start**.
- **No** recurring patrols. **No** change-notification emails.
- Calendar: **shortcode only**, **month grid only**, **modal** detail, **front-end** patrol creation.
- Logged-out: **redirect to custom login page**.
- Vessels: **admin-managed with full details** (registration, capacity, in/out of service).
- Patrol types: **admin-editable list**.
- Reports: **standalone**, with **optional** crew autofill.
- Report visibility: **author + Supervisors/Admins only**.
- Reports **lock on submission**; Supervisors only may amend.
- **PDF emailed to one fixed address**, and the report is **also saved in WordPress**.
- **No** file uploads, photos, or signature capture.
- GAR worksheet **included**, with live total and colour band.
- Officer ID # lives on the **WP user profile**.
- PDF **closely matches** the existing CCSO form.
- `[marine_my_patrols]` shows **past** patrols. Supervisors get a roster **with per-user activity**.
- Styling: **self-contained + accent colour settings**. **Responsive, desktop and mobile equally.**
- Delivery: **installable .zip**.
