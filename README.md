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
| 3 | Calendar UI — shortcode, month grid, modal, create/sign up/withdraw | ⬜ Not started |
| 4 | Mission report form — all sections, repeating tables, GAR worksheet | ⬜ Not started |
| 5 | Persistence, PDF generation, email delivery | ⬜ Not started |
| 6 | Admin screens — patrol list, roster, reports | ⬜ Not started |
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
php tests/smoke.php   # roles-era foundation: GAR banding, enums, patrol types
php tests/logic.php   # scheduling: time ranges, overlaps, waitlist arithmetic
```

Both run without WordPress or PHPUnit — they stub the few WordPress functions
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
  Admin/                   Menu, Vessels screen, Settings screen
assets/                    Admin CSS and JS
tests/smoke.php            Dependency-free test suite
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
