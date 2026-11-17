<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Writes the site as plain files, for any static host. */
#[AsCommand('app:export', 'Run the examples and write the site as static files')]
final class ExportCommand
{
    public function __construct(
        private readonly HttpKernelInterface $kernel,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder to write to')] string $dir = 'var/export',
    ): int {
        @mkdir($dir . '/img', recursive: true);
        array_map('unlink', glob($dir . '/img/*.jpg') ?: []);
        $html = $this->fetch('/')->getContent();
        file_put_contents($dir . '/index.html', $html);
        preg_match_all('~src="(img/[^"]+)"~', $html, $m);
        foreach ($images = array_unique($m[1]) as $image) {
            $response = $this->fetch('/' . $image);
            \assert($response instanceof BinaryFileResponse);
            copy($response->getFile()->getPathname(), $dir . '/' . $image);
        }
        $io->success(sprintf('index.html and %d pictures in %s', count($images), $dir));

        return 0;
    }

    private function fetch(string $path): Response
    {
        $response = $this->kernel->handle(Request::create($path), HttpKernelInterface::SUB_REQUEST, false);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('%s answered %d', $path, $response->getStatusCode()));
        }

        return $response;
    }
}
