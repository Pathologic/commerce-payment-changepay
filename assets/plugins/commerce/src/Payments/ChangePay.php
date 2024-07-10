<?php

namespace Commerce\Payments;

class ChangePay extends Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('changepay');
        $this->debug = $this->getSetting('debug') == '1';
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('secret_key')) || empty($this->getSetting('api_key')) || empty($this->getSetting('shop_id'))) {
            return '<span class="error" style="color: red;">' . $this->lang['changepay.error_empty_params'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $payment   = $this->createPayment($order['id'], $order['amount']);
        $data = [
            'action' => 'createlink',
            'amount' => $payment['amount'],
            'currency' => $order['currency'],
            'order_id' => $order['id'] . '-' . $payment['id'],
            'chat_id' => '',
            'shop_id' => $this->getSetting('shop_id'),
            'api_key' => $this->getSetting('api_key'),
            'redirect_url' => MODX_SITE_URL,
        ];
        try {
            $response = $this->request($data);

			return $response['url'];
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3,
                    'Request failed: <pre>' . print_r($data, true) . '</pre><pre>' . print_r($e->getMessage() . ' ' . $e->getCode(), true) . '</pre>', 'Commerce ChangePay Payment');
            }
        }

        return false;
    }

    public function handleCallback()
    {
        $input = file_get_contents('php://input');
        $response = json_decode($input, true) ?? [];
        $headers = getallheaders();
        if ($this->debug) {
            $this->modx->logEvent(0, 3, 'Callback start <pre>' . print_r($response, true) . '</pre><pre>' . print_r($headers, true) . '</pre>', 'Commerce ChangePay Payment Callback');
        }
        if(isset($response['action']) && $response['action'] == 'success' && isset($response['order_id']) && isset($response['signature']) && $response['signature'] == $this->getSignature($response)) {
            $processor = $this->modx->commerce->loadProcessor();
            try {
                [$order_id, $payment_id] = explode('-', $response['order_id']);
                $payment = $processor->loadPayment($payment_id);

                if (!$payment || $payment['order_id'] != $order_id) {
                    throw new Exception('Payment "' . htmlentities(print_r($payment_id, true)) . '" . not found!');
                }

                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                if ($this->debug) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce ChangePay Payment Callback');

                    return false;
                }
            }
        }

        return false;
    }

    protected function getSignature(array $response)
    {
        $secret = $this->getSetting('secret_key');
        unset($response['signature']);
        ksort($response);

        return hash('sha256', json_encode($response) . $secret);
    }

    protected function request(array $data)
    {
        $curl = curl_init();
        $data = json_encode($data);
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://propayment.easycrypto.space/api/api.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $response = json_decode($response, true) ?? [];

        if ($httpcode !== 200 || empty($response) || (isset($response['error']))) {
            throw new \Exception('Request failed with ' . $httpcode . ': <pre>' . print_r($response, true) . '</pre>', $httpcode);
        }

        return $response;
    }
}
