import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { fetchGeminiMatch, runGeminiMatch } from "../../api/matching";
import EvidenceTable from "../../components/EvidenceTable";
import ObservationPanel from "../../components/ObservationPanel";
import PhotoStrip from "../../components/PhotoStrip";
import { useIncident } from "../IncidentLayout";
import { BAND_CLASS, BAND_LABEL, DECISION_LABEL, signed } from "../../lib/format";
import type { BodyDetail, CandidateDetail, DecisionKind, GeminiMatchCandidate } from "../../types";

type Tab = "candidates" | "ai" | "observations" | "photos" | "history";

export default function BodyReview() {
    const { incident } = useIncident();
    const { pmId } = useParams();

    const [detail, setDetail] = useState<BodyDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [tab, setTab] = useState<Tab>("candidates");
    const [preferred, setPreferred] = useState<{ am_id: string; reported_name: string | null } | null>(null);

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
                <button className={tab === "ai" ? "active" : ""} onClick={() => setTab("ai")}>
                    AI Match
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

            {tab === "ai" && (
                <GeminiMatchPanel
                    incidentId={incident.incident_id}
                    pmId={body.pm_id}
                    onUseCandidate={(candidate) =>
                        setPreferred({ am_id: candidate.am_id, reported_name: candidate.profile?.reported_name ?? null })
                    }
                />
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
                preferred={preferred}
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

/**
 * Gemini's multimodal re-ranking of this body's existing rules-based
 * shortlist, on its own tab. Purely advisory and additive — it never
 * replaces the Candidates tab, and "Use this candidate" only pre-selects
 * the reviewer's own decision below; it never records one itself.
 */
function GeminiMatchPanel({
    incidentId,
    pmId,
    onUseCandidate,
}: {
    incidentId: string;
    pmId: string;
    onUseCandidate: (candidate: GeminiMatchCandidate) => void;
}) {
    const [candidates, setCandidates] = useState<GeminiMatchCandidate[] | null>(null);
    const [summary, setSummary] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    useEffect(() => {
        let cancelled = false;

        fetchGeminiMatch(incidentId, pmId)
            .then(({ data }) => {
                if (!cancelled && data.run_id) {
                    setCandidates(data.candidates);
                    setSummary(data.summary);
                }
            })
            .catch(() => {
                // No prior AI run is not an error — the panel just starts idle.
            });

        return () => {
            cancelled = true;
        };
    }, [incidentId, pmId]);

    async function run() {
        setBusy(true);
        setError("");
        setNotice("");

        try {
            const { data } = await runGeminiMatch(incidentId, pmId);
            setCandidates(data.candidates);
            setSummary(data.summary);

            if (data.candidates.length === 0) {
                setNotice(data.reason ?? "Gemini found no credible visual match in the shortlist.");
            }
        } catch (caught) {
            const apiError = toApiError(caught, "Could not run the AI match.");

            if ((caught as { response?: { status?: number } })?.response?.status === 422) {
                setNotice(apiError.message);
            } else {
                setError(apiError.message);
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <div className="page-head" style={{ marginBottom: 14 }}>
                <p className="muted">
                    Re-ranks the shortlist using photographs and visual evidence the rules-based
                    scorer cannot read. Advisory only — never a confirmation.
                </p>

                <button className="btn btn-primary" onClick={run} disabled={busy}>
                    {busy ? "Matching…" : candidates ? "Run again" : "Run AI match"}
                </button>
            </div>

            {error && <div className="error-banner">{error}</div>}
            {notice && <div className="notice">{notice}</div>}

            {candidates === null && !busy && !notice && !error && (
                <div className="empty">Not run yet for this body.</div>
            )}

            {summary && (
                <div className="card" style={{ marginBottom: 14 }}>
                    <div className="rationale-heading">AI summary</div>
                    <p style={{ marginTop: 6 }}>{summary}</p>
                </div>
            )}

            {candidates && candidates.length > 0 && (
                <div className="repeatable-table">
                    {candidates.map((candidate) => (
                        <article key={candidate.am_id} className={`candidate band-${BAND_CLASS[candidate.confidence_band]}`}>
                            <header className="candidate-head">
                                <div>
                                    <div className="candidate-rank">AI candidate {candidate.rank}</div>
                                    <div className="candidate-name">
                                        {candidate.profile?.reported_name ?? candidate.am_id}
                                    </div>
                                    <div className="muted">
                                        <span className="mono">{candidate.am_id}</span>
                                        {candidate.profile?.sex && ` · ${candidate.profile.sex}`}
                                        {candidate.profile?.age != null && ` · ${candidate.profile.age} yrs`}
                                    </div>
                                </div>

                                <span className={`badge badge-${BAND_CLASS[candidate.confidence_band]}`}>
                                    {BAND_LABEL[candidate.confidence_band]}
                                </span>
                            </header>

                            <div className="candidate-body">
                                {candidate.evidence_summary.length > 0 && (
                                    <>
                                        <div className="rationale-heading">Matched evidence</div>
                                        <ul className="rationale-list">
                                            {candidate.evidence_summary.map((row, i) => (
                                                <li key={i} className={row.verdict === "conflict" ? "conflict-line" : undefined}>
                                                    <strong>{row.category}</strong> — {row.verdict}
                                                    {row.rationale && `: ${row.rationale}`}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}

                                {(candidate.ai_rationale || candidate.ai_visual_notes) && (
                                    <>
                                        <div className="rationale-heading" style={{ marginTop: 12 }}>
                                            Gemini notes
                                        </div>
                                        <p className="muted">
                                            {[candidate.ai_rationale, candidate.ai_visual_notes].filter(Boolean).join(" ")}
                                        </p>
                                    </>
                                )}

                                <div className="btn-row" style={{ marginTop: 14 }}>
                                    <button
                                        type="button"
                                        className="btn btn-sm"
                                        onClick={() => onUseCandidate(candidate)}
                                    >
                                        Use this candidate below
                                    </button>
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </>
    );
}

function DecisionForm({
    incidentId,
    pmId,
    candidates,
    preferred,
    onDecided,
}: {
    incidentId: string;
    pmId: string;
    candidates: CandidateDetail[];
    preferred: { am_id: string; reported_name: string | null } | null;
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

    // An AI-suggested candidate the reviewer chose to act on. It pre-selects
    // this form's candidate — it never submits a decision on its own.
    useEffect(() => {
        if (preferred) {
            setDecision("recommend_confirm_test");
            setAmId(preferred.am_id);
        }
    }, [preferred]);

    // A Gemini suggestion may point at a shortlist candidate outside the
    // top-3 shown above, so the option list must never lose it.
    const options = candidates.some((c) => c.am_id === preferred?.am_id) || !preferred
        ? candidates
        : [...candidates, { am_id: preferred.am_id, profile: { reported_name: preferred.reported_name } } as CandidateDetail];

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
                            {options.map((c) => (
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
