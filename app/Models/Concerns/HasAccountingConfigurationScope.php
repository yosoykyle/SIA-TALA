<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait HasAccountingConfigurationScope
{
    /**
     * @var array<string, string>
     */
    public const EducationLevelOptions = [
        'shs' => 'SHS',
        'college' => 'College',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    public const YearLevelOptions = [
        'shs' => [
            'Grade 11' => 'Grade 11',
            'Grade 12' => 'Grade 12',
        ],
        'college' => [
            '1st Year' => '1st Year',
            '2nd Year' => '2nd Year',
            '3rd Year' => '3rd Year',
            '4th Year' => '4th Year',
        ],
    ];

    protected static function bootHasAccountingConfigurationScope(): void
    {
        static::saving(function (Model $model): void {
            $model->normalizeAccountingScope();

            if (! $model->is_active) {
                return;
            }

            if (static::activeScopeConflictExists($model->accountingScopeAttributes(), $model->getKey())) {
                throw ValidationException::withMessages([
                    'is_active' => static::activeScopeConflictMessage(),
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function yearLevelOptionsFor(?string $educationLevel): array
    {
        $educationLevel = self::normalizeEducationLevel($educationLevel);

        if ($educationLevel !== null && isset(self::YearLevelOptions[$educationLevel])) {
            return self::YearLevelOptions[$educationLevel];
        }

        return array_merge(...array_values(self::YearLevelOptions));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeScopeData(array $data): array
    {
        $data['education_level'] = self::normalizeEducationLevel($data['education_level'] ?? null);
        $data['program_id'] = self::normalizeNullableInteger($data['program_id'] ?? null);
        $data['year_level'] = self::normalizeNullableString($data['year_level'] ?? null);

        return $data;
    }

    /**
     * @param  array{education_level:string|null, program_id:int|null, year_level:string|null}  $scope
     */
    public static function activeScopeConflictExists(array $scope, ?int $ignoreId = null): bool
    {
        $query = self::query()
            ->where('is_active', true)
            ->where('education_level', $scope['education_level']);

        $scope['program_id'] === null
            ? $query->whereNull('program_id')
            : $query->where('program_id', $scope['program_id']);

        $scope['year_level'] === null
            ? $query->whereNull('year_level')
            : $query->where('year_level', $scope['year_level']);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    abstract protected static function activeScopeConflictMessage(): string;

    protected function normalizeAccountingScope(): void
    {
        $scope = self::normalizeScopeData([
            'education_level' => $this->education_level,
            'program_id' => $this->program_id,
            'year_level' => $this->year_level,
        ]);

        $this->education_level = $scope['education_level'];
        $this->program_id = $scope['program_id'];
        $this->year_level = $scope['year_level'];
    }

    /**
     * @return array{education_level:string|null, program_id:int|null, year_level:string|null}
     */
    protected function accountingScopeAttributes(): array
    {
        return [
            'education_level' => $this->education_level,
            'program_id' => $this->program_id,
            'year_level' => $this->year_level,
        ];
    }

    private static function normalizeEducationLevel(mixed $value): ?string
    {
        $normalized = self::normalizeNullableString($value);

        return $normalized !== null ? mb_strtolower($normalized) : null;
    }

    private static function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
