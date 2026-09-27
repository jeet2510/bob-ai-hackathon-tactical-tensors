import { useEffect, useState } from "react";
import api from "../api/client";

// Keyed by API path, not component instance: the gallery and a body's own
// photo tab both reference the same photograph, and a blob fetched once
// should never be requested from the API a second time.
const cache = new Map<string, string>();

/**
 * An `<img>` cannot carry the Authorization header the photo route requires,
 * and the backend returns an API-relative path rather than an absolute URL,
 * so the browser cannot fetch it directly. This loads the photo through the
 * authenticated client instead and renders the resulting blob.
 */
export default function AuthImage({
    src,
    alt,
    className,
}: {
    src: string;
    alt: string;
    className?: string;
}) {
    const path = src.replace(/^\/api/, "");
    const [objectUrl, setObjectUrl] = useState<string | null>(cache.get(path) ?? null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (cache.has(path)) {
            setObjectUrl(cache.get(path)!);
            return;
        }

        let cancelled = false;
        setFailed(false);

        api.get(path, { responseType: "blob" })
            .then(({ data }) => {
                if (cancelled) return;
                const url = URL.createObjectURL(data);
                cache.set(path, url);
                setObjectUrl(url);
            })
            .catch(() => {
                if (!cancelled) setFailed(true);
            });

        return () => {
            cancelled = true;
        };
    }, [path]);

    if (failed) {
        return <div className={`auth-image-error${className ? ` ${className}` : ""}`}>Could not load image.</div>;
    }

    if (!objectUrl) {
        return <div className={`auth-image-skeleton${className ? ` ${className}` : ""}`} aria-hidden="true" />;
    }

    return <img src={objectUrl} alt={alt} className={className} loading="lazy" />;
}
