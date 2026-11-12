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

        $pdo = $this->services->pdo();
        $stats = $pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM works) AS works,
                (SELECT COUNT(*) FROM editions) AS editions,
                (SELECT COUNT(*) FROM copies) AS copies,
                (SELECT COUNT(*) FROM authors) AS authors'
        )->fetch();
        $mine = $pdo->prepare('SELECT COUNT(*) FROM copies WHERE owner_user_id = :id');
        $mine->execute(['id' => $user->id]);

        return $this->render('home/dashboard', [
            'page_title' => 'nav.dashboard',
            'stats' => array_map('intval', $stats ?: []),
            'my_copies' => (int) $mine->fetchColumn(),
            'recent' => $this->services->editions()->recent(12),
            'overdue' => $this->services->loans()->countOverdueForOwner($user->id),
        ]);
    }
}
