<?php

use App\Domain\Money\Exceptions\ExcessiveDecimalScale;
use App\Domain\Money\Exceptions\MalformedMonetaryDecimal;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

test('a value with exactly 18 fractional digits is accepted unchanged', function () {
    $value = MonetaryDecimal::fromString('12345.123456789012345678');

    expect((string) $value)->toBe('12345.123456789012345678');
});

test('a value with fewer than 18 fractional digits is padded to the stored scale', function () {
    $value = MonetaryDecimal::fromString('-10.5');

    expect((string) $value)->toBe('-10.500000000000000000');
});

test('a value with no fractional part is padded to the stored scale', function () {
    $value = MonetaryDecimal::fromString('100');

    expect((string) $value)->toBe('100.000000000000000000');
});

test('a value with 19 fractional digits is rejected, never rounded', function () {
    expect(fn () => MonetaryDecimal::fromString('1.1234567890123456789'))
        ->toThrow(ExcessiveDecimalScale::class);
});

test('the rejection message names the offending value and digit counts', function () {
    try {
        MonetaryDecimal::fromString('1.1234567890123456789');
    } catch (ExcessiveDecimalScale $exception) {
        expect($exception->getMessage())
            ->toContain('1.1234567890123456789')
            ->toContain('19')
            ->toContain('18');

        return;
    }

    test()->fail('Expected ExcessiveDecimalScale to be thrown.');
});

test('scale is determined from the string, not float arithmetic, so trailing zeros are not collapsed', function () {
    // A float round-trip would collapse '1.100' to 1.1, losing a fractional digit count before it
    // could ever be checked against the maximum. Confirm the three explicit fractional digits
    // survive into the padded result, which is only possible without float coercion.
    $value = MonetaryDecimal::fromString('1.100');

    expect((string) $value)->toBe('1.100000000000000000');
});

test('non-canonical but syntactically valid literals are normalized losslessly, never rounded', function (string $input, string $expected) {
    // PostgreSQL's `numeric` column normalizes a leading '+', redundant leading zeros, and negative
    // zero on write; a plain text column on SQLite does not. Normalizing here, at construction,
    // means both engines store identical bytes instead of the caller's literal text diverging by
    // engine. This changes representation only — the numeric value is unchanged — so it is not a
    // new LIF-012 rounding boundary.
    expect((string) MonetaryDecimal::fromString($input))->toBe($expected);
})->with([
    'a redundant leading plus sign' => ['+1.5', '1.500000000000000000'],
    'redundant leading zeros' => ['007.5', '7.500000000000000000'],
    'negative zero' => ['-0.0', '0.000000000000000000'],
    'a redundant leading zero before the decimal point' => ['00.10', '0.100000000000000000'],
]);

test('malformed decimal syntax is rejected by the value object itself, not left to the database', function (string $value) {
    // The value object is the sole authority on the accepted decimal form: SQLite and PostgreSQL do
    // not agree on which non-canonical spellings they accept (PostgreSQL's `numeric` additionally
    // allows exponent notation, surrounding whitespace, and a missing integer part), so delegating
    // syntax to the database would make correctness depend on which engine is running.
    expect(fn () => MonetaryDecimal::fromString($value))->toThrow(MalformedMonetaryDecimal::class);
})->with([
    'multiple decimal points' => '12.34.56',
    'not numeric at all' => 'not-a-number',
    'exponent notation' => '1.1234567890123456789e0',
    'a smaller exponent notation that would underflow to zero' => '1e-19',
    'leading whitespace' => ' 1.1234567890123456789',
    'missing integer part' => '.1234567890123456789',
    'trailing decimal point with no digits' => '1.',
    'empty string' => '',
    'trailing newline' => "1.5\n",
    'trailing space' => '1.5 ',
    'trailing tab' => "1.5\t",
    'trailing carriage return' => "1.5\r",
]);
