#!/usr/bin/env php
<?php
/**
 * Run the examples.
 *
 *   php examples/run.php                         list them
 *   php examples/run.php all                     run every example that has an input picture
 *   php examples/run.php objects.cropcoins       run one
 *   php examples/run.php faces.detect photo.jpg  run one on your own picture
 *
 * Results go to examples/out/<id>.jpg, and <id>-1.jpg, -2.jpg ... when an
 * example returns several pictures.
 */
declare(strict_types=1);

use OpenCvExamples\Catalog;

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'OpenCvExamples\\')) {
        require __DIR__ . '/src/' . substr($class, 15) . '.php';
    }
});

$which = $argv[1] ?? null;
if ($which === null) {
    foreach (Catalog::all() as $entry) {
        printf("%-26s %s%s\n", $entry['id'], $entry['example']->title, $entry['example']->input === null ? '  (needs your own picture)' : '');
    }
    exit(0);
}
if (!extension_loaded('opencv')) {
    fwrite(STDERR, "The opencv extension is not loaded: php -d extension=opencv examples/run.php ...\n");
    exit(2);
}

@mkdir(__DIR__ . '/out');
$ran = 0;
foreach (Catalog::all() as ['id' => $id, 'example' => $example, 'method' => $method]) {
    if ($which !== 'all' && $which !== $id) {
        continue;
    }
    $input = $argv[2] ?? ($example->input === null ? null : __DIR__ . '/' . $example->input);
    if ($input === null) {
        if ($which !== 'all') {
            fwrite(STDERR, "$id needs a picture: php examples/run.php $id photo.jpg\n({$example->needs})\n");
            exit(2);
        }
        continue;
    }
    array_map('unlink', glob(__DIR__ . "/out/$id{,-*}.jpg", GLOB_BRACE));
    $started = hrtime(true);
    $result = $method->invoke($method->getDeclaringClass()->newInstance(), CV\imread($input));
    $ms = (hrtime(true) - $started) / 1e6;
    if ($result instanceof CV\Mat) {
        CV\imwrite(__DIR__ . "/out/$id.jpg", $result);
    } else {
        foreach (array_values($result) as $n => $piece) {
            CV\imwrite(sprintf('%s/out/%s-%d.jpg', __DIR__, $id, $n + 1), $piece);
        }
    }
    printf("%-26s %4.0f ms  %s\n", $id, $ms, $result instanceof CV\Mat ? "out/$id.jpg" : count($result) . " pictures, out/$id-*.jpg");
    $ran++;
}
exit($ran > 0 ? 0 : 1);
