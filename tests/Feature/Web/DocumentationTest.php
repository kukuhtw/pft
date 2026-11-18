<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Tests\TestCase;

/**
 * The user guides in /documentation are public at /dokumentasi/… and linked from the landing page.
 */
final class DocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guides_are_public_and_linked_from_the_landing_page(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('docs').'"', false)
            ->assertSee(route('docs.file', ['path' => 'telegram-bot.html']), false);

        $this->get('/dokumentasi')->assertRedirect('/dokumentasi/tutorial.html');

        $tutorial = $this->get('/dokumentasi/tutorial.html')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $html = $tutorial->baseResponse->getFile()->getContent();
        $this->assertStringContainsString('Catatan hutang', $html);
        $this->assertStringContainsString('images/debts.png', $html);

        $bot = $this->get('/dokumentasi/telegram-bot.html')->assertOk();
        $this->assertStringContainsString('/hutang', $bot->baseResponse->getFile()->getContent());
        $this->get('/dokumentasi/doc.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        foreach (['debts.png', 'debts-all.png', 'debt-entry.png'] as $image) {
            $this->get('/dokumentasi/images/'.$image)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_only_guide_files_can_be_read(): void
    {
        foreach ([
            '/dokumentasi/missing.html',
            '/dokumentasi/..%2F.env',
            '/dokumentasi/%2E%2E%2Fcomposer.json',
            '/dokumentasi/images/..%2F..%2F.env',
            '/dokumentasi/.htaccess',
            '/dokumentasi/index.php',
            '/dokumentasi/tutorial.html.bak',
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
