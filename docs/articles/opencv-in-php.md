# OpenCV 5 in PHP, Without a Python Service in Between

*A PHP extension generated from OpenCV's own headers: what it covers, what it measured, and what is still missing.*

For years I've been jealous of the Python community, in part because they have OpenCV. We do a lot of image processing and analysis, and I kept writing little Python scripts and endpoints, and running servers, that existed only as a bridge to OpenCV. Mostly it was a pain to call out to Python.

Three things changed that: the release of OpenCV 5, the tooling OpenCV ships for generating bindings, and AI tools that make it much easier to develop a PHP extension. So I wrote the extension I had played with and hoped for, for years: [survos/opencv-php](https://github.com/survos/opencv-php).

PHP has ImageMagick and GD, and they do basic image processing. They don't do image recognition, and that's where OpenCV shines.

## A first example: four prints, one scan

Lay several photo prints on a dark surface, take one picture of all of them, and get one upright file per print. Nobody lays prints down straight, so each one has to be found and turned.

```php
<?php
use const CV\{COLOR_BGR2GRAY, THRESH_BINARY, THRESH_OTSU, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE, INTER_CUBIC};

$scan = CV\imread($argv[1]);
$gray = CV\GaussianBlur(CV\cvtColor($scan, COLOR_BGR2GRAY), [5, 5], 0);
[, $mask] = CV\threshold($gray, 0, 255, THRESH_BINARY + THRESH_OTSU);     // prints are lighter than the mat
[$contours] = CV\findContours($mask, RETR_EXTERNAL, CHAIN_APPROX_SIMPLE);

$n = 0;
foreach ($contours as $outline) {
    if (CV\contourArea($outline) < 0.02 * $scan->rows() * $scan->cols()) {
        continue;                                                         // dust, not a print
    }
    [$center, [$w, $h], $angle] = CV\minAreaRect($outline);               // the tilted rectangle around it
    if (abs($angle) > 45) {                                               // keep the turn small
        [$w, $h, $angle] = [$h, $w, $angle - 90 * ($angle <=> 0)];
    }
    $upright = CV\warpAffine($scan, CV\getRotationMatrix2D($center, $angle, 1.0), $scan->size(), INTER_CUBIC);
    $print   = CV\getRectSubPix($upright, [(int) $w, (int) $h], $center);
    CV\imwrite(sprintf('print-%d.jpg', ++$n), $print);
    printf("print-%d.jpg  %d x %d px, was tilted %.1f degrees\n", $n, $w, $h, $angle);
}
```

```
print-1.jpg  848 x 598 px, was tilted -3.0 degrees
print-2.jpg  599 x 848 px, was tilted 9.0 degrees
print-3.jpg  848 x 594 px, was tilted -7.0 degrees
print-4.jpg  848 x 586 px, was tilted 4.0 degrees
```

Read it top to bottom: make the picture grey, let Otsu's method pick the brightness that separates print from background, trace the outline of each bright area, fit the smallest tilted rectangle around each outline, turn the picture by that rectangle's angle, and cut the rectangle out.

If you have written cv2 code in Python, this will look familiar, and that is the point. The function names and argument order are OpenCV's. The differences are PHP ones: output arguments are returned (several come back as a list), and points, sizes and rectangles are plain arrays.

## What was there before

When I looked for OpenCV in PHP, this is what I found:

- [hihozhou/php-opencv](https://github.com/hihozhou/php-opencv), a hand-written extension. Its README says it is no longer in active development and lists OpenCV 4.0.0+ as the requirement. The last push was in May 2020.
- [php-opencv/php-opencv](https://github.com/php-opencv/php-opencv), described as "opencv 4.5+ with dnn module for php 7/8". The repository is archived; the last push was in December 2023.
- [Flyingmana/php-openvc](https://github.com/Flyingmana/php-openvc), a fork of the first that was active this month. Its README says it aims at PHP 8.5 compatibility, that the work is in progress, and it builds against OpenCV 4.
- [ghostjat/php_opencv](https://github.com/ghostjat/php_opencv), described as a php-ffi experiment, last pushed in January 2021.
- A long trail of forum questions about running a cv2 script from PHP with `exec` or `shell_exec`, [like this one](https://answers.opencv.org/question/139111/cv2imwrite-function-not-working-via-php-server/), which is the route I took myself, with an HTTP service instead of a shell call.

I did not find a PHP binding for OpenCV 5.

## Generated, not hand-written

OpenCV has thousands of functions. The official Python, Java and JavaScript bindings are not written by hand: OpenCV marks its C++ headers with annotations, and a parser that ships with OpenCV (`hdr_parser.py`) reads them.

opencv-php uses that same parser, unmodified, to dump the declarations to a JSON file that is committed to the repository. A PHP script then turns the JSON into the C++ source of the extension. A function is wrapped when every type in its signature has a converter; otherwise it is skipped and listed, with the reason, in `api/coverage.json`.

Today that gives 340 functions, 39 classes with 275 methods, and 932 constants, across five modules: core, imgproc, imgcodecs, geometry and objdetect. The generated C++ is committed too, so installing the extension needs neither Python nor the generator.

## The real test: porting a tool we already run

The example above is a small version of something we run. At a document scanning station, an overhead camera photographs sheets of paper lying on a dark mat. One step finds the sheet in the frame, cuts it out upright, and works out its size in millimetres. That step lived in a Python image service, called over HTTP from PHP.

I ported it call for call; it is in the repository as `demo/src/Straighten.php`. Then I ran it over 154 real frames from the station, the largest of them 48 megapixels. Every JPEG the PHP version produced is byte-identical to the stored crop for that frame, and the speed is the same, because the time is OpenCV's either way. The PHP application now does this step in-process and keeps the Python service only as a fallback.

## 190 ms on every PHP start, then 7

The first build had a cost I did not expect. Linked against Homebrew's full OpenCV, the extension added about 190 ms to every PHP start, including `php -v`. The reason: the full `objdetect` library links to `dnn`, OpenVINO and protobuf, about 90 libraries in all, and they are all loaded at start.

Two changes fixed it. The repository has a script, `bin/build-opencv-lean.sh`, that builds OpenCV with only the five wrapped modules (about 8 minutes). And the extension's `config.m4` had a link setting that made macOS resolve symbols slowly. With both, the extension adds 7 ms on macOS and 3 ms on Linux, so it can be enabled for every PHP process.

## Reading QR codes

OpenCV can write and read QR codes, so this round trip needs no other library:

```php
<?php
use const CV\{INTER_NEAREST, BORDER_CONSTANT};

$qr  = CV\QRCodeEncoder::create()->encode('https://github.com/survos/opencv-php');
$big = CV\resize($qr, [0, 0], 8, 8, INTER_NEAREST);                 // 8 px per module
$big = CV\copyMakeBorder($big, 32, 32, 32, 32, BORDER_CONSTANT, 255); // white quiet zone
CV\imwrite('qr.png', $big);

$start = hrtime(true);
[$text, $corners] = (new CV\QRCodeDetector())->detectAndDecode(CV\imread('qr.png'));
printf("%s in %.1f ms\n", $text, (hrtime(true) - $start) / 1e6);
```

```
https://github.com/survos/opencv-php in 6.4 ms
```

I compared it with the pure-PHP reader in chillerlan/php-qrcode on an M4 Pro:

| Measurement | OpenCV from PHP | Other |
|---|---|---|
| Decode a clean QR code | 6 ms | 21 ms (php-qrcode with Imagick), 31 ms (with GD) |
| Decode a QR code in a 12 MP frame | 22 ms, after shrinking the frame to half size | 1,779 ms (php-qrcode) |
| Straighten one overhead scan, average inside one process | 65 ms | about the same in Python |
| Added to every PHP start, full Homebrew OpenCV | 190 ms | |
| Added to every PHP start, lean OpenCV | 7 ms macOS, 3 ms Linux | |

One result in that table needs its caveat. On the full-size 12 MP frame, with the code on a noisy synthetic background, OpenCV did not decode the code at all. It decoded after I shrank the frame to half size. Faster is not the same as more robust, and for large frames, resize first.

## Under FrankenPHP

FrankenPHP runs thread-safe PHP, and the extension builds for it. The repository has a Dockerfile on `dunglas/frankenphp:php8.5` that builds the lean OpenCV, builds the extension, and serves the straightening demo over HTTP. Measured on arm64 (Debian 13, PHP 8.5 ZTS): 343 ms server time for one 48 MP frame, 12 requests sent 6 at a time all returned identical bytes with nothing logged, and the image is 666 MB against 629 MB for the base image.

Two things to know when it runs for real. The pixels are OpenCV's memory and are not counted by `memory_limit`; a 48 MP frame is about 143 MB decoded. And OpenCV uses several threads inside one call, so with several workers on one machine, call `CV\setNumThreads()` with a small number.

## What is not there yet

- **It is not on Packagist.** PIE can install it from a checkout today, not by name.
- **amd64 is unbuilt.** Everything above was built on arm64: macOS, and Debian 13 in a container.
- **No `dnn` or `features` modules,** so no neural-network face detection and no ORB or SIFT. No video.
- FrankenPHP worker mode, a leak check under a debug build of PHP, and a long soak are not done.
- The README calls it a proof of concept. There are nine test files, and they pass.

## Try it

You need PHP 8.3 or later (I build on 8.5), OpenCV 5, and a C++17 compiler. On macOS:

```bash
brew install opencv cmake
git clone https://github.com/survos/opencv-php && cd opencv-php
bin/build-opencv-lean.sh ~/.local/opencv-lean
export PKG_CONFIG_PATH=~/.local/opencv-lean/lib/pkgconfig
phpize && ./configure --with-opencv && make && make test
```

Or with [PIE](https://github.com/php/pie), which is what made me want this to be easy in the first place; installing the DuckDB PHP driver with PIE was the model:

```bash
pie repository:add path /path/to/opencv-php
pie install 'survos/opencv-php:*@dev'
```

Linux distributions still ship OpenCV 4, so there you build OpenCV 5 from source; the lean build script and the Dockerfile both do that.

The code, the coverage list and the FrankenPHP notes are at [github.com/survos/opencv-php](https://github.com/survos/opencv-php). If a function you need was skipped, `api/coverage.json` says why.

---

**Image suggestions**

1. For the first example: the scan next to the four prints it produced (`img/scan.jpg` and `img/print-1.jpg` to `print-4.jpg`). The photos are from DOCUMERICA, the EPA's 1970s photo project held by the National Archives.
2. For the port: one overhead frame (a sheet on the dark mat) next to the straightened crop. Use a sheet with nothing private on it.
