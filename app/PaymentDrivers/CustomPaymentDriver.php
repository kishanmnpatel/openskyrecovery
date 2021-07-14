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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Class CustomPaymentDriver.
 */
class CustomPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $token_billing = false;

    public $can_authorise_credit_card = false;

    private $forte_base_uri="https://api.forte.net/v3/";
    private $forte_api_access_id="6106e6bb2a66bc9b9302bf2cf32ea885";
    private $forte_secure_key="f3ab50eb9d8b47dd9ecc0596a435ea57";
    private $forte_auth_organization_id="org_300005";
    private $forte_organization_id="org_409865";
    private $forte_location_id="loc_277878";
    private $service_fee=3;

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
        if (request()->get('method') == 1) {
            return render('gateways.custom.authorize', $data);
        }elseif (request()->get('method') == 2) {
            return render('gateways.custom.ach.authorize', array_merge($data));
        }
    }

    public function authorizeResponse($request)
    {
        // echo '{
        //     "notes":"Brwn echeck",
        //     "echeck": {
        //         "account_holder": "'.$request->account_holder_name.'",
        //         "account_number":"'.$request->account_number.'",
        //         "routing_number":"'.$request->routing_number.'",
        //         "account_type":"checking"
        //     }
        // }';
        // dd($request->all());
        if ($request->method == GatewayType::CREDIT_CARD) {
            return $this->authorizeCreditCardResponse($request);
        }elseif ($request->method == GatewayType::BANK_TRANSFER) {
            return $this->authorizeACHResponse($request);
        }
    }

    private function authorizeCreditCardResponse($request)
    {
        $request->validate([
            'card_number'=>'required',
            'card_holders_name'=>'required|string',
            'expiry_month'=>'required',
            'expiry_year'=>'required',
            'cvc'=>'required',
        ]);
        $client=auth()->user()->client;
        if ($client->customer_token == null || $client->customer_token == '') {
            $curl = curl_init();

            curl_setopt_array($curl, array(
            CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/customers/',
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
                'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
                'Content-Type: application/json',
                'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
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
        CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/customers/'.$client->customer_token.'/paymethods',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "notes":"'.$request->card_holders_name.' Card",
            "card": {
                "name_on_card":"'.$request->card_holders_name.'",
                "card_type":"'.$request->card_type.'",
                "account_number":"'.str_replace(' ', '', $request->card_number).'",
                "expire_month":'.$request->expiry_month.',
                "expire_year":20'.$request->expiry_year.',
                "card_verification_value": "'.$request->cvc.'"
            }   
        }',
        CURLOPT_HTTPHEADER => array(
            'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
            'Content-Type: application/json',
            'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
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

    private function authorizeACHResponse($request)
    {
        $request->validate([
            'account_number'=>'required|numeric',
            'account_holder_name'=>'required|string',
            'routing_number'=>'required|numeric',
        ]);

        $client=auth()->user()->client;
        if ($client->customer_token == null || $client->customer_token == '') {
            $curl = curl_init();

            curl_setopt_array($curl, array(
            CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/customers/',
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
                'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
                'Content-Type: application/json',
                'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
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
        CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/customers/'.$client->customer_token.'/paymethods',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "notes":"'.$request->account_holder_name.' echeck",
            "echeck": {
                "account_holder": "'.$request->account_holder_name.'",
                "account_number":"'.$request->account_number.'",
                "routing_number":"'.$request->routing_number.'",
                "account_type":"checking"
            }
        }',
        CURLOPT_HTTPHEADER => array(
            'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
            'Content-Type: application/json',
            'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
            'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=tVVcSY5O+xzIMhyvR1efXgAAAABn4GsrsejFXewG9LEvz7cm; incap_ses_9153_621087=wAileyRCBU3lBWqsNP0Ff80/6GAAAAAASCPsRmBm9ygyrCA0iBX3kg==; incap_ses_9210_621087=OHvJaqfG9Cc+r/0GZX7Qf10a6WAAAAAA1CWMfnTjC/4Y/4bz/HTgBg==; incap_ses_713_621087=Lu/yR4IM2iokOlO8ExblCSWB6WAAAAAANBLUy0jRk/4YatHkXIajvA=='
        ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response=json_decode($response);

        if ($httpcode>299) {
            Session::flash('error', $response->response->response_desc); 
            return redirect('payment_methods/create?method='.GatewayType::BANK_TRANSFER);
        }

        $payment_meta = new \stdClass;
        // $payment_meta->brand = (string)sprintf('%s (%s)', $request->bank_name, ctrans('texts.ach'));
        $payment_meta->brand = (string)ctrans('texts.ach');
        $payment_meta->last4 = (string)$response->echeck->last_4_account_number;
        $payment_meta->exp_year = '-';
        $payment_meta->type = GatewayType::BANK_TRANSFER;

        $clientGatewayToken=new ClientGatewayToken();
        $clientGatewayToken->company_id=$client->company->id;
        $clientGatewayToken->client_id=$client->id;
        $clientGatewayToken->token=$response->paymethod_token;
        $clientGatewayToken->company_gateway_id=$this->company_gateway->id;
        $clientGatewayToken->gateway_type_id=GatewayType::BANK_TRANSFER;
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
        $data['gateway_title']=ctrans('texts.payment_type_credit_card');
        if ($data['payment_method_id'] == 2) {
            $data['gateway_title']='ACH';
        }
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

        $curl = curl_init();

        if($data->payment_method_id == 1){
            curl_setopt_array($curl, array(
            CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/transactions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
                "action":"authorize",
                "authorization_amount": '.$data->amount_with_fee.',
                "paymethod_token": "'.$data->payment_token.'",
                "service_fee_amount": '.$data->fee_total.',
                "billing_address":{
                    "first_name": "'.auth()->user()->client->name.'",
                    "last_name": "'.auth()->user()->client->name.'"
                }
            }',
            CURLOPT_HTTPHEADER => array(
                'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
                'Content-Type: application/json',
                'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
                'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=tVVcSY5O+xzIMhyvR1efXgAAAABn4GsrsejFXewG9LEvz7cm; incap_ses_713_621087=fcX1QL+cdVg9Szu8ExblCYU06GAAAAAAgm6Ddpkg9bfkAth70P7yfw=='
            ),
            ));
        }elseif($data->payment_method_id == 2){
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/transactions',
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
                    "echeck":{
                        "sec_code":"PPD",
                    },
                    "billing_address":{
                        "first_name": "'.auth()->user()->client->name.'",
                        "last_name": "'.auth()->user()->client->name.'"
                    }
                }',
                CURLOPT_HTTPHEADER => array(
                    'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
                    'Content-Type: application/json',
                    'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
                    'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=tVVcSY5O+xzIMhyvR1efXgAAAABn4GsrsejFXewG9LEvz7cm; incap_ses_713_621087=fcX1QL+cdVg9Szu8ExblCYU06GAAAAAAgm6Ddpkg9bfkAth70P7yfw=='
                ),
                ));
        }

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
        return redirect('client/invoices')->withSuccess('Invoice paid.');
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
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->forte_base_uri.'organizations/'.$this->forte_organization_id.'/locations/'.$this->forte_location_id.'/paymethods/'.$token->token,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => array(
            'X-Forte-Auth-Organization-Id: '.$this->forte_organization_id,
            'Authorization: Basic '.base64_encode($this->forte_api_access_id.':'.$this->forte_secure_key),
            'Cookie: visid_incap_621087=QJCccwHeTHinK5DnAeQIuXPk5mAAAAAAQUIPAAAAAAATABmm7IZkHhUi85sN+UaS; nlbi_621087=tVVcSY5O+xzIMhyvR1efXgAAAABn4GsrsejFXewG9LEvz7cm; incap_ses_9153_621087=wAileyRCBU3lBWqsNP0Ff80/6GAAAAAASCPsRmBm9ygyrCA0iBX3kg==; incap_ses_9210_621087=OHvJaqfG9Cc+r/0GZX7Qf10a6WAAAAAA1CWMfnTjC/4Y/4bz/HTgBg==; incap_ses_713_621087=I+TAKy3YCA4t9FO8ExblCa+K6WAAAAAAfzQ3eTcdBh4rJjnrZAB9OA=='
        ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response=json_decode($response);

        if ($httpcode>299) {
            Session::flash('error', $response->response->response_desc); 
            return false;
        }
        
        return true;
    }
}
