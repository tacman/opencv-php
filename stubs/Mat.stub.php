<?php

/**
 * Hand-written classes (src/mat.cc, src/opencv.cc). The generated functions
 * and constants are in opencv.stub.php.
 */

namespace CV;

/** Thrown for any error raised inside OpenCV; the code is OpenCV's error code. */
class Exception extends \Exception {}

/**
 * An n-dimensional dense array: an image, a matrix, a list of points.
 *
 * Objects are handles. Passing a Mat to a function never copies pixels, and
 * functions that modify their argument (rectangle(), drawContours(), ...)
 * change this object. `clone $mat` and clone() copy the pixels.
 */
final class Mat
{
    /** Zero-filled unless $value is given (a number, or one number per channel). */
    public function __construct(int $rows = 0, int $cols = 0, int $type = CV_8UC1, int|float|array $value = 0) {}

    /** Build from raw element bytes in row order; the bytes are copied. */
    public static function fromString(string $bytes, int $rows, int $cols, int $type = CV_8UC1): Mat {}

    /**
     * Build from a list of numbers (N x 1) or a list of rows. Without $type the
     * result is CV_32S when every value is an int and CV_64F otherwise; a
     * multi-channel $type folds each row's columns into channels.
     */
    public static function fromArray(array $values, ?int $type = null): Mat {}

    public function rows(): int {}
    public function cols(): int {}
    public function dims(): int {}
    public function channels(): int {}
    /** Depth and channel count combined, e.g. CV_8UC3. */
    public function type(): int {}
    /** Element depth, e.g. CV_8U. */
    public function depth(): int {}
    /** Number of elements (rows x cols for an image). */
    public function total(): int {}
    /** Bytes per element, all channels. */
    public function elemSize(): int {}
    public function empty(): bool {}
    public function isContinuous(): bool {}
    /** @return list<int> size of each dimension, e.g. [rows, cols] */
    public function shape(): array {}
    /** @return array{int,int} [width, height] */
    public function size(): array {}
    public function clone(): Mat {}
    /** Raw element bytes in row order. */
    public function data(): string {}
    public function at(int $row, int $col, int $channel = 0): int|float|bool {}
    /** Values outside the element type's range saturate. */
    public function set(int $row, int $col, int|float $value, int $channel = 0): void {}
    /** [row][col] or, with several channels, [row][col][channel]. Slow for large images. */
    public function toArray(): array {}
    /** A view on a rectangle; it shares pixels with this Mat. */
    public function roi(int $x, int $y, int $width, int $height): Mat {}
    /** New Mat of another type, each value scaled: value * alpha + beta. */
    public function convertTo(int $type, float $alpha = 1.0, float $beta = 0.0): Mat {}
    /**
     * Copy into $target. When $target already has this size and type its own
     * pixels are written, so copying into a roi() pastes into the larger image.
     */
    public function copyTo(Mat $target, ?Mat $mask = null): void {}
    /** Same pixels, different channel count or row count. */
    public function reshape(int $channels, int $rows = 0): Mat {}
}
