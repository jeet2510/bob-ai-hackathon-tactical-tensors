import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api/client";
import { clearAuth } from "../auth/auth";
import type { AuthUser } from "../auth/auth";
export default function Dashboard() {
    const navigate = useNavigate();

    const [user, setUser] = useState<AuthUser | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get("/me")
            .then((response) => {
                setUser(response.data.user);
            })
            .catch(() => {
                clearAuth();
                navigate("/login", {
                    replace: true,
                });
            })
            .finally(() => {
                setLoading(false);
            });
    }, [navigate]);

    async function handleLogout() {
        try {
            await api.post("/logout");
        } catch {
            // Token may already be invalid.
        }

        clearAuth();

        navigate("/login", {
            replace: true,
        });
    }

    if (loading) {
        return <div>Loading...</div>;
    }

    return (
        <div className="dashboard">

            <header className="dashboard-header">
                <div>
                    <h1>DVI Coordinator</h1>
                    <p>Disaster Victim Identification System</p>
                </div>

                <div>
                    <span>
                        {user?.name}
                    </span>

                    <button onClick={handleLogout}>
                        Logout
                    </button>
                </div>
            </header>

            <main className="dashboard-content">

                <h2>Dashboard</h2>

                <p>
                    Welcome, {user?.name}.
                </p>

                <div className="dashboard-placeholder">
                    <h3>Incident Management</h3>
                    <p>
                        Incident management will be implemented next.
                    </p>
                </div>

            </main>

        </div>
    );
}