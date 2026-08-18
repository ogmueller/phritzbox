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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

class FrontendController extends AbstractController
{
    public function __construct(
        // Injected rather than read back out of the container: getParameter()
        // hands back a mixed the caller has to re-narrow, and the one thing this
        // controller needs from the container is a string it can be given.
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function index(): Response
    {
        $indexPath = $this->projectDir.'/public/frontend/index.html';

        if (!file_exists($indexPath)) {
            return new Response(
                '<h1>Frontend not built</h1><p>Run <code>cd app/frontend && npm run build</code> to build the React app.</p>',
                Response::HTTP_OK,
                ['Content-Type' => 'text/html'],
            );
        }

        $html = file_get_contents($indexPath);
        if ($html === false) {
            // The file is there — file_exists() just said so — so a failure here
            // is a permission or I/O fault, not an unbuilt frontend.
            throw new \RuntimeException(\sprintf('Could not read the built frontend at "%s"', $indexPath));
        }

        return new Response(
            $html,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
