import axios from "axios";

export interface ApiError {
    message: string;
    /** Laravel 422 field errors, keyed by field name. */
    fields: Record<string, string>;
}

/**
 * Normalises anything thrown by axios into a shape the forms can render.
 *
 * Laravel returns 422 with `errors: { field: [msg, ...] }`; everything else
 * (network failure, 500, 403) only has a message, if that.
 */
export function toApiError(error: unknown, fallback = "Something went wrong."): ApiError {
    if (!axios.isAxiosError(error)) {
        return { message: fallback, fields: {} };
    }

    if (!error.response) {
        return {
            message: "Cannot reach the API. Check that the backend is running.",
            fields: {},
        };
    }

    const data = error.response.data as
        | { message?: string; errors?: Record<string, string[]> }
        | undefined;

    const fields: Record<string, string> = {};

    for (const [field, messages] of Object.entries(data?.errors ?? {})) {
        if (messages?.length) {
            fields[field] = messages[0];
        }
    }

    return {
        message: data?.message ?? fallback,
        fields,
    };
}
