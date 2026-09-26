# DVI Coordinator

**Disaster victim identification triage that knows when to say "I don't know".**

---

## Team

| Field | Value |
|---|---|
| **Team Name** | Tactical Tensors |
| **Track** | AI |
| **Team Lead** | Neelanjan Chakraborty — neelanjan.careers@gmail.com |
| **Members** | _(add remaining members before submitting)_ |

---

## Problem Statement

After the Balasore train collision in June 2023, NDRF teams spent 72 hours manually cross-referencing more than 100 unidentified bodies against missing-person reports. There was no unified matching system on the ground. Families waited at mortuary gates while officers carried paper between tables, comparing a relative's description of a scar against an examiner's notes — by hand, by eye, across a language barrier, under exhaustion.

The people who suffer are the families, and the officers who must not get it wrong.

---

## Solution

A coordination tool that takes **ante-mortem** profiles (what families describe) and **post-mortem** records (what examiners observe), normalises both into a shared INTERPOL codebook, cross-references every body against every report, and produces a ranked shortlist with **the reason for each ranking** and **the primary-identifier test to run next**.

It is decision support, not identification. INTERPOL requires a match on fingerprints, dental records or DNA before remains are released, and the system never claims otherwise. Its job is to tell a team which of 130 files to test first — and to say plainly when none of them is worth testing.

---

## Key Features

- **Reads the forms as they were actually written.** Families were interviewed in Marathi and Hindi, sometimes transliterated into Latin script and mixed with English mid-sentence (`"black blouse ani purple saree ghatle hote"`); examiners wrote clinical English. Both are normalised into the same 12-category INTERPOL item schema at **94.6% F1** — 91.6% on Marathi, 91.9% on Hindi.
- **Explains every point of every score.** Each pairing produces a per-feature evidence table: what the examiner recorded, what the family reported, the verdict, and the log-likelihood contribution. Conflicts stay visible in red rather than being averaged away.
- **Weighs evidence by INTERPOL reliability tier.** A matching dental chart or implant serial outranks any amount of "medium build, brown eyes". A supportive-evidence ceiling makes that structural, not incidental.
- **Refuses.** Twelve bodies in the dataset have no ante-mortem partner at all. The published baseline offers a best guess for every one of them; this system correctly declines on **4 of 12** with the scorer isolated, and reports its **false refusals** alongside so the refusal rate cannot be gamed.
- **Solves the incident, not one body at a time.** A Hungarian one-to-one assignment resolves look-alike clusters that tie under independent ranking.
- **Recommends the confirmation route.** From what each side actually holds — a completed post-mortem chart against dental records on file, a viable DNA sample against a family reference — it names the fastest test that can settle the pairing, or says no route exists.
- **Ships an evaluation lab.** A screen in the app measures the system against the dataset's hidden ground truth and its published baseline, and separates scorer quality from extraction quality so neither flatters the other.

---

## Results

The dataset ships a naive baseline measured on its gold normalisation. Comparing like-for-like on the same input:

| Metric | This system | Naive baseline | |
|---|---|---|---|
| Correct first choice | **75 / 86** | 72 / 86 | **+3** |
| True pair in top three | **84 / 86** | 82 / 86 | **+2** |
| Incident-wide assignment | **76 / 86** | 73 / 86 | **+3** |
| Correctly refused (no partner exists) | **4 / 12** | 0 / 12 | **+4** |
| True pair never offered | **0** | 0 | same |

**End to end**, with real extraction rather than gold items, the system reaches 69/86 (80.2%) first-choice and 81/86 in the top three — the honest figure, and it is lower by exactly what Stage 1 gets wrong. On the **holdout split**, which was never used to choose weights, first-choice accuracy is 20/22 (90.9%).

Full breakdowns by case type, injected difficulty and language are in the app's evaluation lab and in [`docs/solution-overview.md`](docs/solution-overview.md).

---

## Tech Stack

| Category | Technologies |
|---|---|
| **Languages** | PHP 8.4+, TypeScript |
| **Frameworks** | Laravel 13, React 19, Vite |
| **IBM Technologies** | IBM Bob (built with), watsonx.ai, IBM Granite |
| **Databases** | MySQL (SQLite for tests and local runs) |
| **Other** | Laravel Sanctum, PHPUnit, Pint, oxlint |

IBM Bob was the development partner for this build — planning the re-architecture, generating migrations and scaffolding. **watsonx.ai Granite** is the runtime model for Stage-1 normalisation, behind an adapter with a deterministic rules fallback, so the whole system runs offline with no credentials.

---

## Repository Structure

```
├── src/
│   ├── backend/    Laravel API, extraction, scoring, evaluation
│   ├── frontend/   React dashboard
│   └── data/       The synthetic LS26 dataset (given)
├── docs/           Problem, solution, architecture, setup
├── demo/           Screenshots and demo links
└── submission.yaml
```

---

## How to Run

```bash
git clone <this-repo> && cd bob-ai-hackathon-tactical-tensors

# --- Backend -------------------------------------------------------------
cd src/backend
export PATH="/opt/homebrew/opt/php@8.5/bin:$PATH"   # needs PHP >= 8.4.1
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --force

php artisan dvi:ingest                       # 98 bodies, 130 reports, 1,231 form boxes
php artisan dvi:extract                      # Stage 1, deterministic, no credentials needed
php artisan dvi:match                        # cross-reference + global assignment
php artisan dvi:evaluate                     # score against ground truth
php artisan serve

# --- Frontend ------------------------------------------------------------
cd ../frontend
npm install && cp .env.example .env
npm run dev
```

Sign in as `coordinator@dvi.test` / `password`. Full instructions, including the optional watsonx setup, are in [`docs/setup-guide.md`](docs/setup-guide.md).

---

## Demo

| Artifact | Link |
|---|---|
| Demo Video | [demo/demo-video-link.txt](demo/demo-video-link.txt) |
| Live Demo | [demo/live-demo-url.txt](demo/live-demo-url.txt) |
| Screenshots | [demo/screenshots/](demo/screenshots/) |
| Presentation | [presentation/](presentation/) |

---

## Known Limitations

- **The dataset is synthetic and small.** Every figure here measures the method on 98 bodies of generated data, not real-world accuracy. Scores are ordinal evidence bands, never calibrated probabilities.
- **The vision stage is not implemented.** Photographs are ingested, displayed with provenance, and correctly excluded from matching, but the vision extractor that would turn them into items is specified and not built. The dataset hides a fact in 42 photographs that text-only matching cannot see, so there is measurable headroom here.
- **`granite-v1` is wired but unexercised.** No watsonx credentials were available during the build, so the Granite extractor has not been run against the live API. The rules extractor is what every number in this README was measured with.
- **Refusal is the weakest area.** 4 of 12 correct refusals, at a cost of 4 false refusals out of 86. Bodies whose evidence coincidentally matches a stranger remain hard to decline.
- **The UI has not been visually verified in a browser.** It typechecks, builds, and every endpoint it calls is tested, but no screenshot pass was done.
- **Weights are hand-set, informed by INTERPOL tiers and tuned on the dev split.** They are not learned, and with 64 development pairs they could not responsibly be.

---

## What We're Most Proud Of

The **evaluation lab**, and what it forced.

Most of this project's real work went into measuring it honestly rather than making it look good. Building the false-refusal metric immediately showed that the refusal rate could be gamed. Separating oracle from end-to-end runs showed that an early "we beat the baseline" claim was comparing perfect input against imperfect. A hard cap on supportive evidence looked principled and quietly destroyed Recall@3, so it became a soft one.

The scoring rule we are most pleased with came from reading failures, not from theory: a family who says "kurta and salwar" but misremembers the colours has still told you something true. Treating that as a conflict rather than partial agreement was the single largest source of wrong answers, and fixing it moved first-choice accuracy by six pairs.

Judges should look at `src/backend/app/Services/Matching/EvidenceWeights.php` and `EvidenceTotal.php`, where the reasoning is written down beside the numbers, and at the evaluation lab screen, where the system states its own error rate next to the baseline it is claiming to beat.
