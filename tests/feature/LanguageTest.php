<?php

use App\Filters\UiLocale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Page language (Indonesian / English) and the first-visit picker.
 *
 * @internal
 */
final class LanguageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = '';
        unset($_COOKIE[UiLocale::COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[UiLocale::COOKIE]);
        parent::tearDown();
    }

    public function testFirstVisitShowsThePickerInIndonesian(): void
    {
        $result = $this->get('demo/chat');

        $result->assertSee('id="lang-picker"');
        $result->assertSee('<h1>Simulator WhatsApp</h1>');
    }

    public function testEnglishPagesHaveNoPickerAndTranslateReplies(): void
    {
        $_COOKIE[UiLocale::COOKIE] = 'en';

        $result = $this->get('demo/chat');

        $result->assertDontSee('id="lang-picker"');
        $result->assertSee('<h1>WhatsApp simulator</h1>');
        $result->assertSee('window.pahamiTranslate');
        $result->assertSee('Will I win?');
    }

    public function testSwitchSetsTheCookieAndGoesBack(): void
    {
        $result = $this->get('lang/en', ['r' => '/demo/12']);

        $result->assertRedirectTo(site_url('demo/12'));
        $this->assertSame('en', $result->response()->getCookie(UiLocale::COOKIE)->getValue());
    }

    public function testSwitchNeverRedirectsOffSite(): void
    {
        foreach (['https://evil.example/', '//evil.example', '/webhook/fonnte'] as $target) {
            $this->get('lang/id', ['r' => $target])->assertRedirectTo(site_url('demo/chat'));
        }
    }

    public function testUnknownLanguageFallsBackToIndonesian(): void
    {
        $result = $this->get('lang/fr');

        $this->assertSame('id', $result->response()->getCookie(UiLocale::COOKIE)->getValue());
    }
}
