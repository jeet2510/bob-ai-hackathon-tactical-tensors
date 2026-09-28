import { useRef, useState } from "react";
import { scanAmReportPdf } from "../../api/reports";
import { toApiError } from "../../api/errors";

/**
 * Scan upload panel for the ante-mortem (family report) intake form.
 *
 * Accepts a photograph or scan of a completed paper AM form. The AI reads
 * it and calls onResult with whatever fields it could extract — the caller
 * merges them into form state for human review before anything is saved.
 *
 * Only PDF/image is offered (no live-scan body photo — that is a PM concept).
 */
export default function AmScanUploadPanel({
    incidentId,
    onResult,
}: {
    incidentId: string;
    onResult: (fields: Record<string, unknown>) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    const [fileName, setFileName] = useState("");
    const inputRef = useRef<HTMLInputElement>(null);

    async function handleFile(file: File) {
        setFileName(file.name);
        setBusy(true);
        setError("");
        setMessage("");

        try {
            const { data } = await scanAmReportPdf(incidentId, file);

            if (!data.ai_available) {
                setMessage(
                    data.reason ??
                    "AI assist is unavailable right now. Fill the form manually.",
                );
            } else if (data.fields) {
                onResult(data.fields);
                const providerLabel =
                    data.provider === "claude"
                        ? "Claude"
                        : data.provider === "gemini"
                          ? "Bob by IBM"
                          : "AI";
                setMessage(
                    `${providerLabel} read the form. Prefilled fields are marked "AI-suggested" — review each one before saving.`,
                );
            }
        } catch (caught) {
            setError(toApiError(caught, "Could not read the file.").message);
        } finally {
            setBusy(false);
        }
    }

    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (file) handleFile(file);
    }

    function handleDrop(e: React.DragEvent<HTMLDivElement>) {
        e.preventDefault();
        const file = e.dataTransfer.files?.[0];
        if (file) handleFile(file);
    }

    return (
        <div className="card scan-panel am-scan-panel">
            {/* Header */}
            <div className="scan-panel-header">
                <div className="scan-panel-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="9" y1="12" x2="15" y2="12" />
                        <line x1="9" y1="16" x2="13" y2="16" />
                    </svg>
                </div>
                <div>
                    <h3>Scan the filled paper form</h3>
                    <p className="muted">
                        Upload a photograph or scan of the completed ante-mortem form.
                        The AI reads it and prefills the fields below — nothing is saved until
                        you review and submit.
                    </p>
                </div>
            </div>

            {/* Drop zone */}
            <div
                className={`scan-dropzone${busy ? " scan-dropzone--busy" : ""}`}
                onDragOver={(e) => e.preventDefault()}
                onDrop={handleDrop}
                onClick={() => !busy && inputRef.current?.click()}
                role="button"
                tabIndex={0}
                aria-label="Upload form scan — click or drag a file here"
                onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") inputRef.current?.click(); }}
            >
                <input
                    ref={inputRef}
                    type="file"
                    accept="application/pdf,image/*"
                    style={{ display: "none" }}
                    onChange={handleChange}
                    aria-hidden="true"
                />

                {busy ? (
                    <div className="scan-dropzone-inner">
                        <div className="scan-busy-bars" aria-label="Reading form…">
                            <span /><span /><span /><span />
                        </div>
                        <span className="scan-dropzone-label">Reading form…</span>
                    </div>
                ) : (
                    <div className="scan-dropzone-inner">
                        <svg className="scan-dropzone-upload-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                        <span className="scan-dropzone-label">
                            {fileName
                                ? fileName
                                : "Click or drag a PDF / photo here"}
                        </span>
                        <span className="scan-dropzone-hint">
                            PDF or any image format · max 15 MB
                        </span>
                    </div>
                )}
            </div>

            {message && (
                <div className="notice" style={{ marginBottom: 0, marginTop: 10 }}>
                    {message}
                </div>
            )}
            {error && (
                <div className="error-banner" style={{ marginBottom: 0, marginTop: 10 }}>
                    {error}
                </div>
            )}
        </div>
    );
}
