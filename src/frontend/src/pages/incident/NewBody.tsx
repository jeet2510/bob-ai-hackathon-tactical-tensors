import { useMemo, useState, type ReactNode } from "react";
import { useNavigate } from "react-router-dom";
import { createBody, emptyBodyForm } from "../../api/bodies";
import { toApiError } from "../../api/errors";
import AiSuggestedBadge from "../../components/body-form/AiSuggestedBadge";
import ClothingTable from "../../components/body-form/ClothingTable";
import DentalChartGrid from "../../components/body-form/DentalChartGrid";
import FeaturesTable from "../../components/body-form/FeaturesTable";
import { IdDocumentsTable, JewelleryTable, PhotoLogTable } from "../../components/body-form/RepeatableSections";
import ScanUploadPanel from "../../components/body-form/ScanUploadPanel";
import { SelectField, TextAreaField, TextField } from "../../components/Field";
import { useIncident } from "../IncidentLayout";
import {
    BODY_CONDITION_OPTIONS,
    BUILD_OPTIONS,
    DENTAL_STATUS_OPTIONS,
    DNA_STATUS_OPTIONS,
    EYE_COLOUR_OPTIONS,
    FACIAL_HAIR_OPTIONS,
    HAIR_COLOUR_OPTIONS,
    HAIR_LENGTH_OPTIONS,
    PM_SEX_OPTIONS,
    PRINT_STATUS_OPTIONS,
    SKIN_TONE_OPTIONS,
    type ClothingRow,
    type DentalChartEntry,
    type DistinguishingFeatureRow,
    type IdDocumentRow,
    type JewelleryEffectRow,
    type NewBodyFormState,
    type PhotoLogRow,
} from "../../types";

const SCALAR_AI_FIELDS = [
    "examiner_name",
    "examiner_role",
    "found_at",
    "found_place",
    "body_condition",
    "sex",
    "age_min",
    "age_max",
    "height_cm",
    "build",
    "skin_tone",
    "hair_colour",
    "hair_length",
    "eye_colour",
    "facial_hair",
    "dna_status",
    "dental_status",
    "print_status",
    "notes",
] as const;

const SECTIONS = [
    { id: "header", label: "Case", title: "Case header" },
    { id: "A", label: "A", title: "A. Recovery condition" },
    { id: "B", label: "B", title: "B. Biological profile" },
    { id: "C", label: "C", title: "C. Physical appearance" },
    { id: "D", label: "D", title: "D. Primary-identifier availability" },
    { id: "E", label: "E", title: "E. Dental chart (FDI notation)" },
    { id: "F", label: "F", title: "F. Body diagram — distinguishing features" },
    { id: "G", label: "G", title: "G. Clothing worn" },
    { id: "H", label: "H", title: "H. Jewellery & personal effects" },
    { id: "I", label: "I", title: "I. Identity documents" },
    { id: "J", label: "J", title: "J. Photo evidence log" },
    { id: "K", label: "K", title: "K. Additional notes" },
] as const;

export default function NewBody() {
    const { incident } = useIncident();
    const navigate = useNavigate();

    const [form, setForm] = useState<NewBodyFormState>(emptyBodyForm());
    const [aiFields, setAiFields] = useState<Set<string>>(new Set());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    function setField(name: string, value: string) {
        setForm((prev) => ({ ...prev, [name]: value }));
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

    function mergeAiFields(fields: Record<string, unknown>, source: "pdf_scan" | "live_scan") {
        const suggested = new Set<string>();

        setForm((prev) => {
            const next = { ...prev };

            for (const key of SCALAR_AI_FIELDS) {
                const incoming = fields[key];
                const current = prev[key as keyof NewBodyFormState];

                if (incoming !== null && incoming !== undefined && incoming !== "" && (current === "" || current === undefined)) {
                    (next as Record<string, unknown>)[key] = String(incoming);
                    suggested.add(key);
                }
            }

            if (prev.dental_chart.length === 0 && Array.isArray(fields.dental_chart) && fields.dental_chart.length > 0) {
                next.dental_chart = (fields.dental_chart as Array<{ tooth: number; code: string }>)
                    .filter((e) => e && typeof e.tooth === "number" && ["M", "F", "C", "R"].includes(e.code))
                    .map((e) => ({ tooth: e.tooth, code: e.code as DentalChartEntry["code"] }));
                if (next.dental_chart.length > 0) suggested.add("dental_chart");
            }

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
                        photo_ref: String(row.photo_ref ?? ""),
                    }),
                );
                suggested.add("distinguishing_features");
            }

            if (Array.isArray(fields.clothing)) {
                const bySlot = new Map((fields.clothing as Array<Record<string, unknown>>).map((r) => [r.slot, r]));
                next.clothing = prev.clothing.map((row) => {
                    const incoming = bySlot.get(row.slot);
                    if (!incoming) return row;
                    return {
                        slot: row.slot,
                        garment: row.garment || String(incoming.garment ?? ""),
                        colour: row.colour || String(incoming.colour ?? ""),
                    };
                });
                if (bySlot.size > 0) suggested.add("clothing");
            }

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

            if (prev.id_documents.length === 0 && Array.isArray(fields.id_documents) && fields.id_documents.length > 0) {
                next.id_documents = (fields.id_documents as Array<Record<string, unknown>>).map((row) => ({
                    document_type: String(row.document_type ?? ""),
                    id_last4: String(row.id_last4 ?? ""),
                    name_on_document: String(row.name_on_document ?? ""),
                    note: String(row.note ?? ""),
                }));
                suggested.add("id_documents");
            }

            if (prev.photo_log.length === 0 && Array.isArray(fields.photo_log) && fields.photo_log.length > 0) {
                next.photo_log = (fields.photo_log as Array<Record<string, unknown>>).map((row, i) => ({
                    label: String(row.label ?? i + 1),
                    modality: String(row.modality ?? ""),
                    view: String(row.view ?? ""),
                    quality_flags: Array.isArray(row.quality_flags) ? (row.quality_flags as string[]) : [],
                    upload_ref: "",
                }));
                suggested.add("photo_log");
            }

            next.source = prev.source === "manual" ? source : prev.source === source ? prev.source : "mixed";

            return next;
        });

        setAiFields((prev) => new Set([...prev, ...suggested]));
    }

    function buildPayload(): Record<string, unknown> {
        const num = (v: string) => (v.trim() === "" ? undefined : Number(v));

        return {
            pm_id: form.pm_id || undefined,
            examiner_name: form.examiner_name,
            examiner_role: form.examiner_role,
            found_at: form.found_at,
            found_place: form.found_place || undefined,
            lat: num(form.lat),
            lon: num(form.lon),
            body_condition: form.body_condition,
            sex: form.sex,
            age_min: num(form.age_min),
            age_max: num(form.age_max),
            height_cm: num(form.height_cm),
            build: form.build || undefined,
            skin_tone: form.skin_tone || undefined,
            skin_tone_other: form.skin_tone_other || undefined,
            hair_colour: form.hair_colour || undefined,
            hair_length: form.hair_length || undefined,
            eye_colour: form.eye_colour || undefined,
            facial_hair: form.facial_hair || undefined,
            dna_status: form.dna_status,
            dental_status: form.dental_status,
            print_status: form.print_status,
            dental_chart: form.dental_chart,
            distinguishing_features: form.distinguishing_features.filter((r) => r.type),
            clothing: form.clothing,
            jewellery_effects: form.jewellery_effects.filter((r) => r.item),
            id_documents: form.id_documents.filter((r) => r.document_type || r.id_last4),
            photo_log: form.photo_log.filter((r) => r.modality),
            notes: form.notes || undefined,
            signature_note: form.signature_note || undefined,
            completed_at: form.completed_at || undefined,
            chain_of_custody_hash: form.chain_of_custody_hash || undefined,
            source: form.source,
        };
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError("");
        setFieldErrors({});

        try {
            const { data } = await createBody(incident.incident_id, buildPayload());
            navigate(`/incidents/${incident.incident_id}/bodies/${data.pm_id}`);
        } catch (caught) {
            const apiError = toApiError(caught, "Could not save this body record.");
            setError(apiError.message);
            setFieldErrors(apiError.fields);
        } finally {
            setSaving(false);
        }
    }

    const requiredDone = useMemo(() => {
        const core = [
            form.examiner_name,
            form.examiner_role,
            form.found_at,
            form.body_condition,
            form.sex,
            form.age_min,
            form.age_max,
            form.dna_status,
            form.dental_status,
            form.print_status,
        ];
        return core.filter(Boolean).length;
    }, [form]);

    return (
        <div className="body-intake">
            <div className="page-head">
                <div>
                    <h1>Log a recovered body</h1>
                    <p>
                        Fill this in by hand, or use either AI-assist panel to prefill it from a scanned form
                        or a body photograph — every field stays editable, and nothing saves until you submit.
                    </p>
                </div>
                <div className="intake-progress" title="Required core fields completed">
                    <svg viewBox="0 0 36 36" className="progress-ring">
                        <circle cx="18" cy="18" r="15.5" className="progress-ring-track" />
                        <circle
                            cx="18"
                            cy="18"
                            r="15.5"
                            className="progress-ring-fill"
                            style={{ strokeDasharray: `${(requiredDone / 10) * 97.4} 97.4` }}
                        />
                    </svg>
                    <span>
                        {requiredDone}/10 core fields
                    </span>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <nav className="form-section-nav" aria-label="Form sections">
                {SECTIONS.map((s) => (
                    <a key={s.id} href={`#section-${s.id}`} className="form-section-nav-pill">
                        {s.label}
                    </a>
                ))}
            </nav>

            <div className="scan-panels">
                <ScanUploadPanel kind="pdf" incidentId={incident.incident_id} onResult={(f) => mergeAiFields(f, "pdf_scan")} />
                <ScanUploadPanel kind="photo" incidentId={incident.incident_id} onResult={(f) => mergeAiFields(f, "live_scan")} />
            </div>

            <form className="body-intake-form" onSubmit={submit}>
                <section className="form-section card" id="section-header">
                    <SectionHeader letter="•" title="Case header" description="Who examined this body, and when and where it was recovered." />
                    <div className="form-grid">
                        <TextField label="Case ID (optional — generated if blank)" name="pm_id" value={form.pm_id} onChange={setField} error={fieldErrors.pm_id} />
                        <TextField label="Examiner name" name="examiner_name" value={form.examiner_name} onChange={setField} error={fieldErrors.examiner_name} badge={badge("examiner_name")} required />
                        <TextField label="Examiner role / badge no." name="examiner_role" value={form.examiner_role} onChange={setField} error={fieldErrors.examiner_role} badge={badge("examiner_role")} required />
                        <TextField label="Date/time found" name="found_at" type="datetime-local" value={form.found_at} onChange={setField} error={fieldErrors.found_at} badge={badge("found_at")} required />
                        <TextField label="Recovery site / place" name="found_place" value={form.found_place} onChange={setField} error={fieldErrors.found_place} badge={badge("found_place")} />
                        <TextField label="GPS latitude" name="lat" type="number" value={form.lat} onChange={setField} error={fieldErrors.lat} />
                        <TextField label="GPS longitude" name="lon" type="number" value={form.lon} onChange={setField} error={fieldErrors.lon} />
                    </div>
                </section>

                <section className="form-section card" id="section-A">
                    <SectionHeader letter="A" title="Recovery condition" />
                    <div className="form-grid form-grid-narrow">
                        <SelectField label="Body condition" name="body_condition" value={form.body_condition} onChange={setField} options={BODY_CONDITION_OPTIONS} error={fieldErrors.body_condition} badge={badge("body_condition")} required />
                    </div>
                </section>

                <section className="form-section card" id="section-B">
                    <SectionHeader letter="B" title="Biological profile" />
                    <div className="form-grid form-grid-narrow">
                        <SelectField label="Sex" name="sex" value={form.sex} onChange={setField} options={PM_SEX_OPTIONS} error={fieldErrors.sex} badge={badge("sex")} required />
                        <div className="form-group form-group-pair">
                            <label>
                                <span className="form-group-label-text">Estimated age range<span className="required-mark">*</span></span>
                                {badge("age_min")}
                            </label>
                            <div className="input-pair">
                                <input type="number" placeholder="from" value={form.age_min} onChange={(e) => setField("age_min", e.target.value)} />
                                <span>–</span>
                                <input type="number" placeholder="to" value={form.age_max} onChange={(e) => setField("age_max", e.target.value)} />
                                <span className="unit">years</span>
                            </div>
                            {(fieldErrors.age_min || fieldErrors.age_max) && (
                                <div className="field-error">{fieldErrors.age_min || fieldErrors.age_max}</div>
                            )}
                        </div>
                        <TextField label="Height, supine (cm)" name="height_cm" type="number" value={form.height_cm} onChange={setField} error={fieldErrors.height_cm} badge={badge("height_cm")} />
                        <SelectField label="Build" name="build" value={form.build} onChange={setField} options={BUILD_OPTIONS} error={fieldErrors.build} badge={badge("build")} />
                    </div>
                </section>

                <section className="form-section card" id="section-C">
                    <SectionHeader letter="C" title="Physical appearance" />
                    <div className="form-grid form-grid-narrow">
                        <SelectField label="Skin tone" name="skin_tone" value={form.skin_tone} onChange={setField} options={SKIN_TONE_OPTIONS} error={fieldErrors.skin_tone} badge={badge("skin_tone")} />
                        {form.skin_tone === "Other" && (
                            <TextField label="Skin tone, other" name="skin_tone_other" value={form.skin_tone_other} onChange={setField} />
                        )}
                        <SelectField label="Hair colour" name="hair_colour" value={form.hair_colour} onChange={setField} options={HAIR_COLOUR_OPTIONS} error={fieldErrors.hair_colour} badge={badge("hair_colour")} />
                        <SelectField label="Hair length" name="hair_length" value={form.hair_length} onChange={setField} options={HAIR_LENGTH_OPTIONS} error={fieldErrors.hair_length} badge={badge("hair_length")} />
                        <SelectField label="Eye colour" name="eye_colour" value={form.eye_colour} onChange={setField} options={EYE_COLOUR_OPTIONS} error={fieldErrors.eye_colour} badge={badge("eye_colour")} />
                        <SelectField label="Facial hair" name="facial_hair" value={form.facial_hair} onChange={setField} options={FACIAL_HAIR_OPTIONS} error={fieldErrors.facial_hair} badge={badge("facial_hair")} />
                    </div>
                </section>

                <section className="form-section card" id="section-D">
                    <SectionHeader letter="D" title="Primary-identifier availability" description="This decides which confirmation test gets recommended — please complete carefully." />
                    <div className="form-grid form-grid-narrow">
                        <SelectField label="DNA sample" name="dna_status" value={form.dna_status} onChange={setField} options={DNA_STATUS_OPTIONS} error={fieldErrors.dna_status} badge={badge("dna_status")} required />
                        <SelectField label="Dental" name="dental_status" value={form.dental_status} onChange={setField} options={DENTAL_STATUS_OPTIONS} error={fieldErrors.dental_status} badge={badge("dental_status")} required />
                        <SelectField label="Fingerprints" name="print_status" value={form.print_status} onChange={setField} options={PRINT_STATUS_OPTIONS} error={fieldErrors.print_status} badge={badge("print_status")} required />
                    </div>
                </section>

                <section className="form-section card" id="section-E">
                    <SectionHeader letter="E" title="Dental chart (FDI notation)" description="Leave a tooth's box blank if sound or not examined." badge={badge("dental_chart")} />
                    <DentalChartGrid value={form.dental_chart} onChange={(v) => setForm((p) => ({ ...p, dental_chart: v }))} />
                </section>

                <section className="form-section card" id="section-F">
                    <SectionHeader
                        letter="F"
                        title="Body diagram — distinguishing features"
                        description="For an implant, capture the device serial if visible — it is the single strongest piece of evidence the matching engine uses."
                        badge={badge("distinguishing_features")}
                    />
                    <FeaturesTable
                        rows={form.distinguishing_features}
                        onChange={(v) => setForm((p) => ({ ...p, distinguishing_features: v as DistinguishingFeatureRow[] }))}
                    />
                </section>

                <section className="form-section card" id="section-G">
                    <SectionHeader letter="G" title="Clothing worn (as recovered)" badge={badge("clothing")} />
                    <ClothingTable rows={form.clothing} onChange={(v) => setForm((p) => ({ ...p, clothing: v as ClothingRow[] }))} />
                </section>

                <section className="form-section card" id="section-H">
                    <SectionHeader letter="H" title="Jewellery & personal effects" badge={badge("jewellery_effects")} />
                    <JewelleryTable
                        rows={form.jewellery_effects}
                        onChange={(v) => setForm((p) => ({ ...p, jewellery_effects: v as JewelleryEffectRow[] }))}
                    />
                </section>

                <section className="form-section card" id="section-I">
                    <SectionHeader letter="I" title="Identity documents found on or with the body" badge={badge("id_documents")} />
                    <IdDocumentsTable
                        rows={form.id_documents}
                        onChange={(v) => setForm((p) => ({ ...p, id_documents: v as IdDocumentRow[] }))}
                    />
                </section>

                <section className="form-section card" id="section-J">
                    <SectionHeader letter="J" title="Photo evidence log" badge={badge("photo_log")} />
                    <PhotoLogTable
                        rows={form.photo_log}
                        onChange={(v) => setForm((p) => ({ ...p, photo_log: v as PhotoLogRow[] }))}
                        incidentId={incident.incident_id}
                    />
                </section>

                <section className="form-section card" id="section-K">
                    <SectionHeader letter="K" title="Additional notes / observations" badge={badge("notes")} />
                    <TextAreaField label="Notes" name="notes" value={form.notes} onChange={setField} placeholder="Anything the boxes above didn't capture — condition of effects, scene context, anything unusual." />
                </section>

                <section className="form-section card">
                    <SectionHeader letter="✓" title="Sign-off" />
                    <div className="form-grid">
                        <TextField label="Examiner signature (typed acknowledgement)" name="signature_note" value={form.signature_note} onChange={setField} />
                        <TextField label="Date/time completed" name="completed_at" type="datetime-local" value={form.completed_at} onChange={setField} />
                        <TextField label="Chain-of-custody seal / hash #" name="chain_of_custody_hash" value={form.chain_of_custody_hash} onChange={setField} />
                    </div>
                </section>

                <div className="form-actions form-actions-sticky">
                    <span className="muted form-actions-hint">
                        {requiredDone}/10 core fields complete
                    </span>
                    <button className="btn btn-primary" type="submit" disabled={saving}>
                        {saving ? "Saving…" : "Save body record"}
                    </button>
                </div>
            </form>
        </div>
    );
}

function SectionHeader({
    letter,
    title,
    description,
    badge,
}: {
    letter: string;
    title: string;
    description?: string;
    badge?: ReactNode;
}) {
    return (
        <div className="section-header">
            <span className="section-number">{letter}</span>
            <div className="section-header-text">
                <h3>{title}</h3>
                {description && <p className="muted">{description}</p>}
            </div>
            {badge}
        </div>
    );
}
