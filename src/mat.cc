/*
 * CV\Mat and the zval <-> OpenCV converters.  Hand-written: cv::Mat is not
 * annotated for wrapping in OpenCV's headers (Python maps it to numpy, JS
 * hand-writes it too), so every binding supplies its own.
 */
#include "php_opencv.h"

#include <cmath>
#include <limits>

zend_class_entry* phpcv_mat_ce;
static zend_object_handlers mat_handlers;

struct mat_object {
    cv::Mat* mat;
    zend_object std;
};

static inline mat_object* mat_fetch(zend_object* obj) {
    return (mat_object*)((char*)obj - XtOffsetOf(mat_object, std));
}
#define THIS_MAT (*mat_fetch(Z_OBJ_P(ZEND_THIS))->mat)

static zend_object* mat_create(zend_class_entry* ce) {
    mat_object* o = (mat_object*)zend_object_alloc(sizeof(mat_object), ce);
    o->mat = new cv::Mat();
    zend_object_std_init(&o->std, ce);
    object_properties_init(&o->std, ce);
    o->std.handlers = &mat_handlers;
    return &o->std;
}

static void mat_free(zend_object* obj) {
    mat_object* o = mat_fetch(obj);
    delete o->mat;
    o->mat = nullptr;
    zend_object_std_dtor(obj);
}

/* `clone $mat` copies the pixels, like Mat::clone(). */
static zend_object* mat_clone(zend_object* obj) {
    zend_object* copy = mat_create(obj->ce);
    try {
        *mat_fetch(copy)->mat = mat_fetch(obj)->mat->clone();
    } catch (...) {
        phpcv::rethrow();
    }
    zend_objects_clone_members(copy, obj);
    return copy;
}

/* ---- element access --------------------------------------------------- */

static bool depth_is_integer(int depth) {
    return depth != CV_32F && depth != CV_64F && depth != CV_16F && depth != CV_16BF;
}

static void elem_get(zval* rv, const uchar* p, int depth) {
    switch (depth) {
    case CV_8U:   ZVAL_LONG(rv, *p); break;
    case CV_8S:   ZVAL_LONG(rv, *(const schar*)p); break;
    case CV_Bool: ZVAL_BOOL(rv, *p != 0); break;
    case CV_16U:  ZVAL_LONG(rv, *(const ushort*)p); break;
    case CV_16S:  ZVAL_LONG(rv, *(const short*)p); break;
    case CV_32S:  ZVAL_LONG(rv, *(const int*)p); break;
    case CV_32U:  ZVAL_LONG(rv, *(const unsigned*)p); break;
    case CV_64S:  ZVAL_LONG(rv, (zend_long)*(const int64_t*)p); break;
    case CV_64U:  ZVAL_LONG(rv, (zend_long)*(const uint64_t*)p); break;
    case CV_32F:  ZVAL_DOUBLE(rv, *(const float*)p); break;
    case CV_64F:  ZVAL_DOUBLE(rv, *(const double*)p); break;
    case CV_16F:  ZVAL_DOUBLE(rv, (float)*(const cv::hfloat*)p); break;
    case CV_16BF: ZVAL_DOUBLE(rv, (float)*(const cv::bfloat*)p); break;
    default:      ZVAL_NULL(rv);
    }
}

static void elem_set(uchar* p, int depth, double v) {
    switch (depth) {
    case CV_8U:   *p = cv::saturate_cast<uchar>(v); break;
    case CV_8S:   *(schar*)p = cv::saturate_cast<schar>(v); break;
    case CV_Bool: *p = v != 0; break;
    case CV_16U:  *(ushort*)p = cv::saturate_cast<ushort>(v); break;
    case CV_16S:  *(short*)p = cv::saturate_cast<short>(v); break;
    case CV_32S:  *(int*)p = cv::saturate_cast<int>(v); break;
    case CV_32U:  *(unsigned*)p = cv::saturate_cast<unsigned>(v); break;
    case CV_64S:  *(int64_t*)p = (int64_t)v; break;
    case CV_64U:  *(uint64_t*)p = (uint64_t)v; break;
    case CV_32F:  *(float*)p = (float)v; break;
    case CV_64F:  *(double*)p = v; break;
    case CV_16F:  *(cv::hfloat*)p = cv::hfloat((float)v); break;
    case CV_16BF: *(cv::bfloat*)p = cv::bfloat((float)v); break;
    }
}

static bool zval_number(zval* zv, double& d, bool* is_int = nullptr) {
    ZVAL_DEREF(zv);
    if (Z_TYPE_P(zv) == IS_LONG) { d = (double)Z_LVAL_P(zv); return true; }
    if (Z_TYPE_P(zv) == IS_DOUBLE) { d = Z_DVAL_P(zv); if (is_int) *is_int = false; return true; }
    return false;
}

/* ---- namespace phpcv: public runtime ---------------------------------- */

namespace phpcv {

cv::Mat* mat_ptr(zval* zv) {
    if (Z_TYPE_P(zv) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(zv), phpcv_mat_ce)) return nullptr;
    return mat_fetch(Z_OBJ_P(zv))->mat;
}

void mat_new(zval* rv, const cv::Mat& m) {
    object_init_ex(rv, phpcv_mat_ce);
    *mat_fetch(Z_OBJ_P(rv))->mat = m;
}

void rethrow() {
    try {
        throw;
    } catch (const cv::Exception& e) {
        zend_throw_exception(phpcv_exception_ce, e.what(), e.code);
    } catch (const std::exception& e) {
        zend_throw_exception(phpcv_exception_ce, e.what(), 0);
    } catch (...) {
        zend_throw_exception(phpcv_exception_ce, "Unknown C++ exception", 0);
    }
}

int Mismatch::count(uint32_t argc, uint32_t min, uint32_t max) {
    if (best < -1) {
        best = -1;
        msg = "expects ";
        if (min == max) msg += "exactly " + std::to_string(min);
        else msg += std::to_string(min) + " to " + std::to_string(max);
        msg += std::string(max == 1 ? " argument, " : " arguments, ") + std::to_string(argc) + " given";
    }
    return 0;
}

int Mismatch::arg(uint32_t index, const char* name, const char* expected, zval* got) {
    if ((int)index > best) {
        best = (int)index;
        msg = "Argument #" + std::to_string(index + 1) + " ($" + name + ") must be of type " +
              expected + ", " + zend_zval_value_name(got) + " given";
    }
    return 0;
}

void Mismatch::raise(const char* function) {
    if (EG(exception)) return;
    zend_type_error("%s(): %s", function, msg.c_str());
}

bool to(zval* zv, bool& v) {
    if (Z_TYPE_P(zv) == IS_TRUE) { v = true; return true; }
    if (Z_TYPE_P(zv) == IS_FALSE) { v = false; return true; }
    return false;
}

bool to(zval* zv, int& v) {
    if (Z_TYPE_P(zv) != IS_LONG) return false;
    zend_long l = Z_LVAL_P(zv);
    if (l < INT_MIN || l > INT_MAX) return false;
    v = (int)l;
    return true;
}

bool to(zval* zv, unsigned char& v) {
    if (Z_TYPE_P(zv) != IS_LONG || Z_LVAL_P(zv) < 0 || Z_LVAL_P(zv) > 255) return false;
    v = (unsigned char)Z_LVAL_P(zv);
    return true;
}

bool to(zval* zv, int64_t& v) {
    if (Z_TYPE_P(zv) != IS_LONG) return false;
    v = Z_LVAL_P(zv);
    return true;
}

bool to(zval* zv, size_t& v) {
    if (Z_TYPE_P(zv) != IS_LONG || Z_LVAL_P(zv) < 0) return false;
    v = (size_t)Z_LVAL_P(zv);
    return true;
}

bool to(zval* zv, double& v) { return zval_number(zv, v); }

bool to(zval* zv, float& v) {
    double d;
    if (!zval_number(zv, d)) return false;
    v = (float)d;
    return true;
}

bool to(zval* zv, std::string& v) {
    if (Z_TYPE_P(zv) != IS_STRING) return false;
    v.assign(Z_STRVAL_P(zv), Z_STRLEN_P(zv));
    return true;
}

bool to(zval* zv, std::vector<unsigned char>& v) {
    if (Z_TYPE_P(zv) != IS_STRING) return false;
    v.assign((const unsigned char*)Z_STRVAL_P(zv), (const unsigned char*)Z_STRVAL_P(zv) + Z_STRLEN_P(zv));
    return true;
}

bool to_numbers(zval* zv, double* out, int n) {
    if (Z_TYPE_P(zv) != IS_ARRAY || (int)zend_hash_num_elements(Z_ARRVAL_P(zv)) != n) return false;
    int i = 0;
    zval* item;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), item) {
        if (!zval_number(item, out[i++])) return false;
    } ZEND_HASH_FOREACH_END();
    return true;
}

/* A Scalar is a number or a list of 1 to 4 numbers. */
bool to(zval* zv, cv::Scalar& v) {
    double d;
    if (zval_number(zv, d)) { v = cv::Scalar(d); return true; }
    if (Z_TYPE_P(zv) != IS_ARRAY) return false;
    uint32_t n = zend_hash_num_elements(Z_ARRVAL_P(zv));
    if (n < 1 || n > 4) return false;
    v = cv::Scalar();
    int i = 0;
    zval* item;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), item) {
        if (!zval_number(item, v[i++])) return false;
    } ZEND_HASH_FOREACH_END();
    return true;
}

bool to(zval* zv, cv::Range& v) {
    double d[2];
    if (!to_numbers(zv, d, 2)) return false;
    v = cv::Range((int)d[0], (int)d[1]);
    return true;
}

/* [type, maxCount, epsilon] */
bool to(zval* zv, cv::TermCriteria& v) {
    double d[3];
    if (!to_numbers(zv, d, 3)) return false;
    v = cv::TermCriteria((int)d[0], (int)d[1], d[2]);
    return true;
}

/* [[cx, cy], [width, height], angle], the shape Python uses. */
bool to(zval* zv, cv::RotatedRect& v) {
    if (Z_TYPE_P(zv) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(zv)) != 3) return false;
    zval* c = zend_hash_index_find(Z_ARRVAL_P(zv), 0);
    zval* s = zend_hash_index_find(Z_ARRVAL_P(zv), 1);
    zval* a = zend_hash_index_find(Z_ARRVAL_P(zv), 2);
    double angle;
    if (!c || !s || !a || !to(c, v.center) || !to(s, v.size) || !zval_number(a, angle)) return false;
    v.angle = (float)angle;
    return true;
}

/*
 * What a function taking InputArray accepts:
 *   CV\Mat                    shared, no copy
 *   string                    a byte buffer, 1 x N CV_8U (for imdecode)
 *   [n, n, ...]               N x 1
 *   [[n, ...], [n, ...]]      rows x cols, like a numpy 2-D array; this is
 *                             also how a list of points is passed
 *   number (arithmetic only)  that value in every channel
 * Lists become CV_32S when every value is an int, CV_64F otherwise.
 */
bool to(zval* zv, cv::Mat& v, int flags) {
    ZVAL_DEREF(zv);
    if (cv::Mat* m = mat_ptr(zv)) { v = *m; return true; }
    if (Z_TYPE_P(zv) == IS_STRING) {
        v = cv::Mat(1, (int)Z_STRLEN_P(zv), CV_8U, (void*)Z_STRVAL_P(zv)).clone();
        return true;
    }
    double d;
    if ((flags & MAT_ARITHM_SCALAR) && zval_number(zv, d)) {
        v = cv::Mat(4, 1, CV_64F, cv::Scalar::all(d));
        return true;
    }
    if (Z_TYPE_P(zv) != IS_ARRAY) return false;

    HashTable* ht = Z_ARRVAL_P(zv);
    int rows = (int)zend_hash_num_elements(ht);
    if (rows == 0) { v = cv::Mat(); return true; }

    int cols = -1; /* -1: flat list of numbers */
    bool all_int = true;
    std::vector<double> values;
    zval* row;
    ZEND_HASH_FOREACH_VAL(ht, row) {
        ZVAL_DEREF(row);
        if (Z_TYPE_P(row) == IS_ARRAY) {
            int n = (int)zend_hash_num_elements(Z_ARRVAL_P(row));
            if (cols == -1 && values.empty()) cols = n;
            if (n != cols || n == 0) return false;
            zval* cell;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(row), cell) {
                if (!zval_number(cell, d, &all_int)) return false;
                values.push_back(d);
            } ZEND_HASH_FOREACH_END();
        } else {
            if (cols != -1 || !zval_number(row, d, &all_int)) return false;
            values.push_back(d);
        }
    } ZEND_HASH_FOREACH_END();

    cv::Mat m(rows, cols == -1 ? 1 : cols, all_int ? CV_32S : CV_64F);
    for (size_t i = 0; i < values.size(); i++) {
        if (all_int) m.ptr<int>()[i] = cv::saturate_cast<int>(values[i]);
        else m.ptr<double>()[i] = values[i];
    }
    v = m;
    return true;
}

void from(zval* rv, const cv::Scalar& v) {
    array_init_size(rv, 4);
    for (int i = 0; i < 4; i++) add_next_index_double(rv, v[i]);
}

void from(zval* rv, const cv::Range& v) {
    array_init_size(rv, 2);
    add_next_index_long(rv, v.start);
    add_next_index_long(rv, v.end);
}

void from(zval* rv, const cv::TermCriteria& v) {
    array_init_size(rv, 3);
    add_next_index_long(rv, v.type);
    add_next_index_long(rv, v.maxCount);
    add_next_index_double(rv, v.epsilon);
}

void from(zval* rv, const cv::RotatedRect& v) {
    array_init_size(rv, 3);
    push(rv, v.center);
    push(rv, v.size);
    add_next_index_double(rv, v.angle);
}

void from(zval* rv, const std::vector<unsigned char>& v) {
    ZVAL_STRINGL(rv, (const char*)v.data(), v.size());
}

} // namespace phpcv

/* ---- CV\Mat methods --------------------------------------------------- */

static bool check_dims(zend_long rows, zend_long cols) {
    if (rows < 0 || cols < 0 || rows > INT_MAX || cols > INT_MAX) {
        zend_value_error("Mat dimensions must be between 0 and %d", INT_MAX);
        return false;
    }
    return true;
}

/* new Mat(int $rows = 0, int $cols = 0, int $type = CV_8UC1, int|float|array $value = 0) */
PHP_METHOD(CV_Mat, __construct) {
    zend_long rows = 0, cols = 0, type = CV_8UC1;
    zval* value = nullptr;
    ZEND_PARSE_PARAMETERS_START(0, 4)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(rows)
        Z_PARAM_LONG(cols)
        Z_PARAM_LONG(type)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    if (!check_dims(rows, cols)) RETURN_THROWS();
    cv::Scalar fill;
    if (value && !phpcv::to(value, fill)) {
        zend_argument_type_error(4, "must be a number or a list of up to 4 numbers");
        RETURN_THROWS();
    }
    try {
        /* Always initialised: PHP code must never see uninitialised memory. */
        THIS_MAT = cv::Mat((int)rows, (int)cols, (int)type, fill);
    } catch (...) {
        phpcv::rethrow();
    }
}

/* Mat::fromString(string $bytes, int $rows, int $cols, int $type = CV_8UC1): Mat  (copies) */
PHP_METHOD(CV_Mat, fromString) {
    zend_string* bytes;
    zend_long rows, cols, type = CV_8UC1;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STR(bytes)
        Z_PARAM_LONG(rows)
        Z_PARAM_LONG(cols)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(type)
    ZEND_PARSE_PARAMETERS_END();
    if (!check_dims(rows, cols)) RETURN_THROWS();
    try {
        cv::Mat m((int)rows, (int)cols, (int)type);
        size_t want = m.total() * m.elemSize();
        if (want != ZSTR_LEN(bytes)) {
            zend_value_error("CV\\Mat::fromString(): expected %zu bytes for %ldx%ld of this type, got %zu",
                             want, (long)rows, (long)cols, ZSTR_LEN(bytes));
            RETURN_THROWS();
        }
        if (want) memcpy(m.data, ZSTR_VAL(bytes), want);
        phpcv::mat_new(return_value, m);
    } catch (...) {
        phpcv::rethrow();
    }
}

/* Mat::fromArray(array $values, ?int $type = null): Mat
 * A list of numbers (N x 1) or a list of rows. Without $type: CV_32S if every value is an int, else CV_64F. */
PHP_METHOD(CV_Mat, fromArray) {
    zval* values;
    zend_long type = 0;
    bool type_is_null = true;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(values)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(type, type_is_null)
    ZEND_PARSE_PARAMETERS_END();
    cv::Mat m;
    if (!phpcv::to(values, m)) {
        zend_argument_type_error(1, "must be a list of numbers or a list of equally long rows of numbers");
        RETURN_THROWS();
    }
    try {
        if (!type_is_null && !m.empty()) {
            if (m.depth() != CV_MAT_DEPTH((int)type)) {
                cv::Mat converted;
                m.convertTo(converted, CV_MAT_DEPTH((int)type));
                m = converted;
            }
            if (CV_MAT_CN((int)type) > 1) m = m.reshape(CV_MAT_CN((int)type));
        }
        phpcv::mat_new(return_value, m);
    } catch (...) {
        phpcv::rethrow();
    }
}

#define MAT_GETTER(name, expr)                         \
    PHP_METHOD(CV_Mat, name) {                         \
        ZEND_PARSE_PARAMETERS_NONE();                  \
        const cv::Mat& m = THIS_MAT;                   \
        (void)m;                                       \
        expr;                                          \
    }

MAT_GETTER(rows, RETURN_LONG(m.rows))
MAT_GETTER(cols, RETURN_LONG(m.cols))
MAT_GETTER(dims, RETURN_LONG(m.dims))
MAT_GETTER(channels, RETURN_LONG(m.channels()))
MAT_GETTER(type, RETURN_LONG(m.type()))
MAT_GETTER(depth, RETURN_LONG(m.depth()))
MAT_GETTER(total, RETURN_LONG((zend_long)m.total()))
MAT_GETTER(elemSize, RETURN_LONG((zend_long)m.elemSize()))
MAT_GETTER(empty, RETURN_BOOL(m.empty()))
MAT_GETTER(isContinuous, RETURN_BOOL(m.isContinuous()))

/* shape(): list<int>, one entry per dimension */
PHP_METHOD(CV_Mat, shape) {
    ZEND_PARSE_PARAMETERS_NONE();
    const cv::Mat& m = THIS_MAT;
    array_init_size(return_value, m.dims > 0 ? m.dims : 0);
    for (int i = 0; i < m.dims; i++) add_next_index_long(return_value, m.size[i]);
}

/* size(): [width, height] */
PHP_METHOD(CV_Mat, size) {
    ZEND_PARSE_PARAMETERS_NONE();
    const cv::Mat& m = THIS_MAT;
    array_init_size(return_value, 2);
    add_next_index_long(return_value, m.cols);
    add_next_index_long(return_value, m.rows);
}

/* clone(): Mat with its own copy of the pixels */
PHP_METHOD(CV_Mat, clone) {
    ZEND_PARSE_PARAMETERS_NONE();
    try {
        phpcv::mat_new(return_value, THIS_MAT.clone());
    } catch (...) {
        phpcv::rethrow();
    }
}

/* data(): string, the raw element bytes in row order */
PHP_METHOD(CV_Mat, data) {
    ZEND_PARSE_PARAMETERS_NONE();
    try {
        cv::Mat m = THIS_MAT;
        if (m.empty()) RETURN_EMPTY_STRING();
        if (!m.isContinuous()) m = m.clone();
        RETURN_STRINGL((const char*)m.data, m.total() * m.elemSize());
    } catch (...) {
        phpcv::rethrow();
    }
}

static bool mat_locate(const cv::Mat& m, zend_long row, zend_long col, zend_long channel, const uchar*& p) {
    if (m.dims > 2) {
        zend_throw_exception(phpcv_exception_ce, "Element access supports matrices of up to 2 dimensions", 0);
        return false;
    }
    if (row < 0 || row >= m.rows || col < 0 || col >= m.cols || channel < 0 || channel >= m.channels()) {
        zend_throw_exception_ex(phpcv_exception_ce, 0, "Element (%ld, %ld, %ld) is outside a %dx%d matrix with %d channels",
                                (long)row, (long)col, (long)channel, m.rows, m.cols, m.channels());
        return false;
    }
    p = m.ptr((int)row) + (size_t)col * m.elemSize() + (size_t)channel * m.elemSize1();
    return true;
}

/* at(int $row, int $col, int $channel = 0): int|float|bool */
PHP_METHOD(CV_Mat, at) {
    zend_long row, col, channel = 0;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_LONG(row)
        Z_PARAM_LONG(col)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(channel)
    ZEND_PARSE_PARAMETERS_END();
    const cv::Mat& m = THIS_MAT;
    const uchar* p;
    if (!mat_locate(m, row, col, channel, p)) RETURN_THROWS();
    elem_get(return_value, p, m.depth());
}

/* set(int $row, int $col, int|float $value, int $channel = 0): void */
PHP_METHOD(CV_Mat, set) {
    zend_long row, col, channel = 0;
    double value;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_LONG(row)
        Z_PARAM_LONG(col)
        Z_PARAM_DOUBLE(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(channel)
    ZEND_PARSE_PARAMETERS_END();
    cv::Mat& m = THIS_MAT;
    const uchar* p;
    if (!mat_locate(m, row, col, channel, p)) RETURN_THROWS();
    elem_set((uchar*)p, m.depth(), value);
}

/* toArray(): array, [row][col] or [row][col][channel]; a 1-D matrix gives a flat list */
PHP_METHOD(CV_Mat, toArray) {
    ZEND_PARSE_PARAMETERS_NONE();
    const cv::Mat& m = THIS_MAT;
    if (m.dims > 2) {
        zend_throw_exception(phpcv_exception_ce, "toArray() supports matrices of up to 2 dimensions", 0);
        RETURN_THROWS();
    }
    const int depth = m.depth(), cn = m.channels();
    const size_t step1 = m.elemSize1();
    const bool flat = m.dims == 1;
    array_init_size(return_value, flat ? m.cols : m.rows);
    for (int r = 0; r < m.rows; r++) {
        const uchar* p = m.ptr(r);
        zval row;
        if (!flat) array_init_size(&row, m.cols);
        zval* target = flat ? return_value : &row;
        for (int c = 0; c < m.cols; c++) {
            zval cell;
            if (cn == 1) {
                elem_get(&cell, p, depth);
                p += step1;
            } else {
                array_init_size(&cell, cn);
                for (int k = 0; k < cn; k++, p += step1) {
                    zval v;
                    elem_get(&v, p, depth);
                    add_next_index_zval(&cell, &v);
                }
            }
            add_next_index_zval(target, &cell);
        }
        if (!flat) add_next_index_zval(return_value, &row);
    }
}

/* roi(int $x, int $y, int $width, int $height): Mat, a view that shares pixels with this one */
PHP_METHOD(CV_Mat, roi) {
    zend_long x, y, w, h;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(w)
        Z_PARAM_LONG(h)
    ZEND_PARSE_PARAMETERS_END();
    try {
        phpcv::mat_new(return_value, THIS_MAT(cv::Rect((int)x, (int)y, (int)w, (int)h)));
    } catch (...) {
        phpcv::rethrow();
    }
}

/* convertTo(int $type, float $alpha = 1.0, float $beta = 0.0): Mat */
PHP_METHOD(CV_Mat, convertTo) {
    zend_long type;
    double alpha = 1.0, beta = 0.0;
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_LONG(type)
        Z_PARAM_OPTIONAL
        Z_PARAM_DOUBLE(alpha)
        Z_PARAM_DOUBLE(beta)
    ZEND_PARSE_PARAMETERS_END();
    try {
        cv::Mat out;
        THIS_MAT.convertTo(out, (int)type, alpha, beta);
        phpcv::mat_new(return_value, out);
    } catch (...) {
        phpcv::rethrow();
    }
}

/* copyTo(Mat $target, ?Mat $mask = null): void
 * Copies into $target's own pixels when it already has this size and type (so a roi() of a larger image is
 * written in place); otherwise $target is reallocated. */
PHP_METHOD(CV_Mat, copyTo) {
    zval* target;
    zval* mask = nullptr;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(target, phpcv_mat_ce)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(mask, phpcv_mat_ce)
    ZEND_PARSE_PARAMETERS_END();
    try {
        cv::Mat* dst = phpcv::mat_ptr(target);
        if (mask) THIS_MAT.copyTo(*dst, *phpcv::mat_ptr(mask));
        else THIS_MAT.copyTo(*dst);
    } catch (...) {
        phpcv::rethrow();
    }
}

/* reshape(int $channels, int $rows = 0): Mat, shares pixels */
PHP_METHOD(CV_Mat, reshape) {
    zend_long cn, rows = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_LONG(cn)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(rows)
    ZEND_PARSE_PARAMETERS_END();
    try {
        phpcv::mat_new(return_value, THIS_MAT.reshape((int)cn, (int)rows));
    } catch (...) {
        phpcv::rethrow();
    }
}

ZEND_BEGIN_ARG_INFO_EX(ai_mat_construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, rows, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, cols, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, type, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_MASK(0, value, MAY_BE_LONG | MAY_BE_DOUBLE | MAY_BE_ARRAY, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_fromString, 0, 3, CV\\Mat, 0)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, rows, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, cols, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, type, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_fromArray, 0, 1, CV\\Mat, 0)
    ZEND_ARG_TYPE_INFO(0, values, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, type, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_int, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_bool, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_array, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_string, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_mat, 0, 0, CV\\Mat, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(ai_mat_at, 0, 2, MAY_BE_LONG | MAY_BE_DOUBLE | MAY_BE_BOOL)
    ZEND_ARG_TYPE_INFO(0, row, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, col, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, channel, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_set, 0, 3, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, row, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, col, IS_LONG, 0)
    ZEND_ARG_TYPE_MASK(0, value, MAY_BE_LONG | MAY_BE_DOUBLE, NULL)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, channel, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_roi, 0, 4, CV\\Mat, 0)
    ZEND_ARG_TYPE_INFO(0, x, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, y, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, width, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, height, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_convertTo, 0, 1, CV\\Mat, 0)
    ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, alpha, IS_DOUBLE, 0, "1.0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, beta, IS_DOUBLE, 0, "0.0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(ai_mat_copyTo, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, target, CV\\Mat, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, mask, CV\\Mat, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(ai_mat_reshape, 0, 1, CV\\Mat, 0)
    ZEND_ARG_TYPE_INFO(0, channels, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, rows, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

static const zend_function_entry mat_methods[] = {
    PHP_ME(CV_Mat, __construct, ai_mat_construct, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, fromString, ai_mat_fromString, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(CV_Mat, fromArray, ai_mat_fromArray, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(CV_Mat, rows, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, cols, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, dims, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, channels, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, type, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, depth, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, total, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, elemSize, ai_mat_int, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, empty, ai_mat_bool, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, isContinuous, ai_mat_bool, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, shape, ai_mat_array, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, size, ai_mat_array, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, clone, ai_mat_mat, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, data, ai_mat_string, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, at, ai_mat_at, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, set, ai_mat_set, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, toArray, ai_mat_array, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, roi, ai_mat_roi, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, convertTo, ai_mat_convertTo, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, copyTo, ai_mat_copyTo, ZEND_ACC_PUBLIC)
    PHP_ME(CV_Mat, reshape, ai_mat_reshape, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void phpcv_register_mat(void) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "CV", "Mat", mat_methods);
    phpcv_mat_ce = zend_register_internal_class(&ce);
    phpcv_mat_ce->create_object = mat_create;
    phpcv_mat_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;

    memcpy(&mat_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    mat_handlers.offset = XtOffsetOf(mat_object, std);
    mat_handlers.free_obj = mat_free;
    mat_handlers.clone_obj = mat_clone;
}
