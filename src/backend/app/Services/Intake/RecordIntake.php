<?php

namespace App\Services\Intake;

use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;

/**
 * Shared write-path logic for turning a submitted intake form into
 * observation/observation_norm/photo_evidence rows — used by both
 * PmCaseIntake (post-mortem, examiner-filled) and AmFileIntake
 * (ante-mortem, family-filled).
 *
 * The rule this exists to enforce: EvidenceScorer reads appearance,
 * marks, tattoos, implants, clothing, jewellery, belongings and id
 * documents exclusively from observation/observation_norm rows, keyed by
 * record_type/record_id — never from a PmCase or AmFile column, and it
 * does not care which side of the pairing a row belongs to (confirmed:
 * Lexicon.php, RuleExtractor and EvidenceScorer are all record_type-
 * agnostic). So the exact same item-shapes apply on both sides; only the
 * scalar header fields differ, and those live in each subclass's own
 * store() method.
 *
 * Items are written with `reviewed_by` set, so a record entered through
 * either intake form is scorable by any match run immediately, regardless
 * of which --extractor the run was started with.
 */
abstract class RecordIntake
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

    protected int $obsSeq = 0;

    protected int $photoSeq = 0;

    /**
     * Sections B/C-equivalent appearance box: build, skin tone, hair, eyes,
     * facial hair — one Observation box each, one ObservationNorm item each.
     */
    protected function writeAppearance(string $recordType, string $recordId, array $data, string $reviewer, string $sourceType): void
    {
        if (filled($data['build'] ?? null)) {
            $value = self::BUILD_MAP[$data['build']] ?? null;

            $this->writeItem($recordType, $recordId, 'build', 'Biological profile', "Build: {$data['build']}", $reviewer, $sourceType, [
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

            $this->writeItem($recordType, $recordId, 'appearance', 'Physical appearance', "Skin tone: {$label}", $reviewer, $sourceType, [
                'category' => 'skin_tone',
                'value' => $value,
                'raw_label' => $value === null ? $label : null,
            ]);
        }

        if (filled($data['hair_colour'] ?? null) || filled($data['hair_length'] ?? null)) {
            $colour = self::HAIR_COLOUR_MAP[$data['hair_colour'] ?? ''] ?? null;
            $length = self::HAIR_LENGTH_MAP[$data['hair_length'] ?? ''] ?? null;

            $this->writeItem($recordType, $recordId, 'appearance', 'Physical appearance',
                'Hair: '.trim(($data['hair_colour'] ?? '').' '.($data['hair_length'] ?? '')), $reviewer, $sourceType, [
                    'category' => 'hair',
                    'colour' => $colour,
                    'length' => $length,
                    'raw_colour' => $colour === null ? ($data['hair_colour'] ?? null) : null,
                    'raw_length' => $length === null ? ($data['hair_length'] ?? null) : null,
                ]);
        }

        if (filled($data['eye_colour'] ?? null)) {
            $value = self::EYE_COLOUR_MAP[$data['eye_colour']] ?? null;

            $this->writeItem($recordType, $recordId, 'appearance', 'Physical appearance', "Eyes: {$data['eye_colour']}", $reviewer, $sourceType, [
                'category' => 'eyes',
                'colour' => $value,
                'raw_colour' => $value === null ? $data['eye_colour'] : null,
            ]);
        }

        if (filled($data['facial_hair'] ?? null)) {
            $value = self::FACIAL_HAIR_MAP[$data['facial_hair']] ?? null;

            $this->writeItem($recordType, $recordId, 'appearance', 'Physical appearance', "Facial hair: {$data['facial_hair']}", $reviewer, $sourceType, [
                'category' => 'facial_hair',
                'value' => $value,
                'raw_label' => $value === null ? $data['facial_hair'] : null,
            ]);
        }
    }

    /**
     * Body-diagram distinguishing features: one form box per row.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeDistinguishingFeatures(string $recordType, string $recordId, array $rows, string $reviewer, string $sourceType): void
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
                $this->writeItem($recordType, $recordId, 'tattoo', 'Body diagram', $description ?: 'Tattoo', $reviewer, $sourceType, [
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
                $this->writeItem($recordType, $recordId, 'implant', 'Body diagram', $description ?: 'Implant', $reviewer, $sourceType, [
                    'category' => 'implant',
                    'type' => null,
                    'region' => $row['region'] ?? null,
                    'laterality' => $side,
                    'serial' => filled($row['serial_no'] ?? null) ? $row['serial_no'] : null,
                    'raw_description' => $row['description'] ?? null,
                ]);

                continue;
            }

            $this->writeItem($recordType, $recordId, 'marks', 'Body diagram', $description ?: $type, $reviewer, $sourceType, [
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
     * Five fixed clothing slots.
     *
     * @param  list<array{slot: string, garment: ?string, colour: ?string}>  $rows
     */
    protected function writeClothing(string $recordType, string $recordId, array $rows, string $reviewer, string $sourceType): void
    {
        $slotMap = ['Upper body' => 'upper', 'Lower body' => 'lower', 'Footwear' => 'footwear'];

        foreach ($rows as $row) {
            if (blank($row['garment'] ?? null) && blank($row['colour'] ?? null)) {
                continue;
            }

            $garment = filled($row['garment'] ?? null) ? mb_strtolower(trim($row['garment'])) : null;
            $colour = filled($row['colour'] ?? null) ? mb_strtolower(trim($row['colour'])) : null;

            $this->writeItem($recordType, $recordId, 'clothing', 'Clothing', trim(($row['slot'] ?? '').': '.$garment.' '.$colour), $reviewer, $sourceType, [
                'category' => 'clothing',
                'slot' => $slotMap[$row['slot'] ?? ''] ?? null,
                'garment' => $garment,
                'colour' => $colour,
                'raw_slot' => $row['slot'] ?? null,
            ]);
        }
    }

    /**
     * Jewellery/personal-effect rows.
     *
     * @param  list<array{kind: string, item: ?string, material_description: ?string}>  $rows
     */
    protected function writeJewelleryEffects(string $recordType, string $recordId, array $rows, string $reviewer, string $sourceType): void
    {
        foreach ($rows as $row) {
            if (blank($row['item'] ?? null)) {
                continue;
            }

            $itemKey = mb_strtolower(str_replace(' ', '_', trim($row['item'])));

            if (($row['kind'] ?? null) === 'jewellery') {
                $this->writeItem($recordType, $recordId, 'jewellery', 'Jewellery & personal effects', $row['item'], $reviewer, $sourceType, [
                    'category' => 'jewellery',
                    'item' => $itemKey,
                    'metal' => null,
                    'site' => null,
                    'laterality' => null,
                    'raw_description' => $row['material_description'] ?? null,
                ]);
            } else {
                $this->writeItem($recordType, $recordId, 'belongings', 'Jewellery & personal effects', $row['item'], $reviewer, $sourceType, [
                    'category' => 'belonging',
                    'item' => $itemKey,
                    'raw_description' => $row['material_description'] ?? null,
                ]);
            }
        }
    }

    /**
     * Identity documents.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeIdDocuments(string $recordType, string $recordId, array $rows, string $reviewer, string $sourceType): void
    {
        foreach ($rows as $row) {
            if (blank($row['document_type'] ?? null) && blank($row['id_last4'] ?? null)) {
                continue;
            }

            $this->writeItem($recordType, $recordId, 'id_document', 'Identity documents', trim(
                ($row['document_type'] ?? '').' ending '.($row['id_last4'] ?? '?')
            ), $reviewer, $sourceType, [
                'category' => 'id_document',
                'kind' => filled($row['document_type'] ?? null) ? mb_strtolower(trim($row['document_type'])) : null,
                'name' => $row['name_on_document'] ?? null,
                'id_last4' => $row['id_last4'] ?? null,
                'on_body' => $recordType === 'PM' ? true : null,
                'note' => $row['note'] ?? null,
            ]);
        }
    }

    protected function writeNotes(string $recordType, string $recordId, ?string $notes, string $reviewer, string $sourceType, string $formField): void
    {
        if (blank($notes)) {
            return;
        }

        Observation::create([
            'obs_id' => $this->nextObsId($recordId),
            'record_type' => $recordType,
            'record_id' => $recordId,
            'category' => 'notes',
            'form_field' => $formField,
            'raw_text' => $notes,
            'lang' => 'en',
            'source_type' => $sourceType,
            'recorded_by' => $reviewer,
            'recorded_at' => now(),
            'synthetic' => false,
        ]);
    }

    /**
     * Moves a file staged by an upload endpoint into its permanent home
     * under this record, and returns the path recorded on photo_evidence
     * (relative to the `local` disk's private root, matching how file_path
     * is already recorded relative to the dataset root for seeded photos).
     */
    protected function promoteUpload(string $relativeDir, string $stagedAbsolutePath): ?string
    {
        if (! is_file($stagedAbsolutePath)) {
            return null;
        }

        $extension = pathinfo($stagedAbsolutePath, PATHINFO_EXTENSION) ?: 'bin';
        $relative = rtrim($relativeDir, '/').'/'.uniqid('photo_').'.'.$extension;
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
    protected function writeItem(string $recordType, string $recordId, string $boxCategory, string $formField, string $rawText, string $reviewer, string $sourceType, array $item): void
    {
        $obsId = $this->nextObsId($recordId);

        Observation::create([
            'obs_id' => $obsId,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'category' => $boxCategory,
            'form_field' => $formField,
            'raw_text' => $rawText,
            'lang' => 'en',
            'source_type' => $sourceType,
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

    protected function nextObsId(string $recordId): string
    {
        $this->obsSeq++;

        return sprintf('%s-O%02d', $recordId, $this->obsSeq);
    }

    protected function nextPhotoId(string $recordId): string
    {
        $this->photoSeq++;

        return sprintf('PH-%s-%02d', $recordId, $this->photoSeq);
    }

    /**
     * @param  array<string, mixed>  $attributes  everything except photo_id/record_type/record_id
     */
    protected function createPhoto(string $recordType, string $recordId, array $attributes): PhotoEvidence
    {
        return PhotoEvidence::create($attributes + [
            'photo_id' => $this->nextPhotoId($recordId),
            'record_type' => $recordType,
            'record_id' => $recordId,
            'synthetic' => false,
        ]);
    }
}
