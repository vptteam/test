<?php

declare(strict_types=1);

use Listeners\WhatsApp\WhatsAppListener;

require_once BASE_PATH . '/listeners/whatsapp/WhatsAppListener.php';

$listener = new WhatsAppListener();

$listener->handle();