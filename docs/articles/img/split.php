<?php
use const CV\{COLOR_BGR2GRAY, THRESH_BINARY, THRESH_OTSU, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE, INTER_CUBIC};

$scan = CV\imread($argv[1]);
$gray = CV\GaussianBlur(CV\cvtColor($scan, COLOR_BGR2GRAY), [5, 5], 0);
[, $mask] = CV\threshold($gray, 0, 255, THRESH_BINARY + THRESH_OTSU);     // prints are lighter than the mat
[$contours] = CV\findContours($mask, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);

$n = 0;
foreach ($contours as $outline) {
    if (CV\contourArea($outline) < 0.02 * $scan->rows() * $scan->cols()) {
        continue;                                                         // dust, not a print
    }
    [$center, [$w, $h], $angle] = CV\minAreaRect($outline);               // the tilted rectangle around it
    if (abs($angle) > 45) {                                               // keep the turn small
        [$w, $h, $angle] = [$h, $w, $angle - 90 * ($angle <=> 0)];
    }
    $upright = CV\warpAffine($scan, CV\getRotationMatrix2D($center, $angle, 1.0), $scan->size(), INTER_CUBIC);
    $print   = CV\getRectSubPix($upright, [(int) $w, (int) $h], $center);
    CV\imwrite(sprintf('print-%d.jpg', ++$n), $print);
    printf("print-%d.jpg  %d x %d px, was tilted %.1f degrees\n", $n, $w, $h, $angle);
}
