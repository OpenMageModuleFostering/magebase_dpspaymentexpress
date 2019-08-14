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

class MageBase_DpsPaymentExpress_PxpayController extends Mage_Core_Controller_Front_Action
{

    public function successAction()
    {
        if ($this->_validateUserId($this->getRequest()->getParam('userid'))) {

            /* successAction is called twice
             * once by DPS's FailProofNotification
             * and also by the customer when returning
             * DPS has no session
             * only the DPS response is processed to prevent double handling
             */
            $session = Mage::getSingleton('checkout/session');
            if ($session->getLastOrderId()) {
                Mage::log(
                    $session->getLastRealOrderId().' MageBaseDps User returned to Success Url',
                    null,
                    MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
                );
                $result = $this->getRequest()->getParam('result');
                $resultXml = $this->_getRealResponse($result);
                if ($resultXml) {
                    if ((int)$resultXml->Success == 1) {
                        $session->setLastQuoteId((int)$resultXml->TxnData2)
                            ->setLastOrderId(
                                Mage::getModel('sales/order')->loadByIncrementId((string)$resultXml->MerchantReference)
                                ->getId()
                            )
                            ->setLastRealOrderId((string)$resultXml->MerchantReference)
                            ->setLastSuccessQuoteId((int)$resultXml->TxnData2);
                        $this->_redirect('checkout/onepage/success', array('_secure'=>true));
                    } else {
                        $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
                    }
                } else {
                    $session->setLastQuoteId((int)$resultXml->TxnData2)
                        ->setLastOrderId(
                            Mage::getModel('sales/order')->loadByIncrementId((string)$resultXml->MerchantReference)
                            ->getId()
                        )
                        ->setLastRealOrderId((string)$resultXml->MerchantReference);
                    $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
                }
            } else {
                try {
                    $result = $this->getRequest()->getParam('result');
                    Mage::log(
                        'DPS result from url: '.$result,
                        null,
                        MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
                    );
                    if (empty ($result)) {
                        throw new Exception(
                            "Can't retrieve result from GET variable result. Check your server configuration."
                        );
                    }
                    $resultXml = $this->_processSuccessResponse($this->getRequest()->getParam('result'));
                }catch (Exception $e){
                    $resultXml = false;
                    Mage::logException($e);
                    Mage::log(
                        'MageBaseDps failed with exception - see exception.log',
                        null,
                        MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
                    );
                    $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
                }
                $this->_redirect('checkout/onepage/success', array('_secure'=>true));
            } 
        } else {
            Mage::log(
                'MageBaseDps successAction, but wrong PxPayUserId',
                null,
                MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
            );
            $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
        }
    }

    public function failAction()
    {
        Mage::log(
            'MageBaseDps failAction',
            null,
            MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
        );
        if (!$this->_validateUserId($this->getRequest()->getParam('userid'))) {
            Mage::log(
                'MageBaseDps failAction - wrong PxPayUserId',
                null,
                MageBase_DpsPaymentExpress_Model_Method_Pxpay::DPS_LOG_FILENAME
            );
        }
        $resultXml = $this->_processFailResponse($this->getRequest()->getParam('result'));
        if ($session = Mage::getSingleton('checkout/session')) {
            $session->setLastQuoteId((int)$resultXml->TxnData2)
                    ->setLastRealOrderId((string)$resultXml->MerchantReference);
            $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
        }
    }

    private function _getRealResponse($result)
    {
        return Mage::getSingleton('magebasedps/method_pxpay')->getRealResponse($result);
    }

    private function _processSuccessResponse($result)
    {
        return Mage::getSingleton('magebasedps/method_pxpay')->processSuccessResponse($result);
    }

    private function _processFailResponse($result)
    {
        return Mage::getSingleton('magebasedps/method_pxpay')->processFailResponse($result);
    }

    private function _validateUserId($userId)
    {
        return Mage::getSingleton('magebasedps/method_pxpay')->validateUserId($userId);
    }

}