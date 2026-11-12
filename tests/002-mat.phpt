--TEST--
CV\Mat: construction, element access, bytes, views, clone
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC1, CV_8UC3, CV_32FC1};

$m = new Mat(2, 3, CV_8UC3, [1, 2, 3]);
echo json_encode([$m->rows(), $m->cols(), $m->channels(), $m->type() === CV_8UC3, $m->total(), $m->elemSize(), $m->empty()]), "\n";
echo json_encode($m->shape()), json_encode($m->size()), "\n";
echo json_encode($m->toArray()[1][2]), "\n";

// Zero-filled by default, never uninitialised.
echo bin2hex((new Mat(2, 2))->data()), "\n";
var_dump((new Mat())->empty());

$m->set(0, 1, 300, 2);            // saturates to 255
$m->set(0, 1, 7);
echo json_encode([$m->at(0, 1), $m->at(0, 1, 1), $m->at(0, 1, 2)]), "\n";

$g = Mat::fromString("\x00\x01\x02\x03\x04\x05", 2, 3);
echo json_encode($g->toArray()), "\n";
var_dump($g->data() === "\x00\x01\x02\x03\x04\x05");

// roi() is a view: writing through it changes the parent.
$roi = $g->roi(1, 0, 2, 2);
$roi->set(0, 0, 99);
echo $g->at(0, 1), " ", json_encode($roi->toArray()), " ", bin2hex($roi->data()), "\n";

// clone copies.
$c = clone $g;
$c->set(0, 0, 50);
$d = $g->clone();
$d->set(0, 0, 60);
echo $g->at(0, 0), " ", $c->at(0, 0), " ", $d->at(0, 0), "\n";

$f = $g->convertTo(CV_32FC1, 0.5);
var_dump($f->at(1, 2));
echo json_encode($g->reshape(1, 3)->shape()), "\n";

try { $g->at(5, 5); } catch (CV\Exception $e) { echo $e->getMessage(), "\n"; }
try { Mat::fromString("abc", 2, 2); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { new Mat(-1, 2); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
?>
--EXPECT--
[2,3,3,true,6,3,false]
[2,3][3,2]
[1,2,3]
00000000
bool(true)
[7,2,255]
[[0,1,2],[3,4,5]]
bool(true)
99 [[99,2],[4,5]] 63020405
0 50 60
float(2.5)
[3,2]
Element (5, 5, 0) is outside a 2x3 matrix with 1 channels
CV\Mat::fromString(): expected 4 bytes for 2x2 of this type, got 3
Mat dimensions must be between 0 and 2147483647
