<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Utils\HtmlEngine;
use App\Models\GatewayType;
use App\Utils\Traits\MakesHash;
use App\Models\ClientGatewayToken;
use Illuminate\Support\Facades\Session;

/**
 * Class CustomPaymentDriver.
 */
class CustomPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $token_billing = false;

    public $can_authorise_credit_card = false;

    /**
     * Returns the gateway types.
     */
    public function gatewayTypes(): array
    {
        $types = [
            GatewayType::CUSTOM,
        ];

        return $types;
    }

    public function setPaymentMethod($payment_method_id)
    {
        $this->payment_method = $payment_method_id;

        return $this;
    }

    public function authorizeView(array $data)
    {
        // dd($data);
        return render('gateways.custom.authorize', $data);
        // return $this->payment_method->authorizeView($data); //this is your custom implementation from here
    }

    public function authorizeResponse($request)
    {
        // echo '{
        //     "notes":"Brwn Work Card",
        //     "card": {
        //         "name_on_card":"'.$request->card_holders_name.'",
        //         "card_type":"visa",
        //         "account_number":"'.$request->card_number.'",
        //         "expire_month":'.$request->expiry_month.',
        //         "expire_year":'.$request->expiry_year.',
        //         "card_verification_value": "'.$request->cvc.'"
        //     }   
        // }';
        // dd($request->all());
        $request->validate([
            'card_number'=>'required|min:14|max:16',
            'card_holders_name'=>'required|string',
            'expiry_month'=>'required|numeric:2',
            'expiry_year'=>'required|numeric:2',
            'cvc'=>'required|numeric:3',
        ]);
        $client=auth()->user()->client;
        if ($client->customer_token == null || $client->customer_token == '') {
            $curl = curl_init();

            curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://sandbox.forte.net/api/v3/organizations/org_410728/locations/loc_278961/customers/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
                "first_name": "'.$client->name.'",
                "last_name": "'.$client->name.'",
                "company_name": "'.$client->name.'",
                "customer_id": "'.$client->number.'"
            }',
            CURLOPT_HTTPHEADER => array(
                'X-Forte-Auth-Organization-Id: org_410728',
                'Content-Type: application/json',
                'Authorization: Basic ZjRjMDJhZDA0NDIwOGQzYjNlMDNmZTMyMDZlODU2YWY6MzI2YjY1ZjA4NWVjNjUxMzY0ZDg2M2FjY2Q4MzkxYzc=',
                'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=eeFJXPvhGXW3XVl0R1efXgAAAAC5hY2Arn4aSDDQA+R2vZZu; incap_ses_713_621087=IuVrdOb1HwK0pTS8ExblCT8B6GAAAAAAWyswWx7wzWve4j23+Nsp4w=='
            ),
            ));
    
            $response = curl_exec($curl);
    
            curl_close($curl);
            
            $response=json_decode($response);
            $client->customer_token=$response->customer_token;
            $client->save();
        }
        
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://sandbox.forte.net/api/v3/organizations/org_410728/locations/loc_278961/customers/'.$client->customer_token.'/paymethods',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "notes":"Brwn Work Card",
            "card": {
                "name_on_card":"'.$request->card_holders_name.'",
                "card_type":"'.$request->card_type.'",
                "account_number":"'.$request->card_number.'",
                "expire_month":'.$request->expiry_month.',
                "expire_year":20'.$request->expiry_year.',
                "card_verification_value": "'.$request->cvc.'"
            }   
        }',
        CURLOPT_HTTPHEADER => array(
            'X-Forte-Auth-Organization-Id: org_410728',
            'Content-Type: application/json',
            'Authorization: Basic ZjRjMDJhZDA0NDIwOGQzYjNlMDNmZTMyMDZlODU2YWY6MzI2YjY1ZjA4NWVjNjUxMzY0ZDg2M2FjY2Q4MzkxYzc=',
            'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS'
        ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response=json_decode($response);

        if ($httpcode>299) {
            Session::flash('error', $response->response->response_desc); 
            return redirect()->back();
        }

        $payment_meta = new \stdClass;
        $payment_meta->exp_month = (string) $response->card->expire_month;
        $payment_meta->exp_year = (string) $response->card->expire_year;
        $payment_meta->brand = (string) $response->card->card_type;
        $payment_meta->last4 = (string) $response->card->last_4_account_number;
        $payment_meta->type = GatewayType::CREDIT_CARD;

        $clientGatewayToken=new ClientGatewayToken();
        $clientGatewayToken->company_id=2;
        $clientGatewayToken->client_id=$client->id;
        $clientGatewayToken->token=$response->paymethod_token;
        $clientGatewayToken->company_gateway_id=5;
        $clientGatewayToken->gateway_type_id=1;
        $clientGatewayToken->meta=$payment_meta;
        $clientGatewayToken->save();

        return redirect()->route('client.payment_methods.index');
    }

    /**
     * View for displaying custom content of the driver.
     *
     * @param array $data
     * @return mixed
     */
    public function processPaymentView($data)
    {
        $variables = [];

        if (count($this->payment_hash->invoices()) > 0) {
            $invoice_id = $this->decodePrimaryKey($this->payment_hash->invoices()[0]->invoice_id);
            $invoice = Invoice::findOrFail($invoice_id);

            $variables = (new HtmlEngine($invoice->invitations->first()))->generateLabelsAndValues();
        }

        $data['title'] = $this->company_gateway->getConfigField('name');
        $data['instructions'] = strtr($this->company_gateway->getConfigField('text'), $variables['values']);

        $this->payment_hash->data = array_merge((array) $this->payment_hash->data, $data);
        $this->payment_hash->save();

        $data['gateway'] = $this;
        // dd($data);
        return render('gateways.custom.pay', $data);
    }

    /**
     * Processing method for payment. Should never be reached with this driver.
     *
     * @return mixed
     */
    public function processPaymentResponse($request)
    {
        $data=$request;
        // dd($data);
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://sandbox.forte.net/api/v3/organizations/org_410728/locations/loc_278961/transactions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "action":"sale",
            "authorization_amount": '.$data->amount_with_fee.',
            "paymethod_token": "'.$data->payment_token.'",
            "billing_address":{
            "first_name": "'.auth()->user()->client->name.'",
            "last_name": "'.auth()->user()->client->name.'"
        }
        }',
        CURLOPT_HTTPHEADER => array(
            'X-Forte-Auth-Organization-Id: org_410728',
            'Content-Type: application/json',
            'Authorization: Basic ZjRjMDJhZDA0NDIwOGQzYjNlMDNmZTMyMDZlODU2YWY6MzI2YjY1ZjA4NWVjNjUxMzY0ZDg2M2FjY2Q4MzkxYzc=',
            'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=tVVcSY5O+xzIMhyvR1efXgAAAABn4GsrsejFXewG9LEvz7cm; incap_ses_713_621087=fcX1QL+cdVg9Szu8ExblCYU06GAAAAAAgm6Ddpkg9bfkAth70P7yfw=='
        ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        $response=json_decode($response);
        if ($httpcode>299) {
            Session::flash('error', $response->response->response_desc); 
            return redirect('client/invoices');
        }

        $data['gateway_type_id']=GatewayType::CREDIT_CARD;
        $data['amount']=$request->amount_with_fee;
        $data['payment_type']=6;
        $data['transaction_reference']=$response->transaction_id;
        // dd($data);
        $payment=$this->createPayment($data, Payment::STATUS_COMPLETED);
        Session::flash('success', 'Invoice Paid successfully.'); 
        return redirect('client/invoices');
    }

    /**
     * Detach payment method from custom payment driver.
     *
     * @param ClientGatewayToken $token
     * @return void
     */
    public function detach(ClientGatewayToken $token)
    {
        // Driver doesn't support this feature.
    }
}
