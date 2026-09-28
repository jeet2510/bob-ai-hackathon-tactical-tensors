import type { DentalChartEntry } from "../../types";

const UPPER = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const CODES: Array<"" | "M" | "F" | "C" | "R"> = ["", "M", "F", "C", "R"];

export default function DentalChartGrid({
    value,
    onChange,
}: {
    value: DentalChartEntry[];
    onChange: (entries: DentalChartEntry[]) => void;
}) {
    const byTooth = new Map(value.map((entry) => [entry.tooth, entry.code]));

    function cycle(tooth: number) {
        const current = byTooth.get(tooth) ?? "";
        const next = CODES[(CODES.indexOf(current) + 1) % CODES.length];
        const rest = value.filter((entry) => entry.tooth !== tooth);

        onChange(next === "" ? rest : [...rest, { tooth, code: next }]);
    }

    function row(teeth: number[], key: string) {
        return (
            <div className="fdi-grid-row" key={key}>
                {teeth.map((tooth) => {
                    const code = byTooth.get(tooth) ?? "";

                    return (
                        <button
                            key={tooth}
                            type="button"
                            className={`fdi-tooth${code ? ` state-${code}` : ""}`}
                            onClick={() => cycle(tooth)}
                            title={`Tooth ${tooth}${code ? ` — ${code}` : ""}`}
                        >
                            <span className="fdi-tooth-num">{tooth}</span>
                            <span className="fdi-tooth-code">{code || "·"}</span>
                        </button>
                    );
                })}
            </div>
        );
    }

    return (
        <div className="fdi-grid">
            {row(UPPER, "upper")}
            {row(LOWER, "lower")}
            <p className="faint">
                Click a tooth to cycle blank → M (missing) → F (filled) → C (crown) → R (root canal). Leave a
                tooth blank if sound or not examined.
            </p>
        </div>
    );
}
