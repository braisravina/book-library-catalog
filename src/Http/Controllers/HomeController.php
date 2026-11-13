<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->services->auth()->user();
        if ($user === null) {
            // Guests see a neutral landing page with no catalogue data.
            return $this->render('home/landing', ['page_title' => null]);
        }

        // Every figure is taken over the records this user may see.
        $visibility = $this->services->visibility($user);
        $stats = $this->services->stats()->dashboard($visibility, $user->id);

        return $this->render('home/dashboard', [
            'page_title' => 'nav.dashboard',
            'stats' => $stats,
            'my_copies' => $stats['my_copies'],
            'recent' => $this->services->editions()->recent(12, $visibility),
            'overdue' => $this->services->loans()->countOverdueForOwner($user->id, $visibility),
        ]);
    }
}
