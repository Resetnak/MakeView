<?php

declare(strict_types=1);

namespace Makeview\Readme;

/** One typed region of a README, with the line range it occupies. */
final readonly class Block
{
    /**
     * @param string $type One of: heading, paragraph, list, table, fence, reference.
     * @param string $text The block's content, fence markers and list bullets removed.
     * @param int $startLine 1-based line where the block begins.
     * @param int $endLine 1-based line where the block ends.
     * @param int $depth List nesting level; 0 for every other type.
     * @param string $heading The heading this block sits under, '' before the first one.
     */
    public function __construct(
        public string $type,
        public string $text,
        public int $startLine,
        public int $endLine,
        public int $depth,
        public string $heading,
    ) {
    }
}
