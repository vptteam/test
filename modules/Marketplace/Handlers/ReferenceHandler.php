<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Models\Listing;
use Core\ReplyInterface;
use Core\Logger;

class ReferenceHandler
{
    protected Listing $listing;

    public function __construct()
    {
        $this->listing = new Listing();
    }

    public function execute(
        string $phone,
        string $reference,
        ReplyInterface $reply
    ): bool {

        Logger::write(
            'reference_handler_debug',
            [
                'step' => 'LOOKUP_START',
                'reference' => $reference
            ]
        );

        $row = $this->listing->findByReference($reference);

if (!$row) {

    $reply->text(
        $phone,
        "❌ Listing not found."
    );

    return false;
}

$listing = $this->listing->find(
    (int)$row['id']
);

        if (!$listing) {

            $reply->text(
                $phone,
                "❌ Listing not found."
            );

            Logger::write(
                'reference_handler_debug',
                [
                    'step' => 'NOT_FOUND'
                ]
            );

            return false;
        }
        
        
/*
|--------------------------------------------------------------------------
| Send Listing Photos
|--------------------------------------------------------------------------
*/

if (
    !empty($listing['photos']) &&
    is_array($listing['photos'])
) {

    foreach ($listing['photos'] as $photo) {

        if (empty($photo['filepath'])) {
            continue;
        }

        Logger::write(
            'reference_handler_debug',
            [
                'step' => 'SENDING_PHOTO',
                'filepath' => $photo['filepath']
            ]
        );

        try {

    $reply->photo(
        $phone,
        $photo['filepath']
    );

} catch (\Throwable $e) {

    Logger::write(
        'reference_handler_error',
        [
            'step' => 'PHOTO_SEND_FAILED',
            'filepath' => $photo['filepath'],
            'message' => $e->getMessage()
        ]
    );

}
    }
}
        $message =
            "📦 {$listing['title']}\n\n" .
            "🏷️ Reference: {$listing['reference']}\n" .
            "💰 Price: ₦" . number_format((float)$listing['price']) . "\n" .
            "📍 {$listing['location']}\n\n" .
            $listing['description'];

        $reply->text(
            $phone,
            $message
        );

        Logger::write(
            'reference_handler_debug',
            [
                'step' => 'LOOKUP_COMPLETE',
                'listing_id' => $listing['id']
            ]
        );

        return true;
    }
}