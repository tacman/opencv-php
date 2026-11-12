dnl opencv-php: PHP bindings for OpenCV 5

PHP_ARG_WITH([opencv],
  [for OpenCV 5 support],
  [AS_HELP_STRING([--with-opencv],
    [Build the OpenCV extension (needs OpenCV >= 5.0 and its opencv5.pc)])])

if test "$PHP_OPENCV" != "no"; then
  PHP_REQUIRE_CXX()

  PKG_CHECK_MODULES([OPENCV], [opencv5 >= 5.0.0])
  PHP_EVAL_INCLINE([$OPENCV_CFLAGS])

  dnl Link only the modules the bindings wrap, not everything opencv5.pc lists
  dnl (which includes every contrib module the distribution happened to build).
  dnl Keep this list in step with the modules passed to gen/dump_decls.py.
  OPENCV_PHP_MODULES="core imgproc imgcodecs geometry objdetect"
  OPENCV_PHP_LIBDIR=`$PKG_CONFIG --variable=libdir opencv5`
  for module in $OPENCV_PHP_MODULES; do
    PHP_ADD_LIBRARY_WITH_PATH([opencv_$module], [$OPENCV_PHP_LIBDIR], [OPENCV_SHARED_LIBADD])
  done
  PHP_SUBST([OPENCV_SHARED_LIBADD])

  OPENCV_PHP_SOURCES="src/opencv.cc src/mat.cc src/object.cc src/generated/registry.cc"
  for module in $OPENCV_PHP_MODULES; do
    OPENCV_PHP_SOURCES="$OPENCV_PHP_SOURCES src/generated/$module.cc"
  done

  dnl OpenCV 5 requires C++17.
  PHP_NEW_EXTENSION([opencv], [$OPENCV_PHP_SOURCES], [$ext_shared],,
    [-std=c++17], [cxx])
  PHP_ADD_BUILD_DIR([$ext_builddir/src/generated])
fi
