# Problem Statement

## Background

Disaster victim identification is the process of putting names to the dead after a mass-casualty event. INTERPOL defines the procedure: teams collect **post-mortem** data from recovered remains and **ante-mortem** data from families, then reconcile the two. Identification is only declared on a **primary identifier** — fingerprints, dental records, or DNA. Everything else, including scars, tattoos, clothing and personal effects, is secondary evidence that can support or contradict a hypothesis but never establish one.

The reconciliation step is where the time goes. It is a comparison problem of *n × m* records, under a deadline that is emotional rather than technical: families are waiting.

## The Problem

After the Balasore train collision of June 2023, which killed 293 people, NDRF teams spent roughly 72 hours manually cross-referencing over 100 unidentified bodies against incoming missing-person reports. No unified matching system existed on-ground.

The manual process has three specific failures:

1. **It does not scale.** 100 bodies against 130 reports is 13,000 comparisons. Done on paper, at a table, by people who have been awake for a day and a half.
2. **It loses the reasoning.** A decision recorded as "probable match" carries no record of which features agreed, which conflicted, and which could not be assessed — so it cannot be checked, challenged, or revisited when a new report arrives.
3. **It cannot say no.** Under pressure to produce answers, the weakest candidate on the table becomes the answer. Handing a family the wrong body is not a smaller error than handing them none.

There is a fourth problem that a system built outside India would miss. Families are interviewed in the language they speak — in the Western Ghats corridor, Marathi and Hindi, frequently transliterated into Latin script and code-mixed with English. Examiners write clinical English. Two descriptions of the same scar can share no words at all.

## Who is Affected

**Directly:** the NDRF officers, forensic examiners, police surgeons and family-liaison officers staffing an identification commission — perhaps twenty people handling hundreds of records in a temporary mortuary with intermittent power and no reliable connectivity.

**Ultimately:** the families. At Irshalwadi in 2023, 27 bodies were recovered against more than 50 people missing. Relatives of those never found waited years for a death certificate, because a death certificate requires an identification.

## Why It Matters

- **Time.** Remains degrade. Every hour of delay removes evidence — skin slippage destroys tattoos, decomposition makes eye colour unassessable — so the window in which secondary evidence is usable closes measurably.
- **Cost of error.** A misidentification means the wrong family buries a stranger, and another family never learns what happened. Both are irreversible in the way that matters.
- **Legal consequence.** Death certificates, inheritance, remarriage, and insurance all depend on formal identification. Families of the unidentified are left in a legal limbo that can last years.
- **Resource allocation.** DNA analysis is slow and expensive. Knowing *which* comparison to run first is worth more than running them all.

## Why Existing Solutions Fall Short

**Paper INTERPOL forms** define the vocabulary well — this project adopts them as its schema — but they are a recording format, not a matching system.

**Spreadsheets and ad-hoc databases**, the common field improvisation, support filtering but not weighted comparison. A filter on "male, 30–40, 170cm" returns thirty rows and ranks none of them.

**Commercial DVI software** exists but is procured in advance, deployed in advance, and trained on in advance. It is not what a district administration has on the third day of an unplanned landslide.

**Naive automated matching** is worse than it looks. A matcher that weighs every feature equally will rank a stranger who shares a build and an eye colour above the true match whose family misremembered a shirt — and, critically, will return a confident best guess for a body whose family never filed a report at all. The dataset's own baseline demonstrates this: it offers a candidate for all twelve bodies that have no partner.

**General-purpose LLM matching** fails a different test. It can read the Marathi, but it cannot show a forensic officer which sentence of which form produced which conclusion, and a system that cannot be audited cannot be used for a decision that ends in a body being released to a family.

What is missing is a tool that reads the forms as they are actually written, weighs findings by their established forensic reliability, shows its reasoning at every step, recommends which confirmation test to run — and declines to answer when the honest answer is that it does not know.
