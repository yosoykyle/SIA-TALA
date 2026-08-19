<?php

namespace Tests\Unit;

use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use PHPUnit\Framework\TestCase;

final class TAL96D5E1B1FixtureTruthTest extends TestCase
{
    public function test_current_catalog_uses_the_approved_23_third_year_rows(): void
    {
        $catalog = new SchedulingAcceptanceScenarioCatalog;

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

    public function test_current_manifests_exclude_externally_arranged_work_without_rewriting_historical_v1(): void
    {
        $catalog = new SchedulingAcceptanceScenarioCatalog;
        $middle = $catalog->manifest(SchedulingAcceptanceScenarioCatalog::Middle);
        $max = $catalog->manifest(SchedulingAcceptanceScenarioCatalog::Max);

        $this->assertSame(77, $middle['counts']['offerings']);
        $this->assertSame(77, $middle['counts']['sections']);
        $this->assertSame(74, $middle['counts']['scheduling_demands']);
        $this->assertSame(241.0, $middle['faculty_evidence']['total_teaching_units']);

        $this->assertSame(77, $max['counts']['offerings']);
        $this->assertSame(172, $max['counts']['sections']);
        $this->assertSame(166, $max['counts']['scheduling_demands']);
        $this->assertSame(534.0, $max['faculty_evidence']['total_teaching_units']);

        $this->assertSame(
            'TAL96D5D_SYNTHETIC_V1',
            $middle['curriculum_evidence']['historical_fixture']['version'],
        );
        $this->assertSame(
            80,
            $middle['curriculum_evidence']['historical_fixture']['scheduling_demands'],
        );
        $this->assertSame(
            'HISTORICAL_ONLY',
            $middle['curriculum_evidence']['historical_fixture']['status'],
        );
    }

    public function test_manifest_records_source_subtotal_discrepancies_without_inventing_a_course(): void
    {
        $catalog = new SchedulingAcceptanceScenarioCatalog;
        $evidence = $catalog->manifest(SchedulingAcceptanceScenarioCatalog::Middle)['curriculum_evidence'];

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
