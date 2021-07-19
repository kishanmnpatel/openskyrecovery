<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FortePayments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
	$gateway = new Gateway;
	$gateway->name = 'Forte Payments'; 
	$gateway->key = Str::lower(Str::random(32)); 
	$gateway->provider = ‘FancyGateway’;
	$gateway->is_offsite = true;
	$gateway->fields = new \stdClass;
	$gateway->visible = true;
	$gateway->site_url = "https://forte.net":;
	$gateway->default_gateway_type_id = 2;
	$gateway->save();        //
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
