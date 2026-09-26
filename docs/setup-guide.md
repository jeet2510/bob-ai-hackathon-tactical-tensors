# Setup Guide

## Prerequisites

| Requirement | Notes |
|---|---|
| **PHP ≥ 8.4.1** | `composer.lock` resolves Symfony 8, which requires it. PHP 8.3 will fail to install dependencies. |
| Composer | |
| Node ≥ 20 | |
| MySQL 8 | Optional — SQLite works for everything including the full dataset. |

On macOS with Homebrew:

```bash
brew install php@8.5
export PATH="/opt/homebrew/opt/php@8.5/bin:$PATH"
php -v          # must report 8.4.1 or newer
```

If `composer install` reports platform-requirement errors and leaves no `vendor/`, the PHP version is the cause — `artisan` will then fail with a missing autoloader.

---

## Backend

```bash
cd src/backend

composer install
cp .env.example .env
php artisan key:generate
```

### Database

**MySQL** (as configured in `.env.example`):

```bash
mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS ibm_bob_hackathon;"
php artisan migrate --force
```

**SQLite** — no server required:

```bash
touch database/dvi.sqlite
php artisan migrate --force \
  --env=local --database=sqlite \
  # or set DB_CONNECTION=sqlite and DB_DATABASE=/absolute/path/dvi.sqlite in .env
```

### Load the dataset and run the pipeline

```bash
php artisan db:seed                  # creates the coordinator login
php artisan dvi:ingest               # 1 incident, 98 bodies, 130 reports, 1,231 form boxes, 367 photos
php artisan dvi:extract              # Stage 1 — deterministic, no credentials needed
php artisan dvi:match                # cross-reference + global assignment
```

Expected output from `dvi:ingest`:

```
incident 1 · pm_case 98 · am_file 130 · observation 1,231 · photo_evidence 367
208 photos are matchable; 159 are restricted display-only and will never be scored.
```

`dvi:match` reports 12,740 comparisons in well under a second.

### Verify

```bash
php artisan dvi:doctor               # ground-truth isolation, face policy, run integrity
php artisan dvi:evaluate             # accuracy against hidden ground truth
php artisan dvi:evaluate:extraction  # Stage-1 precision/recall per category
php artisan test                     # 20 tests
```

`dvi:evaluate` should report 69/86 first-choice end-to-end, with zero false exclusions.

### Serve

```bash
php artisan serve                    # http://127.0.0.1:8000
```

---

## Frontend

```bash
cd src/frontend

npm install
cp .env.example .env                 # VITE_API_URL=http://127.0.0.1:8000/api
npm run dev
```

Vite binds to IPv6 `localhost` on macOS — open **`http://localhost:5173`**, not `127.0.0.1`.

Sign in with:

```
coordinator@dvi.test
password
```

---

## Optional: IBM watsonx.ai

The system runs fully without this. With credentials, IBM Granite can drive Stage-1 normalisation instead of the rules extractor.

Add to `src/backend/.env`:

```env
WATSONX_API_KEY=your-ibm-cloud-api-key
WATSONX_PROJECT_ID=your-watsonx-project-id
WATSONX_URL=https://us-south.ml.cloud.ibm.com
WATSONX_MODEL=ibm/granite-3-3-8b-instruct
```

Then:

```bash
php artisan dvi:extract --extractor=granite-v1
php artisan dvi:match --extractor=granite-v1
php artisan dvi:evaluate
```

Completions are written to the `llm_cache` table, so a second run costs nothing and works offline. Both extractors' items coexist in the database under their own version strings, and the evaluation lab compares them side by side.

> **Not yet exercised.** No watsonx credentials were available during development, so this path is written and unit-reachable but has not been run against the live API. Every published figure comes from `rules-v1`.

---

## Reproducing the published results

```bash
# End-to-end — the honest system figure
php artisan dvi:extract --extractor=rules-v1 --fresh
php artisan dvi:match   --extractor=rules-v1
php artisan dvi:evaluate                      # 69/86 first choice
php artisan dvi:evaluate --split=holdout      # 20/22

# Scorer isolated — like-for-like with the dataset's published baseline.
# Reads ground truth. Never present this as an end-to-end system result.
php artisan dvi:extract --extractor=oracle-gold --fresh
php artisan dvi:match   --extractor=oracle-gold
php artisan dvi:evaluate                      # 75/86 vs baseline 72/86
```

Add `--misses` to list every pairing that was not ranked first, with its case type and injected difficulty.

---

## Configuration reference

| Variable | Default | Purpose |
|---|---|---|
| `DVI_DATA_PATH` | `../data/data` | Dataset CSVs and images, relative to `src/backend` |
| `DVI_GROUND_TRUTH_PATH` | `../data/data/ground_truth` | Evaluation only. Point elsewhere to disable the evaluation screen. |
| `WATSONX_*` | unset | Optional Granite extraction |
| `VITE_API_URL` | `http://127.0.0.1:8000/api` | Frontend → backend |

---

## Troubleshooting

**`Could not open input file: artisan`** — you are not in `src/backend`.

**`composer install` fails on platform requirements** — PHP is older than 8.4.1. See Prerequisites.

**`No incident found. Run dvi:ingest first.`** — the database is empty. Note that `php artisan test` uses `RefreshDatabase`, and if you have exported `DB_DATABASE` in your shell it will override `phpunit.xml` and wipe that database. Run tests in a clean shell, or re-run `dvi:ingest` afterwards.

**Evaluation screen reports unavailable** — `DVI_GROUND_TRUTH_PATH` does not resolve. This is intentional in deployments without the dataset's truth files; nothing else is affected.

**Frontend shows a network error** — check `VITE_API_URL` and that `php artisan serve` is running. A 401 signs you out automatically, which usually means the token was cleared server-side.
