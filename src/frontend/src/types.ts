export type ConfidenceBand = "high" | "moderate" | "low" | "no_credible_candidate";

export type Verdict = "match" | "conflict" | "missing" | "excluded";

export type Tier = "A" | "B" | "C";

export type DecisionKind =
    | "recommend_confirm_test"
    | "reject"
    | "defer"
    | "no_credible_candidate";

export interface Incident {
    incident_id: string;
    name: string;
    incident_date: string;
    district: string | null;
    synthetic: boolean;
    pm_cases_count?: number;
    am_files_count?: number;
}

export interface MatchRun {
    run_id: string;
    incident_id: string;
    scorer_version: string;
    extractor_version: string;
    config_hash: string | null;
    assignment_applied: boolean;
    stats: Record<string, number> | null;
    created_at: string;
}

/** Grouped counts behind the dashboard's charts — never individual rows. */
export interface IncidentBreakdowns {
    body_condition: Record<string, number>;
    sex: Record<string, number>;
    dna_status: Record<string, number>;
    dental_status: Record<string, number>;
    print_status: Record<string, number>;
    recovered_by_day: Record<string, number>;
    photos_by_modality: Record<string, number>;
    photos_matchable: number;
    photos_restricted: number;
}

/** One matchable photograph in the incident-wide gallery. Restricted face
 *  photographs are never returned by this endpoint at all. */
export interface GalleryPhoto {
    photo_id: string;
    record_type: "PM" | "AM";
    record_id: string;
    modality: string;
    view: string | null;
    captured_at: string | null;
    source_type: string | null;
    quality_flags: string[];
    url: string;
}

export interface IncidentStats {
    bodies: number;
    profiles: number;
    matched: boolean;
    with_credible_candidate?: number;
    no_credible_candidate?: number;
    high?: number;
    moderate?: number;
    low?: number;
    reviewed?: number;
    awaiting_review?: number;
    no_confirmation_route?: number;
    assigned?: number;
}

/** What primary-identifier test to run first, and what is blocking the others. */
export interface ConfirmationRoute {
    route: string | null;
    label: string;
    available: Array<{ route: string; label: string; detail: string }>;
    blockers: string[];
}

export interface TopCandidate {
    am_id: string;
    reported_name: string | null;
    score: number;
    confidence_band: ConfidenceBand;
    coverage: number;
    assigned: boolean;
    recommended_route: ConfirmationRoute | null;
}

export interface BodyRow {
    pm_id: string;
    sex: string | null;
    age_estimate: string | null;
    height_cm: number | null;
    body_condition: string | null;
    found_place: string | null;
    found_at: string | null;
    lat: number | null;
    lon: number | null;
    degraded: boolean;
    dental_status: string | null;
    dna_status: string | null;
    print_status: string | null;
    top_candidate: TopCandidate | null;
    decision: { decision: DecisionKind; reviewer: string; note: string | null; decided_at: string } | null;
}

export interface PmCase {
    pm_id: string;
    sex: string | null;
    age_min: number | null;
    age_max: number | null;
    height_cm: number | null;
    body_condition: string | null;
    found_place: string | null;
    found_at: string | null;
    dna_status: string | null;
    dental_status: string | null;
    print_status: string | null;
    dental_chart_fdi: string | null;
}

export interface AmFile {
    am_id: string;
    incident_id?: string;
    reported_name: string;
    sex: string | null;
    age: number | null;
    height_text: string | null;
    height_cm_reported: number | null;
    last_seen_at: string | null;
    last_seen_place: string | null;
    reported_by_relation: string | null;
    occupation: string | null;
    marital_status: string | null;
    dna_reference_type: string | null;
    dental_records_available: boolean;
    dentist_contact: string | null;
    prints_on_file: boolean;
    id_sources: string | null;
    dental_chart_fdi: string | null;
    reporter_name: string | null;
    reporter_phone: string | null;
    reporter_address: string | null;
    interviewing_officer: string | null;
    interviewed_at: string | null;
    source_type: string | null;
}

/** One structured item read out of a form box or a photograph. */
export type NormItem = Record<string, unknown> & { category: string };

export interface NormalisedItem {
    id: number;
    item: NormItem;
    confidence: number | null;
    extractor_version: string;
    reviewed_by: string | null;
}

export interface ObservationRow {
    obs_id: string;
    record_type: "PM" | "AM";
    record_id: string;
    category: string;
    form_field: string | null;
    raw_text: string;
    lang: string;
    source_type: string | null;
    recorded_by: string | null;
    reliability_note: string | null;
    items?: NormalisedItem[];
}

export interface PhotoRow {
    photo_id: string;
    modality: string;
    view: string | null;
    captured_at: string | null;
    source_type: string | null;
    quality_flags: string[];
    use_policy: string;
    restricted: boolean;
    reliability_note: string | null;
    sha256: string | null;
    url: string | null;
}

export interface EvidenceRow {
    id: number;
    category: string;
    tier: Tier | null;
    verdict: Verdict;
    llr: number;
    pm_obs_id: string | null;
    am_obs_id: string | null;
    pm_item: NormItem | null;
    am_item: NormItem | null;
    rationale: string;
}

export interface CandidateDetail {
    am_id: string;
    rank: number;
    score: number;
    confidence_band: ConfidenceBand;
    coverage: number;
    assigned: boolean;
    recommended_route: ConfirmationRoute | null;
    profile: AmFile | null;
    evidence: EvidenceRow[];
}

export interface ReviewDecisionRow {
    id: number;
    pm_id: string;
    am_id: string | null;
    reviewer: string;
    decision: DecisionKind;
    note: string | null;
    decided_at: string;
}

export interface BodyDetail {
    body: PmCase;
    observations: ObservationRow[];
    photos: PhotoRow[];
    candidates: CandidateDetail[];
    decisions: ReviewDecisionRow[];
    run: MatchRun | null;
}

/**
 * One of Gemini's top-3, refining the body's existing rules-based shortlist
 * with photo evidence the deterministic scorer never sees. Advisory only —
 * score/coverage/recommended_route are carried over unchanged from the
 * rules-based candidate for the same pairing; only confidence_band,
 * ai_rationale and ai_visual_notes come from Gemini itself.
 */
export interface GeminiEvidenceSummaryRow {
    category: string;
    verdict: Verdict;
    rationale: string;
}

export interface GeminiMatchCandidate {
    am_id: string;
    rank: number;
    score: number;
    confidence_band: ConfidenceBand;
    coverage: number;
    recommended_route: ConfirmationRoute | null;
    ai_rationale: string | null;
    ai_visual_notes: string | null;
    profile: AmFile | null;
    /** The deterministic scorer's own matched evidence for this pairing, compacted for a quick read. */
    evidence_summary: GeminiEvidenceSummaryRow[];
}

export interface GeminiMatchResult {
    success: boolean;
    ai_available: boolean;
    run_id: string | null;
    /** A short plain-language synthesis across all returned candidates. */
    summary: string | null;
    candidates: GeminiMatchCandidate[];
    reason: string | null;
}

export type SectionOutcome =
    | "referred_for_confirmation"
    | "awaiting_review"
    | "no_credible_candidate";

export interface ReconciliationReport {
    incident: Pick<Incident, "incident_id" | "name" | "incident_date" | "district" | "synthetic">;
    generated_at: string;
    run: Pick<MatchRun, "run_id" | "scorer_version" | "extractor_version" | "created_at"> | null;
    summary: {
        bodies: number;
        profiles: number;
        referred_for_confirmation: number;
        awaiting_review: number;
        no_credible_candidate: number;
        no_confirmation_route: number;
        families_still_waiting: number;
    };
    sections: Array<{
        body: Record<string, string | number | null>;
        outcome: SectionOutcome;
        decision: { decision: DecisionKind; am_id: string | null; reviewer: string; note: string | null; decided_at: string } | null;
        candidates: Array<{
            am_id: string;
            rank: number;
            score: number;
            confidence_band: ConfidenceBand;
            coverage: number;
            assigned: boolean;
            recommended_route: ConfirmationRoute | null;
            reported_name: string | null;
            reported_by_relation: string | null;
            key_evidence: Array<Pick<EvidenceRow, "category" | "tier" | "verdict" | "llr" | "rationale">>;
        }>;
    }>;
    outstanding_profiles: Array<{
        am_id: string;
        reported_name: string;
        sex: string | null;
        age: number | null;
        reported_by_relation: string | null;
        last_seen_place: string | null;
        dna_reference_type: string | null;
        dental_records_available: boolean;
    }>;
    disclaimer: string;
}

export interface EvaluationMetrics {
    run_id: string;
    split: string;
    extractor_version: string;
    scorer_version: string;
    true_pairs: number;
    recall_at_1: number;
    recall_at_3: number;
    recall_at_1_pct: number;
    recall_at_3_pct: number;
    false_exclusions: number;
    assignment_correct: number;
    assignment_made: number;
    refusals_correct: number;
    false_refusals: number;
    refusal_total: number;
    per_case_type: Record<string, { n: number; top1: number; top3: number }>;
    per_tag: Record<string, { n: number; top1: number; top3: number }>;
    misses: Array<{
        pm_id: string;
        expected: string;
        got: string | null;
        true_rank: number | null;
        case_type: string;
        tags: string;
    }>;
}

export interface Prf {
    gold: number;
    predicted: number;
    matched: number;
    precision: number;
    recall: number;
    f1: number;
}

export interface EvaluationPayload {
    baseline: {
        recall_at_1: number;
        recall_at_3: number;
        assignment: number;
        true_pairs: number;
        refusals_correct: number;
        refusal_total: number;
        false_exclusions: number;
    };
    runs: Record<string, Record<string, EvaluationMetrics>>;
    extraction: Record<
        string,
        { overall: Prf; by_lang: Record<string, Prf>; by_category: Record<string, Prf> }
    >;
    notes: string[];
}

export interface AssignmentRow {
    pm_id: string;
    ranked_first: { am_id: string; reported_name: string | null; score: number; confidence_band: ConfidenceBand } | null;
    assigned: { am_id: string; reported_name: string | null; score: number; confidence_band: ConfidenceBand } | null;
    differs: boolean;
}

/* ------------------------------------------------------- Incident Pipeline */
/* Option lists mirror BodyController::store()'s validation enums exactly.
 * Where the paper form offers a choice the INTERPOL codebook has no code
 * for, `value` is a plain literal (still stored, just not scorable) — see
 * PmCaseIntake's *_MAP constants for the authoritative mapping. */

export const BODY_CONDITION_OPTIONS = [
    { value: "Fresh", label: "Fresh" },
    { value: "Slight decomp.", label: "Slight decomposition" },
    { value: "Moderate decomp.", label: "Moderate decomposition" },
    { value: "Advanced decomp.", label: "Advanced decomposition" },
    { value: "Burnt", label: "Burnt" },
];

export const PM_SEX_OPTIONS = [
    { value: "M", label: "Male" },
    { value: "F", label: "Female" },
];

export const BUILD_OPTIONS = [
    { value: "Slim", label: "Slim" },
    { value: "Medium", label: "Medium" },
    { value: "Heavy", label: "Heavy" },
    { value: "Muscular", label: "Muscular" },
    { value: "Obese", label: "Obese" },
];

export const SKIN_TONE_OPTIONS = [
    { value: "Fair", label: "Fair" },
    { value: "Wheatish", label: "Wheatish" },
    { value: "Dark", label: "Dark" },
    { value: "Other", label: "Other" },
];

export const HAIR_COLOUR_OPTIONS = [
    { value: "Black", label: "Black" },
    { value: "Brown", label: "Brown" },
    { value: "Grey", label: "Grey" },
    { value: "White", label: "White" },
    { value: "Dyed", label: "Dyed" },
];

export const HAIR_LENGTH_OPTIONS = [
    { value: "Bald", label: "Bald" },
    { value: "Short", label: "Short" },
    { value: "Medium", label: "Medium" },
    { value: "Long", label: "Long" },
];

export const EYE_COLOUR_OPTIONS = [
    { value: "Black", label: "Black" },
    { value: "Brown", label: "Brown" },
    { value: "Hazel", label: "Hazel" },
    { value: "Grey", label: "Grey" },
];

export const FACIAL_HAIR_OPTIONS = [
    { value: "Clean-shaven", label: "Clean-shaven" },
    { value: "Moustache", label: "Moustache" },
    { value: "Beard", label: "Beard" },
    { value: "Stubble", label: "Stubble" },
    { value: "Not applicable", label: "Not applicable" },
];

export const DNA_STATUS_OPTIONS = [
    { value: "sample_taken", label: "Taken" },
    { value: "degraded", label: "Degraded" },
    { value: "not_collected", label: "Not collected" },
];

export const DENTAL_STATUS_OPTIONS = [
    { value: "chart_completed", label: "Chart completed" },
    { value: "not_examined", label: "Not examined" },
    { value: "unsuitable", label: "Unsuitable" },
];

export const PRINT_STATUS_OPTIONS = [
    { value: "usable", label: "Usable" },
    { value: "unusable", label: "Unusable" },
    { value: "not_taken", label: "Not taken" },
];

export const FEATURE_TYPE_OPTIONS = [
    { value: "tattoo", label: "Tattoo" },
    { value: "mark", label: "Mark" },
    { value: "scar", label: "Scar" },
    { value: "mole", label: "Mole" },
    { value: "birthmark", label: "Birthmark" },
    { value: "burn", label: "Burn" },
    { value: "deformity", label: "Deformity" },
    { value: "amputation", label: "Amputation" },
    { value: "piercing", label: "Piercing" },
    { value: "implant", label: "Implant" },
    { value: "other", label: "Other" },
];

export const SIDE_OPTIONS = [
    { value: "L", label: "Left" },
    { value: "R", label: "Right" },
    { value: "C", label: "Centre" },
];

export const CLOTHING_SLOTS = ["Headwear", "Upper body", "Lower body", "Footwear", "Other"];

export const JEWELLERY_KIND_OPTIONS = [
    { value: "jewellery", label: "Jewellery" },
    { value: "belonging", label: "Belonging" },
];

export const PHOTO_MODALITY_OPTIONS = [
    { value: "Body diagram", label: "Body diagram" },
    { value: "Tattoo/mark", label: "Tattoo/mark" },
    { value: "Clothing", label: "Clothing" },
    { value: "Face (restr.)", label: "Face (restr.)" },
    { value: "Other", label: "Other" },
];

export const QUALITY_FLAG_OPTIONS = [
    { value: "Blur", label: "Blur" },
    { value: "Low light", label: "Low light" },
    { value: "Noise", label: "Noise" },
    { value: "None", label: "None" },
];

/** Closed vocabularies mirroring backend Lexicon.php — offered as prefill
 * suggestions (via a datalist) on free-text fields so manually-entered data
 * lands in the same vocabulary the matching engine reads, without forcing a
 * rigid dropdown on fields that legitimately need free text too. */

export const REGION_SUGGESTIONS = [
    "upper arm", "forearm", "shin", "cheek", "forehead", "shoulder", "wrist", "knee",
    "thigh", "abdomen", "chest", "back", "chin", "neck", "hand", "foot", "trunk and thighs",
];

export const GARMENT_SUGGESTIONS = [
    "salwar top", "t-shirt", "blouse", "kurta", "hoodie", "shirt",
    "track pants", "saree", "salwar", "jeans", "trousers", "leggings", "shorts", "lungi",
    "rubber boots", "leather shoes", "sports shoes", "chappals", "sandals",
];

export const CLOTHING_COLOUR_SUGGESTIONS = [
    "white", "blue", "green", "grey", "brown", "black", "red", "purple", "pink", "yellow", "orange",
];

export const JEWELLERY_ITEM_SUGGESTIONS = [
    "mangalsutra", "toe ring", "nose stud", "earrings", "bangles", "kada", "watch", "thread", "chain", "ring",
];

export const BELONGING_SUGGESTIONS = [
    "mobile phone", "earphones", "spectacles", "backpack", "handbag", "umbrella", "wallet", "keys",
];

export const ID_DOCUMENT_TYPE_SUGGESTIONS = ["voter-style", "office", "school", "college"];

/** One examined tooth in the FDI dental chart (Section E). */
export interface DentalChartEntry {
    tooth: number;
    code: "M" | "F" | "C" | "R";
}

/** Section F: one row of the body-diagram distinguishing-features table. */
export interface DistinguishingFeatureRow {
    type: string;
    description: string;
    region: string;
    side: "L" | "R" | "C" | "";
    serial_no: string;
    photo_ref: string;
}

/** Section G: one of the five fixed clothing slots. */
export interface ClothingRow {
    slot: string;
    garment: string;
    colour: string;
}

/** Section H: one jewellery or personal-effect row. */
export interface JewelleryEffectRow {
    kind: "jewellery" | "belonging";
    item: string;
    material_description: string;
}

/** Section I: one identity document found on or with the body. */
export interface IdDocumentRow {
    document_type: string;
    id_last4: string;
    name_on_document: string;
    note: string;
}

/** Section J: one photo evidence log entry. */
export interface PhotoLogRow {
    label: string;
    modality: string;
    view: string;
    quality_flags: string[];
    upload_ref: string;
}

/** The whole post-mortem intake form, sections A–K, as local editable state. */
export interface NewBodyFormState {
    pm_id: string;
    examiner_name: string;
    examiner_role: string;
    found_at: string;
    found_place: string;
    lat: string;
    lon: string;
    body_condition: string;
    sex: string;
    age_min: string;
    age_max: string;
    height_cm: string;
    build: string;
    skin_tone: string;
    skin_tone_other: string;
    hair_colour: string;
    hair_length: string;
    eye_colour: string;
    facial_hair: string;
    dna_status: string;
    dental_status: string;
    print_status: string;
    dental_chart: DentalChartEntry[];
    distinguishing_features: DistinguishingFeatureRow[];
    clothing: ClothingRow[];
    jewellery_effects: JewelleryEffectRow[];
    id_documents: IdDocumentRow[];
    photo_log: PhotoLogRow[];
    notes: string;
    signature_note: string;
    completed_at: string;
    chain_of_custody_hash: string;
    source: "manual" | "pdf_scan" | "live_scan" | "mixed";
}

/* ----------------------------------------------------- Ante-mortem report */

/** value matches the exact strings already in am_file.csv/AmFileIntake::validationRules() — "single" is the DB value for the paper form's "Unmarried" checkbox. */
export const MARITAL_STATUS_OPTIONS = [
    { value: "single", label: "Unmarried" },
    { value: "married", label: "Married" },
    { value: "widowed", label: "Widowed" },
    { value: "divorced_separated", label: "Divorced/separated" },
];

/** No explicit "none" entry — the SelectField's own placeholder ("None available") covers it. */
export const DNA_REFERENCE_OPTIONS = [
    { value: "family_reference", label: "Family member can give one" },
    { value: "personal_item", label: "A personal item is available (toothbrush, razor, hairbrush)" },
];

export const DENTAL_RECORDS_OPTIONS = [
    { value: "0", label: "Not available" },
    { value: "1", label: "Available" },
];

export const ID_SOURCE_OPTIONS = ["Passport", "Aadhaar / national ID", "Driving licence", "Prior police record"];

/** Section F/G/H/I rows reuse the PM shapes (DistinguishingFeatureRow, ClothingRow, JewelleryEffectRow, IdDocumentRow). */
export interface AmReportFormState {
    am_id: string;
    reported_name: string;
    reported_by_relation: string;
    occupation: string;
    last_seen_at: string;
    last_seen_place: string;
    marital_status: string;
    sex: string;
    age: string;
    height_text: string;
    height_cm_reported: string;
    build: string;
    skin_tone: string;
    skin_tone_other: string;
    hair_colour: string;
    hair_length: string;
    eye_colour: string;
    facial_hair: string;
    dna_reference_type: string;
    dental_records_available: boolean;
    dentist_contact: string;
    prints_on_file: boolean;
    id_sources: string[];
    recent_photo_ref: string;
    tattoo_photo_ref: string;
    distinguishing_features: DistinguishingFeatureRow[];
    clothing: ClothingRow[];
    jewellery_effects: JewelleryEffectRow[];
    id_documents: IdDocumentRow[];
    notes: string;
    reporter_name: string;
    reporter_phone: string;
    reporter_address: string;
    interviewing_officer: string;
    interviewed_at: string;
}

export interface ShareLinkResult {
    success: boolean;
    url: string;
    token: string;
    expires_at: string;
}

export interface ReportContext {
    success: boolean;
    incident: { incident_id: string; name: string; incident_date: string };
}

/** Response from the two AI-assist scan endpoints. */
export interface ScanResult {
    success: boolean;
    ai_available: boolean;
    /** Which provider actually answered — "claude" (primary) or "gemini" (fallback). */
    provider: "claude" | "gemini" | null;
    upload_ref: string;
    fields: Record<string, unknown> | null;
    confidence: Record<string, number> | null;
    reason: string | null;
}
