<?php

namespace garethnic\payfast;

use garethnic\payfast\Contracts\PaymentProcessor;
use Illuminate\Http\Request;
use SebastianBergmann\Money\Currency;
use SebastianBergmann\Money\Money;
use Illuminate\Support\Facades\Log;
use Exception;

// Messages
// Error
define('PF_ERR_AMOUNT_MISMATCH', 'Amount mismatch');
define('PF_ERR_BAD_SOURCE_IP', 'Bad source IP address');
define('PF_ERR_CONNECT_FAILED', 'Failed to connect to PayFast');
define('PF_ERR_BAD_ACCESS', 'Bad access of page');
define('PF_ERR_INVALID_SIGNATURE', 'Security signature mismatch');
define('PF_ERR_CURL_ERROR', 'An error occurred executing cURL');
define('PF_ERR_INVALID_DATA', 'The data received is invalid');
define('PF_ERR_UKNOWN', 'Unkown error occurred');

// General
define('PF_MSG_OK', 'Payment was successful');
define('PF_MSG_FAILED', 'Payment has failed');

class Payfast implements PaymentProcessor
{
    protected $merchant;

    protected $buyer;

    protected $merchantReference;

    protected $amount;

    protected $item;

    protected $output;

    protected $vars;

    protected $response_vars;

    protected $host;

    protected $button;

    protected $status;

    protected $userAgent;

    protected $paramString;


    public function __construct()
    {
        $this->merchant = config('payfast.merchant');
        $this->userAgent = config('payfast.userAgent');
    }

    /**
     * Get merchant details
     *
     * @return mixed
     */
    public function getMerchant()
    {
        return $this->merchant;
    }

    /**
     * Add buyer details
     *
     * @param string $first
     * @param string $last
     * @param string $email
     */
    public function setBuyer($first, $last, $email)
    {
        $this->buyer = [
            'name_first' => $first,
            'name_last' => $last,
            'email_address' => $email
        ];
    }

    /**
     * Add merchant details for query string
     *
     * @param string $reference
     */
    public function setMerchantReference($reference)
    {
        $this->merchantReference = $reference;
    }

    /**
     * Add item details
     *
     * @param string $item
     * @param string $description
     */
    public function setItem($item, $description)
    {
        $this->item = [
            'item_name' => $item,
            'item_description' => $description,
        ];
    }

    /**
     * Add amount to be charged
     *
     * @param int $amount
     */
    public function setAmount($amount)
    {
        $money = $this->newMoney($amount);

        $this->amount = $money->getConvertedAmount();
    }

    /**
     * Create payment form
     *
     * @param bool $submitButton
     * @return string
     */
    public function paymentForm($submitButton = true)
    {
        $this->button = $submitButton;

        $this->vars = $this->paymentVars();

        $this->buildQueryString();

        $this->vars['signature'] = md5($this->output);

        return $this->buildForm();
    }

    /**
     * Payment details
     *
     * @return mixed
     */
    public function paymentVars()
    {
        return array_merge($this->merchant, $this->buyer,
            ['m_payment_id' => $this->merchantReference, 'amount' => $this->amount], $this->item);
    }

    /**
     * Bring it all together for the query string
     *
     */
    public function buildQueryString()
    {
        foreach ($this->vars as $key => $val) {
            if (!empty($val)) {
                $this->output .= $key . '=' . urlencode(trim($val)) . '&';
            }
        }

        $this->output = substr($this->output, 0, -1);
        $this->paramString = $this->output;

        if (isset($passPhrase)) {
            $this->output .= '&passphrase=' . $passPhrase;
        }
    }

    /**
     * Form building grunt work
     *
     * @return string
     */
    public function buildForm()
    {
        $this->getHost();

        $htmlForm = '<form id="payfast-pay-form" action="https://' . $this->host . '/eng/process" method="post">';

        foreach ($this->vars as $name => $value) {
            $htmlForm .= '<input type="hidden" name="' . $name . '" value="' . $value . '">';
        }

        if ($this->button) {
            $htmlForm .= '<button type="submit">' . $this->getSubmitButton() . '</button>';
        }

        return $htmlForm . '</form>';
    }

    /**
     * Perform security checks
     *
     * @param $request
     * @param int $amount
     * @return $this
     * @throws Exception
     */
    public function verify($request, $amount)
    {
        $this->setHeader();

        $this->response_vars = $request->all();

        $this->setAmount($amount);

        foreach ($this->response_vars as $key => $val) {
            $this->vars[$key] = stripslashes($val);
        }

        $this->buildQueryString();

        Log::info('Validating signature');
        $this->validSignature($request->get('signature'));
        Log::info('Validating host');
        $this->validateHost($request);
        Log::info('Validating amount');
        $this->validateAmount($request->get('amount_gross'));
        Log::info('Validating payfast data');
        $this->validatePayfastData($request);

        $this->status = $request->get('payment_status');

        return $this;
    }

    /**
     * Get payment status
     *
     * @return mixed
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * Obligatory header
     *
     */
    public function setHeader()
    {
        header('HTTP/1.0 200 OK');
        flush();
    }

    /**
     * Check for valid signature
     *
     * @param $signature
     * @return bool
     * @throws Exception
     */
    public function validSignature($signature)
    {
        if ($this->vars['signature'] === $signature) {
            return true;
        } else {
            throw new Exception('Invalid Signature');
        }
    }

    /**
     * Check for valid host
     *
     * @param $request
     * @return bool
     * @throws Exception
     */
    public function validateHost($request)
    {
        $hosts = $this->getHosts();

        //REMOTE_ADDR returns ::1 ipv6 localhost
        if (!in_array($request->server('HTTP_X_FORWARDED_FOR'), $hosts)) {
            throw new Exception('Not a valid Host');
        }

        return true;
    }

    /**
     * Get host unique IP's
     *
     * @return mixed
     */
    public function getHosts()
    {
        $validHosts = config('payfast.hosts');

        $validIps = [];

        foreach ($validHosts as $pfHostname) {
            $ips = gethostbynamel($pfHostname);

            if ($ips !== false) {
                $validIps = array_merge($validIps, $ips);
            }
        }

        // Remove duplicates
        $validIps = array_unique($validIps);

        return $validIps;
    }

    /**
     * Check for valid payment amount
     *
     * @param $grossAmount
     * @return bool
     * @throws Exception
     */
    public function validateAmount($grossAmount)
    {
        if ($this->amount === $this->newMoney($grossAmount, true)->getConvertedAmount()) {
            return true;
        } else {
            throw new Exception('The gross amount does not match the order amount');
        }
    }

    /**
     * Create money object
     *
     * @param $amount
     * @return Money|static
     */
    public function newMoney($amount)
    {
        if (is_string($amount) || is_float($amount)) {
            return Money::fromString((string)$amount, new Currency('ZAR'));
        }

        return new Money($amount, new Currency('ZAR'));
    }

    /**
     * Get host
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host = config('payfast.testing') ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
    }

    /**
     * Create payment button
     *
     * @return bool|string
     */
    public function getSubmitButton()
    {
        if (is_string($this->button)) {
            return $this->button;
        }

        if ($this->button == true) {
            return 'Pay Now';
        }

        return false;
    }

    /**
     * Get ITN response variables
     *
     * @return mixed
     */
    public function responseVars()
    {
        return $this->response_vars;
    }

    /**
     * ITN callback validation
     * 
     * @param Request $request
     */
    public function validatePayfastData(Request $request)
    {
        Log::info('Validating Payfast data');
        $url = 'https://' . $this->getHost() . '/eng/query/validate';
        $output = '';
        $pfError = false;

        if (in_array('curl', get_loaded_extensions())) {
            $ch = curl_init();

            $curlOpts = [
                // Base options
                CURLOPT_USERAGENT => $this->userAgent, // Set user agent
                CURLOPT_RETURNTRANSFER => true,  // Return output as string rather than outputting it
                CURLOPT_HEADER => false,         // Don't include header in output
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,

                // Standard settings
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $this->paramString,
            ];
            curl_setopt_array($ch, $curlOpts);

            // Execute CURL
            if (!$res = curl_exec($ch)) {
                trigger_error(curl_error($ch));
            }
            curl_close($ch);

            Log::info('cURL response - ' . $res);

            if (!$res) {
                $pfError = true;
                $pfErrMsg = PF_ERR_CURL_ERROR;
            }
        } else {
            // Construct Header
            $header = "POST /eng/query/validate HTTP/1.0\r\n";
            $header .= "Host: " . $this->host . "\r\n";
            $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $header .= "Content-Length: " . strlen($this->paramString) . "\r\n\r\n";

            // Connect to server
            $socket = fsockopen('ssl://' . $this->host, 443, $errno, $errstr, 10);

            // Send command to server
            fputs($socket, $header . $this->paramString);

            // Read the response from the server
            $res = '';
            $headerDone = false;

            while (!feof($socket)) {
                $line = fgets($socket, 1024);

                // Check if we are finished reading the header yet
                if (strcmp($line, "\r\n") == 0) {
                    // read the header
                    $headerDone = true;
                } else {
                    if ($headerDone) { // If header has been processed
                        // Read the main response
                        $res .= $line;
                    }
                }
            }
        }
        // Get data from server
        if (!$pfError) {
            // Parse the returned data
            $lines = explode("\n", $res);

            $output .= "\n\nValidate response from server:\n\n"; // DEBUG

            foreach ($lines as $line) // DEBUG
            {
                $output .= $line . "\n";
            } // DEBUG
        }

        // Interpret the response from server
        if (!$pfError) {
            // Get the response from PayFast (VALID or INVALID)
            $result = trim($lines[0]);

            $output .= "\nResult = " . $result; // DEBUG

            // If the transaction was valid
            if (strcmp($result, 'VALID') == 0) {
                // Process as required
                Log::info('Socket response - ' . $result);
            } // If the transaction was NOT valid
            else {
                // Log for investigation
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_DATA;
            }
        }

        // If an error occurred
        if ($pfError) {
            $output .= "\nAn error occurred!";
            $output .= "\nError = " . $pfErrMsg;
        }

        // Log output to file | DEBUG
        //Log::info('Payfast response: ' . $output);
    }
}