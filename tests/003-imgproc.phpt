--TEST--
Generated imgproc/core functions: outputs are returned, in/out Mats change in place
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC3, COLOR_BGR2GRAY, THRESH_BINARY, INTER_AREA, FILLED};

$img = new Mat(120, 200, CV_8UC3, [30, 60, 90]);

// In/out argument: drawn on the object itself, returns null.
var_dump(CV\rectangle($img, [40, 30], [120, 90], [255, 255, 255], FILLED));
echo json_encode($img->toArray()[50][50]), "\n";

// One output: returned directly.
$gray = CV\cvtColor($img, COLOR_BGR2GRAY);
echo json_encode($gray->shape()), " ", $gray->channels(), " ", $gray->at(0, 0), " ", $gray->at(50, 50), "\n";

// Return value plus an output: a list.
[$t, $bin] = CV\threshold($gray, 200, 255, THRESH_BINARY);
var_dump($t);
echo CV\countNonZero($bin), "\n";

// Pointer outputs (double*, Point*) come back too.
echo json_encode(CV\minMaxLoc($gray)), "\n";
echo json_encode(CV\mean($gray)), "\n";

// Optional and named arguments; C++ defaults apply to what is left out.
echo json_encode(CV\resize($img, [100, 60])->size()), "\n";
echo json_encode(CV\resize($img, [0, 0], 0.5, 0.25)->size()), "\n";
echo json_encode(CV\resize($img, [50, 30], interpolation: INTER_AREA)->size()), "\n";

// vector<Mat> in and out.
$channels = CV\split($img);
echo count($channels), " ", $channels[2]->at(0, 0), "\n";
echo json_encode(CV\merge(array_reverse($channels))->toArray()[0][0]), "\n";

// A number as the second operand of an arithmetic function.
echo CV\add($gray, 10)->at(0, 0), " ", CV\subtract($gray, 100)->at(0, 0), "\n";

// PHP lists as matrices: rows of numbers, floats give CV_64F.
$shifted = CV\warpAffine($img, [[1.0, 0, 10], [0, 1, 5]], [200, 120]);
echo json_encode([$shifted->toArray()[0][0], $shifted->toArray()[5][10]]), "\n";
echo json_encode(CV\getRotationMatrix2D([0, 0], 90, 1.0)->shape()), "\n";
echo json_encode(CV\GaussianBlur($gray, [5, 5], 1.5)->shape()), "\n";
?>
--EXPECT--
NULL
[255,255,255]
[120,200] 1 66 255
float(200)
4941
[66,255,[0,0],[40,30]]
[104.910375,0,0,0]
[100,60]
[100,30]
[50,30]
3 90
[90,60,30]
76 0
[[0,0,0],[30,60,90]]
[2,3]
[120,200]
