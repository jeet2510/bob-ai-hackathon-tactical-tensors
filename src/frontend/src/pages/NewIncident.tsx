import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { createIncident } from "../api/bodies";
import { toApiError } from "../api/errors";
import Layout from "../components/Layout";
import { TextField } from "../components/Field";

export default function NewIncident() {
    const navigate = useNavigate();

    const [incidentId, setIncidentId] = useState("");
    const [name, setName] = useState("");
    const [incidentDate, setIncidentDate] = useState("");
    const [district, setDistrict] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError("");
        setFieldErrors({});

        try {
            const { data } = await createIncident({
                incident_id: incidentId.toUpperCase(),
                name,
                incident_date: incidentDate,
                district: district || undefined,
            });

            navigate(`/incidents/${data.incident.incident_id}/bodies/new`);
        } catch (caught) {
            const apiError = toApiError(caught, "Could not create the incident.");
            setError(apiError.message);
            setFieldErrors(apiError.fields);
        } finally {
            setSaving(false);
        }
    }

    return (
        <Layout>
            <div className="page-head">
                <div>
                    <h1>New incident</h1>
                    <p>
                        Opens an incident record. Recovered bodies and family reports are logged against
                        it next — records never cross between incidents.
                    </p>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <form className="card" onSubmit={submit}>
                <div className="form-grid">
                    <TextField
                        label="Incident reference (e.g. LS27)"
                        name="incident_id"
                        value={incidentId}
                        onChange={(_, v) => setIncidentId(v)}
                        error={fieldErrors.incident_id}
                        hint="Short code, letters/numbers/dashes only. Used in every record ID."
                    />
                    <TextField
                        label="Incident name"
                        name="name"
                        value={name}
                        onChange={(_, v) => setName(v)}
                        error={fieldErrors.name}
                    />
                    <TextField
                        label="Incident date"
                        name="incident_date"
                        type="date"
                        value={incidentDate}
                        onChange={(_, v) => setIncidentDate(v)}
                        error={fieldErrors.incident_date}
                    />
                    <TextField
                        label="District"
                        name="district"
                        value={district}
                        onChange={(_, v) => setDistrict(v)}
                        error={fieldErrors.district}
                    />
                </div>

                <div className="form-actions">
                    <button className="btn btn-primary" type="submit" disabled={saving}>
                        {saving ? "Creating…" : "Create incident and log the first body"}
                    </button>
                </div>
            </form>
        </Layout>
    );
}
