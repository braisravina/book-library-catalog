<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\I18n\Translator;

final class LocaleController extends Controller
{
    public function update(Request $request): Response
    {
        $locale = (string) $request->input('locale', '');
        if (Translator::isSupported($locale)) {
            $this->services->session()->set('locale', $locale);
            $user = $this->services->auth()->user();
            if ($user !== null) {
                $this->services->users()->updateLocale($user->id, $locale);
            }
        }
        $back = (string) $request->input('return_to', '/');

        return Response::redirect(Response::isSafeLocalPath($back) ? $back : '/');
    }
}
