<?php
/**
 * Find the headstone in a handheld photograph and cut it out upright.
 *
 * The photographer frames one stone in the middle of the picture, with grass
 * around it, sky or trees above, and often other stones behind.  Stone is grey
 * or dark and unsaturated; grass, foliage and sky are not.  So: mark the
 * obvious vegetation and sky as background, mark the middle of the frame as
 * probably the stone, let GrabCut settle the rest, and keep the central blob.
 *
 * Then make it upright.  A headstone's sides are vertical, so when both sides
 * are visible as long near-vertical edges, a perspective warp makes them
 * vertical again (this undoes both the camera's roll and the keystone of
 * looking down at the stone).  Without both sides, level the stone on its
 * near-horizontal edges instead.
 *
 * Photographs with no stone (people, cars, landscape) are rejected, not cropped.
 */
declare(strict_types=1);

namespace Demo;

use CV\Mat;

use const CV\{BORDER_REPLICATE, CC_STAT_AREA, CC_STAT_HEIGHT, CC_STAT_LEFT, CC_STAT_TOP, CC_STAT_WIDTH, CMP_EQ,
    COLOR_BGR2GRAY, COLOR_BGR2HSV, CV_32F, CV_8UC1, GC_BGD, GC_INIT_WITH_MASK, GC_PR_BGD, GC_PR_FGD, IMREAD_COLOR,
    IMWRITE_JPEG_QUALITY, INTER_AREA, INTER_CUBIC, MORPH_CLOSE, MORPH_ELLIPSE, MORPH_OPEN};

final class StoneCrop
{
    /** Longest edge of the working copy; the crop itself is cut from the full frame. */
    private const WORK_EDGE = 900;

    /**
     * @return array{stone: bool, info: array<string,mixed>, overlay: string, jpeg?: string}
     */
    public static function fromBytes(string $image, int $quality = 92): array
    {
        $frame = \CV\imdecode($image, IMREAD_COLOR);
        if ($frame->empty()) {
            throw new \InvalidArgumentException('Not a decodable image');
        }
        $scale = self::WORK_EDGE / max($frame->rows(), $frame->cols());
        $small = \CV\resize($frame, [0, 0], $scale, $scale, INTER_AREA);

        $found = self::findStone($small);
        if ($found === null) {
            [, $overlay] = \CV\imencode('.jpg', $small, [IMWRITE_JPEG_QUALITY, 80]);
            return ['stone' => false, 'overlay' => $overlay, 'info' => ['reject' => 'no central object']];
        }
        [$x, $y, $w, $h] = $found['box'];
        $info = [
            'frame' => [$frame->cols(), $frame->rows()],
            'areaShare' => round($w * $h / ($small->cols() * $small->rows()), 3),
            'stoneFill' => round($found['fill'], 3),        // share of the box that GrabCut called stone
            'saturation' => round($found['saturation'], 1), // mean HSV saturation of the stone pixels, 0-255
            'edgesTouched' => $found['edges'],
            'method' => $found['method'],
        ];
        $reject = self::rejectReason($info);
        $color = $reject === null ? [0, 0, 255] : [0, 200, 255];
        $overlay = $small->clone();
        \CV\rectangle($overlay, [$x, $y], [$x + $w, $y + $h], $color, 3);
        [, $overlayJpeg] = \CV\imencode('.jpg', $overlay, [IMWRITE_JPEG_QUALITY, 80]);
        if ($reject !== null) {
            return ['stone' => false, 'overlay' => $overlayJpeg, 'info' => $info + ['reject' => $reject]];
        }

        // Back to full resolution, with a small margin so the carving at the edge survives.
        $pad = 0.02;
        $fx = max(0, (int) (($x - $w * $pad) / $scale));
        $fy = max(0, (int) (($y - $h * $pad) / $scale));
        $fw = min($frame->cols() - $fx, (int) ($w * (1 + 2 * $pad) / $scale));
        $fh = min($frame->rows() - $fy, (int) ($h * (1 + 2 * $pad) / $scale));
        $info['box'] = [$fx, $fy, $fw, $fh];

        $sides = self::findSides($small->roi($x, $y, $w, $h));
        if ($sides !== null) {
            // Make both sides vertical: a perspective warp of the crop, in full-resolution pixels.
            [$src, $dst] = self::keystoneQuads($sides, $w, $h);
            $k = 1 / $scale;
            $mul = fn (array $pts) => array_map(fn ($p) => [$p[0] * $k + ($x / $scale - $fx), $p[1] * $k + ($y / $scale - $fy)], $pts);
            $matrix = \CV\getPerspectiveTransform(Mat::fromArray($mul($src), CV_32F), Mat::fromArray($mul($dst), CV_32F));
            $crop = \CV\warpPerspective($frame->roi($fx, $fy, $fw, $fh), $matrix, [$fw, $fh], INTER_CUBIC, BORDER_REPLICATE);
            $info['upright'] = 'sides';
            $info['sideLeanDeg'] = [round($sides[0]['lean'], 2), round($sides[1]['lean'], 2)];
        } else {
            $tilt = self::tiltDegrees($small->roi($x, $y, $w, $h));
            if (abs($tilt) >= 0.3) {
                // Rotate the whole frame about the box centre, then cut: the corners get real pixels.
                $matrix = \CV\getRotationMatrix2D([$fx + $fw / 2, $fy + $fh / 2], $tilt, 1.0);
                $frame = \CV\warpAffine($frame, $matrix, [$frame->cols(), $frame->rows()], INTER_CUBIC, BORDER_REPLICATE);
            }
            $crop = $frame->roi($fx, $fy, $fw, $fh)->clone();
            $info['upright'] = abs($tilt) >= 0.3 ? 'level' : 'as shot';
            $info['tiltDeg'] = round($tilt, 2);
        }
        [$ok, $jpeg] = \CV\imencode('.jpg', $crop, [IMWRITE_JPEG_QUALITY, $quality]);
        if (!$ok) {
            throw new \RuntimeException('Could not encode the crop');
        }
        return ['stone' => true, 'jpeg' => $jpeg, 'overlay' => $overlayJpeg, 'info' => $info];
    }

    /** Why this is not a stone, or null.  Thresholds were set by eye on 119 St. Peter's photos. */
    public static function rejectReason(array $info): ?string
    {
        if ($info['stoneFill'] < 0.5) {
            return 'ragged shape';              // people, cars: GrabCut keeps a loose, irregular blob
        }
        if ($info['saturation'] > 95) {
            return 'too colourful';             // flags, flowers; red granite reaches ~80
        }
        if ($info['edgesTouched'] >= 2 && $info['stoneFill'] < 0.7) {
            return 'spills off the frame';
        }
        return null;
    }

    /** @return array{box: array{int,int,int,int}, fill: float, saturation: float, edges: int}|null */
    public static function findStone(Mat $small): ?array
    {
        [$rows, $cols] = [$small->rows(), $small->cols()];
        $hsv = \CV\cvtColor($small, COLOR_BGR2HSV);

        // Start: everything probably background, the middle of the frame probably stone.
        $mask = new Mat($rows, $cols, CV_8UC1, GC_PR_BGD);
        $cx0 = (int) ($cols * 0.18);
        $cy0 = (int) ($rows * 0.12);
        (new Mat($rows - 2 * $cy0, $cols - 2 * $cx0, CV_8UC1, GC_PR_FGD))
            ->copyTo($mask->roi($cx0, $cy0, $cols - 2 * $cx0, $rows - 2 * $cy0));

        // Certain background: green-to-yellow vegetation (also in shadow), and blue sky.
        $sure = new Mat($rows, $cols, CV_8UC1, GC_BGD);
        $sure->copyTo($mask, \CV\inRange($hsv, [22, 60, 25], [95, 255, 255]));
        $sure->copyTo($mask, \CV\inRange($hsv, [95, 35, 110], [130, 255, 255]));

        $bgd = new Mat();
        $fgd = new Mat();
        \CV\grabCut($small, $mask, [0, 0, 0, 0], $bgd, $fgd, 5, GC_INIT_WITH_MASK);

        $stone = \CV\bitwise_or(\CV\compare($mask, [1], CMP_EQ), \CV\compare($mask, [GC_PR_FGD], CMP_EQ));
        $found = self::centralBlob($stone, $hsv);
        if ($found === null) {
            // A stone that fills the frame, or a bright one, teaches GrabCut that stone is background.
            // Fall back to the plain colour rule: whatever is neither vegetation nor sky.
            $notScenery = \CV\bitwise_not(\CV\bitwise_or(
                \CV\inRange($hsv, [22, 60, 25], [95, 255, 255]),
                \CV\inRange($hsv, [95, 35, 110], [130, 255, 255]),
            ));
            $found = self::centralBlob($notScenery, $hsv);
            if ($found !== null) {
                $found['method'] = 'colour';
            }
        }
        return $found;
    }

    /** The blob that is big, central and grey; null when nothing is big enough. */
    private static function centralBlob(Mat $stone, Mat $hsv): ?array
    {
        [$rows, $cols] = [$stone->rows(), $stone->cols()];
        $stone = \CV\morphologyEx($stone, MORPH_OPEN, \CV\getStructuringElement(MORPH_ELLIPSE, [7, 7]));
        $stone = \CV\morphologyEx($stone, MORPH_CLOSE, \CV\getStructuringElement(MORPH_ELLIPSE, [21, 21]));

        // Score: area, discounted by distance from the middle and by colour (a flag beside the stone is central too).
        [$n, $labels, $stats, $centroids] = \CV\connectedComponentsWithStats($stone);
        $stats = $stats->toArray();
        $centroids = $centroids->toArray();
        $best = null;
        $diag = hypot($cols, $rows) / 2;
        for ($i = 1; $i < $n; $i++) {
            $area = $stats[$i][CC_STAT_AREA];
            if ($area < 0.02 * $rows * $cols) {
                continue;
            }
            $off = hypot($centroids[$i][0] - $cols / 2, $centroids[$i][1] - $rows / 2) / $diag;
            $blob = \CV\compare($labels, [$i], CMP_EQ);
            $saturation = \CV\mean($hsv, $blob)[1];
            $score = $area * (1 - $off) ** 2 * (1 - min(1.0, $saturation / 160)) ** 2;
            if ($best === null || $score > $best[0]) {
                $best = [$score, $i, $saturation];
            }
        }
        if ($best === null) {
            return null;
        }
        $s = $stats[$best[1]];
        [$x, $y, $w, $h] = [$s[CC_STAT_LEFT], $s[CC_STAT_TOP], $s[CC_STAT_WIDTH], $s[CC_STAT_HEIGHT]];
        $edges = (int) ($x <= 2) + (int) ($y <= 2) + (int) ($x + $w >= $cols - 2) + (int) ($y + $h >= $rows - 2);
        return [
            'box' => [$x, $y, $w, $h],
            'fill' => $s[CC_STAT_AREA] / ($w * $h),
            'saturation' => $best[2],
            'edges' => $edges,
            'method' => 'grabcut',
        ];
    }

    /**
     * The stone's left and right sides: the longest near-vertical edge in the
     * left third and in the right third of the box.  Null unless both are found
     * and they lean differently enough to be worth a perspective correction.
     *
     * @return array{0: array{x0: float, x1: float, lean: float}, 1: array{x0: float, x1: float, lean: float}}|null
     *         each side as its x at the top (y = 0) and bottom (y = h) of the box
     */
    public static function findSides(Mat $stone): ?array
    {
        [$rows, $cols] = [$stone->rows(), $stone->cols()];
        $gray = \CV\GaussianBlur(\CV\cvtColor($stone, COLOR_BGR2GRAY), [3, 3], 0);
        $lines = \CV\HoughLinesP(\CV\Canny($gray, 50, 150), 1, M_PI / 360, 40, max(20, (int) ($rows * 0.3)), 6)->toArray();
        $best = [null, null];
        foreach ($lines as [$x1, $y1, $x2, $y2]) {
            if ($y1 === $y2) {
                continue;
            }
            $lean = rad2deg(atan2($x2 - $x1, $y2 - $y1));   // 0 = vertical; signed, top-to-bottom
            $lean = $lean > 90 ? $lean - 180 : ($lean < -90 ? $lean + 180 : $lean);
            if (abs($lean) > 12) {
                continue;
            }
            $mid = ($x1 + $x2) / 2;
            $side = $mid < $cols / 3 ? 0 : ($mid > 2 * $cols / 3 ? 1 : null);
            if ($side === null) {
                continue;
            }
            $len = hypot($x2 - $x1, $y2 - $y1);
            if ($best[$side] === null || $len > $best[$side]['len']) {
                $slope = ($x2 - $x1) / ($y2 - $y1);
                $best[$side] = ['x0' => $x1 - $y1 * $slope, 'x1' => $x1 + ($rows - $y1) * $slope, 'lean' => $lean, 'len' => $len];
            }
        }
        if ($best[0] === null || $best[1] === null) {
            return null;
        }
        // Sides parallel already: nothing to undo.  Wildly different: probably not the stone's sides.
        $diff = abs($best[0]['lean'] - $best[1]['lean']);
        $mean = abs($best[0]['lean'] + $best[1]['lean']) / 2;
        if (($diff < 1.0 && $mean < 1.0) || $diff > 10) {
            return null;
        }
        return $best;
    }

    /** Source and target corner points (TL, TR, BR, BL) that make both sides vertical. */
    private static function keystoneQuads(array $sides, int $w, int $h): array
    {
        [$l, $r] = $sides;
        $src = [[$l['x0'], 0], [$r['x0'], 0], [$r['x1'], $h], [$l['x1'], $h]];
        // Keep the stone's width at its middle height, centred where it was.
        $lx = ($l['x0'] + $l['x1']) / 2;
        $rx = ($r['x0'] + $r['x1']) / 2;
        $dst = [[$lx, 0], [$rx, 0], [$rx, $h], [$lx, $h]];
        return [$src, $dst];
    }

    /**
     * Rotation (degrees, OpenCV sign) that levels the stone: the length-weighted
     * median angle of the near-horizontal edges below the top of the stone.
     * 0 when there are too few, or they disagree.
     */
    public static function tiltDegrees(Mat $stone): float
    {
        $gray = \CV\GaussianBlur(\CV\cvtColor($stone, COLOR_BGR2GRAY), [3, 3], 0);
        $edges = \CV\Canny($gray, 50, 150);
        $minLen = max(20, (int) ($stone->cols() * 0.25));
        $lines = \CV\HoughLinesP($edges, 1, M_PI / 360, 40, $minLen, 6)->toArray();
        $angles = [];
        $rows = $stone->rows();
        foreach ($lines as $line) {
            [$x1, $y1, $x2, $y2] = $line;     // 1 x N, four channels: toArray() gives [[x1, y1, x2, y2], ...]
            // The top of a headstone is often curved or sloped; trust the carving and the base below it.
            if (($y1 + $y2) / 2 < 0.2 * $rows) {
                continue;
            }
            $deg = rad2deg(atan2($y2 - $y1, $x2 - $x1));
            if (abs($deg) <= 8) {
                $angles[] = [$deg, hypot($x2 - $x1, $y2 - $y1)];
            }
        }
        if (count($angles) < 3) {
            return 0.0;
        }
        $median = self::weightedMedian($angles);
        // Lines that disagree mean the stone has no clear horizontal: leave it as photographed.
        $spread = self::weightedMedian(array_map(fn ($a) => [abs($a[0] - $median), $a[1]], $angles));
        return $spread > 1.5 ? 0.0 : $median;
    }

    /** @param list<array{float, float}> $values [value, weight] */
    private static function weightedMedian(array $values): float
    {
        usort($values, fn ($a, $b) => $a[0] <=> $b[0]);
        $half = array_sum(array_column($values, 1)) / 2;
        foreach ($values as [$value, $weight]) {
            if (($half -= $weight) <= 0) {
                return $value;
            }
        }
        return end($values)[0];
    }
}
