<?php
/**
 * The Braintree webhook handler
 *
 * @since 1.9.5 - Various updates to how we log & process requests from Braintree
 */

// If loading directly, make sure we return a 200 HTTP status
global $isapage;
$isapage = true;

//in case the file is loaded directly
if ( ! defined( "ABSPATH" ) ) {
	define( 'WP_USE_THEMES', false );
	require_once( dirname( __FILE__ ) . '/../../../../wp-load.php' );
}

//globals
global $wpdb;

// Debug log
global $logstr;
$logstr = array( "Logged On: " . date_i18n( "m/d/Y H:i:s", current_time( 'timestamp' ) ) );

// Don't run this with wrong PHP version
if ( version_compare( PHP_VERSION, '5.4.45', '<' ) ) {
	return;
}

//load Braintree library, gateway class constructor does config
if ( ! class_exists( '\Braintree' ) ) {
	require_once( PMPRO_DIR . "/classes/gateways/class.pmprogateway_braintree.php" );
}

$gateway             = new PMProGateway_braintree();
$webhookNotification = null;

if ( empty( $_REQUEST['bt_payload'] ) ) {
	$logstr[] = "No payload in request?!? " . print_r( $_REQUEST, true );
	pmpro_braintreeWebhookExit();
}

if ( isset( $_POST['bt_signature'] ) && ! isset( $_POST['bt_payload'] ) ) {
	$logstr[] = "No payload and signature included in the request!";
	pmpro_braintreeWebhookExit();
}

//get notification
try {
	/**
	 * @since 1.9.5 - BUG FIX: Unable to identify Braintree Webhook messages
	 *        Expecting Braintree library to sanitize signature & payload since using sanitize_text_field() breaks Braintree parser
	 */
	$webhookNotification = Braintree_WebhookNotification::parse( $_POST['bt_signature'], $_POST['bt_payload'] );
} catch ( Exception $e ) {
	{
		$logstr[] = "Couldn't extract notification from payload: {$_REQUEST['bt_payload']}";
		$logstr[] = "Error message: " . $e->getMessage();
		
		pmpro_braintreeWebhookExit();
	}
}

/**
 * @since 1.9.5 - ENHANCEMENT: Log if notification object has unexpected format
 */
if ( ! isset( $webhookNotification->kind ) ) {
	
	$logstr[] = "Unexpected webhook message: " . print_r( $webhookNotification, true ) . "\n";
	pmpro_braintreeWebhookExit();
}

/**
 * Only verifying?
 * @since 1.9.5 - Log Webhook tests with webhook supplied timestamp (verifies there's no caching).
 */
if ( $webhookNotification->kind === Braintree_WebhookNotification::CHECK ) {
	
	$when = $webhookNotification->timestamp->format('Y-m-d H:i:s.u');
	
	$logstr[] = "Since you are just testing the URL, check that the timestamp updates on refresh to make sure this isn't being cached.";
	$logstr[] = "Braintree gateway timestamp: {$when}";
	pmpro_braintreeWebhookExit();
}

//subscription charged sucessfully
if ( $webhookNotification->kind === Braintree_WebhookNotification::SUBSCRIPTION_CHARGED_SUCCESSFULLY ) {
	$logstr[] = "The Braintree gateway received payment for a recurring billing plan";
	
	//need a subscription id
	if ( empty( $webhookNotification->subscription->id ) ) {
		$logstr[] = "No subscription ID.";
		pmpro_braintreeWebhookExit();
	}
	
	//figure out which order to attach to
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $webhookNotification->subscription->id );
	
	//no order?
	if ( empty( $old_order ) ) {
		$logstr[] = "Couldn't find the original subscription with ID={$webhookNotification->subscription->id}.";
		pmpro_braintreeWebhookExit();
	}
	
	//create new order
	$user_id                = $old_order->user_id;
	$user                   = get_userdata( $user_id );
	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );
	
	if ( empty( $user ) ) {
		$logstr[] = "Couldn't find the old order's user. Order ID = {$old_order->id}.";
		pmpro_braintreeWebhookExit();
	}
	
	//data about this transaction
	$transaction = $webhookNotification->subscription->transactions[0];
	
	//alright. create a new order/invoice
	$morder                              = new MemberOrder();
	$morder->user_id                     = $old_order->user_id;
	$morder->membership_id               = $old_order->membership_id;
	$morder->InitialPayment              = $transaction->amount;    //not the initial payment, but the order class is expecting this
	$morder->PaymentAmount               = $transaction->amount;
	$morder->payment_transaction_id      = $transaction->id;
	$morder->subscription_transaction_id = $webhookNotification->subscription->id;
	
	$morder->gateway             = $old_order->gateway;
	$morder->gateway_environment = $old_order->gateway_environment;
	
	$morder->FirstName = $transaction->billing_details->first_name;
	$morder->LastName  = $transaction->billing_details->last_name;
	$morder->Email     = $wpdb->get_var( "SELECT user_email FROM $wpdb->users WHERE ID = '" . $old_order->user_id . "' LIMIT 1" );
	$morder->Address1  = $transaction->billing_details->street_address;
	$morder->City      = $transaction->billing_details->locality;
	$morder->State     = $transaction->billing_details->region;
	//$morder->CountryCode = $old_order->billing->city;
	$morder->Zip         = $transaction->billing_details->postal_code;
	$morder->PhoneNumber = $old_order->billing->phone;
	
	$morder->billing->name    = trim( $transaction->billing_details->first_name . " " . $transaction->billing_details->last_name );
	$morder->billing->street  = $transaction->billing_details->street_address;
	$morder->billing->city    = $transaction->billing_details->locality;
	$morder->billing->state   = $transaction->billing_details->region;
	$morder->billing->zip     = $transaction->billing_details->postal_code;
	$morder->billing->country = $transaction->billing_details->country_code_alpha2;
	$morder->billing->phone   = $old_order->billing->phone;
	
	//get CC info that is on file
	$morder->cardtype              = get_user_meta( $user_id, "pmpro_CardType", true );
	$morder->accountnumber         = hideCardNumber( get_user_meta( $user_id, "pmpro_AccountNumber", true ), false );
	$morder->expirationmonth       = get_user_meta( $user_id, "pmpro_ExpirationMonth", true );
	$morder->expirationyear        = get_user_meta( $user_id, "pmpro_ExpirationYear", true );
	$morder->ExpirationDate        = $morder->expirationmonth . $morder->expirationyear;
	$morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;
	
	//save
	$morder->status = "success";
	$morder->saveOrder();
	$morder->getMemberOrderByID( $morder->id );
	
	//email the user their invoice
	$pmproemail = new PMProEmail();
	$pmproemail->sendInvoiceEmail( $user, $morder );
	
	do_action( 'pmpro_subscription_payment_completed', $morder );
	
	$logstr[] = "Triggered pmpro_subscription_payment_completed actions and returned";
	
	/**
	 * @since 1.9.5 - Didn't terminate & save debug loggins for Webhook
	 */
	pmpro_braintreeWebhookExit();
}

/*
	Note here: These next three checks all work the same way and send the same "billing failed" email, but kick off different actions based on the kind.
*/

//subscription charged unsuccessfully
if ( $webhookNotification->kind === Braintree_WebhookNotification::SUBSCRIPTION_CHARGED_UNSUCCESSFULLY ) {
	$logstr[] = "The Braintree gateway let us know there's a problem with the payment";
	
	//need a subscription id
	if ( empty( $webhookNotification->subscription->id ) ) {
		$logstr[] = "No subscription ID.";
		pmpro_braintreeWebhookExit();
	}
	
	//figure out which order to attach to
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $webhookNotification->subscription->id );
	
	if ( empty( $old_order ) ) {
		$logstr[] = "Couldn't find old order for failed payment with subscription id={$webhookNotification->subscription->id}";
		pmpro_braintreeWebhookExit();
	}
	
	$user_id                = $old_order->user_id;
	$user                   = get_userdata( $user_id );
	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );
	
	//generate billing failure email
	do_action( "pmpro_subscription_payment_failed", $old_order );
	
	$transaction = $webhookNotification->transactions[0];
	
	//prep this order for the failure emails
	$morder                   = new MemberOrder();
	$morder->user_id          = $user_id;
	$morder->billing->name    = trim( $transaction->billing_details->first_name . " " . $transaction->billing_details->first_name );
	$morder->billing->street  = $transaction->billing_details->street_address;
	$morder->billing->city    = $transaction->billing_details->locality;
	$morder->billing->state   = $transaction->billing_details->region;
	$morder->billing->zip     = $transaction->billing_details->postal_code;
	$morder->billing->country = $transaction->billing_details->country_code_alpha2;
	$morder->billing->phone   = $old_order->billing->phone;
	
	//get CC info that is on file
	$morder->cardtype        = get_user_meta( $user_id, "pmpro_CardType", true );
	$morder->accountnumber   = hideCardNumber( get_user_meta( $user_id, "pmpro_AccountNumber", true ), false );
	$morder->expirationmonth = get_user_meta( $user_id, "pmpro_ExpirationMonth", true );
	$morder->expirationyear  = get_user_meta( $user_id, "pmpro_ExpirationYear", true );
	
	// Email the user and ask them to update their credit card information
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureEmail( $user, $morder );
	
	// Email admin so they are aware of the failure
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureAdminEmail( get_bloginfo( "admin_email" ), $morder );
	
	$logstr[] = "Sent email to the member and site admin. Thanks.";
	pmpro_braintreeWebhookExit();
}

//subscription went past due
if ( $webhookNotification->kind === Braintree_WebhookNotification::SUBSCRIPTION_WENT_PAST_DUE ) {
	
	$logstr[] = "The Braintree gateway informed us the subscription payment is past due";
	
	//need a subscription id
	if ( empty( $webhookNotification->subscription->id ) ) {
		$logstr[] = "No subscription ID.";
		pmpro_braintreeWebhookExit();
	}
	
	//figure out which order to attach to
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $webhookNotification->subscription->id );
	
	if ( empty( $old_order ) ) {
		$logstr[] = "Couldn't find old order for failed payment with subscription id=" . $webhookNotification->subscription->id;
		pmpro_braintreeWebhookExit();
	}
	
	$user_id                = $old_order->user_id;
	$user                   = get_userdata( $user_id );
	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );
	
	//generate billing failure email
	do_action( "pmpro_subscription_payment_failed", $old_order );
	do_action( "pmpro_subscription_payment_went_past_due", $old_order );
	
	$transaction = $webhookNotification->transactions[0];
	
	//prep this order for the failure emails
	$morder                   = new MemberOrder();
	$morder->user_id          = $user_id;
	$morder->billing->name    = trim( $transaction->billing_details->first_name . " " . $transaction->billing_details->first_name );
	$morder->billing->street  = $transaction->billing_details->street_address;
	$morder->billing->city    = $transaction->billing_details->locality;
	$morder->billing->state   = $transaction->billing_details->region;
	$morder->billing->zip     = $transaction->billing_details->postal_code;
	$morder->billing->country = $transaction->billing_details->country_code_alpha2;
	$morder->billing->phone   = $old_order->billing->phone;
	
	//get CC info that is on file
	$morder->cardtype        = get_user_meta( $user_id, "pmpro_CardType", true );
	$morder->accountnumber   = hideCardNumber( get_user_meta( $user_id, "pmpro_AccountNumber", true ), false );
	$morder->expirationmonth = get_user_meta( $user_id, "pmpro_ExpirationMonth", true );
	$morder->expirationyear  = get_user_meta( $user_id, "pmpro_ExpirationYear", true );
	
	// Email the user and ask them to update their credit card information
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureEmail( $user, $morder );
	
	// Email admin so they are aware of the failure
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureAdminEmail( get_bloginfo( "admin_email" ), $morder );
	
	$logstr[] = "Sent email to the member and site admin. Thanks.";
	pmpro_braintreeWebhookExit();
}

//subscription expired
if ( $webhookNotification->kind === Braintree_WebhookNotification::SUBSCRIPTION_EXPIRED ) {
	$logstr[] = "The Braintree gateway informed us the recurring payment plan has completed its required number of payments";
	
	//need a subscription id
	if ( empty( $webhookNotification->subscription->id ) ) {
		$logstr[] = "No subscription ID.";
		pmpro_braintreeWebhookExit();
	}
	
	//figure out which order to attach to
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $webhookNotification->subscription->id );
	
	if ( empty( $old_order ) ) {
		$logstr[] = "Couldn't find old order for failed payment with subscription id=" . $webhookNotification->subscription->id;
		pmpro_braintreeWebhookExit();
	}
	
	$user_id                = $old_order->user_id;
	$user                   = get_userdata( $user_id );
	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );
	
	//generate billing failure email
	do_action( "pmpro_subscription_expired", $old_order );
	
	$transaction = $webhookNotification->transactions[0];
	
	//prep this order for the failure emails
	$morder                   = new MemberOrder();
	$morder->user_id          = $user_id;
	$morder->billing->name    = trim( $transaction->billing_details->first_name . " " . $transaction->billing_details->first_name );
	$morder->billing->street  = $transaction->billing_details->street_address;
	$morder->billing->city    = $transaction->billing_details->locality;
	$morder->billing->state   = $transaction->billing_details->region;
	$morder->billing->zip     = $transaction->billing_details->postal_code;
	$morder->billing->country = $transaction->billing_details->country_code_alpha2;
	$morder->billing->phone   = $old_order->billing->phone;
	
	//get CC info that is on file
	$morder->cardtype        = get_user_meta( $user_id, "pmpro_CardType", true );
	$morder->accountnumber   = hideCardNumber( get_user_meta( $user_id, "pmpro_AccountNumber", true ), false );
	$morder->expirationmonth = get_user_meta( $user_id, "pmpro_ExpirationMonth", true );
	$morder->expirationyear  = get_user_meta( $user_id, "pmpro_ExpirationYear", true );
	
	// Email the user and ask them to update their credit card information
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureEmail( $user, $morder );
	
	// Email admin so they are aware of the failure
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureAdminEmail( get_bloginfo( "admin_email" ), $morder );
	
	$logstr[] = "Sent email to the member and site admin. Thanks.";
	pmpro_braintreeWebhookExit();
}

//subscription cancelled (they used one l canceled)
if ( Braintree_WebhookNotification::SUBSCRIPTION_CANCELED === $webhookNotification->kind ) {
	
	$logstr[] = "The Braintree gateway cancelled the subscription plan";
	
	//need a subscription id
	if ( empty( $webhookNotification->subscription->id ) ) {
		$logstr[] = "No subscription ID.";
		pmpro_braintreeWebhookExit();
	}
	
	//figure out which order to attach to
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $webhookNotification->subscription->id );
	
	if ( empty( $old_order ) ) {
		$logstr[] = "Couldn't find old order for failed payment with subscription id={$webhookNotification->subscription->id}";
		pmpro_braintreeWebhookExit();
	}
	
	$user_id                = $old_order->user_id;
	$user                   = get_userdata( $user_id );
	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );
	
	/**
	 * @since 1.9.5 - BUG FIX: Erroneously triggering warning email
	 *                Happens when a user cancels their membership (and Braintree) sends a webhook notifying us
	 *                of the fact that the user cancelled their subscription plan (SUBSCRIPTION_CANCELED event).
	 */
	if ( empty( $user->membership_level ) ) {
		
		$logstr[] = "Membership for user (ID: {$user_id}) is cancelled already. Probably a duplicate webhook notification. Exiting!";
		pmpro_braintreeWebhookExit();
	}
	
	// Trigger subscription cancelled action
	do_action( "pmpro_subscription_cancelled", $old_order );
	
	$transaction = $webhookNotification->transactions[0];
	
	//prep this order for the failure emails
	$morder                   = new MemberOrder();
	$morder->user_id          = $user_id;
	$morder->billing->name    = trim( $transaction->billing_details->first_name . " " . $transaction->billing_details->first_name );
	$morder->billing->street  = $transaction->billing_details->street_address;
	$morder->billing->city    = $transaction->billing_details->locality;
	$morder->billing->state   = $transaction->billing_details->region;
	$morder->billing->zip     = $transaction->billing_details->postal_code;
	$morder->billing->country = $transaction->billing_details->country_code_alpha2;
	$morder->billing->phone   = $old_order->billing->phone;
	
	//get CC info that is on file
	$morder->cardtype        = get_user_meta( $user_id, "pmpro_CardType", true );
	$morder->accountnumber   = hideCardNumber( get_user_meta( $user_id, "pmpro_AccountNumber", true ), false );
	$morder->expirationmonth = get_user_meta( $user_id, "pmpro_ExpirationMonth", true );
	$morder->expirationyear  = get_user_meta( $user_id, "pmpro_ExpirationYear", true );
	
	// Email the user and let them know the membership was cancelled
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureEmail( $user, $morder );
	
	// Email admin so they are aware of the failure
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureAdminEmail( get_bloginfo("admin_email"), $morder );
	
	// Send email
	$logstr[] = "Sent billing failure email to the member and site admin. Thanks.";
	pmpro_braintreeWebhookExit();
}

/**
 * @since 1.9.5 - BUG FIX: Didn't terminate & save debug log for webhook event
 */
pmpro_braintreeWebhookExit();

/**
 * Exit the Webhook handler, and save the debug log (if needed)
 */
function pmpro_braintreeWebhookExit() {
	
	global $logstr;
	
	//Log the info (if there is any)
	if ( ! empty( $logstr ) ) {
		
		$logstr[] = "\n-------------\n";
		
		$debuglog = implode( "\n", $logstr );
		
		//log in file or email?
		if ( defined( 'PMPRO_BRAINTREE_WEBHOOK_DEBUG' ) && PMPRO_BRAINTREE_WEBHOOK_DEBUG === "log" ) {
			//file
			$loghandle = fopen( dirname( __FILE__ ) . "/../logs/braintree-webhook.txt", "a+" );
			fwrite( $loghandle, $debuglog );
			fclose( $loghandle );
		} else if ( defined( 'PMPRO_BRAINTREE_WEBHOOK_DEBUG' ) ) {
			/**
			 * @since 1.9.5 - BUG FIX: We specifically care about errors, not strings at position 0
			 */
			//email
			if ( false !== strpos( PMPRO_BRAINTREE_WEBHOOK_DEBUG, "@" ) ) {
				$log_email = PMPRO_BRAINTREE_WEBHOOK_DEBUG;    //constant defines a specific email address
			} else {
				$log_email = get_option( "admin_email" );
			}
			
			wp_mail( $log_email, get_option( "blogname" ) . " Braintree Webhook Log", nl2br( $debuglog ) );
		}
	}
	
	exit;
}
