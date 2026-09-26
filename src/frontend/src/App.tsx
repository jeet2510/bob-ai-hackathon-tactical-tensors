import type { ReactNode } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import { isAuthenticated } from "./auth/auth";
import Login from "./pages/Login";
import Incidents from "./pages/Incidents";
import IncidentLayout from "./pages/IncidentLayout";
import CommandBoard from "./pages/incident/CommandBoard";
import BodyReview from "./pages/incident/BodyReview";
import Profiles from "./pages/incident/Profiles";
import Assignment from "./pages/incident/Assignment";
import Report from "./pages/incident/Report";
import EvaluationLab from "./pages/incident/EvaluationLab";
import Audit from "./pages/incident/Audit";
import "./App.css";

function ProtectedRoute({ children }: { children: ReactNode }) {
    // Presence of a token only. The API is the real authority, and a stale
    // token is discovered there — the axios interceptor signs the user out.
    if (!isAuthenticated()) {
        return <Navigate to="/login" replace />;
    }

    return children;
}

export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />

            <Route
                path="/incidents"
                element={
                    <ProtectedRoute>
                        <Incidents />
                    </ProtectedRoute>
                }
            />

            <Route
                path="/incidents/:incidentId"
                element={
                    <ProtectedRoute>
                        <IncidentLayout />
                    </ProtectedRoute>
                }
            >
                <Route index element={<CommandBoard />} />
                <Route path="bodies/:pmId" element={<BodyReview />} />
                <Route path="profiles" element={<Profiles />} />
                <Route path="assignment" element={<Assignment />} />
                <Route path="report" element={<Report />} />
                <Route path="evaluation" element={<EvaluationLab />} />
                <Route path="audit" element={<Audit />} />
            </Route>

            <Route
                path="/"
                element={<Navigate to={isAuthenticated() ? "/incidents" : "/login"} replace />}
            />

            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}
