<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Static checks for the ISBN-assisted entry front end: the browser-side
 * ISBN rules stay in step with Isbn::problem(), every reason has a
 * translated message, nothing breaks the CSP, and the layout has its
 * phone and reduced-motion rules.
 */
final class IsbnEntryAssetsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private static function read(string $path): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $path);
    }

    public function testBrowserReasonsMatchTheServerAndHaveMessages(): void
    {
        $js = self::read('public/assets/js/app.js');
        preg_match_all("/return '(required|characters|length|prefix|checksum)';/", $js, $m);
        $jsReasons = array_values(array_unique($m[1]));
        sort($jsReasons);

        preg_match_all("/'validation\\.isbn_([a-z]+)'/", self::read('src/Catalogue/Isbn.php'), $p);
        $phpReasons = array_values(array_unique($p[1]));
        sort($phpReasons);
        self::assertSame($phpReasons, $jsReasons);

        $template = self::read('templates/catalogue/isbn_step.php');
        foreach ($jsReasons as $reason) {
            self::assertStringContainsString('data-msg-' . $reason . '="<?= $view->t(\'validation.isbn_' . $reason . '\'', $template);
        }
        // Same normalisation and check-digit rules as the PHP class.
        self::assertStringContainsString(".replace(/^ISBN(?:-1[03])?:?/, '')", $js);
        self::assertStringContainsString('/^\d{9}X$/', $js);
        self::assertStringContainsString('/^97[89]/', $js);
    }

    public function testFrontEndStaysCspSafe(): void
    {
        $js = self::read('public/assets/js/app.js');
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function', 'document.write'] as $sink) {
            self::assertStringNotContainsString($sink, $js);
        }
        foreach (['templates/catalogue/isbn_step.php', 'templates/catalogue/_lookup_review.php', 'templates/catalogue/_similar_works.php', 'templates/catalogue/book_form.php', 'templates/catalogue/_lookup_status.php', 'templates/catalogue/edition_open_library.php', 'templates/catalogue/edition_show.php'] as $template) {
            $html = self::read($template);
            self::assertDoesNotMatchRegularExpression('/<script|\sstyle=|\son[a-z]+=/i', $html, $template);
        }
    }

    public function testAccessibleLoadingStateAndLiveRegions(): void
    {
        $template = self::read('templates/catalogue/isbn_step.php');
        self::assertStringContainsString('role="status" data-lookup-status', $template);
        self::assertStringContainsString('aria-live="polite" data-isbn-check', $template);
        $js = self::read('public/assets/js/app.js');
        self::assertStringContainsString("form.setAttribute('aria-busy', 'true')", $js);
        self::assertStringContainsString("window.addEventListener('pageshow', reset)", $js, 'Back navigation re-enables the button');
        self::assertStringContainsString("hasAttribute('data-isbn-manual')", $js, 'Manual entry is never blocked');
    }

    public function testPhoneAndReducedMotionRules(): void
    {
        $css = self::read('public/assets/css/app.css');
        self::assertMatchesRegularExpression('/@media \(max-width: 40rem\) \{\s*\.lookup-field \{ grid-template-columns: 1fr; \}\s*\.isbn-form \.form-actions \{ flex-direction: column;/', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce) { .isbn-form[aria-busy="true"] .lookup-status::before { animation: none; } }', $css);
        self::assertStringContainsString('.similar-actions .button { min-height: 2.75rem; }', $css, '44px touch targets');
        self::assertStringContainsString('.button[hidden] { display: none; }', $css, 'Enhancement-only buttons stay hidden without JavaScript');
    }

    public function testContributionLinksAreExternalAndCopyIsAnnounced(): void
    {
        $page = self::read('templates/catalogue/edition_open_library.php');
        self::assertStringContainsString('$external = \' rel="noopener noreferrer external"\';', $page);
        self::assertStringNotContainsString('target=', $page, 'Nothing opens by itself in another window');
        self::assertStringNotContainsString('method="post"', $page, 'Read-only page: nothing is submitted to this application');
        self::assertStringContainsString("document.getElementById(button.getAttribute('data-copy-status') || '')", self::read('public/assets/js/app.js'));
        self::assertMatchesRegularExpression('/@media \(max-width: 40rem\) \{\s*\.lookup-legend dl \{ grid-template-columns: 1fr; \}/', self::read('public/assets/css/app.css'));
    }
}
