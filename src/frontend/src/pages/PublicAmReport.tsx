import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { buildAmReportPayload, createAmReportPublic, emptyAmReportForm, getReportContext, uploadFilePublic } from "../api/reports";
import { toApiError } from "../api/errors";
import AmReportFields from "../components/am-form/AmReportFields";
import PublicLayout from "../components/PublicLayout";
import type { AmReportFormState } from "../types";

type Status = "loading" | "ready" | "invalid" | "submitted";

export default function PublicAmReport() {
    const { incidentId } = useParams();
    const [searchParams] = useSearchParams();
    const token = searchParams.get("token") ?? "";

    const [status, setStatus] = useState<Status>("loading");
    const [incidentName, setIncidentName] = useState("");
    const [form, setForm] = useState<AmReportFormState>(emptyAmReportForm());
    const [uploadingPhoto, setUploadingPhoto] = useState<"recent" | "tattoo" | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [amId, setAmId] = useState("");

    useEffect(() => {
        if (!incidentId || !token) {
            setStatus("invalid");
            return;
        }

        getReportContext(incidentId, token)
            .then(({ data }) => {
                setIncidentName(data.incident.name);
                setStatus("ready");
            })
            .catch(() => setStatus("invalid"));
    }, [incidentId, token]);

    function setField(name: string, value: string) {
        setForm((prev) => ({ ...prev, [name]: value }));
    }

    async function handleUpload(kind: "recent" | "tattoo", file: File) {
        if (!incidentId) return;
        setUploadingPhoto(kind);

        try {
            const { data } = await uploadFilePublic(incidentId, token, file);
            setForm((prev) => ({ ...prev, [kind === "recent" ? "recent_photo_ref" : "tattoo_photo_ref"]: data.upload_ref }));
        } catch {
            // Left blank — the picker reappears so the upload can be retried.
        } finally {
            setUploadingPhoto(null);
        }
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        if (!incidentId) return;

        setSaving(true);
        setError("");
        setFieldErrors({});

        try {
            const { data } = await createAmReportPublic(incidentId, token, buildAmReportPayload(form));
            setAmId(data.am_id);
            setStatus("submitted");
            window.scrollTo({ top: 0, behavior: "smooth" });
        } catch (caught) {
            const apiError = toApiError(caught, "Could not submit this report. Please check the form and try again.");
            setError(apiError.message);
            setFieldErrors(apiError.fields);
        } finally {
            setSaving(false);
        }
    }

    if (status === "loading") {
        return (
            <PublicLayout>
                <div className="empty">Loading…</div>
            </PublicLayout>
        );
    }

    if (status === "invalid") {
        return (
            <PublicLayout>
                <div className="public-card">
                    <h1>This link isn't valid</h1>
                    <p className="muted">
                        It may have expired, or been typed incorrectly. Please ask the coordinator you're in
                        contact with for a new link.
                    </p>
                </div>
            </PublicLayout>
        );
    }

    if (status === "submitted") {
        return (
            <PublicLayout incidentName={incidentName}>
                <div className="public-card public-card-confirm">
                    <div className="public-confirm-icon">✓</div>
                    <h1>Thank you</h1>
                    <p>
                        The report for <strong>{form.reported_name}</strong> has been received and will be
                        reviewed by the response team. You don't need to do anything else right now.
                    </p>
                    <p className="muted">
                        If anyone contacts you about this, this reference may help: <span className="mono">{amId}</span>
                    </p>
                </div>
            </PublicLayout>
        );
    }

    return (
        <PublicLayout incidentName={incidentName}>
            <div className="public-card public-intro">
                <h1>Report a missing person</h1>
                <p>
                    You're reporting for <strong>{incidentName}</strong>. Please fill in what you know — it's
                    completely normal not to remember everything. Leaving a box blank is always better than
                    guessing.
                </p>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <form className="body-intake-form" onSubmit={submit}>
                <AmReportFields
                    form={form}
                    setField={setField}
                    setForm={setForm}
                    fieldErrors={fieldErrors}
                    variant="public"
                    uploadingPhoto={uploadingPhoto}
                    onUploadRecentPhoto={(file) => handleUpload("recent", file)}
                    onUploadTattooPhoto={(file) => handleUpload("tattoo", file)}
                />

                <div className="form-actions form-actions-sticky">
                    <span className="muted form-actions-hint">Only the name and your details are required.</span>
                    <button className="btn btn-primary" type="submit" disabled={saving}>
                        {saving ? "Submitting…" : "Submit report"}
                    </button>
                </div>
            </form>
        </PublicLayout>
    );
}
