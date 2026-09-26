import type { ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../api/client";
import { clearAuth, getStoredUser } from "../auth/auth";

export default function Layout({ children }: { children: ReactNode }) {
    const navigate = useNavigate();
    const user = getStoredUser();

    async function handleLogout() {
        try {
            await api.post("/logout");
        } catch {
            // The token may already be invalid server-side; sign out regardless.
        }

        clearAuth();
        navigate("/login", { replace: true });
    }

    return (
        <div className="shell">
            <header className="topbar">
                <div className="topbar-brand">
                    <Link to="/incidents">
                        <strong>DVI Coordinator</strong>
                    </Link>
                    <span>Disaster Victim Identification</span>
                </div>

                <div className="topbar-user">
                    {user && <span>{user.name}</span>}
                    <button className="btn btn-sm" onClick={handleLogout}>
                        Sign out
                    </button>
                </div>
            </header>

            <div className="page">{children}</div>
        </div>
    );
}
