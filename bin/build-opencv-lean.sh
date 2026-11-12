#!/usr/bin/env bash
# Build a lean OpenCV 5 for the extension: only the modules it wraps, no dnn.
#
#   bin/build-opencv-lean.sh [prefix] [version]
#   bin/build-opencv-lean.sh ~/.local/opencv-lean 5.1.0
#
# Why: a distribution's full OpenCV (Homebrew's, for one) links objdetect to
# dnn, OpenVINO and protobuf, about 90 shared libraries that load on every PHP
# start (190 ms measured on an M4).  This build loads in a few milliseconds, so
# the extension can be enabled for every PHP process.
#
# Needs cmake and a C++17 compiler (macOS: `brew install cmake`).  Afterwards:
#
#   PKG_CONFIG_PATH=<prefix>/lib/pkgconfig phpize && ./configure --with-opencv && make && make install
#
# Keep MODULES in step with OPENCV_PHP_MODULES in config.m4.
set -euo pipefail

PREFIX="${1:-$HOME/.local/opencv-lean}"
VERSION="${2:-5.1.0}"
MODULES="core,imgproc,imgcodecs,geometry,objdetect"
WORK="${OPENCV_BUILD_DIR:-${TMPDIR:-/tmp}/opencv-lean-build}"
JOBS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"

command -v cmake >/dev/null || { echo "cmake is required (macOS: brew install cmake)" >&2; exit 1; }

mkdir -p "$WORK"
if [ ! -d "$WORK/opencv-$VERSION" ]; then
    git clone --depth 1 --branch "$VERSION" https://github.com/opencv/opencv.git "$WORK/opencv-$VERSION"
fi

# The image codecs are OpenCV's bundled copies, so the result depends on
# nothing outside the system.  Everything not needed by MODULES is off.
cmake -S "$WORK/opencv-$VERSION" -B "$WORK/build-$VERSION" \
    -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$PREFIX" \
    -DBUILD_LIST="$MODULES" \
    -DBUILD_SHARED_LIBS=ON -DOPENCV_GENERATE_PKGCONFIG=ON \
    -DBUILD_JPEG=ON -DBUILD_PNG=ON -DBUILD_TIFF=ON -DBUILD_WEBP=ON -DBUILD_ZLIB=ON \
    -DWITH_OPENEXR=OFF -DWITH_OPENJPEG=OFF -DWITH_JASPER=OFF -DWITH_AVIF=OFF -DWITH_JPEGXL=OFF \
    -DWITH_FFMPEG=OFF -DWITH_GSTREAMER=OFF -DWITH_V4L=OFF -DWITH_GTK=OFF -DWITH_QT=OFF \
    -DWITH_OPENCL=OFF -DWITH_ITT=OFF -DWITH_PROTOBUF=OFF -DWITH_FLATBUFFERS=OFF \
    -DWITH_TBB=OFF -DWITH_EIGEN=OFF -DWITH_VTK=OFF -DWITH_OPENVINO=OFF \
    -DBUILD_TESTS=OFF -DBUILD_PERF_TESTS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_DOCS=OFF \
    -DBUILD_opencv_apps=OFF -DBUILD_JAVA=OFF -DBUILD_opencv_python3=OFF -DBUILD_opencv_js=OFF
cmake --build "$WORK/build-$VERSION" -j "$JOBS"
cmake --install "$WORK/build-$VERSION"

echo
echo "OpenCV $VERSION ($MODULES) installed in $PREFIX"
echo "Build the extension against it with:"
echo "  PKG_CONFIG_PATH=$PREFIX/lib/pkgconfig phpize && ./configure --with-opencv && make && make install"
