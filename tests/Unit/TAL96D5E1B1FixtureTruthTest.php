<?php

namespace Tests\Unit;

use App\Actions\SystemAdministration\CanonicalTalaSchedulingDataset;
use PHPUnit\Framework\TestCase;

final class TAL96D5E1B1FixtureTruthTest extends TestCase
{
    public function test_current_catalog_uses_the_approved_23_third_year_rows(): void
    {
        $catalog = new CanonicalTalaSchedulingDataset;

        $this->assertSame([
            'THC09',
            'THC10',
            'HMPRAC03',
            'HPC22',
            'COOPMM',
            'BME20',
            'BME21',
            'BME16',
        ], $catalog->courseCodes('DBM', 'Third Year'));
        $this->assertSame([
            'SIA101',
            'SF101',
            'PD101',
            'CAP102',
            'WEBNCIII2',
            'NIHONGO02',
            'HMPRAC02',
        ], $catalog->courseCodes('DIT', 'Third Year'));
        $this->assertSame([
            'THC09',
            'THC10',
            'HMPRAC03',
            'HPC22',
            'HPC23',
            'HPC24',
            'HPC20',
            'HMPRAC02',
        ], $catalog->courseCodes('DTHM', 'Third Year'));

        $this->assertSame(23, array_sum([
            count($catalog->courseCodes('DBM', 'Third Year')),
            count($catalog->courseCodes('DIT', 'Third Year')),
            count($catalog->courseCodes('DTHM', 'Third Year')),
        ]));
    }

    public function test_canonical_manifest_has_one_unambiguous_current_dataset(): void
    {
        $catalog = new CanonicalTalaSchedulingDataset;
        $manifest = $catalog->manifest();

        $this->assertSame('CANONICAL_TALA_SCHEDULING_DATASET', $manifest['dataset']);
        $this->assertSame(47, $manifest['counts']['students']);
        $this->assertSame(6, $manifest['counts']['cohorts']);
        $this->assertSame(9, $manifest['counts']['faculty']);
        $this->assertSame(54, $manifest['counts']['offerings']);
        $this->assertSame(54, $manifest['counts']['sections']);
        $this->assertSame(54, $manifest['counts']['scheduling_demands']);
        $this->assertSame(162.0, $manifest['faculty_evidence']['total_teaching_units']);
        $this->assertSame(19.0, $manifest['faculty_evidence']['maximum_constructed_load']);
        $this->assertCount(10, $catalog->roomDefinitions());
        $this->assertArrayNotHasKey('historical_fixture', $manifest['curriculum_evidence']);
    }

    public function test_manifest_records_source_subtotal_discrepancies_without_inventing_a_course(): void
    {
        $catalog = new CanonicalTalaSchedulingDataset;
        $evidence = $catalog->manifest()['curriculum_evidence'];

        $this->assertSame('COURSE_ROWS', $evidence['total_authority']);
        $this->assertSame('DO_NOT_INVENT', $evidence['missing_course_policy']);
        $this->assertSame([
            'DBM' => [
                'source_rows' => 8,
                'computed_units' => 25,
                'printed_subtotal' => 28,
                'disposition' => 'SOURCE_SUBTOTAL_DISCREPANCY',
            ],
            'DIT' => [
                'source_rows' => 7,
                'computed_units' => 25,
                'printed_subtotal' => 25,
                'disposition' => 'ALIGNED',
            ],
            'DTHM' => [
                'source_rows' => 8,
                'computed_units' => 29,
                'printed_subtotal' => 23,
                'disposition' => 'SOURCE_SUBTOTAL_DISCREPANCY',
            ],
        ], $evidence['third_year_second_semester']);
    }
}
