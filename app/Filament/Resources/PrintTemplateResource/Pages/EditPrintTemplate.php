<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrintTemplateResource\Pages;

use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Domain\PrintTemplate\TemplateCatalog;
use App\Filament\Concerns\HandlesPrintErrors;
use App\Filament\Resources\PrintTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPrintTemplate extends EditRecord
{
    use HandlesPrintErrors;

    protected static string $resource = PrintTemplateResource::class;

    protected $html;

    protected $css;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewLive')
                ->label('Live-Vorschau (PDF)')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->action(function () {
                    $data = $this->form->getState();
                    $html = $data['html_content'] ?? '';
                    $css = $data['css_content'] ?? '';
                    $sample = TemplateCatalog::for($this->record->type)['sample'] ?? [];

                    return self::runPrintAction(function () use ($html, $css, $sample) {
                        $runner = new PrintJobRunner(app(GotenbergClient::class));
                        $rendered = $runner->renderTemplate($html, $sample);
                        $pdf = app(GotenbergClient::class)->htmlToPdf($rendered, $css);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            'preview_'.$this->record->key.'.pdf',
                            ['Content-Type' => 'application/pdf'],
                        );
                    }, 'Live-Vorschau');
                }),
            DeleteAction::make()->visible(fn () => ! $this->record->is_system),
        ];
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
        $current = $this->record->currentVersion;
        $changed = ! $current
            || $current->html_content !== $this->html
            || ($current->css_content ?? '') !== ($this->css ?? '');

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

        Notification::make()->success()
            ->title("Neue Version v$next gespeichert")->send();
    }
}
