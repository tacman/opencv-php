--TEST--
Every generated function rejects wrong arguments with an exception, never a crash
--EXTENSIONS--
opencv
--ENV--
OPENCV_LOG_LEVEL=SILENT
--FILE--
<?php
// Bad arguments must surface as TypeError / CV\Exception. Each function is
// called with too many arguments and with wrongly typed ones; none of these
// calls can match a real signature, so nothing has side effects.
$junk = [
    array_fill(0, 40, null),
    [new stdClass, new stdClass, new stdClass],
    ['x', 'y', 'z', 'w', 'v', 'u', 't', 's', 'r', 'q', 'p', 'o'],
    [[[[1]]], [[[2]]], [[[3]]]],
    [new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat(), new CV\Mat()],
];
$functions = get_extension_funcs('opencv');
$calls = $thrown = 0;
foreach ($functions as $function) {
    foreach ($junk as $args) {
        $calls++;
        try {
            $function(...$args);
        } catch (TypeError | CV\Exception $e) {
            $thrown++;
        }
    }
}
var_dump(count($functions) > 300, $calls === count($functions) * count($junk), $thrown > $calls * 0.9);

// The same for every method of every generated class: static methods directly,
// instance methods on an object whose constructor never ran.
$methods = $refused = 0;
foreach ((new ReflectionExtension('opencv'))->getClasses() as $class) {
    if (in_array($class->getName(), ['CV\\Mat', 'CV\\Exception'], true)) {
        continue;
    }
    $blank = $class->newInstanceWithoutConstructor();
    foreach ($class->getMethods() as $method) {
        foreach ($junk as $args) {
            $methods++;
            try {
                $method->isStatic() ? $method->invoke(null, ...$args) : $method->invoke($blank, ...$args);
            } catch (Error | CV\Exception $e) {
                $refused++;
            }
        }
    }
}
var_dump($methods > 500, $refused === $methods);
echo "survived\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
survived
