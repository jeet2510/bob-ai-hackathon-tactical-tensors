import axios from "axios";

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
    },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem("dvi_token");

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        // The token was revoked or expired server-side. Route protection only
        // checks that a token exists, so the API is where this is discovered.
        if (error.response?.status === 401 && !isLoginRequest(error)) {
            localStorage.removeItem("dvi_token");
            localStorage.removeItem("dvi_user");

            if (window.location.pathname !== "/login") {
                window.location.assign("/login");
            }
        }

        return Promise.reject(error);
    },
);

function isLoginRequest(error: { config?: { url?: string } }): boolean {
    // A failed sign-in must surface its own message rather than redirecting.
    return Boolean(error.config?.url?.includes("/login"));
}

export default api;