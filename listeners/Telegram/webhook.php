<?php

declare(strict_types=1);

use Listeners\Telegram\TelegramListener;

require_once BASE_PATH . '/listeners/telegram/TelegramListener.php';

$listener = new TelegramListener();

$listener->handle();