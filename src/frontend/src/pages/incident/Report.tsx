import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import { BAND_CLASS, BAND_LABEL, DECISION_LABEL, signed } from "../../lib/format";
import type { ReconciliationReport, SectionOutcome } from "../../types";

const OUTCOME: Record<SectionOutcome, { label: string; badge: string }> = {
    referred_for_confirmation: { label: "Referred for confirmation", badge: "badge-high" },
    awaiting_review: { label: "Awaiting review", badge: "badge-moderate" },
    no_credible_candidate: { label: "No credible candidate", badge: "badge-none" },
};

export default function Report() {
    const { incident } = useIncident();

    const [report, setReport] = useState<ReconciliationReport | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        api.get(`/incidents/${incident.incident_id}/report`)
            .then(({ data }) => setReport(data.report))
            .catch((caught) => setError(toApiError(caught, "Could not build the report.").message))
            .finally(() => setLoading(false));
    }, [incident.incident_id]);

    if (loading) return <div className="empty">Building report…</div>;
    if (!report) return <div className="error-banner">{error}</div>;

    const { summary } = report;

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Reconciliation report</h2>
                    <p className="muted">
                        {report.incident.name} · generated {report.generated_at}
                        {report.run && ` · ${report.run.scorer_version} on ${report.run.extractor_version}`}
                    </p>
                </div>

                <button className="btn" onClick={() => window.print()}>
                    Print / save as PDF
                </button>
            </div>

            <div className="disclaimer">{report.disclaimer}</div>

            <div className="stat-grid">
                <Stat value={summary.bodies} label="Bodies" />
                <Stat value={summary.referred_for_confirmation} label="Referred for testing" />
                <Stat value={summary.awaiting_review} label="Awaiting review" />
                <Stat value={summary.no_credible_candidate} label="No credible candidate" />
                <Stat value={summary.no_confirmation_route} label="No confirmation route" />
                <Stat value={summary.families_still_waiting} label="Families still waiting" />
            </div>

            <h3 style={{ margin: "26px 0 12px" }}>Per body</h3>

            {report.sections.map((section) => (
                <section className="report-section" key={String(section.body.pm_id)}>
                    <div className="report-section-head">
                        <div>
                            <h3 className="mono">{section.body.pm_id}</h3>
                            <p className="muted">
                                {section.body.sex ?? "sex not recorded"}
                                {section.body.age_estimate && ` · ${section.body.age_estimate} yrs`}
                                {section.body.height_cm && ` · ${section.body.height_cm} cm`}
                            </p>
                            <p className="faint">
                                {section.body.found_place ?? "recovery place not recorded"} ·{" "}
                                {section.body.body_condition}
                            </p>
                        </div>
                        <span className={`badge ${OUTCOME[section.outcome].badge}`}>
                            {OUTCOME[section.outcome].label}
                        </span>
                    </div>

                    {section.decision && (
                        <p className="muted">
                            <strong>{DECISION_LABEL[section.decision.decision]}</strong> by{" "}
                            {section.decision.reviewer} on {section.decision.decided_at.slice(0, 16)}
                            {section.decision.note && ` — ${section.decision.note}`}
                        </p>
                    )}

                    {section.candidates.length === 0 ? (
                        <p className="muted">
                            No family report resembles this body strongly enough to test. Refer for
                            DNA sampling and keep the record open.
                        </p>
                    ) : (
                        section.candidates.map((candidate) => (
                            <div className="report-candidate" key={candidate.am_id}>
                                <div className="report-candidate-head">
                                    <div>
                                        <strong>
                                            {candidate.rank}. {candidate.reported_name ?? candidate.am_id}
                                        </strong>{" "}
                                        <span className="mono faint">{candidate.am_id}</span>
                                        {candidate.reported_by_relation && (
                                            <div className="faint">
                                                reported by {candidate.reported_by_relation}
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <span className={`badge badge-${BAND_CLASS[candidate.confidence_band]}`}>
                                            {BAND_LABEL[candidate.confidence_band]}
                                        </span>{" "}
                                        <span className="faint">{signed(candidate.score)}</span>
                                    </div>
                                </div>

                                {candidate.recommended_route && (
                                    <p className="muted">
                                        <strong>Confirm by:</strong> {candidate.recommended_route.label}
                                    </p>
                                )}

                                {candidate.key_evidence.length > 0 && (
                                    <ul className="rationale-list">
                                        {candidate.key_evidence.map((e, i) => (
                                            <li key={i} className={e.verdict === "conflict" ? "conflict-line" : ""}>
                                                {e.rationale} <span className="faint">({signed(e.llr)})</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))
                    )}

                    <div className="no-print" style={{ marginTop: 12 }}>
                        <Link
                            className="btn btn-sm"
                            to={`/incidents/${incident.incident_id}/bodies/${section.body.pm_id}`}
                        >
                            Open review
                        </Link>
                    </div>
                </section>
            ))}

            <h3 style={{ margin: "26px 0 12px" }}>
                Families still waiting ({report.outstanding_profiles.length})
            </h3>
            <p className="muted" style={{ marginBottom: 12 }}>
                Missing-person reports with no body referred for confirmation. In a real response
                these are the people whose relatives are still at the mortuary gate.
            </p>

            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Reported name</th>
                            <th>Sex / age</th>
                            <th>Reported by</th>
                            <th>Last seen</th>
                            <th>Reference samples</th>
                        </tr>
                    </thead>
                    <tbody>
                        {report.outstanding_profiles.map((p) => (
                            <tr key={p.am_id}>
                                <td className="mono">{p.am_id}</td>
                                <td>{p.reported_name}</td>
                                <td>
                                    {p.sex ?? "—"}
                                    {p.age !== null && ` · ${p.age}`}
                                </td>
                                <td>{p.reported_by_relation ?? "—"}</td>
                                <td>{p.last_seen_place ?? "—"}</td>
                                <td className="faint">
                                    {[
                                        p.dna_reference_type ? `DNA (${p.dna_reference_type.replace(/_/g, " ")})` : null,
                                        p.dental_records_available ? "dental records" : null,
                                    ]
                                        .filter(Boolean)
                                        .join(", ") || "none on file"}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function Stat({ value, label }: { value: number; label: string }) {
    return (
        <div className="stat">
            <div className="stat-value">{value}</div>
            <div className="stat-label">{label}</div>
        </div>
    );
}
