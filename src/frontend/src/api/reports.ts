import axios from "axios";
import api from "./client";
import type { AmFile, AmReportFormState, ReportContext, ShareLinkResult } from "../types";

/** Empty form state for a fresh ante-mortem report — every section blank. */
export function emptyAmReportForm(): AmReportFormState {
    return {
        am_id: "",
        reported_name: "",
        reported_by_relation: "",
        occupation: "",
        last_seen_at: "",
        last_seen_place: "",
        marital_status: "",
        sex: "",
        age: "",
        height_text: "",
        height_cm_reported: "",
        build: "",
        skin_tone: "",
        skin_tone_other: "",
        hair_colour: "",
        hair_length: "",
        eye_colour: "",
        facial_hair: "",
        dna_reference_type: "",
        dental_records_available: false,
        dentist_contact: "",
        prints_on_file: false,
        id_sources: [],
        recent_photo_ref: "",
        tattoo_photo_ref: "",
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
        notes: "",
        reporter_name: "",
        reporter_phone: "",
        reporter_address: "",
        interviewing_officer: "",
        interviewed_at: "",
    };
}

/** Builds the API payload from form state — id_sources drives prints_on_file
 * automatically (any source selected implies "yes, on file somewhere"). */
export function buildAmReportPayload(form: AmReportFormState): Record<string, unknown> {
    const num = (v: string) => (v.trim() === "" ? undefined : Number(v));

    return {
        am_id: form.am_id || undefined,
        reported_name: form.reported_name,
        reported_by_relation: form.reported_by_relation || undefined,
        occupation: form.occupation || undefined,
        last_seen_at: form.last_seen_at || undefined,
        last_seen_place: form.last_seen_place || undefined,
        marital_status: form.marital_status || undefined,
        sex: form.sex || undefined,
        age: num(form.age),
        height_text: form.height_text || undefined,
        height_cm_reported: num(form.height_cm_reported),
        build: form.build || undefined,
        skin_tone: form.skin_tone || undefined,
        skin_tone_other: form.skin_tone_other || undefined,
        hair_colour: form.hair_colour || undefined,
        hair_length: form.hair_length || undefined,
        eye_colour: form.eye_colour || undefined,
        facial_hair: form.facial_hair || undefined,
        dna_reference_type: form.dna_reference_type || undefined,
        dental_records_available: form.dental_records_available,
        dentist_contact: form.dental_records_available ? form.dentist_contact || undefined : undefined,
        prints_on_file: form.id_sources.length > 0,
        id_sources: form.id_sources,
        recent_photo_ref: form.recent_photo_ref || undefined,
        tattoo_photo_ref: form.tattoo_photo_ref || undefined,
        distinguishing_features: form.distinguishing_features.filter((r) => r.type),
        clothing: form.clothing,
        jewellery_effects: form.jewellery_effects.filter((r) => r.item),
        id_documents: form.id_documents.filter((r) => r.document_type || r.id_last4),
        notes: form.notes || undefined,
        reporter_name: form.reporter_name,
        reporter_phone: form.reporter_phone || undefined,
        reporter_address: form.reporter_address || undefined,
        interviewing_officer: form.interviewing_officer || undefined,
        interviewed_at: form.interviewed_at || undefined,
    };
}

const multipart = { headers: { "Content-Type": "multipart/form-data" } };

/** A bare axios instance (no auth interceptor, no base auth headers) for the
 * public share-link endpoints — these are reached by a family member who is
 * never logged in, and are authorised by the `token` query/body param instead. */
const publicApi = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    headers: { Accept: "application/json" },
});

// ---------------------------------------------------------------- Staff (authenticated)

export function createAmReportStaff(incidentId: string, payload: Record<string, unknown>) {
    return api.post<{ success: boolean; am_id: string; profile: AmFile }>(`/incidents/${incidentId}/profiles`, payload);
}

export function getShareLink(incidentId: string) {
    return api.post<ShareLinkResult>(`/incidents/${incidentId}/profiles/share-link`);
}

// -------------------------------------------------------------------- Public (no auth)

export function getReportContext(incidentId: string, token: string) {
    return publicApi.get<ReportContext>(`/public/incidents/${incidentId}/report-context`, { params: { token } });
}

export function createAmReportPublic(incidentId: string, token: string, payload: Record<string, unknown>) {
    return publicApi.post<{ success: boolean; am_id: string }>(
        `/public/incidents/${incidentId}/reports`,
        { ...payload, token },
    );
}

export function uploadFilePublic(incidentId: string, token: string, file: File) {
    const data = new FormData();
    data.append("file", file);
    data.append("token", token);

    return publicApi.post<{ success: boolean; upload_ref: string }>(
        `/public/incidents/${incidentId}/uploads`,
        data,
        multipart,
    );
}
