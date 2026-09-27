import { useState } from "react";
import type { FormEvent } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import api from "../api/client";
import {
    isAuthenticated,
    saveAuth,
} from "../auth/auth";

export default function Login() {
    const navigate = useNavigate();

    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");

    const [error, setError] = useState("");
    const [loading, setLoading] = useState(false);

    if (isAuthenticated()) {
        return <Navigate to="/incidents" replace />;
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        setError("");
        setLoading(true);

        try {
            const response = await api.post("/login", {
                email,
                password,
            });

            saveAuth(
                response.data.token,
                response.data.user
            );

            navigate("/incidents", {
                replace: true,
            });
        } catch (error: any) {
            setError(
                error.response?.data?.message ||
                "Unable to login. Please check your credentials."
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="login-page">
            <div className="login-card">

                <div className="login-tab">
                    <span className="login-tab-code">FORM DVI&#8209;01 &middot; ACCESS</span>
                    <span className="login-tab-status">
                        <span className="login-tab-dot" aria-hidden="true" />
                        System online
                    </span>
                </div>

                <div className="login-body">
                    <div className="login-header">
                        <h1>DVI Coordinator</h1>
                        <p>Disaster Victim Identification &mdash; secure access</p>
                    </div>

                    <form onSubmit={handleSubmit} className="login-form">

                        <div className="login-field">
                            <label htmlFor="email">
                                Email
                            </label>

                            <input
                                id="email"
                                type="email"
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                                placeholder="you@agency.gov"
                                autoComplete="username"
                                required
                            />
                        </div>

                        <div className="login-field">
                            <label htmlFor="password">
                                Password
                            </label>

                            <input
                                id="password"
                                type="password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                                autoComplete="current-password"
                                required
                            />
                        </div>

                        {error && (
                            <div className="login-error" role="alert">
                                {error}
                            </div>
                        )}

                        <button
                            type="submit"
                            className="login-submit"
                            disabled={loading}
                        >
                            <span>{loading ? "Authenticating…" : "Sign in"}</span>
                        </button>

                    </form>

                    <p className="login-footnote">
                        Access to case records is logged and audited under chain&#8209;of&#8209;custody protocol.
                    </p>
                </div>
            </div>
        </div>
    );
}