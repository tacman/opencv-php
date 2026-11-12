#!/usr/bin/env php
<?php
/**
 * Generate the PHP extension sources from the API dump.
 *
 *   php gen/generate.php [api/opencv-5.1.0.json]
 *
 * Reads the declarations OpenCV's header parser produced (gen/dump_decls.py)
 * and writes
 *   src/generated/classes.h     class entries and converters for class types
 *   src/generated/<module>.cc   one wrapper per C++ function and method
 *   src/generated/registry.cc   the function table, class and constant registration
 *   stubs/opencv.stub.php       signatures for IDEs and static analysis
 *   api/coverage.json           what was wrapped, what was skipped and why
 *
 * This is the PHP counterpart of modules/python/src2/gen2.py and
 * modules/js/generator/embindgen.py.  A function is wrapped when every type in
 * its signature has a converter; anything else is skipped and reported, so
 * coverage grows by adding converters, not by editing lists.
 *
 * Three kinds of C++ class come out of the parser:
 *   - "map" structs (CV_EXPORTS_W_MAP, e.g. Moments) become PHP arrays keyed by
 *     member name;
 *   - everything else becomes a PHP class holding a cv::Ptr<T>.  "Simple"
 *     classes (CV_EXPORTS_W_SIMPLE, e.g. KeyPoint) are also converted by value;
 *   - a few value types have a hand-written array form instead (TYPES below).
 */
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

// Parser type name => [C++ type, PHP stub type, wording for error messages].
const TYPES = [
    'bool'          => ['bool', 'bool', 'bool'],
    'int'           => ['int', 'int', 'int'],
    'uchar'         => ['unsigned char', 'int', 'int'],
    'int64'         => ['int64_t', 'int', 'int'],
    'int64_t'       => ['int64_t', 'int', 'int'],
    'size_t'        => ['size_t', 'int', 'int'],
    'double'        => ['double', 'float', 'float'],
    'float'         => ['float', 'float', 'float'],
    'String'        => ['std::string', 'string', 'string'],
    'string'        => ['std::string', 'string', 'string'],
    'std::string'   => ['std::string', 'string', 'string'],
    'c_string'      => ['std::string', 'string', 'string'],
    'Mat'           => ['cv::Mat', 'Mat|array|string', 'CV\\\\Mat|array|string'],
    'Scalar'        => ['cv::Scalar', 'array|int|float', 'int|float|array of up to 4 numbers'],
    'Point'         => ['cv::Point', 'array', 'array [x, y]'],
    'Point2i'       => ['cv::Point', 'array', 'array [x, y]'],
    'Point2f'       => ['cv::Point2f', 'array', 'array [x, y]'],
    'Point2d'       => ['cv::Point2d', 'array', 'array [x, y]'],
    'Point3i'       => ['cv::Point3i', 'array', 'array [x, y, z]'],
    'Point3f'       => ['cv::Point3f', 'array', 'array [x, y, z]'],
    'Point3d'       => ['cv::Point3d', 'array', 'array [x, y, z]'],
    'NativeByteArray' => ['std::string', 'string', 'string'],
    'Size'          => ['cv::Size', 'array', 'array [width, height]'],
    'Size2f'        => ['cv::Size2f', 'array', 'array [width, height]'],
    'Rect'          => ['cv::Rect', 'array', 'array [x, y, width, height]'],
    'Rect2f'        => ['cv::Rect2f', 'array', 'array [x, y, width, height]'],
    'Rect2d'        => ['cv::Rect2d', 'array', 'array [x, y, width, height]'],
    'Range'         => ['cv::Range', 'array', 'array [start, end]'],
    'TermCriteria'  => ['cv::TermCriteria', 'array', 'array [type, maxCount, epsilon]'],
    'RotatedRect'   => ['cv::RotatedRect', 'array', 'array [[cx, cy], [width, height], angle]'],
];

/** PHP keywords that cannot name a function, even inside a namespace. */
const RESERVED = ['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
    'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
    'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'finally',
    'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
    'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
    'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch',
    'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield'];

/** Enum scopes that are implementation detail, not API. */
const SKIP_CONST_SCOPES = '/^cv\.(_InputArray|_OutputArray|Mat|UMat|UMatData|SparseMat|detail|Param|MatShape)\b/';

/** Classes that are not generated: hand-written, or not usable from PHP yet. */
const SKIP_CLASSES = [
    'cv.MatShape' => 'tensor shapes are not mapped yet',
];

final class BindingGenerator
{
    private array $api;
    /** @var array<string,array> dotted C++ name => class model */
    private array $classes = [];
    /** @var array<string,string> type name as it appears in signatures => dotted class name */
    private array $classNames = [];
    /** @var array<string,string> enum type name as it appears in signatures => C++ type */
    private array $enums = [];
    /** @var list<string> dotted namespaces, longest first */
    private array $namespaces;
    /** @var array<string,array> lower-cased "ns\name" => function */
    private array $functions = [];
    private array $skipped = [];
    private array $constants = [];
    private array $unsupportedTypes = [];

    public function __construct(private string $apiFile)
    {
        $this->api = json_decode(file_get_contents($apiFile), true, flags: JSON_THROW_ON_ERROR);
        $this->namespaces = $this->api['namespaces'];
        usort($this->namespaces, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function run(): void
    {
        // Pass 1: names of classes and enums, so signatures can refer to them.
        foreach ($this->api['modules'] as $module => $data) {
            foreach ($data['decls'] as $decl) {
                if (preg_match('/^(class|struct) (\S+)/', $decl['name'], $m)) {
                    $this->declareClass($module, $m[2], $decl);
                } elseif (preg_match('/^enum (?:class |struct )?(\S+)/', $decl['name'], $m) && !str_contains($m[1], '<unnamed>')) {
                    $cpp = str_replace('.', '::', $m[1]);
                    $parts = explode('.', $m[1]);
                    $this->enums[end($parts)] ??= $cpp;
                    $this->enums[implode('_', array_slice($parts, 1))] = $cpp;
                }
            }
        }
        $this->linkClasses();
        // Pass 2: members.
        foreach ($this->api['modules'] as $module => $data) {
            foreach ($data['decls'] as $decl) {
                if (str_starts_with($decl['name'], 'enum ')) {
                    $this->addConstants($module, $decl);
                } elseif (!preg_match('/^(class|struct|const) /', $decl['name'])) {
                    $this->addFunction($module, $decl);
                }
            }
        }
        foreach ($this->classes as &$class) {
            $this->addProperties($class);
        }
        unset($class);

        @mkdir(ROOT . '/src/generated', recursive: true);
        @mkdir(ROOT . '/stubs', recursive: true);
        $this->write('src/generated/classes.h', $this->classesHeader());
        foreach (array_keys($this->api['modules']) as $module) {
            $this->write("src/generated/$module.cc", $this->moduleSource($module));
        }
        $this->write('src/generated/registry.cc', $this->registrySource());
        $this->write('stubs/opencv.stub.php', $this->stubSource());
        $this->write('api/coverage.json', json_encode($this->coverage(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->report();
    }

    // -- model: classes --------------------------------------------------------

    private function declareClass(string $module, string $dotted, array $decl): void
    {
        $parts = explode('.', $dotted);
        $short = end($parts);
        if (isset(TYPES[$short])) {
            return; // has a hand-written array form
        }
        $ns = 'cv';
        foreach ($this->namespaces as $candidate) {
            if (str_starts_with($dotted, $candidate . '.')) {
                $ns = $candidate;
                break;
            }
        }
        $local = str_replace('.', '_', substr($dotted, strlen($ns) + 1));
        $bases = [];
        if (preg_match('/^:\s*(.+)$/', trim($decl['ret']), $m)) {
            foreach (explode(',', $m[1]) as $base) {
                $bases[] = str_replace('::', '.', trim($base));
            }
        }
        $this->classes[$dotted] = [
            'dotted' => $dotted,
            'module' => $module,
            'header' => $decl['header'],
            'cpp' => str_replace('.', '::', $dotted),
            'id' => preg_replace('/\W+/', '_', substr($dotted, 3)),
            'ns' => $this->phpNamespace($ns),
            'name' => $local,
            'bases' => $bases,
            'parent' => null,
            'children' => [],
            'simple' => in_array('/Simple', $decl['mods'], true),
            'map' => in_array('/Map', $decl['mods'], true),
            'skip' => SKIP_CLASSES[$dotted] ?? null,
            'rawProps' => $decl['args'],
            'props' => [],
            'methods' => [],
            'doc' => $decl['doc'],
        ];
        $this->classNames[$short] ??= $dotted;
        $this->classNames[implode('_', array_slice($parts, 1))] = $dotted;
    }

    private function linkClasses(): void
    {
        foreach ($this->classes as &$class) {
            foreach ($class['bases'] as $base) {
                if (isset($this->classes[$base]) && !$this->classes[$base]['map'] && !$this->classes[$base]['skip']) {
                    $class['parent'] = $base;
                    break;
                }
            }
        }
        unset($class);
        foreach ($this->classes as $dotted => $class) {
            if ($class['parent'] !== null) {
                $this->classes[$class['parent']]['children'][] = $dotted;
            }
        }
    }

    /** Classes that become PHP classes, parents before children. */
    private function objectClasses(): array
    {
        $ordered = [];
        $visit = function (string $dotted) use (&$visit, &$ordered): void {
            $class = $this->classes[$dotted];
            if (isset($ordered[$dotted]) || $class['map'] || $class['skip']) {
                return;
            }
            if ($class['parent'] !== null) {
                $visit($class['parent']);
            }
            $ordered[$dotted] = $class;
        };
        foreach (array_keys($this->classes) as $dotted) {
            $visit($dotted);
        }
        return $ordered;
    }

    private function addProperties(array &$class): void
    {
        foreach ($class['rawProps'] as $prop) {
            $type = $this->type($prop['type']);
            if ($type === null || $type['deref']) {
                $this->skipped[] = [
                    'module' => $class['module'],
                    'name' => $class['dotted'] . '.' . $prop['name'],
                    'reason' => "property type {$prop['type']}",
                ];
                continue;
            }
            $class['props'][] = [
                'name' => $prop['name'],
                'type' => $type,
                'writable' => in_array('/RW', $prop['mods'], true) || $class['map'],
            ];
        }
    }

    // -- model: types ----------------------------------------------------------

    /** @return array{cpp:string,php:string,label:string,deref:bool}|null */
    private function type(string $name): ?array
    {
        $name = trim($name);
        $make = fn (string $cpp, string $php, string $label, bool $deref = false) =>
            ['cpp' => $cpp, 'php' => $php, 'label' => $label, 'deref' => $deref];
        if (isset(TYPES[$name])) {
            return $make(...TYPES[$name]);
        }
        if (preg_match('/^Vec([2-6])([bsifdw])$/', $name, $m)) {
            return $make("cv::$name", 'array', "array of {$m[1]} numbers");
        }
        if (isset($this->enums[$name])) {
            return $make($this->enums[$name], 'int', 'int');
        }
        if (str_starts_with($name, 'vector_')) {
            $innerName = substr($name, 7);
            if ($innerName === 'uchar' || $innerName === 'char') {
                return $make('std::vector<unsigned char>', 'string', 'string');
            }
            $inner = $this->type($innerName);
            if ($inner === null || $inner['deref']) {
                return null;
            }
            return $make("std::vector<{$inner['cpp']}>", 'array', 'array');
        }
        $pointer = str_starts_with($name, 'Ptr_');
        $className = $pointer ? substr($name, 4) : $name;
        $class = $this->classes[$this->classNames[$className] ?? ''] ?? null;
        if ($class === null || $class['skip']) {
            return null;
        }
        if ($class['map']) {
            return $pointer ? null : $make($class['cpp'], 'array', 'array');
        }
        $php = $class['ns'] === 'CV' ? $class['name'] : '\\' . $class['ns'] . '\\' . $class['name'];
        $label = str_replace('\\', '\\\\', $class['ns'] . '\\' . $class['name']);
        if ($pointer) {
            return $make("cv::Ptr<{$class['cpp']}>", $php, $label);
        }
        if ($class['simple']) {
            return $make($class['cpp'], $php, $label);
        }
        // Passed by reference in C++: hold the pointer, hand over *pointer.
        return $make("cv::Ptr<{$class['cpp']}>", $php, $label, true);
    }

    private function phpNamespace(string $dotted): string
    {
        return 'CV' . str_replace('.', '\\', substr($dotted, 2));
    }

    // -- model: functions and methods ------------------------------------------

    private function addFunction(string $module, array $decl): void
    {
        $parts = explode('.', $decl['name']);
        $name = array_pop($parts);
        $scope = implode('.', $parts);
        $skip = function (string $reason) use ($module, $decl): void {
            $this->skipped[] = ['module' => $module, 'name' => $decl['name'], 'reason' => $reason];
        };

        $class = $this->classes[$scope] ?? null;
        if ($class === null && !in_array($scope, $this->api['namespaces'], true)) {
            $skip(isset(TYPES[end($parts)]) ? 'method of a type mapped to a PHP array' : "unknown scope $scope");
            return;
        }
        if ($class !== null && ($class['map'] || $class['skip'])) {
            $skip($class['skip'] ?? 'method of a struct mapped to a PHP array');
            return;
        }

        $phpName = $name;
        $static = false;
        foreach ($decl['mods'] as $mod) {
            if ($mod === '/phantom') {
                $skip('phantom declaration');
                return;
            }
            if ($mod === '/S') {
                $static = true;
            }
            if ($mod[0] === '=') {
                $phpName = substr($mod, 1);
            }
        }
        if (str_starts_with($phpName, 'operator')) {
            $skip('operator');
            return;
        }
        $isCtor = $class !== null && $name === end($parts);

        // Default values are written relative to the declaration's namespace.
        $using = null;
        foreach ($this->namespaces as $candidate) {
            if (str_starts_with($decl['name'], $candidate . '.')) {
                $using = $candidate === 'cv' ? null : str_replace('.', '::', $candidate);
                break;
            }
        }
        $variant = ['call' => str_replace('.', '::', $decl['name']), 'method' => $name, 'ret' => null, 'args' => [],
            'doc' => $decl['doc'], 'using' => $using];
        $unsupported = function (string $typeName, string $what) use ($skip): void {
            $this->unsupportedTypes[$typeName] = ($this->unsupportedTypes[$typeName] ?? 0) + 1;
            $skip($what);
        };
        if (!$isCtor && $decl['ret'] !== '' && $decl['ret'] !== 'void') {
            $variant['ret'] = $this->type($decl['ret']);
            if ($variant['ret'] === null) {
                $unsupported($decl['ret'], "return type {$decl['ret']}");
                return;
            }
            if ($variant['ret']['deref']) {
                $skip("returns {$decl['ret']} by value");
                return;
            }
        }
        foreach ($decl['args'] as $arg) {
            $typeName = $arg['type'];
            $out = in_array('/O', $arg['mods'], true);
            $inout = in_array('/IO', $arg['mods'], true);
            $pointer = false;
            if (str_ends_with($typeName, '*')) {
                if (!$out) {
                    $unsupported($typeName, "pointer argument $typeName {$arg['name']}");
                    return;
                }
                $pointer = true;
                $typeName = rtrim($typeName, '*');
            }
            $type = $this->type($typeName);
            if ($type === null) {
                $unsupported($typeName, "argument type $typeName {$arg['name']}");
                return;
            }
            if ($type['deref'] && ($out || $pointer)) {
                $skip("output argument of class type $typeName");
                return;
            }
            $default = $arg['default'] === '' || $arg['default'] === [] ? null : $arg['default'];
            if ($default === 'noArray()' || $default === 'cv::noArray()') {
                $default = 'cv::Mat()';
            }
            if ($default !== null && preg_match('/^vector_\w+\(\)$/', $default)) {
                $default = $type['cpp'] . '()';
            }
            if ($type['deref'] && $default !== null) {
                $skip("default value for class argument $typeName {$arg['name']}");
                return;
            }
            $variant['args'][] = [
                'name' => $arg['name'],
                'type' => $type,
                'kind' => $out ? 'out' : ($inout ? 'inout' : 'in'),
                'pointer' => $pointer,
                'default' => $default,
                'arithm' => in_array('/AOS', $arg['mods'], true),
                'cstring' => $typeName === 'c_string',
            ];
        }

        // PHP parameters are the inputs, in declaration order.  A parameter is
        // optional only if every parameter after it is optional too.
        $inputs = array_values(array_filter($variant['args'], fn ($a) => $a['kind'] !== 'out'));
        $required = 0;
        foreach ($inputs as $i => $a) {
            if ($a['default'] === null) {
                $required = $i + 1;
            }
        }
        $variant['required'] = $required;
        $variant['signature'] = implode(',', array_map(fn ($a) => $a['type']['cpp'], $inputs));

        if ($class !== null) {
            $this->addMethod($scope, $isCtor ? '__construct' : $phpName, $static, $variant, $skip);
            return;
        }

        if (in_array(strtolower($phpName), RESERVED, true)) {
            $phpName .= '_';
        }
        $phpNs = $this->phpNamespace($scope);
        $key = strtolower("$phpNs\\$phpName");
        if (!isset($this->functions[$key])) {
            $this->functions[$key] = [
                'module' => $module,
                'ns' => $phpNs,
                'name' => $phpName,
                'qualified' => "$phpNs\\$phpName",
                'symbol' => 'phpcv_fn_' . preg_replace('/\W+/', '_', strtolower(substr($scope, 2) . '_' . $phpName)),
                'kind' => 'function',
                'headers' => [],
                'variants' => [],
            ];
        }
        if ($this->acceptVariant($this->functions[$key], $phpName, $variant, $skip)) {
            $this->functions[$key]['headers'][$decl['header']] = true;
        }
    }

    private function addMethod(string $scope, string $phpName, bool $static, array $variant, callable $skip): void
    {
        $class = &$this->classes[$scope];
        $ctor = $phpName === '__construct';
        $key = strtolower($phpName);
        if (!isset($class['methods'][$key])) {
            $class['methods'][$key] = [
                'module' => $class['module'],
                'name' => $phpName,
                'qualified' => $class['ns'] . '\\' . $class['name'] . '::' . $phpName,
                'symbol' => 'phpcv_m_' . $class['id'] . '_' . preg_replace('/\W+/', '_', $key),
                'kind' => $ctor ? 'ctor' : ($static ? 'static' : 'method'),
                'class' => $scope,
                'headers' => [],
                'variants' => [],
            ];
        }
        $this->acceptVariant($class['methods'][$key], $phpName, $variant, $skip);
    }

    private function acceptVariant(array &$fn, string $phpName, array $variant, callable $skip): bool
    {
        if ($fn['name'] !== $phpName) {
            $skip("name collides with {$fn['name']} (PHP names are case-insensitive)");
            return false;
        }
        foreach ($fn['variants'] as $existing) {
            if ($existing['signature'] === $variant['signature']) {
                $skip('same PHP signature as an earlier overload');
                return false;
            }
        }
        $fn['variants'][] = $variant;
        return true;
    }

    private function addConstants(string $module, array $decl): void
    {
        foreach ($decl['args'] as $member) {
            $dotted = substr($member['type'], strlen('const '));
            if (preg_match(SKIP_CONST_SCOPES, $dotted)) {
                continue;
            }
            $ns = 'cv';
            foreach ($this->namespaces as $candidate) {
                if (str_starts_with($dotted, $candidate . '.')) {
                    $ns = $candidate;
                    break;
                }
            }
            $local = str_replace('.', '_', substr($dotted, strlen($ns) + 1));
            $phpName = $this->phpNamespace($ns) . '\\' . $local;
            $this->constants[$phpName] ??= [
                'module' => $module,
                'ns' => $this->phpNamespace($ns),
                'name' => $local,
                'cpp' => str_replace('.', '::', $dotted),
                'value' => $member['name'],
                'header' => $decl['header'],
            ];
        }
    }

    // -- C++: classes.h --------------------------------------------------------

    private function classesHeader(): string
    {
        $headers = [];
        foreach (array_keys($this->api['modules']) as $module) {
            $headers["opencv2/$module.hpp"] = true;
        }
        foreach ($this->classes as $class) {
            $headers[$class['header']] = true;
        }
        $out = $this->banner()
            . "/* Included from php_opencv.h, outside any namespace, before the container templates. */\n"
            . "#ifndef PHP_OPENCV_GENERATED_CLASSES_H\n#define PHP_OPENCV_GENERATED_CLASSES_H\n\n";
        foreach (array_keys($headers) as $header) {
            $out .= "#include <$header>\n";
        }
        $out .= "\n";
        foreach ($this->objectClasses() as $class) {
            $out .= "extern zend_class_entry* phpcv_ce_{$class['id']};\n"
                . "void phpcv_register_class_{$class['id']}(void);\n"
                . "cv::Ptr<{$class['cpp']}> phpcv_ptr_{$class['id']}(zval* zv);\n";
        }
        $out .= "\nnamespace phpcv {\n";
        foreach ($this->classes as $class) {
            if ($class['skip']) {
                continue;
            }
            if (!$class['map']) {
                $out .= "bool to(zval* zv, cv::Ptr<{$class['cpp']}>& v);\n"
                    . "void from(zval* rv, const cv::Ptr<{$class['cpp']}>& v);\n";
            }
            if ($class['map'] || $class['simple']) {
                $out .= "bool to(zval* zv, {$class['cpp']}& v);\nvoid from(zval* rv, const {$class['cpp']}& v);\n";
            }
        }
        return $out . "} // namespace phpcv\n\n#endif\n";
    }

    // -- C++: module sources ---------------------------------------------------

    private function moduleSource(string $module): string
    {
        $functions = array_values(array_filter($this->functions, fn ($fn) => $fn['module'] === $module && $fn['variants']));
        // The umbrella header, plus any other header a wrapped function was
        // declared in (e.g. opencv2/imgproc/bindings.hpp, which the umbrella
        // header does not pull in).
        $headers = ["opencv2/$module.hpp" => true];
        foreach ($functions as $fn) {
            $headers += $fn['headers'];
        }
        $out = $this->banner() . "#include \"../php_opencv.h\"\n";
        foreach (array_keys($headers) as $header) {
            $out .= "#include <$header>\n";
        }
        $out .= "\n#include <cfloat>\n#include <climits>\n\nusing namespace cv;\n\n"
            . "ZEND_NAMED_FUNCTION(phpcv_no_constructor);\n\n";

        foreach ($this->classes as $class) {
            if ($class['module'] !== $module || $class['skip']) {
                continue;
            }
            $out .= $class['map'] ? $this->mapClassSource($class) : $this->objectClassSource($class);
        }
        foreach ($functions as $fn) {
            $out .= $this->callableSource($fn);
        }
        return $out;
    }

    /** A struct exchanged with PHP as an array keyed by member name. */
    private function mapClassSource(array $class): string
    {
        $cpp = $class['cpp'];
        $from = "    array_init_size(rv, " . count($class['props']) . ");\n    zval item;\n";
        $to = "    if (Z_TYPE_P(zv) != IS_ARRAY) return false;\n    zval* field;\n";
        foreach ($class['props'] as $p) {
            $n = $p['name'];
            $len = strlen($n);
            $from .= "    from(&item, v.$n);\n    add_assoc_zval_ex(rv, \"$n\", $len, &item);\n";
            $to .= "    if ((field = zend_hash_str_find(Z_ARRVAL_P(zv), \"$n\", $len)) && !to(field, v.$n)) return false;\n";
        }
        return "namespace phpcv {\n/* Keys that are missing keep the C++ default. */\n"
            . "bool to(zval* zv, $cpp& v)\n{\n$to    return true;\n}\n\n"
            . "void from(zval* rv, const $cpp& v)\n{\n$from}\n} // namespace phpcv\n\n";
    }

    private function objectClassSource(array $class): string
    {
        $id = $class['id'];
        $cpp = $class['cpp'];
        $phpClass = str_replace('\\', '\\\\', $class['ns'] . '\\' . $class['name']);
        $out = "/* ---- {$class['ns']}\\{$class['name']} " . str_repeat('-', max(3, 60 - strlen($class['name']))) . " */\n\n"
            . "zend_class_entry* phpcv_ce_$id;\n\n";

        // Upcasts happen in C++, where the compiler knows the class layout:
        // an object of a generated subclass yields its own Ptr, converted.
        $out .= "cv::Ptr<$cpp> phpcv_ptr_$id(zval* zv)\n{\n"
            . "    if (Z_TYPE_P(zv) != IS_OBJECT) return cv::Ptr<$cpp>();\n"
            . "    zend_class_entry* ce = Z_OBJCE_P(zv);\n";
        foreach ($class['children'] as $child) {
            $childId = $this->classes[$child]['id'];
            $out .= "    if (instanceof_function(ce, phpcv_ce_$childId)) return phpcv_ptr_$childId(zv);\n";
        }
        $out .= "    if (instanceof_function(ce, phpcv_ce_$id)) return phpcv::ObjectType<$cpp>::fetch(Z_OBJ_P(zv))->ptr;\n"
            . "    return cv::Ptr<$cpp>();\n}\n\n"
            . "namespace phpcv {\n"
            . "bool to(zval* zv, cv::Ptr<$cpp>& v)\n{\n    ZVAL_DEREF(zv);\n    v = phpcv_ptr_$id(zv);\n    return (bool)v;\n}\n\n"
            . "void from(zval* rv, const cv::Ptr<$cpp>& v)\n{\n    ObjectType<$cpp>::wrap(rv, phpcv_ce_$id, v);\n}\n";
        if ($class['simple']) {
            $out .= "\nbool to(zval* zv, $cpp& v)\n{\n    ZVAL_DEREF(zv);\n    cv::Ptr<$cpp> p = phpcv_ptr_$id(zv);\n"
                . "    if (!p) return false;\n    v = *p;\n    return true;\n}\n\n"
                . "void from(zval* rv, const $cpp& v)\n{\n    ObjectType<$cpp>::wrap(rv, phpcv_ce_$id, cv::makePtr<$cpp>(v));\n}\n";
        }
        $out .= "} // namespace phpcv\n\n";

        // Properties.
        $table = '';
        foreach ($class['props'] as $p) {
            $n = $p['name'];
            $self = "    zval self;\n    ZVAL_OBJ(&self, obj);\n    cv::Ptr<$cpp> p = phpcv_ptr_$id(&self);\n";
            $out .= "static void phpcv_get_{$id}_$n(zend_object* obj, zval* rv)\n{\n$self"
                . "    if (!p) { phpcv::throw_uninitialized(obj->ce); return; }\n    phpcv::from(rv, p->$n);\n}\n\n";
            $setter = 'NULL';
            if ($p['writable']) {
                $setter = "phpcv_set_{$id}_$n";
                $out .= "static bool $setter(zend_object* obj, zval* value)\n{\n$self"
                    . "    if (!p) { phpcv::throw_uninitialized(obj->ce); return false; }\n    return phpcv::to(value, p->$n);\n}\n\n";
            }
            $table .= "    {\"$n\", phpcv_get_{$id}_$n, $setter, \"{$p['type']['label']}\"},\n";
        }
        if ($table !== '') {
            $out .= "static const phpcv::Property phpcv_props_{$id}[] = {\n$table};\n\n";
        }

        // Methods.
        $entries = '';
        $methods = $class['methods'];
        $hasCtor = isset($methods['__construct']) && $methods['__construct']['variants'];
        foreach ($methods as $fn) {
            if (!$fn['variants']) {
                continue;
            }
            // PHP checks an overriding method's signature against its parent's;
            // C++ does not.  An override is declared variadic, which is
            // compatible with whatever the parent declares.
            $overrides = false;
            for ($up = $class['parent']; $up !== null && !$overrides; $up = $this->classes[$up]['parent']) {
                $overrides = $fn['kind'] !== 'ctor' && !empty($this->classes[$up]['methods'][strtolower($fn['name'])]['variants']);
            }
            $out .= $this->callableSource($fn) . $this->arginfoSource($fn, $overrides);
            $flags = 'ZEND_ACC_PUBLIC' . ($fn['kind'] === 'static' ? ' | ZEND_ACC_STATIC' : '');
            $entries .= "    ZEND_FENTRY({$fn['name']}, {$fn['symbol']}, ai_{$fn['symbol']}, $flags)\n";
        }
        if (!$hasCtor) {
            if ($class['simple']) {
                // A plain struct: default-construct it, then set its properties.
                $out .= "ZEND_NAMED_FUNCTION(phpcv_m_{$id}___construct)\n{\n    ZEND_PARSE_PARAMETERS_NONE();\n"
                    . "    try {\n        phpcv::ObjectType<$cpp>::fetch(Z_OBJ_P(ZEND_THIS))->ptr = cv::makePtr<$cpp>();\n"
                    . "    } catch (...) {\n        phpcv::rethrow();\n    }\n}\n\n"
                    . "ZEND_BEGIN_ARG_INFO_EX(ai_phpcv_m_{$id}___construct, 0, 0, 0)\nZEND_END_ARG_INFO()\n\n";
                $entries .= "    ZEND_FENTRY(__construct, phpcv_m_{$id}___construct, ai_phpcv_m_{$id}___construct, ZEND_ACC_PUBLIC)\n";
            } else {
                $out .= "ZEND_BEGIN_ARG_INFO_EX(ai_phpcv_m_{$id}___construct, 0, 0, 0)\nZEND_END_ARG_INFO()\n\n";
                $entries .= "    ZEND_FENTRY(__construct, phpcv_no_constructor, ai_phpcv_m_{$id}___construct, ZEND_ACC_PUBLIC)\n";
            }
        }
        $parent = $class['parent'] !== null ? 'phpcv_ce_' . $this->classes[$class['parent']]['id'] : 'NULL';
        $out .= "static const zend_function_entry phpcv_methods_{$id}[] = {\n$entries    PHP_FE_END\n};\n\n"
            . "void phpcv_register_class_$id(void)\n{\n    zend_class_entry ce;\n"
            . "    INIT_CLASS_ENTRY(ce, \"$phpClass\", phpcv_methods_$id);\n"
            . "    phpcv_ce_$id = zend_register_internal_class_ex(&ce, $parent);\n"
            . "    phpcv::ObjectType<$cpp>::setup(phpcv_ce_$id);\n";
        if ($table !== '') {
            $out .= "    phpcv::register_properties(phpcv_ce_$id, phpcv_props_$id, " . count($class['props']) . ");\n";
        }
        return $out . "}\n\n";
    }

    /** The variants of one PHP function or method, and the entry point that tries them in turn. */
    private function callableSource(array $fn): string
    {
        $class = isset($fn['class']) ? $this->classes[$fn['class']] : null;
        $cpp = $class['cpp'] ?? '';
        $extraParam = match ($fn['kind']) {
            'method' => ", $cpp* self",
            'ctor' => ", cv::Ptr<$cpp>& self",
            default => '',
        };
        $extraArg = $extraParam === '' ? '' : ', ' . ($fn['kind'] === 'method' ? 'self.get()' : 'self');
        $out = '';
        $calls = [];
        foreach ($fn['variants'] as $i => $variant) {
            $out .= $this->variantSource("{$fn['symbol']}_v$i", $variant, $fn['kind'], $cpp, $extraParam);
            $calls[] = "{$fn['symbol']}_v$i(args, argc, return_value, mismatch$extraArg)";
        }
        $qualified = addslashes($fn['qualified']);
        $out .= "ZEND_NAMED_FUNCTION({$fn['symbol']})\n{\n"
            . "    zval* args = ZEND_CALL_ARG(execute_data, 1);\n"
            . "    uint32_t argc = ZEND_NUM_ARGS();\n";
        if ($fn['kind'] === 'method') {
            $out .= "    cv::Ptr<$cpp> self = phpcv_ptr_{$class['id']}(ZEND_THIS);\n"
                . "    if (!self) { phpcv::throw_uninitialized(Z_OBJCE_P(ZEND_THIS)); return; }\n";
        } elseif ($fn['kind'] === 'ctor') {
            $out .= "    cv::Ptr<$cpp> self;\n";
        }
        $out .= "    phpcv::Mismatch mismatch;\n    if (" . implode(' || ', $calls) . ") {\n";
        if ($fn['kind'] === 'ctor') {
            $out .= "        if (self) phpcv::ObjectType<$cpp>::fetch(Z_OBJ_P(ZEND_THIS))->ptr = self;\n";
        }
        return $out . "        return;\n    }\n    mismatch.raise(\"$qualified\");\n}\n\n";
    }

    private function variantSource(string $symbol, array $v, string $kind, string $classCpp, string $extraParam): string
    {
        $inputs = array_values(array_filter($v['args'], fn ($a) => $a['kind'] !== 'out'));
        $max = count($inputs);
        $body = ($v['using'] !== null ? "    using namespace {$v['using']};\n" : '')
            . "    if (argc < {$v['required']} || argc > $max) return mismatch.count(argc, {$v['required']}, $max);\n";
        $callArgs = [];
        $results = [];
        $index = 0;
        foreach ($v['args'] as $a) {
            $var = 'a_' . $a['name'];
            $cpp = $a['type']['cpp'];
            if ($a['kind'] === 'out') {
                $body .= "    $cpp $var" . ($a['pointer'] ? '{}' : '') . ";\n";
                $callArgs[] = $a['pointer'] ? "&$var" : $var;
                $results[] = $var;
                continue;
            }
            $i = $index++;
            $fail = "return mismatch.arg($i, \"{$a['name']}\", \"{$a['type']['label']}\", &args[$i]);";
            if ($a['kind'] === 'inout' && $cpp === 'cv::Mat') {
                // Modified in place: it must be a real Mat object, and C++
                // works on the object's own cv::Mat.
                $body .= "    cv::Mat* p_$var = argc > $i ? phpcv::mat_ptr(&args[$i]) : nullptr;\n"
                    . "    if (!p_$var) return mismatch.arg($i, \"{$a['name']}\", \"CV\\\\Mat\", &args[$i]);\n";
                $callArgs[] = "*p_$var";
                continue;
            }
            $extra = $cpp === 'cv::Mat' && $a['arithm'] ? ', phpcv::MAT_ARITHM_SCALAR' : '';
            $init = $a['default'] !== null && $i >= $v['required'] ? ' = ' . $a['default'] : '';
            $body .= "    $cpp $var$init;\n";
            if ($i < $v['required']) {
                $body .= "    if (!phpcv::to(&args[$i], $var$extra)) $fail\n";
            } else {
                $body .= "    if (argc > $i && Z_TYPE(args[$i]) != IS_NULL && !phpcv::to(&args[$i], $var$extra)) $fail\n";
            }
            $callArgs[] = $a['type']['deref'] ? "*$var" : ($a['cstring'] ? "$var.c_str()" : $var);
            if ($a['kind'] === 'inout') {
                $results[] = $var;
            }
        }
        $list = implode(', ', $callArgs);
        $call = match ($kind) {
            'method' => "self->{$v['method']}($list)",
            'ctor' => "self = cv::makePtr<$classCpp>($list)",
            default => "{$v['call']}($list)",
        };
        if ($v['ret'] !== null) {
            $body .= "    {$v['ret']['cpp']} retval;\n";
            $call = "retval = $call";
            array_unshift($results, 'retval');
        }
        $body .= "    try {\n        $call;\n    } catch (...) {\n        phpcv::rethrow();\n        return 1;\n    }\n";
        if ($kind !== 'ctor') {
            if (count($results) === 1) {
                $body .= "    phpcv::from(return_value, {$results[0]});\n";
            } elseif (count($results) > 1) {
                $body .= '    array_init_size(return_value, ' . count($results) . ");\n";
                foreach ($results as $r) {
                    $body .= "    phpcv::push(return_value, $r);\n";
                }
            }
        }
        return "static int $symbol(zval* args, uint32_t argc, zval* return_value, phpcv::Mismatch& mismatch$extraParam)\n{\n"
            . $body . "    return 1;\n}\n\n";
    }

    private function arginfoSource(array $fn, bool $variadic = false): string
    {
        $ai = 'ai_' . $fn['symbol'];
        if ($variadic || count($fn['variants']) !== 1) {
            // Overloaded: resolved at run time on the argument types.
            return "ZEND_BEGIN_ARG_INFO_EX($ai, 0, 0, 0)\n    ZEND_ARG_VARIADIC_INFO(0, args)\nZEND_END_ARG_INFO()\n\n";
        }
        $v = $fn['variants'][0];
        $out = "ZEND_BEGIN_ARG_INFO_EX($ai, 0, 0, {$v['required']})\n";
        $i = 0;
        foreach ($v['args'] as $a) {
            if ($a['kind'] === 'out') {
                continue;
            }
            $out .= $i++ < $v['required']
                ? "    ZEND_ARG_INFO(0, {$a['name']})\n"
                : "    ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, {$a['name']}, \"null\")\n";
        }
        return $out . "ZEND_END_ARG_INFO()\n\n";
    }

    // -- C++: registry ---------------------------------------------------------

    private function registrySource(): string
    {
        $out = $this->banner() . "#include \"../php_opencv.h\"\n";
        $headers = [];
        // Enums can live in headers the umbrella header does not include.
        foreach ($this->constants as $c) {
            $headers[$c['header']] = true;
        }
        foreach (array_keys($headers) as $header) {
            $out .= "#include <$header>\n";
        }
        $out .= "\n";
        $functions = array_filter($this->functions, fn ($fn) => (bool) $fn['variants']);
        $entries = '';
        foreach ($functions as $fn) {
            $out .= "ZEND_NAMED_FUNCTION({$fn['symbol']});\n";
        }
        $out .= "\n";
        foreach ($functions as $fn) {
            $out .= $this->arginfoSource($fn);
            $ns = str_replace('\\', '\\\\', $fn['ns']);
            $entries .= "    ZEND_NS_NAMED_FE(\"$ns\", {$fn['name']}, {$fn['symbol']}, ai_{$fn['symbol']})\n";
        }
        $classes = $this->objectClasses();
        $out .= "const zend_function_entry phpcv_functions[] = {\n$entries    PHP_FE_END\n};\n"
            . 'const int phpcv_function_count = ' . count($functions) . ";\n"
            . 'const int phpcv_class_count = ' . count($classes) . ";\n"
            . 'const int phpcv_constant_count = ' . count($this->constants) . ";\n\n"
            . "/* Parents are registered before the classes that extend them. */\n"
            . "void phpcv_register_classes(void)\n{\n";
        foreach ($classes as $class) {
            $out .= "    phpcv_register_class_{$class['id']}();\n";
        }
        $out .= "}\n\nvoid phpcv_register_constants(int module_number)\n{\n";
        foreach ($this->constants as $php => $c) {
            $name = str_replace('\\', '\\\\', $php);
            $out .= "    zend_register_long_constant(\"$name\", " . strlen($php)
                . ", static_cast<zend_long>({$c['cpp']}), CONST_PERSISTENT, module_number);\n";
        }
        return $out . "}\n";
    }

    // -- stubs -----------------------------------------------------------------

    private function stubSource(): string
    {
        $byNs = [];
        foreach ($this->constants as $c) {
            $value = preg_match('/^-?\d+$/', (string) $c['value']) ? $c['value'] : 'UNKNOWN';
            $byNs[$c['ns']]['const'][] = $value === 'UNKNOWN'
                ? "    /** @var int {$c['cpp']} */\n    const {$c['name']} = UNKNOWN;\n"
                : "    const {$c['name']} = $value;\n";
        }
        foreach ($this->objectClasses() as $class) {
            $byNs[$class['ns']]['class'][] = $this->stubClass($class);
        }
        foreach ($this->functions as $fn) {
            if ($fn['variants']) {
                $byNs[$fn['ns']]['fn'][] = $this->stubCallable($fn, '    ', 'function');
            }
        }
        ksort($byNs);
        $out = "<?php\n\n/**\n * Generated by gen/generate.php from " . basename($this->apiFile)
            . ". Do not edit.\n *\n * Signatures for IDEs and static analysis; the extension is the implementation.\n"
            . " * CV\\Mat and CV\\Exception are hand-written, see stubs/Mat.stub.php.\n */\n\n";
        foreach ($byNs as $ns => $items) {
            $out .= "namespace $ns {\n" . implode('', $items['const'] ?? []) . "\n"
                . implode("\n", $items['class'] ?? []) . "\n"
                . implode("\n", $items['fn'] ?? []) . "}\n\n";
        }
        return $out;
    }

    private function stubClass(array $class): string
    {
        $extends = '';
        if ($class['parent'] !== null) {
            $parent = $this->classes[$class['parent']];
            $extends = ' extends ' . ($parent['ns'] === $class['ns'] ? '' : '\\' . $parent['ns'] . '\\') . $parent['name'];
        }
        $out = '';
        $brief = $this->brief($class['doc']);
        if ($brief !== []) {
            $out .= "    /**\n" . implode("\n", array_map(fn ($l) => rtrim('     * ' . $l), $brief)) . "\n     */\n";
        }
        $out .= "    class {$class['name']}$extends\n    {\n";
        foreach ($class['props'] as $p) {
            $out .= '        public ' . ($p['writable'] ? '' : 'readonly ') . "{$p['type']['php']} \${$p['name']};\n";
        }
        $methods = array_filter($class['methods'], fn ($fn) => (bool) $fn['variants']);
        if (!isset($methods['__construct'])) {
            $out .= $class['simple']
                ? "        public function __construct() {}\n"
                : "        /** Not constructible; obtained from a factory function. */\n        private function __construct() {}\n";
        }
        foreach ($methods as $fn) {
            $out .= "\n" . $this->stubCallable($fn, '        ', $fn['kind'] === 'static' ? 'public static function' : 'public function');
        }
        return $out . "    }\n";
    }

    private function stubCallable(array $fn, string $indent, string $keyword): string
    {
        $ctor = $fn['kind'] === 'ctor';
        $signatures = [];
        foreach ($fn['variants'] as $v) {
            $params = [];
            $i = 0;
            foreach ($v['args'] as $a) {
                if ($a['kind'] === 'out') {
                    continue;
                }
                $type = $a['kind'] === 'inout' && $a['type']['cpp'] === 'cv::Mat' ? 'Mat' : $a['type']['php'];
                $optional = $i++ >= $v['required'];
                $params[] = ($optional ? (str_contains($type, '|') ? "$type|null" : "?$type") : $type)
                    . ' $' . $a['name'] . ($optional ? ' = null /* ' . str_replace('*/', '* /', $a['default']) . ' */' : '');
            }
            $returns = [];
            if ($v['ret'] !== null) {
                $returns[] = $this->returnType($v['ret']);
            }
            foreach ($v['args'] as $a) {
                if ($a['kind'] === 'out' || ($a['kind'] === 'inout' && $a['type']['cpp'] !== 'cv::Mat')) {
                    $returns[] = $this->returnType($a['type']) . ' $' . $a['name'];
                }
            }
            $return = match (count($returns)) {
                0 => 'void',
                1 => explode(' ', $returns[0])[0],
                default => 'array',
            };
            $signatures[] = [$params, $return, $returns];
        }
        $doc = array_map(fn ($l) => rtrim(' * ' . $l), $this->brief($fn['variants'][0]['doc']));
        if (count($signatures) === 1) {
            [$params, $return, $returns] = $signatures[0];
            if (count($returns) > 1) {
                $doc[] = ' *';
                $doc[] = ' * @return array [' . implode(', ', $returns) . ']';
            }
            $decl = "$keyword {$fn['name']}(" . implode(', ', $params) . ')' . ($ctor ? '' : ": $return") . ' {}';
        } else {
            $doc[] = ' *';
            $doc[] = ' * Overloads, chosen by argument types:';
            foreach ($signatures as [$params, $return, $returns]) {
                // Inside a docblock the C++ default is shown directly.
                $shown = preg_replace('~ = null /\* (.*?) \*/~', ' = $1', implode(', ', $params));
                $doc[] = ' *   ' . $fn['name'] . '(' . $shown . ')'
                    . ($ctor ? '' : ': ' . (count($returns) > 1 ? '[' . implode(', ', $returns) . ']' : $return));
            }
            $decl = "$keyword {$fn['name']}(mixed ...\$args)" . ($ctor ? '' : ': mixed') . ' {}';
        }
        $comment = $doc === [] ? '' : "$indent/**\n" . implode("\n", array_map(fn ($l) => $indent . $l, $doc)) . "\n$indent */\n";
        return $comment . "$indent$decl\n";
    }

    /** First paragraph of a Doxygen comment, as lines. */
    private function brief(string $doc): array
    {
        $brief = trim(explode("\n\n", trim($doc))[0] ?? '');
        $brief = preg_replace('/^@brief\s+/', '', $brief);
        if ($brief === '') {
            return [];
        }
        return array_map(fn ($line) => str_replace('*/', '* /', trim($line)), explode("\n", $brief));
    }

    private function returnType(array $type): string
    {
        return $type['cpp'] === 'cv::Mat' ? 'Mat' : explode('|', $type['php'])[0];
    }

    // -- reporting -------------------------------------------------------------

    private function coverage(): array
    {
        $wrapped = [];
        foreach ($this->functions as $fn) {
            if ($fn['variants']) {
                $wrapped[$fn['module']]['functions'][] = $fn['qualified']
                    . (count($fn['variants']) > 1 ? ' (' . count($fn['variants']) . ' overloads)' : '');
            }
        }
        $methodCount = 0;
        foreach ($this->objectClasses() as $class) {
            $names = [];
            foreach ($class['methods'] as $fn) {
                if ($fn['variants']) {
                    $names[] = $fn['name'];
                    $methodCount++;
                }
            }
            $wrapped[$class['module']]['classes'][$class['ns'] . '\\' . $class['name']] = [
                'methods' => $names,
                'properties' => array_column($class['props'], 'name'),
            ];
        }
        foreach ($this->classes as $class) {
            if ($class['map'] && !$class['skip']) {
                $wrapped[$class['module']]['array_structs'][] = $class['cpp'];
            }
        }
        arsort($this->unsupportedTypes);
        return [
            'source' => basename($this->apiFile),
            'functions' => count(array_filter($this->functions, fn ($fn) => (bool) $fn['variants'])),
            'classes' => count($this->objectClasses()),
            'methods' => $methodCount,
            'constants' => count($this->constants),
            'skipped' => count($this->skipped),
            'unsupported_types' => $this->unsupportedTypes,
            'wrapped' => $wrapped,
            'skipped_detail' => $this->skipped,
        ];
    }

    private function report(): void
    {
        $c = $this->coverage();
        $perModule = [];
        foreach ($c['wrapped'] as $module => $w) {
            $perModule[$module]['functions'] = count($w['functions'] ?? []);
            $perModule[$module]['classes'] = count($w['classes'] ?? []);
            $perModule[$module]['methods'] = array_sum(array_map(fn ($k) => count($k['methods']), $w['classes'] ?? []));
        }
        foreach ($this->skipped as $s) {
            $perModule[$s['module']]['skipped'] = ($perModule[$s['module']]['skipped'] ?? 0) + 1;
        }
        foreach ($perModule as $module => $m) {
            fprintf(STDERR, "%-10s %3d functions, %2d classes, %3d methods wrapped; %3d declarations skipped\n",
                $module, $m['functions'] ?? 0, $m['classes'] ?? 0, $m['methods'] ?? 0, $m['skipped'] ?? 0);
        }
        fprintf(STDERR, "%d functions, %d classes, %d methods, %d constants. Details in api/coverage.json\n",
            $c['functions'], $c['classes'], $c['methods'], $c['constants']);
    }

    private function banner(): string
    {
        return "/* Generated by gen/generate.php from " . basename($this->apiFile) . ". Do not edit. */\n";
    }

    private function write(string $path, string $content): void
    {
        file_put_contents(ROOT . '/' . $path, $content);
    }
}

(new BindingGenerator($argv[1] ?? ROOT . '/api/opencv-5.1.0.json'))->run();
