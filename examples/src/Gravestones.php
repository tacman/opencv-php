<?php
declare(strict_types=1);

namespace OpenCvExamples;

use CV\Mat;
use Demo\StoneCrop;

use const CV\{BORDER_REPLICATE, CMP_EQ, COLOR_BGR2GRAY, COLOR_GRAY2BGR, CV_32F, CV_8UC1, CV_8UC3, GC_FGD, GC_INIT_WITH_RECT,
    GC_PR_FGD, INTER_CUBIC, MORPH_ELLIPSE, MORPH_OPEN};

require_once __DIR__ . '/../../demo/src/StoneCrop.php';

#[Group('Gravestones', order: 1)]
final class Gravestones
{
    #[Example(
        title: 'Straighten a leaning headstone',
        description: 'A headstone photographed from a standing position leans: its sides are not parallel in the picture. Find the two sides, then warp the crop so both are vertical again.',
        input: 'images/gravestone.jpg',
        order: 1,
    )]
    public function straighten(Mat $photo): Mat
    {
        // Where the stone is (GrabCut) and where its sides are (Canny + Hough): demo/src/StoneCrop.php.
        [$x, $y, $w, $h] = StoneCrop::findStone($photo)['box'];
        $stone = $photo->roi($x, $y, $w, $h);
        [$left, $right] = StoneCrop::findSides($stone);       // each side as its x at the top (x0) and bottom (x1)

        // The four corners as they are, and as they should be: same width at mid-height, sides vertical.
        $from = [[$left['x0'], 0], [$right['x0'], 0], [$right['x1'], $h], [$left['x1'], $h]];
        $lx = ($left['x0'] + $left['x1']) / 2;
        $rx = ($right['x0'] + $right['x1']) / 2;
        $to = [[$lx, 0], [$rx, 0], [$rx, $h], [$lx, $h]];

        $matrix = \CV\getPerspectiveTransform(Mat::fromArray($from, CV_32F), Mat::fromArray($to, CV_32F));

        return \CV\warpPerspective($stone, $matrix, [$w, $h], INTER_CUBIC, BORDER_REPLICATE);
    }

    #[Example(
        title: 'Cut the stone out of its background',
        description: 'GrabCut separates an object from what surrounds it. Tell it that everything outside a rectangle is background, and it works out which pixels inside belong to the stone.',
        input: 'images/gravestone.jpg',
        order: 2,
    )]
    public function cutOut(Mat $photo): Mat
    {
        [$w, $h] = [$photo->cols(), $photo->rows()];
        $mask = new Mat($h, $w, CV_8UC1, 0);
        $inside = [(int) ($w * 0.04), (int) ($h * 0.04), (int) ($w * 0.92), (int) ($h * 0.92)];
        \CV\grabCut($photo, $mask, $inside, new Mat(), new Mat(), 5, GC_INIT_WITH_RECT);

        // Keep what GrabCut calls foreground or probably foreground, without the specks.
        $stone = \CV\bitwise_or(\CV\compare($mask, [GC_FGD], CMP_EQ), \CV\compare($mask, [GC_PR_FGD], CMP_EQ));
        $stone = \CV\morphologyEx($stone, MORPH_OPEN, \CV\getStructuringElement(MORPH_ELLIPSE, [15, 15]));

        $cutOut = new Mat($h, $w, CV_8UC3, [255, 255, 255]);
        $photo->copyTo($cutOut, $stone);

        return $cutOut;
    }

    #[Example(
        title: 'Bring out a worn inscription',
        description: 'Weathered lettering has little contrast. CLAHE stretches the contrast separately in each part of the picture, so shallow carving shows without the bright areas burning out.',
        input: 'images/gravestone.jpg',
        order: 3,
    )]
    public function inscription(Mat $photo): Mat
    {
        $gray = \CV\cvtColor($photo, COLOR_BGR2GRAY);
        $clahe = \CV\createCLAHE(clipLimit: 4.0, tileGridSize: [8, 8]);

        return $clahe->apply($gray);
    }
}
