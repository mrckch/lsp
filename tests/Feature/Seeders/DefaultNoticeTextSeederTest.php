<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\NoticeText\Models\NoticeText;
use Database\Seeders\DefaultNoticeTextSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefaultNoticeTextSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_an_active_default_notice_text_without_creator(): void
    {
        $this->seed(DefaultNoticeTextSeeder::class);

        $text = NoticeText::defaultText();
        $this->assertNotNull($text);
        $this->assertSame(DefaultNoticeTextSeeder::NAME, $text->name);
        $this->assertNull($text->created_by_user_id);
        $this->assertStringContainsString('richtig', $text->content);
    }

    #[Test]
    public function it_is_idempotent_and_keeps_user_edits(): void
    {
        $this->seed(DefaultNoticeTextSeeder::class);
        NoticeText::query()->firstWhere('name', DefaultNoticeTextSeeder::NAME)->update(['content' => 'Eigener Text']);

        $this->seed(DefaultNoticeTextSeeder::class);

        $this->assertSame(1, NoticeText::query()->count());
        $this->assertSame('Eigener Text', NoticeText::query()->first()->content);
    }

    #[Test]
    public function only_one_notice_text_is_default(): void
    {
        $this->seed(DefaultNoticeTextSeeder::class);

        $other = NoticeText::query()->create(['name' => 'Klasse 2', 'content' => 'X', 'status' => 'aktiv', 'is_default' => true]);

        $this->assertSame(1, NoticeText::query()->where('is_default', true)->count());
        $this->assertTrue(NoticeText::defaultText()->is($other));
    }
}
