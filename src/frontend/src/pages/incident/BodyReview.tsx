import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import EvidenceTable from "../../components/EvidenceTable";
import ObservationPanel from "../../components/ObservationPanel";
import PhotoStrip from "../../components/PhotoStrip";
import { useIncident } from "../IncidentLayout";
import { BAND_CLASS, BAND_LABEL, DECISION_LABEL, signed } from "../../lib/format";
import type { BodyDetail, CandidateDetail, DecisionKind } from "../../types";

type Tab = "candidates" | "observations" | "photos" | "history";

export default function BodyReview() {
    const { incident } = useIncident();
    const { pmId } = useParams();

    const [detail, setDetail] = useState<BodyDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [tab, setTab] = useState<Tab>("candidates");

    const load = useCallback(async () => {
        try {
            const { data } = await api.get(`/incidents/${incident.incident_id}/bodies/${pmId}`);
            setDetail(data);
            setError("");
        } catch (caught) {
            setError(toApiError(caught, "Could not load this body.").message);
        } finally {
            setLoading(false);
        }
    }, [incident.incident_id, pmId]);

    useEffect(() => {
        load();
    }, [load]);

    if (loading) {
        return <div className="empty">Loading…</div>;
    }

    if (!detail) {
        return <div className="error-banner">{error || "Body not found."}</div>;
    }

    const { body, candidates, observations, photos, decisions } = detail;
    const current = decisions[0];

    return (
        <>
            <div className="page-head">
                <div>
                    <h2 className="mono">{body.pm_id}</h2>
                    <p className="muted">
                        {body.sex ?? "sex not recorded"}
                        {body.age_min !== null && ` · estimated ${body.age_min}–${body.age_max}`}
                        {body.height_cm && ` · ${body.height_cm} cm`}
                        {body.found_place && ` · ${body.found_place}`}
                    </p>
                    <p className="faint">
                        {body.body_condition} · dental {body.dental_status} · DNA {body.dna_status} ·
                        prints {body.print_status}
                    </p>
                </div>

                <Link className="btn" to={`/incidents/${incident.incident_id}`}>
                    Back to command board
                </Link>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <div className="disclaimer">
                Ranked suggestions for which family report to <em>test</em> first. Nothing here is an
                identification: INTERPOL requires a match on fingerprints, dental records or DNA
                before remains are released.
            </div>

            {current && (
                <div className="notice">
                    Current decision: <strong>{DECISION_LABEL[current.decision]}</strong>
                    {current.am_id && ` — ${current.am_id}`} by {current.reviewer} on{" "}
                    {current.decided_at.slice(0, 16)}
                    {current.note && <div className="faint">{current.note}</div>}
                </div>
            )}

            <nav className="tabs subtabs">
                <button className={tab === "candidates" ? "active" : ""} onClick={() => setTab("candidates")}>
                    Candidates ({candidates.length})
                </button>
                <button className={tab === "observations" ? "active" : ""} onClick={() => setTab("observations")}>
                    Source evidence ({observations.length})
                </button>
                <button className={tab === "photos" ? "active" : ""} onClick={() => setTab("photos")}>
                    Photographs ({photos.length})
                </button>
                <button className={tab === "history" ? "active" : ""} onClick={() => setTab("history")}>
                    Decision history ({decisions.length})
                </button>
            </nav>

            {tab === "candidates" && (
                candidates.length === 0 ? (
                    <div className="empty">
                        <strong>No credible candidate.</strong>
                        <p className="muted" style={{ marginTop: 8 }}>
                            No family report in this incident resembles this body strongly enough to
                            be worth testing. That is a finding, not a gap — refer for DNA sampling
                            and keep the record open.
                        </p>
                    </div>
                ) : (
                    candidates.map((candidate) => (
                        <CandidateCard key={candidate.am_id} candidate={candidate} />
                    ))
                )
            )}

            {tab === "observations" && (
                <ObservationPanel observations={observations} onCorrected={load} />
            )}

            {tab === "photos" && <PhotoStrip photos={photos} />}

            {tab === "history" && (
                decisions.length === 0 ? (
                    <div className="empty">No decision recorded for this body yet.</div>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Decision</th>
                                    <th>Pairing</th>
                                    <th>Reviewer</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {decisions.map((d) => (
                                    <tr key={d.id}>
                                        <td>{d.decided_at.slice(0, 16)}</td>
                                        <td>
                                            <span className="badge badge-accent">
                                                {DECISION_LABEL[d.decision]}
                                            </span>
                                        </td>
                                        <td className="mono">{d.am_id ?? "—"}</td>
                                        <td>{d.reviewer}</td>
                                        <td className="muted">{d.note ?? "—"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )
            )}

            <DecisionForm
                incidentId={incident.incident_id}
                pmId={body.pm_id}
                candidates={candidates}
                onDecided={load}
            />
        </>
    );
}

function CandidateCard({ candidate }: { candidate: CandidateDetail }) {
    const profile = candidate.profile;
    const route = candidate.recommended_route;

    return (
        <article className={`candidate band-${BAND_CLASS[candidate.confidence_band]}`}>
            <header className="candidate-head">
                <div>
                    <div className="candidate-rank">
                        Candidate {candidate.rank}
                        {candidate.assigned && " · preferred by incident-wide assignment"}
                    </div>
                    <div className="candidate-name">{profile?.reported_name ?? candidate.am_id}</div>
                    <div className="muted">
                        <span className="mono">{candidate.am_id}</span>
                        {profile?.sex && ` · ${profile.sex}`}
                        {profile?.age !== null && profile?.age !== undefined && ` · ${profile.age} yrs`}
                        {profile?.height_text && ` · reported ${profile.height_text}`}
                    </div>
                    {profile?.reported_by_relation && (
                        <div className="faint">
                            Reported by {profile.reported_by_relation}
                            {profile.last_seen_place && ` · last seen ${profile.last_seen_place}`}
                        </div>
                    )}
                </div>

                <div className="candidate-score">
                    <div className={`score-value score-${BAND_CLASS[candidate.confidence_band]}`}>
                        {signed(candidate.score)}
                    </div>
                    <div className="score-caption">evidence strength</div>
                    <span className={`badge badge-${BAND_CLASS[candidate.confidence_band]}`}>
                        {BAND_LABEL[candidate.confidence_band]}
                    </span>
                    <div className="score-caption">{candidate.coverage} categories informative</div>
                </div>
            </header>

            <div className="candidate-body">
                {route && (
                    <div className={route.route ? "route route-available" : "route route-blocked"}>
                        <div className="rationale-heading">Confirm by</div>
                        <strong>{route.label}</strong>
                        {route.available[0] && <p className="muted">{route.available[0].detail}</p>}
                        {route.available.length > 1 && (
                            <p className="faint">
                                Also possible: {route.available.slice(1).map((r) => r.label).join(", ")}.
                            </p>
                        )}
                        {route.blockers.length > 0 && (
                            <ul className="rationale-list conflicts">
                                {route.blockers.map((b, i) => (
                                    <li key={i}>{b}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                <div className="rationale-heading" style={{ marginTop: 18 }}>
                    Evidence
                </div>
                <EvidenceTable evidence={candidate.evidence} />
            </div>
        </article>
    );
}

function DecisionForm({
    incidentId,
    pmId,
    candidates,
    onDecided,
}: {
    incidentId: string;
    pmId: string;
    candidates: CandidateDetail[];
    onDecided: () => void;
}) {
    const [decision, setDecision] = useState<DecisionKind>("recommend_confirm_test");
    const [amId, setAmId] = useState(candidates[0]?.am_id ?? "");
    const [reviewer, setReviewer] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const needsPairing = decision !== "no_credible_candidate";

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError("");
        setMessage("");

        try {
            const { data } = await api.post(`/incidents/${incidentId}/bodies/${pmId}/decisions`, {
                decision,
                am_id: needsPairing ? amId : null,
                reviewer: reviewer || undefined,
                note: note || undefined,
            });

            setMessage(data.message);
            setNote("");
            onDecided();
        } catch (caught) {
            setError(toApiError(caught, "Could not record the decision.").message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <form className="card decision-form" onSubmit={submit}>
            <h3>Record a decision</h3>
            <p className="muted" style={{ marginTop: 6 }}>
                Decisions are append-only. Changing your mind adds a new entry and leaves the
                previous one on the record.
            </p>

            {message && <div className="notice" style={{ marginTop: 12 }}>{message}</div>}
            {error && <div className="error-banner" style={{ marginTop: 12 }}>{error}</div>}

            <div className="form-grid" style={{ marginTop: 14 }}>
                <div className="form-group">
                    <label htmlFor="decision">Decision</label>
                    <select
                        id="decision"
                        value={decision}
                        onChange={(e) => setDecision(e.target.value as DecisionKind)}
                    >
                        <option value="recommend_confirm_test">Refer for confirmation test</option>
                        <option value="reject">Rule this pairing out</option>
                        <option value="defer">Defer — need more evidence</option>
                        <option value="no_credible_candidate">No credible candidate</option>
                    </select>
                </div>

                {needsPairing && (
                    <div className="form-group">
                        <label htmlFor="am_id">Candidate</label>
                        <select id="am_id" value={amId} onChange={(e) => setAmId(e.target.value)}>
                            <option value="">Select…</option>
                            {candidates.map((c) => (
                                <option key={c.am_id} value={c.am_id}>
                                    {c.profile?.reported_name ?? c.am_id} ({c.am_id})
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <div className="form-group">
                    <label htmlFor="reviewer">Reviewer role</label>
                    <input
                        id="reviewer"
                        value={reviewer}
                        onChange={(e) => setReviewer(e.target.value)}
                        placeholder="identification commission"
                    />
                </div>

                <div className="form-group wide">
                    <label htmlFor="note">Note</label>
                    <textarea
                        id="note"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="What was decided and why — this is part of the record."
                    />
                </div>
            </div>

            <div className="form-actions">
                <button className="btn btn-primary" type="submit" disabled={saving}>
                    {saving ? "Recording…" : "Record decision"}
                </button>
            </div>
        </form>
    );
}
