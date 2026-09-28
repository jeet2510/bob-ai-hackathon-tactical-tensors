import { useState } from "react";
import { scanBodyPhoto, scanFormPdf } from "../../api/bodies";
import { toApiError } from "../../api/errors";
import { FileUploadField } from "../Field";

export default function ScanUploadPanel({
    kind,
    incidentId,
    onResult,
}: {
    kind: "pdf" | "photo";
    incidentId: string;
    onResult: (fields: Record<string, unknown>, uploadRef: string, confidence: Record<string, number> | null) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");

    async function handleFile(file: File) {
        setBusy(true);
        setError("");
        setMessage("");

        try {
            const { data } = kind === "pdf" ? await scanFormPdf(incidentId, file) : await scanBodyPhoto(incidentId, file);

            if (!data.ai_available) {
                setMessage(data.reason ?? "AI assist is unavailable right now. Continue filling the form manually.");
            } else if (data.fields) {
                onResult(data.fields, data.upload_ref, data.confidence);
                const providerLabel = data.provider === "claude" ? "Claude" : data.provider === "gemini" ? "Gemini" : "AI";
                setMessage(
                    `${providerLabel} read the ${kind === "pdf" ? "form" : "photo"}. Prefilled fields are marked “AI-suggested” below — review each one.`,
                );
            }
        } catch (caught) {
            setError(toApiError(caught, "Could not read the file.").message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="card scan-panel">
            <h3>{kind === "pdf" ? "Scan the filled paper form" : "Live scan a body photo"}</h3>
            <p className="muted">
                {kind === "pdf"
                    ? "Upload a photograph or scan of the completed paper form. Gemini reads it and prefills the sections below — nothing is saved until you review and submit."
                    : "Upload or take a photo of the body. Gemini reads what's visible and prefills condition, build and appearance — nothing is saved until you review and submit."}
            </p>

            <FileUploadField
                label={kind === "pdf" ? "Form PDF" : "Body photo"}
                name={`scan-${kind}`}
                accept={kind === "pdf" ? "application/pdf" : "image/*"}
                capture={kind === "photo" ? "environment" : undefined}
                busy={busy}
                busyLabel={kind === "pdf" ? "Reading form…" : "Analysing photo…"}
                onFileSelected={handleFile}
            />

            {message && <div className="notice">{message}</div>}
            {error && <div className="error-banner">{error}</div>}
        </div>
    );
}
