import type { ReactNode } from "react";

export default function PublicLayout({ incidentName, children }: { incidentName?: string; children: ReactNode }) {
    return (
        <div className="public-shell">
            <header className="public-header">
                <strong>DVI Coordinator</strong>
                <span>{incidentName ? `Family report — ${incidentName}` : "Family report"}</span>
            </header>

            <main className="public-main">{children}</main>

            <footer className="public-footer">
                This information is used only to help identify a missing person and is kept confidential
                by the incident's response team.
            </footer>
        </div>
    );
}
