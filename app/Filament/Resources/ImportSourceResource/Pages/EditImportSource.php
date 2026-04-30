<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportSourceResource\Pages;

use App\Filament\Resources\ImportSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportSource extends EditRecord
{
    protected static string $resource = ImportSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Beim Edit: leeres Passwortfeld bedeutet "nicht ändern".
        // Wir mergen das Form-Config mit dem alten config_encrypted, damit der
        // alte password-Wert erhalten bleibt, wenn das Form-Feld leer war.
        $existing = (array) ($this->record->config_encrypted ?? []);
        $incoming = (array) ($data['config_encrypted'] ?? []);

        if (empty($incoming['password'] ?? null)) {
            unset($incoming['password']);
        }
        $data['config_encrypted'] = array_merge($existing, $incoming);

        return $data;
    }
}
