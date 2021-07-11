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

namespace App\Http\Livewire;

use Livewire\Component;
use App\Libraries\MultiDB;
use App\Models\GatewayType;

class PayNowDropdown extends Component
{
    public $total;

    public $methods;

    public $company;
    
    public function mount(int $total)
    {
        MultiDB::setDb($this->company->db);

        $this->total = $total;

        $this->methods = auth()->user()->client->service()->getPaymentMethods($total);
        if($this->methods[0]['label'] == 'Forte '){
            $this->methods[] = [
            'label'=>'Bank Transfer',
            'company_gateway_id'=>auth()->user()->client->service()->getPaymentMethods($total)[0]['company_gateway_id'],
            'gateway_type_id'=>GatewayType::BANK_TRANSFER,
            ];
        }
    }

    public function render()
    {
        return render('components.livewire.pay-now-dropdown');
    }
}
