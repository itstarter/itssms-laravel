<?php

use Illuminate\Support\Facades\Route;

it('registers no webhook route when no path is configured', function () {
    expect(Route::has('itssms.webhook'))->toBeFalse();
});
