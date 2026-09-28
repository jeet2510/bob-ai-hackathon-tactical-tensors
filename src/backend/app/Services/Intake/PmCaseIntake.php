<?php

namespace App\Services\Intake;

use App\Models\Incident;
use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use Illuminate\Support\Facades\DB;

/**
 * Turns one submitted post-mortem intake form (sections A–K of the DVI field
 * form) into the rows the rest of the system already knows how to read.
 *
 * The one rule that matters here: everything except the header scalars
 * (condition, sex, age, height, primary-identifier status, dental chart)
 * is descriptive content, and descriptive content is only ever visible to
 * the matcher through observation/observation_norm rows — never through a
 * PmCase column. See EvidenceScorer::SET_CATEGORIES / SINGLE_CATEGORIES.
 *
 * Items are written with `reviewed_by` set, so a body logged through this
 * form is scorable by any match run immediately, regardless of which
 * --extractor the run was started with (MatchingService::loadItems() always
 * includes reviewed rows).
 */
class PmCaseIntake
{
    /** Paper build label -> INTERPOL codebook value (Lexicon::BUILD). */
    public const BUILD_MAP = [
        'Slim' => 'slight',
        'Medium' => 'medium',
        'Heavy' => 'large',
        'Muscular' => 'large',
        'Obese' => 'large',
    ];

    /** Paper skin-tone label -> codebook value (Lexicon::SKIN_TONE), where one exists. */
    public const SKIN_TONE_MAP = [
        'Fair' => 'fair',
        'Wheatish' => 'medium',
        'Dark' => 'dark',
        // 'Other' has no codebook value; the free-text detail is kept as-is.
    ];

    /** Paper hair-colour label -> codebook value (Lexicon::HAIR_COLOUR), where one exists. */
    public const HAIR_COLOUR_MAP = [
        'Black' => 'black',
        'Brown' => 'brown',
        'Grey' => 'grey',
        'White' => 'grey',
        // 'Dyed' has no codebook value.
    ];

    /** Paper hair-length label -> codebook value (Lexicon::HAIR_LENGTH), where one exists. */
    public const HAIR_LENGTH_MAP = [
        'Short' => 'short',
        'Medium' => 'medium',
        'Long' => 'long',
        // 'Bald' has no codebook value.
    ];

    /** Paper eye-colour label -> codebook value (Lexicon::EYE_COLOUR), where one exists. */
    public const EYE_COLOUR_MAP = [
        'Black' => 'black',
        'Brown' => 'brown',
        // 'Hazel' and 'Grey' have no codebook value.
    ];

    /** Paper facial-hair label -> codebook value (Lexicon::FACIAL_HAIR), where one exists. */
    public const FACIAL_HAIR_MAP = [
        'Clean-shaven' => 'clean_shaven',
        'Moustache' => 'moustache',
        'Beard' => 'beard',
        // 'Stubble' and 'Not applicable' have no codebook value.
    ];

    /** Paper distinguishing-feature type -> mark item type (Lexicon::MARK_TYPE), where one exists. */
    public const MARK_TYPE_MAP = [
        'scar' => 'scar_linear',
        'mole' => 'mole',
        'birthmark' => 'birthmark',
        'burn' => 'burn_scar',
        // mark / deformity / amputation / piercing / other have no codebook value:
        // still recorded, but not scorable until a reviewer supplies a type.
    ];

    /** Paper photo-log modality label -> photo_evidence.modality. */
    public const PHOTO_MODALITY_MAP = [
        'Body diagram' => 'body_diagram',
        'Tattoo/mark' => 'tattoo_closeup',
        'Clothing' => 'clothing_flatlay',
        'Face (restr.)' => PhotoEvidence::MODALITY_FACE,
        'Other' => 'other',
    ];

    protected int $obsSeq = 0;

    protected int $photoSeq = 0;

    /**
     * @param  array<string, mixed>  $data  validated request payload
     * @param  array<string, string>  $stagedFiles  upload_ref => absolute path of a file staged via UploadController
     */
    public function store(Incident $incident, array $data, array $stagedFiles = []): PmCase
    {
        return DB::transaction(function () use ($incident, $data, $stagedFiles) {
            $pmId = $data['pm_id'] ?? $this->nextPmId($incident->incident_id);

            $pm = PmCase::create([
                'pm_id' => $pmId,
                'incident_id' => $incident->incident_id,
                'examiner_name' => $data['examiner_name'],
                'examiner_role' => $data['examiner_role'],
                'found_at' => $data['found_at'],
                'found_place' => $data['found_place'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lon' => $data['lon'] ?? null,
                'body_condition' => $data['body_condition'],
                'sex' => $data['sex'],
                'age_min' => $data['age_min'],
                'age_max' => $data['age_max'],
                'height_cm' => $data['height_cm'] ?? null,
                'dna_status' => $data['dna_status'],
                'dental_status' => $data['dental_status'],
                'print_status' => $data['print_status'],
                'dental_chart_fdi' => $this->dentalChartJson($data['dental_chart'] ?? []),
                'signature_note' => $data['signature_note'] ?? null,
                'completed_at' => $data['completed_at'] ?? null,
                'chain_of_custody_hash' => $data['chain_of_custody_hash'] ?? null,
                'source_type' => $data['source'] ?? 'manual',
                'synthetic' => false,
            ]);

            $reviewer = $data['examiner_role'];

            $this->writeAppearance($pm, $data, $reviewer);
            $this->writeDistinguishingFeatures($pm, $data['distinguishing_features'] ?? [], $reviewer);
            $this->writeClothing($pm, $data['clothing'] ?? [], $reviewer);
            $this->writeJewelleryEffects($pm, $data['jewellery_effects'] ?? [], $reviewer);
            $this->writeIdDocuments($pm, $data['id_documents'] ?? [], $reviewer);
            $this->writeNotes($pm, $data['notes'] ?? null, $reviewer);
            $this->writePhotoLog($pm, $data['photo_log'] ?? [], $reviewer, $stagedFiles);

            return $pm->fresh();
        });
    }

    /**
     * @param  list<array{tooth: int, code: string}>  $rows
     */
    protected function dentalChartJson(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $chart = ['missing' => [], 'filled' => [], 'crown' => [], 'root_canal' => []];
        $codeToState = ['M' => 'missing', 'F' => 'filled', 'C' => 'crown', 'R' => 'root_canal'];

        foreach ($rows as $row) {
            $state = $codeToState[$row['code']] ?? null;

            if ($state !== null) {
                $chart[$state][] = (int) $row['tooth'];
            }
        }

        return json_encode($chart);
    }

    /**
     * Sections B/C: build, skin tone, hair, eyes, facial hair — one Observation
     * box each ("build", "appearance"), one ObservationNorm item each.
     */
    protected function writeAppearance(PmCase $pm, array $data, string $reviewer): void
    {
        if (filled($data['build'] ?? null)) {
            $value = self::BUILD_MAP[$data['build']] ?? null;

            $this->writeItem($pm, 'build', 'B. Biological profile', "Build: {$data['build']}", $reviewer, [
                'category' => 'build',
                'value' => $value,
                'raw_label' => $value === null ? $data['build'] : null,
            ]);
        }

        if (filled($data['skin_tone'] ?? null)) {
            $label = $data['skin_tone'] === 'Other' && filled($data['skin_tone_other'] ?? null)
                ? $data['skin_tone_other']
                : $data['skin_tone'];
            $value = self::SKIN_TONE_MAP[$data['skin_tone']] ?? null;

            $this->writeItem($pm, 'appearance', 'C. Physical appearance', "Skin tone: {$label}", $reviewer, [
                'category' => 'skin_tone',
                'value' => $value,
                'raw_label' => $value === null ? $label : null,
            ]);
        }

        if (filled($data['hair_colour'] ?? null) || filled($data['hair_length'] ?? null)) {
            $colour = self::HAIR_COLOUR_MAP[$data['hair_colour'] ?? ''] ?? null;
            $length = self::HAIR_LENGTH_MAP[$data['hair_length'] ?? ''] ?? null;

            $this->writeItem($pm, 'appearance', 'C. Physical appearance',
                'Hair: '.trim(($data['hair_colour'] ?? '').' '.($data['hair_length'] ?? '')), $reviewer, [
                    'category' => 'hair',
                    'colour' => $colour,
                    'length' => $length,
                    'raw_colour' => $colour === null ? ($data['hair_colour'] ?? null) : null,
                    'raw_length' => $length === null ? ($data['hair_length'] ?? null) : null,
                ]);
        }

        if (filled($data['eye_colour'] ?? null)) {
            $value = self::EYE_COLOUR_MAP[$data['eye_colour']] ?? null;

            $this->writeItem($pm, 'appearance', 'C. Physical appearance', "Eyes: {$data['eye_colour']}", $reviewer, [
                'category' => 'eyes',
                'colour' => $value,
                'raw_colour' => $value === null ? $data['eye_colour'] : null,
            ]);
        }

        if (filled($data['facial_hair'] ?? null)) {
            $value = self::FACIAL_HAIR_MAP[$data['facial_hair']] ?? null;

            $this->writeItem($pm, 'appearance', 'C. Physical appearance', "Facial hair: {$data['facial_hair']}", $reviewer, [
                'category' => 'facial_hair',
                'value' => $value,
                'raw_label' => $value === null ? $data['facial_hair'] : null,
            ]);
        }
    }

    /**
     * Section F: up to six distinguishing features, each its own form box.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeDistinguishingFeatures(PmCase $pm, array $rows, string $reviewer): void
    {
        foreach ($rows as $row) {
            $type = $row['type'] ?? null;

            if (blank($type)) {
                continue;
            }

            $side = match ($row['side'] ?? null) {
                'L' => 'left',
                'R' => 'right',
                default => null,
            };

            $description = trim(($row['description'] ?? '').($row['serial_no'] ?? '' ? ' Serial: '.$row['serial_no'] : ''));

            if ($type === 'tattoo') {
                $this->writeItem($pm, 'tattoo', 'F. Body diagram', $description ?: 'Tattoo', $reviewer, [
                    'category' => 'tattoo',
                    'design' => null,
                    'region' => $row['region'] ?? null,
                    'laterality' => $side,
                    'legible' => true,
                    'raw_description' => $row['description'] ?? null,
                ]);

                continue;
            }

            if ($type === 'implant') {
                $this->writeItem($pm, 'implant', 'F. Body diagram', $description ?: 'Implant', $reviewer, [
                    'category' => 'implant',
                    'type' => null,
                    'region' => $row['region'] ?? null,
                    'laterality' => $side,
                    'serial' => filled($row['serial_no'] ?? null) ? $row['serial_no'] : null,
                    'raw_description' => $row['description'] ?? null,
                ]);

                continue;
            }

            $this->writeItem($pm, 'marks', 'F. Body diagram', $description ?: $type, $reviewer, [
                'category' => 'mark',
                'type' => self::MARK_TYPE_MAP[$type] ?? null,
                'region' => $row['region'] ?? null,
                'laterality' => $side,
                'raw_type' => $type,
                'raw_description' => $row['description'] ?? null,
            ]);
        }
    }

    /**
     * Section G: five fixed clothing slots.
     *
     * @param  list<array{slot: string, garment: ?string, colour: ?string}>  $rows
     */
    protected function writeClothing(PmCase $pm, array $rows, string $reviewer): void
    {
        $slotMap = ['Upper body' => 'upper', 'Lower body' => 'lower', 'Footwear' => 'footwear'];

        foreach ($rows as $row) {
            if (blank($row['garment'] ?? null) && blank($row['colour'] ?? null)) {
                continue;
            }

            $garment = filled($row['garment'] ?? null) ? mb_strtolower(trim($row['garment'])) : null;
            $colour = filled($row['colour'] ?? null) ? mb_strtolower(trim($row['colour'])) : null;

            $this->writeItem($pm, 'clothing', 'G. Clothing worn', trim(($row['slot'] ?? '').': '.$garment.' '.$colour), $reviewer, [
                'category' => 'clothing',
                'slot' => $slotMap[$row['slot'] ?? ''] ?? null,
                'garment' => $garment,
                'colour' => $colour,
                'raw_slot' => $row['slot'] ?? null,
            ]);
        }
    }

    /**
     * Section H: up to three jewellery/personal-effect rows.
     *
     * @param  list<array{kind: string, item: ?string, material_description: ?string}>  $rows
     */
    protected function writeJewelleryEffects(PmCase $pm, array $rows, string $reviewer): void
    {
        foreach ($rows as $row) {
            if (blank($row['item'] ?? null)) {
                continue;
            }

            $itemKey = mb_strtolower(str_replace(' ', '_', trim($row['item'])));

            if (($row['kind'] ?? null) === 'jewellery') {
                $this->writeItem($pm, 'jewellery', 'H. Jewellery & personal effects', $row['item'], $reviewer, [
                    'category' => 'jewellery',
                    'item' => $itemKey,
                    'metal' => null,
                    'site' => null,
                    'laterality' => null,
                    'raw_description' => $row['material_description'] ?? null,
                ]);
            } else {
                $this->writeItem($pm, 'belongings', 'H. Jewellery & personal effects', $row['item'], $reviewer, [
                    'category' => 'belonging',
                    'item' => $itemKey,
                    'raw_description' => $row['material_description'] ?? null,
                ]);
            }
        }
    }

    /**
     * Section I: identity documents found on or with the body.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeIdDocuments(PmCase $pm, array $rows, string $reviewer): void
    {
        foreach ($rows as $row) {
            if (blank($row['document_type'] ?? null) && blank($row['id_last4'] ?? null)) {
                continue;
            }

            $this->writeItem($pm, 'id_document', 'I. Identity documents', trim(
                ($row['document_type'] ?? '').' ending '.($row['id_last4'] ?? '?')
            ), $reviewer, [
                'category' => 'id_document',
                'kind' => filled($row['document_type'] ?? null) ? mb_strtolower(trim($row['document_type'])) : null,
                'name' => $row['name_on_document'] ?? null,
                'id_last4' => $row['id_last4'] ?? null,
                'on_body' => true,
                'note' => $row['note'] ?? null,
            ]);
        }
    }

    protected function writeNotes(PmCase $pm, ?string $notes, string $reviewer): void
    {
        if (blank($notes)) {
            return;
        }

        Observation::create([
            'obs_id' => $this->nextObsId($pm->pm_id),
            'record_type' => 'PM',
            'record_id' => $pm->pm_id,
            'category' => 'notes',
            'form_field' => 'K. Additional notes / observations',
            'raw_text' => $notes,
            'lang' => 'en',
            'source_type' => $pm->source_type,
            'recorded_by' => $reviewer,
            'recorded_at' => now(),
            'synthetic' => false,
        ]);
    }

    /**
     * Section J: the photo evidence log. Section F/live-scan photos land here
     * too once they carry an upload_ref.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $stagedFiles
     */
    protected function writePhotoLog(PmCase $pm, array $rows, string $reviewer, array $stagedFiles): void
    {
        foreach ($rows as $row) {
            $modality = self::PHOTO_MODALITY_MAP[$row['modality'] ?? ''] ?? 'other';
            $restricted = $modality === PhotoEvidence::MODALITY_FACE;

            $filePath = null;

            if (filled($row['upload_ref'] ?? null) && isset($stagedFiles[$row['upload_ref']]) && ! $restricted) {
                $filePath = $this->promoteUpload($pm, $stagedFiles[$row['upload_ref']]);
            }

            PhotoEvidence::create([
                'photo_id' => $this->nextPhotoId($pm->pm_id),
                'record_type' => 'PM',
                'record_id' => $pm->pm_id,
                'modality' => $modality,
                'view' => $row['view'] ?? null,
                'captured_at' => now(),
                'source_type' => $pm->source_type,
                'quality_flags' => implode(',', $row['quality_flags'] ?? []),
                'file_path' => $filePath,
                'use_policy' => $restricted
                    ? PhotoEvidence::POLICY_RESTRICTED
                    : 'extract_items_then_human_confirm',
                'sha256' => $filePath ? hash_file('sha256', storage_path('app/private/'.$filePath)) : null,
                'synthetic' => false,
            ]);
        }
    }

    /**
     * Moves a file staged by UploadController into its permanent home under
     * this body's record, and returns the path recorded on photo_evidence
     * (relative to the `local` disk's private root, matching how file_path
     * is already recorded relative to the dataset root for seeded photos).
     */
    protected function promoteUpload(PmCase $pm, string $stagedAbsolutePath): ?string
    {
        if (! is_file($stagedAbsolutePath)) {
            return null;
        }

        $extension = pathinfo($stagedAbsolutePath, PATHINFO_EXTENSION) ?: 'bin';
        $relative = "incidents/{$pm->incident_id}/bodies/{$pm->pm_id}/photos/".uniqid('photo_').'.'.$extension;
        $destination = storage_path('app/private/'.$relative);

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        rename($stagedAbsolutePath, $destination);

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function writeItem(PmCase $pm, string $boxCategory, string $formField, string $rawText, string $reviewer, array $item): void
    {
        $obsId = $this->nextObsId($pm->pm_id);

        Observation::create([
            'obs_id' => $obsId,
            'record_type' => 'PM',
            'record_id' => $pm->pm_id,
            'category' => $boxCategory,
            'form_field' => $formField,
            'raw_text' => $rawText,
            'lang' => 'en',
            'source_type' => $pm->source_type,
            'recorded_by' => $reviewer,
            'recorded_at' => now(),
            'synthetic' => false,
        ]);

        ObservationNorm::create([
            'obs_id' => $obsId,
            'item_index' => 0,
            'norm_json' => $item,
            'extractor_version' => 'manual-entry-v1',
            'confidence' => 1.0,
            'reviewed_by' => $reviewer,
            'reviewed_at' => now(),
        ]);
    }

    protected function nextPmId(string $incidentId): string
    {
        $count = PmCase::where('incident_id', $incidentId)->count();

        do {
            $count++;
            $candidate = sprintf('PM-%s-%03d', $incidentId, $count);
        } while (PmCase::whereKey($candidate)->exists());

        return $candidate;
    }

    protected function nextObsId(string $pmId): string
    {
        $this->obsSeq++;

        return sprintf('%s-O%02d', $pmId, $this->obsSeq);
    }

    protected function nextPhotoId(string $pmId): string
    {
        $this->photoSeq++;

        return sprintf('PH-%s-%02d', $pmId, $this->photoSeq);
    }
}
