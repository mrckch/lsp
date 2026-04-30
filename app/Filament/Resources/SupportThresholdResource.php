<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\SupportThreshold\Models\SupportThreshold;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\SupportThresholdResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupportThresholdResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'support_thresholds.manage'; }
    protected static function createPermission(): ?string { return 'support_thresholds.manage'; }
    protected static function editPermission(): ?string { return 'support_thresholds.manage'; }
    protected static function deletePermission(): ?string { return 'support_thresholds.manage'; }

    protected static ?string $model = SupportThreshold::class;
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Test-Konfiguration';
    protected static ?int $navigationSort = 60;
    protected static ?string $modelLabel = 'Förderbedarfs-Schwelle';
    protected static ?string $pluralModelLabel = 'Förderbedarfs-Schwellen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(150),
            Textarea::make('description')->label('Beschreibung')->rows(2),
            Select::make('metric')->required()
                ->options([
                    'lq_absolute' => 'LQ absolut',
                    'lq_delta' => 'LQ Δ über letzte n Erhebungen',
                    'lq_below_class_median' => 'LQ unter Klassenmedian',
                ]),
            Select::make('operator')->required()
                ->options([
                    'lt' => '< (kleiner als)',
                    'le' => '≤ (kleiner gleich)',
                    'gt' => '> (größer als)',
                    'ge' => '≥ (größer gleich)',
                    'eq' => '= (gleich)',
                ]),
            TextInput::make('value')->numeric()->required(),
            TextInput::make('window_count')->label('Anzahl Erhebungen (nur für Δ-Metrik)')->numeric()->minValue(2),
            Select::make('severity')->required()
                ->options([
                    'hinweis' => 'Hinweis',
                    'auffaellig' => 'Auffällig',
                    'foerderbedarf' => 'Förderbedarf',
                ]),
            Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                BadgeColumn::make('severity')
                    ->colors(['gray' => 'hinweis', 'warning' => 'auffaellig', 'danger' => 'foerderbedarf']),
                TextColumn::make('metric'),
                TextColumn::make('operator'),
                TextColumn::make('value'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportThresholds::route('/'),
            'create' => Pages\CreateSupportThreshold::route('/create'),
            'edit' => Pages\EditSupportThreshold::route('/{record}/edit'),
        ];
    }
}
