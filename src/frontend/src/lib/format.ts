import type { ConfidenceBand, NormItem, Tier, Verdict } from "../types";

export const BAND_LABEL: Record<ConfidenceBand, string> = {
    high: "Strong evidence",
    moderate: "Moderate evidence",
    low: "Weak evidence",
    no_credible_candidate: "No credible candidate",
};

/**
 * Bands are ordinal evidence strength, never probabilities — the wording
 * throughout the interface avoids anything that sounds like a percentage
 * likelihood of identity.
 */
export const BAND_CLASS: Record<ConfidenceBand, string> = {
    high: "high",
    moderate: "moderate",
    low: "low",
    no_credible_candidate: "none",
};

export const VERDICT_LABEL: Record<Verdict, string> = {
    match: "Agrees",
    conflict: "Conflicts",
    missing: "Not recorded",
    excluded: "Not assessable",
};

export const TIER_LABEL: Record<Tier, string> = {
    A: "Primary identifier",
    B: "Strong secondary",
    C: "Supportive",
};

export const LANGUAGE: Record<string, string> = {
    en: "English",
    hi: "Hindi",
    mr: "Marathi",
};

export const DECISION_LABEL: Record<string, string> = {
    recommend_confirm_test: "Referred for confirmation",
    reject: "Ruled out",
    defer: "Deferred",
    no_credible_candidate: "No credible candidate",
};

/** Evidence strength is signed: show the sign so a negative reads as negative. */
export function signed(value: number): string {
    return `${value >= 0 ? "+" : ""}${value.toFixed(2)}`;
}

export function humanise(value: unknown): string {
    if (value === null || value === undefined || value === "") {
        return "—";
    }

    if (typeof value === "boolean") {
        return value ? "yes" : "no";
    }

    if (Array.isArray(value)) {
        return value.map(humanise).join("–");
    }

    return String(value).replace(/_/g, " ");
}

/**
 * A one-line rendering of a normalised item, e.g. "mole · cheek · left".
 *
 * Skips the bookkeeping keys and anything unrecorded, so a reviewer comparing
 * two items sees only what was actually observed.
 */
export function describeItem(item: NormItem | null | undefined): string {
    if (!item) {
        return "—";
    }

    if (item.negated === true) {
        return `no ${item.category}`;
    }

    if (item.assessable === false) {
        return `${item.category} — not assessable`;
    }

    const skip = new Set(["category", "negated", "assessable", "body_region", "truth_note"]);

    const parts = Object.entries(item)
        .filter(([key, value]) => !skip.has(key) && value !== null && value !== undefined && value !== "")
        .map(([, value]) => humanise(value));

    return parts.length > 0 ? parts.join(" · ") : item.category;
}
