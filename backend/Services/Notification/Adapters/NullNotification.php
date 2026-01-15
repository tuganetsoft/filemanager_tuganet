<?php

namespace Filegator\Services\Notification\Adapters;

use Filegator\Services\Notification\NotificationInterface;
use Filegator\Services\Service;

class NullNotification implements Service, NotificationInterface
{
    public function init(array $config = [])
    {
    }

    public function notifyUpload(string $uploadPath, array $files): void
    {
    }
}
