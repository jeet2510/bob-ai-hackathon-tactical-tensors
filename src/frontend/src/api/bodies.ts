import api from "./client";
import type { Incident, NewBodyFormState, ScanResult } from "../types";

const multipart = { headers: { "Content-Type": "multipart/form-data" } };

export function createIncident(payload: {
    incident_id: string;
    name: string;
    incident_date: string;
    district?: string;
}) {
    return api.post<{ success: boolean; incident: Incident }>("/incidents", payload);
}

export function createBody(incidentId: string, payload: Record<string, unknown>) {
    return api.post<{ success: boolean; pm_id: string }>(`/incidents/${incidentId}/bodies`, payload);
}

export function scanFormPdf(incidentId: string, file: File) {
    const data = new FormData();
    data.append("file", file);

    return api.post<ScanResult>(`/incidents/${incidentId}/bodies/scan-pdf`, data, multipart);
}

export function scanBodyPhoto(incidentId: string, file: File) {
    const data = new FormData();
    data.append("file", file);

    return api.post<ScanResult>(`/incidents/${incidentId}/bodies/scan-photo`, data, multipart);
}

export function uploadFile(incidentId: string, file: File) {
    const data = new FormData();
    data.append("file", file);

    return api.post<{ success: boolean; upload_ref: string }>(`/incidents/${incidentId}/uploads`, data, multipart);
}

/** Empty form state for a fresh intake — every section blank, ready to fill or AI-prefill. */
export function emptyBodyForm(): NewBodyFormState {
    return {
        pm_id: "",
        examiner_name: "",
        examiner_role: "",
        found_at: "",
        found_place: "",
        lat: "",
        lon: "",
        body_condition: "",
        sex: "",
        age_min: "",
        age_max: "",
        height_cm: "",
        build: "",
        skin_tone: "",
        skin_tone_other: "",
        hair_colour: "",
        hair_length: "",
        eye_colour: "",
        facial_hair: "",
        dna_status: "",
        dental_status: "",
        print_status: "",
        dental_chart: [],
        distinguishing_features: [],
        clothing: [
            { slot: "Headwear", garment: "", colour: "" },
            { slot: "Upper body", garment: "", colour: "" },
            { slot: "Lower body", garment: "", colour: "" },
            { slot: "Footwear", garment: "", colour: "" },
            { slot: "Other", garment: "", colour: "" },
        ],
        jewellery_effects: [],
        id_documents: [],
        photo_log: [],
        notes: "",
        signature_note: "",
        completed_at: "",
        chain_of_custody_hash: "",
        source: "manual",
    };
}
