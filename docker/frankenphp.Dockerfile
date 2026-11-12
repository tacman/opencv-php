# FrankenPHP with the opencv extension.
#
#   podman build -f docker/frankenphp.Dockerfile -t opencv-php-frankenphp .
#   podman run --rm -p 8099:80 opencv-php-frankenphp
#
# Debian and Ubuntu still package OpenCV 4, so OpenCV 5 is built here from
# source, with only the modules the extension wraps.  The image codecs are
# OpenCV's bundled copies, so the final image needs no extra apt packages.

ARG PHP_VERSION=8.5
ARG OPENCV_VERSION=5.1.0

FROM dunglas/frankenphp:php${PHP_VERSION} AS build
ARG OPENCV_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential cmake ninja-build git ca-certificates pkg-config autoconf \
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
    && cmake --install /build/opencv

# The extension: generated sources are committed, so this is phpize + make.
# Once the package is on Packagist this stage becomes `pie install survos/opencv-php`.
COPY config.m4 /src/ext/
COPY src /src/ext/src
RUN cd /src/ext \
    && export PKG_CONFIG_PATH=/opt/opencv/lib/pkgconfig \
    && phpize && ./configure --with-opencv && make -j"$(nproc)" \
    && cp modules/opencv.so "$(php-config --extension-dir)/" \
    && php-config --extension-dir > /extension-dir

FROM dunglas/frankenphp:php${PHP_VERSION}
COPY --from=build /opt/opencv/lib/ /opt/opencv/lib/
COPY --from=build /extension-dir /tmp/extension-dir
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN echo /opt/opencv/lib > /etc/ld.so.conf.d/opencv.conf && ldconfig \
    && docker-php-ext-enable opencv \
    && php --ri opencv

COPY demo/src /app/src
COPY demo/public /app/public
ENV SERVER_NAME=:80
