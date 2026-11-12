--TEST--
objdetect: ArUco markers and QR codes round trip (namespaced classes, class arguments, vector<Mat> output)
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC1, INTER_NEAREST};
use const CV\aruco\DICT_4X4_100;

// Draw two ArUco markers on a white page and find them again.
$dictionary = CV\aruco\getPredefinedDictionary(DICT_4X4_100);
$page = new Mat(400, 600, CV_8UC1, 255);
foreach ([[12, 50, 60], [37, 350, 180]] as [$id, $x, $y]) {
    $marker = CV\aruco\generateImageMarker($dictionary, $id, 120);
    $marker->copyTo($page->roi($x, $y, 120, 120));
}
$detector = new CV\aruco\ArucoDetector($dictionary, new CV\aruco\DetectorParameters());
[$corners, $ids, $rejected] = $detector->detectMarkers($page);
$found = [];
foreach ($ids->toArray() as $i => $id) {          // a vector<int> is a 1-D Mat in OpenCV 5: a flat list
    $found[$id] = array_map(fn ($p) => array_map('intval', array_map('round', $p)), $corners[$i]->toArray()[0]);
}
ksort($found);
echo json_encode($found), "\n";
echo get_class($detector), ' ', get_parent_class($detector), ' ', get_class($detector->getDictionary()), "\n";

// Parameters are plain properties.
$params = new CV\aruco\DetectorParameters();
$params->adaptiveThreshWinSizeMin = 5;
echo $params->adaptiveThreshWinSizeMin, ' ', gettype($params->minMarkerPerimeterRate), "\n";

// QR: encode, enlarge, decode.
$qr = CV\QRCodeEncoder::create()->encode('opencv-php');
$big = CV\copyMakeBorder(CV\resize($qr, [0, 0], 8, 8, INTER_NEAREST), 32, 32, 32, 32, CV\BORDER_CONSTANT, 255);
[$text, $points] = (new CV\QRCodeDetector())->detectAndDecode($big);
echo $text, ' ', json_encode($points->shape()), "\n";
?>
--EXPECT--
{"12":[[50,60],[169,60],[169,179],[50,179]],"37":[[350,180],[469,180],[469,299],[350,299]]}
CV\aruco\ArucoDetector CV\Algorithm CV\aruco\Dictionary
5 double
opencv-php [1,4]
