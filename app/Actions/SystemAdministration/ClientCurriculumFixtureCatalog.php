<?php

namespace App\Actions\SystemAdministration;

/**
 * Source-preserving first-semester rows used by the guarded TAL-96 fixture.
 *
 * Canonical codes remove spacing and normalize numeric padding for TALA's
 * course identity, while source_code retains the client document value.
 */
final class ClientCurriculumFixtureCatalog
{
    /**
     * @return array<string, array<string, list<array{
     *     source_code:string,
     *     code:string,
     *     title:string,
     *     units:float,
     *     prerequisite_source:string
     * }>>>
     */
    public function firstSemesterRows(): array
    {
        return [
            'DBM' => [
                'First Year' => [
                    $this->row('GE1', 'GE01', 'Understanding the Self', 3),
                    $this->row('GE2', 'GE02', 'Purposive Communication', 3),
                    $this->row('GE3', 'GE03', 'Mathematics in the Modern World', 3),
                    $this->row('BOOKEEPING NCIII', 'BOOKKEEPINGNCIII', 'Intermediate Accounting 1 / Bookkeeping NC III', 4),
                    $this->row('BME1', 'BME01', 'Human Resources Management', 3),
                    $this->row('BME2', 'BME02', 'Administrative and Office Management', 3),
                    $this->row('BME3', 'BME03', 'Basic Microeconomics', 3),
                    $this->row('NSTP1', 'NSTP01', 'Civic Welfare Training Service 1', 2),
                    $this->row('PE1', 'PE01', 'Physical Education 1 (Physical Fitness and Wellness)', 2),
                    $this->row('CCS NC II', 'CCSNCII', 'Contact Center Services NC II', 3),
                ],
                'Second Year' => [
                    $this->row('GE7', 'GE07', 'Ethics', 3),
                    $this->row('GE8', 'GE08', "Rizal's Life and Works", 3),
                    $this->row('AGRO NC II', 'AGRONCII', 'Agro Entrepreneurship NC II', 4),
                    $this->row('BME8', 'BME08', 'Operations Management and TQM', 3),
                    $this->row('BME7', 'BME07', 'International Business Management', 3),
                    $this->row('PRO FM 1', 'PROFM01', 'Financial Accounting and Reporting', 3),
                    $this->row('PRO FM 2', 'PROFM02', 'Financial Management', 3),
                    $this->row('PE3', 'PE03', 'Physical Education 2 (Individual / Dual Sports)', 2, 'PE2'),
                    $this->row('PRO FM 3', 'PROFM03', 'Financial Market', 3),
                ],
                'Third Year' => [
                    $this->row('RESHM', 'RESHM', 'Intermediate Accounting 3', 3, 'INTACCT2'),
                    $this->row('HPC16', 'HPC16', 'Inventory Management and Control / Agro NC IV', 4),
                    $this->row('HPC17', 'HPC17', 'Costing and Pricing / Agro NC IV', 4),
                    $this->row('HPC18/FBS NC III', 'HPC18FBSNCIII', 'Logistics Management / Agro NC IV', 4),
                    $this->row('HPC19', 'HPC19', 'Business Law (Obligations and Contracts)', 3),
                    $this->row('BME2', 'BME02', 'Good Governance and Social Responsibility', 3),
                    $this->row('GE009', 'GE09', 'Foreign Language / Japanese Language', 3),
                ],
            ],
            'DIT' => [
                'First Year' => [
                    $this->row('GE001', 'GE01', 'Understanding the Self', 3),
                    $this->row('GE002', 'GE02', 'Purposive Communication', 3),
                    $this->row('GE003', 'GE03', 'Mathematics in the Modern World', 3),
                    $this->row('CCS NC2', 'CCSNCII', 'Contact Center Services NC II', 4),
                    $this->row('NSTP1', 'NSTP01', 'Civic Welfare Training Service 1', 3),
                    $this->row('PE1', 'PE01', 'Physical Education (Physical Fitness and Wellness)', 2),
                    $this->row('CC101', 'CC101', 'Introduction to Computing', 3),
                    $this->row('CSS NCII', 'CSSNCII', 'Computer System Servicing NC II (ICCS)', 4, 'NCII'),
                    $this->row('MATH111', 'MATH111', 'Linear Algebra', 3, 'GE3'),
                ],
                'Second Year' => [
                    $this->row('GE007', 'GE07', 'Ethics', 3),
                    $this->row('GE008', 'GE08', "Rizal's Life and Works", 3),
                    $this->row('CC107', 'CC107', 'Application Development and Emerging Technologies / Programming NC III', 4, 'IPT101'),
                    $this->row('CC105', 'CC105', 'Information Management', 3),
                    $this->row('NET 101', 'NET101', 'Computer Networking 1 (SCN)', 3, 'CC101'),
                    $this->row('IM 101', 'IM101', 'Database Management System (Oracle)', 3),
                    $this->row('IPT 101', 'IPT101', 'Integrative Programming and Technologies 1 / Programming NC III', 4, 'NCIII'),
                    $this->row('PE3', 'PE03', 'Physical Education (Individual / Dual Sports)', 2, 'PE2'),
                    $this->row('CC104', 'CC104', 'Data Structures and Algorithms', 3, 'CC101'),
                ],
                'Third Year' => [
                    $this->row('GE009', 'GE09', 'Arts Appreciation', 3),
                    $this->row('SA 101', 'SA101', 'System Administration and Maintenance', 3),
                    $this->row('CAP 101', 'CAP101', 'Research 1', 3),
                    $this->row('MS 102', 'MS102', 'Quantitative Methods (Including Modeling and Simulation)', 3),
                    $this->row('WEB NC III-1', 'WEBNCIII1', 'Web Development NC III - 1 (HTML)', 4),
                    $this->row('SP 101', 'SP101', 'Social and Professional Issues', 3),
                    $this->row('IS101', 'IS101', 'Intelligent Systems', 3),
                    $this->row('Nihongo1', 'NIHONGO01', 'Foreign Language / Japanese Language 1', 3),
                    $this->row('ANIM NC II', 'ANIMNCII', 'Animation NC II', 4, 'NC II'),
                ],
            ],
            'DTHM' => [
                'First Year' => [
                    $this->row('GE1', 'GE01', 'Understanding the Self', 3),
                    $this->row('GE2', 'GE02', 'Purposive Communication', 3),
                    $this->row('GE3', 'GE03', 'Mathematics in the Modern World', 3),
                    $this->row('THC1', 'THC01', 'Risk Management as Applied to Safety, Security, and Sanitation', 3),
                    $this->row('HPC1', 'HPC01', 'Fundamentals in Lodging Operations', 4),
                    $this->row('HPC2', 'HPC02', 'Bread and Pastry Production NC II', 4),
                    $this->row('THC2', 'THC02', 'Macro Perspective of Tourism and Hospitality', 3),
                    $this->row('NSTP1', 'NSTP01', 'Civic Welfare Training Service 1', 3),
                    $this->row('PE1', 'PE01', 'Physical Education (Physical Fitness and Wellness)', 2),
                    $this->row('HPC3', 'HPC03', 'Food and Beverage Services', 4),
                ],
                'Second Year' => [
                    $this->row('GE007', 'GE07', 'Ethics', 3),
                    $this->row('GE 008', 'GE08', "Rizal's Life and Works", 3),
                    $this->row('HSKPG NC III', 'HSKPGNCIII', 'Housekeeping NC III', 3),
                    $this->row('HPC9', 'HPC09', 'Applied Business Tools and Technologies (PMS) with Lab', 4),
                    $this->row('HPC10', 'HPC10', 'Catering Management / Food and Beverage Services NC III', 4, 'FBS NC II'),
                    $this->row('HPC11', 'HPC11', 'Supply Chain Management in Hospitality', 3),
                    $this->row('HPC12', 'HPC12', 'Kitchen Essentials and Basic Food Preparation', 4),
                    $this->row('HPC13', 'HPC13', 'Specialized Food and Beverage Service Operations', 3, 'FBS NC II'),
                    $this->row('PE3', 'PE03', 'Physical Education 2 (Individual / Dual Sports)', 2, 'PE1'),
                ],
                'Third Year' => [
                    $this->row('RESHM', 'RESHM', 'Research in Hospitality', 3),
                    $this->row('HPC16', 'HPC16', 'Housekeeping Operations / Housekeeping NC III', 3, 'HSKP NC II'),
                    $this->row('HPC17', 'HPC17', 'Fundamentals of Food Science and Technology', 3),
                    $this->row('HPC18/FBS NC III', 'HPC18FBSNCIII', 'Food and Beverage Operations / FBS NC IV', 4),
                    $this->row('HPC19', 'HPC19', 'Foreign Language / Japanese Language', 3),
                    $this->row('BME2', 'BME02', 'Strategic Management in Tourism and Hospitality', 3),
                    $this->row('HPC 13', 'HPC13', 'Food and Beverage Cost Control / FBS NC II', 3, 'FBS NC II'),
                    $this->row('HPC15', 'HPC15', 'Introduction to Transport Services / Tourism Promotion Services', 3),
                    $this->row('HPC21', 'HPC21', 'World Geography and Destinations', 3),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     source_code:string,
     *     code:string,
     *     title:string,
     *     units:float,
     *     prerequisite_source:string
     * }
     */
    private function row(
        string $sourceCode,
        string $code,
        string $title,
        float $units,
        string $prerequisiteSource = 'None',
    ): array {
        return [
            'source_code' => $sourceCode,
            'code' => $code,
            'title' => $title,
            'units' => $units,
            'prerequisite_source' => $prerequisiteSource,
        ];
    }
}
