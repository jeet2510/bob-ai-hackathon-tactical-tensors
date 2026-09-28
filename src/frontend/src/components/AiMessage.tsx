import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { useNavigate } from "react-router-dom";

/** PM-<incident code>-<number> or AM-<incident code>-<number>, e.g. PM-LS26-014, AM-LS26-002. */
const REFERENCE_PATTERN = /\b(PM|AM)-[A-Z0-9]+-\d+\b/g;

/**
 * @returns deduplicated record ids referenced in the text, in first-seen order
 */
function extractReferences(text: string): string[] {
    const found = text.match(REFERENCE_PATTERN) ?? [];
    return Array.from(new Set(found));
}

/**
 * Renders one piece of AI-generated prose (the assistant's chat replies, the
 * dashboard briefing) as safe markdown — react-markdown never renders raw
 * HTML, so nothing the model writes can inject markup — plus a row of
 * "Open …" buttons for every PM-/AM- record id it mentioned, so a reference
 * like "PM-LS26-014 has no confirmation route" is something a reviewer can
 * act on immediately rather than having to go search for by hand.
 */
export default function AiMessage({
    text,
    incidentId,
    onNavigate,
}: {
    text: string;
    incidentId: string;
    onNavigate?: () => void;
}) {
    const navigate = useNavigate();
    const references = extractReferences(text);

    function open(id: string) {
        onNavigate?.();
        navigate(
            id.startsWith("PM-")
                ? `/incidents/${incidentId}/bodies/${id}`
                : `/incidents/${incidentId}/profiles?q=${encodeURIComponent(id)}`,
        );
    }

    return (
        <div className="ai-message">
            <div className="ai-message-prose">
                <ReactMarkdown remarkPlugins={[remarkGfm]}>{text}</ReactMarkdown>
            </div>

            {references.length > 0 && (
                <div className="ai-message-refs">
                    {references.map((id) => (
                        <button key={id} type="button" className="chip chip-link" onClick={() => open(id)}>
                            Open {id} →
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
