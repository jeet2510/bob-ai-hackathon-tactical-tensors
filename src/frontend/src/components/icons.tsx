/**
 * Small stroke icons for the incident sidebar. Hand-drawn rather than an
 * icon-font dependency — six glyphs don't need a library.
 */
type IconProps = { className?: string };

const base = {
    width: 18,
    height: 18,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.7,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
};

export function IconGrid({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <rect x="3.5" y="3.5" width="7.5" height="7.5" rx="1.4" />
            <rect x="13" y="3.5" width="7.5" height="7.5" rx="1.4" />
            <rect x="3.5" y="13" width="7.5" height="7.5" rx="1.4" />
            <rect x="13" y="13" width="7.5" height="7.5" rx="1.4" />
        </svg>
    );
}

export function IconUsers({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <circle cx="8.5" cy="8" r="3" />
            <path d="M3 20c0-3 2.5-5 5.5-5s5.5 2 5.5 5" />
            <circle cx="17" cy="8.5" r="2.4" />
            <path d="M15.5 12c2.4.2 4 1.9 4 4.5" />
        </svg>
    );
}

export function IconTarget({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <circle cx="12" cy="12" r="8" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="12" cy="12" r="0.6" fill="currentColor" />
        </svg>
    );
}

export function IconFile({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <path d="M6 3.5h8l4.5 4.5V20a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" />
            <path d="M14 3.5V8h4.5" />
            <path d="M8.5 12.5h7M8.5 16h5" />
        </svg>
    );
}

export function IconChart({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
        </svg>
    );
}

export function IconClipboard({ className }: IconProps) {
    return (
        <svg {...base} className={className}>
            <rect x="5" y="4.5" width="14" height="16" rx="1.6" />
            <path d="M9 4.5V3.8A1.8 1.8 0 0 1 10.8 2h2.4A1.8 1.8 0 0 1 15 3.8v.7" />
            <path d="M8.5 11h7M8.5 15h7M8.5 19h4" />
        </svg>
    );
}
