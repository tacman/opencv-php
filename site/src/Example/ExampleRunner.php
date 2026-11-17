<?php
declare(strict_types=1);

namespace App\Example;

use OpenCvExamples\Catalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the page shows for each example: its attribute, its code, its input
 * and the pictures it produced.  Runs examples/run.php (in its own process, so
 * the site itself does not need the extension) when a result is missing or
 * older than the example classes.
 */
final class ExampleRunner
{
    public function __construct(
        #[Autowire('%app.examples_dir%')] private readonly string $dir,
    ) {
    }

    /**
     * @return array<string, list<array{id: string, example: \OpenCvExamples\Example, code: string, before: ?string, after: list<string>}>>
     *         by group title; before and after are file names under img/
     */
    public function groups(): array
    {
        $this->refresh();
        $groups = [];
        foreach (Catalog::all() as $entry) {
            $id = $entry['id'];
            $after = array_map('basename', glob(sprintf('%s/out/%s{,-*}.jpg', $this->dir, $id), GLOB_BRACE) ?: []);
            natsort($after);
            $groups[$entry['group']][] = [
                'id' => $id,
                'example' => $entry['example'],
                'code' => Catalog::code($entry['method']),
                'before' => $entry['example']->input === null ? null : "in-$id.jpg",
                'after' => array_values($after),
            ];
        }

        return $groups;
    }

    /** The file behind an img/ name, or null. */
    public function image(string $name): ?string
    {
        $path = $this->dir . '/out/' . $name;
        if (preg_match('~^in-([a-z.]+)\.jpg$~', $name, $m)) {
            $path = null;
            foreach (Catalog::all() as $entry) {
                if ($entry['id'] === $m[1] && $entry['example']->input !== null) {
                    $path = $this->dir . '/' . $entry['example']->input;
                }
            }
        }

        return $path !== null && is_file($path) ? $path : null;
    }

    private function refresh(): void
    {
        $stamp = $this->dir . '/out/.built';
        $newest = max(array_map('filemtime', [...glob($this->dir . '/src/*.php'), ...glob($this->dir . '/images/*')]));
        if (is_file($stamp) && filemtime($stamp) >= $newest) {
            return;
        }
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $process = new Process([$php, ...(extension_loaded('opencv') ? [] : ['-d', 'extension=opencv']), $this->dir . '/run.php', 'all'], timeout: 300);
        $process->mustRun();
        touch($stamp);
    }
}
