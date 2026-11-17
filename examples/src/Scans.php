<?php
declare(strict_types=1);

namespace OpenCvExamples;

use CV\Mat;

use const CV\{CHAIN_APPROX_SIMPLE, COLOR_BGR2GRAY, CV_32F, INTER_AREA, INTER_CUBIC, KMEANS_PP_CENTERS, RETR_EXTERNAL,
    THRESH_BINARY, THRESH_OTSU};

#[Group('Scans and photographs', order: 2)]
final class Scans
{
    #[Example(
        title: 'Split a scan into prints',
        description: 'Several photo prints lie on a dark mat at whatever angle they were put down. Threshold, take the outer contours, fit a tilted rectangle to each, turn it upright and cut it out.',
        input: '../docs/articles/img/scan.jpg',
        order: 1,
    )]
    public function splitPrints(Mat $scan): array
    {
        $gray = \CV\GaussianBlur(\CV\cvtColor($scan, COLOR_BGR2GRAY), [5, 5], 0);
        [, $mask] = \CV\threshold($gray, 0, 255, THRESH_BINARY + THRESH_OTSU);    // prints are lighter than the mat
        [$contours] = \CV\findContours($mask, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);

        $prints = [];
        foreach ($contours as $outline) {
            if (\CV\contourArea($outline) < 0.02 * $scan->rows() * $scan->cols()) {
                continue;                                                         // dust, not a print
            }
            [$center, [$w, $h], $angle] = \CV\minAreaRect($outline);              // the tilted rectangle around it
            if (abs($angle) > 45) {                                               // keep the turn small
                [$w, $h, $angle] = [$h, $w, $angle - 90 * ($angle <=> 0)];
            }
            $upright = \CV\warpAffine($scan, \CV\getRotationMatrix2D($center, $angle, 1.0), $scan->size(), INTER_CUBIC);
            $prints[] = \CV\getRectSubPix($upright, [(int) $w, (int) $h], $center);
        }

        return $prints;
    }

    #[Example(
        title: 'Find a photograph\'s main colours',
        description: 'k-means groups the pixels into five clusters by colour; the centre of each cluster is one colour of the palette, and the size of the cluster says how much of the picture it covers.',
        input: '../docs/articles/img/print-4.jpg',
        order: 2,
    )]
    public function palette(Mat $photo): Mat
    {
        // A small copy is enough to judge colour: one row per pixel, three float columns.
        $small = \CV\resize($photo, [64, 64], interpolation: INTER_AREA);
        $pixels = $small->reshape(1, 64 * 64)->convertTo(CV_32F);

        $labels = new Mat();
        [, $centers] = \CV\kmeans($pixels, 5, $labels, [3, 20, 1.0], 3, KMEANS_PP_CENTERS);
        $share = array_count_values(array_column($labels->toArray(), 0));
        arsort($share);

        // Draw the colours as a strip under the photograph, widest first.
        [$w, $x] = [$photo->cols(), 0];
        $strip = new Mat(80, $w, $photo->type(), [255, 255, 255]);
        foreach ($share as $cluster => $count) {
            $width = (int) round($w * $count / (64 * 64));
            \CV\rectangle($strip, [$x, 0, $width, 80], array_map('intval', $centers->toArray()[$cluster]), -1);
            $x += $width;
        }

        return \CV\vconcat([$photo, $strip]);
    }
}
