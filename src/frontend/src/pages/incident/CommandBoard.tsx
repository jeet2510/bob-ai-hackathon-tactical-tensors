import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../../api/client";
import { fetchInsight } from "../../api/assistant";
import { toApiError } from "../../api/errors";
import PhotoGallery from "../../components/PhotoGallery";
import {
    BodyConditionChart,
    EvidenceBandBar,
    LanguageBars,
    ReadinessChart,
    RecoveryTimelineChart,
} from "../../components/charts/DashboardCharts";
import { useIncident } from "../IncidentLayout";
import { BAND_CLASS, BAND_LABEL, DECISION_LABEL, signed } from "../../lib/format";
import type { BodyRow, ConfidenceBand } from "../../types";

const BANDS: ConfidenceBand[] = ["high", "moderate", "low", "no_credible_candidate"];

export default function CommandBoard() {
    const { incident, stats, breakdowns, languages } = useIncident();
    const navigate = useNavigate();

    const [bodies, setBodies] = useState<BodyRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [band, setBand] = useState<ConfidenceBand | "">("");
    const [search, setSearch] = useState("");

    useEffect(() => {
        let cancelled = false;

        const timer = setTimeout(() => {
            api.get(`/incidents/${incident.incident_id}/bodies`, {
                params: { ...(band ? { band } : {}), ...(search ? { search } : {}) },
            })
                .then(({ data }) => {
                    if (!cancelled) {
                        setBodies(data.bodies);
                        setError("");
                    }
                })
                .catch((caught) => {
                    if (!cancelled) setError(toApiError(caught, "Could not load bodies.").message);
                })
                .finally(() => {
                    if (!cancelled) setLoading(false);
                });
        }, search ? 250 : 0);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [incident.incident_id, band, search]);

    const withoutRoute = useMemo(
        () => bodies.filter((b) => b.top_candidate && !b.top_candidate.recommended_route?.route).length,
        [bodies],
    );

    return (
        <>
            <IncidentInsightCard incidentId={incident.incident_id} />

            <div className="stat-grid">
                <Stat value={stats.bodies} label="Bodies recovered" />
                <Stat value={stats.profiles} label="Families reporting" />
                <Stat value={stats.high ?? 0} label="Strong evidence" tone="high" />
                <Stat value={stats.moderate ?? 0} label="Moderate evidence" tone="moderate" />
                <Stat
                    value={stats.no_credible_candidate ?? 0}
                    label="No credible candidate"
                    tone="none"
                />
                <Stat value={stats.reviewed ?? 0} label="Reviewed" />
            </div>

            {(stats.no_confirmation_route ?? 0) > 0 && (
                <div className="disclaimer">
                    <strong>{stats.no_confirmation_route} bodies have no viable confirmation route.</strong>{" "}
                    Their strongest candidate cannot be settled by fingerprints, dental records or DNA
                    with what is currently held — a sample needs collecting before ranking can help them.
                </div>
            )}

            <div className="dashboard-grid">
                <section className="card chart-card chart-card-wide">
                    <h3>Bodies by strongest-candidate evidence</h3>
                    <p className="muted">Part-to-whole across every recovered body.</p>
                    <EvidenceBandBar stats={stats} />
                </section>

                <section className="card chart-card">
                    <h3>Primary-identifier readiness</h3>
                    <p className="muted">DNA, dental and print status across all bodies.</p>
                    <ReadinessChart breakdowns={breakdowns} />
                </section>

                <section className="card chart-card">
                    <h3>Body condition on recovery</h3>
                    <p className="muted">Drives how much of each description can be trusted.</p>
                    <BodyConditionChart breakdowns={breakdowns} />
                </section>

                <section className="card chart-card">
                    <h3>Bodies recovered per day</h3>
                    <p className="muted">Recovery-scene timeline for the incident.</p>
                    <RecoveryTimelineChart breakdowns={breakdowns} />
                </section>

                <section className="card chart-card">
                    <h3>Form boxes by language</h3>
                    <p className="muted">What language each source record was written in.</p>
                    <LanguageBars languages={languages} />
                </section>
            </div>

            <PhotoGallery incidentId={incident.incident_id} />

            <h2 style={{ marginTop: 28, marginBottom: 14 }}>Case triage</h2>

            <div className="toolbar">
                <input
                    type="search"
                    placeholder="Search by body tag or recovery place…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
                <select value={band} onChange={(e) => setBand(e.target.value as ConfidenceBand | "")}>
                    <option value="">All evidence bands</option>
                    {BANDS.map((b) => (
                        <option key={b} value={b}>
                            {BAND_LABEL[b]}
                        </option>
                    ))}
                </select>
            </div>

            {error && <div className="error-banner">{error}</div>}

            {loading ? (
                <div className="empty">Loading…</div>
            ) : bodies.length === 0 ? (
                <div className="empty">No bodies match this filter.</div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Body</th>
                                <th>Description</th>
                                <th>Recovery</th>
                                <th>Strongest candidate</th>
                                <th>Evidence</th>
                                <th>Confirm via</th>
                                <th>Review</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {bodies.map((body) => (
                                <tr
                                    key={body.pm_id}
                                    className="row-clickable"
                                    onClick={() => navigate(`/incidents/${incident.incident_id}/bodies/${body.pm_id}`)}
                                >
                                    <td>
                                        <span className="mono">{body.pm_id}</span>
                                        {body.degraded && (
                                            <div className="faint" title="External description is unreliable">
                                                {body.body_condition}
                                            </div>
                                        )}
                                    </td>
                                    <td>
                                        {body.sex ?? "—"}
                                        {body.age_estimate && ` · ${body.age_estimate} yrs`}
                                        {body.height_cm && ` · ${body.height_cm} cm`}
                                    </td>
                                    <td>
                                        {body.found_place ?? "—"}
                                        <div className="faint">{body.found_at?.slice(0, 16)}</div>
                                    </td>
                                    <td>
                                        {body.top_candidate ? (
                                            <>
                                                {body.top_candidate.reported_name ?? body.top_candidate.am_id}
                                                <div className="faint mono">{body.top_candidate.am_id}</div>
                                            </>
                                        ) : (
                                            <span className="faint">none offered</span>
                                        )}
                                    </td>
                                    <td>
                                        {body.top_candidate ? (
                                            <>
                                                <span
                                                    className={`badge badge-${BAND_CLASS[body.top_candidate.confidence_band]}`}
                                                >
                                                    {BAND_LABEL[body.top_candidate.confidence_band]}
                                                </span>
                                                <div className="faint">
                                                    {signed(body.top_candidate.score)} over{" "}
                                                    {body.top_candidate.coverage} categories
                                                </div>
                                            </>
                                        ) : (
                                            "—"
                                        )}
                                    </td>
                                    <td>
                                        {body.top_candidate?.recommended_route?.route ? (
                                            <span className="chip chip-match">
                                                {body.top_candidate.recommended_route.label}
                                            </span>
                                        ) : (
                                            <span className="chip chip-conflict">No route</span>
                                        )}
                                    </td>
                                    <td>
                                        {body.decision ? (
                                            <span className="badge badge-accent">
                                                {DECISION_LABEL[body.decision.decision]}
                                            </span>
                                        ) : (
                                            <span className="faint">pending</span>
                                        )}
                                    </td>
                                    <td className="tbl-actions">
                                        <Link
                                            className="btn btn-sm"
                                            to={`/incidents/${incident.incident_id}/bodies/${body.pm_id}`}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            View / Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!loading && withoutRoute > 0 && band === "" && (
                <p className="faint" style={{ marginTop: 12 }}>
                    {withoutRoute} of the listed bodies have a candidate but no primary-identifier
                    route available.
                </p>
            )}
        </>
    );
}

/**
 * On-demand, plain-language dashboard briefing — never generated
 * automatically, matching every other AI surface in this app.
 */
function IncidentInsightCard({ incidentId }: { incidentId: string }) {
    const [text, setText] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState("");

    async function run() {
        setBusy(true);
        setNotice("");

        try {
            const { data } = await fetchInsight(incidentId);

            if (data.ai_available && data.text) {
                setText(data.text);
            } else {
                setNotice(data.reason ?? "AI briefing unavailable right now.");
            }
        } catch (caught) {
            setNotice(toApiError(caught, "Could not generate a briefing.").message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card" style={{ marginBottom: 22 }}>
            <div className="page-head" style={{ marginBottom: text ? 10 : 0 }}>
                <div>
                    <h3>
                        AI briefing <span className="badge badge-accent">Bob by IBM</span>
                    </h3>
                    {!text && (
                        <p className="muted" style={{ marginTop: 4 }}>
                            A plain-language summary of where this incident stands right now.
                        </p>
                    )}
                </div>

                <button className="btn btn-sm" onClick={run} disabled={busy}>
                    {busy ? "Thinking…" : text ? "Refresh" : "Generate briefing"}
                </button>
            </div>

            {notice && <div className="notice">{notice}</div>}
            {text && <p>{text}</p>}
        </section>
    );
}

function Stat({ value, label, tone }: { value: number; label: string; tone?: string }) {
    return (
        <div className="stat">
            <div className={`stat-value${tone ? ` stat-${tone}` : ""}`}>{value}</div>
            <div className="stat-label">{label}</div>
        </div>
    );
}
