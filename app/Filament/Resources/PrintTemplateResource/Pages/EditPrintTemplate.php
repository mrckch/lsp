<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrintTemplateResource\Pages;

use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Filament\Resources\PrintTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrintTemplate extends EditRecord
{
    protected static string $resource = PrintTemplateResource::class;

    protected $html;
    protected $css;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => ! $this->record->is_system)];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->html = $data['html_content'] ?? '';
        $this->css = $data['css_content'] ?? '';
        unset($data['html_content'], $data['css_content']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Wenn sich HTML/CSS ggü. aktueller Version unterscheidet → neue Version
        $current = $this->record->currentVersion;
        $changed = ! $current
            || $current->html_content !== $this->html
            || ($current->css_content ?? '') !== $this->css;

        if (! $changed) {
            return;
        }

        $next = ($this->record->versions()->max('version_number') ?? 0) + 1;
        $version = PrintTemplateVersion::create([
            'print_template_id' => $this->record->id,
            'version_number' => $next,
            'html_content' => $this->html,
            'css_content' => $this->css,
            'created_by_user_id' => auth()->id(),
        ]);
        $this->record->update(['current_version_id' => $version->id]);
    }
}
