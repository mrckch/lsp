<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Filament\Resources\QuestionnaireResource\Pages\EditQuestionnaire;
use App\Filament\Resources\QuestionnaireResource\Pages\ListQuestionnaires;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuestionnaireImportActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'Admin',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($this->admin);

        Storage::fake('local');
    }

    #[Test]
    public function list_page_imports_csv_as_new_questionnaire(): void
    {
        $file = UploadedFile::fake()->createWithContent('fragen.csv', "satz;antwort;typ\nA.;richtig;test\nB.;falsch;uebung\n");

        Livewire::test(ListQuestionnaires::class)
            ->callAction('importQuestionnaire', data: [
                'file' => $file,
                'name' => 'Import-Test',
                'parallel_form' => 'B2',
            ])
            ->assertHasNoActionErrors();

        $q = Questionnaire::query()->where('name', 'Import-Test')->first();
        $this->assertNotNull($q);
        $this->assertSame('B2', $q->parallel_form);
        $this->assertEquals($this->admin->id, $q->created_by_user_id);
        $this->assertSame(['A.'], $q->questions()->pluck('question_text')->all());
        $this->assertSame(['B.'], $q->practiceQuestions()->pluck('question_text')->all());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'questionnaire.imported')->where('entity_id', $q->id)->exists());
        // Upload wird nach dem Einlesen wieder entfernt
        $this->assertSame([], Storage::disk('local')->allFiles('lsp/imports/questionnaires'));
    }

    #[Test]
    public function invalid_file_creates_nothing(): void
    {
        $file = UploadedFile::fake()->createWithContent('fragen.csv', "satz;antwort\nA.;vielleicht\n");

        Livewire::test(ListQuestionnaires::class)
            ->callAction('importQuestionnaire', data: ['file' => $file, 'name' => 'Kaputt']);

        $this->assertSame(0, Questionnaire::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('lsp/imports/questionnaires'));
    }

    #[Test]
    public function edit_page_replaces_questions_of_existing_questionnaire(): void
    {
        $q = Questionnaire::create(['name' => 'Bestand', 'status' => 'entwurf', 'created_by_user_id' => $this->admin->id]);
        QuestionnaireQuestion::create([
            'questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Alt.', 'correct_answer' => 'richtig',
        ]);
        $file = UploadedFile::fake()->createWithContent('neu.json', '[{"text": "Neu.", "answer": "falsch"}]');

        Livewire::test(EditQuestionnaire::class, ['record' => $q->getRouteKey()])
            ->callAction('importQuestions', data: ['file' => $file, 'mode' => 'replace'])
            ->assertHasNoActionErrors();

        $this->assertSame(['Neu.'], $q->questions()->pluck('question_text')->all());
    }
}
