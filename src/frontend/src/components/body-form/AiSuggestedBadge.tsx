export default function AiSuggestedBadge({ confidence }: { confidence?: number }) {
    return (
        <span className="chip ai-suggested">
            AI-suggested{confidence !== undefined && ` · ${Math.round(confidence * 100)}%`} — verify
        </span>
    );
}
