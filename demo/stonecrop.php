#!/usr/bin/env php
<?php
/**
 * Cut headstones out of handheld cemetery photographs.
 *
 *   php demo/stonecrop.php <photo.jpg | folder> [output-folder]
 *
 * Per photo with a stone: <name>.stone.jpg (the upright crop) and <name>.box.jpg
 * (the frame with the detected box, for review).  Photos with no stone get only
 * <name>.reject.jpg (amber box, or none).  Prints one JSON line per photo.
 */
declare(strict_types=1);

require __DIR__ . '/src/StoneCrop.php';

if (!extension_loaded('opencv')) {
    fwrite(STDERR, "The opencv extension is not loaded. See README.md, Install.\n");
    exit(2);
}
[$input, $outDir] = [$argv[1] ?? null, $argv[2] ?? sys_get_temp_dir() . '/opencv-php-stones'];
if ($input === null) {
    fwrite(STDERR, "usage: php demo/stonecrop.php <photo.jpg | folder> [output-folder]\n");
    exit(2);
}
$photos = is_dir($input) ? glob(rtrim($input, '/') . '/*.{jpg,jpeg,JPG,png}', GLOB_BRACE) : [$input];
@mkdir($outDir, recursive: true);
foreach ($photos as $photo) {
    $started = hrtime(true);
    $result = Demo\StoneCrop::fromBytes(file_get_contents($photo));
    $ms = round((hrtime(true) - $started) / 1e6, 1);
    $name = pathinfo($photo, PATHINFO_FILENAME);
    if ($result['stone']) {
        file_put_contents("$outDir/$name.stone.jpg", $result['jpeg']);
        file_put_contents("$outDir/$name.box.jpg", $result['overlay']);
    } else {
        file_put_contents("$outDir/$name.reject.jpg", $result['overlay']);
    }
    echo json_encode(['file' => basename($photo), 'stone' => $result['stone']] + $result['info'] + ['ms' => $ms]), "\n";
}
