# Stallionking Tracker: Laravel version with QR + label-text scanning

Full **Laravel 12** application (PHP 8.2+, SQLite by default; MySQL/Postgres also work).

## Run locally
1. `composer install`
2. `cp .env.example .env && php artisan key:generate` (skip if `.env` already exists)
3. Add the values from `.env.additions` to `.env` (for local HTTP use `SESSION_SECURE_COOKIE=false`)
4. `touch database/database.sqlite && php artisan migrate --seed`
5. `php artisan serve`, then open http://127.0.0.1:8000 and sign in at `/login` with `SEED_ADMIN_STAFF_ID` / `SEED_ADMIN_PASSWORD`
6. `php artisan test`

To use MySQL instead, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`. In production serve over **HTTPS** (browsers only allow camera access on HTTPS or localhost) with `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`.

## How scanning works
- **QR:** native `BarcodeDetector` where available, otherwise jsQR (loaded on demand). Accepts JSON, vCard, MECARD or `Label: value` QR contents.
- **Label text:** the photo is read in the browser with Tesseract.js, so images never reach your server. Only the text is sent to `POST /warehouse/scan/parse`, which extracts name, phone, email, address, city, state and landmark.
- Scanned fields are highlighted and always editable; nothing is booked until staff press **Book parcel**. Each parcel records whether its receiver came from `manual`, `qr` or `ocr`.

## Architecture
| Concern | Where |
|---|---|
| Roles (staff, dispatch, admin, superadmin) | `Enums/Role`, `EnsureRole` middleware, `ParcelPolicy` |
| Legal status steps only | `Enums/ParcelStatus::allowedNext`, row-locked in `ParcelService::advance` |
| No duplicate parcels | `Idempotency-Key` header + unique DB index; same key returns the first parcel |
| Audit trail | `parcel_events`, append-only (model blocks update/delete) |
| Login safety | throttling (5/min per staff ID + IP), session regeneration, deactivated users cannot sign in |
| Error handling | `bootstrap/app.php`: no stack traces or SQL shown, friendly message + reference ID matching the log line |
| Public tracking | exposes only status, timeline and destination city/state, never names or phones |

## Production checklist
- Self-host jsQR and Tesseract.js (and the `eng.traineddata` file) instead of using the CDN, then add a strict Content-Security-Policy.
- Set `trustProxies` to your proxy IPs; use Redis for cache, sessions and queues.
- Back up the database daily; keep `parcel_events` in the backup set.
