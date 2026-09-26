# Solution Overview

## What it does

DVI Coordinator takes the two sides of an identification problem — what families describe about missing people, and what examiners observe on recovered bodies — and produces, for each body, a ranked shortlist of candidate reports with the reasoning behind each ranking and the primary-identifier test that would settle it.

It never asserts an identification. Under INTERPOL procedure, identification requires fingerprints, dental records or DNA. The value this adds is **triage**: which of 130 files to test first, and which bodies are not worth testing against anything currently on file.

## The five stages

```
Free text (en/hi/mr)  ──► Stage 1  Normalise ──► structured INTERPOL items
Photographs           ──► Stage 1b Vision    ──► (specified, not built)
                                                        │
                            Stage 2  Weigh evidence ◄───┘
                            per feature → verdict + log-likelihood
                                                        │
                            Stage 3  Global one-to-one assignment
                                                        │
                            Stage 4  Review + confirmation route
                                                        │
                            Stage 5  Reconciliation report
```

### Stage 1 — Reading the forms

The hard part, and the reason an LLM belongs here at all. The data looks like this:

| Source | Text |
|---|---|
| Family (Marathi) | `उजव्या दंडावर जन्मखूण आहे.` |
| Family (code-mixed) | `black blouse ani purple saree ghatle hote. Payat leather shoes.` |
| Examiner (English) | `Dark brown pigmented naevus, 2 mm, left dorsum of hand.` |

All of it reduces to the same 12-category codebook — `mark`, `tattoo`, `clothing`, `jewellery`, `belonging`, `id_document`, `implant`, `hair`, `eyes`, `skin_tone`, `build`, `facial_hair` — with fields for region, laterality, colour, size and assessability.

Two interchangeable extractors implement this:

- **`rules-v1`** — a deterministic multilingual lexicon in PHP. No credentials, no network, no cost. **94.6% F1** overall.
- **`granite-v1`** — IBM Granite on watsonx.ai, prompted against the same closed codebook.

Both write through a replay cache, so any run reproduces byte-identically offline.

**Stage-1 accuracy (`rules-v1`, item-level against gold):**

| | Items | Precision | Recall | F1 |
|---|---|---|---|---|
| English | 2,013 | 94.5% | 95.5% | **95.0%** |
| Marathi | 184 | 91.8% | 91.3% | **91.6%** |
| Hindi | 105 | 92.3% | 91.4% | **91.9%** |
| **All** | **2,302** | **94.2%** | **95.0%** | **94.6%** |

The weakest category is clothing at 90.2%, and most of that residual is irreducible: families frequently do not state footwear colour, so it cannot be recovered from their text at all.

### Stage 2 — Weighing evidence

Each pairing is scored feature by feature. Every feature produces one of four verdicts, and the distinctions are forensic, not cosmetic:

| Verdict | Meaning | Effect |
|---|---|---|
| `match` | The two sides agree | Positive weight |
| `conflict` | They disagree | Negative weight |
| `missing` | One side recorded nothing | **Zero** — silence is not disagreement |
| `excluded` | Present but unassessable (burnt, decomposed) | **Zero** — being unable to look is not evidence of absence |

Weights follow INTERPOL reliability tiers:

- **Tier A — primary identifiers.** Dental chart comparison (+9), implant device serial (+8). Decisive, and available on about half this dataset's cases on both sides.
- **Tier B — strong secondary.** Tattoo design and site (+3.4), distinguishing marks with laterality (+2.0), implant type (+3.2).
- **Tier C — supportive.** Clothing, jewellery, belongings, build, hair, eyes, skin tone, sex, age, height. Individually near-worthless — most people are of medium build with brown eyes.

Three design decisions did most of the work:

**Conflicts are weighted far more gently than matches.** In this dataset roughly a fifth of families misremember clothing and one in twelve flips left for right. A system that punished those symmetrically would reject true matches, which is the one failure a DVI tool must not have. False exclusions are **0** across every run.

**A garment match with the wrong colour is positive evidence.** Reading it as a conflict was the single largest source of wrong first choices; correcting it moved first-choice accuracy by six pairs. A relative who says "kurta and salwar" and misremembers the shade has still told you something true.

**Supportive evidence is damped, not capped.** Tier-C contributions pass through `tanh`, so accumulating demographic agreement cannot outweigh a scar, while the ordering among weakly-evidenced pairings is preserved. A hard ceiling was tried first and cost four pairs of Recall@3, because pairings that hit the ceiling scored identically and their ordering collapsed.

**A pairing with no individuating evidence is never a candidate**, whatever it scores. Agreeing that a body is a man in his thirties of medium build describes thousands of people.

### Stage 3 — Solving the incident as a whole

Ranking each body independently produces contradictions: two near-identical people both rank first against the same report, and one of those answers is certainly wrong. Since a person can be in one place, the correct reading is the set of pairings maximising total evidence under a one-to-one constraint — an assignment problem, solved with the Hungarian algorithm. It is advisory, shown beside the independent ranking, and never overrides a reviewer.

### Stage 4 — Confirmation route

From `pm_case.{dna,dental,print}_status` against `am_file.{dna_reference_type,dental_records_available,prints_on_file}`, the system names the fastest viable primary-identifier test — or reports that no route exists, which tells a coordinator a sample needs collecting before any amount of comparison will help.

### Stage 5 — Reconciliation report

A printable working paper per body: outcome, ranked candidates with key evidence, recommended route, reviewer decisions, and — the sections that matter most — bodies with no credible candidate, bodies with no viable confirmation route, and families still waiting.

## Results

The dataset publishes a naive baseline measured on its gold normalisation. A fair comparison needs the same input, so the system's scorer is also run on gold items:

**Scorer isolated — like-for-like with the baseline**

| Metric | This system | Naive baseline | |
|---|---|---|---|
| Correct first choice | **75 / 86** | 72 / 86 | +3 |
| True pair in top three | **84 / 86** | 82 / 86 | +2 |
| Incident-wide assignment | **76 / 86** | 73 / 86 | +3 |
| Correctly refused | **4 / 12** | 0 / 12 | +4 |
| True pair never offered | **0** | 0 | same |

**End to end — the honest system figure**

| Split | Pairs | First choice | Top three | Assignment | Correct refusals | False refusals |
|---|---|---|---|---|---|---|
| All | 86 | 69 (80.2%) | 81 | 75 | 6 / 12 | 8 |
| Dev | 64 | 49 (76.6%) | 59 | 54 | 6 / 12 | 6 |
| **Holdout** | 22 | **20 (90.9%)** | 22 | 21 | — | 2 |

The end-to-end figure is lower than the oracle figure by exactly what Stage 1 gets wrong. The holdout split was never used to choose weights.

**Where the hand-crafted hard cases land** (scorer isolated): `clear` 6/6, `decoy` 4/4, `doc_trap` 2/2, `pm_change` 2/2 — all at first choice. The baseline manages 1/4 on `family_error`.

**On refusal.** The system reports **false refusals** beside correct ones, because a refusal metric without one can be maximised by refusing everything. 4 correct refusals cost 4 false ones. The threshold was chosen on the dev split at the point where correct refusals stopped improving.

## What is not built

- **The vision stage.** Photographs are ingested, displayed with provenance and quality flags, and correctly excluded from matching. The extractor that would turn them into items is specified but not implemented. The dataset withholds a fact from the text in 42 photographs specifically to make this measurable, so there is known headroom.
- **`granite-v1` against the live API.** The adapter, prompt and cache are written; no watsonx credentials were available to exercise them. Every number above comes from `rules-v1`.

## Safety properties

These are enforced in code and checked by `php artisan dvi:doctor`:

1. **Ground truth is quarantined.** No file under `app/Http`, `app/Services/Extraction`, `app/Services/Matching`, `app/Services/Reporting` or `app/Models` may reference it. The one sanctioned exception is the evaluation controller.
2. **Faces are never matched or displayed.** All 159 facial photographs carry no file and are refused by the API, not merely hidden by the interface.
3. **Names are never scored.** A name on a form is not evidence about a body. Identity documents are weighted as a lead only, and when the name on a recovered card disagrees with the report, the discrepancy is shown to the reviewer rather than folded into a number.
4. **Decisions are append-only.** Changing a conclusion adds a row; the history survives.
