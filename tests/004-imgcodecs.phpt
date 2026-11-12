--TEST--
imgcodecs: encode to a PHP string, decode from one, write and read files
--EXTENSIONS--
opencv
--ENV--
OPENCV_LOG_LEVEL=SILENT
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC3, IMREAD_GRAYSCALE, IMREAD_UNCHANGED, IMWRITE_JPEG_QUALITY};

$img = new Mat(40, 60, CV_8UC3, [10, 20, 30]);

[$ok, $png] = CV\imencode('.png', $img);
var_dump($ok, is_string($png), substr($png, 1, 3));
$back = CV\imdecode($png, IMREAD_UNCHANGED);
var_dump($back->data() === $img->data());
echo json_encode(CV\imdecode($png, IMREAD_GRAYSCALE)->shape()), "\n";

// vector<int> parameter list.
[, $q90] = CV\imencode('.jpg', $img, [IMWRITE_JPEG_QUALITY, 90]);
var_dump(bin2hex(substr($q90, 0, 3)));

$file = sys_get_temp_dir() . '/opencv-php-test-' . getmypid() . '.png';
var_dump(CV\imwrite($file, $img));
$read = CV\imread($file);
echo json_encode($read->shape()), " ", json_encode($read->toArray()[0][0]), "\n";
unlink($file);

// A missing file is an empty Mat, as in C++.
var_dump(CV\imread('/nonexistent/x.png')->empty());
var_dump(CV\haveImageReader('/nonexistent/x.png'));
?>
--EXPECT--
bool(true)
bool(true)
string(3) "PNG"
bool(true)
[40,60]
string(6) "ffd8ff"
bool(true)
[40,60] [10,20,30]
bool(true)
bool(false)
