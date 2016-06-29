# Laravel 5 Payfast

A dead simple Laravel 5 payment processing class for payments through payfast.co.za. This package only supports ITN transactions.

Forked from [billowapp/payfast](https://github.com/billowapp/payfast).

**This repo adds missing verification steps that are required.**

**Still in development.**

## Installation

Add Laravel 5 Payfast to your composer.json


    composer require io-digital/payfast


Add the PayfastServiceProvider to your providers array in config/app.php

```php
'providers' => [
    //

    IoDigital\Payfast\PayfastServiceProvider::class,
];
```
In your `.env` add the following keys:

```
PAYFAST_MERCHANT_ID=your-id
PAYFAST_MERCHANT_KEY=your-key
```

IMPORTANT: In order to work with the ITN callback reliably, it's suggested to make use of [ngrok](https://ngrok.com/).
That would make your `config/payfast.php` file url's look like:

```php
'return_url' => 'https://xxxxxxxx.ngrok.io/success',
```

### Config
publish default configuration file.

    php artisan vendor:publish

IMPORTANT: You will need to edit App\Http\Middleware\VerifyCsrfToken by adding the route, which handles the ITN response to the $except array. Validation is done via the ITN response.

```php
protected $except = [
        '/itn'
    ];
```


```php

/*
|--------------------------------------------------------------------------
| Merchant Settings
|--------------------------------------------------------------------------
| All Merchant settings below are for example purposes only. for more info
| see www.payfast.co.za. The Merchant ID and Merchant Key can be obtained
| from your payfast.co.za account.
|
*/

[

    'testing' => true, // Set to false when in production.

    'currency' => 'ZAR', // ZAR is the only supported currency at this point.

    'merchant' => [
        'merchant_id' => '10000100', // TEST Credentials. Replace with your merchant ID from Payfast.
        'merchant_key' => '46f0cd694581a', // TEST Credentials. Replace with your merchant key from Payfast.
        'return_url' => 'http://your-domain.co.za/success', // The URL the customer should be redirected to after a successful payment.
        'cancel_url' => 'http://your-domain.co.za/cancelled', // The URL the customer should be redirected to after a payment is cancelled.
        'notify_url' => 'http://your-domain.co.za/itn', // The URL to which Payfast will post return variables.
    ]

];

```
### Usage

Creating a payment returns an html form ready to POST to payfast. When the customer submits the form they will be redirected to payfast to complete payment. Upon successful payment the customer will be returned to the specified 'return_url' and in the case of a cancellation they will be returned to the specified 'cancel_url'

```php

use IoDigital\Payfast\Contracts\PaymentProcessor;

Class PaymentController extends Controller
{

    public function confirmPayment(PaymentProcessor $payfast)
    {
        // Eloquent example.
        $cartTotal = 9999;
        $order = Order::create([
                'm_payment_id' => '001', // A unique reference for the order.
                'amount'       => $cartTotal     
            ]);

        // Build up payment Paramaters.
        $payfast->setBuyer('first name', 'last name', 'email');

        //PS ADD DIVISION BY 100 FOR CENTS AS THE NEW DEPENDENCY "mathiasverraes/money": "^1.3" DOESN'T DO CONVERSION
        payfast->setAmount($purchase->amount / 100);

        $payfast->setItem('item-title', 'item-description');
        $payfast->setMerchantReference($order->m_payment_id);

        // Return the payment form.
        return $payfast->paymentForm('Place Order');
    }

}
```  

### ITN Responses

Payfast will send a POST request to notify the merchant (You) with a status on the transaction. This will allow you to update your order status based on the appropriate status sent back from Payfast. You are not forced to use the key 'm_payment_id' to store your merchant reference but this is the the key that will be returned back to you from Payfast for further verification.

```php

use IoDigital\Payfast\Contracts\PaymentProcessor;

Class PaymentController extends Controller
{

    public function itn(Request $request, PaymentProcessor $payfast)
    {
        // Retrieve the Order from persistance. Eloquent Example.
        $order = Order::where('m_payment_id', $request->get('m_payment_id'))->firstOrFail(); // Eloquent Example

        // Verify the payment status.
        $status = (int) $payfast->verify($request, $order->amount, /*$order->m_payment_id*/)->status();

        // Handle the result of the transaction.
        switch( $status )
        {
            case 'COMPLETE': // Things went as planned, update your order status and notify the customer/admins.
                break;
            case 'FAILED': // We've got problems, notify admin and contact Payfast Support.
                break;
            case 'PENDING': // We've got problems, notify admin and contact Payfast Support.
                break;
            default: // We've got problems, notify admin to check logs.
                break;
        }
    }       

}
```  

The response variables POSTED back by payfast may be accessed as follows:

```php

 return $payfast->responseVars();

```

Variables Returned by Payfast

```php

[
    'm_payment_id' => '',
    'pf_payment_id' => '',
    'payment_status' => '',
    'item_name' => '',
    'item_description' => '',
    'amount_gross' => '',
    'amount_fee' => '',
    'amount_net' => '',
    'custom_str1' => '',
    'custom_str2' => '',
    'custom_str3' => '',
    'custom_str4' => '',
    'custom_str5' => '',
    'custom_int1' => '',
    'custom_int2' => '',
    'custom_int3' => '',
    'custom_int4' => '',
    'custom_int5' => '',
    'name_first' => '',
    'name_last' => '',
    'email_address' => '',
    'merchant_id' => '',
    'signature' => '',
];

```

### Amounts

In the case of an integer, the cart total must be passed through in cents, as follows:

```php

$cartTotal = 9999;
// "mathiasverraes/money": "^1.3" doesn't convert from cents correctly yet
// That's why a manual division by 100 is necessary
$payfast->setAmount($cartTotal / 100);

```

### Payment Form

By default the getPaymentForm() method will return a compiled HTML form including a submit button. There are 3 configurations available for the submit button.

```php

$payfast->getPaymentForm() // Default Text: 'Pay Now'

$payfast->getPaymentForm(false) // No submit button, handy for submitting the form via javascript

$payfast->getPaymentForm('Confirm and Pay') // Override Default Submit Button Text.

```

### To Do's

1. Unit Testing
2. Add in a Facade Class
3. Allow for custom integers/strings
4. Curl request to Payfast (validation) -> needs more testing
