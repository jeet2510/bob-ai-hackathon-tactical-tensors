import ClothingTable from "../body-form/ClothingTable";
import FeaturesTable from "../body-form/FeaturesTable";
import { IdDocumentsTable, JewelleryTable } from "../body-form/RepeatableSections";
import { CheckboxGroupField, FileUploadField, SelectField, TextAreaField, TextField } from "../Field";
import {
    BUILD_OPTIONS,
    DENTAL_RECORDS_OPTIONS,
    DNA_REFERENCE_OPTIONS,
    EYE_COLOUR_OPTIONS,
    FACIAL_HAIR_OPTIONS,
    HAIR_COLOUR_OPTIONS,
    HAIR_LENGTH_OPTIONS,
    ID_SOURCE_OPTIONS,
    MARITAL_STATUS_OPTIONS,
    PM_SEX_OPTIONS,
    SKIN_TONE_OPTIONS,
    type AmReportFormState,
    type ClothingRow,
    type DistinguishingFeatureRow,
    type IdDocumentRow,
    type JewelleryEffectRow,
} from "../../types";

export default function AmReportFields({
    form,
    setField,
    setForm,
    fieldErrors,
    variant,
    uploadingPhoto,
    onUploadRecentPhoto,
    onUploadTattooPhoto,
}: {
    form: AmReportFormState;
    setField: (name: string, value: string) => void;
    setForm: (updater: (prev: AmReportFormState) => AmReportFormState) => void;
    fieldErrors: Record<string, string>;
    variant: "public" | "staff";
    uploadingPhoto: "recent" | "tattoo" | null;
    onUploadRecentPhoto: (file: File) => void;
    onUploadTattooPhoto: (file: File) => void;
}) {
    return (
        <>
            <section className="form-section card" id="am-section-A">
                <SectionHeader letter="A" title="Missing person's details" />
                <div className="form-grid">
                    <TextField label="Name" name="reported_name" value={form.reported_name} onChange={setField} error={fieldErrors.reported_name} required wide />
                    <TextField label="Relationship to person reporting" name="reported_by_relation" value={form.reported_by_relation} onChange={setField} placeholder="e.g. Son, Daughter, Neighbour" />
                    <TextField label="Occupation" name="occupation" value={form.occupation} onChange={setField} />
                    <TextField label="Last seen — date/time" name="last_seen_at" type="datetime-local" value={form.last_seen_at} onChange={setField} error={fieldErrors.last_seen_at} />
                    <TextField label="Last seen — place" name="last_seen_place" value={form.last_seen_place} onChange={setField} wide />
                    <SelectField label="Marital status" name="marital_status" value={form.marital_status} onChange={setField} options={MARITAL_STATUS_OPTIONS} />
                </div>
            </section>

            <section className="form-section card" id="am-section-B">
                <SectionHeader letter="B" title="Biological profile" description="Best estimate is fine." />
                <div className="form-grid form-grid-narrow">
                    <SelectField label="Sex" name="sex" value={form.sex} onChange={setField} options={PM_SEX_OPTIONS} error={fieldErrors.sex} />
                    <TextField label="Age (years)" name="age" type="number" value={form.age} onChange={setField} error={fieldErrors.age} />
                    <TextField label="Height" name="height_text" value={form.height_text} onChange={setField} placeholder='e.g. "5 ft 7 in" or cm' />
                </div>
            </section>

            <section className="form-section card" id="am-section-C">
                <SectionHeader letter="C" title="Physical appearance" />
                <div className="form-grid form-grid-narrow">
                    <SelectField label="Skin tone" name="skin_tone" value={form.skin_tone} onChange={setField} options={SKIN_TONE_OPTIONS} />
                    {form.skin_tone === "Other" && (
                        <TextField label="Skin tone, other" name="skin_tone_other" value={form.skin_tone_other} onChange={setField} />
                    )}
                    <SelectField label="Build" name="build" value={form.build} onChange={setField} options={BUILD_OPTIONS} />
                    <SelectField label="Hair colour" name="hair_colour" value={form.hair_colour} onChange={setField} options={HAIR_COLOUR_OPTIONS} />
                    <SelectField label="Hair length" name="hair_length" value={form.hair_length} onChange={setField} options={HAIR_LENGTH_OPTIONS} />
                    <SelectField label="Eye colour" name="eye_colour" value={form.eye_colour} onChange={setField} options={EYE_COLOUR_OPTIONS} />
                    <SelectField label="Facial hair" name="facial_hair" value={form.facial_hair} onChange={setField} options={FACIAL_HAIR_OPTIONS} />
                </div>
            </section>

            <section className="form-section card" id="am-section-D">
                <SectionHeader letter="D" title="Records that could confirm identity" description="This decides which confirmation test gets recommended — please answer carefully." />
                <div className="form-grid form-grid-narrow">
                    <SelectField
                        label="DNA reference sample"
                        name="dna_reference_type"
                        value={form.dna_reference_type}
                        onChange={setField}
                        options={DNA_REFERENCE_OPTIONS}
                        placeholder="None available"
                    />
                    <SelectField
                        label="Dental records"
                        name="dental_records_available"
                        value={form.dental_records_available ? "1" : "0"}
                        onChange={(_, v) => setForm((p) => ({ ...p, dental_records_available: v === "1" }))}
                        options={DENTAL_RECORDS_OPTIONS}
                    />
                    {form.dental_records_available && (
                        <TextField label="Dentist / clinic name & contact" name="dentist_contact" value={form.dentist_contact} onChange={setField} wide />
                    )}
                </div>
                <CheckboxGroupField
                    label="Fingerprints on file (select any that apply)"
                    name="id_sources"
                    values={form.id_sources}
                    onChange={(_, values) => setForm((p) => ({ ...p, id_sources: values }))}
                    options={ID_SOURCE_OPTIONS.map((o) => ({ value: o, label: o }))}
                    hint="Leave all unchecked if none are known."
                    wide
                />
            </section>

            <section className="form-section card" id="am-section-E">
                <SectionHeader letter="E" title="Photographs available" description="Both optional." />
                <div className="form-grid">
                    {form.recent_photo_ref ? (
                        <div className="form-group">
                            <label>Recent photograph</label>
                            <span className="chip chip-match">attached</span>
                        </div>
                    ) : (
                        <FileUploadField
                            label="Recent photograph"
                            name="recent_photo"
                            accept="image/*"
                            capture="environment"
                            busy={uploadingPhoto === "recent"}
                            busyLabel="Uploading…"
                            onFileSelected={onUploadRecentPhoto}
                        />
                    )}
                    {form.tattoo_photo_ref ? (
                        <div className="form-group">
                            <label>Tattoo/mark close-up</label>
                            <span className="chip chip-match">attached</span>
                        </div>
                    ) : (
                        <FileUploadField
                            label="Close-up of a tattoo, mark or scar"
                            name="tattoo_photo"
                            accept="image/*"
                            capture="environment"
                            busy={uploadingPhoto === "tattoo"}
                            busyLabel="Uploading…"
                            onFileSelected={onUploadTattooPhoto}
                        />
                    )}
                </div>
            </section>

            <section className="form-section card" id="am-section-F">
                <SectionHeader letter="F" title="Body diagram — tattoos, marks, scars & old injuries" description="If they carry a device card (pacemaker, joint replacement, etc.), copy the serial number exactly — it is the single strongest piece of evidence this system uses." />
                <FeaturesTable
                    rows={form.distinguishing_features}
                    onChange={(v) => setForm((p) => ({ ...p, distinguishing_features: v as DistinguishingFeatureRow[] }))}
                />
            </section>

            <section className="form-section card" id="am-section-G">
                <SectionHeader letter="G" title="Clothing last worn" description="As best remembered — exact colour is less important than getting the garment right." />
                <ClothingTable rows={form.clothing} onChange={(v) => setForm((p) => ({ ...p, clothing: v as ClothingRow[] }))} />
            </section>

            <section className="form-section card" id="am-section-H">
                <SectionHeader letter="H" title="Jewellery & personal effects usually worn or carried" />
                <JewelleryTable
                    rows={form.jewellery_effects}
                    onChange={(v) => setForm((p) => ({ ...p, jewellery_effects: v as JewelleryEffectRow[] }))}
                />
            </section>

            <section className="form-section card" id="am-section-I">
                <SectionHeader letter="I" title="Identity documents they usually carried" />
                <IdDocumentsTable
                    rows={form.id_documents}
                    onChange={(v) => setForm((p) => ({ ...p, id_documents: v as IdDocumentRow[] }))}
                />
            </section>

            <section className="form-section card" id="am-section-notes">
                <SectionHeader letter="•" title="Anything else the family wants recorded" />
                <TextAreaField label="Notes" name="notes" value={form.notes} onChange={setField} placeholder="Surgeries, implants, habits, anything distinctive — in your own words." />
            </section>

            <section className="form-section card" id="am-section-reporter">
                <SectionHeader
                    letter="•"
                    title={variant === "public" ? "Your details" : "Reporter details"}
                    description={variant === "public" ? "So the coordinator can reach you if they need anything else." : undefined}
                />
                <div className="form-grid">
                    <TextField
                        label={variant === "public" ? "Your name" : "Reporter name"}
                        name="reporter_name"
                        value={form.reporter_name}
                        onChange={setField}
                        error={fieldErrors.reporter_name}
                        required
                    />
                    <TextField label={variant === "public" ? "Your phone number" : "Reporter phone"} name="reporter_phone" type="tel" value={form.reporter_phone} onChange={setField} />
                    <TextField label={variant === "public" ? "Your address" : "Reporter address"} name="reporter_address" value={form.reporter_address} onChange={setField} wide />
                    {variant === "staff" && (
                        <>
                            <TextField label="Interviewing officer" name="interviewing_officer" value={form.interviewing_officer} onChange={setField} />
                            <TextField label="Date/time of interview" name="interviewed_at" type="datetime-local" value={form.interviewed_at} onChange={setField} />
                        </>
                    )}
                </div>
            </section>
        </>
    );
}

function SectionHeader({ letter, title, description }: { letter: string; title: string; description?: string }) {
    return (
        <div className="section-header">
            <span className="section-number">{letter}</span>
            <div className="section-header-text">
                <h3>{title}</h3>
                {description && <p className="muted">{description}</p>}
            </div>
        </div>
    );
}
