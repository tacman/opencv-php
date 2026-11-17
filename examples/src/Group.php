<?php
declare(strict_types=1);

namespace OpenCvExamples;

/** A class of examples: one heading in the table of contents. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Group
{
    public function __construct(
        public string $title,
        public int $order = 0,
    ) {
    }
}
