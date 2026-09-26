import type { ReactNode } from "react";

interface BaseProps {
    label: string;
    name: string;
    value: string;
    onChange: (name: string, value: string) => void;
    error?: string;
    hint?: string;
    wide?: boolean;
    placeholder?: string;
}

function Wrapper({
    label,
    name,
    error,
    hint,
    wide,
    children,
}: Pick<BaseProps, "label" | "name" | "error" | "hint" | "wide"> & { children: ReactNode }) {
    return (
        <div className={wide ? "form-group wide" : "form-group"}>
            <label htmlFor={name}>{label}</label>
            {children}
            {hint && !error && <div className="hint">{hint}</div>}
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}

export function TextField({
    type = "text",
    ...props
}: BaseProps & { type?: "text" | "number" | "date" | "datetime-local" | "tel" }) {
    return (
        <Wrapper {...props}>
            <input
                id={props.name}
                type={type}
                value={props.value}
                placeholder={props.placeholder}
                onChange={(event) => props.onChange(props.name, event.target.value)}
            />
        </Wrapper>
    );
}

export function TextAreaField(props: BaseProps) {
    return (
        <Wrapper {...props}>
            <textarea
                id={props.name}
                value={props.value}
                placeholder={props.placeholder}
                onChange={(event) => props.onChange(props.name, event.target.value)}
            />
        </Wrapper>
    );
}

export function SelectField({
    options,
    ...props
}: BaseProps & { options: Array<{ value: string; label: string }> }) {
    return (
        <Wrapper {...props}>
            <select
                id={props.name}
                value={props.value}
                onChange={(event) => props.onChange(props.name, event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Wrapper>
    );
}

export const SEX_OPTIONS = [
    { value: "", label: "Not recorded" },
    { value: "male", label: "Male" },
    { value: "female", label: "Female" },
    { value: "other", label: "Other" },
    { value: "unknown", label: "Unknown" },
];
