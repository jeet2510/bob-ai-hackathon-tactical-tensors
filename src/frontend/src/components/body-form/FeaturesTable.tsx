import { FEATURE_TYPE_OPTIONS, REGION_SUGGESTIONS, SIDE_OPTIONS, type DistinguishingFeatureRow } from "../../types";

const MAX_ROWS = 6;

export default function FeaturesTable({
    rows,
    onChange,
}: {
    rows: DistinguishingFeatureRow[];
    onChange: (rows: DistinguishingFeatureRow[]) => void;
}) {
    function update(index: number, patch: Partial<DistinguishingFeatureRow>) {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function add() {
        if (rows.length >= MAX_ROWS) return;
        onChange([...rows, { type: "", description: "", region: "", side: "", serial_no: "", photo_ref: "" }]);
    }

    function remove(index: number) {
        onChange(rows.filter((_, i) => i !== index));
    }

    if (rows.length === 0) {
        return (
            <div className="repeatable-empty">
                <p className="muted">No distinguishing features recorded yet.</p>
                <button type="button" className="btn btn-primary btn-sm" onClick={add}>
                    + Add distinguishing feature
                </button>
            </div>
        );
    }

    return (
        <div className="repeatable-table">
            <datalist id="region-suggestions">
                {REGION_SUGGESTIONS.map((r) => (
                    <option key={r} value={r} />
                ))}
            </datalist>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Region</th>
                        <th>Side</th>
                        <th>Serial no.</th>
                        <th>Photo ID</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="repeatable-table-row">
                            <td data-label="#">
                                <span className="row-index">{i + 1}</span>
                            </td>
                            <td data-label="Type">
                                <select className="select-input" value={row.type} onChange={(e) => update(i, { type: e.target.value })}>
                                    <option value="">Select…</option>
                                    {FEATURE_TYPE_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </td>
                            <td data-label="Description">
                                <input value={row.description} onChange={(e) => update(i, { description: e.target.value })} />
                            </td>
                            <td data-label="Region">
                                <input
                                    list="region-suggestions"
                                    autoComplete="off"
                                    value={row.region}
                                    placeholder="e.g. forearm"
                                    onChange={(e) => update(i, { region: e.target.value })}
                                />
                            </td>
                            <td data-label="Side">
                                <select
                                    className="select-input"
                                    value={row.side}
                                    onChange={(e) => update(i, { side: e.target.value as "L" | "R" | "C" | "" })}
                                >
                                    <option value="">—</option>
                                    {SIDE_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </td>
                            <td data-label="Serial no.">
                                <input
                                    value={row.serial_no}
                                    disabled={row.type !== "implant"}
                                    placeholder={row.type === "implant" ? "strongest evidence" : ""}
                                    onChange={(e) => update(i, { serial_no: e.target.value })}
                                />
                            </td>
                            <td data-label="Photo ID">
                                <input
                                    value={row.photo_ref}
                                    placeholder="J-log #"
                                    onChange={(e) => update(i, { photo_ref: e.target.value })}
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
            {rows.length < MAX_ROWS && (
                <button type="button" className="btn btn-sm" onClick={add}>
                    + Add distinguishing feature
                </button>
            )}
        </div>
    );
}
