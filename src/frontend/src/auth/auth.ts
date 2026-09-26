export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export function getToken(): string | null {
    return localStorage.getItem("dvi_token");
}

export function getStoredUser(): AuthUser | null {
    const user = localStorage.getItem("dvi_user");

    if (!user) {
        return null;
    }

    try {
        return JSON.parse(user);
    } catch {
        return null;
    }
}

export function isAuthenticated(): boolean {
    return Boolean(getToken());
}

export function saveAuth(token: string, user: AuthUser) {
    localStorage.setItem("dvi_token", token);
    localStorage.setItem("dvi_user", JSON.stringify(user));
}

export function clearAuth() {
    localStorage.removeItem("dvi_token");
    localStorage.removeItem("dvi_user");
}