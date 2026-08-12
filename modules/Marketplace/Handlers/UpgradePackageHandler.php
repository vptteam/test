<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;


use Core\ReplyInterface;
use Core\Logger;

use Models\SellerPackage;

use Services\Marketplace\AdvertUpgradeService;



class UpgradePackageHandler
{


    protected SellerPackage $packages;


    protected AdvertUpgradeService $upgrade;





    public function __construct()
    {


        $this->packages =
            new SellerPackage();


        $this->upgrade =
            new AdvertUpgradeService();


    }









    /**
     * Ask user to select package
     */
    public function ask(

        ReplyInterface $reply,

        string $phone,

        array $data = []

    ): void {


        $packages =
    $this->packages->upgradePackages();



        if(!$packages){


            $reply->text(

                $phone,

                "⚠️ No seller packages available."

            );


            return;


        }







        $text =
            "🚀 Upgrade Seller Package\n\n";


        $text .=
            "Choose your seller plan:\n\n";




        foreach($packages as $index=>$package){


            $number =
                $index + 1;



            $limit =
                $package['daily_post_limit'] === null

                ?

                "Unlimited"

                :

                $package['daily_post_limit'];




            $text .=

                "{$number}️⃣ "
                .
                $package['name']
                .
                "\n";



            $text .=

    "₦"
    .
    number_format(
        (float)$package['price']
    )
    .
    " / "
    .
    $package['duration_days']
    .
    " days\n";



            $text .=

                "Daily adverts: "
                .
                $limit
                .
                "\n\n";


        }



        $text .=

            "Reply with\n\n 1 ";



        $reply->text(

            $phone,

            $text

        );


    }









    /**
     * Validate selection
     */
    public function validate(

        array $message

    ): bool {


        $text =
            trim(
                $message['text'] ?? ''
            );



        return is_numeric($text);



    }









    /**
     * Save selection
     */
    public function save(

        array $message

    ): array {


        return [

            'package_choice'=>

                (int)$message['text']

        ];


    }


/**
 * Execute upgrade
 */
public function execute(
    ReplyInterface $reply,
    array $user,
    array $message,
    string $text,
    array $data
): bool {


    $packages = $this->packages->upgradePackages();

    $packages = array_values($packages);


    Logger::write(
        'upgrade_package',
        [
            'step' => 'PACKAGES_LOADED',
            'count' => count($packages),
            'packages' => $packages,
            'text' => $text,
            'saved_data' => $data
        ]
    );



    /*
    |--------------------------------------------------------------------------
    | Get selected package number
    |--------------------------------------------------------------------------
    */

    $choice = (int)(
        $data['package_choice']
        ??
        trim($text)
    );



    if (
        $choice < 1
        ||
        $choice > count($packages)
    ) {


        Logger::write(
            'upgrade_package',
            [
                'step'=>'INVALID_SELECTION',
                'choice'=>$choice
            ]
        );


        $reply->text(
            $message['phone'],
            "❌ Invalid package selection."
        );


        return true;

    }



    $package = $packages[$choice - 1];



    Logger::write(
        'upgrade_package',
        [
            'step'=>'PACKAGE_SELECTED',
            'package'=>$package
        ]
    );




    /*
    |--------------------------------------------------------------------------
    | Create Payment
    |--------------------------------------------------------------------------
    */


    $payment = $this->upgrade->createUpgrade(

        (int)$user['id'],

        $package['slug']

    );





    if(
        !($payment['success'] ?? false)
    ){


        Logger::write(
            'upgrade_package',
            [
                'step'=>'PAYMENT_FAILED',
                'response'=>$payment
            ]
        );


        $reply->text(

            $message['phone'],

            "⚠️ Unable to create payment.\n\n"
            .
            ($payment['message'] ?? '')

        );


        return true;

    }





    $reply->text(

        $message['phone'],

        "✅ {$package['name']} selected.\n\n"
        .
        "Amount: ₦"
        .
        number_format(
            (float)$package['price']
        )
        .
        "\n\n"
        .
        "Send a HI when done paying:\n"
        .
        "Pay here:\n"
        .
        $payment['payment_url']

    );



    return true;


}

}