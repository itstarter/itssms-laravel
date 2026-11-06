<?php

use ItsStarter\Sms\Data\MessageStatus;

it('ignores an unreadable delivered_at', function () {
    $status = MessageStatus::fromArray(['to' => '94771234567', 'status' => 'delivered', 'delivered_at' => 'garbage']);

    expect($status->deliveredAt)->toBeNull();
});
