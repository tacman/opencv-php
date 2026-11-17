<?php
declare(strict_types=1);

namespace App\Controller;

use App\Example\ExampleRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One page.  It refers to its pictures by relative name (img/<file>), so the
 * same HTML works served by Symfony and exported to a folder.
 */
final class ExampleController extends AbstractController
{
    public function __construct(
        private readonly ExampleRunner $runner,
    ) {
    }

    #[Route('/', name: 'home')]
    #[Route('/index.html')]
    public function index(): Response
    {
        return $this->render('index.html.twig', [
            'groups' => $this->runner->groups(),
            'opencv' => \function_exists('CV\getVersionString') ? \CV\getVersionString() : null,
        ]);
    }

    /** A result (examples/out/<file>) or an example's input (in-<id>.jpg). */
    #[Route('/img/{file}', name: 'image', requirements: ['file' => '[A-Za-z0-9_.-]+\.jpg'])]
    public function image(string $file): Response
    {
        $path = $this->runner->image($file) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($path, headers: ['Content-Type' => 'image/jpeg']);
    }
}
