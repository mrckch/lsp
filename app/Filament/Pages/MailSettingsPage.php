<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Mail\MailService;
use App\Domain\Mail\Models\MailSettings;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MailSettingsPage extends Page implements HasForms
{
    use AuthorizedPage;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string { return 'mail.settings.manage'; }

    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 30;
    protected static ?string $title = 'Mail-Einstellungen';
    protected static ?string $navigationLabel = 'Mail (SMTP)';
    protected static string $view = 'filament.pages.mail-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = MailSettings::singleton();
        $this->form->fill([
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => null,
            'smtp_encryption' => $settings->smtp_encryption,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'reply_to' => $settings->reply_to,
            'is_active' => $settings->is_active,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('SMTP-Server')->columns(2)->schema([
                TextInput::make('smtp_host')->label('Host')->required(),
                TextInput::make('smtp_port')->label('Port')->numeric()->default(587)->required(),
                TextInput::make('smtp_username')->label('Benutzer'),
                TextInput::make('smtp_password')->label('Passwort')->password()->revealable()
                    ->helperText('Nur ausfüllen, wenn das Passwort geändert werden soll.'),
                Select::make('smtp_encryption')->label('Verschlüsselung')->required()
                    ->options(['tls' => 'TLS', 'starttls' => 'STARTTLS', 'none' => 'keine'])
                    ->default('tls'),
            ]),
            Section::make('Absender')->columns(2)->schema([
                TextInput::make('from_address')->label('Absender-Adresse')->email(),
                TextInput::make('from_name')->label('Absender-Name'),
                TextInput::make('reply_to')->label('Reply-To')->email(),
            ]),
            Section::make('Status')->schema([
                Toggle::make('is_active')->label('SMTP aktiv (sonst Fallback auf Default-Mailer)'),
            ]),
        ])->statePath('data');
    }

    public function saveAction(): Action
    {
        return Action::make('save')->label('Speichern')->submit('save');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = MailSettings::singleton();

        $settings->fill([
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => $data['smtp_port'],
            'smtp_username' => $data['smtp_username'] ?: null,
            'smtp_encryption' => $data['smtp_encryption'],
            'from_address' => $data['from_address'] ?: null,
            'from_name' => $data['from_name'] ?: null,
            'reply_to' => $data['reply_to'] ?: null,
            'is_active' => (bool) $data['is_active'],
            'updated_by_user_id' => auth()->id(),
        ]);

        if (! empty($data['smtp_password'])) {
            $settings->smtp_password = $data['smtp_password'];
        }
        $settings->save();

        Notification::make()->success()->title('Mail-Einstellungen gespeichert')->send();
    }

    public function testMailAction(): Action
    {
        return Action::make('testMail')
            ->label('Test-Mail an mich senden')
            ->color('info')
            ->icon('heroicon-o-paper-airplane')
            ->action(function () {
                $email = auth()->user()?->email;
                if (! $email) {
                    Notification::make()->danger()
                        ->title('Keine Mailadresse für Ihren Account hinterlegt')->send();

                    return;
                }
                $msg = app(MailService::class)->send([
                    'to' => [$email],
                    'subject' => 'LSP – Test-Mail',
                    'body_html' => '<p>Dies ist eine Test-Mail aus dem LSP-System.</p>',
                ], auth()->id());

                if ($msg->status === 'sent') {
                    Notification::make()->success()->title('Test-Mail versendet')->send();
                } else {
                    Notification::make()->danger()
                        ->title('Versand fehlgeschlagen')
                        ->body($msg->error_message ?? '')->send();
                }
            });
    }
}
