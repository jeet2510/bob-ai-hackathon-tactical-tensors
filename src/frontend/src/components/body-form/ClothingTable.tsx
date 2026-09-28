import { CLOTHING_COLOUR_SUGGESTIONS, GARMENT_SUGGESTIONS, type ClothingRow } from "../../types";

/** The five slots are fixed by the paper form — no add/remove here. */
export default function ClothingTable({
    rows,
    onChange,
}: {
    rows: ClothingRow[];
    onChange: (rows: ClothingRow[]) => void;
}) {
    function update(index: number, patch: Partial<ClothingRow>) {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    return (
        <div className="repeatable-table">
            <datalist id="garment-suggestions">
                {GARMENT_SUGGESTIONS.map((g) => (
                    <option key={g} value={g} />
                ))}
            </datalist>
            <datalist id="clothing-colour-suggestions">
                {CLOTHING_COLOUR_SUGGESTIONS.map((c) => (
                    <option key={c} value={c} />
                ))}
            </datalist>
            <table>
                <thead>
                    <tr>
                        <th>Slot</th>
                        <th>Garment</th>
                        <th>Colour</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={row.slot}>
                            <td data-label="Slot" className="row-slot-label">
                                {row.slot}
                            </td>
                            <td data-label="Garment">
                                <input
                                    list="garment-suggestions"
                                    autoComplete="off"
                                    value={row.garment}
                                    onChange={(e) => update(i, { garment: e.target.value })}
                                />
                            </td>
                            <td data-label="Colour">
                                <input
                                    list="clothing-colour-suggestions"
                                    autoComplete="off"
                                    value={row.colour}
                                    onChange={(e) => update(i, { colour: e.target.value })}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
