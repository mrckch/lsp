<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Permission\ScopeFilter;
use App\Domain\Student\Models\Student;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\StudentResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'students.view'; }
    protected static function createPermission(): ?string { return 'students.manage'; }
    protected static function editPermission(): ?string { return 'students.manage'; }
    protected static function deletePermission(): ?string { return 'students.delete'; }

    protected static ?string $model = Student::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Stammdaten';
    protected static ?int $navigationSort = 30;
    protected static ?string $modelLabel = 'Schüler/in';
    protected static ?string $pluralModelLabel = 'Schüler';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('student_code')->label('Schülercode')->disabled()->dehydrated(false),
            TextInput::make('external_student_id')->label('SchiLD/SVWS-ID'),
            TextInput::make('first_name_encrypted')->label('Vorname')->required(),
            TextInput::make('last_name_encrypted')->label('Nachname')->required(),
            Select::make('gender')->label('Geschlecht')
                ->options(['m' => 'männlich', 'w' => 'weiblich', 'd' => 'divers', 'unbekannt' => 'unbekannt'])
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => app(ScopeFilter::class)->applyToStudents($q, auth()->user()))
            ->columns([
                TextColumn::make('student_code')->label('Code')->searchable()->sortable(),
                TextColumn::make('first_name_encrypted')->label('Vorname'),
                TextColumn::make('last_name_encrypted')->label('Nachname'),
                BadgeColumn::make('gender')->label('Geschlecht')
                    ->colors(['warning' => 'unbekannt', 'primary' => 'm', 'success' => 'w', 'gray' => 'd']),
                BadgeColumn::make('status')->label('Status')
                    ->colors(['success' => 'aktiv', 'gray' => 'archiviert']),
                TextColumn::make('learningGroups.name')
                    ->label('Lerngruppen')->badge()->limit(50),
            ])
            ->filters([
                SelectFilter::make('status')->options(['aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'])->default('aktiv'),
                SelectFilter::make('gender')->options(['m' => 'männlich', 'w' => 'weiblich', 'd' => 'divers']),
            ])
            ->actions([
                EditAction::make(),
                Action::make('archive')
                    ->label('Archivieren')->icon('heroicon-o-archive-box')
                    ->visible(fn (Student $r) => $r->status === 'aktiv')
                    ->requiresConfirmation()
                    ->action(fn (Student $r) => $r->archive('Manuelle Archivierung')),
                Action::make('unarchive')
                    ->label('Reaktivieren')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Student $r) => $r->status === 'archiviert')
                    ->action(fn (Student $r) => $r->unarchive()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
