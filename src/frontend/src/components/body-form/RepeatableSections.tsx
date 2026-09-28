import { useState } from "react";
import { uploadFile } from "../../api/bodies";
import { FileUploadField } from "../Field";
import {
    BELONGING_SUGGESTIONS,
    ID_DOCUMENT_TYPE_SUGGESTIONS,
    JEWELLERY_ITEM_SUGGESTIONS,
    JEWELLERY_KIND_OPTIONS,
    PHOTO_MODALITY_OPTIONS,
    QUALITY_FLAG_OPTIONS,
    type IdDocumentRow,
    type JewelleryEffectRow,
    type PhotoLogRow,
} from "../../types";

const MAX_JEWELLERY_ROWS = 3;

export function JewelleryTable({
    rows,
    onChange,
}: {
    rows: JewelleryEffectRow[];
    onChange: (rows: JewelleryEffectRow[]) => void;
}) {
    function update(index: number, patch: Partial<JewelleryEffectRow>) {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function add() {
        if (rows.length >= MAX_JEWELLERY_ROWS) return;
        onChange([...rows, { kind: "jewellery", item: "", material_description: "" }]);
    }

    function remove(index: number) {
        onChange(rows.filter((_, i) => i !== index));
    }

    if (rows.length === 0) {
        return (
            <div className="repeatable-empty">
                <p className="muted">No jewellery or personal effects recorded yet.</p>
                <button type="button" className="btn btn-primary btn-sm" onClick={add}>
                    + Add jewellery / belonging
                </button>
            </div>
        );
    }

    return (
        <div className="repeatable-table">
            <datalist id="jewellery-item-suggestions">
                {JEWELLERY_ITEM_SUGGESTIONS.map((s) => (
                    <option key={s} value={s} />
                ))}
            </datalist>
            <datalist id="belonging-suggestions">
                {BELONGING_SUGGESTIONS.map((s) => (
                    <option key={s} value={s} />
                ))}
            </datalist>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Material / description</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i}>
                            <td data-label="Type">
                                <select
                                    className="select-input"
                                    value={row.kind}
                                    onChange={(e) => update(i, { kind: e.target.value as "jewellery" | "belonging" })}
                                >
                                    {JEWELLERY_KIND_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </td>
                            <td data-label="Item">
                                <input
                                    list={row.kind === "jewellery" ? "jewellery-item-suggestions" : "belonging-suggestions"}
                                    autoComplete="off"
                                    value={row.item}
                                    onChange={(e) => update(i, { item: e.target.value })}
                                />
                            </td>
                            <td data-label="Material / description">
                                <input
                                    value={row.material_description}
                                    onChange={(e) => update(i, { material_description: e.target.value })}
                                />
                            </td>
                            <td data-label="" className="repeatable-table-actions">
                                <button type="button" className="repeatable-table-remove" onClick={() => remove(i)} aria-label="Remove row">
                                    ×
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {rows.length < MAX_JEWELLERY_ROWS && (
                <button type="button" className="btn btn-sm" onClick={add}>
                    + Add jewellery / belonging
                </button>
            )}
        </div>
    );
}

export function IdDocumentsTable({
    rows,
    onChange,
}: {
    rows: IdDocumentRow[];
    onChange: (rows: IdDocumentRow[]) => void;
}) {
    function update(index: number, patch: Partial<IdDocumentRow>) {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function add() {
        onChange([...rows, { document_type: "", id_last4: "", name_on_document: "", note: "" }]);
    }

    function remove(index: number) {
        onChange(rows.filter((_, i) => i !== index));
    }

    if (rows.length === 0) {
        return (
            <div className="repeatable-empty">
                <p className="muted">No identity documents recorded yet.</p>
                <button type="button" className="btn btn-primary btn-sm" onClick={add}>
                    + Add document
                </button>
            </div>
        );
    }

    return (
        <div className="repeatable-table">
            <datalist id="id-document-type-suggestions">
                {ID_DOCUMENT_TYPE_SUGGESTIONS.map((s) => (
                    <option key={s} value={s} />
                ))}
            </datalist>
            <table>
                <thead>
                    <tr>
                        <th>Document type</th>
                        <th>ID no. (last 4)</th>
                        <th>Name printed</th>
                        <th>Note</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i}>
                            <td data-label="Document type">
                                <input
                                    list="id-document-type-suggestions"
                                    autoComplete="off"
                                    value={row.document_type}
                                    onChange={(e) => update(i, { document_type: e.target.value })}
                                />
                            </td>
                            <td data-label="ID no. (last 4)">
                                <input
                                    value={row.id_last4}
                                    maxLength={4}
                                    inputMode="numeric"
                                    onChange={(e) => update(i, { id_last4: e.target.value })}
                                />
                            </td>
                            <td data-label="Name printed">
                                <input
                                    value={row.name_on_document}
                                    onChange={(e) => update(i, { name_on_document: e.target.value })}
                                />
                            </td>
                            <td data-label="Note">
                                <input value={row.note} onChange={(e) => update(i, { note: e.target.value })} />
                            </td>
                            <td data-label="" className="repeatable-table-actions">
                                <button type="button" className="repeatable-table-remove" onClick={() => remove(i)} aria-label="Remove row">
                                    ×
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <button type="button" className="btn btn-sm" onClick={add}>
                + Add document
            </button>
        </div>
    );
}

export function PhotoLogTable({
    rows,
    onChange,
    incidentId,
}: {
    rows: PhotoLogRow[];
    onChange: (rows: PhotoLogRow[]) => void;
    incidentId: string;
}) {
    const [uploadingIndex, setUploadingIndex] = useState<number | null>(null);

    function update(index: number, patch: Partial<PhotoLogRow>) {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function add() {
        onChange([...rows, { label: String(rows.length + 1), modality: "", view: "", quality_flags: [], upload_ref: "" }]);
    }

    function remove(index: number) {
        onChange(rows.filter((_, i) => i !== index));
    }

    function toggleFlag(index: number, flag: string) {
        const current = rows[index].quality_flags;
        update(index, {
            quality_flags: current.includes(flag) ? current.filter((f) => f !== flag) : [...current, flag],
        });
    }

    async function handlePhoto(index: number, file: File) {
        setUploadingIndex(index);

        try {
            const { data } = await uploadFile(incidentId, file);
            update(index, { upload_ref: data.upload_ref });
        } catch {
            // Left blank — the row stays editable and the upload can be retried.
        } finally {
            setUploadingIndex(null);
        }
    }

    return (
        <div className="repeatable-table">
            <p className="disclaimer">
                A face photo is never matched by the system — take one anyway for family viewing only.
            </p>

            {rows.length === 0 ? (
                <div className="repeatable-empty">
                    <p className="muted">No photo log entries yet.</p>
                    <button type="button" className="btn btn-primary btn-sm" onClick={add}>
                        + Add photo log entry
                    </button>
                </div>
            ) : (
                <>
                    <table>
                        <thead>
                            <tr>
                                <th>Photo ID</th>
                                <th>Modality</th>
                                <th>View</th>
                                <th>Quality flags</th>
                                <th>File</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, i) => (
                                <tr key={i}>
                                    <td data-label="Photo ID">
                                        <input value={row.label} onChange={(e) => update(i, { label: e.target.value })} className="input-narrow" />
                                    </td>
                                    <td data-label="Modality">
                                        <select
                                            className="select-input"
                                            value={row.modality}
                                            onChange={(e) => update(i, { modality: e.target.value })}
                                        >
                                            <option value="">Select…</option>
                                            {PHOTO_MODALITY_OPTIONS.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td data-label="View">
                                        <input
                                            value={row.view}
                                            placeholder="front / back / close-up"
                                            onChange={(e) => update(i, { view: e.target.value })}
                                        />
                                    </td>
                                    <td data-label="Quality flags">
                                        <div className="checkbox-group checkbox-group-tight">
                                            {QUALITY_FLAG_OPTIONS.map((o) => {
                                                const checked = row.quality_flags.includes(o.value);
                                                return (
                                                    <label key={o.value} className={`checkbox-pill${checked ? " checked" : ""}`}>
                                                        <input type="checkbox" checked={checked} onChange={() => toggleFlag(i, o.value)} />
                                                        {o.label}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </td>
                                    <td data-label="File">
                                        {row.modality === "Face (restr.)" ? (
                                            <span className="faint">display-restricted — not uploaded</span>
                                        ) : row.upload_ref ? (
                                            <span className="chip chip-match">attached</span>
                                        ) : (
                                            <FileUploadField
                                                compact
                                                label="Attach photo"
                                                name={`photo-log-${i}`}
                                                accept="image/*"
                                                busy={uploadingIndex === i}
                                                onFileSelected={(file) => handlePhoto(i, file)}
                                            />
                                        )}
                                    </td>
                                    <td data-label="" className="repeatable-table-actions">
                                        <button type="button" className="repeatable-table-remove" onClick={() => remove(i)} aria-label="Remove row">
                                            ×
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <button type="button" className="btn btn-sm" onClick={add}>
                        + Add photo log entry
                    </button>
                </>
            )}
        </div>
    );
}
