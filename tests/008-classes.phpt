--TEST--
Generated classes: factories, inheritance, constructors, properties, structs as arrays
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC1, FILLED};

function attempt(callable $f): void {
    try { $f(); echo "no error\n"; }
    catch (Throwable $e) { echo get_class($e), ': ', strtok($e->getMessage(), "\n"), "\n"; }
}

$gray = new Mat(64, 64, CV_8UC1, 100);
CV\rectangle($gray, [10, 10], [30, 30], 110, FILLED);

// A class OpenCV hands out through a factory; methods, and methods inherited from Algorithm.
$clahe = CV\createCLAHE(2.0, [8, 8]);
echo get_class($clahe), ' < ', get_parent_class($clahe), "\n";
var_dump($clahe->getClipLimit());
$clahe->setClipLimit(3.5);
echo $clahe->getClipLimit(), ' ', json_encode($clahe->getTilesGridSize()), ' ', json_encode($clahe->apply($gray)->shape()), "\n";
var_dump($clahe->empty());
attempt(fn () => new CV\CLAHE());
attempt(fn () => $clahe->apply('x', 1, 2, 3));

// A value class: overloaded constructor, read/write properties backed by C++ members.
$kp = new CV\KeyPoint(1.5, 2.5, 7.0);
echo json_encode([$kp->pt, $kp->size, $kp->angle, $kp->class_id]), "\n";
$kp->pt = [9, 8];
$kp->octave = 3;
echo json_encode((array) $kp), "\n";
var_dump(isset($kp->pt), isset($kp->nope));
attempt(function () use ($kp) { $kp->pt = 'x'; });
attempt(function () use ($kp) { $kp->nope = 1; });

// Static methods, overloaded on vector<KeyPoint> / vector<Point2f>, objects in and out of lists.
echo json_encode(CV\KeyPoint::convert([$kp, new CV\KeyPoint(1, 2, 3)])), "\n";
$made = CV\KeyPoint::convert([[1.0, 2.0], [3.0, 4.0]], 5.0);
echo count($made), ' ', get_class($made[1]), ' ', json_encode($made[1]->pt), ' ', $made[1]->size, "\n";

// PHP subclasses are accepted wherever the base class is.
class MyPoint extends CV\KeyPoint {}
echo json_encode(CV\KeyPoint::convert([new MyPoint(4, 5, 1)])), "\n";

// A struct with no wrapped constructor is default-constructed, then filled in.
$params = new CV\ReduceParams();
$params->rtype = CV\REDUCE_SUM;
$params->axes = [0];
echo json_encode(CV\reduce($gray, $params)->shape()), "\n";

// CV_EXPORTS_W_MAP structs are plain arrays in both directions.
$m = CV\moments($gray);
echo count($m), ' ', $m['m00'], ' ', json_encode(CV\HuMoments($m)->shape()), "\n";

// Objects as arguments and return values; operator[] is exposed under its alias.
$file = sys_get_temp_dir() . '/opencv-php-test-' . getmypid() . '.yml';
$fs = new CV\FileStorage($file, CV\FileStorage_WRITE);
$fs->write('answer', 42);
$fs->write('name', 'opencv');
$fs->write('m', new Mat(2, 2, CV_8UC1, 7));
$fs->release();
$fs = new CV\FileStorage($file, CV\FileStorage_READ);
$node = $fs->getNode('answer');
echo get_class($node), ' ', $node->real(), ' ', $fs->getNode('name')->string(), ' ',
    json_encode($fs->getNode('m')->mat()->toArray()), ' ', json_encode($fs->root()->keys()), "\n";
$fs->release();
unlink($file);

$subdiv = new CV\Subdiv2D([0, 0, 100, 100]);
$subdiv->insert([[10.0, 10.0], [50.0, 20.0], [30.0, 70.0]]);
echo json_encode($subdiv->getTriangleList()), "\n";          // vector<Vec6f>

// An object whose constructor never ran is refused, not dereferenced.
attempt(fn () => (new ReflectionClass(CV\TickMeter::class))->newInstanceWithoutConstructor()->start());
attempt(fn () => clone $kp);
?>
--EXPECT--
CV\CLAHE < CV\Algorithm
float(2)
3.5 [8,8] [64,64]
bool(false)
Error: CV\CLAHE cannot be constructed directly; use the function that creates it
TypeError: CV\CLAHE::apply(): expects exactly 1 argument, 4 given
[[1.5,2.5],7,-1,-1]
{"pt":[9,8],"size":7,"angle":-1,"response":0,"octave":3,"class_id":-1}
bool(true)
bool(false)
TypeError: Cannot assign string to property CV\KeyPoint::$pt of type array [x, y]
Error: Cannot create dynamic property CV\KeyPoint::$nope
[[9,8],[1,2]]
2 CV\KeyPoint [3,4] 5
[[4,5]]
[1,64]
24 414010 [7,1]
CV\FileNode 42 opencv [[7,7],[7,7]] ["answer","name","m"]
[[10,10,50,20,30,70]]
Error: CV\TickMeter object has not been initialized by its constructor
Error: Trying to clone an uncloneable object of class CV\KeyPoint
