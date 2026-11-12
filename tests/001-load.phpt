--TEST--
Extension loads against OpenCV 5 and exposes classes, functions and constants
--EXTENSIONS--
opencv
--FILE--
<?php
var_dump(extension_loaded('opencv'));
var_dump(version_compare(CV\getVersionString(), '5.0.0', '>='));
var_dump(class_exists(CV\Mat::class), is_subclass_of(CV\Exception::class, Exception::class));
var_dump(function_exists('CV\imread'), function_exists('CV\cvtColor'), function_exists('CV\contourArea'));
// OpenCV 5 widened the depth field: CV_8UC3 is 64 here, it was 16 in OpenCV 4.
var_dump(CV\CV_8UC3, CV\CV_32FC1, CV\COLOR_BGR2GRAY, CV\INTER_LINEAR, CV\BORDER_CONSTANT);
var_dump(count(get_extension_funcs('opencv')) > 300);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(64)
int(5)
int(6)
int(1)
int(0)
bool(true)
