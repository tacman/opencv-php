<?php
declare(strict_types=1);

namespace OpenCvExamples;

/**
 * Marks a method as an example.  The method takes the input picture and
 * returns the result: one CV\Mat, or a list of them (one per piece found).
 * The examples site builds its table of contents and pages from these.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Example
{
    /**
     * @param string      $input picture the example runs on, relative to examples/
     * @param string|null $needs what it needs beyond the default build; such examples are shown but not run
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $input = null,
        public int $order = 0,
        public ?string $needs = null,
    ) {
    }
}
