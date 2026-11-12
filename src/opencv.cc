/*
 * Module entry.  The function table and the enum constants come from the
 * generated registry (src/generated/registry.cc).
 */
#include "php_opencv.h"

zend_class_entry* phpcv_exception_ce;

static void register_type_constants(int module_number) {
    struct Depth { const char* name; int depth; };
    static const Depth depths[] = {
        {"8U", CV_8U}, {"8S", CV_8S}, {"16U", CV_16U}, {"16S", CV_16S},
        {"32S", CV_32S}, {"32F", CV_32F}, {"64F", CV_64F}, {"16F", CV_16F},
        {"16BF", CV_16BF}, {"Bool", CV_Bool}, {"64U", CV_64U}, {"64S", CV_64S},
        {"32U", CV_32U},
    };
    for (const Depth& d : depths) {
        std::string base = std::string("CV\\CV_") + d.name;
        zend_register_long_constant(base.c_str(), base.size(), d.depth, CONST_PERSISTENT, module_number);
        for (int cn = 1; cn <= 4; cn++) {
            std::string name = base + "C" + std::to_string(cn);
            zend_register_long_constant(name.c_str(), name.size(), CV_MAKETYPE(d.depth, cn), CONST_PERSISTENT, module_number);
        }
    }
}

PHP_MINIT_FUNCTION(opencv) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "CV", "Exception", NULL);
    phpcv_exception_ce = zend_register_internal_class_ex(&ce, zend_ce_exception);

    phpcv_register_mat();
    phpcv_register_classes();
    register_type_constants(module_number);
    phpcv_register_constants(module_number);
    return SUCCESS;
}

PHP_MINFO_FUNCTION(opencv) {
    char buf[32];
    php_info_print_table_start();
    php_info_print_table_row(2, "OpenCV support", "enabled");
    php_info_print_table_row(2, "Extension version", PHP_OPENCV_VERSION);
    php_info_print_table_row(2, "OpenCV library version", cv::getVersionString().c_str());
    php_info_print_table_row(2, "OpenCV headers version", CV_VERSION);
    snprintf(buf, sizeof(buf), "%d", phpcv_function_count);
    php_info_print_table_row(2, "Generated functions", buf);
    snprintf(buf, sizeof(buf), "%d", phpcv_class_count);
    php_info_print_table_row(2, "Generated classes", buf);
    snprintf(buf, sizeof(buf), "%d", phpcv_constant_count);
    php_info_print_table_row(2, "Generated constants", buf);
    php_info_print_table_end();
}

zend_module_entry opencv_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_OPENCV_EXTNAME,
    phpcv_functions,
    PHP_MINIT(opencv),
    NULL,
    NULL,
    NULL,
    PHP_MINFO(opencv),
    PHP_OPENCV_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_OPENCV
extern "C" {
ZEND_GET_MODULE(opencv)
}
#endif
