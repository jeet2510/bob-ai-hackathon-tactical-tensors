# Architecture

## Shape

Two independent applications under `src/`, plus the given dataset.

```
src/backend    Laravel 13 API — ingest, extraction, scoring, evaluation
src/frontend   React 19 + Vite SPA
src/data       The synthetic LS26 dataset (source of record)
```

No monorepo tooling: each app has its own dependency manifest and `.env`.

## Data model

The schema mirrors `src/data/schema.sql` and is aligned to the INTERPOL DVI AM (yellow) and PM (pink) form sets. Its central property is that **source records and inferences live in separate tables**, so what a human wrote is always distinguishable from what a machine read into it.

**Source of record** — written only by `dvi:ingest`, never by the pipeline:

| Table | Holds |
|---|---|
| `incident` | The event |
| `pm_case` | A recovered body: scalar facts, recovery context, primary-identifier availability, FDI dental chart |
| `am_file` | A missing-person report: identity as given, height as the family said it, reference-sample availability |
| `observation` | One free-text form box, verbatim, with language and provenance |
| `photo_evidence` | One photograph or a restricted placeholder, with quality flags and a SHA-256 |

**Inferred** — written by the pipeline, versioned so nothing is overwritten:

| Table | Holds |
|---|---|
| `observation_norm` | One structured item read from a form box, with extractor version and confidence |
| `photo_norm` | The same, read from a photograph |
| `match_run` | One execution of the scorer, with its version and config hash |
| `match_evidence` | Why a pairing scores what it does, per feature |
| `candidate` | The ranked shortlist |
| `review_decision` | What a reviewer concluded — append-only |
| `llm_cache` | Replay store, so runs reproduce without network |

Primary keys are the dataset's own string identifiers (`PM-LS26-014`), not autoincrement integers: they are what appears on the body tag and in the ground-truth files, so every URL and log line traces back to a source record.

### Two deliberate omissions

`photo_evidence.withheld_from_text` exists in the source CSV and is **never ingested** — it is derived from ground truth and exists only so evaluation can measure what a text-only pipeline missed.

Nothing in the schema stores a computed identification. The strongest state a body reaches is *referred for confirmation*.

## Pipeline

```
observation ─┬─► ExtractorInterface ──► observation_norm
photo_evidence┘  (rules-v1 | granite-v1 | oracle-gold)
                                              │
                          EvidenceScorer ◄────┘
                          per category → MatchEvidence{verdict, llr, tier, provenance}
                                              │
                          EvidenceTotal → score, band, coverage
                                              │
                          GlobalAssignment (Hungarian) → candidate.assigned
                                              │
                          ConfirmationRoute → which test to run
                                              │
                          ReconciliationReport
```

### Key classes

| Class | Responsibility |
|---|---|
| `Services\Extraction\Lexicon` | Surface forms → codebook, across English, Hindi and Marathi in both scripts |
| `Services\Extraction\Matcher` | Substring matching, clause splitting, size and weight parsing |
| `Services\Extraction\RuleExtractor` | Deterministic Stage 1 |
| `Services\Extraction\GraniteExtractor` | Stage 1 via watsonx.ai, same codebook |
| `Services\Extraction\WatsonxClient` | IAM token exchange, generation, cache write-through |
| `Services\Matching\EvidenceWeights` | The weights, with the reasoning written beside them |
| `Services\Matching\DentalChart` | FDI chart comparison — the only Tier-A comparison implemented |
| `Services\Matching\EvidenceScorer` | One body against one report, feature by feature |
| `Services\Matching\EvidenceTotal` | Aggregation, Tier-C damping, confidence band |
| `Services\Matching\GlobalAssignment` | Hungarian one-to-one over the incident |
| `Services\Matching\ConfirmationRoute` | Which primary-identifier test to run first |
| `Services\Reporting\ReconciliationReport` | The commission's working paper |
| `Services\Evaluation\*` | Ground truth, item comparison, metrics — **evaluation only** |

### Why substring matching, not tokens

Devanagari inflects by suffix (`नडगी` → `नडगीवर`), and scripts mix mid-clause. A whitespace-delimited token is the wrong unit; a surface form appearing anywhere in a clause is the right one. Lexicon map order is significant — longer forms are listed before shorter forms they contain, so `salwar top` is never read as `salwar`.

## Ground-truth isolation

The dataset ships hidden truth: gold Stage-1 output, the PM↔AM link table, and the personas the records were generated from. Reading any of it from the pipeline would make every accuracy figure the system reports a statement about itself.

Three mechanisms enforce the separation:

1. **`Services\Evaluation\GroundTruth`** is the only reader, and the namespace is the boundary.
2. **`dvi:ingest` refuses** any `--data` path containing `ground_truth`.
3. **`php artisan dvi:doctor`** greps the quarantined namespaces for any reference to the truth files or the classes that read them, and fails if it finds one. It also verifies the face-photograph policy and that oracle items are labelled and separable.

`GoldOracleExtractor` deliberately violates the spirit of this and is therefore loud about it: it writes under the version string `oracle-gold`, warns on every run, and exists solely so the scorer can be compared against the dataset's published baseline on identical input.

## API

Token auth via Laravel Sanctum. One active token per user; a 401 signs the SPA out through an axios response interceptor.

```
GET    /api/incidents
GET    /api/incidents/{incident}
GET    /api/incidents/{incident}/bodies                    triage board
GET    /api/incidents/{incident}/bodies/{pmId}             review screen
GET    /api/incidents/{incident}/profiles[/{amId}]
POST   /api/incidents/{incident}/bodies/{pmId}/decisions   append-only
GET    /api/incidents/{incident}/assignment
GET    /api/incidents/{incident}/report
GET    /api/incidents/{incident}/audit
GET    /api/incidents/{incident}/evaluation                reads ground truth
PATCH  /api/observation-items/{item}                       human correction
GET    /api/photos/{photo}/file                            403 for restricted
```

There is no update or delete route for a review decision. That is the design.

## Frontend

`IncidentLayout` loads one incident and publishes it through `useIncident()` to every nested route, so child pages read context rather than re-fetching.

| Component | Role |
|---|---|
| `EvidenceTable` | The per-feature breakdown; conflicts coloured, uninformative rows collapsible |
| `ObservationPanel` | Source text beside extracted items; inline correction |
| `PhotoStrip` | Photographs with provenance; restricted faces as a labelled absence |

Scores are displayed as **signed evidence strength** (`+12.4`), not as a percentage. This is deliberate: the dataset's own guidance is that these are ordinal evidence bands, and a percentage invites reading them as a probability of identity.

## Human in the loop

A reviewer can correct any extracted item inline. The correction writes `observation_norm.reviewed_by`, and `MatchingService::loadItems()` gives reviewed items precedence over the extractor's reading of the same box — so a domain expert's judgement propagates through every subsequent score. `dvi:extract --keep-reviewed` preserves corrections across re-runs.

## Reproducibility

- Every match run records its scorer version, extractor version and a hash of the weight configuration.
- Candidates and evidence belong to a run, so re-scoring never overwrites the evidence a past decision rested on.
- Every model call is cached by input hash and prompt version, so a run replays exactly with no network.
- Extraction is deterministic: greedy decoding, temperature 0.

## Deployment notes

- **PHP ≥ 8.4.1** — `composer.lock` resolves Symfony 8.
- MySQL in production; SQLite in tests (`phpunit.xml`) and fine for local runs.
- The dataset path is `config/dvi.php`, overridable with `DVI_DATA_PATH`. Set `DVI_GROUND_TRUTH_PATH` to a non-existent path in any deployment where truth should not be present — the evaluation screen reports itself unavailable and nothing else changes.
- watsonx is entirely optional. With no credentials the system runs on `rules-v1`, which is what every published figure was measured with.
