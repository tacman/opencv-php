<?php
declare(strict_types=1);

namespace OpenCvExamples;

use CV\Mat;

use const CV\aruco\DICT_4X4_50;
use const CV\{FONT_HERSHEY_SIMPLEX, LINE_AA};

#[Group('Codes and markers', order: 4)]
final class Codes
{
    #[Example(
        title: 'Read every QR code in a photo',
        description: 'A paper ruler made of QR codes, photographed by hand. Each code is found with its four corners and decoded; here each one holds its position on the ruler as JSON.',
        input: 'images/qr-ruler.jpg',
        order: 1,
    )]
    public function readQrCodes(Mat $photo): Mat
    {
        $detector = new \CV\QRCodeDetectorAruco();
        [, $texts, $corners] = $detector->detectAndDecodeMulti($photo);

        $result = $photo->clone();
        foreach ($corners->toArray() as $i => $quad) {
            $quad = array_map(fn (array $p) => [(int) $p[0], (int) $p[1]], $quad);
            $read = $texts[$i] !== '';
            \CV\polylines($result, [$quad], true, $read ? [0, 170, 0] : [0, 0, 255], 4, LINE_AA);
            if ($read) {
                $at = json_decode($texts[$i], true);                              // {"dim": "x", "idx": 3}
                \CV\putText($result, $at['dim'] . $at['idx'], $quad[0], FONT_HERSHEY_SIMPLEX, 1.2, [0, 170, 0], 3, LINE_AA);
            }
        }

        return $result;
    }

    #[Example(
        title: 'Find ArUco markers',
        description: 'ArUco markers are the black-and-white squares used to locate and measure things in a camera frame. Each one is found with its four corners and its id, whichever way the sheet is turned.',
        input: 'images/aruco-sheet.jpg',
        order: 2,
    )]
    public function findArucoMarkers(Mat $photo): Mat
    {
        $dictionary = \CV\aruco\getPredefinedDictionary(DICT_4X4_50);
        $detector = new \CV\aruco\ArucoDetector($dictionary, new \CV\aruco\DetectorParameters());
        [$corners, $ids] = $detector->detectMarkers($photo);

        $result = $photo->clone();
        \CV\aruco\drawDetectedMarkers($result, $corners, $ids);

        return $result;
    }
}
