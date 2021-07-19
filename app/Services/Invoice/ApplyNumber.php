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

namespace App\Services\Invoice;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\AbstractService;
use App\Utils\Traits\GeneratesCounter;

class ApplyNumber extends AbstractService
{
    use GeneratesCounter;

    private $client;

    private $invoice;

    public function __construct(Client $client, Invoice $invoice)
    {
        $this->client = $client;

        $this->invoice = $invoice;
    }

    public function run()
    {
        if ($this->invoice->number != '') {
            return $this->invoice;
        }

        switch ($this->client->getSetting('counter_number_applied')) {
            case 'when_saved':
                $this->invoice->number = $this->getNextInvoiceNumber($this->client, $this->invoice, $this->invoice->recurring_id);
                break;
            case 'when_sent':
                if ($this->invoice->status_id == Invoice::STATUS_SENT) {
                    $this->invoice->number = $this->getNextInvoiceNumber($this->client, $this->invoice, $this->invoice->recurring_id);
                }
                break;

            default:
                // code...
                break;
        }

        return $this->invoice;
    }
}
