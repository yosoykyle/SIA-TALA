<?php

namespace App\Support;

use App\Models\CourseComponent;
use App\Models\RoomFeature;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class RoomEquipment
{
    public static function label(?string $key): string
    {
        return match (strtoupper((string) $key)) {
            'PROJECTOR' => 'Projector',
            'COMPUTER_UNITS' => 'Computers',
            'LAB_SINK' => 'Laboratory sink',
            'AIR_CONDITIONED' => 'Air conditioning',
            default => str(mb_strtolower((string) $key))->headline()->toString(),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(['PROJECTOR', 'COMPUTER_UNITS', 'LAB_SINK', 'AIR_CONDITIONED'])
            ->merge(RoomFeature::query()->distinct()->pluck('feature_key'))
            ->merge(CourseComponent::query()->whereNotNull('required_room_feature_keys')->pluck('required_room_feature_keys')->flatten())
            ->unique()->mapWithKeys(fn (string $key): array => [$key => self::label($key)])->sort()->all();
    }

    public static function select(string $name): Select
    {
        return Select::make($name)->options(self::options(...))->searchable()
            ->getOptionLabelUsing(fn (?string $value): ?string => filled($value) ? self::label($value) : null)
            ->getOptionLabelsUsing(fn (array $values): array => collect($values)->mapWithKeys(fn (string $value): array => [$value => self::label($value)])->all())
            ->rules(fn (Select $component): array => $component->isMultiple() ? ['array'] : ['string', 'max:255'])
            ->nestedRecursiveRules(['string', 'max:255'], fn (Select $component): bool => $component->isMultiple())
            ->createOptionForm([TextInput::make('key')->label('Other equipment identifier')->required()->maxLength(255)
                ->helperText('Use the same identifier for room equipment and course requirements. Existing identifiers stay unchanged.')])
            ->createOptionUsing(fn (array $data): string => strtoupper(trim($data['key'])))
            ->createOptionAction(fn ($action) => $action->modalHeading('Other equipment')->modalSubmitActionLabel('Use equipment'));
    }
}
