<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\View;

use DigitalLibrary\I18n\Translator;
use DigitalLibrary\View\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 3);
        $this->view = new View($base . '/templates', new Translator($base . '/lang', 'en'));
        $this->view->share('csrf_token', str_repeat('a', 64));
    }

    public function testEscapesHtmlAndAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $this->view->e('<script>alert("x")</script>'));
        self::assertSame('&apos; onmouseover=&apos;x', $this->view->e("' onmouseover='x"));
        self::assertSame("\u{FFFD}", $this->view->e("\xC3"), 'Invalid UTF-8 is substituted, not dropped');
    }

    public function testTranslationParametersAreEscaped(): void
    {
        self::assertSame('Welcome, &lt;b&gt;Eve&lt;/b&gt;', $this->view->t('dashboard.welcome', ['name' => '<b>Eve</b>']));
    }

    public function testUserDataIsEscapedInRenderedPage(): void
    {
        $html = $this->view->render('auth/login', ['page_title' => 'auth.login.title', 'email' => '"><script>x()</script>', 'error' => null]);

        self::assertStringNotContainsString('<script>x()</script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        self::assertStringContainsString('name="_csrf" value="' . str_repeat('a', 64) . '"', $html);
        self::assertStringContainsString('<html lang="en">', $html);
    }

    public function testRejectsTraversalInTemplateNames(): void
    {
        foreach (['../config/routes', 'auth/../../x', '/etc/passwd', 'Auth/Login', 'auth/login.php'] as $name) {
            try {
                $this->view->render($name);
                self::fail("Template name accepted: {$name}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
