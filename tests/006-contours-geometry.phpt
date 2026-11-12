--TEST--
Contours end to end: vector<Mat> output, geometry functions, struct-like returns
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC1, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE, FILLED};

$img = new Mat(100, 100, CV_8UC1);
CV\rectangle($img, [10, 20], [49, 59], 255, FILLED);
CV\circle($img, [75, 75], 10, 255, FILLED);

[$contours, $hierarchy] = CV\findContours($img, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);
usort($contours, fn ($a, $b) => CV\contourArea($b) <=> CV\contourArea($a));
echo count($contours), " ", json_encode($hierarchy->shape()), " ", $hierarchy->channels(), "\n";

$box = $contours[0];
echo json_encode($box->toArray()), "\n";
var_dump(CV\contourArea($box));
echo json_encode(CV\boundingRect($box)), "\n";                 // Rect -> [x, y, w, h]
echo json_encode(CV\minAreaRect($box)[1]), "\n";               // RotatedRect -> [[cx,cy],[w,h],angle]
var_dump(CV\arcLength($box, true));
[$center, $radius] = CV\minEnclosingCircle($contours[1]);      // Point2f + float outputs
echo json_encode(array_map('intval', array_map('round', $center))), " ", $radius > 9 && $radius < 12 ? 'ok' : $radius, "\n";

// A PHP list of points is accepted wherever a contour is.
echo json_encode(CV\boundingRect([[5, 5], [15, 8], [9, 30]])), "\n";
var_dump(CV\pointPolygonTest($box, [30, 40], false), CV\pointPolygonTest($box, [0, 0], false));

// Draw the contours back (vector<Mat> input).
$canvas = new Mat(100, 100, CV_8UC1);
CV\drawContours($canvas, $contours, -1, 255, FILLED);
var_dump(CV\countNonZero($canvas) === CV\countNonZero($img));
?>
--EXPECT--
2 [1,2] 4
[[[10,20]],[[10,59]],[[49,59]],[[49,20]]]
float(1521)
[10,20,40,40]
[39,39]
float(156)
[75,75] ok
[5,5,11,26]
float(1)
float(-1)
bool(true)
