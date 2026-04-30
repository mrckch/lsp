<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrintTemplateResource\Pages;

use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Filament\Resources\PrintTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrintTemplate extends CreateRecord
{
    protected static string $resource = PrintTemplateResource::class;

    protected $html;
    protected $css;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->html = $data['html_content'] ?? '';
        $this->css = $data['css_content'] ?? '';
        unset($data['html_content'], $data['css_content']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $version = PrintTemplateVersion::create([
            'print_template_id' => $this->record->id,
            'version_number' => 1,
            'html_content' => $this->html,
            'css_content' => $this->css,
            'created_by_user_id' => auth()->id(),
        ]);
        $this->record->update(['current_version_id' => $version->id]);
    }
}
