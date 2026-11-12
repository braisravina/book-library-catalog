<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\I18n;

use DigitalLibrary\I18n\LocaleNegotiator;
use DigitalLibrary\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../../lang';

    public function testAllLocalesDefineTheSameKeys(): void
    {
        $translator = new Translator(self::LANG_DIR);
        $reference = array_keys($translator->catalogue('en'));
        sort($reference);
        self::assertNotEmpty($reference);

        foreach (Translator::SUPPORTED as $locale) {
            $keys = array_keys($translator->catalogue($locale));
            sort($keys);
            self::assertSame([], array_values(array_diff($reference, $keys)), "Keys missing from {$locale}");
            self::assertSame([], array_values(array_diff($keys, $reference)), "Extra keys in {$locale}");
        }
    }

    public function testPlaceholdersAreConsistentAcrossLocales(): void
    {
        $translator = new Translator(self::LANG_DIR);
        foreach ($translator->catalogue('en') as $key => $message) {
            preg_match_all('/:([a-z_]+)/', $message, $expected);
            foreach (['es', 'gl'] as $locale) {
                preg_match_all('/:([a-z_]+)/', $translator->catalogue($locale)[$key], $actual);
                self::assertEqualsCanonicalizing($expected[1], $actual[1], "Placeholder mismatch for {$key} in {$locale}");
            }
        }
    }

    public function testTranslatesWithPlaceholdersAndFallback(): void
    {
        $translator = new Translator(self::LANG_DIR, 'gl');
        self::assertSame('Ola, Uxía', $translator->get('dashboard.welcome', ['name' => 'Uxía']));
        self::assertSame('missing.key', $translator->get('missing.key'));
        self::assertSame('Welcome, Ana', $translator->get('dashboard.welcome', ['name' => 'Ana'], 'en'));
    }

    public function testUnsupportedLocaleFallsBack(): void
    {
        $translator = new Translator(self::LANG_DIR, 'fr');
        self::assertSame('en', $translator->locale());
        $translator->setLocale('../../etc/passwd');
        self::assertSame('en', $translator->locale());
        self::assertSame([], $translator->catalogue('../../etc/passwd'));
    }

    public function testAcceptLanguageNegotiation(): void
    {
        $supported = Translator::SUPPORTED;
        self::assertSame('gl', LocaleNegotiator::fromAcceptLanguage('gl-ES,gl;q=0.9,es;q=0.8,en;q=0.7', $supported, 'en'));
        self::assertSame('es', LocaleNegotiator::fromAcceptLanguage('fr-FR, es;q=0.5', $supported, 'en'));
        self::assertSame('es', LocaleNegotiator::fromAcceptLanguage('en;q=0.4, es;q=0.9', $supported, 'en'));
        self::assertSame('en', LocaleNegotiator::fromAcceptLanguage('de, fr', $supported, 'en'));
        self::assertSame('en', LocaleNegotiator::fromAcceptLanguage('es;q=0', $supported, 'en'));
        self::assertSame('en', LocaleNegotiator::fromAcceptLanguage(null, $supported, 'en'));
        self::assertSame('en', LocaleNegotiator::fromAcceptLanguage('*', $supported, 'en'));
    }
}
