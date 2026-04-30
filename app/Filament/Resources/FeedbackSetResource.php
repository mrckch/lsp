<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\FeedbackSetResource\Pages;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
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

class FeedbackSetResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'feedback_sets.view'; }
    protected static function createPermission(): ?string { return 'feedback_sets.manage'; }
    protected static function editPermission(): ?string { return 'feedback_sets.manage'; }
    protected static function deletePermission(): ?string { return 'feedback_sets.manage'; }

    protected static ?string $model = FeedbackSet::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Test-Konfiguration';
    protected static ?int $navigationSort = 30;
    protected static ?string $modelLabel = 'Rückmeldeset';
    protected static ?string $pluralModelLabel = 'Rückmeldesets';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(150)->columnSpan(2),
                Select::make('status')->required()->default('aktiv')
                    ->options(['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert']),
                Toggle::make('is_default')->label('Standard'),
            ]),

            Section::make('Bereiche')
                ->description('Pro Punkte- oder LQ-Bereich ein HTML-Snippet, das in der Rückmeldung erscheint.')
                ->schema([
                    Repeater::make('ranges')->relationship()
                        ->label('Bereiche')->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->cloneable()
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['name'] ?? 'Bereich')
                        ->schema([
                            TextInput::make('name')->required()->maxLength(100),
                            Select::make('match_type')->required()->default('lq')
                                ->options(['lq' => 'LQ-Bereich', 'punkte' => 'Punkte-Bereich']),
                            TextInput::make('min_value')->label('Min')->numeric()->required(),
                            TextInput::make('max_value')->label('Max')->numeric()->required(),
                            Toggle::make('is_active')->label('Aktiv')->default(true)->inline(false),
                            Textarea::make('template_html')->label('HTML-Template')
                                ->rows(6)->columnSpanFull()
                                ->placeholder('<p>Sehr gut, {{vorname}}! Du hast {{punkte}} Punkte ...</p>'),
                        ])->columns(5),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('ranges_count')->label('Bereiche')->counts('ranges'),
                BadgeColumn::make('status')
                    ->colors(['warning' => 'entwurf', 'success' => 'aktiv', 'gray' => 'archiviert']),
                IconColumn::make('is_default')->label('Standard')->boolean(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedbackSets::route('/'),
            'create' => Pages\CreateFeedbackSet::route('/create'),
            'edit' => Pages\EditFeedbackSet::route('/{record}/edit'),
        ];
    }
}
