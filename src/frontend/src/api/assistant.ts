import api from "./client";
import type { AssistantReply, AssistantTurn, IncidentInsight } from "../types";

/** The dashboard's on-demand AI briefing for this incident. */
export function fetchInsight(incidentId: string) {
    return api.get<IncidentInsight>(`/incidents/${incidentId}/insight`);
}

/** One turn of the DVI Assistant chat sidebar. History is held client-side and resent each turn. */
export function askAssistant(incidentId: string, message: string, history: AssistantTurn[]) {
    return api.post<AssistantReply>(`/incidents/${incidentId}/assistant`, { message, history });
}
