--TEST--
Overload resolution by argument type, TypeError and CV\Exception
--EXTENSIONS--
opencv
--FILE--
<?php
use CV\Mat;
use const CV\{CV_8UC1, CV_8UC3, COLOR_BGR2GRAY};

$img = new Mat(50, 50, CV_8UC1);

// rectangle(img, pt1, pt2, color) and rectangle(img, rect, color)
CV\rectangle($img, [0, 0], [9, 9], 255, -1);
CV\rectangle($img, [20, 20, 10, 10], 255, -1);
echo CV\countNonZero($img), "\n";

function attempt(callable $f): void {
    try { $f(); echo "no error\n"; }
    catch (Throwable $e) { echo get_class($e), ': ', strtok($e->getMessage(), "\n"), "\n"; }
}
attempt(fn () => CV\resize($img, 'big'));
attempt(fn () => CV\resize($img));
attempt(fn () => CV\resize(42, [1, 1]));
attempt(fn () => CV\rectangle([[1, 2]], [0, 0], [1, 1], 255));
attempt(fn () => CV\rectangle($img));
attempt(fn () => CV\threshold($img, 'x', 255, 0));
attempt(fn () => CV\resize($img, [10, 10], bogus: 1));

// Errors raised inside OpenCV carry its message and error code.
try {
    CV\cvtColor($img, COLOR_BGR2GRAY);
} catch (CV\Exception $e) {
    var_dump($e->getCode() === CV\Error\StsBadArg || $e->getCode() < 0, str_contains($e->getMessage(), 'OpenCV('));
}

$r = new ReflectionFunction('CV\resize');
echo $r->getNumberOfRequiredParameters(), '/', $r->getNumberOfParameters(), ' ',
    implode(',', array_map(fn ($p) => $p->getName(), $r->getParameters())), "\n";
?>
--EXPECT--
200
TypeError: CV\resize(): Argument #2 ($dsize) must be of type array [width, height], string given
TypeError: CV\resize(): expects 2 to 5 arguments, 1 given
TypeError: CV\resize(): Argument #1 ($src) must be of type CV\Mat|array|string, int given
TypeError: CV\rectangle(): Argument #1 ($img) must be of type CV\Mat, array given
TypeError: CV\rectangle(): expects 4 to 7 arguments, 1 given
TypeError: CV\threshold(): Argument #2 ($thresh) must be of type float, string given
Error: Unknown named parameter $bogus
bool(true)
bool(true)
2/5 src,dsize,fx,fy,interpolation
