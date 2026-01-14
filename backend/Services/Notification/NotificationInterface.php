<?php

namespace Filegator\Services\Notification;

interface NotificationInterface
{
    public function notifyUpload(string $uploadPath, array $files): void;
}
