<?php

require_once Mage::getBaseDir('lib') . '/autoload.php';

use PaynetEasy\PaynetEasyApi\PaymentData\PaymentTransaction as PaynetTransaction;
use PaynetEasy\PaynetEasyApi\PaymentData\Payment            as PaynetPayment;
use PaynetEasy\PaynetEasyApi\PaymentData\Customer           as PaynetCustomer;
use PaynetEasy\PaynetEasyApi\PaymentData\BillingAddress     as PaynetAddress;
use PaynetEasy\PaynetEasyApi\PaymentData\CreditCard         as PaynetCard;
use PaynetEasy\PaynetEasyApi\PaymentData\QueryConfig        as PaynetConfig;

use PaynetEasy\PaynetEasyApi\Utils\Validator;
use PaynetEasy\PaynetEasyApi\Transport\CallbackResponse;

use Mage_Sales_Model_Order                                  as MageOrder;
use Mage_Sales_Model_Order_Payment_Transaction              as MageTransaction;

use PaynetEasy\PaynetEasyApi\PaymentProcessor;

use PaynetEasy\PaynetEasyApi\Exception\ResponseException;

class   PaynetEasy_Paynet_Model_Sale
extends Mage_Payment_Model_Method_Abstract
{
    /**
     * Internal model code
     *
     * @var string
     */
    protected $_code          = 'paynet_sale';

    /**
     * Name for the block with additional payment method information
     *
     * @var string
     */
    protected $_formBlockType = 'paynet/sale';

    /**
     * Can use this payment method in administration panel?
     *
     * @var boolean
     */
    protected $_canUseInternal          = false;

    /**
     * Is this payment method suitable for multi-shipping checkout?
     *
     * @var boolean
     */
    protected $_canUseForMultishipping  = false;

    /**
     * Call PaynetEasy_Paynet_Model_Saleform::initialize() or not
     *
     * @var boolean
     */
    protected $_isInitializeNeeded      = true;

    /**
     * Instantiate state and set it to state object
     *
     * @param       string            $paymentAction
     * @param       Varien_Object     $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setData('payment_action', $paymentAction);
        $stateObject->setState(MageOrder::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
        $stateObject->save();
    }

    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object))
        {
            $data = new Varien_Object($data);
        }

        $info = $this->getInfoInstance();

        $info
            ->setCcOwner($data->getCcOwner())
            ->setCcNumber($data->getCcNumber())
            ->setCcCid($data->getCcCid())
            ->setCcExpMonth($data->getCcExpMonth())
            ->setCcExpYear($data->getCcExpYear())
        ;

        return $this;
    }

    /**
     * Prepare info instance for save
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function prepareSave()
    {
        $info = $this->getInfoInstance();

        $info->setCcNumberEnc($info->encrypt($info->getCcNumber()));
        $info->setCcCidEnc($info->encrypt($info->getCcCid()));

        $info
            ->setCcNumber(null)
            ->setCcCid(null);

        return $this;
    }

    /**
     * Return Order place redirect url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        $result = array();

        preg_match('#(?<=_)[a-z]+$#i', get_called_class(), $result);

        if (empty ($result))
        {
            throw new RuntimeException('Can not get model code from model class');
        }

        $modelCode = strtolower($result[0]);

        return Mage::getUrl("paynet/{$modelCode}/redirect", array('_secure' => true));
    }

    /**
     * Starts order processing.
     * Method executes query to paynet gateway and returns response from gateway.
     * After that payment status must be updated.
     *
     * @param       integer                         $orderId                Order ID
     * @param       string                          $callbackUrl            Url for final payment processing
     *
     * @return      \PaynetEasy\PaynetEasyApi\Transport\Response            Gateway response object
     */
    public function startSale($orderId, $callbackUrl)
    {
        $mageOrder          = $this->getMageOrder($orderId);
        $magePayment        = $mageOrder->getPayment();
        $paynetTransaction  = $this->getPaynetTransaction($mageOrder, $callbackUrl);

        try
        {
            $response = $this
                ->getPaymentProcessor()
                ->executeQuery('sale', $paynetTransaction);
        }
        catch (Exception $e)
        {
            $this->cancelOrder($mageOrder, "Order '{$orderId}' cancelled, error occured");
            throw $e;
        }

        $magePayment->setTransactionId($paynetTransaction->getPayment()->getPaynetId());
        $magePayment
            ->addTransaction(MageTransaction::TYPE_PAYMENT)
            ->setIsClosed(0)
            ->save();

        $mageOrder->save();
        $magePayment->save();

        return $response;
    }

    /**
     * Updates payment status.
     * Method executes query to paynet gateway and returns response from gateway.
     * After this method call must be one of the following actions:
     * - Display html from Response::getHtml() if Response::isShowHtmlNeeded() is true
     * - Update payment status if Response::isStatusUpdateNeeded() is true
     * - Continue order processing otherwise
     *
     * @param       integer                         $orderId                Order ID
     *
     * @return      \PaynetEasy\PaynetEasyApi\Transport\Response            Gateway response object
     */
    public function updateStatus($orderId)
    {
        $mageOrder          = $this->getMageOrder($orderId);
        $paynetTransaction  = $this->getPaynetTransaction($mageOrder);

        try
        {
            $response = $this
                ->getPaymentProcessor()
                ->executeQuery('status', $paynetTransaction);
        }
        catch (Exception $e)
        {
            $this->cancelOrder($mageOrder, "Order '{$orderId}' cancelled, error occured");
            throw $e;
        }

        if ($paynetTransaction->isApproved())
        {
            $this->completeOrder($mageOrder);
        }
        elseif ($paynetTransaction->isFinished())
        {
            $this->cancelOrder($mageOrder, $paynetTransaction->getLastError()->getMessage());
        }
        else
        {
            $mageOrder->save();
        }

        return $response;
    }

    /**
     * Finish order processing.
     * Method checks callback data and returns object with them.
     * After that order processing result can be displayed.
     *
     * @param       integer     $orderId        Order ID
     * @param       array       $callback       Callback data from Paynet
     *
     * @return      CallbackResponse            Callback object
     */
    public function finishSale($orderId, array $callback)
    {
        $mageOrder = $this->getMageOrder($orderId);

        if (!$mageOrder || !$mageOrder->getId())
        {
            throw new ResponseException("Order with id '{$orderId}' not found");
        }

        if ($mageOrder->getState() == MageOrder::STATE_PROCESSING)
        {
            return $mageOrder;
        }

        $paynetTransaction = $this->getPaynetTransaction($mageOrder);
        $paynetTransaction->setStatus(PaynetTransaction::STATUS_PROCESSING);

        try
        {
            $callbackResponse = $this
                ->getPaymentProcessor()
                ->processCustomerReturn(new CallbackResponse($callback), $paynetTransaction);
        }
        catch (Exception $e)
        {
            $this->cancelOrder($mageOrder, "Order '{$orderId}' cancelled, error occured");
            throw $e;
        }

        if ($paynetTransaction->isApproved())
        {
            $this->completeOrder($mageOrder);
        }
        else
        {
            $this->cancelOrder($mageOrder, $paynetTransaction->getLastError()->getMessage());
        }

        return $callbackResponse;
    }

    /**
     * Get service for payment processing
     *
     * @return \PaynetEasy\PaynetEasyApi\PaymentProcessor
     */
    protected function getPaymentProcessor()
    {
        static $processor = null;

        if (empty($processor))
        {
            $processor = new PaymentProcessor;
        }

        return $processor;
    }

    /**
     * Get Magento order object by ID
     *
     * @param       int                         $orderId            Order ID
     *
     * @return      Mage_Sales_Model_Order                          Order object
     */
    protected function getMageOrder($orderId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($orderId);
    }

    /**
     * Get Paynet payment transaction object by Magento order object
     *
     * @param       MageOrder       $mageOrder          Magento order
     * @param       string          $redirectUrl        Url for final payment processing
     *
     * @return      PaynetTransaction                   Paynet payment transaction
     */
    protected function getPaynetTransaction(MageOrder $mageOrder, $redirectUrl = null)
    {
        $mageAddress        = $mageOrder->getBillingAddress();
        $magePayment        = Mage::getModel('sales/quote')
            ->load($mageOrder->getQuoteId())
            ->getPayment()
        ;

        $paynetConfig       = new PaynetConfig;
        $paynetAddress      = new PaynetAddress;
        $paynetTransaction  = new PaynetTransaction;
        $paynetPayment      = new PaynetPayment;
        $paynetCustomer     = new PaynetCustomer;
        $paynetCard         = new PaynetCard;

        $paynetAddress
            ->setCountry($mageAddress->getCountryId())
            ->setCity($mageAddress->getCity())
            ->setFirstLine($mageAddress->getStreet1())
            ->setZipCode($mageAddress->getPostcode())
            ->setPhone($mageAddress->getTelephone())
        ;

        if (Validator::validateByRule($mageAddress->getRegionCode(), Validator::COUNTRY, false))
        {
            $paynetAddress->setState($mageAddress->getRegionCode());
        }

        $paynetCard
            ->setCardPrintedName($magePayment->getCcOwner())
            ->setCreditCardNumber($magePayment->getCcNumber())
            ->setExpireMonth($magePayment->getCcExpMonth())
            ->setExpireYear(substr($magePayment->getCcExpYear(), 2))
            ->setCvv2($magePayment->getCcCid())
        ;

        $paynetCustomer
            ->setEmail($mageAddress->getEmail())
            ->setFirstName($mageOrder->getCustomerFirstname())
            ->setLastName($mageOrder->getCustomerLastname())
            ->setIpAddress($mageOrder->getRemoteIp())
        ;

        $paynetPayment
            ->setClientId($mageOrder->getIncrementId())
            ->setPaynetId($mageOrder->getPayment()->getLastTransId())
            ->setDescription($this->getPaynetPaymentDescription($mageOrder))
            ->setAmount($mageOrder->getBaseGrandTotal())
            ->setCurrency($mageOrder->getOrderCurrencyCode())
            ->setCustomer($paynetCustomer)
            ->setBillingAddress($paynetAddress)
            ->setCreditCard($paynetCard)
        ;

        $paynetConfig
            ->setEndPoint($this->getConfigData('endpoint_id'))
            ->setLogin($this->getConfigData('merchant_login'))
            ->setSigningKey($this->getConfigData('merchant_key'))
            ->setGatewayMode($this->getConfigData('gateway_mode'))
            ->setGatewayUrlSandbox($this->getConfigData('sandbox_api_url'))
            ->setGatewayUrlProduction($this->getConfigData('production_api_url'))
        ;

        if (Validator::validateByRule($redirectUrl, Validator::URL, false))
        {
            $paynetConfig
                ->setRedirectUrl($redirectUrl)
                ->setCallbackUrl($redirectUrl)
            ;
        }

        $paynetTransaction
            ->setPayment($paynetPayment)
            ->setQueryConfig($paynetConfig)
        ;

        return $paynetTransaction;
    }

    /**
     * Get paynet payment description by magento order
     *
     * @param       MageOrder      $order      Magento order
     *
     * @return      string                                  Paynet order description
     */
    protected function getPaynetPaymentDescription(MageOrder $order)
    {
        return  Mage::helper('paynet')->__('shopping_in') . ': ' .
                Mage::app()->getStore($order->getStoreId())->getGroup()->getName() . '; ' .
                Mage::helper('paynet')->__('order_id') . ': ' . $order->getIncrementId();
    }

    /**
     * Cancel Magento order
     *
     * @param   MageOrder       $order          Order
     * @param   string          $message        Cancel message
     */
    protected function cancelOrder(MageOrder $order, $message)
    {
        $order->cancel()
              ->setState(MageOrder::STATE_CANCELED, true, $message, true)
              ->sendOrderUpdateEmail()
              ->setIsNotified(true)
              ->save();
    }

    /**
     * Complete Magento order processing
     *
     * @param   MageOrde        $order          Order
     */
    protected function completeOrder(MageOrder $order)
    {
        $order->setState(MageOrder::STATE_PROCESSING, true)
              ->sendOrderUpdateEmail()
              ->setIsNotified(true)
              ->save();
    }
}
