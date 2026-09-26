import { useEffect, useState } from "react";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import { LANGUAGE } from "../../lib/format";
import type { EvaluationMetrics, EvaluationPayload, Prf } from "../../types";

/**
 * How good is this, really?
 *
 * A DVI tool that cannot state its own error rate should not be trusted with
 * the decision it is being used for. This screen puts the system beside the
 * dataset's own published baseline and separates three questions that are
 * easy to conflate: how well the forms are read, how well the scorer ranks
 * given perfect reading, and what the pipeline achieves unaided.
 */
export default function EvaluationLab() {
    const { incident } = useIncident();

    const [payload, setPayload] = useState<EvaluationPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [split, setSplit] = useState<"all" | "dev" | "holdout">("all");

    useEffect(() => {
        api.get(`/incidents/${incident.incident_id}/evaluation`)
            .then(({ data }) => setPayload(data))
            .catch((caught) => setError(toApiError(caught, "Evaluation unavailable.").message))
            .finally(() => setLoading(false));
    }, [incident.incident_id]);

    if (loading) return <div className="empty">Measuring…</div>;
    if (!payload) return <div className="error-banner">{error}</div>;

    const { baseline, runs, extraction, notes } = payload;
    const versions = Object.keys(runs);

    const oracle = versions.find((v) => v.startsWith("oracle"));
    const endToEnd = versions.find((v) => !v.startsWith("oracle"));

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Evaluation lab</h2>
                    <p className="muted">
                        Measured against the dataset's hidden ground truth. Nothing on this screen
                        feeds the pipeline — truth enters only to be compared against.
                    </p>
                </div>

                <select value={split} onChange={(e) => setSplit(e.target.value as typeof split)}>
                    <option value="all">All pairs</option>
                    <option value="dev">Development split</option>
                    <option value="holdout">Holdout split</option>
                </select>
            </div>

            <div className="disclaimer">
                Figures describe this synthetic dataset, not real-world accuracy. The weights were
                chosen on the development split; the holdout split was never used to tune them.
            </div>

            {oracle && runs[oracle][split] && (
                <MetricTable
                    title="Scorer quality, like-for-like with the published baseline"
                    caption={
                        "Both the baseline and this row are measured on the gold normalisation, so the " +
                        "comparison isolates ranking quality from how well the forms were read."
                    }
                    metrics={runs[oracle][split]}
                    baseline={split === "all" ? baseline : null}
                />
            )}

            {endToEnd && runs[endToEnd][split] && (
                <MetricTable
                    title="End-to-end — what the pipeline actually achieves"
                    caption={
                        "Real extraction feeding the real scorer. This is the honest figure, and it is " +
                        "lower than the row above by exactly the amount Stage 1 gets wrong."
                    }
                    metrics={runs[endToEnd][split]}
                    baseline={null}
                />
            )}

            <h3 style={{ margin: "28px 0 12px" }}>Stage 1 — reading the forms</h3>
            <p className="muted" style={{ marginBottom: 14 }}>
                The ceiling on everything downstream: the scorer can only weigh findings the
                extractor managed to read out of the text.
            </p>

            {Object.entries(extraction).map(([version, quality]) => (
                <div className="card" key={version} style={{ marginBottom: 14 }}>
                    <h3>
                        {version}{" "}
                        <span className="badge badge-accent">F1 {quality.overall.f1}%</span>
                    </h3>

                    <div className="two-col" style={{ marginTop: 14 }}>
                        <PrfTable
                            title="By language"
                            rows={quality.by_lang}
                            label={(key) => LANGUAGE[key] ?? key}
                        />
                        <PrfTable title="By feature" rows={quality.by_category} label={(k) => k} />
                    </div>
                </div>
            ))}

            {endToEnd && runs[endToEnd][split] && (
                <Breakdowns metrics={runs[endToEnd][split]} />
            )}

            <div className="card" style={{ marginTop: 18 }}>
                <div className="rationale-heading">Reading these numbers</div>
                <ul className="rationale-list">
                    {notes.map((note, i) => (
                        <li key={i}>{note}</li>
                    ))}
                </ul>
            </div>
        </>
    );
}

function MetricTable({
    title,
    caption,
    metrics,
    baseline,
}: {
    title: string;
    caption: string;
    metrics: EvaluationMetrics;
    baseline: EvaluationPayload["baseline"] | null;
}) {
    const rows: Array<[string, number, number, number | null, string]> = [
        ["Correct first choice", metrics.recall_at_1, metrics.true_pairs, baseline?.recall_at_1 ?? null, "up"],
        ["True pair in top three", metrics.recall_at_3, metrics.true_pairs, baseline?.recall_at_3 ?? null, "up"],
        [
            "Incident-wide assignment correct",
            metrics.assignment_correct,
            metrics.true_pairs,
            baseline?.assignment ?? null,
            "up",
        ],
        [
            "Correctly refused (no partner exists)",
            metrics.refusals_correct,
            metrics.refusal_total,
            baseline?.refusals_correct ?? null,
            "up",
        ],
        [
            "Wrongly refused (partner did exist)",
            metrics.false_refusals,
            metrics.true_pairs,
            null,
            "down",
        ],
        [
            "True pair never offered at all",
            metrics.false_exclusions,
            metrics.true_pairs,
            baseline?.false_exclusions ?? null,
            "down",
        ],
    ];

    return (
        <div className="card" style={{ marginBottom: 16 }}>
            <h3>{title}</h3>
            <p className="muted" style={{ marginTop: 6, marginBottom: 14 }}>
                {caption}
            </p>

            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>This system</th>
                            <th>Naive baseline</th>
                            <th>Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(([label, value, total, base, direction]) => {
                            const delta = base === null ? null : value - base;
                            const better =
                                delta === null
                                    ? null
                                    : direction === "up"
                                      ? delta > 0
                                      : delta < 0;

                            return (
                                <tr key={label}>
                                    <td>{label}</td>
                                    <td>
                                        <strong>
                                            {value} / {total}
                                        </strong>
                                    </td>
                                    <td className="muted">{base === null ? "—" : `${base} / ${total}`}</td>
                                    <td>
                                        {delta === null ? (
                                            "—"
                                        ) : delta === 0 ? (
                                            <span className="faint">same</span>
                                        ) : (
                                            <span className={better ? "weight-positive" : "weight-negative"}>
                                                {delta > 0 ? "+" : ""}
                                                {delta}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function PrfTable({
    title,
    rows,
    label,
}: {
    title: string;
    rows: Record<string, Prf>;
    label: (key: string) => string;
}) {
    const entries = Object.entries(rows).sort((a, b) => b[1].gold - a[1].gold);

    return (
        <div>
            <div className="rationale-heading">{title}</div>
            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{title.includes("language") ? "Language" : "Feature"}</th>
                            <th>Items</th>
                            <th>Precision</th>
                            <th>Recall</th>
                            <th>F1</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map(([key, prf]) => (
                            <tr key={key}>
                                <td>{label(key)}</td>
                                <td>{prf.gold}</td>
                                <td>{prf.precision}%</td>
                                <td>{prf.recall}%</td>
                                <td>
                                    <strong>{prf.f1}%</strong>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function Breakdowns({ metrics }: { metrics: EvaluationMetrics }) {
    return (
        <>
            <h3 style={{ margin: "28px 0 12px" }}>Where it struggles</h3>

            <div className="two-col">
                <BucketTable title="By case type" bucket={metrics.per_case_type} />
                <BucketTable title="By injected difficulty" bucket={metrics.per_tag} />
            </div>

            {metrics.misses.length > 0 && (
                <div className="card" style={{ marginTop: 16 }}>
                    <div className="rationale-heading">
                        {metrics.misses.length} pairings not ranked first
                    </div>
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Body</th>
                                    <th>True partner</th>
                                    <th>Ranked first instead</th>
                                    <th>True rank</th>
                                    <th>Why it is hard</th>
                                </tr>
                            </thead>
                            <tbody>
                                {metrics.misses.map((m) => (
                                    <tr key={m.pm_id}>
                                        <td className="mono">{m.pm_id}</td>
                                        <td className="mono">{m.expected}</td>
                                        <td className="mono">{m.got ?? "—"}</td>
                                        <td>{m.true_rank ?? "never offered"}</td>
                                        <td className="muted">
                                            {m.case_type}
                                            {m.tags && ` · ${m.tags.replace(/,/g, ", ")}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}

function BucketTable({
    title,
    bucket,
}: {
    title: string;
    bucket: Record<string, { n: number; top1: number; top3: number }>;
}) {
    const entries = Object.entries(bucket).sort((a, b) => b[1].n - a[1].n);

    if (entries.length === 0) return null;

    return (
        <div>
            <div className="rationale-heading">{title}</div>
            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Pairs</th>
                            <th>First choice</th>
                            <th>Top three</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map(([key, c]) => (
                            <tr key={key}>
                                <td>{key.replace(/_/g, " ")}</td>
                                <td>{c.n}</td>
                                <td>
                                    {c.top1} ({Math.round((c.top1 / c.n) * 100)}%)
                                </td>
                                <td>
                                    {c.top3} ({Math.round((c.top3 / c.n) * 100)}%)
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
