<?php
/**
 * NotifyController.php
 */

// Include the Netcash Pay Now common file
define('PN_DEBUG', (Mage::getStoreConfig('payment/paynow/debugging') ? true : false));
include_once(dirname(__FILE__) . '/../paynow_common.inc');


/**
 * PayNow_PayNow_NotifyController
 */
class PayNow_PayNow_NotifyController extends Mage_Core_Controller_Front_Action
{
    /**
     * indexAction
     *
     * Instantiate IPN model and pass IPN request to it
     */
    public function indexAction()
    {

        if( isset($_POST) && !empty($_POST) ) {

            // This is the notification coming in!
            // Act as an IPN request and forward request to Credit Card method.
            // Logic is exactly the same

            $this->_pn_do_transaction();
            die();

        }

        die( PN_ERR_BAD_ACCESS );
    }

	/**
	 * Check if this is a 'callback' stating the transaction is pending.
	 */
	private function pn_is_pending() {
		return isset($_POST['TransactionAccepted'])
			&& $_POST['TransactionAccepted'] == 'false'
			&& stristr($_POST['Reason'], 'pending');
	}

	/**
	 * Check if this is a 'offline' payment like EFT or retail
	 */
	private function pn_is_offline() {

		/*
		Returns 2 for EFT
		Returns 3 for Retail
		*/
		$offline_methods = [2, 3];

		$method = isset($_POST['Method']) ? (int) $_POST['Method'] : null;
		pnlog('Checking if offline: ' . print_r(array(
			"isset" => (bool) isset($_POST['Method']),
			"Method" => (int) $_POST['Method'],
		), true));

		return $method && in_array($method, $offline_methods);
	}

    private function _pn_do_transaction() {

        // Variable Initialization
        $pnError = false;
        $pnErrMsg = '';
        $pnData = array();

        pnlog('Netcash Pay Now IPN call received');
        pnlog('Server = ' . Mage::getStoreConfig('payment/paynow/server'));

        // Get data posted back by Pay Now
        if (!$pnError) {
            pnlog('Get data posted back by Pay Now');

            // Posted variables from ITN
            $pnData = pnGetData();

            pnlog('Netcash Pay Now Data: ' . print_r($pnData, true));

            if ($pnData === false) {
                $pnError = true;
                $pnErrMsg = PN_ERR_BAD_ACCESS;
            }
        }

        if( isset($_POST) && !empty($_POST) && !$this->pn_is_pending() && !$this->pn_is_offline() ) {


	        // Notify Pay Now that information has been received
	        // Fails with 'headers already sent' on some servers
	        // See http://stackoverflow.com/questions/8028957/how-to-fix-headers-already-sent-error-in-php
	        //if (!$pnError) {
	        //header('HTTP/1.0 200 OK');
	        //flush();
	        //}

	        if ($pnData['TransactionAccepted'] == 'false') {
	            $pnError = true;
	            $pnErrMsg = PN_MSG_FAILED;
	        }

	        // Get internal order and verify it hasn't already been processed
	        if (!$pnError) {
	            pnlog("Check if the order has not already been processed");

	            // Load order
	            $trnsOrdId = $pnData['Reference'];
	            $order = Mage::getModel('sales/order');
	            $order->loadByIncrementId($trnsOrdId);
	            $this->_storeID = $order->getStoreId();

	            // Check order is in "pending payment" state
	            pnlog("The current order status is " . $order->getStatus() . " vs " . Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
	            if ($order->getStatus() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
	                $pnError = true;
	                $pnErrMsg = PN_ERR_ORDER_PROCESSED;
	                pnlog("Order already processed. Redirecting to success");

	                $url = Mage::getUrl( 'paynow/redirect/success', array( '_secure' => true ) );
	                header("Location: {$url}");
	                die();
	            }
	        }

	        // Check status and update order
	        if (!$pnError) {
	            pnlog('Check status and update order');

	            // Successful
	            if ($pnData['TransactionAccepted'] == "true") {
	                pnlog('Order complete');

	                // Currently order gets set to "Pending" even if invoice is paid.
	                // Looking at http://stackoverflow.com/a/18711371 (http://stackoverflow.com/questions/18711176/how-to-set-order-status-as-complete-in-magento)
	                //  it is suggested that this is normal behaviour and an order is only "complete" after shipment
	                // 2 Options.
	                //  a. Leave as is. (Recommended)
	                //  b. Force order complete status (http://stackoverflow.com/a/18711313)

	                // Update order additional payment information
	                $payment = $order->getPayment();
	                $payment->setAdditionalInformation("TransactionAccepted", $pnData['TransactionAccepted']);
	                $payment->setAdditionalInformation("Reference", $pnData['Reference']);
	                $payment->setAdditionalInformation("RequestTrace", $pnData['RequestTrace']);
	                //$payment->setAdditionalInformation( "email_address", $pnData['email_address'] );
	                $payment->setAdditionalInformation("Amount", $pnData['Amount']);
	                $payment->save();
	                // Save invoice
	                $this->saveInvoice($order);
	            }
	        }

	        // If an error occurred show the reason and present a hyperlink back to the store
	        if ($pnError) {
	        	$_msg = 'Transaction failed, reason: ' . $pnErrMsg;

	        	// $payment = $order->getPayment();
	        	// if( $payment ) {
	        	// 	$payment->setAdditionalInformation($_msg);
	        	// 	$payment->save();
	        	// }

	            pnlog($_msg);
	            $url = Mage::getUrl('paynow/redirect/cancel', array('_secure' => true));
	            header("Location: $url");
	            die();
	        } else { // Redirect to the success page
	            $url = Mage::getUrl( 'paynow/redirect/success', array( '_secure' => true ) );
	            // $this->_redirect('paynow/redirect/success');
	            header("Location: {$url}");
	            die();
	        }
        } else {
        	$url_for_redirect = Mage::getUrl('customer/account');

        	// Probably calling the "redirect" URL
        	pnlog('Probably calling redirect url: ' . $url_for_redirect);
        	// $this->_redirect($url_for_redirect);
        	header("Location: $url_for_redirect");
        	die();

        }
    }

    /**
     * saveInvoice
     */
    protected function saveInvoice(Mage_Sales_Model_Order $order)
    {
        pnlog('Saving invoice');

        // Check for mail msg
        $invoice = $order->prepareInvoice();

        $invoice->register()->capture();
        Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
        //$invoice->sendEmail();

        $message = Mage::helper('paynow')->__('Notified customer about invoice #%s.', $invoice->getIncrementId());
        $comment = $order->sendNewOrderEmail()->addStatusHistoryComment($message)
            ->setIsCustomerNotified(true)
            ->save();
    }

}
