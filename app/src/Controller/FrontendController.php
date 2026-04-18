<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class FrontendController extends AbstractController
{
    public function index(): Response
    {
        $indexPath = $this->getParameter('kernel.project_dir').'/public/frontend/index.html';

        if (!file_exists($indexPath)) {
            return new Response(
                '<h1>Frontend not built</h1><p>Run <code>cd app/frontend && npm run build</code> to build the React app.</p>',
                Response::HTTP_OK,
                ['Content-Type' => 'text/html'],
            );
        }

        return new Response(
            file_get_contents($indexPath),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
