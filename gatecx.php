<?php
/*
**********************************************

      *** GateCX Embedded for WHMCS ***

File:					gatecx.php
File version:			0.1.0
Date:					16-07-2016

Requires:				gatecx/cacert.pem

Copyright (C) NetDistrict 2016
All Rights Reserved
**********************************************
*/

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;


/**
 * Module meta data.
 */
function gatecx_MetaData()
{
	
    return array(
        'DisplayName' => 'GateCX Embedded for Stripe.com',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => true,
    );
	
}


/**
 * Gateway configuration options.
 */
function gatecx_config()
{
	
    return array(
        # The friendly display name for a payment gateway should be
        # defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'GateCX Embedded for Stripe.com',
        ),
		'private-key' => array(
			'FriendlyName' => 'Private Key', 
			'Type' => 'text', 
			'Size' => '40', 
		), 
		'public-key' => array(
			'FriendlyName' => 'Public Key', 
			'Type' => 'text', 
			'Size' => '40', 
		), 
    );
	
}


/**
 * Make payment gateway available in front end.
 */
function gatecx_link($params)
{
	
	# Enter your code submit to the gateway...
	
	$code = '<form method="post" action="creditcard.php">
			<input type="hidden" name="invoiceid" value="'.$params['invoiceid'].'" />
			<input type="submit" value="'.$params['langpaynow'].'" />
			</form>';	
		
	return $code;
	
}


/**
 * Capture payment.
 *
 * Called when a payment is to be processed and captured.
 *
 * The card cvv number will only be present for the initial card holder present
 * transactions. Automated recurring capture attempts will not provide it.
 *
 */
function gatecx_capture($params)
{
	
    # Gateway Configuration Parameters
    $accountId = $params['accountID'];
	
    # Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount']; // Format: 000.00
	$gatewayAmount = $amount*100; // Amount in cents
    $currencyCode = $params['currency']; // Currency Code

    # Credit Card Parameters
    $cardToken = $params['gatewayid'];
	
	# Charge description
	if (isset($params['invoicenum'])) $chargeDesc = $params['invoicenum'];
	else $chargeDesc = $params['invoiceid'];

	# Post data variables
	$url = 'https://api.stripe.com/v1/charges';
	$data = 'amount='.$gatewayAmount.'&currency='.$currencyCode.'&customer='.$cardToken.'&description='.$chargeDesc;
		
	$result = gatecx_connect($url, $data);
		
	$transactionId = $result->id;
	$status = $result->status;
	$amount = ($result->amount) / 100;
	$currency = $result->currency;
	$failCode = $result->failure_code;
	$failDesc = $result->failure_message;
	$feeAmount = NULL;	
	
	if ($status == 'succeeded' || $status == 'paid') {
		
		return array(
			'status' => 'success',
			'transid' => $transactionId,
			'fees' => $feeAmount,
			'rawdata' => array(
				'Transaction ID' => $transactionId
			),
		);

	} else {
				
		return array(
			'status' => 'declined',
			'rawdata' => array(
				'Transaction ID' => $transactionId,
				'Failure Code' => $failCode,
				'failure Description' => $failDesc
			),
		);
		
	}
	
}


/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 */
function gatecx_refund($params)
{
	
	# Invoice Variables
	$invoiceid = $params['invoiceid'];
	$transid = $params['transid']; // Transaction ID of original payment
	$amount = $params['amount']; // Format: 000.00
	$gatewayAmount = $amount*100; // Amount in cents
    $currency = $params['currency']; // Currency code
	
	# Perform Refund Here & Generate $results Array, eg:	
	$url = 'https://api.stripe.com/v1/charges/'.$transid.'/refund';
	$data = 'amount='.$gatewayAmount;
		
	$result = gatecx_connect($url, $data);

	# Return Results
	if (!isset($result->error)) {
		
		return array(
			'status' => 'success',
			'transid' => $result->refunds->data[0]->id,
			'rawdata' => array(
				'invoice ID' => $invoiceid,
				'Amount' => $amount,
			),
		);
	
	} else {
		
		return array(
			'status' => 'error',
			'rawdata' => array(
				'invoice ID' => $invoiceid,
				'Transaction ID' => $transid,
				'Message' => $result->error->message,
			),
		);
	
	}
	
}


/**
 * Tokenised Remote Storage.
 */
function gatecx_storeremote($params)
{

	$clientdetails = $params['clientdetails'];
			
	# Split expiry data
	$exp_data = str_split($params['cardexp'], 2);
	$exp_month = $exp_data[0];
	$exp_year =  '20'.$exp_data[1];
	
	# Set name
	if (!empty($clientdetails['companyname'])) $name = $clientdetails['companyname'];
	else $name = $clientdetails['firstname'] .' '. $clientdetails['lastname'];
	
	# Set status
	$status = "error";
		
	# Create CC token
	$url = 'https://api.stripe.com/v1/tokens';
	$data = 'card[number]='.$params['cardnum'].'&card[exp_month]='.$exp_month.'&card[exp_year]='.$exp_year.'&card[cvc]='.$params['cardcvv'].'&card[address_city]='.$clientdetails['city'].'&card[address_country]='.$clientdetails['countrycode'].'&card[address_line1]='.$clientdetails['address1'].'&card[address_line2]='.$clientdetails['address2'].'&card[address_state]='.$clientdetails['state'].'&card[address_zip]='.$clientdetails['postcode'].'&card[name]='.$name;
		
	$result = gatecx_connect($url, $data);
	
	if(is_object($result)) {
		
		# Check if customer has been created
		if (empty($params['gatewayid'])) {
	
			# Create customer
			$url = 'https://api.stripe.com/v1/customers';
			$data = 'description='.$clientdetails['userid'].'&email='.$clientdetails['email'].'&source='.$result->id; 
			$action = 'create';
											
		} elseif ($params['action'] == 'delete') {
			
			# Get default card
			$url = 'https://api.stripe.com/v1/customers/'.$params['gatewayid'];
			$result = gatecx_connect($url);
						
			# Delete customer entry
			$url = 'https://api.stripe.com/v1/customers/'.$params['gatewayid'].'/sources/'.$result->default_source;
			$data = 'DELETE';
			$action = 'delete';
					
		} else {
	
			# Update customer
			$url = 'https://api.stripe.com/v1/customers/'.$params['gatewayid'];
			$data = 'description='.$clientdetails['userid'].'&source='.$result->id; 
			$action = 'update';
			
		}
		
		$result = gatecx_connect($url, $data);
		
		if (isset($result->error)) {

			return array(
				'status' => 'failed',
				'rawdata' => array(
					'action' => $action,
					'description'=>$result->error->message
				),
			);
			
		}
		
	}

	return array(
		'status' => 'success',
		'gatewayid' => $result->id,
		'rawdata' => array(
			'token' => $result->id,
			'action' => $action
		),
	);
	
}


/**
 * Stripe Curl API Connect.
 **/

function gatecx_connect($url, $data=NULL)
{	

	# Get private key
	$gateway = Capsule::table('tblpaymentgateways')->where('gateway','gatecx')->where('setting','private-key')->first();
	$private_key = $gateway->value;
			
	# Send and Retrieve data
    $ch = curl_init($url);

	curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Client: GateCX PHP v0.0.1'));
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERPWD, $private_key . ":" . NULL);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_POST, 1);
	
	if ($data == 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');	
	else curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
	curl_setopt($ch, CURLOPT_CAINFO, dirname ( __FILE__ ). DIRECTORY_SEPARATOR."gatecx".DIRECTORY_SEPARATOR."cacert.pem");

	$return = curl_exec($ch);
	
	# Curl debug
	//echo 'Curl error: ' . curl_error($ch);
	
	curl_close($ch);
	
	# Return Results
    return json_decode($return);
	
}

?>