<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert la coquille de l'application Vue pour toute URL hors API / outils internes ;
 * le routage est ensuite assuré côté client par vue-router.
 */
final class SpaController extends AbstractController
{
    #[Route(
        '/{vue}',
        name: 'app_spa',
        requirements: ['vue' => '^(?!api|_(profiler|wdt|error)|build).*'],
        defaults: ['vue' => ''],
        methods: ['GET'],
        priority: -10,
    )]
    public function index(): Response
    {
        return $this->render('spa.html.twig');
    }
}
