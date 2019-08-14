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

class MageBase_DpsPaymentExpress_Model_System_Logos
{
    public function toOptionArray()
    {
        return array(
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_VISA,
                'label' => Mage::helper('magebasedps')->__('Visa')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_VISAVERIFIED,
                'label' => Mage::helper('magebasedps')->__('Verified by Visa')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_MASTERCARD,
                'label' => Mage::helper('magebasedps')->__('MasterCard')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_MASTERCARDSECURE,
                'label' => Mage::helper('magebasedps')->__('MasterCard SecureCode')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_AMEX,
                'label' => Mage::helper('magebasedps')->__('American Express')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_JCB,
                'label' => Mage::helper('magebasedps')->__('JCB')
            ),
            array(
                'value' => MageBase_DpsPaymentExpress_Model_Method_Common::LOGOFILE_DINERS,
                'label' => Mage::helper('magebasedps')->__('Diners')
            ),
        );
    }
}