<?php
/**
 * MageBase DPS Payment Express
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    MageBase
 * @package     MageBase_DpsPaymentExpress
 * @author      Kristof Ringleff
 * @copyright   Copyright (c) 2010 MageBase (http://www.magebase.com)
 * @copyright   Copyright (c) 2010 Fooman Ltd (http://www.fooman.co.nz)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class MageBase_DpsPaymentExpress_Model_Method_Pxpay extends Mage_Payment_Model_Method_Abstract
{

    const URL_PXPAY = 'https://sec2.paymentexpress.com/pxpay/pxaccess.aspx';
    const URL_PXPAY_SUCCESS = 'magebasedps/pxpay/success';
    const URL_PXPAY_FAIL = 'magebasedps/pxpay/fail';

    const DPS_LOG_FILENAME = 'magebase_dps_pxpay.log';

    protected $_code  = 'magebasedpspxpay';
    protected $_formBlockType = 'magebasedps/pxpay_form';
    protected $_infoBlockType = 'magebasedps/pxpay_info';

    /**
     * Payment Method features
     * @var bool
     */
    protected $_isGateway               = false;
    protected $_canAuthorize            = false;
    protected $_canCapture              = false;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;
    protected $_isInitializeNeeded      = true;

    protected $_order;

    /**
     * is PxPay enabled
     *
     * @return string
     */
    private function _isActive()
    {
        return Mage::getStoreConfigFlag('payment/'.$this->_code.'/active');
    }

    /**
     * retrieve PxPayUserId from database
     *
     * @return string
     */
    private function _getPxPayUserId()
    {
        return Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/'.$this->_code.'/pxpayuserid'));
    }

    /**
     * retrieve PxPayKey from database
     *
     * @return string
     */
    private function _getPxPayKey()
    {
        return Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/'.$this->_code.'/pxpaykey'));
    }

    /**
     * retrieve payment action from database
     * Auth or Purchase
     *
     * @return int
     */
    private function _getPxPayPaymentAction()
    {
        switch(Mage::getStoreConfig('payment/'.$this->_code.'/payment_action')) {
            case Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE:
                return MageBase_DpsPaymentExpress_Model_Method_Common::ACTION_AUTHORIZE;
                break;
            case Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE:
                return MageBase_DpsPaymentExpress_Model_Method_Common::ACTION_PURCHASE;
                break;
        }
    }

    /**
     * retrieve order matching MerchantReference
     *
     * @param SimpleXMLElement $resultXml
     * @return Mage_Sales_Model_Order
     */
    private function _getOrder($resultXml)
    {
        if (!$this->_order) {
            $this->_order = Mage::getModel('sales/order')->loadByIncrementId($resultXml->MerchantReference);
            if (!$this->_order->getId()) {
                $this->_order = false;
            }
        }
        return $this->_order;
    }

    /**
     * check if returned userId matches value from database
     *
     * @param $userId
     * @return bool
     */
    public function validateUserId($userId)
    {
        return $this->_getPxPayUserId() == $userId;
    }

    /**
     * Return redirect url to DPS after order has been placed
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        if (!$this->_isActive()) {
            throw new Exception("Payment method is not available.");
            return false;
        }
        $url = $this->_getPxPayUrl();
        if (!$url) {
            throw new Exception("Payment method is not available.");
            return false;
        }
        return $url;
    }

    /**
     * Instantiate state and set it to state object
     * @param string $paymentAction
     * @param Varien_Object
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus(Mage::getStoreConfig('payment/'.$this->_code.'/unpaid_order_status'));
        $stateObject->setIsNotified(false);
    }

    /**
     * check if current currency code is allowed for this payment method
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return Mage::helper('magebasedps')->canUseCurrency($currencyCode);
    }

    /**
     * process the DPS result string (GET) when succesful
     *
     * @param string $result [encrypted]
     * @return SimpleXMLElement
     */
    public function processSuccessResponse($result)
    {
        if (!$this->_isActive()) {
            throw new Exception("Payment method is not available.");
        } else {
            $responseXml = $this->getRealResponse($result);
            switch ($this->_validateResponse($responseXml)){
                case MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_INVOICE:
                    $this->_registerPayment($responseXml);
                    Mage::getModel('sales/quote')->load($responseXml->TxnData2)->setIsActive(false)->save();
                    break;
                case MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_DONT_INVOICE:
                    $this->_acknowledgeOrder($responseXml);
                    Mage::getModel('sales/quote')->load($responseXml->TxnData2)->setIsActive(false)->save();
                    break;
                case MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_ALREADY_INVOICED:
                    Mage::getModel('sales/quote')->load($responseXml->TxnData2)->setIsActive(false)->save();
                    break;
                default:
                    $this->_cancelOrderAfterFailure($responseXml);
                    return false;
                    break;
            }
        }
    }

    /**
     * process the DPS result string (GET) after failure
     *
     * @param string $result [encrypted]
     * @return SimpleXMLElement
     */
    public function processFailResponse($result)
    {
        if (!$this->_isActive()) {
            throw new Exception("Payment method is not available.");
        }
        $responseXml = $this->getRealResponse($result);
        $this->_cancelOrderAfterFailure($responseXml);
        return $responseXml;
    }

    /**
     * retrieve PxPayUrl to redirect customer to (generated by DPS)
     *
     * @return string
     */
    protected function _getPxPayUrl()
    {
        try{
            $txnId = substr(uniqid(rand()), 0, 16);
            $client = new Zend_Http_Client();
            $client->setUri(self::URL_PXPAY);
            $client->setConfig(
                array(
                    'maxredirects'=>0,
                    'timeout'=>30,
                    )
            );
            $quote = Mage::getSingleton('checkout/session')->getQuote();

            $xml = new SimpleXMLElement('<GenerateRequest></GenerateRequest>');
            $xml->addChild('PxPayUserId', htmlentities($this->_getPxPayUserId()));
            $xml->addChild('PxPayKey', htmlentities($this->_getPxPayKey()));
            $xml->addChild('AmountInput', trim(htmlentities(sprintf("%9.2f", $quote->getBaseGrandTotal()))));
            $xml->addChild('BillingId', '');
            $xml->addChild('CurrencyInput', htmlentities($quote->getBaseCurrencyCode()));
            $xml->addChild('EmailAddress', htmlentities($quote->getCustomerEmail()));
            $xml->addChild('EnableAddBillCard', '0');
            $xml->addChild('MerchantReference', htmlentities($quote->getReservedOrderId()));
            $xml->addChild('TxnData1', $quote->getStore()->getName());
            $xml->addChild('TxnData2', $quote->getId());
            $xml->addChild('TxnData3', '');
            $xml->addChild('TxnType', htmlentities($this->_getPxPayPaymentAction()));
            $xml->addChild('TxnId', $txnId);
            $xml->addChild('BillingId', '');
            $xml->addChild('UrlFail', htmlentities(Mage::getUrl(self::URL_PXPAY_FAIL)));
            $xml->addChild('UrlSuccess', htmlentities(Mage::getUrl(self::URL_PXPAY_SUCCESS)));
            $xml->addChild('Opt', '');

            $client->setParameterPost('xml', $xml->asXML());

            if ($this->debugToDb()) {
                $debugEntry = Mage::getModel('magebasedps/debug')
                    ->setRequestBody($xml->asXML())
                    ->save();
            }
            $response = $client->request('POST');

            $responseXml = simplexml_load_string($response->getBody());
            if ($this->debugToDb()) {
                $debugEntry->setResponseBody($response->getBody())
                    ->save();
            }
            if ($responseXml['valid'] == 1) {
                return strval($responseXml->URI);
            }
            return false;
        }catch (Exception $e){
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Query DPS Server to obtain real ProcessResponse from encrypted response
     *
     * @param string $result [encrypted]
     * @return SimpleXMLElement
     */
    public function getRealResponse($result)
    {
        try {
            $client = new Zend_Http_Client();
            $client->setUri(self::URL_PXPAY);
            $client->setConfig(
                array(
                    'maxredirects'=>0,
                    'timeout'=>30,
                    )
            );
            $xml = new SimpleXMLElement('<ProcessResponse></ProcessResponse>');
            $xml->addChild('PxPayUserId', htmlentities($this->_getPxPayUserId()));
            $xml->addChild('PxPayKey', htmlentities($this->_getPxPayKey()));
            $xml->addChild('Response', $result);

            $client->setParameterPost('xml', $xml->asXML());

            if ($this->debugToDb()) {
                $debugEntry = Mage::getModel('magebasedps/debug')
                    ->setRequestBody($xml->asXML())
                    ->save();
            }
            
            $response = $client->request('POST');

            $responseXml = simplexml_load_string($response->getBody());
            if ($responseXml && $this->debugToDb()) {
                $debugEntry->setResponseBody($responseXml->asXML())
                        ->save();
            }
            if ($responseXml['valid'] == 1) {
                return $responseXml;
            } else {
                throw new Exception("DPS did not return a valid response.");
            }
        }catch (Exception $e) {
            Mage::logException($e);
            Mage::log("Error in DPS obtaining ProcessResponse ".$e->getMessage(), null, self::DPS_LOG_FILENAME);
            return false;
        }
    }

    /**
     * validate returned response and determine if an invoice should be created
     * checks: success = 1
     *         amount settled = base grand total
     *         currency settled = base currency
     *         order exists
     *
     * @param SimpleXMLElement $resultXml
     * @return int
     */
    protected function _validateResponse($resultXml)
    {
        try {
            if ((int)$resultXml->Success != 1) {
                Mage::log("Error in DPS Response Validation: Unsuccessful", null, self::DPS_LOG_FILENAME);
                return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
            }
            $order = $this->_getOrder($resultXml);
            if (!$order->getId()) {
                Mage::log("Error in DPS Response Validation: No Order", null, self::DPS_LOG_FILENAME);
                return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
            }
            if ((float)$resultXml->AmountSettlement != $order->getBaseGrandTotal()) {
                Mage::log(
                    $order->getIncrementId(). " Error in DPS Response Validation: Mismatched totals",
                    null,
                    self::DPS_LOG_FILENAME
                );
                return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
            }
            if((float)$resultXml->CurrencySettlement != $order->getBaseCurrencyCode()) {
                Mage::log(
                    $order->getIncrementId(). " Error in DPS Response Validation: Mismatched currencies",
                    null,
                    self::DPS_LOG_FILENAME
                );
                return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
            }
            switch ($order->getState()) {
                case Mage_Sales_Model_Order::STATE_NEW:
                case Mage_Sales_Model_Order::STATE_PENDING_PAYMENT:
                    if ((string)$resultXml->TxnType == MageBase_DpsPaymentExpress_Model_Method_Common::ACTION_AUTHORIZE) {
                        Mage::log(
                            $order->getIncrementId()." DPS Response: Authorize OK",
                            null,
                            self::DPS_LOG_FILENAME
                        );
                        return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_DONT_INVOICE;
                        break;
                    } elseif((string)$resultXml->TxnType == MageBase_DpsPaymentExpress_Model_Method_Common::ACTION_PURCHASE) {
                        if ($order->canInvoice()) {
                            Mage::log(
                                $order->getIncrementId(). " DPS Response: Purchase OK - create Invoice",
                                null,
                                self::DPS_LOG_FILENAME
                            );
                            return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_INVOICE;
                            break;
                        } else {
                            Mage::log(
                                $order->getIncrementId(). " DPS Response: Purchase OK - don't Invoice",
                                null,
                                self::DPS_LOG_FILENAME
                            );
                            return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_DONT_INVOICE;
                            break;
                        }                        
                    }
                    //not supported
                    Mage::log(
                        $order->getIncrementId(). " Error in DPS Response Validation: Not supported action",
                        null,
                        self::DPS_LOG_FILENAME
                    );
                    return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
                    break;
                default:
                    Mage::log(
                        $order->getIncrementId(). " DPS Validation: Order already processed - current state (".$order->getState().")",
                        null,
                        self::DPS_LOG_FILENAME
                    );
                    return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_OK_ALREADY_INVOICED;
                    break;
            }
        }catch (Exception $e) {
            Mage::log(
                "Error in DPS Response Validation ".$e->getMessage(),
                null,
                self::DPS_LOG_FILENAME
            );
            return MageBase_DpsPaymentExpress_Model_Method_Common::STATUS_ERROR;
        }

    }

    /**
     * save all useful returned info from DPS to additional information field
     * on order payment object
     *
     * @param SimpleXMLElement $responseXml
     * @param Mage_Sales_Model_Order_Payment $payment ?
     * @return void
     */
    public function setAdditionalData($responseXml,$payment)
    {
        $data = array (
                'AuthCode' => (string)$responseXml->AuthCode,
                'CardName' => (string)$responseXml->CardName,
                'CurrencySettlement'=> (string)$responseXml->CurrencySettlement,
                'AmountSettlement'=> (string)$responseXml->AmountSettlement,
                'CardHolderName' => (string)$responseXml->CardHolderName,
                'CardNumber' => (string)$responseXml->CardNumber,
                'CardNumber2' => (string)$responseXml->CardNumber2,
                'TxnType' => (string)$responseXml->TxnType,
                'TxnId' => (string)$responseXml->TxnId,
                'DpsTxnRef' => (string)$responseXml->DpsTxnRef,
                'BillingId' => (string)$responseXml->BillingId,
                'DpsBillingId' => (string)$responseXml->DpsBillingId,
                'TxnMac' => (string)$responseXml->TxnMac,
                'ResponseText' => (string)$responseXml->ResponseText
        );
        $payment->setAdditionalData(serialize($data));
    }

    /**
     * Create invoice, save info to payment and send email to customer
     *
     * @param SimpleXMLElement $responseXml
     */
    protected function _registerPayment($responseXml)
    {
        $order = $this->_getOrder($responseXml);
        $payment = $order->getPayment();
        $this->setAdditionalData($responseXml, $payment);
        $invoice = $order->prepareInvoice();
        $invoice->register();
        $order->addRelatedObject($invoice);
        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, '');
        $order->setStatus(Mage::getStoreConfig('payment/'.$this->_code.'/order_status'));
        $order->sendNewOrderEmail();
        $order->setEmailSent(true);
        $order->save();
    }

    /**
     * Save info to payment and send email to customer
     *
     * @param SimpleXMLElement $responseXml
     */
    protected function _acknowledgeOrder($responseXml)
    {
        $order = $this->_getOrder($responseXml);
        $payment = $order->getPayment();
        $this->setAdditionalData($responseXml, $payment);
        if (!$order->getEmailSent()) {
            $order->sendNewOrderEmail();
            $order->setEmailSent(true);
        }
        $order->save();
    }

    /**
     * cancel the order when we aren't successful
     *
     * @param SimpleXMLElement $responseXml
     */
    protected function _cancelOrderAfterFailure($responseXml=null)
    {
        if ($responseXml) {
            $order = $this->_getOrder($responseXml);
            $this->setAdditionalData($responseXml, $order->getPayment());
        } else {
            $order = Mage::getModel('sales/order')->load(Mage::getSingleton('checkout/session')->getLastOrderId());
        }
        if ($order->getId() && $order->getState() != Mage_Sales_Model_Order::STATE_CANCELED) {
            $order->registerCancellation(Mage::helper('magebasedps')->__('There has been an error processing your payment. Please try later or contact us for help.'), false)
                  ->save();
        }
    }

    /**
     * get flag if we should log debug info to database
     *
     * @return bool
     */
    public function debugToDb()
    {
        return Mage::getStoreConfig('payment/'.$this->_code.'/debug');
    }

}