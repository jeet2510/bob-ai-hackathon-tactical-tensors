<?php

namespace App\Services\Matching;

use App\Models\AmFile;
use App\Models\PmCase;

/**
 * Which primary-identifier test to run first to settle a pairing.
 *
 * This is the system's actual output. Nothing upstream identifies anybody:
 * INTERPOL requires a match on fingerprints, dental records or DNA, and the
 * value of ranking candidates is that it tells a team which test to spend
 * their next twelve hours on. Saying "no route available" is equally useful —
 * it tells a coordinator that a body needs a sample collected before any
 * amount of comparison will help.
 */
class ConfirmationRoute
{
    /**
     * Ordered by how quickly each can return an answer in the field.
     * Fingerprints are hours, dental comparison a day or so, DNA far longer —
     * so an equally conclusive but slower route is offered second.
     *
     * @return array{
     *     route: string|null,
     *     label: string,
     *     available: list<array{route: string, label: string, detail: string}>,
     *     blockers: list<string>
     * }
     */
    public static function for(PmCase $pm, AmFile $am): array
    {
        $available = [];
        $blockers = [];

        if ($pm->print_status === 'usable' && $am->prints_on_file) {
            $available[] = [
                'route' => 'fingerprints',
                'label' => 'Fingerprint comparison',
                'detail' => 'Prints are usable post-mortem and the ante-mortem file has prints on record.',
            ];
        } elseif ($am->prints_on_file) {
            $blockers[] = "Prints on file ante-mortem, but post-mortem prints are {$pm->print_status}.";
        }

        if ($pm->dental_status === 'chart_completed' && $am->dental_records_available) {
            $available[] = [
                'route' => 'dental',
                'label' => 'Dental comparison',
                'detail' => 'A post-mortem chart is complete and the family has dental records.',
            ];
        } elseif ($am->dental_records_available) {
            $blockers[] = "Dental records available ante-mortem, but the post-mortem chart is {$pm->dental_status}.";
        }

        if ($pm->dna_status === 'sample_taken' && filled($am->dna_reference_type)) {
            $reference = str_replace('_', ' ', (string) $am->dna_reference_type);

            $available[] = [
                'route' => 'dna',
                'label' => 'DNA comparison',
                'detail' => "A post-mortem sample is held and a {$reference} reference is available.",
            ];
        } elseif (filled($am->dna_reference_type) && $pm->dna_status === 'degraded') {
            $blockers[] = 'A DNA reference exists, but the post-mortem sample is degraded.';
        }

        if ($available === []) {
            return [
                'route' => null,
                'label' => 'No primary-identifier route available',
                'available' => [],
                'blockers' => $blockers !== [] ? $blockers : [
                    'Neither record holds prints, dental records or a DNA reference that can be compared.',
                ],
            ];
        }

        return [
            'route' => $available[0]['route'],
            'label' => $available[0]['label'],
            'available' => $available,
            'blockers' => $blockers,
        ];
    }
}
