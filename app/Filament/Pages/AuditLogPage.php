<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\Models\AuditLog;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogPage extends Page implements HasTable
{
    use AuthorizedPage;
    use InteractsWithTable;

    protected static function requiredPermission(): ?string { return 'system.audit.view'; }

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 50;
    protected static ?string $title = 'Audit-Log';
    protected static ?string $navigationLabel = 'Audit-Log';
    protected static string $view = 'filament.pages.audit-log';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->latest('created_at'))
            ->columns([
                TextColumn::make('created_at')->label('Zeit')->dateTime('d.m.Y H:i:s')->sortable(),
                BadgeColumn::make('actor_type')->label('Akteur')
                    ->colors(['primary' => 'user', 'gray' => 'system', 'warning' => 'student', 'danger' => 'external']),
                TextColumn::make('actor.username')->label('Benutzer'),
                TextColumn::make('action')->label('Aktion')->searchable(),
                TextColumn::make('entity_type')->label('Entity'),
                TextColumn::make('entity_id')->label('ID'),
                IconColumn::make('includes_clearnames')->label('Klarnamen')->boolean(),
                TextColumn::make('ip_address')->label('IP'),
                IconColumn::make('archived_at')->label('Archiv')->boolean()
                    ->getStateUsing(fn (AuditLog $r) => $r->archived_at !== null)
                    ->tooltip(fn (AuditLog $r) => $r->archived_at?->format('d.m.Y H:i')),
            ])
            ->filters([
                SelectFilter::make('actor_type')->options([
                    'user' => 'User', 'system' => 'System', 'student' => 'Student', 'external' => 'Extern',
                ]),
                Filter::make('action')->form([
                    \Filament\Forms\Components\TextInput::make('action')->label('Aktion enthält'),
                ])->query(fn (Builder $q, array $data) =>
                    isset($data['action']) && $data['action'] !== ''
                        ? $q->where('action', 'like', '%'.$data['action'].'%')
                        : $q),
                Filter::make('clearnames')->label('Nur Klarname-Aktionen')
                    ->query(fn (Builder $q) => $q->where('includes_clearnames', true))
                    ->toggle(),
                SelectFilter::make('archive_mode')
                    ->label('Archiv-Sicht')
                    ->options([
                        'active' => 'Nur aktive (Default)',
                        'all' => 'Alle inkl. Archiv',
                        'archived' => 'Nur archivierte',
                    ])
                    ->default('active')
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? 'active') {
                        'archived' => $q->whereNotNull('archived_at'),
                        'all' => $q,
                        default => $q->whereNull('archived_at'),
                    }),
            ])
            ->defaultPaginationPageOption(50);
    }
}
