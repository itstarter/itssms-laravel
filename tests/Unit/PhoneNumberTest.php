<?php

use ItsStarter\Sms\Support\PhoneNumber;

it('normalises every accepted format to 94XXXXXXXXX', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('94771234567');
})->with([
    '0771234567',
    '771234567',
    '94771234567',
    '+94771234567',
    '+94 77 123 4567',
    '077-123-4567',
    '(077) 123 4567',
    '077.123.4567',
    ' 0771234567 ',
    '0094771234567',
    '+94 (0) 77 123 4567',
]);

it('accepts the other mobile prefixes', function (string $input, string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    ['0712345678', '94712345678'],
    ['0722345678', '94722345678'],
    ['0752345678', '94752345678'],
    ['0762345678', '94762345678'],
    ['0782345678', '94782345678'],
]);

it('rejects landlines, foreign and malformed numbers', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull()
        ->and(PhoneNumber::isValid($input))->toBeFalse();
})->with([
    'colombo landline' => '0112345678',
    'landline with country code' => '+94112345678',
    'indian mobile' => '+919876543210',
    'us number' => '+14155551234',
    'too short' => '07712345',
    'too long' => '07712345678',
    'letters' => '07712abc67',
    'empty' => '',
    'plus then trunk zero' => '+0771234567',
    'only symbols' => '+--',
]);

it('reports validity', function () {
    expect(PhoneNumber::isValid('0771234567'))->toBeTrue();
});
