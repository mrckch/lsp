<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\TemplateCatalog;
use App\Filament\Resources\PrintTemplateResource\Pages;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

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
                    ->disabledOn('edit')
                    ->helperText('Technischer Schlüssel, nicht änderbar.'),
                TextInput::make('name')->required()->maxLength(150),
                Select::make('type')->label('Typ')
                    ->options(TemplateCatalog::options())
                    ->required()->live()
                    ->helperText('Bestimmt, welche Variablen zur Verfügung stehen.'),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),

            Section::make('Verfügbare Variablen')
                ->description('Klicke ein Schlagwort, um es zu kopieren – einfach im HTML-Editor einfügen.')
                ->collapsible()
                ->schema([
                    Placeholder::make('variables_help')
                        ->hiddenLabel()
                        ->content(function (Get $get) {
                            $type = $get('type');
                            if (! $type || ! ($meta = TemplateCatalog::for($type))) {
                                return new HtmlString('<em style="color:#6b7280;">Erst einen Typ wählen.</em>');
                            }

                            $html = '<div style="display:flex; flex-wrap:wrap; gap:0.4rem;">';
                            foreach ($meta['variables'] as $name => $label) {
                                $tag = '{{'.$name.'}}';
                                $esc = e($tag);
                                $title = e($label);
                                $html .= sprintf(
                                    '<button type="button" class="lsp-var-chip" '.
                                    'data-var="%s" title="%s" '.
                                    'onclick="navigator.clipboard.writeText(\'%s\').then(() => this.innerText = \'kopiert ✓\')" '.
                                    'style="cursor:pointer; background:#eff6ff; color:#1e40af; '.
                                    'padding:0.2rem 0.6rem; border-radius:9999px; border:1px solid #bfdbfe; font-family:ui-monospace,monospace; font-size:0.85rem;">'.
                                    '%s</button>',
                                    $esc, $title, $esc, $esc,
                                );
                            }
                            $html .= '</div>';

                            return new HtmlString($html);
                        }),
                ]),

            Section::make('HTML-Inhalt')
                ->description('Reine Inhalts-HTML; das umgebende <html><body> wird beim Render ergänzt.')
                ->schema([
                    RichEditor::make('html_content')
                        ->label('Vorlage')
                        ->afterStateHydrated(function ($component, $record) {
                            $component->state($record?->currentVersion?->html_content ?? '');
                        })
                        ->dehydrated()
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike',
                            'h2', 'h3', 'bulletList', 'orderedList',
                            'link', 'codeBlock', 'blockquote',
                            'undo', 'redo',
                        ])
                        ->extraInputAttributes(['style' => 'min-height: 350px;']),
                ]),

            Section::make('CSS')
                ->description('Eigene Styles für den PDF-Output (Font, Seitenränder, Tabellen ...)')
                ->collapsed(fn (Get $get) => empty($get('css_content')))
                ->schema([
                    Textarea::make('css_content')
                        ->label('Stylesheet')
                        ->afterStateHydrated(function ($component, $record) {
                            $component->state($record?->currentVersion?->css_content ?? '');
                        })
                        ->dehydrated()
                        ->rows(10)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.85rem;'])
                        ->placeholder("@page { size: A4; margin: 2cm; }\nbody { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; }"),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Schlüssel')->searchable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state) => TemplateCatalog::for((string) $state)['label'] ?? $state),
                TextColumn::make('versions_count')->label('Versionen')->counts('versions'),
                TextColumn::make('currentVersion.version_number')->label('Aktuell'),
                IconColumn::make('is_system')->label('System')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                Action::make('preview')
                    ->label('PDF-Vorschau')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->action(function (PrintTemplate $record) {
                        $version = $record->currentVersion;
                        if (! $version) {
                            Notification::make()->danger()->title('Keine Version vorhanden')->send();

                            return null;
                        }
                        $sample = TemplateCatalog::for($record->type)['sample'] ?? [];
                        try {
                            $runner = new PrintJobRunner(app(GotenbergClient::class));
                            $html = $runner->renderTemplate($version->html_content, $sample);
                            $pdf = app(GotenbergClient::class)->htmlToPdf($html, $version->css_content);

                            $name = 'preview_'.$record->key.'_'.now()->format('His').'.pdf';
                            $path = 'lsp/previews/'.$name;
                            Storage::disk('local')->put($path, $pdf);

                            Notification::make()->success()
                                ->title('Vorschau erzeugt')
                                ->body("Datei: $path")->send();

                            return response()->streamDownload(
                                fn () => print ($pdf),
                                $name,
                                ['Content-Type' => 'application/pdf'],
                            );
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('Vorschau fehlgeschlagen')
                                ->body($e->getMessage())->send();

                            return null;
                        }
                    }),
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
