import { useState } from "react";
import { describeItem, signed, TIER_LABEL, VERDICT_LABEL } from "../lib/format";
import type { EvidenceRow } from "../types";

/**
 * The per-category breakdown behind one pairing.
 *
 * Every row is attributable: what the examiner recorded, what the family
 * reported, how the system read the two together, and how much that moved the
 * score. Conflicts are never averaged into the total silently — they sit in
 * the table in their own colour so a reviewer sees what disagrees as easily as
 * what agrees.
 */
export default function EvidenceTable({ evidence }: { evidence: EvidenceRow[] }) {
    const [showUninformative, setShowUninformative] = useState(false);

    const informative = evidence.filter((row) => row.verdict === "match" || row.verdict === "conflict");

    // "Not recorded" and "not assessable" rows matter — they are the reason a
    // pairing is thin — but they would otherwise bury the findings.
    const rest = evidence.filter((row) => row.verdict !== "match" && row.verdict !== "conflict");

    const shown = showUninformative ? [...informative, ...rest] : informative;

    if (evidence.length === 0) {
        return <p className="muted">No evidence recorded for this pairing.</p>;
    }

    return (
        <>
            <div className="table-wrap">
                <table className="evidence-table">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Reliability</th>
                            <th>Post-mortem</th>
                            <th>Family report</th>
                            <th>Reading</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((row) => (
                            <tr key={row.id} className={`verdict-${row.verdict}`}>
                                <td>
                                    <strong>{row.category.replace(/_/g, " ")}</strong>
                                    <div className="faint">{row.rationale}</div>
                                </td>
                                <td>
                                    {row.tier && (
                                        <span className={`chip tier-${row.tier}`}>
                                            {TIER_LABEL[row.tier]}
                                        </span>
                                    )}
                                </td>
                                <td className="muted">{describeItem(row.pm_item)}</td>
                                <td className="muted">{describeItem(row.am_item)}</td>
                                <td>
                                    <span className={`badge badge-verdict-${row.verdict}`}>
                                        {VERDICT_LABEL[row.verdict]}
                                    </span>
                                </td>
                                <td className={row.llr < 0 ? "weight-negative" : "weight-positive"}>
                                    {row.llr === 0 ? "—" : signed(row.llr)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {rest.length > 0 && (
                <button
                    type="button"
                    className="btn btn-sm"
                    style={{ marginTop: 10 }}
                    onClick={() => setShowUninformative((open) => !open)}
                >
                    {showUninformative ? "Hide" : "Show"} {rest.length} feature
                    {rest.length === 1 ? "" : "s"} that carried no information
                </button>
            )}
        </>
    );
}
