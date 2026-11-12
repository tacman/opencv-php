#!/usr/bin/env php
<?php
/**
 * Straighten overhead scans from the command line.
 *
 *   php demo/straighten.php <frame.jpg | batch-folder> [output-folder]
 *
 * Writes <name>.straight.jpg per frame and prints the measurement as JSON lines.
 */
declare(strict_types=1);

require __DIR__ . '/src/Straighten.php';

if (!extension_loaded('opencv')) {
    fwrite(STDERR, "The opencv extension is not loaded. See README.md, Install.\n");
    exit(2);
}
[$input, $outDir] = [$argv[1] ?? null, $argv[2] ?? sys_get_temp_dir() . '/opencv-php-demo'];
if ($input === null) {
    fwrite(STDERR, "usage: php demo/straighten.php <frame.jpg | batch-folder> [output-folder]\n");
    exit(2);
}
$frames = is_dir($input) ? glob(rtrim($input, '/') . '/*.{jpg,jpeg,png}', GLOB_BRACE) : [$input];
@mkdir($outDir, recursive: true);
$failed = 0;
foreach ($frames as $frame) {
    $started = hrtime(true);
    $result = Demo\Straighten::fromBytes(file_get_contents($frame));
    $ms = round((hrtime(true) - $started) / 1e6, 1);
    $name = pathinfo($frame, PATHINFO_FILENAME);
    if ($result === null) {
        echo json_encode(['frame' => basename($frame), 'error' => 'no paper found', 'ms' => $ms]), "\n";
        $failed++;
        continue;
    }
    file_put_contents("$outDir/$name.straight.jpg", $result['jpeg']);
    echo json_encode(['frame' => basename($frame), 'out' => "$outDir/$name.straight.jpg", 'ms' => $ms] + $result['info']), "\n";
}
exit($failed ? 1 : 0);
