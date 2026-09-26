import { useState } from "react";
import api from "../api/client";
import { toApiError } from "../api/errors";
import { describeItem, LANGUAGE } from "../lib/format";
import type { NormalisedItem, ObservationRow } from "../types";

/**
 * The source text beside what the system read out of it.
 *
 * Families here were interviewed in Marathi and Hindi, sometimes in Latin
 * script mixed with English, while examiners wrote clinical English. Nothing
 * downstream can be checked unless a reviewer can see the sentence a finding
 * came from — so the original wording is always shown, never a translation
 * standing in for it.
 *
 * Any item can be corrected in place. A correction outranks the extractor
 * everywhere afterwards, which is what makes this a tool an expert can
 * actually own rather than merely audit.
 */
export default function ObservationPanel({
    observations,
    onCorrected,
}: {
    observations: ObservationRow[];
    onCorrected?: () => void;
}) {
    if (observations.length === 0) {
        return <div className="empty">No form boxes recorded.</div>;
    }

    return (
        <div className="observation-list">
            {observations.map((observation) => (
                <ObservationCard
                    key={observation.obs_id}
                    observation={observation}
                    onCorrected={onCorrected}
                />
            ))}
        </div>
    );
}

function ObservationCard({
    observation,
    onCorrected,
}: {
    observation: ObservationRow;
    onCorrected?: () => void;
}) {
    const items = observation.items ?? [];
    const foreign = observation.lang !== "en";

    return (
        <article className="observation">
            <header className="observation-head">
                <div>
                    <div className="observation-field">
                        {observation.form_field ?? observation.category}
                    </div>
                    <div className="faint mono">{observation.obs_id}</div>
                </div>
                <div className="observation-tags">
                    <span className={`chip ${foreign ? "chip-lang" : ""}`}>
                        {LANGUAGE[observation.lang] ?? observation.lang}
                    </span>
                    {observation.source_type && (
                        <span className="chip">{observation.source_type.replace(/_/g, " ")}</span>
                    )}
                </div>
            </header>

            <blockquote className={foreign ? "raw-text raw-text-foreign" : "raw-text"}>
                {observation.raw_text}
            </blockquote>

            {observation.reliability_note && (
                <p className="faint">Note: {observation.reliability_note}</p>
            )}

            <div className="extracted">
                <div className="rationale-heading">
                    Read as {items.length} item{items.length === 1 ? "" : "s"}
                </div>

                {items.length === 0 ? (
                    <p className="faint">
                        Nothing structured was read from this box — it contributes nothing to matching.
                    </p>
                ) : (
                    <ul className="item-list">
                        {items.map((item) => (
                            <ItemRow key={item.id} item={item} onCorrected={onCorrected} />
                        ))}
                    </ul>
                )}
            </div>
        </article>
    );
}

function ItemRow({
    item,
    onCorrected,
}: {
    item: NormalisedItem;
    onCorrected?: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(() => JSON.stringify(item.item, null, 2));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    async function save() {
        let parsed: Record<string, unknown>;

        try {
            parsed = JSON.parse(draft);
        } catch {
            setError("That is not valid JSON.");
            return;
        }

        if (typeof parsed.category !== "string") {
            setError("An item needs a category.");
            return;
        }

        setSaving(true);
        setError("");

        try {
            await api.patch(`/observation-items/${item.id}`, { item: parsed });
            setEditing(false);
            onCorrected?.();
        } catch (caught) {
            setError(toApiError(caught, "Could not save the correction.").message);
        } finally {
            setSaving(false);
        }
    }

    const lowConfidence = item.confidence !== null && item.confidence < 0.8;

    return (
        <li className="item-row">
            <div className="item-main">
                <span className="item-category">{item.item.category}</span>
                <span className="item-detail">{describeItem(item.item)}</span>
            </div>

            <div className="item-meta">
                {item.reviewed_by ? (
                    <span className="chip chip-match">confirmed by {item.reviewed_by}</span>
                ) : (
                    item.confidence !== null && (
                        <span className={`chip ${lowConfidence ? "chip-partial" : ""}`}>
                            {Math.round(item.confidence * 100)}% confident
                        </span>
                    )
                )}
                <button type="button" className="btn btn-sm" onClick={() => setEditing((o) => !o)}>
                    {editing ? "Cancel" : "Correct"}
                </button>
            </div>

            {editing && (
                <div className="item-editor">
                    <textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={7} />
                    {error && <div className="field-error">{error}</div>}
                    <div className="btn-row">
                        <button className="btn btn-sm btn-primary" disabled={saving} onClick={save}>
                            {saving ? "Saving…" : "Save correction"}
                        </button>
                        <span className="faint">
                            Re-run matching afterwards for this to affect rankings.
                        </span>
                    </div>
                </div>
            )}
        </li>
    );
}
