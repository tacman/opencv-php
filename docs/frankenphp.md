# Running opencv-php under FrankenPHP

FrankenPHP runs thread-safe (ZTS) PHP. The extension builds for ZTS and keeps no
per-request global state: its class and property tables are filled once at
module start and only read afterwards.

## The image

`docker/frankenphp.Dockerfile` builds on `dunglas/frankenphp:php8.5` and serves
the demo (`demo/public/index.php`).

```bash
podman build -f docker/frankenphp.Dockerfile -t opencv-php-frankenphp .
podman run --rm -p 8099:80 opencv-php-frankenphp
curl -s localhost:8099/status
curl -s -H 'Content-Type: application/octet-stream' --data-binary @frame.jpg \
     -D - -o straight.jpg localhost:8099/analyze/straighten
```

No distribution packages OpenCV 5 yet, so the image does three things:

1. Builds OpenCV 5 from source, limited to the wrapped modules
   (`-DBUILD_LIST=core,imgproc,imgcodecs,geometry,objdetect`), with OpenCV's
   bundled JPEG/PNG/TIFF/WebP so the final image needs no extra apt packages.
2. Builds the extension against it (`phpize && ./configure && make`). The
   generated sources are committed, so there is no Python and no generator run.
3. Copies the OpenCV libraries and `opencv.so` into the final stage and enables it.

To add it to an existing FrankenPHP Dockerfile, keep steps 1 and 2 as their own
stage and copy from it:

```dockerfile
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS opencv
# ... the two RUN steps from docker/frankenphp.Dockerfile ...

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base
COPY --from=opencv /opt/opencv/lib/ /opt/opencv/lib/
COPY --from=opencv /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN echo /opt/opencv/lib > /etc/ld.so.conf.d/opencv.conf && ldconfig && docker-php-ext-enable opencv
```

and require `"ext-opencv": "*"` in the application's `composer.json`.

## Measured

On an Apple M4, podman, arm64; Debian 13, PHP 8.5.12 ZTS, FrankenPHP 1.13.5,
OpenCV 5.1.0. Input: 48-megapixel overhead scans.

| | |
|---|---|
| `GET /status` | `sapi: frankenphp`, `threadSafe: true` |
| One frame, `POST /analyze/straighten` | 343 ms server time; same measurement as the Python original |
| 12 requests, 6 at a time, across threads | all 200, all byte-identical; no crash, nothing logged |
| Container memory afterwards | 167 MB |
| Image size | 666 MB, against 629 MB for the base image |
| Compiling OpenCV | about 8 minutes on 7 cores, once; later builds reuse the layer |

On macOS (PHP 8.5.12 NTS, Homebrew OpenCV 5.1.0) the same tool is byte-identical
to its Python original and averages 65 ms per frame inside one process, with
PHP's own peak memory at 24 MB.

## When it runs for real

- **Memory.** A 48 MP frame is about 143 MB decoded, and `memory_limit` does not
  count it: the pixels are OpenCV's. Size the container for PHP plus two or
  three frames. `unset($mat)` frees a frame at once.
- **Threads.** OpenCV parallelises inside one call. With several FrankenPHP
  threads or several workers on one machine, call `CV\setNumThreads()` with a
  small number at start so they do not oversubscribe the cores.
- **Request bodies.** Send images as `application/octet-stream`.
- **Build time.** Compiling OpenCV on every cold build is the cost to avoid:
  publish a base image (FrankenPHP + OpenCV + the extension) per OpenCV or PHP
  bump and start applications `FROM` it.

## Not yet verified

- `amd64`: the container build was done on arm64 only.
- A leak check under a debug build of PHP, and a long soak in a worker.
- FrankenPHP worker mode (the demo runs in classic mode).
