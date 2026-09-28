import { useState, type ReactNode } from "react";
import { useNavigate } from "react-router-dom";
import { buildAmReportPayload, createAmReportStaff, emptyAmReportForm } from "../../api/reports";
import { uploadFile } from "../../api/bodies";
import { toApiError } from "../../api/errors";
import AiSuggestedBadge from "../../components/body-form/AiSuggestedBadge";
import AmReportFields from "../../components/am-form/AmReportFields";
import AmScanUploadPanel from "../../components/am-form/AmScanUploadPanel";
import { useIncident } from "../IncidentLayout";
import type { AmReportFormState, ClothingRow, DistinguishingFeatureRow, IdDocumentRow, JewelleryEffectRow } from "../../types";

/** Scalar AM fields that can be prefilled by AI scan */
const SCALAR_AM_AI_FIELDS = [
    "sex",
    "age",
    "height_text",
    "height_cm_reported",
    "build",
    "skin_tone",
    "skin_tone_other",
    "hair_colour",
    "hair_length",
    "eye_colour",
    "facial_hair",
    "notes",
] as const;

export default function NewProfile() {
    const { incident } = useIncident();
    const navigate = useNavigate();

    const [form, setForm] = useState<AmReportFormState>(emptyAmReportForm());
    const [aiFields, setAiFields] = useState<Set<string>>(new Set());
    const [uploadingPhoto, setUploadingPhoto] = useState<"recent" | "tattoo" | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    function setField(name: string, value: string) {
        setForm((prev) => ({ ...prev, [name]: value }));
        // Clear AI badge once the user edits the field themselves
        setAiFields((prev) => {
            if (!prev.has(name)) return prev;
            const next = new Set(prev);
            next.delete(name);
            return next;
        });
    }

    function badge(name: string): ReactNode {
        return aiFields.has(name) ? <AiSuggestedBadge /> : undefined;
    }

    function mergeAiFields(fields: Record<string, unknown>) {
        const suggested = new Set<string>();

        setForm((prev) => {
            const next = { ...prev };

            for (const key of SCALAR_AM_AI_FIELDS) {
                let incoming = fields[key];

                // Map PM field names → AM field names
                if (key === "age" && (incoming === undefined || incoming === "") && fields.age_min !== undefined) {
                    incoming = fields.age_min;
                }
                if (key === "height_cm_reported" && (incoming === undefined || incoming === "") && fields.height_cm !== undefined) {
                    incoming = fields.height_cm;
                }

                const current = prev[key as keyof AmReportFormState];
                if (incoming !== null && incoming !== undefined && incoming !== "" && (current === "" || current === undefined)) {
                    (next as Record<string, unknown>)[key] = String(incoming);
                    suggested.add(key);
                }
            }

            // Distinguishing features
            if (
                prev.distinguishing_features.length === 0 &&
                Array.isArray(fields.distinguishing_features) &&
                fields.distinguishing_features.length > 0
            ) {
                next.distinguishing_features = (fields.distinguishing_features as Array<Record<string, unknown>>).map(
                    (row) => ({
                        type: String(row.type ?? ""),
                        description: String(row.description ?? ""),
                        region: String(row.region ?? ""),
                        side: (row.side as DistinguishingFeatureRow["side"]) ?? "",
                        serial_no: String(row.serial_no ?? ""),
                        photo_ref: "",
                    }),
                );
                suggested.add("distinguishing_features");
            }

            // Clothing
            if (Array.isArray(fields.clothing) && fields.clothing.length > 0) {
                const bySlot = new Map((fields.clothing as Array<Record<string, unknown>>).map((r) => [r.slot, r]));
                const merged = prev.clothing.map((row) => {
                    const inc = bySlot.get(row.slot);
                    if (!inc) return row;
                    return {
                        slot: row.slot,
                        garment: row.garment || String(inc.garment ?? ""),
                        colour: row.colour || String(inc.colour ?? ""),
                    } as ClothingRow;
                });
                // append any rows without a matching slot
                const existingSlots = new Set(prev.clothing.map((r) => r.slot));
                for (const [slot, inc] of bySlot.entries()) {
                    if (!existingSlots.has(slot as string)) {
                        merged.push({
                            slot: String(slot),
                            garment: String(inc.garment ?? ""),
                            colour: String(inc.colour ?? ""),
                        } as ClothingRow);
                    }
                }
                next.clothing = merged;
                if (bySlot.size > 0) suggested.add("clothing");
            }

            // Jewellery & effects
            if (
                prev.jewellery_effects.length === 0 &&
                Array.isArray(fields.jewellery_effects) &&
                fields.jewellery_effects.length > 0
            ) {
                next.jewellery_effects = (fields.jewellery_effects as Array<Record<string, unknown>>).map((row) => ({
                    kind: (row.kind as JewelleryEffectRow["kind"]) ?? "jewellery",
                    item: String(row.item ?? ""),
                    material_description: String(row.material_description ?? ""),
                }));
                suggested.add("jewellery_effects");
            }

            // Identity documents
            if (
                prev.id_documents.length === 0 &&
                Array.isArray(fields.id_documents) &&
                fields.id_documents.length > 0
            ) {
                next.id_documents = (fields.id_documents as Array<Record<string, unknown>>).map((row) => ({
                    document_type: String(row.document_type ?? ""),
                    id_last4: String(row.id_last4 ?? ""),
                    name_on_document: String(row.name_on_document ?? ""),
                    note: String(row.note ?? ""),
                })) as IdDocumentRow[];
                suggested.add("id_documents");
            }

            return next;
        });

        setAiFields((prev) => new Set([...prev, ...suggested]));
    }

    async function handleUpload(kind: "recent" | "tattoo", file: File) {
        setUploadingPhoto(kind);
        try {
            const { data } = await uploadFile(incident.incident_id, file);
            setForm((prev) => ({ ...prev, [kind === "recent" ? "recent_photo_ref" : "tattoo_photo_ref"]: data.upload_ref }));
        } catch {
            // Left blank — the picker reappears so the upload can be retried.
        } finally {
            setUploadingPhoto(null);
        }
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError("");
        setFieldErrors({});

        try {
            await createAmReportStaff(incident.incident_id, buildAmReportPayload(form));
            navigate(`/incidents/${incident.incident_id}/profiles`);
        } catch (caught) {
            const apiError = toApiError(caught, "Could not save this family report.");
            setError(apiError.message);
            setFieldErrors(apiError.fields);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="body-intake">
            <div className="page-head">
                <div>
                    <h1>Log a family report</h1>
                    <p>
                        For a phoned-in or walk-in report. To let a family submit this themselves instead,
                        use "Get shareable family-report link" on the Family reports page.
                    </p>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <AmScanUploadPanel
                incidentId={incident.incident_id}
                onResult={mergeAiFields}
            />

            <form className="body-intake-form" onSubmit={submit}>
                <AmReportFields
                    form={form}
                    setField={setField}
                    setForm={setForm}
                    fieldErrors={fieldErrors}
                    variant="staff"
                    uploadingPhoto={uploadingPhoto}
                    onUploadRecentPhoto={(file) => handleUpload("recent", file)}
                    onUploadTattooPhoto={(file) => handleUpload("tattoo", file)}
                    badge={badge}
                />

                <div className="form-actions form-actions-sticky">
                    <span className="muted form-actions-hint">Section A and your name are the only required fields.</span>
                    <button className="btn btn-primary" type="submit" disabled={saving}>
                        {saving ? "Saving…" : "Save family report"}
                    </button>
                </div>
            </form>
        </div>
    );
}
