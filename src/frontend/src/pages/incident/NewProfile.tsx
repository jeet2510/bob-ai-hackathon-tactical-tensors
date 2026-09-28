import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { buildAmReportPayload, createAmReportStaff, emptyAmReportForm } from "../../api/reports";
import { uploadFile } from "../../api/bodies";
import { toApiError } from "../../api/errors";
import AmReportFields from "../../components/am-form/AmReportFields";
import { useIncident } from "../IncidentLayout";
import type { AmReportFormState } from "../../types";

export default function NewProfile() {
    const { incident } = useIncident();
    const navigate = useNavigate();

    const [form, setForm] = useState<AmReportFormState>(emptyAmReportForm());
    const [uploadingPhoto, setUploadingPhoto] = useState<"recent" | "tattoo" | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    function setField(name: string, value: string) {
        setForm((prev) => ({ ...prev, [name]: value }));
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
