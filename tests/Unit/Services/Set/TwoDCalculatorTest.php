<?php

namespace Tests\Unit\Services\Set;

use App\Services\Set\TwoDCalculator;
use PHPUnit\Framework\TestCase;

class TwoDCalculatorTest extends TestCase
{
    private TwoDCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new TwoDCalculator;
    }

    public function test_canonical_example(): void
    {
        // 1644.39 -> 9 ; 89284959005 -> 5
        $this->assertSame('95', $this->calc->calculate('1644.39', '89284959005'));
    }

    public function test_strips_thousands_separators(): void
    {
        $this->assertSame('9', $this->calc->indexDigit('1,644.39'));
        $this->assertSame('5', $this->calc->valueDigit('89,284,959,005'));
    }

    public function test_index_with_no_decimal_yields_zero(): void
    {
        $this->assertSame('0', $this->calc->indexDigit('1644'));
    }

    public function test_index_trailing_zero_decimal(): void
    {
        $this->assertSame('0', $this->calc->indexDigit('1644.40'));
        $this->assertSame('4', $this->calc->indexDigit('1644.04'));
    }

    public function test_value_with_decimal_uses_integer_part(): void
    {
        $this->assertSame('3', $this->calc->valueDigit('70364087793.6'));
    }

    public function test_zero_padded_result(): void
    {
        $this->assertSame('00', $this->calc->calculate('10.00', '1000'));
    }

    public function test_accepts_numeric_input(): void
    {
        $this->assertSame('9', $this->calc->indexDigit(1644.39));
        $this->assertSame('5', $this->calc->valueDigit(89284959005));
    }
}
