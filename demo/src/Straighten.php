<?php
/**
 * The overhead-scan "straighten" tool.
 *
 * Find the sheet of paper lying on a black mat under an overhead camera, cut
 * it out upright, and measure it against the ArUco markers of a printed corner
 * bracket.  This is a call-for-call port of a Python (cv2) service endpoint,
 * and produces byte-identical JPEGs from the same frame.
 */
declare(strict_types=1);

namespace Demo;

use CV\Mat;

use const CV\{CHAIN_APPROX_SIMPLE, COLOR_BGR2GRAY, CV_32F, IMREAD_COLOR, IMWRITE_JPEG_QUALITY, INTER_AREA, INTER_CUBIC,
    MORPH_CLOSE, MORPH_OPEN, MORPH_RECT, RETR_EXTERNAL, THRESH_BINARY, THRESH_OTSU};
use const CV\aruco\DICT_4X4_100;

final class Straighten
{
    /** Common paper, in mm as [short, long]. Used only to put a name on a measured size. */
    private const NOMINAL_MM = [
        'letter' => [215.9, 279.4], 'legal' => [215.9, 355.6], 'A4' => [210.0, 297.0], 'A5' => [148.0, 210.0],
        '4x6 in' => [101.6, 152.4], '5x7 in' => [127.0, 177.8],
    ];
    /** Marker centres on the printed corner anchor, in mm from its outer top-left corner. */
    private const ANCHOR_CENTRES_MM = [10 => [15.7, 15.7], 12 => [136.7, 15.7], 13 => [15.7, 136.7]];
    private const MARKER_SIDE_MM = 28.6;

    /**
     * @return array{jpeg: string, info: array<string,mixed>}|null null when no paper is in the frame
     */
    public static function fromBytes(string $image, int $quality = 92): ?array
    {
        $frame = \CV\imdecode($image, IMREAD_COLOR);
        if ($frame->empty()) {
            throw new \InvalidArgumentException('Not a decodable image');
        }
        $markers = self::detectAruco($frame);
        $rect = self::findPaper($frame, array_column($markers, 'polygon'));
        if ($rect === null) {
            return null;
        }
        $out = self::cropRect($frame, $rect);
        $info = self::describe($out->cols(), $out->rows(), self::pxPerMm($markers));
        $info['tiltDeg'] = round(self::tiltDegrees($rect), 2);
        $ids = array_column($markers, 'id');
        sort($ids);
        $info['markers'] = $ids;
        [$ok, $jpeg] = \CV\imencode('.jpg', $out, [IMWRITE_JPEG_QUALITY, $quality]);
        if (!$ok) {
            throw new \RuntimeException('Could not encode the crop');
        }
        return ['jpeg' => $jpeg, 'info' => $info];
    }

    /** ArUco markers (dictionary 4x4_100): id, corner polygon, centre, side length. */
    public static function detectAruco(Mat $frame): array
    {
        $gray = $frame->channels() === 1 ? $frame : \CV\cvtColor($frame, COLOR_BGR2GRAY);
        $detector = new \CV\aruco\ArucoDetector(
            \CV\aruco\getPredefinedDictionary(DICT_4X4_100),
            new \CV\aruco\DetectorParameters(),
        );
        [$corners, $ids] = $detector->detectMarkers($gray);
        $out = [];
        foreach ($ids->toArray() as $index => $id) {      // 1-D in OpenCV 5: a flat list of ids
            $pts = $corners[$index]->toArray()[0];       // 1 x 4, two channels: [[x, y], ...]
            $side = 0.0;
            for ($i = 0; $i < 4; $i++) {
                $side += hypot($pts[($i + 1) % 4][0] - $pts[$i][0], $pts[($i + 1) % 4][1] - $pts[$i][1]) / 4;
            }
            $out[] = [
                'id' => $id,
                'polygon' => $pts,
                'center' => [array_sum(array_column($pts, 0)) / 4, array_sum(array_column($pts, 1)) / 4],
                'side_px' => $side,
            ];
        }
        return $out;
    }

    /**
     * The paper on the black mat: the largest bright area, as a rotated rectangle
     * [[cx, cy], [width, height], angle] in full-frame pixels.
     */
    public static function findPaper(Mat $frame, array $markerPolygons = []): ?array
    {
        $scale = 1600.0 / max($frame->rows(), $frame->cols());
        $small = \CV\resize($frame, [0, 0], $scale, $scale, INTER_AREA);
        $gray = \CV\cvtColor($small, COLOR_BGR2GRAY);
        foreach ($markerPolygons as $polygon) {
            // Markers have a white quiet zone: black out the marker grown to cover it.
            $cx = array_sum(array_column($polygon, 0)) / 4 * $scale;
            $cy = array_sum(array_column($polygon, 1)) / 4 * $scale;
            $grown = array_map(fn ($p) => [(int) ($cx + ($p[0] * $scale - $cx) * 1.7), (int) ($cy + ($p[1] * $scale - $cy) * 1.7)], $polygon);
            \CV\fillPoly($gray, [$grown], 0);
        }
        $gray = \CV\GaussianBlur($gray, [5, 5], 0);
        // Mat versus everything lighter, then white paper versus the lighter-but-not-white
        // things on the mat: a second Otsu over just the bright pixels.
        [$cut] = \CV\threshold($gray, 0, 255, THRESH_BINARY + THRESH_OTSU);
        $bright = preg_replace(sprintf('/[\x00-\x%02x]/', (int) $cut), '', $gray->data());
        if (strlen($bright) > 1000) {
            [$paperCut] = \CV\threshold(Mat::fromString($bright, strlen($bright), 1), 0, 255, THRESH_BINARY + THRESH_OTSU);
            $cut = max($cut, $paperCut);
        }
        [, $bw] = \CV\threshold($gray, $cut, 255, THRESH_BINARY);
        $kernel = \CV\getStructuringElement(MORPH_RECT, [9, 9]);
        $bw = \CV\morphologyEx(\CV\morphologyEx($bw, MORPH_OPEN, $kernel), MORPH_CLOSE, $kernel);
        [$contours] = \CV\findContours($bw, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);
        if ($contours === []) {
            return null;
        }
        usort($contours, fn ($a, $b) => \CV\contourArea($b) <=> \CV\contourArea($a));
        if (\CV\contourArea($contours[0]) < 0.03 * $small->rows() * $small->cols()) {
            return null;
        }
        [[$cx, $cy], [$rw, $rh], $angle] = \CV\minAreaRect(\CV\convexHull($contours[0]));
        return [[$cx / $scale, $cy / $scale], [$rw / $scale, $rh / $scale], $angle];
    }

    /** Crop and deskew the frame to a rotated rectangle. */
    public static function cropRect(Mat $frame, array $rect, int $paddingPx = 0): Mat
    {
        [$center, [$rw, $rh], $angle] = $rect;
        $box = \CV\boxPoints([$center, [max(1.0, $rw + 2 * $paddingPx), max(1.0, $rh + 2 * $paddingPx)], $angle])->toArray();
        // Order the corners TL, TR, BR, BL: boxPoints does not promise a winding order.
        $by = fn (callable $key, bool $max) => array_reduce($box,
            fn ($best, $p) => $best === null || ($max ? $key($p) > $key($best) : $key($p) < $key($best)) ? $p : $best);
        $sum = fn ($p) => $p[0] + $p[1];
        $diff = fn ($p) => $p[1] - $p[0];
        [$tl, $br, $tr, $bl] = [$by($sum, false), $by($sum, true), $by($diff, false), $by($diff, true)];
        $dist = fn ($a, $b) => hypot($a[0] - $b[0], $a[1] - $b[1]);
        $w = (int) round(max($dist($tr, $tl), $dist($br, $bl)));
        $h = (int) round(max($dist($bl, $tl), $dist($br, $tr)));
        $matrix = \CV\getPerspectiveTransform(
            Mat::fromArray([$tl, $tr, $br, $bl], CV_32F),
            Mat::fromArray([[0, 0], [$w - 1, 0], [$w - 1, $h - 1], [0, $h - 1]], CV_32F),
        );
        return \CV\warpPerspective($frame, $matrix, [$w, $h], INTER_CUBIC);
    }

    /** The scale of the frame from its ArUco markers, in pixels per mm. */
    public static function pxPerMm(array $markers): ?float
    {
        $known = array_values(array_filter($markers, fn ($m) => isset(self::ANCHOR_CENTRES_MM[$m['id']])));
        $scales = [];
        foreach ($known as $i => $a) {
            foreach (array_slice($known, $i + 1) as $b) {
                [$ma, $mb] = [self::ANCHOR_CENTRES_MM[$a['id']], self::ANCHOR_CENTRES_MM[$b['id']]];
                $mm = hypot($ma[0] - $mb[0], $ma[1] - $mb[1]);
                if ($mm > 0) {
                    $scales[] = hypot($a['center'][0] - $b['center'][0], $a['center'][1] - $b['center'][1]) / $mm;
                }
            }
        }
        if ($scales !== []) {
            return round(self::median($scales), 3);
        }
        $sides = array_column($markers, 'side_px');
        return $sides === [] ? null : round(self::median($sides) / (float) (getenv('ARUCO_MARKER_MM') ?: self::MARKER_SIDE_MM), 3);
    }

    /** How far the paper was from upright: small, signed, in (-45, 45]. */
    public static function tiltDegrees(array $rect): float
    {
        $a = fmod($rect[2], 90.0);
        $a = $a < 0 ? $a + 90.0 : $a;
        return $a > 45.0 ? $a - 90.0 : $a;
    }

    /** The straightened page's size in mm and inches, and the nearest common paper if it is close. */
    public static function describe(int $w, int $h, ?float $pxPerMm): array
    {
        $info = ['widthPx' => $w, 'heightPx' => $h, 'pxPerMm' => $pxPerMm];
        if (!$pxPerMm) {
            return $info;
        }
        [$wmm, $hmm] = [$w / $pxPerMm, $h / $pxPerMm];
        $info += ['widthMm' => round($wmm, 1), 'heightMm' => round($hmm, 1), 'widthIn' => round($wmm / 25.4, 2), 'heightIn' => round($hmm / 25.4, 2)];
        [$short, $long] = [min($wmm, $hmm), max($wmm, $hmm)];
        $best = null;
        foreach (self::NOMINAL_MM as $name => [$s, $l]) {
            $d = abs($s - $short) + abs($l - $long);
            if ($best === null || $d < $best[1]) {
                $best = [$name, $d, $s, $l];
            }
        }
        if (abs($best[2] - $short) < 6 && abs($best[3] - $long) < 8) {
            $info['nominal'] = $best[0];
        }
        return $info;
    }

    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
