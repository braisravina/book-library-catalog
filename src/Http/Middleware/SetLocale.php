<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\I18n\LocaleNegotiator;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Security\Session;

/**
 * Locale precedence: explicit session choice (seeded from the user's profile
 * at login) > Accept-Language > configured default.
 */
final class SetLocale implements Middleware
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly string $default,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $locale = $this->session->get('locale');
        if (!is_string($locale) || !Translator::isSupported($locale)) {
            $locale = LocaleNegotiator::fromAcceptLanguage($request->header('Accept-Language'), Translator::SUPPORTED, $this->default);
        }
        $this->translator->setLocale($locale);

        $response = $next($request);
        $response->setHeader('Content-Language', $this->translator->locale());
        $vary = $response->header('Vary');
        $response->setHeader('Vary', $vary === null ? 'Accept-Language' : $vary . ', Accept-Language');

        return $response;
    }
}
