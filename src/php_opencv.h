/*
 * opencv-php: PHP bindings for OpenCV 5.
 *
 * This header is the hand-written runtime shared by the hand-written classes
 * (src/mat.cc) and the generated wrappers in src/generated.  It plays the
 * role cv2_convert.hpp plays for Python and core_bindings.cpp plays for JS:
 * everything that turns a zval into an OpenCV value and back lives here.
 */
#ifndef PHP_OPENCV_H
#define PHP_OPENCV_H

#include <opencv2/core.hpp>
#include <new>
#include <string>
#include <type_traits>
#include <vector>

extern "C" {
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
}

#define PHP_OPENCV_VERSION "0.1.0-dev"
#define PHP_OPENCV_EXTNAME "opencv"

extern zend_module_entry opencv_module_entry;
#define phpext_opencv_ptr &opencv_module_entry

extern zend_class_entry* phpcv_mat_ce;
extern zend_class_entry* phpcv_exception_ce;

void phpcv_register_mat(void);
void phpcv_register_classes(void);                /* generated */
extern const int phpcv_class_count;               /* generated */
void phpcv_register_constants(int module_number); /* generated */
extern const zend_function_entry phpcv_functions[]; /* generated */
extern const int phpcv_function_count;              /* generated */
extern const int phpcv_constant_count;              /* generated */

namespace phpcv {

/* ---- CV\Mat ---------------------------------------------------------- */

/** The cv::Mat wrapped by a CV\Mat object, or nullptr if zv is not one. */
cv::Mat* mat_ptr(zval* zv);
/** Create a CV\Mat object in rv that shares m's data (no pixel copy). */
void mat_new(zval* rv, const cv::Mat& m);

/* ---- generated classes ------------------------------------------------ */

/**
 * Every generated class is a PHP object holding a cv::Ptr<T>, whether OpenCV
 * hands the type out by pointer (Algorithm subclasses) or by value (KeyPoint).
 */
template <typename T> struct Object {
    cv::Ptr<T> ptr;
    zend_object std;
};

/** A C++ data member exposed as a PHP property; set is null when read-only. */
struct Property {
    const char* name;
    void (*get)(zend_object* obj, zval* rv);
    bool (*set)(zend_object* obj, zval* value);
    const char* type;
};

void register_properties(zend_class_entry* ce, const Property* properties, size_t count);
void init_object_handlers(zend_object_handlers* handlers, int offset, zend_object_free_obj_t free_obj);
/** Raised by a method called on an object whose constructor never ran. */
void throw_uninitialized(zend_class_entry* ce);

template <typename T> struct ObjectType {
    static zend_object_handlers handlers;

    static Object<T>* fetch(zend_object* obj) {
        return (Object<T>*)((char*)obj - XtOffsetOf(Object<T>, std));
    }
    static zend_object* create(zend_class_entry* ce) {
        Object<T>* o = (Object<T>*)zend_object_alloc(sizeof(Object<T>), ce);
        new (&o->ptr) cv::Ptr<T>();
        zend_object_std_init(&o->std, ce);
        object_properties_init(&o->std, ce);
        o->std.handlers = &handlers;
        return &o->std;
    }
    static void free(zend_object* obj) {
        typedef cv::Ptr<T> Holder;
        fetch(obj)->ptr.~Holder();
        zend_object_std_dtor(obj);
    }
    static void setup(zend_class_entry* ce) {
        ce->create_object = create;
        ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
        init_object_handlers(&handlers, XtOffsetOf(Object<T>, std), free);
    }
    /** New PHP object of class ce around p; null for an empty pointer. */
    static void wrap(zval* rv, zend_class_entry* ce, const cv::Ptr<T>& p) {
        if (!p) {
            ZVAL_NULL(rv);
            return;
        }
        ZVAL_OBJ(rv, create(ce));
        fetch(Z_OBJ_P(rv))->ptr = p;
    }
};
template <typename T> zend_object_handlers ObjectType<T>::handlers;

/* ---- errors ---------------------------------------------------------- */

/**
 * Call from inside `catch (...)`: converts the in-flight C++ exception into
 * a PHP CV\Exception.
 */
void rethrow();

/**
 * Remembers why each overload was rejected, so the TypeError can name the
 * argument of the overload that got furthest.
 */
struct Mismatch {
    int best = -2;
    std::string msg;
    int count(uint32_t argc, uint32_t min, uint32_t max);
    int arg(uint32_t index, const char* name, const char* expected, zval* got);
    void raise(const char* function);
};

/* ---- PHP -> OpenCV ---------------------------------------------------- */

enum { MAT_ARITHM_SCALAR = 1 };

bool to(zval* zv, bool& v);
bool to(zval* zv, int& v);
bool to(zval* zv, unsigned char& v);
bool to(zval* zv, int64_t& v);
bool to(zval* zv, size_t& v);
bool to(zval* zv, double& v);
bool to(zval* zv, float& v);
bool to(zval* zv, std::string& v);
bool to(zval* zv, cv::Mat& v, int flags = 0);
bool to(zval* zv, cv::Scalar& v);
bool to(zval* zv, cv::Range& v);
bool to(zval* zv, cv::TermCriteria& v);
bool to(zval* zv, cv::RotatedRect& v);
/** vector<uchar> is a PHP string of bytes (encoded images, raw buffers). */
bool to(zval* zv, std::vector<unsigned char>& v);

} // namespace phpcv

/*
 * Converters for the generated classes and structs.  They are declared here,
 * before the vector<T> templates below, so that vector<KeyPoint> and friends
 * can find them.
 */
#include "generated/classes.h"

namespace phpcv {

/** Reads exactly n numbers from a PHP list. */
bool to_numbers(zval* zv, double* out, int n);

template <typename T> bool to(zval* zv, cv::Point_<T>& v) {
    double d[2];
    if (!to_numbers(zv, d, 2)) return false;
    v = cv::Point_<T>(cv::saturate_cast<T>(d[0]), cv::saturate_cast<T>(d[1]));
    return true;
}
template <typename T> bool to(zval* zv, cv::Point3_<T>& v) {
    double d[3];
    if (!to_numbers(zv, d, 3)) return false;
    v = cv::Point3_<T>(cv::saturate_cast<T>(d[0]), cv::saturate_cast<T>(d[1]), cv::saturate_cast<T>(d[2]));
    return true;
}
template <typename T> bool to(zval* zv, cv::Size_<T>& v) {
    double d[2];
    if (!to_numbers(zv, d, 2)) return false;
    v = cv::Size_<T>(cv::saturate_cast<T>(d[0]), cv::saturate_cast<T>(d[1]));
    return true;
}
template <typename T> bool to(zval* zv, cv::Rect_<T>& v) {
    double d[4];
    if (!to_numbers(zv, d, 4)) return false;
    v = cv::Rect_<T>(cv::saturate_cast<T>(d[0]), cv::saturate_cast<T>(d[1]),
                     cv::saturate_cast<T>(d[2]), cv::saturate_cast<T>(d[3]));
    return true;
}
template <typename T, int n> bool to(zval* zv, cv::Vec<T, n>& v) {
    double d[n];
    if (!to_numbers(zv, d, n)) return false;
    for (int i = 0; i < n; i++) v[i] = cv::saturate_cast<T>(d[i]);
    return true;
}
template <typename T, typename std::enable_if<std::is_enum<T>::value, int>::type = 0>
bool to(zval* zv, T& v) {
    int i;
    if (!to(zv, i)) return false;
    v = static_cast<T>(i);
    return true;
}
template <typename T> bool to(zval* zv, std::vector<T>& v) {
    if (Z_TYPE_P(zv) != IS_ARRAY) return false;
    v.clear();
    v.reserve(zend_hash_num_elements(Z_ARRVAL_P(zv)));
    zval* item;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), item) {
        ZVAL_DEREF(item);
        T element;
        if (!to(item, element)) return false;
        v.push_back(element);
    } ZEND_HASH_FOREACH_END();
    return true;
}

/* ---- OpenCV -> PHP ---------------------------------------------------- */

inline void from(zval* rv, bool v) { ZVAL_BOOL(rv, v); }
inline void from(zval* rv, int v) { ZVAL_LONG(rv, v); }
inline void from(zval* rv, unsigned char v) { ZVAL_LONG(rv, v); }
inline void from(zval* rv, int64_t v) { ZVAL_LONG(rv, v); }
inline void from(zval* rv, size_t v) { ZVAL_LONG(rv, (zend_long)v); }
inline void from(zval* rv, double v) { ZVAL_DOUBLE(rv, v); }
inline void from(zval* rv, float v) { ZVAL_DOUBLE(rv, v); }
inline void from(zval* rv, const std::string& v) { ZVAL_STRINGL(rv, v.data(), v.size()); }
inline void from(zval* rv, const cv::Mat& v) { mat_new(rv, v); }
void from(zval* rv, const cv::Scalar& v);
void from(zval* rv, const cv::Range& v);
void from(zval* rv, const cv::TermCriteria& v);
void from(zval* rv, const cv::RotatedRect& v);
void from(zval* rv, const std::vector<unsigned char>& v);

inline void add_number(zval* arr, double v) { add_next_index_double(arr, v); }
inline void add_number(zval* arr, float v) { add_next_index_double(arr, v); }
inline void add_number(zval* arr, int v) { add_next_index_long(arr, v); }
inline void add_number(zval* arr, int64_t v) { add_next_index_long(arr, v); }

template <typename T> void from(zval* rv, const cv::Point_<T>& v) {
    array_init_size(rv, 2);
    add_number(rv, v.x);
    add_number(rv, v.y);
}
template <typename T> void from(zval* rv, const cv::Point3_<T>& v) {
    array_init_size(rv, 3);
    add_number(rv, v.x);
    add_number(rv, v.y);
    add_number(rv, v.z);
}
template <typename T> void from(zval* rv, const cv::Size_<T>& v) {
    array_init_size(rv, 2);
    add_number(rv, v.width);
    add_number(rv, v.height);
}
template <typename T> void from(zval* rv, const cv::Rect_<T>& v) {
    array_init_size(rv, 4);
    add_number(rv, v.x);
    add_number(rv, v.y);
    add_number(rv, v.width);
    add_number(rv, v.height);
}
inline void add_number(zval* arr, unsigned char v) { add_next_index_long(arr, v); }
inline void add_number(zval* arr, short v) { add_next_index_long(arr, v); }
inline void add_number(zval* arr, unsigned short v) { add_next_index_long(arr, v); }
template <typename T, int n> void from(zval* rv, const cv::Vec<T, n>& v) {
    array_init_size(rv, n);
    for (int i = 0; i < n; i++) add_number(rv, v[i]);
}
template <typename T, typename std::enable_if<std::is_enum<T>::value, int>::type = 0>
void from(zval* rv, T v) {
    ZVAL_LONG(rv, static_cast<zend_long>(v));
}
template <typename T> void from(zval* rv, const std::vector<T>& v) {
    array_init_size(rv, (uint32_t)v.size());
    for (const T& element : v) {
        zval item;
        from(&item, element);
        add_next_index_zval(rv, &item);
    }
}
/* vector<bool> hands out proxies, not references. */
inline void from(zval* rv, const std::vector<bool>& v) {
    array_init_size(rv, (uint32_t)v.size());
    for (bool element : v) add_next_index_bool(rv, element);
}

/** Appends one converted value to a PHP list (multi-value returns). */
template <typename T> void push(zval* list, const T& v) {
    zval item;
    from(&item, v);
    add_next_index_zval(list, &item);
}

} // namespace phpcv

#endif
