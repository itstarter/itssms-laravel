<?php

use ItsStarter\Sms\Support\Segments;

it('counts 160 GSM-7 characters as one segment and 161 as two', function () {
    $one = Segments::count(str_repeat('a', 160));
    $two = Segments::count(str_repeat('a', 161));

    expect($one->encoding)->toBe('GSM-7')
        ->and($one->characters)->toBe(160)
        ->and($one->segments)->toBe(1)
        ->and($one->perSegment)->toBe(160)
        ->and($two->segments)->toBe(2)
        ->and($two->perSegment)->toBe(153);
});

it('describes a short message', function () {
    $s = Segments::count('Hello');

    expect($s->encoding)->toBe('GSM-7')
        ->and($s->characters)->toBe(5)
        ->and($s->segments)->toBe(1)
        ->and($s->perSegment)->toBe(160);
});

it('splits GSM-7 multipart at 153 characters', function () {
    expect(Segments::count(str_repeat('a', 306))->segments)->toBe(2)
        ->and(Segments::count(str_repeat('a', 307))->segments)->toBe(3);
});

it('counts 70 Sinhala characters as one segment and 71 as two', function () {
    $one = Segments::count(str_repeat('ස', 70));
    $two = Segments::count(str_repeat('ස', 71));

    expect($one->encoding)->toBe('UCS-2')
        ->and($one->segments)->toBe(1)
        ->and($one->perSegment)->toBe(70)
        ->and($two->segments)->toBe(2)
        ->and($two->perSegment)->toBe(67);
});

it('splits UCS-2 multipart at 67 characters', function () {
    expect(Segments::count(str_repeat('ස', 134))->segments)->toBe(2)
        ->and(Segments::count(str_repeat('ස', 135))->segments)->toBe(3);
});

it('detects Tamil as UCS-2', function () {
    expect(Segments::count('வணக்கம்')->encoding)->toBe('UCS-2');
});

it('counts extended GSM-7 characters as two', function (string $char) {
    $s = Segments::count($char);

    expect($s->encoding)->toBe('GSM-7')
        ->and($s->characters)->toBe(2);
})->with(['^', '{', '}', "\x5c", '[', ']', '~', '|', '€']);

it('lets extended characters push a message over the limit', function () {
    // 159 basic + 1 extended = 161 septets -> 2 segments
    expect(Segments::count(str_repeat('a', 159).'€')->segments)->toBe(2)
        ->and(Segments::count(str_repeat('a', 158).'€')->segments)->toBe(1);
});

it('forces UCS-2 for emoji and counts them as two units', function () {
    $s = Segments::count('Hi 😀');

    expect($s->encoding)->toBe('UCS-2')
        ->and($s->characters)->toBe(5)
        ->and($s->segments)->toBe(1);
});

it('forces UCS-2 for curly quotes', function () {
    expect(Segments::count('It’s')->encoding)->toBe('UCS-2')
        ->and(Segments::count('“Hi”')->encoding)->toBe('UCS-2');
});

it('treats an empty message as zero segments', function () {
    $s = Segments::count('');

    expect($s->characters)->toBe(0)
        ->and($s->segments)->toBe(0);
});

it('accepts newlines and GSM accented letters as GSM-7', function () {
    expect(Segments::count("é\nù£$")->encoding)->toBe('GSM-7');
});
