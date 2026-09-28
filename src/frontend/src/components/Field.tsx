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
    /** Rendered next to the label — used for the "AI-suggested" indicator. */
    badge?: ReactNode;
    required?: boolean;
}

function Wrapper({
    label,
    name,
    error,
    hint,
    wide,
    badge,
    required,
    children,
}: Pick<BaseProps, "label" | "name" | "error" | "hint" | "wide" | "badge" | "required"> & { children: ReactNode }) {
    return (
        <div className={wide ? "form-group wide" : "form-group"}>
            <label htmlFor={name}>
                <span className="form-group-label-text">
                    {label}
                    {required && <span className="required-mark">*</span>}
                </span>
                {badge}
            </label>
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
    placeholder = "Select…",
    ...props
}: BaseProps & { options: Array<{ value: string; label: string }>; placeholder?: string }) {
    return (
        <Wrapper {...props}>
            <select
                id={props.name}
                className="select-input"
                value={props.value}
                onChange={(event) => props.onChange(props.name, event.target.value)}
            >
                <option value="">{placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Wrapper>
    );
}

/**
 * A free-text field with browser-native autocomplete suggestions from a
 * closed vocabulary — lets an examiner type what they see while still being
 * nudged toward values the matching engine actually recognises.
 */
export function DatalistField({
    suggestions,
    ...props
}: BaseProps & { suggestions: string[] }) {
    const listId = `${props.name}-list`;

    return (
        <Wrapper {...props}>
            <input
                id={props.name}
                list={listId}
                type="text"
                value={props.value}
                placeholder={props.placeholder}
                autoComplete="off"
                onChange={(event) => props.onChange(props.name, event.target.value)}
            />
            <datalist id={listId}>
                {suggestions.map((s) => (
                    <option key={s} value={s} />
                ))}
            </datalist>
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

export function RadioGroupField({
    options,
    ...props
}: BaseProps & { options: Array<{ value: string; label: string }> }) {
    return (
        <Wrapper {...props}>
            <div className="radio-group" role="radiogroup" aria-label={props.label}>
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={`radio-pill${props.value === option.value ? " checked" : ""}`}
                    >
                        <input
                            type="radio"
                            name={props.name}
                            checked={props.value === option.value}
                            onChange={() => props.onChange(props.name, option.value)}
                        />
                        {option.label}
                    </label>
                ))}
            </div>
        </Wrapper>
    );
}

interface CheckboxGroupProps {
    label: string;
    name: string;
    values: string[];
    onChange: (name: string, values: string[]) => void;
    options: Array<{ value: string; label: string }>;
    error?: string;
    hint?: string;
    wide?: boolean;
    badge?: ReactNode;
    /** At most one option may be selected at a time (still rendered as checkboxes, per the paper form). */
    single?: boolean;
}

export function CheckboxGroupField({ options, single, ...props }: CheckboxGroupProps) {
    function toggle(value: string) {
        if (single) {
            props.onChange(props.name, props.values.includes(value) ? [] : [value]);
            return;
        }

        const next = props.values.includes(value)
            ? props.values.filter((v) => v !== value)
            : [...props.values, value];

        props.onChange(props.name, next);
    }

    return (
        <Wrapper label={props.label} name={props.name} error={props.error} hint={props.hint} wide={props.wide} badge={props.badge}>
            <div className="checkbox-group">
                {options.map((option) => {
                    const checked = props.values.includes(option.value);

                    return (
                        <label key={option.value} className={`checkbox-pill${checked ? " checked" : ""}`}>
                            <input type="checkbox" checked={checked} onChange={() => toggle(option.value)} />
                            {option.label}
                        </label>
                    );
                })}
            </div>
        </Wrapper>
    );
}

export function FileUploadField({
    label,
    name,
    onFileSelected,
    accept,
    capture,
    hint,
    error,
    busy,
    busyLabel,
    compact,
}: {
    label: string;
    name: string;
    onFileSelected: (file: File) => void;
    accept?: string;
    capture?: "environment" | "user";
    hint?: string;
    error?: string;
    busy?: boolean;
    busyLabel?: string;
    /** A small inline picker (used inside a table row) instead of the full dropzone. */
    compact?: boolean;
}) {
    const input = (
        <input
            id={name}
            type="file"
            accept={accept}
            capture={capture}
            disabled={busy}
            onChange={(event) => {
                const file = event.target.files?.[0];
                if (file) {
                    onFileSelected(file);
                }
                event.target.value = "";
            }}
        />
    );

    if (compact) {
        return input;
    }

    return (
        <div className="form-group">
            <label htmlFor={name}>{label}</label>
            <div className="upload-dropzone">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                    <path
                        fill="currentColor"
                        d="M12 3a1 1 0 0 1 1 1v9.59l2.3-2.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.42l2.3 2.3V4a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v1a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1a1 1 0 0 1 1-1Z"
                    />
                </svg>
                <div className="upload-dropzone-text">
                    <strong>{busy ? busyLabel ?? "Working…" : label}</strong>
                    <span>{busy ? "This can take a few seconds" : "Tap to choose a file"}</span>
                </div>
                {input}
            </div>
            {hint && !error && <div className="hint">{hint}</div>}
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
