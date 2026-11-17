# opencv-php

PHP bindings for **OpenCV 5**, generated from OpenCV's own header annotations —
the same source of truth the official Python, Java and JavaScript bindings use.

Status: proof of concept. Built and tested on PHP 8.5 with OpenCV 5.1.0: macOS arm64 (NTS) and Debian 13 arm64 under FrankenPHP (ZTS).

```php
$photo = CV\imread('photo.jpg');

$small = CV\resize($photo, [800, 600]);
$gray  = CV\cvtColor($small, CV\COLOR_BGR2GRAY);
CV\imwrite('photo-gray.jpg', $gray);

[$text] = (new CV\QRCodeDetector())->detectAndDecode(CV\imread('ticket.png'));   // read a QR code
```

Function names and arguments are OpenCV's own, so the OpenCV documentation and
any Python `cv2` example carry over. [`examples/`](examples/) has complete
scripts: straightening a leaning gravestone, splitting a scan of several
prints, reading QR codes and ArUco markers.

## What is covered

| Module | Functions | Classes | Methods |
|---|---|---|---|
| core | 133 | 7 | 59 |
| imgproc | 103 | 6 | 57 |
| geometry | 72 | 3 | 18 |
| imgcodecs | 19 | 1 | 1 |
| objdetect | 13 | 22 | 140 |

340 functions, 39 classes with 275 methods, and 932 enum constants.
`api/coverage.json` lists every wrapped and skipped declaration with the reason.

```php
$clahe = CV\createCLAHE(2.0, [8, 8]);          // CV\CLAHE extends CV\Algorithm
$clahe->setClipLimit(3.5);
$equalized = $clahe->apply($gray);

$kp = new CV\KeyPoint(1.5, 2.5, 7.0);
$kp->pt = [9, 8];                               // properties are the C++ members
$points = CV\KeyPoint::convert([$kp]);

$fs = new CV\FileStorage('params.yml', CV\FileStorage_READ);
$matrix = $fs->getNode('camera_matrix')->mat();
```

## Demo

`demo/` ports a real tool from a Python (cv2) service: find the sheet of paper
in an overhead scan, cut it out upright, and measure it against the ArUco
markers of a printed bracket. On the same 48 MP frames its JPEG output is
byte-identical to the Python original, at the same speed.

```bash
php -d extension=opencv demo/straighten.php /path/to/frames /tmp/straight
```

`docs/frankenphp.md` covers running it under FrankenPHP
(`docker/frankenphp.Dockerfile`), with measurements.

## Examples

`examples/src/` holds the examples: each is a method that takes a picture and
returns the result, marked with an `#[Example]` attribute that carries its
title, description and input picture.

```php
#[Example(
    title: 'Crop every coin',
    description: 'Coins on a blue cloth: whatever is not blue is an object. Keep the round ones, and cut a square around each.',
    input: 'images/coins.jpg',
)]
public function cropCoins(Mat $photo): array
```

```bash
php examples/run.php                      # list them
php examples/run.php all                  # run them; results in examples/out/
php examples/run.php objects.cropcoins    # run one
```

| Example | What it does |
|---|---|
| `gravestones.straighten` | Finds a headstone's leaning sides and warps the crop so they are vertical |
| `gravestones.cutout` | Cuts the stone out of its background with GrabCut |
| `gravestones.inscription` | Brings out worn lettering with CLAHE |
| `scans.splitprints` | Cuts a scan of several photo prints into one upright file per print |
| `scans.palette` | Finds a photograph's main colours with k-means |
| `objects.cropcoins` | Crops every coin out of a photograph |
| `codes.readqrcodes` | Finds and reads every QR code in a photograph |
| `codes.findarucomarkers` | Detects ArUco markers with corners and ids |
| `faces.detect`, `faces.match` | Find faces with YuNet; say which are the same person with SFace |

`site/` is a small Symfony app that turns the attributes into a page: a table
of contents, and for each example the picture before, the picture after, and
the PHP in between. It runs the examples when a result is missing or out of date.

```bash
cd site && composer install
php bin/console app:export ../../opencv-php-site     # the site as static files
```

The two face examples are ported from
[php-opencv/php-opencv-examples](https://github.com/php-opencv/php-opencv-examples)
by [morozovsk](https://github.com/morozovsk), written for the
[php-opencv](https://github.com/php-opencv/php-opencv) extension on OpenCV 4.
They need an OpenCV built with `dnn` (not the lean build below), models from
the [OpenCV zoo](https://github.com/opencv/opencv_zoo) and a photograph of
your own; `examples/src/Faces.php` says which. The rest of that collection
needs the `dnn` module or the contrib `face` and `xobjdetect` modules, which
are not wrapped yet.

The gravestone, coin and QR photographs are by Tac Tacelosky; the prints are
from DOCUMERICA, held by the U.S. National Archives.

## Install

Needs OpenCV 5 with its `opencv5.pc` visible to pkg-config, and a C++17 compiler.

```bash
brew install opencv        # 5.x; Linux distros still ship 4.x, build 5 from source there
phpize && ./configure --with-opencv && make && make test
```

With [PIE](https://github.com/php/pie):

```bash
pie install tacman/opencv-php
```

PIE builds the extension on your machine, so OpenCV 5 must already be installed
where `pkg-config` can find it: `brew install opencv` on macOS. Debian and
Ubuntu still package OpenCV 4; `docker/pie.Dockerfile` builds OpenCV 5 and then
runs the same `pie install`. To build from a checkout instead:
`pie repository:add path /path/to/opencv-php`, then
`pie install 'tacman/opencv-php:*@dev'`.

**Start-up cost.** A distribution's full OpenCV links `objdetect` to `dnn`,
OpenVINO and protobuf: about 90 libraries, 190 ms on every PHP start with
Homebrew's. `bin/build-opencv-lean.sh` builds OpenCV with only the wrapped
modules (about 8 minutes, needs `cmake`); against that the extension adds 7 ms
on macOS and 3 ms on Linux, so it can be enabled for every PHP process:

```bash
bin/build-opencv-lean.sh ~/.local/opencv-lean
PKG_CONFIG_PATH=~/.local/opencv-lean/lib/pkgconfig phpize && ./configure --with-opencv && make && make install
```

## How values map

| OpenCV | PHP |
|---|---|
| `Mat`, `InputArray` | `CV\Mat`; also a list of numbers, a list of rows (`[[1.0, 0, 10], [0, 1, 5]]`, or points `[[x, y], ...]`), or a string of bytes |
| `OutputArray` and other output arguments | returned. One result is returned as is; several come back as a list, return value first |
| `InputOutputArray` | must be a `CV\Mat`; changed in place |
| `Point`, `Size`, `Rect` | `[x, y]`, `[width, height]`, `[x, y, width, height]` |
| `Scalar` | a number, or a list of up to 4 |
| `RotatedRect`, `TermCriteria`, `Range` | `[[cx, cy], [w, h], angle]`, `[type, maxCount, eps]`, `[start, end]` |
| `vector<uchar>` | string |
| `vector<T>` | list |
| classes (`CLAHE`, `KeyPoint`, `FileStorage`, ...) | PHP classes with the C++ inheritance; `Ptr<T>` and by-value both map to the object |
| `CV_PROP` members | properties, type-checked on assignment; read-only where C++ says so |
| "map" structs (`Moments`, `Filter2DParams`) | arrays keyed by member name; missing keys keep the C++ default |
| `Vec2d` ... `Vec6f` | list of numbers |
| enums | `int`; members are constants (`CV\INTER_LINEAR`, `CV\Error\StsBadArg`, `CV\FileStorage_READ`) |
| `cv::Exception` | `CV\Exception` |

Things to know:

- A PHP list becomes `CV_32S` when every value is an int and `CV_64F` otherwise.
  Functions that insist on floats (`warpAffine`'s matrix) need at least one
  float in the list, the same trap as `np.array([[1, 0, 10], ...])` in Python.
- Optional arguments take `null` to mean "the C++ default"; named arguments
  work for functions that are not overloaded.
- Overloaded functions (`rectangle`, `divide`, ...) are resolved at run time by
  argument types and are declared variadic, so no named arguments there.
- OpenCV 5 renumbered the type constants (`CV_8UC3` is 64, it was 16). Use the
  constants, never literals.
- Classes without a wrapped constructor (`CLAHE`) come from their factory
  function; `new` throws. Generated objects cannot be cloned or serialized.
- `operator[]` and friends appear under the alias OpenCV gives them for
  bindings (`FileStorage::getNode()`), as in Python.
- Pixel memory is allocated by OpenCV and is not counted by `memory_limit`.

## How it is built

```
OpenCV headers ──gen/dump_decls.py──▶ api/opencv-5.1.0.json ──gen/generate.php──▶ src/generated/*.cc
 (CV_EXPORTS_W)   (OpenCV's own                (committed)                         stubs/opencv.stub.php
                   hdr_parser.py)                                                  api/coverage.json
```

- `gen/vendor/hdr_parser.py` is OpenCV's parser, copied unmodified from the
  5.1.0 tag (Apache-2.0). `gen/dump_decls.py` is a thin wrapper that writes its
  output as JSON. This is the only Python, and only maintainers run it.
- `gen/generate.php` turns the JSON into C++. A function is wrapped when every
  type in its signature has a converter; otherwise it is skipped and reported.
- `Mat` has the hand-written helpers PHP needs in place of numpy: `fromArray`,
  `fromString`, `data`, `toArray`, `at`/`set`, `roi`, `copyTo`, `convertTo`.
- `src/php_opencv.h`, `src/mat.cc`, `src/object.cc`, `src/opencv.cc` are
  hand-written: `CV\Mat`, the zval converters, and the object and property
  machinery every generated class shares.
- Generated sources are committed, so installing needs neither Python nor the
  generator.

Regenerate after changing the generator, or for a new OpenCV release:

```bash
python3 gen/dump_decls.py /path/to/opencv-source api/opencv-5.1.0.json   # core imgcodecs imgproc geometry objdetect
php gen/generate.php
```

To add a module: add it to the `dump_decls.py` command, to
`OPENCV_PHP_MODULES` in `config.m4`, and regenerate.

## Next

1. More modules: `dnn`, `objdetect`, `features`, `photo`, `video`, `videoio`
   (add to the dump and to `config.m4`; expect a few new types per module).
2. `MatShape`, `RNG`, and N-dimensional `Mat` access.
3. Typed arginfo generated from the same model, so reflection shows real types.
4. CI matrix and prebuilt binaries for PIE; Linux build against OpenCV 5.
