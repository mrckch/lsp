<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Filament\Resources\PrintTemplateResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrintTemplateResource extends Resource
{
    protected static ?string $model = PrintTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Drucksachen';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Druckvorlage';
    protected static ?string $pluralModelLabel = 'Druckvorlagen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(2)->schema([
                TextInput::make('key')->required()->maxLength(100)
                    ->disabledOn('edit')->helperText('Technischer Schlüssel, nicht änderbar.'),
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('type')->maxLength(50)
                    ->placeholder('z. B. student_feedback, login_codes ...'),
                Toggle::make('is_system')->label('System-Vorlage')->disabled(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),

            Section::make('Aktuelle Version')
                ->description('Bearbeiten erzeugt eine neue Version. Alte Versionen bleiben für Reproduzierbarkeit erhalten.')
                ->schema([
                    Textarea::make('html_content')
                        ->label('HTML')
                        ->rows(15)
                        ->afterStateHydrated(function ($component, $record) {
                            $component->state($record?->currentVersion?->html_content ?? '');
                        })
                        ->dehydrated(),
                    Textarea::make('css_content')
                        ->label('CSS')
                        ->rows(8)
                        ->afterStateHydrated(function ($component, $record) {
                            $component->state($record?->currentVersion?->css_content ?? '');
                        })
                        ->dehydrated(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Schlüssel')->searchable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('versions_count')->label('Versionen')->counts('versions'),
                TextColumn::make('currentVersion.version_number')->label('Aktuell'),
                IconColumn::make('is_system')->label('System')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                Action::make('versionHistory')
                    ->label('Versionen')->icon('heroicon-o-clock')
                    ->modalContent(fn (PrintTemplate $record) => view('filament.print-template-versions', [
                        'template' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen'),
                DeleteAction::make()->visible(fn (PrintTemplate $r) => ! $r->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrintTemplates::route('/'),
            'create' => Pages\CreatePrintTemplate::route('/create'),
            'edit' => Pages\EditPrintTemplate::route('/{record}/edit'),
        ];
    }
}
