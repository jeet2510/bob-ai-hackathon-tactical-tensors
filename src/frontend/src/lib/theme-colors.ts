import { useEffect, useState } from "react";

/**
 * Charts render as SVG paint attributes, which read the CSS custom
 * properties as static text rather than live values, so the resolved
 * colour has to be read out of the cascade — this keeps every chart on the
 * same tokens as the rest of the interface, light or dark, without a second
 * palette to maintain.
 */
export interface ThemeColors {
    accent: string;
    high: string;
    moderate: string;
    low: string;
    danger: string;
    border: string;
    textMuted: string;
    textFaint: string;
    surface: string;
    surface2: string;
}

const TOKENS: Record<keyof ThemeColors, string> = {
    accent: "--accent",
    high: "--high",
    moderate: "--moderate",
    low: "--low",
    danger: "--danger",
    border: "--border",
    textMuted: "--text-muted",
    textFaint: "--text-faint",
    surface: "--surface",
    surface2: "--surface-2",
};

function readThemeColors(): ThemeColors {
    const style = getComputedStyle(document.documentElement);
    const out = {} as ThemeColors;

    (Object.keys(TOKENS) as Array<keyof ThemeColors>).forEach((key) => {
        out[key] = style.getPropertyValue(TOKENS[key]).trim();
    });

    return out;
}

/** Re-reads the palette when the OS switches between light and dark. */
export function useThemeColors(): ThemeColors {
    const [colors, setColors] = useState<ThemeColors>(readThemeColors);

    useEffect(() => {
        const media = window.matchMedia("(prefers-color-scheme: dark)");
        const update = () => setColors(readThemeColors());

        media.addEventListener("change", update);
        return () => media.removeEventListener("change", update);
    }, []);

    return colors;
}

/** A monotonic single-hue ramp for ordinal severity scales (light -> dark). */
export function sequentialRamp(hex: string, steps: number): string[] {
    const clean = hex.replace("#", "");
    const bytes = clean.length === 3 ? clean.split("").map((c) => c + c) : clean.match(/../g) ?? ["00", "00", "00"];
    const [r, g, b] = bytes.map((h) => parseInt(h, 16));

    return Array.from({ length: steps }, (_, i) => {
        const alpha = 0.32 + (0.68 * i) / Math.max(steps - 1, 1);
        return `rgba(${r}, ${g}, ${b}, ${alpha.toFixed(2)})`;
    });
}
