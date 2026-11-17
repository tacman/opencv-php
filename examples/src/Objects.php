<?php
declare(strict_types=1);

namespace OpenCvExamples;

use CV\Mat;

use const CV\{CHAIN_APPROX_SIMPLE, COLOR_BGR2HSV, MORPH_ELLIPSE, MORPH_OPEN, RETR_EXTERNAL};

#[Group('Objects', order: 3)]
final class Objects
{
    #[Example(
        title: 'Crop every coin',
        description: 'Coins on a blue cloth: whatever is not blue is an object. Keep the round ones, and cut a square around each.',
        input: 'images/coins.jpg',
        order: 1,
    )]
    public function cropCoins(Mat $photo): array
    {
        $hsv = \CV\cvtColor($photo, COLOR_BGR2HSV);
        $cloth = \CV\inRange($hsv, [100, 120, 50], [130, 255, 255]);              // hue 100-130 is blue
        $things = \CV\morphologyEx(\CV\bitwise_not($cloth), MORPH_OPEN, \CV\getStructuringElement(MORPH_ELLIPSE, [9, 9]));
        [$contours] = \CV\findContours($things, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);

        $coins = [];
        foreach ($contours as $outline) {
            $area = \CV\contourArea($outline);
            [$center, $radius] = \CV\minEnclosingCircle($outline);
            if ($area < 2000 || $area < 0.85 * M_PI * $radius ** 2) {
                continue;                                                         // too small, or not round
            }
            $side = (int) (2 * $radius * 1.08);
            $coins[] = \CV\getRectSubPix($photo, [$side, $side], $center);
        }
        usort($coins, fn (Mat $a, Mat $b) => $b->cols() <=> $a->cols());          // largest first

        return $coins;
    }
}
