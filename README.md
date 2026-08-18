# Marine Unit Plugin

A WordPress plugin for a police marine unit: a patrol calendar that Operators
post to and crew sign up for, plus a digital Marine Unit Mission Report.

Full requirements, decisions and the complete mission report field map live in
**[PROJECT_SPEC.md](PROJECT_SPEC.md)**. Read that first — it is the
authoritative brief.

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Foundation — roles, capabilities, user profile fields, settings, vessels, patrol types | ✅ Complete |
| 2 | Patrol data layer — CPT, sign-ups, overlap detection, waitlist promotion | ✅ Complete |
| 3 | Calendar UI — shortcode, month grid, modal, create/sign up/withdraw | ✅ Complete |
| 4 | Mission report form — all sections, repeating tables, GAR worksheet, saving | ✅ Complete |
| 5 | PDF generation, email delivery, resend | ✅ Complete |
| 6 | Admin screens — patrol list, roster, CSV export | ⬜ Not started |
| 7 | Accessibility, i18n, packaging | ⬜ Not started |

## Requirements

- WordPress 6.5+ (target: 7.0.4)
- PHP 8.1+ (target: 8.2)

## Building the installable zip

```bash
./bin/build.sh
```

Lints every PHP file, runs the test suite, and writes
`dist/marine-unit-plugin-<version>.zip`. The zip contains a single
`marine-unit-plugin/` directory, so it installs via **Plugins → Add New →
Upload Plugin**.

## Tests

```bash
php tests/smoke.php      # foundation: GAR banding, enums, patrol types
php tests/logic.php      # scheduling: time ranges, overlaps, waitlist arithmetic
php tests/calendar.php   # month arithmetic: blanks, leap years, navigation
php tests/render.php     # renders the real calendar and report HTML, parsed as a DOM
php tests/report.php     # report computed values, validation, field coercion
php tests/pdf.php        # generates a real PDF and asserts against its content
```

`tests/render.php` is the closest thing to an integration test available
without a WordPress install: it stubs WordPress, runs the real `Renderer`, and
asserts against the parsed DOM — so it catches undefined functions, unbalanced
markup, unescaped output and off-by-one errors in the grid.

All six run without WordPress or PHPUnit — they stub the few WordPress functions
the pure classes touch, so they work anywhere PHP is installed, and exit
non-zero on failure. `bin/build.sh` runs every suite in `tests/` and refuses to
package if any fails.

### Scheduling rules worth knowing

The tests pin behaviour that is easy to get wrong later:

- **Intervals are half-open `[start, end)`.** A patrol running 13:00–17:00 and
  one running 17:00–21:00 do **not** clash. Crew hand a boat straight over, and
  treating a shared boundary as a conflict would block a legitimate sign-up.
- **Overnight patrols are supported.** An end time at or before the start time
  rolls to the next day, so 22:00–02:00 is a four-hour night patrol rather than
  an error.
- **Bad times are rejected, not coerced.** `DateTimeImmutable::createFromFormat`
  overflows nonsense like `99:99` into a later date instead of failing, which
  produced an inverted range that silently made every overlap check return
  "no conflict". Parsing now round-trips the formatted result and refuses to
  construct an inverted range at all.
- **Lowering a patrol's crew limit never demotes confirmed crew.** The patrol
  runs over capacity until someone withdraws. Bumping someone who already
  committed is worse than a temporary overage.
- **Waitlist order is total and stable** — by position, then by sign-up id, so
  it never depends on row order from the database.

### PDF generation

The plugin generates PDFs with its own small writer (`includes/Pdf/`) rather
than bundling Dompdf or TCPDF. Three reasons:

- The Mission Report is a **fixed-layout form**. Placing text and rules at
  coordinates reproduces it far more faithfully than reflowing HTML.
- No vendored dependency — the whole plugin stays under 200KB, with nothing to
  keep patched.
- Only the 14 standard PDF fonts are used, so **no font files ship** and no
  external service is ever contacted.

`tests/pdf.php` generates a real document and asserts against its extracted
content, so "the PDF is valid and nothing fell off a page" is checked rather
than assumed. That test exists because an earlier version silently drew the
worksheet's closing paragraphs past the page edge, where they vanished without
any error — content flowing off a page is now paginated instead.

### Mission report rules worth knowing

- **Computed fields are never trusted from the browser.** The three meter
  totals and the GAR score are recalculated server-side on every save, and
  anything the request claimed for them is discarded.
- **Meter totals are rounded.** Decimal hour readings do not always subtract
  cleanly in binary floating point — `1000.3 - 1000.0` is `0.29999999999995` —
  so a raw subtraction would be stored and printed as that.
- **A blank meter is not a zero reading**, and an unanswered Yes/No question is
  not a "No". Both stay null rather than being coerced.
- **The GAR risk score on page 1 and the worksheet total are the same number**,
  derived once so they cannot diverge.
- **A delivery failure never fails a submission.** The report is saved first;
  the email outcome is recorded on it, and Supervisors can resend from
  **Marine Unit → Mission Reports**.
- **Four defects in the paper form are deliberately not reproduced** — see
  PROJECT_SPEC.md §2. Most consequentially, "Calls for Service" and "Vessels
  Boarded" shared one field on the original, so answering one answered the
  other. Each question now has its own field, and Yes/No is a radio pair rather
  than two checkboxes that could both be ticked.

## Shortcodes

| Shortcode | What it shows |
|---|---|
| `[marine_calendar]` | Month grid, patrol modal, sign-up/withdraw, and the Add Patrol button for Operators |
| `[marine_mission_report]` | The full Mission Report form, including the GAR worksheet |
| `[marine_my_patrols]` | Past patrols the signed-in user crewed |

Signed-out visitors hitting a page with either shortcode are redirected to the
login page configured in Settings, with a `redirect_to` back to where they were.

## What Phase 1 installs

On activation the plugin:

- Registers three roles — **Crewmate**, **Operator**, **Supervisor** — and
  grants every plugin capability to Administrators. Existing roles of the same
  name are updated rather than replaced.
- Creates three tables: `mup_signups`, `mup_vessels`, `mup_report_audit`.
- Seeds the vessel list (`SeaArk SB1`, `Zodiac SB2`, `Zodiac B16A`,
  `Parker B16`, `Other`, `Other Agency`) and the nine patrol types, both taken
  from the unit's existing mission report form.
- Adds **Officer ID #** and a rank/name override to the WordPress user profile.
- Adds a **Marine Unit** admin menu with Vessels and Settings screens.

Deactivating the plugin removes nothing. Deleting it removes roles and
capabilities, and removes unit data only if **Delete all data on uninstall** was
enabled in Settings first.

## Layout

```
marine-unit-plugin.php     Bootstrap: header, constants, hooks
uninstall.php              Deletion routine (opt-in data purge)
includes/
  Autoloader.php           PSR-4 autoloader, no Composer runtime needed
  Plugin.php               Container; wires modules on plugins_loaded
  Activator.php            Tables, roles, seeds
  Deactivator.php          Deliberately near-empty
  Roles.php                Roles, capabilities, meta-cap mapping
  Settings.php             Option registry, defaults, sanitisation
  PatrolTypes.php          Editable patrol type list
  UserProfile.php          Officer ID and display name fields
  Enum/                    GarBand, PatrolStatus, SignupStatus, VesselStatus
  Database/Schema.php      dbDelta table definitions
  Support/
    TimeRange.php          Half-open interval, overlap and overnight handling
    Result.php             Success/failure/warning outcomes
    MonthGrid.php          Calendar month arithmetic
  Frontend/
    Shortcodes.php         Shortcode registration and the login gate
    Renderer.php           Calendar HTML
    ReportRenderer.php     Mission report form HTML
    Ajax.php               Calendar endpoints, all nonce + capability checked
    ReportAjax.php         Report submission and crew autofill
    Assets.php             Conditional CSS/JS loading, accent colours
  Reports/
    ReportSchema.php       Field definitions — one source of truth
    ReportData.php         Values plus every computed figure
    ReportSanitizer.php    Server-side sanitising and validation
    ReportRepository.php   CPT, JSON payload storage, audit trail
    ReportMailer.php       PDF build, delivery, outcome recording
  Pdf/
    PdfWriter.php          Self-contained PDF generator, no dependencies
    ReportPdf.php          The report laid out to match the paper form
  Patrols/
    Patrol.php             Patrol model
    PatrolRepository.php   CPT registration, persistence, calendar queries
    ConflictChecker.php    User overlap (blocks) and vessel overlap (warns)
  Signups/
    Signup.php             Sign-up model
    Waitlist.php           Pure waitlist arithmetic
    SignupRepository.php   Persistence and transaction control
    SignupService.php      Sign up, withdraw, remove, capacity sync
  Vessels/                 Vessel model and repository
  Admin/                   Menu, Vessels, Settings, Mission Reports screens
assets/                    Admin and calendar CSS/JS
tests/                     Dependency-free test suites (no WordPress, no PHPUnit)
bin/build.sh               Lint, test and package
```

## Conventions

- Namespace `MarineUnit\`; prefix `mup_` / `MUP_`; text domain
  `marine-unit-plugin`.
- Every entry point checks a capability server-side. Hiding a button is never
  the security boundary.
- Nonce on every state-changing request; `$wpdb->prepare()` on every query
  taking input; output escaped at the point of echo.
- Object-level permissions go through `map_meta_cap`, so call
  `current_user_can( Roles::EDIT_PATROL, $patrol_id )` rather than checking a
  primitive capability directly.

## Note on the source form

The unit's existing report (`BMR_1.08.26.pdf`) is an Adobe LiveCycle **XFA
dynamic form** — ordinary PDF text extraction returns only a placeholder
message. The field definitions were recovered from the embedded XFA template;
`PROJECT_SPEC.md` §2 documents how to repeat that extraction.

That sample PDF contains real officers' names and ID numbers. It is
`.gitignore`d and must not be committed, nor used for test fixtures.
