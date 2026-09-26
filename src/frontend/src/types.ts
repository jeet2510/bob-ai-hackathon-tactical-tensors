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
    reported_name: string;
    sex: string | null;
    age: number | null;
    height_text: string | null;
    height_cm_reported: number | null;
    last_seen_at: string | null;
    last_seen_place: string | null;
    reported_by_relation: string | null;
    occupation: string | null;
    dna_reference_type: string | null;
    dental_records_available: boolean;
    prints_on_file: boolean;
    dental_chart_fdi: string | null;
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
