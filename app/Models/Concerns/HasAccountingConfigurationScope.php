<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait HasAccountingConfigurationScope
{
    /**
     * @var array<string, string>
     */
    public const YearLevelOptions = [
        '1st Year' => '1st Year',
        '2nd Year' => '2nd Year',
        '3rd Year' => '3rd Year',
        '4th Year' => '4th Year',
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
    public static function yearLevelOptions(): array
    {
        return self::YearLevelOptions;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeScopeData(array $data): array
    {
        $data['program_id'] = self::normalizeNullableInteger($data['program_id'] ?? null);
        $data['year_level'] = self::normalizeNullableString($data['year_level'] ?? null);

        return $data;
    }

    /**
     * @param  array{program_id:int|null, year_level:string|null}  $scope
     */
    public static function activeScopeConflictExists(array $scope, ?int $ignoreId = null): bool
    {
        $query = self::query()
            ->where('is_active', true);

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
            'program_id' => $this->program_id,
            'year_level' => $this->year_level,
        ]);

        $this->program_id = $scope['program_id'];
        $this->year_level = $scope['year_level'];
    }

    /**
     * @return array{program_id:int|null, year_level:string|null}
     */
    protected function accountingScopeAttributes(): array
    {
        return [
            'program_id' => $this->program_id,
            'year_level' => $this->year_level,
        ];
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
