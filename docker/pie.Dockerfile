# Installs the extension on Linux the way the README says: `pie install tacman/opencv-php`
# from Packagist.  Use it to check a release, or as a recipe for your own image.
#
#   podman build -f docker/pie.Dockerfile -t opencv-php-pie .
#   podman run --rm -v "$PWD:/app" opencv-php-pie php /app/examples/run.php all
#
# Debian and Ubuntu still package OpenCV 4, so OpenCV 5 is built here from
# source first, with only the modules the extension wraps (no dnn).

ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

ARG OPENCV_VERSION=5.1.0
ARG PIE_VERSION=1.5.5
ARG PACKAGE=tacman/opencv-php

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential cmake ninja-build git ca-certificates pkg-config autoconf curl unzip \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch ${OPENCV_VERSION} https://github.com/opencv/opencv.git /src/opencv \
    && cmake -S /src/opencv -B /build/opencv -G Ninja \
        -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/opt/opencv \
        -DBUILD_LIST=core,imgproc,imgcodecs,geometry,objdetect \
        -DBUILD_SHARED_LIBS=ON -DOPENCV_GENERATE_PKGCONFIG=ON \
        -DBUILD_JPEG=ON -DBUILD_PNG=ON -DBUILD_TIFF=ON -DBUILD_WEBP=ON -DBUILD_ZLIB=ON \
        -DWITH_OPENEXR=OFF -DWITH_OPENJPEG=OFF -DWITH_JASPER=OFF -DWITH_AVIF=OFF -DWITH_JPEGXL=OFF \
        -DWITH_FFMPEG=OFF -DWITH_GSTREAMER=OFF -DWITH_V4L=OFF -DWITH_GTK=OFF -DWITH_QT=OFF \
        -DWITH_OPENCL=OFF -DWITH_ITT=OFF -DWITH_PROTOBUF=OFF -DWITH_FLATBUFFERS=OFF \
        -DBUILD_TESTS=OFF -DBUILD_PERF_TESTS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_DOCS=OFF \
        -DBUILD_opencv_apps=OFF -DBUILD_JAVA=OFF -DBUILD_opencv_python3=OFF -DBUILD_opencv_js=OFF \
    && cmake --build /build/opencv \
    && cmake --install /build/opencv \
    && rm -rf /src/opencv /build/opencv \
    && echo /opt/opencv/lib > /etc/ld.so.conf.d/opencv.conf && ldconfig

RUN curl -fsSL -o /usr/local/bin/pie https://github.com/php/pie/releases/download/${PIE_VERSION}/pie.phar \
    && chmod +x /usr/local/bin/pie

RUN PKG_CONFIG_PATH=/opt/opencv/lib/pkgconfig pie install ${PACKAGE} \
    && php --ri opencv
