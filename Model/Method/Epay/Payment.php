<?php
/**
 * Copyright (c) 2017. All rights reserved Bambora Online.
 *
 * This program is free software. You are allowed to use the software but NOT allowed to modify the software.
 * It is also not legal to do any changes to the software and distribute it in your own name / brand.
 *
 * All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
 *
 * @author    Bambora Online
 * @copyright Bambora Online (http://bambora.com)
 * @license   Bambora Online
 *
 */
namespace Bambora\Online\Model\Method\Epay;

use \Bambora\Online\Model\Api\EpayApi;
use \Bambora\Online\Model\Api\EpayApiModels;
use \Magento\Sales\Model\Order\Payment\Transaction;
use \Bambora\Online\Helper\BamboraConstants;

class Payment extends \Bambora\Online\Model\Method\AbstractPayment implements \Bambora\Online\Model\Method\IPayment
{
    const METHOD_CODE = 'bambora_epay';
    const METHOD_REFERENCE = 'bamboraEPayReference';

    protected $_code = self::METHOD_CODE;

    protected $_infoBlockType = 'Bambora\Online\Block\Info\View';

    /**
     * Payment Method feature
     */
    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
    protected $_canRefund                   = true;
    protected $_canRefundInvoicePartial     = true;
    protected $_canVoid                     = true;

    /**
     * @var \Bambora\Online\Model\Api\Epay\Request\Models\Auth
     */
    private $_auth;

    /**
     * Get ePay Auth object
     *
     * @return \Bambora\Online\Model\Api\Epay\Request\Models\Auth
     */
    public function getAuth()
    {
        if (!$this->_auth) {
            $storeId = $this->getStoreManager()->getStore()->getId();
            $this->_auth = $this->_bamboraHelper->generateEpayAuth($storeId);
        }

        return $this->_auth;
    }

    /**
     * Get Bambora Checkout payment window
     *
     * @param \Magento\Sales\Model\Order
     * @return \Bambora\Online\Model\Api\Epay\Request\Models\Url
     */
    public function getPaymentWindow($order)
    {
        if (!isset($order)) {
            return null;
        }
        return $this->createPaymentRequest($order);
    }

    /**
     * Create the ePay payment window Request url
     *
     * @param \Magento\Sales\Model\Order
     * @return \Bambora\Online\Model\Api\Epay\Request\Models\Url
     */
    public function createPaymentRequest($order)
    {
        $currency = $order->getBaseCurrencyCode();
        $minorUnits = $this->_bamboraHelper->getCurrencyMinorUnits($currency);
        $totalAmountMinorUnits = $this->_bamboraHelper->convertPriceToMinorUnits($order->getBaseTotalDue(), $minorUnits);
        $storeId = $order->getStoreId();

        /** @var \Bambora\Online\Model\Api\Epay\Request\Payment */
        $paymentRequest = $this->_bamboraHelper->getEpayApiModel(EpayApiModels::REQUEST_PAYMENT);
        $paymentRequest->encoding = "UTF-8";
        $paymentRequest->cms = $this->_bamboraHelper->getModuleHeaderInfo();
        $paymentRequest->windowState = $this->getConfigData(BamboraConstants::WINDOW_STATE, $storeId);
        $paymentRequest->mobile = $this->getConfigData(BamboraConstants::ENABLE_MOBILE_PAYMENT_WINDOW, $storeId);
        $paymentRequest->merchantNumber = $this->getAuth()->merchantNumber;
        $paymentRequest->windowId = $this->getConfigData(BamboraConstants::PAYMENT_WINDOW_ID, $storeId);
        $paymentRequest->amount = $totalAmountMinorUnits;
        $paymentRequest->currency = $currency;
        $paymentRequest->orderId = $order->getIncrementId();
        $paymentRequest->acceptUrl = $this->_urlBuilder->getUrl('bambora/epay/accept', ['_secure' => $this->_request->isSecure()]);
        $paymentRequest->cancelUrl = $this->_urlBuilder->getUrl('bambora/epay/cancel', ['_secure' => $this->_request->isSecure()]);
        $paymentRequest->callbackUrl = $this->_urlBuilder->getUrl('bambora/epay/callback', ['_secure' => $this->_request->isSecure()]);
        $paymentRequest->instantCapture = $this->getConfigData(BamboraConstants::INSTANT_CAPTURE, $storeId);
        $paymentRequest->language = $this->_bamboraHelper->calcLanguage();
        $paymentRequest->ownReceipt = $this->getConfigData(BamboraConstants::OWN_RECEIPT, $storeId);
        $paymentRequest->timeout = 60;
        $paymentRequest->invoice = $this->createInvoice($order, $minorUnits);
        $paymentRequest->hash = $this->_bamboraHelper->calcEpayMd5Key($order, $paymentRequest);

        /** @var \Bambora\Online\Model\Api\Epay\Action */
        $actionProvider = $this->_bamboraHelper->getEpayApi(EpayApi::API_ACTION);
        $paymentUrl = $actionProvider->getPaymentWindowUrl($paymentRequest);

        return $paymentUrl;
    }

    /**
     * Create Invoice
     *
     * @param \Magento\Sales\Model\Order $order
     * @param int $minorUnits
     * @return string
     */
    public function createInvoice($order, $minorUnits)
    {
        if ($this->getConfigData(BamboraConstants::ENABLE_INVOICE_DATA)) {
            /** @var \Bambora\Online\Model\Api\Epay\Request\Models\Invoice */
            $invoice = $this->_bamboraHelper->getEpayApiModel(EpayApiModels::REQUEST_MODEL_INVOICE);

            $billingAddress = $order->getBillingAddress();
            /** @var \Bambora\Online\Model\Api\Epay\Request\Models\Customer */
            $customer = $this->_bamboraHelper->getEpayApiModel(EpayApiModels::REQUEST_MODEL_CUSTOMER);
            $customer->emailaddress = $billingAddress->getEmail();
            $customer->firstname = $billingAddress->getFirstname();
            $customer->lastname = $billingAddress->getLastname();
            $customer->address = $billingAddress->getStreet()[0];
            $customer->zip = $billingAddress->getPostcode();
            $customer->city = $billingAddress->getCity();
            $customer->country = $billingAddress->getCountryId();

            $invoice->customer = $customer;

            $sa = $order->getShippingAddress();
            /** @var \Bambora\Online\Model\Api\Epay\Request\Models\ShippingAddress */
            $shippingAddress = $this->_bamboraHelper->getEpayApiModel(EpayApiModels::REQUEST_MODEL_SHIPPINGADDRESS);
            $shippingAddress->firstname = $sa->getFirstname();
            $shippingAddress->lastname = $sa->getLastname();
            $shippingAddress->address = $sa->getStreet()[0];
            $shippingAddress->zip = $sa->getPostcode();
            $shippingAddress->city = $sa->getCity();
            $shippingAddress->country = $sa->getCountryId();

            $invoice->shippingaddress = $shippingAddress;
            $invoice->lines = array();

            $items = $order->getAllVisibleItems();
            /** @var \Magento\Sales\Api\Data\OrderItemInterface $item */
            foreach ($items as $item) {
                $description = empty($item->getDescription()) ? $item->getName() : $item->getDescription();
                $invoice->lines[] = array(
                        "id" =>$item->getSku(),
                        "description" => $this->removeSpecialCharacters($description),
                        "quantity" => intval($item->getQtyOrdered()),
                        "price" => $this->_bamboraHelper->convertPriceToMinorUnits($item->getBasePrice() - ($item->getBaseDiscountAmount() / intval($item->getQtyOrdered())), $minorUnits),
                        "vat" => $item->getBaseTaxAmount() > 0 ? floatval($item->getBaseTaxAmount() / ($item->getBasePrice() - ($item->getBaseDiscountAmount() / intval($item->getQtyOrdered())))) : 0
                    );
            }
            // add shipment as line
            $shippingText = __("Shipping");
            $shippingDescription = $order->getShippingDescription();
            $invoice->lines[] = array(
                       "id" => $shippingText,
                       "description" => isset($shippingDescription) ? $shippingDescription : $shippingText,
                       "quantity" => 1,
                       "price" =>$this->_bamboraHelper->convertPriceToMinorUnits(($order->getBaseShippingAmount() - $order->getBaseShippingDiscountAmount()), $minorUnits),
                       "vat" =>$order->getBaseShippingTaxAmount() > 0 ? round(($order->getBaseShippingTaxAmount() / ($order->getBaseShippingInclTax() - $order->getBaseShippingDiscountAmount())) * 100) : 0
                   );

            return json_encode($invoice, JSON_UNESCAPED_UNICODE);
        } else {
            return "";
        }
    }

    /**
     * Remove special characters
     *
     * @param string $value
     * @return string
     */
    private function removeSpecialCharacters($value)
    {
        return preg_replace('/[^\p{Latin}\d ]/u', '', $value);
    }

    /**
     * Capture payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order */
        $order = $payment->getOrder();

        try {
            $transactionId = $payment->getAdditionalInformation($this::METHOD_REFERENCE);

            $isInstantCapure = $payment->getAdditionalInformation(BamboraConstants::INSTANT_CAPTURE);

            if ($isInstantCapure === true) {
                $payment->setTransactionId($transactionId . '-' . BamboraConstants::INSTANT_CAPTURE)
                    ->setIsTransactionClosed(true)
                    ->setParentTransactionId($transactionId);

                return $this;
            }

            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(__("The capture action could not, be processed online. Please enable remote payment processing from the module configuration"));
            }

            $currency = $order->getBaseCurrencyCode();
            $minorunits = $this->_bamboraHelper->getCurrencyMinorunits($currency);
            $amountMinorunits = $this->_bamboraHelper->convertPriceToMinorUnits($amount, $minorunits);

            /** @var \Bambora\Online\Model\Api\Epay\Action */
            $actionProvider = $this->_bamboraHelper->getEPayApi(EpayApi::API_ACTION);
            $captureResponse = $actionProvider->capture($amountMinorunits, $transactionId, $this->getAuth());

            $message = "";
            if (!$this->_bamboraHelper->validateEpayApiResult($captureResponse, $transactionId, $this->getAuth(), BamboraConstants::CAPTURE, $message)) {
                throw new \Exception(__("The capture action failed.") . ' - '.$message);
            }

            $payment->setTransactionId($transactionId. '-' . Transaction::TYPE_CAPTURE)
                    ->setIsTransactionClosed(true)
                    ->setParentTransactionId($transactionId);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "({$order->getIncrementId()}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Refund payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order */
        $order = $payment->getOrder();
        $id = $order->getIncrementId();

        try {
            $creditMemo = $payment->getCreditmemo();
            $id = $creditMemo->getInvoice()->getIncrementId();

            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(__("The refund action could not, be processed online. Please enable remote payment processing from the module configuration"));
            }

            $transactionId = $payment->getAdditionalInformation($this::METHOD_REFERENCE);

            $currency = $order->getBaseCurrencyCode();
            $minorunits = $this->_bamboraHelper->getCurrencyMinorunits($currency);
            $amountMinorunits = $this->_bamboraHelper->convertPriceToMinorUnits($amount, $minorunits);

            /** @var \Bambora\Online\Model\Api\Epay\Action */
            $actionProvider = $this->_bamboraHelper->getEPayApi(EpayApi::API_ACTION);
            $creditResponse = $actionProvider->credit($amountMinorunits, $transactionId, $this->getAuth());

            $message = "";
            if (!$this->_bamboraHelper->validateEpayApiResult($creditResponse, $transactionId, $this->getAuth(), BamboraConstants::REFUND, $message)) {
                throw new \Exception(__("The refund action failed.") . ' - '.$message);
            }

            $payment->setTransactionId($transactionId. '-' . Transaction::TYPE_REFUND)
                    ->setIsTransactionClosed(true)
                    ->setParentTransactionId($transactionId);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "({$id}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Cancel payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        try {
            $this->void($payment);
            $this->_messageManager->addSuccess(__("The payment have been voided").' ('.$payment->getOrder()->getIncrementId().')');
        } catch (\Exception $ex) {
            $this->_messageManager->addError($ex->getMessage());
        }

        return $this;
    }

    /**
     * Void payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order */
        $order = $payment->getOrder();

        try {
            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(__("The void action could not, be processed online. Please enable remote payment processing from the module configuration"));
            }

            $transactionId = $payment->getAdditionalInformation($this::METHOD_REFERENCE);

            /** @var \Bambora\Online\Model\Api\Epay\Action */
            $actionProvider = $this->_bamboraHelper->getEPayApi(EpayApi::API_ACTION);
            $deleteResponse = $actionProvider->delete($transactionId, $this->getAuth());

            $message = "";
            if (!$this->_bamboraHelper->validateEpayApiResult($deleteResponse, $transactionId, $this->getAuth(), BamboraConstants::VOID, $message)) {
                throw new \Exception(__('The void action failed.') . ' - '.$message);
            }

            $payment->setTransactionId($transactionId. '-' . Transaction::TYPE_VOID)
                    ->setIsTransactionClosed(true)
                    ->setParentTransactionId($transactionId);

            $this->cancelSurchargeFeeItem($payment);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "(OrderId: {$order->getIncrementId()}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Get Bambora Checkout Transaction
     *
     * @param mixed $transactionId
     * @param string &$message
     * @return \Bambora\Online\Model\Api\Epay\Response\Models\TransactionInformationType|null
     */
    public function getTransaction($transactionId, &$message)
    {
        try {
            if (!$this->getConfigData(BamboraConstants::REMOTE_INTERFACE)) {
                return null;
            }
            /** @var \Bambora\Online\Model\Api\Epay\Action */
            $actionProvider = $this->_bamboraHelper->getEpayApi(EpayApi::API_ACTION);
            $transactionResponse = $actionProvider->getTransaction($transactionId, $this->getAuth());

            if (!$this->_bamboraHelper->validateEpayApiResult($transactionResponse, $transactionId, $this->getAuth(), BamboraConstants::GET_TRANSACTION, $message)) {
                return null;
            }

            return $transactionResponse->transactionInformation;
        } catch (\Exception $ex) {
            $errorMessage = "(TransactionId: {$transactionId}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            return null;
        }
    }

    /**{@inheritDoc}*/
    public function canCapture()
    {
        if ($this->_canCapture && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**{@inheritDoc}*/
    public function canRefund()
    {
        if ($this->_canRefund && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**{@inheritDoc}*/
    public function canVoid()
    {
        if ($this->_canVoid && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve an url for merchant payment logoes
     *
     * @return string
     */
    public function getEpayPaymentTypeUrl()
    {
        /** @var \Bambora\Online\Model\Api\Epay\Action */
        $actionProvider = $this->_bamboraHelper->getEpayApi(EpayApi::API_ACTION);

        return $actionProvider->getPaymentLogoUrl($this->getAuth()->merchantNumber);
    }

    /**
     * Retrieve an url ePay Logo
     *
     * @return string
     */
    public function getEpayLogoUrl()
    {
        /** @var \Bambora\Online\Model\Api\Epay\Action */
        $actionProvider = $this->_bamboraHelper->getEpayApi(EpayApi::API_ACTION);

        return $actionProvider->getEpayLogoUrl();
    }

    /**
     * Retrieve an url for the ePay Checkout action
     *
     * @return string
     */
    public function getCheckoutUrl()
    {
        return $this->_urlBuilder->getUrl('bambora/epay/checkout', ['_secure' => $this->_request->isSecure()]);
    }

    /**
     * Retrieve an url for the ePay Decline action
     *
     * @return string
     */
    public function getCancelUrl()
    {
        return $this->_urlBuilder->getUrl('bambora/epay/cancel', ['_secure' => $this->_request->isSecure()]);
    }
}
