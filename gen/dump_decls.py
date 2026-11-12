#!/usr/bin/env python3
"""Dump OpenCV's wrappable API to JSON.

Runs OpenCV's own header parser (gen/vendor/hdr_parser.py, the one the Python,
Java and JS bindings use) over the public headers of the requested modules and
writes the raw declarations to a JSON file.  The PHP generator (gen/generate.php)
reads that JSON, so nothing else in this project needs Python.

Usage:
    gen/dump_decls.py <include-root> <out.json> [module ...]

<include-root> is a directory that contains opencv2/, e.g.
    $(pkg-config --variable=includedir opencv5)/opencv5   (installed)
or a list of modules/*/include dirs is derived when given an OpenCV source tree.
"""
import glob
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "vendor"))
import hdr_parser  # noqa: E402

# Same exclusions OpenCV's cmake applies before handing headers to gen2.py.
SKIP = re.compile(r"/hal/|/cuda/|/cuda\.|/opencl/|/detail/|\.details\.|\.inl\.h|/legacy/|/private|_c\.h$|/utils/|/parallel/|/openvx/|/ocl|/directx|/va_intel|/opengl|/quaternion|/dualquaternion|/eigen|/cvstd_wrapper|/async|/simd_|/intrin|/core_c|/bindings_utils")

# Platform macros the parser evaluates in #if blocks (mirrors
# cmake/OpenCVBindingsPreprocessorDefinitions.cmake for a desktop build).
# Only modules that are wrapped are declared present.  API that the headers
# guard behind another module (HAVE_OPENCV_DNN in objdetect) is left out, so
# the generated code compiles against an OpenCV built with just these modules.
DEFINES = {
    "CV_VERSION_MAJOR": 5, "CV_VERSION_MINOR": 1, "CV_VERSION_PATCH": 0,
    "OPENCV_ABI_COMPATIBILITY": 500,
    "HAVE_OPENCV_CORE": 1, "HAVE_OPENCV_IMGPROC": 1, "HAVE_OPENCV_IMGCODECS": 1, "HAVE_OPENCV_GEOMETRY": 1, "HAVE_OPENCV_OBJDETECT": 1,
    "EIGEN_WORLD_VERSION": 0, "EIGEN_MAJOR_VERSION": 0, "EIGEN_MINOR_VERSION": 0,
    "OPENCV_HAVE_FILESYSTEM_SUPPORT": 1, "OPENCV_BINDINGS_PARSER": 1,
    "CV_ENABLE_UNROLLED": 0, "CV__EXCEPTION_PTR": 0, "CV_NEON": 0,
    "TBB_INTERFACE_VERSION": 0, "CV_SSE2": 0, "CV_VSX": 0,
    "OPENCV_SUPPORTS_FP_DENORMALS_HINT": 0, "CV_LOG_STRIP_LEVEL": 0,
    "CV_LOG_LEVEL_SILENT": 0, "CV_LOG_LEVEL_FATAL": 1, "CV_LOG_LEVEL_ERROR": 2,
    "CV_LOG_LEVEL_WARN": 3, "CV_LOG_LEVEL_INFO": 4, "CV_LOG_LEVEL_DEBUG": 5,
    "CV_LOG_LEVEL_VERBOSE": 6, "CERES_FOUND": 0,
}


def headers_for(root, module):
    # Installed layout: <root>/opencv2/<module>.hpp + <root>/opencv2/<module>/**
    # Source layout:    <root>/modules/<module>/include/opencv2/...
    src = os.path.join(root, "modules", module, "include")
    base = src if os.path.isdir(src) else root
    found = glob.glob(os.path.join(base, "opencv2", module + ".hpp"))
    found += glob.glob(os.path.join(base, "opencv2", module, "**", "*.hpp"), recursive=True)
    # Match on the path below the include dir, never on where the tree lives.
    return sorted(h for h in found if not SKIP.search("/" + os.path.relpath(h, base))), base


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    root, out = sys.argv[1], sys.argv[2]
    modules = sys.argv[3:] or ["core", "imgcodecs", "imgproc", "geometry", "objdetect"]
    parser = hdr_parser.CppHeaderParser(
        generate_umat_decls=False,
        generate_gpumat_decls=False,
        preprocessor_definitions=DEFINES,
    )
    result = {"modules": {}, "namespaces": []}
    for module in modules:
        headers, base = headers_for(root, module)
        entries = []
        for h in headers:
            rel = os.path.relpath(h, base)
            for d in parser.parse(h):
                # d = [name, rettype, modifiers, args, original_rettype?, docstring?]
                entries.append({
                    "name": d[0],
                    "ret": d[1],
                    "mods": d[2],
                    "args": [
                        {"type": a[0], "name": a[1], "default": a[2], "mods": a[3]}
                        for a in d[3]
                    ],
                    "doc": d[5] if len(d) > 5 and d[5] else "",
                    "header": rel,
                })
        result["modules"][module] = {
            "headers": [os.path.relpath(h, base) for h in headers],
            "decls": entries,
        }
        print("%-10s %3d headers %5d declarations" % (module, len(headers), len(entries)), file=sys.stderr)
    result["namespaces"] = sorted(parser.namespaces)
    with open(out, "w") as f:
        json.dump(result, f, indent=1, sort_keys=True)
        f.write("\n")


if __name__ == "__main__":
    main()
