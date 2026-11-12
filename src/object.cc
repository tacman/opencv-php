/*
 * Shared behaviour of the generated classes: C++ data members exposed as PHP
 * properties.  The classes themselves are generated (src/generated).
 */
#include "php_opencv.h"

#include <cstring>
#include <unordered_map>

namespace phpcv {

/* Filled during MINIT, read-only afterwards. */
static std::unordered_map<zend_class_entry*, std::vector<Property>> property_tables;

void register_properties(zend_class_entry* ce, const Property* properties, size_t count) {
    property_tables[ce].assign(properties, properties + count);
}

static const Property* find_property(zend_class_entry* ce, zend_string* name) {
    for (; ce; ce = ce->parent) {
        auto table = property_tables.find(ce);
        if (table == property_tables.end()) continue;
        for (const Property& p : table->second) {
            if (zend_string_equals_cstr(name, p.name, strlen(p.name))) return &p;
        }
    }
    return nullptr;
}

static zval* read_property(zend_object* obj, zend_string* name, int type, void** cache_slot, zval* rv) {
    const Property* p = find_property(obj->ce, name);
    if (!p) return zend_std_read_property(obj, name, type, cache_slot, rv);
    ZVAL_NULL(rv);
    p->get(obj, rv);
    return rv;
}

static zval* write_property(zend_object* obj, zend_string* name, zval* value, void** cache_slot) {
    const Property* p = find_property(obj->ce, name);
    if (!p) return zend_std_write_property(obj, name, value, cache_slot);
    if (!p->set) {
        zend_throw_error(NULL, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), p->name);
        return value;
    }
    zval* v = value;
    ZVAL_DEREF(v);
    if (!p->set(obj, v) && !EG(exception)) {
        zend_type_error("Cannot assign %s to property %s::$%s of type %s",
                        zend_zval_value_name(v), ZSTR_VAL(obj->ce->name), p->name, p->type);
    }
    return value;
}

static int has_property(zend_object* obj, zend_string* name, int check, void** cache_slot) {
    const Property* p = find_property(obj->ce, name);
    if (!p) return zend_std_has_property(obj, name, check, cache_slot);
    if (check == ZEND_PROPERTY_EXISTS) return 1;
    zval value;
    ZVAL_NULL(&value);
    p->get(obj, &value);
    int result = check == ZEND_PROPERTY_NOT_EMPTY ? zend_is_true(&value) : Z_TYPE(value) != IS_NULL;
    zval_ptr_dtor(&value);
    return result;
}

/* No direct pointer to a C++ member: force reads and writes through the handlers. */
static zval* get_property_ptr_ptr(zend_object* obj, zend_string* name, int type, void** cache_slot) {
    if (find_property(obj->ce, name)) return NULL;
    return zend_std_get_property_ptr_ptr(obj, name, type, cache_slot);
}

/* var_dump(), print_r() and (array) show the C++ members. */
static HashTable* get_properties_for(zend_object* obj, zend_prop_purpose purpose) {
    if (purpose != ZEND_PROP_PURPOSE_DEBUG && purpose != ZEND_PROP_PURPOSE_ARRAY_CAST &&
        purpose != ZEND_PROP_PURPOSE_VAR_EXPORT && purpose != ZEND_PROP_PURPOSE_JSON) {
        return zend_std_get_properties_for(obj, purpose);
    }
    HashTable* ht = zend_new_array(8);
    std::vector<zend_class_entry*> chain;
    for (zend_class_entry* ce = obj->ce; ce; ce = ce->parent) chain.push_back(ce);
    for (auto ce = chain.rbegin(); ce != chain.rend(); ++ce) {
        auto table = property_tables.find(*ce);
        if (table == property_tables.end()) continue;
        for (const Property& p : table->second) {
            zval value;
            ZVAL_NULL(&value);
            p.get(obj, &value);
            if (EG(exception)) {
                zval_ptr_dtor(&value);
                return ht;
            }
            zend_hash_str_update(ht, p.name, strlen(p.name), &value);
        }
    }
    return ht;
}

void init_object_handlers(zend_object_handlers* handlers, int offset, zend_object_free_obj_t free_obj) {
    memcpy(handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    handlers->offset = offset;
    handlers->free_obj = free_obj;
    handlers->clone_obj = NULL;
    handlers->read_property = read_property;
    handlers->write_property = write_property;
    handlers->has_property = has_property;
    handlers->get_property_ptr_ptr = get_property_ptr_ptr;
    handlers->get_properties_for = get_properties_for;
}

void throw_uninitialized(zend_class_entry* ce) {
    if (!EG(exception)) {
        zend_throw_error(NULL, "%s object has not been initialized by its constructor", ZSTR_VAL(ce->name));
    }
}

} // namespace phpcv

/* __construct of classes OpenCV only hands out through factory functions. */
ZEND_NAMED_FUNCTION(phpcv_no_constructor) {
    zend_throw_error(NULL, "%s cannot be constructed directly; use the function that creates it",
                     ZSTR_VAL(Z_OBJCE_P(ZEND_THIS)->name));
}
