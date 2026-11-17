<?php
declare(strict_types=1);

namespace OpenCvExamples;

/**
 * Every example, read from the #[Group] and #[Example] attributes: the table
 * of contents.  Needs no OpenCV, only reflection.
 */
final class Catalog
{
    /**
     * @return list<array{id: string, group: string, example: Example, method: \ReflectionMethod}>
     *         in table-of-contents order; id is "<class>.<method>" in lower case
     */
    public static function all(): array
    {
        $entries = [];
        foreach (glob(__DIR__ . '/*.php') as $file) {
            $class = new \ReflectionClass(__NAMESPACE__ . '\\' . basename($file, '.php'));
            $group = ($class->getAttributes(Group::class)[0] ?? null)?->newInstance();
            if ($group === null) {
                continue;
            }
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $example = ($method->getAttributes(Example::class)[0] ?? null)?->newInstance();
                if ($example !== null) {
                    $entries[] = [
                        'id' => strtolower($class->getShortName() . '.' . $method->getName()),
                        'group' => $group->title,
                        'example' => $example,
                        'method' => $method,
                        'sort' => [$group->order, $example->order],
                    ];
                }
            }
        }
        usort($entries, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $e) => array_diff_key($e, ['sort' => 0]), $entries);
    }

    /** The method as written, from its signature to its closing brace, without the class indentation. */
    public static function code(\ReflectionMethod $method): string
    {
        $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

        return rtrim(implode('', array_map(fn (string $line) => preg_replace('/^ {4}/', '', $line), $lines)));
    }
}
