import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { useThemeColors, sequentialRamp } from "../../lib/theme-colors";
import { BAND_CLASS, BAND_LABEL, LANGUAGE } from "../../lib/format";
import type { ConfidenceBand, IncidentBreakdowns } from "../../types";

const BANDS: ConfidenceBand[] = ["high", "moderate", "low", "no_credible_candidate"];

type BandStats = Partial<Record<ConfidenceBand, number>>;

function tooltipStyle(colors: ReturnType<typeof useThemeColors>) {
    return {
        background: colors.surface,
        border: `1px solid ${colors.border}`,
        borderRadius: 6,
        fontSize: 13,
        padding: "8px 10px",
    };
}

/**
 * Part-to-whole is a horizontal stacked bar, not a pie — four segments read
 * faster as adjacent widths than as wedges, and it reuses the same
 * high/moderate/low badge colours already used throughout the app.
 */
export function EvidenceBandBar({ stats }: { stats: BandStats }) {
    const total = BANDS.reduce((sum, b) => sum + (stats[b] ?? 0), 0);

    if (total === 0) {
        return <div className="empty">No candidates ranked yet.</div>;
    }

    return (
        <div className="band-bar-wrap">
            <div className="band-bar" role="img" aria-label="Bodies by strongest-candidate evidence band">
                {BANDS.map((band) => {
                    const value = stats[band] ?? 0;
                    if (value === 0) return null;
                    const pct = (value / total) * 100;

                    return (
                        <div
                            key={band}
                            className={`band-bar-segment band-bar-${BAND_CLASS[band]}`}
                            style={{ width: `${pct}%` }}
                            title={`${BAND_LABEL[band]} — ${value} bodies`}
                        >
                            {pct >= 10 && <span>{value}</span>}
                        </div>
                    );
                })}
            </div>

            <div className="band-bar-legend">
                {BANDS.map((band) => (
                    <span key={band} className="band-bar-legend-item">
                        <span className={`legend-dot legend-dot-${BAND_CLASS[band]}`} />
                        {BAND_LABEL[band]} <strong>{stats[band] ?? 0}</strong>
                    </span>
                ))}
            </div>
        </div>
    );
}

const READINESS_LABEL: Record<string, string> = {
    usable: "Usable",
    review: "Needs review",
    unavailable: "Unavailable",
};

/** DNA, dental and print readiness, normalised onto the same three states so
 *  a coordinator can compare across identifiers at a glance. */
export function ReadinessChart({ breakdowns }: { breakdowns: IncidentBreakdowns }) {
    const colors = useThemeColors();

    const data = [
        {
            identifier: "DNA",
            usable: breakdowns.dna_status.sample_taken ?? 0,
            review: breakdowns.dna_status.degraded ?? 0,
            unavailable: breakdowns.dna_status.not_collected ?? 0,
        },
        {
            identifier: "Dental",
            usable: breakdowns.dental_status.chart_completed ?? 0,
            review: breakdowns.dental_status.unsuitable ?? 0,
            unavailable: breakdowns.dental_status.not_examined ?? 0,
        },
        {
            identifier: "Prints",
            usable: breakdowns.print_status.usable ?? 0,
            review: breakdowns.print_status.unusable ?? 0,
            unavailable: breakdowns.print_status.not_taken ?? 0,
        },
    ];

    return (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} layout="vertical" barCategoryGap={18} margin={{ left: 8, right: 24, top: 4, bottom: 4 }}>
                <CartesianGrid strokeDasharray="0" horizontal={false} stroke={colors.border} />
                <XAxis type="number" tick={{ fill: colors.textFaint, fontSize: 12 }} axisLine={{ stroke: colors.border }} tickLine={false} allowDecimals={false} />
                <YAxis
                    type="category"
                    dataKey="identifier"
                    tick={{ fill: colors.textMuted, fontSize: 13 }}
                    axisLine={false}
                    tickLine={false}
                    width={52}
                />
                <Tooltip
                    contentStyle={tooltipStyle(colors)}
                    labelStyle={{ color: colors.textMuted, fontWeight: 600, marginBottom: 4 }}
                    formatter={(value, key) => [value, READINESS_LABEL[String(key)] ?? String(key)]}
                />
                <Bar dataKey="usable" stackId="r" fill={colors.high} radius={[0, 0, 0, 0]} maxBarSize={22} />
                <Bar dataKey="review" stackId="r" fill={colors.moderate} maxBarSize={22} />
                <Bar dataKey="unavailable" stackId="r" fill={colors.low} radius={[0, 4, 4, 0]} maxBarSize={22} />
            </BarChart>
        </ResponsiveContainer>
    );
}

const CONDITION_ORDER = ["Fresh", "Slight decomp.", "Moderate decomp.", "Advanced decomp.", "Burnt"];

/** Body condition is an ordinal severity scale, so it gets a single sequential
 *  ramp rather than distinct hues — darker reads as "more degraded." */
export function BodyConditionChart({ breakdowns }: { breakdowns: IncidentBreakdowns }) {
    const colors = useThemeColors();
    const ramp = sequentialRamp(colors.accent, CONDITION_ORDER.length);

    const present = CONDITION_ORDER.filter((label) => label in breakdowns.body_condition);
    const data = present.map((label) => ({ label, total: breakdowns.body_condition[label] }));

    if (data.length === 0) {
        return <div className="empty">No body-condition data recorded.</div>;
    }

    return (
        <ResponsiveContainer width="100%" height={200}>
            <BarChart data={data} barCategoryGap={14} margin={{ left: -12, right: 8, top: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="0" vertical={false} stroke={colors.border} />
                <XAxis
                    dataKey="label"
                    tick={{ fill: colors.textFaint, fontSize: 11 }}
                    axisLine={{ stroke: colors.border }}
                    tickLine={false}
                    interval={0}
                />
                <YAxis tick={{ fill: colors.textFaint, fontSize: 12 }} axisLine={false} tickLine={false} allowDecimals={false} width={28} />
                <Tooltip contentStyle={tooltipStyle(colors)} labelStyle={{ color: colors.textMuted, fontWeight: 600 }} />
                <Bar dataKey="total" radius={[4, 4, 0, 0]} maxBarSize={40}>
                    {data.map((_, i) => (
                        <Cell key={i} fill={ramp[i]} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Bodies recovered per day — a single measure over time, one hue, no legend. */
export function RecoveryTimelineChart({ breakdowns }: { breakdowns: IncidentBreakdowns }) {
    const colors = useThemeColors();

    const data = Object.entries(breakdowns.recovered_by_day).map(([day, total]) => ({
        day: new Date(day).toLocaleDateString(undefined, { month: "short", day: "numeric" }),
        total,
    }));

    if (data.length === 0) {
        return <div className="empty">No recovery dates recorded.</div>;
    }

    return (
        <ResponsiveContainer width="100%" height={200}>
            <BarChart data={data} barCategoryGap={24} margin={{ left: -12, right: 8, top: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="0" vertical={false} stroke={colors.border} />
                <XAxis dataKey="day" tick={{ fill: colors.textFaint, fontSize: 12 }} axisLine={{ stroke: colors.border }} tickLine={false} />
                <YAxis tick={{ fill: colors.textFaint, fontSize: 12 }} axisLine={false} tickLine={false} allowDecimals={false} width={28} />
                <Tooltip contentStyle={tooltipStyle(colors)} labelStyle={{ color: colors.textMuted, fontWeight: 600 }} />
                <Bar dataKey="total" fill={colors.accent} radius={[4, 4, 0, 0]} maxBarSize={36} />
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Form-box language mix. Three categories at most, identity already carried
 *  by the label, so magnitude alone (one hue) is enough — no legend needed. */
export function LanguageBars({ languages }: { languages: Record<string, number> }) {
    const entries = Object.entries(languages).sort(([, a], [, b]) => b - a);
    const max = Math.max(...entries.map(([, n]) => n), 1);

    if (entries.length === 0) {
        return <div className="empty">No form boxes recorded.</div>;
    }

    return (
        <div className="lang-bars">
            {entries.map(([code, count]) => (
                <div className="lang-bar-row" key={code}>
                    <span className="lang-bar-label">{LANGUAGE[code] ?? code}</span>
                    <span className="lang-bar-track">
                        <span className="lang-bar-fill" style={{ width: `${(count / max) * 100}%` }} />
                    </span>
                    <span className="lang-bar-value mono">{count}</span>
                </div>
            ))}
        </div>
    );
}
