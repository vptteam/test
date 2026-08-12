<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\Logger;
use Core\ReplyInterface;
use Models\Listing;
use Models\Media;
use Models\SellerSubscription;
use Services\Publisher;
use Throwable;
use Models\AdvertUsage;

class PublishHandler
{
    protected Listing $listing;

    protected Media $media;

    protected Publisher $publisher;

    protected SellerSubscription $sellerSubscription;
    
    protected AdvertUsage $advertUsage;

    public function __construct()
    {
        $this->listing = new Listing();
        $this->media = new Media();
        $this->publisher = new Publisher();
        $this->sellerSubscription = new SellerSubscription();
        $this->advertUsage = new AdvertUsage();

        Logger::write(
            'publish_handler_debug',
            [
                'step' => 'CONSTRUCTOR_READY'
            ]
        );
    }

    /**
     * Ask seller to confirm publishing.
     */
    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {

        Logger::write(
            'publish_handler_debug',
            [
                'step'  => 'ASK_CONFIRMATION',
                'phone' => $phone,
                'data'  => $data
            ]
        );

        $message =
            "🚀 *Your advert is ready to go live!*\n\n"

            . "Before publishing, please read the safety tips below:\n\n"

            . "🛡️ *Safety Tips*\n"
            . "• Never trust screenshots of payment alerts.\n"
            . "• Do not share your PIN, OTP or passwords.\n\n"

            . "🛡️ *SENDAM Escrow*\n"
            . "For extra protection, buyers can pay securely through SENDAM Escrow. Funds are only released after both parties are satisfied.\n\n"

            . "By publishing this advert you agree to the SENDAM Marketplace Terms.\n\n"

            . "Reply *YES* to publish.\n"
            . "Reply *NO* to cancel.";

        $reply->text(
            $phone,
            $message
        );
    }

    /**
     * Validate seller confirmation.
     */
    public function validate(
        array $message
    ): bool {

        $answer = strtoupper(
            trim(
                (string)($message['text'] ?? '')
            )
        );

        $valid = in_array(
            $answer,
            [
                'YES',
                'NO'
            ],
            true
        );

        Logger::write(
            'publish_handler_debug',
            [
                'step'   => 'VALIDATE_DECISION',
                'answer' => $answer,
                'valid'  => $valid
            ]
        );

        return $valid;
    }

    /**
     * Save seller decision.
     */
    public function save(
        array $message
    ): array {

        $decision = strtoupper(
            trim(
                (string)($message['text'] ?? '')
            )
        );

        Logger::write(
            'publish_handler_debug',
            [
                'step'     => 'SAVE_DECISION',
                'decision' => $decision
            ]
        );

        return [
            'decision' => $decision
        ];
    }

    /**
     * Execute advert publishing.
     *
     * (Continues in Part 2...)
     */
     
        public function execute(
    ReplyInterface $reply,
    array $user,
    array $message,
    string $text,
    array $data
): bool {

        Logger::write(
            'publish_handler_debug',
            [
                'step' => 'EXECUTE_START',
                'user' => $user,
                'data' => $data
            ]
        );

        $phone = (string)($message['phone'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Seller Cancelled
        |--------------------------------------------------------------------------
        */

        if (($data['decision'] ?? '') !== 'YES') {

            $reply->text(
                $phone,
                "❌ Your advert has been cancelled.\n\nYou can create another advert anytime."
            );

            Logger::write(
                'publish_handler_debug',
                [
                    'step' => 'USER_CANCELLED'
                ]
            );

            // Return true so WorkflowExecutor finishes the conversation.
            return true;
        }

        /*
|--------------------------------------------------------------------------
| Validate Seller Can Create Advert
|--------------------------------------------------------------------------
*/

$check = $this->sellerSubscription->canCreateAdvert(
    (int)$user['id']
);

if (!$check['success']) {

    Logger::write(
        'publish_handler_error',
        [
            'step'    => 'CREATE_BLOCKED',
            'user_id' => $user['id']
        ]
    );

    $reply->text(
        $phone,
        $check['message']
    );

    return false;
}


        /*
        |--------------------------------------------------------------------------
        | Create Listing
        |--------------------------------------------------------------------------
        */

        try {

            Logger::write(
                'publish_handler_debug',
                [
                    'step' => 'CREATE_LISTING_START'
                ]
            );

            $listingId = $this->listing->create(

                (int)$user['id'],

                [

                    'title' => $data['title'] ?? '',

                    'price' => $data['price'] ?? 0,

                    'location' => $data['location'] ?? '',

                    'description' => $data['description'] ?? ''

                ]

            );

            if (!$listingId) {

                throw new \RuntimeException(
                    'Listing creation failed.'
                );
            }

            $reference = $this->listing->generateReference(
                $listingId
            );

            $this->listing->updateReference(
                $listingId,
                $reference
            );

            Logger::write(
                'publish_handler_debug',
                [
                    'step' => 'LISTING_CREATED',
                    'listing_id' => $listingId,
                    'reference' => $reference
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'publish_handler_error',
                [
                    'step' => 'CREATE_LISTING_FAILED',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]
            );

            $reply->text(
                $phone,
                "❌ Unable to create your advert.\n\nPlease try again."
            );

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Save Photos
        |--------------------------------------------------------------------------
        */

        if (!empty($data['photos']) && is_array($data['photos'])) {

            foreach ($data['photos'] as $photo) {

                try {

                    $this->media->create(

                        [

                            'module'      => 'marketplace',

                            'record_id'   => $listingId,

                            'platform'    => $photo['platform'] ?? 'telegram',

                            'media_type'  => 'image',

                            'media_id'    => $photo['media_id'] ?? null,

                            'filename'    => $photo['filename'] ?? '',

                            'filepath'    => $photo['filepath'] ?? '',

                            'mime_type'   => $photo['mime_type'] ?? '',

                            'file_size'   => $photo['file_size'] ?? 0,

                            'width'       => $photo['width'] ?? 0,

                            'height'      => $photo['height'] ?? 0

                        ]

                    );

                } catch (Throwable $e) {

                    Logger::write(
                        'publish_handler_error',
                        [
                            'step' => 'PHOTO_SAVE_FAILED',
                            'listing_id' => $listingId,
                            'message' => $e->getMessage()
                        ]
                    );
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Continue in Part 3...
        |--------------------------------------------------------------------------
        */
                /*
        |--------------------------------------------------------------------------
        | Publish Listing
        |--------------------------------------------------------------------------
        */

        $published = false;

        try {

            Logger::write(
                'publish_handler_debug',
                [
                    'step'       => 'PUBLISH_START',
                    'listing_id' => $listingId
                ]
            );

            $published = $this->publisher->publish(
                $listingId
            );

        } catch (Throwable $e) {

            Logger::write(
                'publish_handler_error',
                [
                    'step'       => 'PUBLISH_EXCEPTION',
                    'listing_id' => $listingId,
                    'message'    => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine()
                ]
            );

            $published = false;
        }

        /*
        |--------------------------------------------------------------------------
        | Publishing Failed
        |--------------------------------------------------------------------------
        */

        if (!$published) {

            Logger::write(
                'publish_handler_error',
                [
                    'step'       => 'PUBLISH_FAILED',
                    'listing_id' => $listingId
                ]
            );

            $reply->text(
                $phone,
                "❌ Your advert was created but could not be published at the moment.\n\nPlease try again later."
            );

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Listing Published
        |--------------------------------------------------------------------------
        */

        try {

            $this->listing->publish(
                $listingId
            );

            Logger::write(
                'publish_handler_debug',
                [
                    'step'       => 'DATABASE_UPDATED',
                    'listing_id' => $listingId
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'publish_handler_error',
                [
                    'step'       => 'DATABASE_UPDATE_FAILED',
                    'listing_id' => $listingId,
                    'message'    => $e->getMessage()
                ]
            );
        }

      
/*
|--------------------------------------------------------------------------
| Increment Usage
|--------------------------------------------------------------------------
*/

try {

    if (($check['type'] ?? '') === 'paid') {

        $this->sellerSubscription->incrementUsage(
            (int)$check['subscription']['id']
        );

        Logger::write(
            'publish_handler_debug',
            [
                'step' => 'PAID_USAGE_INCREMENTED',
                'subscription_id' => $check['subscription']['id']
            ]
        );

    } else {

        $this->advertUsage->increment(
            (int)$user['id']
        );

        Logger::write(
            'publish_handler_debug',
            [
                'step' => 'FREE_USAGE_INCREMENTED',
                'user_id' => $user['id']
            ]
        );

    }

} catch (Throwable $e) {

    Logger::write(
        'publish_handler_error',
        [
            'step'       => 'USAGE_INCREMENT_FAILED',
            'listing_id' => $listingId,
            'message'    => $e->getMessage()
        ]
    );

}
        /*
        |--------------------------------------------------------------------------
        | Success Message
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'publish_handler_debug',
            [
                'step'       => 'PUBLISH_SUCCESS',
                'listing_id' => $listingId,
                'reference'  => $reference
            ]
        );

        $reply->text(
            $phone,
            "🎉 Congratulations!\n\n"
            . "Your advert is now LIVE on SENDAM Marketplace.\n\n"
            . "📦 Listing Reference: {$reference}\n\n"
            . "Share this reference with buyers so they can quickly locate your advert using the SENDAM Bot.\n\n"
            . "🛡️ Buyers can also complete their purchase securely using SENDAM Escrow.\n\n"
            . "Thank you for using SENDAM Marketplace."
        );

        Logger::write(
            'publish_handler_debug',
            [
                'step'       => 'EXECUTE_COMPLETE',
                'listing_id' => $listingId,
                'published'  => true
            ]
        );

        return true;
    }
}