<?php

namespace App\Support;

/**
 * Hardcoded About credits for turnover. Not loaded from config, .env, or Settings.
 */
final class SystemCredits
{
    public const SYSTEM_NAME = 'OWWA IV-A CALABARZON Inventory System';

    public const OFFICE = 'Overseas Workers Welfare Administration, Regional Welfare Office IV-A';

    public const PROJECT_TYPE = 'Capstone Project';

    public const INSTITUTION = 'City College of Calamba';

    public const PROGRAM = 'Bachelor of Science in Information Technology';

    public const YEAR = '2026';

    public const ADVISER = 'Prof. Jester Emann Peñaranda';

    /**
     * @var list<string>
     */
    public const DEVELOPERS = [
        'Dan Jediael P. Caranay',
        'Jonathan M. Ilao',
        'Jennilyn B. Pataueg',
        'Carl Gabriel E. Pesigan',
    ];

    /**
     * @return array{
     *     system_name: string,
     *     office: string,
     *     project_type: string,
     *     institution: string,
     *     program: string,
     *     year: string,
     *     adviser: string,
     *     developers: list<string>
     * }
     */
    public static function about(): array
    {
        return [
            'system_name' => self::SYSTEM_NAME,
            'office' => self::OFFICE,
            'project_type' => self::PROJECT_TYPE,
            'institution' => self::INSTITUTION,
            'program' => self::PROGRAM,
            'year' => self::YEAR,
            'adviser' => self::ADVISER,
            'developers' => self::DEVELOPERS,
        ];
    }
}
