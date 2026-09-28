import api from "./client";
import type { GeminiMatchResult } from "../types";

/** Triggers a fresh Gemini refinement of this body's existing shortlist. */
export function runGeminiMatch(incidentId: string, pmId: string) {
    return api.post<GeminiMatchResult>(`/incidents/${incidentId}/bodies/${pmId}/gemini-match`);
}

/** Reads back the last saved Gemini refinement for this body, if any — never calls Gemini itself. */
export function fetchGeminiMatch(incidentId: string, pmId: string) {
    return api.get<GeminiMatchResult>(`/incidents/${incidentId}/bodies/${pmId}/gemini-match`);
}
