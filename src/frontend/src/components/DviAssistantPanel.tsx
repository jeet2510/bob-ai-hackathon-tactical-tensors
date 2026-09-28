import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useParams } from "react-router-dom";
import { askAssistant } from "../api/assistant";
import { toApiError } from "../api/errors";
import AiMessage from "./AiMessage";
import { IconClose, IconSend } from "./icons";
import type { AssistantTurn } from "../types";

const SUGGESTIONS = [
    "What needs my attention right now?",
    "Which families are still waiting?",
    "Summarise evidence strength across bodies.",
];

/**
 * "DVI Assistant" — a collapsible, site-wide chat sidebar. It answers from a
 * bounded snapshot of the currently open incident's data (see
 * App\Services\Assistant\DviAssistant on the backend); it never records a
 * decision or changes anything. Nothing here is persisted — closing the
 * panel or switching incidents starts a fresh conversation.
 */
export default function DviAssistantPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { incidentId } = useParams();

    const [messages, setMessages] = useState<AssistantTurn[]>([]);
    const [input, setInput] = useState("");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setMessages([]);
        setError("");
        setInput("");
    }, [incidentId]);

    useEffect(() => {
        listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: "smooth" });
    }, [messages, busy]);

    async function send(text?: string) {
        const message = (text ?? input).trim();

        if (!message || busy || !incidentId) {
            return;
        }

        const history = messages;
        setMessages((m) => [...m, { role: "user", text: message }]);
        setInput("");
        setBusy(true);
        setError("");

        try {
            const { data } = await askAssistant(incidentId, message, history);

            if (data.ai_available && data.reply) {
                setMessages((m) => [...m, { role: "assistant", text: data.reply as string }]);
            } else {
                setError(data.reason ?? "The assistant is unavailable right now.");
            }
        } catch (caught) {
            setError(toApiError(caught, "Could not reach the assistant.").message);
        } finally {
            setBusy(false);
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        send();
    }

    return (
        <>
            {open && <div className="assistant-backdrop" onClick={onClose} />}

            <aside className={`assistant-panel${open ? " open" : ""}`}>
                <header className="assistant-panel-head">
                    <div>
                        <strong>DVI Assistant</strong>
                        <span className="faint"> · Bob by IBM</span>
                    </div>
                    <button type="button" className="assistant-panel-close" onClick={onClose} aria-label="Close assistant">
                        <IconClose />
                    </button>
                </header>

                {!incidentId ? (
                    <div className="assistant-empty">
                        <p className="muted">Open an incident to ask about its data.</p>
                    </div>
                ) : (
                    <>
                        <div className="assistant-messages" ref={listRef}>
                            {messages.length === 0 && (
                                <div className="assistant-empty">
                                    <p className="muted">
                                        Ask about this incident's bodies, families, evidence or what still
                                        needs attention.
                                    </p>
                                    <div className="assistant-suggestions">
                                        {SUGGESTIONS.map((s) => (
                                            <button
                                                key={s}
                                                type="button"
                                                className="assistant-suggestion"
                                                onClick={() => send(s)}
                                            >
                                                {s}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {messages.map((m, i) => (
                                <div key={i} className={`assistant-message assistant-message-${m.role}`}>
                                    {m.role === "assistant" ? (
                                        <AiMessage text={m.text} incidentId={incidentId} onNavigate={onClose} />
                                    ) : (
                                        m.text
                                    )}
                                </div>
                            ))}

                            {busy && (
                                <div className="assistant-message assistant-message-assistant assistant-typing">
                                    Thinking…
                                </div>
                            )}
                        </div>

                        {error && <div className="error-banner assistant-error">{error}</div>}

                        <form className="assistant-input" onSubmit={onSubmit}>
                            <input
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                placeholder="Ask the DVI Assistant…"
                                disabled={busy}
                            />
                            <button
                                type="submit"
                                className="btn btn-primary btn-sm"
                                disabled={busy || !input.trim()}
                                aria-label="Send"
                            >
                                <IconSend />
                            </button>
                        </form>

                        <p className="assistant-disclaimer">
                            Advisory only — never an identification. Cannot record decisions or change data.
                        </p>
                    </>
                )}
            </aside>
        </>
    );
}
