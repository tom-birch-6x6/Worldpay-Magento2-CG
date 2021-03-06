<?php

namespace Sapient\Worldpay\Model\Mapping;

use Sapient\Worldpay\Model\SavedTokenFactory;
use Magento\Framework\Session\SessionManagerInterface;

class Service
{

    protected $_logger;
    protected $savedTokenFactory;
    protected $_scopeConfig;
    /**
     * @var SessionManagerInterface
     */
    protected $session;
    const THIS_TRANSACTION = 'thisTransaction';
    const LESS_THAN_THIRTY_DAYS = 'lessThanThirtyDays';
    const THIRTY_TO_SIXTY_DAYS = 'thirtyToSixtyDays';
    const MORE_THAN_SIXTY_DAYS = 'moreThanSixtyDays';
    const DURING_TRANSACTION = 'duringTransaction';
    const CREATED_DURING_TRANSACTION = 'createdDuringTransaction';
    const CHANGED_DURING_TRANSACTION = 'changedDuringTransaction';
    const NO_ACCOUNT = 'noAccount';
    const NO_CHANGE = 'noChange';

    public function __construct(
        \Sapient\Worldpay\Logger\WorldpayLogger $wplogger,
        \Sapient\Worldpay\Helper\Data $worldpayHelper,
        SavedTokenFactory $savedTokenFactory,
        \Sapient\Worldpay\Model\SavedToken $savedtoken,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Customer\Model\Session $customerSession,
        SessionManagerInterface $session,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->wplogger = $wplogger;
        $this->savedTokenFactory = $savedTokenFactory;
        $this->worldpayHelper = $worldpayHelper;
        $this->customerSession = $customerSession;
        $this->savedtoken = $savedtoken;
        $this->_urlBuilder = $urlBuilder;
        $this->session = $session;
        $this->_scopeConfig = $scopeConfig;
    }

    public function collectVaultOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $reservedOrderId = $quote->getReservedOrderId();
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);
        return [
            'orderCode' => $orderCode,
            'merchantCode' => $this->worldpayHelper->getMerchantCode($paymentDetails['cc_type']),
            'orderDescription' => $this->_getOrderDescription($reservedOrderId),
            'currencyCode' => $quote->getQuoteCurrencyCode(),
            'amount' => $quote->getGrandTotal(),
            'paymentDetails' => $this->_getVaultPaymentDetails($paymentDetails),
            'cardAddress' => $this->_getCardAddress($quote),
            'shopperEmail' => $quote->getCustomerEmail(),
            'threeDSecureConfig' => $this->_getThreeDSecureConfig($paymentDetails['method']),
            'tokenRequestConfig' => $this->_getTokenRequestConfig($paymentDetails),
            'acceptHeader' => php_sapi_name() !== "cli" ? filter_input(INPUT_SERVER, 'HTTP_ACCEPT') : '',
            'userAgentHeader' => php_sapi_name() !== "cli" ? filter_input(
                INPUT_SERVER,
                'HTTP_USER_AGENT',
                FILTER_SANITIZE_STRING,
                FILTER_FLAG_STRIP_LOW
            ) : '',
            'shippingAddress' => $this->_getShippingAddress($quote),
            'billingAddress' => $this->_getBillingAddress($quote),
            'cusDetails' => $this->getCustomerDetailsfor3DS2($quote),
            'exemptionEngine' => $this->getExemptionEngineDetails(),
            'method' => $paymentDetails['method'],
            'orderStoreId' => $orderStoreId,
            'shopperId' => $quote->getCustomerId(),
            'exponent' => $exponent
        ];
    }

    public function collectDirectOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $reservedOrderId = $quote->getReservedOrderId();
        $savemyCard = isset($paymentDetails['additional_data']['save_my_card'])
                ? $paymentDetails['additional_data']['save_my_card'] : '';
        $tokenizationEnabled = isset($paymentDetails['additional_data']['tokenization_enabled'])
                ? $paymentDetails['additional_data']['tokenization_enabled'] : '';
        $storedCredentialsEnabled = isset($paymentDetails['additional_data']['stored_credentials_enabled'])
                ? $paymentDetails['additional_data']['stored_credentials_enabled'] : '';
        $paymentDetails['additional_data']['disclaimerFlag'] =
                isset($paymentDetails['additional_data']['disclaimerFlag'])
                ? $paymentDetails['additional_data']['disclaimerFlag'] : 0;
        $thirdPartyData = '';
        $shippingFee = '';
        if ((isset($paymentDetails['additional_data']['cpf']) && $paymentDetails['additional_data']['cpf'] == true)
                || (isset($paymentDetails['additional_data']['instalment'])
                && ($paymentDetails['additional_data']['instalment'] == true))) {
            $thirdPartyData = $this->getThirdPartyDetails($paymentDetails, $quote);
            $shippingFee = $this->getShippingFeeForBrazil($paymentDetails, $quote);
        }
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);
        return [
                'orderCode' => $orderCode,
                'merchantCode' => $this->worldpayHelper->getMerchantCode($paymentDetails['additional_data']['cc_type']),
                'orderDescription' => $this->_getOrderDescription($reservedOrderId),
                'currencyCode' => $quote->getQuoteCurrencyCode(),
                'amount' => $quote->getGrandTotal(),
                'paymentDetails' => $this->_getPaymentDetails($paymentDetails),
                'cardAddress' => $this->_getCardAddress($quote),
                'shopperEmail' => $quote->getCustomerEmail(),
                'threeDSecureConfig' => $this->_getThreeDSecureConfig($paymentDetails['method']),
                'tokenRequestConfig' => $this->_getTokenRequestConfig($paymentDetails),
                'acceptHeader' => php_sapi_name() !== "cli" ? filter_input(INPUT_SERVER, 'HTTP_ACCEPT') : '',
                'userAgentHeader' => php_sapi_name() !== "cli" ? filter_input(
                    INPUT_SERVER,
                    'HTTP_USER_AGENT',
                    FILTER_SANITIZE_STRING,
                    FILTER_FLAG_STRIP_LOW
                ) : '',
                'shippingAddress' => $this->_getShippingAddress($quote),
                'billingAddress' => $this->_getBillingAddress($quote),
                'method' => $paymentDetails['method'],
                'orderStoreId' => $orderStoreId,
                'shopperId' => $quote->getCustomerId(),
                'saveCardEnabled' => $savemyCard,
                'tokenizationEnabled' => $tokenizationEnabled,
                'storedCredentialsEnabled' => $storedCredentialsEnabled,
                'cusDetails' => $this->getCustomerDetailsfor3DS2($quote),
                'exemptionEngine' => $this->getExemptionEngineDetails(),
                'thirdPartyData' => $thirdPartyData,
                'shippingfee' => $shippingFee,
                'exponent' => $exponent
            ];
    }

    public function getCustomerDetailsfor3DS2($quote)
    {
        $cusDetails = [];
        $now = new \DateTime();
        $cusDetails['created_at'] = !empty($this->customerSession->getCustomer()->getCreatedAt())
                ? $this->customerSession->getCustomer()->getCreatedAt() : $now->format('Y-m-d H:i:s');
        $cusDetails['updated_at'] = !empty($this->customerSession->getCustomer()->getUpdatedAt())
                ? $this->customerSession->getCustomer()->getUpdatedAt() : $now->format('Y-m-d H:i:s');
        $orderDetails = $this->worldpayHelper->getOrderDetailsByEmailId($quote->getCustomerEmail());
        $orderDetails['created_at'] = !empty($orderDetails['created_at'])
                ? $orderDetails['created_at'] : $now->format('Y-m-d H:i:s');
        $orderDetails['updated_at'] = !empty($orderDetails['updated_at'])
                ? $orderDetails['updated_at'] : $now->format('Y-m-d H:i:s');
        $orderDetails['previous_purchase'] = !empty($orderDetails['updated_at'])
                ? 'true' : 'false';
        
        $orderCount = $this->worldpayHelper->getOrdersCountByEmailId($quote->getCustomerEmail());
        if ($quote->getCustomerId()) {
            $savedCardCount = $this->worldpayHelper->getSavedCardsCount($quote->getCustomerId());
        } else {
            $savedCardCount = 0;
        }
        
        $cusDetails['shopperAccountAgeIndicator'] = $this->
                getshopperAccountAgeIndicator($cusDetails['created_at'], $now->format('Y-m-d H:i:s'));
        $cusDetails['shopperAccountChangeIndicator'] = $this->
                getShopperAccountChangeIndicator($cusDetails['updated_at'], $now->format('Y-m-d H:i:s'));
        $cusDetails['shopperAccountPasswordChangeIndicator'] = $this->
                getShopperAccountPasswordChangeIndicator($cusDetails['updated_at'], $now->format('Y-m-d H:i:s'));
        $cusDetails['shopperAccountShippingAddressUsageIndicator'] = $this->
           getShopperAccountShippingAddressUsageIndicator($orderDetails['created_at'], $now->format('Y-m-d H:i:s'));
        $cusDetails['shopperAccountPaymentAccountIndicator'] = $this->
           getShopperAccountPaymentAccountIndicator($orderDetails['created_at'], $now->format('Y-m-d H:i:s'));
        
        $cusDetails['order_details'] = $orderDetails;
        $cusDetails['order_count'] = $orderCount;
        $cusDetails['card_count'] = $savedCardCount;
        $cusDetails['shipping_method'] = $quote->getShippingAddress()->getShippingMethod();

        return $cusDetails;
    }

    public function getExemptionEngineDetails()
    {
        $exemptionEngine = [];
        $exemptionEngine['enabled'] = $this->_scopeConfig->
                getValue(
                    'worldpay/exemption_config/exemption_engine',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
        $exemptionEngine['placement'] = $this->_scopeConfig->
                getValue(
                    'worldpay/exemption_config/exemption_engine_placement',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
        $exemptionEngine['type'] = $this->_scopeConfig->
                getValue(
                    'worldpay/exemption_config/exemption_engine_type',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
        return $exemptionEngine;
    }

    public function collectRedirectOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $updatedPaymentDetails = '';
        $reservedOrderId = $quote->getReservedOrderId();
        if ($paymentDetails['additional_data']['cc_type'] == 'savedcard') {
            $updatedPaymentDetails = $this->_getPaymentDetailsUsingToken($paymentDetails, $quote);
            $paymentType = $updatedPaymentDetails['cardMethod'];
        } else {
            $paymentType = $this->_getRedirectPaymentType($paymentDetails);
            $updatedPaymentDetails = ['token_type' => $this->worldpayHelper->getMerchantTokenization()];
        }
        $thirdPartyData = '';
        $shippingFee = '';
        if ((isset($paymentDetails['additional_data']['cpf'])
                && $paymentDetails['additional_data']['cpf'] == true)
                || (isset($paymentDetails['additional_data']['instalment'])
                        && ($paymentDetails['additional_data']['instalment'] == true))) {
            $thirdPartyData = $this->getThirdPartyDetails($paymentDetails, $quote);
            $shippingFee = $this->getShippingFeeForBrazil($paymentDetails, $quote);
        }
        $stmtNarrative = '';
        $apmPaymentTypes = $this->worldpayHelper->getApmTypes('worldpay_apm');
        if (array_key_exists($paymentDetails['additional_data']['cc_type'], $apmPaymentTypes) &&
                (isset($paymentDetails['additional_data']['statementNarrative']))) {
            $stmtNarrative = $paymentDetails['additional_data']['statementNarrative'];
        }
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);
        return [
                'orderCode' => $orderCode,
                'merchantCode' => $this->worldpayHelper->
                                   getMerchantCode($paymentDetails['additional_data']['cc_type']),
                'orderDescription' => $this->_getOrderDescription($reservedOrderId),
                'currencyCode' => $quote->getQuoteCurrencyCode(),
                'amount' => $quote->getGrandTotal(),
                'paymentType' => $paymentType,
                'shopperEmail' => $quote->getCustomerEmail(),
                'statementNarrative' => $stmtNarrative,
                'threeDSecureConfig' => $this->_getThreeDSecureConfig(),
                'tokenRequestConfig' => $this->_getTokenRequestConfig($paymentDetails),
                'acceptHeader' => php_sapi_name() !== "cli" ? filter_input(INPUT_SERVER, 'HTTP_ACCEPT') : '',
                'userAgentHeader' => php_sapi_name() !== "cli" ?
                                        filter_input(
                                            INPUT_SERVER,
                                            'HTTP_USER_AGENT',
                                            FILTER_SANITIZE_STRING,
                                            FILTER_FLAG_STRIP_LOW
                                        ) : '',
                'shippingAddress' => $this->_getShippingAddress($quote),
                'billingAddress' => $this->_getBillingAddress($quote),
                'method' => $paymentDetails['method'],
                'paymentPagesEnabled' => $this->worldpayHelper->getCustomPaymentEnabled(),
                'installationId' => $this->worldpayHelper->getInstallationId(),
                'hideAddress' => $this->worldpayHelper->getHideAddress(),
                'shopperId' => $quote->getCustomerId(),
                'orderStoreId' => $orderStoreId,
                'paymentDetails' => $updatedPaymentDetails,
                'thirdPartyData' => $thirdPartyData,
                'shippingfee' => $shippingFee,
                'exponent' => $exponent
            ];
    }

    public function collectKlarnaOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $reservedOrderId = $quote->getReservedOrderId();
        $stmtNarrative = '';
        
        $apmPaymentTypes = $this->worldpayHelper->getApmTypes('worldpay_apm');
        if (array_key_exists($paymentDetails['additional_data']['cc_type'], $apmPaymentTypes) &&
                (isset($paymentDetails['additional_data']['statementNarrative']))) {
            $stmtNarrative = $paymentDetails['additional_data']['statementNarrative'];
        }
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);
        return [
            'orderCode' => $orderCode,
            'merchantCode' => $this->worldpayHelper->getMerchantCode($paymentDetails['additional_data']['cc_type']),
            'orderDescription' => $this->_getOrderDescription($reservedOrderId),
            'currencyCode' => $quote->getQuoteCurrencyCode(),
            'amount' => $quote->getGrandTotal(),
            'paymentType' => $this->_getRedirectPaymentType($paymentDetails),
            'shopperEmail' => $quote->getCustomerEmail(),
            'statementNarrative' => $stmtNarrative,
            'threeDSecureConfig' => $this->_getThreeDSecureConfig(),
            'tokenRequestConfig' => $this->_getTokenRequestConfig($paymentDetails),
            'acceptHeader' => php_sapi_name() !== "cli" ? filter_input(INPUT_SERVER, 'HTTP_ACCEPT') : '',
            'userAgentHeader' => php_sapi_name() !== "cli" ? filter_input(
                INPUT_SERVER,
                'HTTP_USER_AGENT',
                FILTER_SANITIZE_STRING,
                FILTER_FLAG_STRIP_LOW
            ) : '',
            'shippingAddress' => $this->_getShippingAddress($quote),
            'billingAddress' => $this->_getBillingAddress($quote),
            'method' => $paymentDetails['method'],
            'paymentPagesEnabled' => $this->worldpayHelper->getCustomPaymentEnabled(),
            'installationId' => $this->worldpayHelper->getInstallationId(),
            'hideAddress' => $this->worldpayHelper->getHideAddress(),
            'orderLineItems' => $this->_getOrderLineItems($quote, 'KLARNA-SSL'),
            'orderStoreId' => $orderStoreId,
            'exponent' => $exponent
        ];
    }

    public function collectTokenOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $reservedOrderId = $quote->getReservedOrderId();
        $updatedPaymentDetails = $this->_getPaymentDetailsUsingToken($paymentDetails, $quote);

        $savemyCard = isset($paymentDetails['additional_data']['save_my_card'])
                ? $paymentDetails['additional_data']['save_my_card'] : '';
        $tokenizationEnabled = isset($paymentDetails['additional_data']['tokenization_enabled'])
                ? $paymentDetails['additional_data']['tokenization_enabled'] : '';
        $storedCredentialsEnabled = isset($paymentDetails['additional_data']['stored_credentials_enabled'])
                ? $paymentDetails['additional_data']['stored_credentials_enabled'] : '';
        $thirdPartyData = '';
        $shippingFee = '';
        if ((isset($paymentDetails['additional_data']['cpf'])
                && $paymentDetails['additional_data']['cpf'] == true)
                || (isset($paymentDetails['additional_data']['instalment'])
                        && ($paymentDetails['additional_data']['instalment'] == true))) {
            $thirdPartyData = $this->getThirdPartyDetails($paymentDetails, $quote);
            $shippingFee = $this->getShippingFeeForBrazil($paymentDetails, $quote);
        }
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);
        return [
            'orderCode' => $orderCode,
                'merchantCode' => $this->worldpayHelper->getMerchantCode($updatedPaymentDetails['brand']),
                'orderDescription' => $this->_getOrderDescription($reservedOrderId),
                'currencyCode' => $quote->getQuoteCurrencyCode(),
                'amount' => $quote->getGrandTotal(),
                'paymentDetails' => $updatedPaymentDetails,
                'cardAddress' => $this->_getCardAddress($quote),
                'shopperEmail' => $quote->getCustomerEmail(),
                'threeDSecureConfig' => $this->_getThreeDSecureConfig($paymentDetails['method']),
                'tokenRequestConfig' => $this->_getTokenRequestConfig($paymentDetails),
                'acceptHeader' => filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),
                'userAgentHeader' => filter_input(
                    INPUT_SERVER,
                    'HTTP_USER_AGENT',
                    FILTER_SANITIZE_STRING,
                    FILTER_FLAG_STRIP_LOW
                ),
                'shippingAddress' => $this->_getShippingAddress($quote),
                'billingAddress' => $this->_getBillingAddress($quote),
                'method' => $paymentDetails['method'],
                'orderStoreId' => $orderStoreId,
                'shopperId' => $quote->getCustomerId(),
                'saveCardEnabled' => $savemyCard,
                'tokenizationEnabled' => $tokenizationEnabled,
                'storedCredentialsEnabled' => $storedCredentialsEnabled,
                'cusDetails' => $this->getCustomerDetailsfor3DS2($quote),
                'exemptionEngine' => $this->getExemptionEngineDetails(),
                'thirdPartyData' => $thirdPartyData,
                'shippingfee' => $shippingFee,
                'exponent' => $exponent
            ];
    }

    public function collectPaymentOptionsParameters(
        $countryId,
        $paymenttype
    ) {
        return [
            'merchantCode' => $this->worldpayHelper->getMerchantCode($paymenttype),
            'countryCode' => $countryId,
            'paymentType' => $paymenttype
        ];
    }

    private function _getTokenRequestConfig($paymentDetails)
    {
        if (isset($paymentDetails['additional_data']['save_my_card'])) {
            return $paymentDetails['additional_data']['save_my_card'];
        }
    }

    private function _getThreeDSecureConfig($method = null)
    {
        if ($method == 'worldpay_moto') {
            return [
                'isDynamic3D' => false,
                'is3DSecure' => false
            ];
        } elseif ($method == 'worldpay_cc_vault') {
            return [
                'isDynamic3D' => true,
                'is3DSecure' => false
            ];
        } else {
            return [
                'isDynamic3D' => (bool) $this->worldpayHelper->isDynamic3DEnabled(),
                'is3DSecure' => (bool) $this->worldpayHelper->is3DSecureEnabled()
            ];
        }
    }

    private function _getShippingAddress($quote)
    {
        $shippingaddress = $this->_getAddress($quote->getShippingAddress());
        if (!array_filter($shippingaddress)) {
            $shippingaddress = $this->_getAddress($quote->getBillingAddress());
        }
        return $shippingaddress;
    }

    private function _getBillingAddress($quote)
    {
        return $this->_getAddress($quote->getBillingAddress());
    }

    private function _getOrderLineItems($quote, $paymentType = null)
    {
        $orderitems = [];
        $orderitems['orderTaxAmount'] = $quote->getShippingAddress()->getData('tax_amount');
        $orderitems['termsURL'] = $this->_urlBuilder->getUrl();
        $lineitem = [];
        $orderItems = $quote->getItemsCollection();
        foreach ($orderItems as $_item) {
            $lineitem = [];
            if ($_item->getParentItem()) {
                continue;
            } else {
                $rowtotal = $_item->getRowTotal();
                $totalamount = $rowtotal - $_item->getDiscountAmount();
                $totaltax = $_item->getTaxAmount() + $_item->getHiddenTaxAmount()
                        + $_item->getWeeeTaxAppliedRowAmount();
                $discountamount = $_item->getDiscountAmount();

                $lineitem['reference'] = $_item->getProductId();
                $lineitem['name'] = $_item->getName();
                $lineitem['quantity'] = (int) $_item->getQty();
                $lineitem['quantityUnit'] = $this->worldpayHelper->getQuantityUnit($_item->getProduct());
                $lineitem['unitPrice'] = ($rowtotal / $_item->getQty()) + ($totaltax / $_item->getQty());
                $lineitem['taxRate'] = (int) $_item->getTaxPercent();
                $lineitem['totalAmount'] = $totalamount + $totaltax;
                $lineitem['totalTaxAmount'] = $totaltax;
                if ($discountamount > 0) {
                    $lineitem['totalDiscountAmount'] = $discountamount;
                }
                $orderitems['lineItem'][] = $lineitem;
            }
        }

        $lineitem = [];
        $address = $quote->getShippingAddress();
        if ($address->getShippingAmount() > 0) {
            $totalAmount = $address->getShippingAmount() - $address->getShippingDiscountAmount();
            $totaltax = $address->getShippingTaxAmount() + $address->getShippingHiddenTaxAmount();
            $lineitem['reference'] = 'Shipid';
            $lineitem['name'] = 'Shipping amount';
            $lineitem['quantity'] = 1;
            $lineitem['quantityUnit'] = 'shipping';
            $lineitem['unitPrice'] = $address->getShippingAmount() + $totaltax;
            $lineitem['totalAmount'] = $totalAmount + $totaltax;
            $lineitem['totalTaxAmount'] = $totaltax;
            $lineitem['taxRate'] = (int) (($totaltax * 100) / $address->getShippingAmount());
            if ($address->getShippingDiscountAmount() > 0) {
                $lineitem['totalDiscountAmount'] = $address->getShippingDiscountAmount();
            }
            $orderitems['lineItem'][] = $lineitem;
        }
        if (!empty($paymentType) && $paymentType == "KLARNA-SSL" && $orderitems['orderTaxAmount'] == 0) {
            $orderitems['orderTaxAmount'] = $totaltax;
        }
        return $orderitems;
    }

    private function _getAddress($address)
    {
        return [
            'firstName' => $address->getFirstname(),
            'lastName' => $address->getLastname(),
            'street' => $address->getData('street'),
            'postalCode' => $address->getPostcode(),
            'city' => $address->getCity(),
            'countryCode' => $address->getCountryId(),
        ];
    }

    private function _getCardAddress($quote)
    {
        return $this->_getAddress($quote->getBillingAddress());
    }

    private function _getPaymentDetails($paymentDetails)
    {
        $method = $paymentDetails['method'];
        if ($paymentDetails['additional_data']['cc_type'] == "PAYWITHGOOGLE-SSL") {
            return $paymentDetails['additional_data']['cc_type'];
        }

        if ($paymentDetails['additional_data']['cse_enabled']) {
            $details = [
                'cseEnabled' => $paymentDetails['additional_data']['cse_enabled'],
                'encryptedData' => $paymentDetails['additional_data']['encryptedData'],
                'paymentType' => $paymentDetails['additional_data']['cc_type'],
            ];
        } else {
            $details = [
                'paymentType' => $paymentDetails['additional_data']['cc_type'],
                'cardNumber' => $paymentDetails['additional_data']['cc_number'],
                'expiryMonth' => $paymentDetails['additional_data']['cc_exp_month'],
                'expiryYear' => $paymentDetails['additional_data']['cc_exp_year'],
                'cardHolderName' => $paymentDetails['additional_data']['cc_name'],
                'cseEnabled' => $paymentDetails['additional_data']['cse_enabled'],
            ];

            if (isset($paymentDetails['additional_data']['cc_cid'])) {
                $details['cvc'] = $paymentDetails['additional_data']['cc_cid'];
            }
        }
        $this->customerSession->setIsSavedCardRequested(false);
        if (isset($paymentDetails['additional_data']['save_my_card'])
                && $paymentDetails['additional_data']['save_my_card']) {
            $this->customerSession->setIsSavedCardRequested(true);
        }
        $details['sessionId'] = $this->session->getSessionId();
        $details['shopperIpAddress'] = $this->_getClientIPAddress();
        $details['dynamicInteractionType'] = $this->worldpayHelper->getDynamicIntegrationType($method);

        // 3DS2 value
        if (isset($paymentDetails['additional_data']['dfReferenceId'])) {
            $details['dfReferenceId'] = $paymentDetails['additional_data']['dfReferenceId'];
        }
        // Check for Merchant Token
        $details['token_type'] = $this->worldpayHelper->getMerchantTokenization();
        return $details;
    }

    private function _getRedirectPaymentType($paymentDetails)
    {
        if ('CARTEBLEUE-SSL' == $paymentDetails['additional_data']['cc_type']) {
            return 'ECMC-SSL';
        }
        return $paymentDetails['additional_data']['cc_type'];
    }

    private function _getOrderDescription($reservedOrderId)
    {
        return $this->worldpayHelper->getOrderDescription();
    }

    private function _getPaymentDetailsUsingToken($paymentDetails, $quote)
    {
        $savedCardData = $this->savedtoken->loadByTokenCode($paymentDetails['additional_data']['tokenCode']);
        if (isset($paymentDetails['encryptedData'])) {
            $details = [
                'encryptedData' => $paymentDetails['encryptedData'],
                'transactionIdentifier' => $savedCardData->getTransactionIdentifier()
            ];
        } else {
            $details = [
                'brand' => $savedCardData->getCardBrand(),
                'paymentType' => 'TOKEN-SSL',
                'customerId' => $quote->getCustomerId(),
                'tokenCode' => $savedCardData->getTokenCode(),
                'transactionIdentifier' => $savedCardData->getTransactionIdentifier(),
                'cardMethod' => $savedCardData->getMethod()
            ];
            if (isset($paymentDetails['additional_data']['saved_cc_cid'])
                    && !empty($paymentDetails['additional_data']['saved_cc_cid'])) {
                $details['cvc'] = $paymentDetails['additional_data']['saved_cc_cid'];
            }
        }
        $details['sessionId'] = $this->session->getSessionId();
        $details['shopperIpAddress'] = $this->_getClientIPAddress();
        $details['dynamicInteractionType'] = $this->worldpayHelper->
                getDynamicIntegrationType($paymentDetails['method']);
        // 3DS2 value
        if (isset($paymentDetails['additional_data']['dfReferenceId'])) {
            $details['dfReferenceId'] = $paymentDetails['additional_data']['dfReferenceId'];
        }
        // CVV through HPP
        $details['installationId'] = $this->worldpayHelper->getInstallationId();
        $details['ccIntegrationMode'] = $this->worldpayHelper->getCcIntegrationMode();
        $details['paymentPagesEnabled'] = $this->worldpayHelper->getCustomPaymentEnabled();
        // Check for Merchant Token
        $details['token_type'] = $this->worldpayHelper->getMerchantTokenization();
        return $details;
    }

    private function _getVaultPaymentDetails($paymentDetails)
    {
        $details = [
            'brand' => $paymentDetails['card_brand'],
            'paymentType' => 'TOKEN-SSL',
            'customerId' => $paymentDetails['customer_id'],
            'tokenCode' => $paymentDetails['token'],
        ];
        $details['sessionId'] = $this->session->getSessionId();
        $details['shopperIpAddress'] = $this->_getClientIPAddress();
        $details['dynamicInteractionType'] = $this->worldpayHelper->
                                                getDynamicIntegrationType($paymentDetails['method']);
        // Check for Merchant Token
        $details['token_type'] = $this->worldpayHelper->getMerchantTokenization();
        return $details;
    }

    private function _getClientIPAddress()
    {
        $REMOTE_ADDR = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
        $remoteAddresses = explode(',', $REMOTE_ADDR);
        return trim($remoteAddresses[0]);
    }

    public function collectWalletOrderParameters(
        $orderCode,
        $quote,
        $orderStoreId,
        $paymentDetails
    ) {
        $reservedOrderId = $quote->getReservedOrderId();
        $currencyCode = $quote->getQuoteCurrencyCode();
        $exponent = $this->worldpayHelper->getCurrencyExponent($currencyCode);

        //Google Pay
        if ($paymentDetails['additional_data']['cc_type'] == 'PAYWITHGOOGLE-SSL') {
            if ($paymentDetails['additional_data']['walletResponse']) {
                $walletResponse = (array) json_decode($paymentDetails['additional_data']['walletResponse']);
                $paymentMethodData = (array) $walletResponse['paymentMethodData'];
                $tokenizationData = (array) $paymentMethodData['tokenizationData'];
                $token = (array) json_decode($tokenizationData['token']);

                return [
                    'orderCode' => $orderCode,
                    'merchantCode' => $this->worldpayHelper->
                        getMerchantCode($paymentDetails['additional_data']['cc_type']),
                    'orderDescription' => $this->_getOrderDescription($reservedOrderId),
                    'currencyCode' => $quote->getQuoteCurrencyCode(),
                    'amount' => $quote->getGrandTotal(),
                    'paymentType' => $this->_getRedirectPaymentType($paymentDetails),
                    'shopperEmail' => $quote->getCustomerEmail(),
                    'method' => $paymentDetails['method'],
                    'orderStoreId' => $orderStoreId,
                    'protocolVersion' => $token['protocolVersion'],
                    'signature' => $token['signature'],
                    'signedMessage' => $token['signedMessage'],
                    'exponent' => $exponent
                ];
            }
        }

        //Apple Pay
        if ($paymentDetails['additional_data']['cc_type'] == 'APPLEPAY-SSL') {
            if ($paymentDetails['additional_data']['appleResponse']) {
                $appleResponse = (array) json_decode($paymentDetails['additional_data']['appleResponse']);
                $paymentMethodData = (array) $appleResponse['paymentData'];

                $version = $paymentMethodData['version'];

                $data = $paymentMethodData['data'];
                $signature = $paymentMethodData['signature'];

                $headerObject = $paymentMethodData['header'];

                $ephemeralPublicKey = $headerObject->ephemeralPublicKey;
                $publicKeyHash = $headerObject->publicKeyHash;
                $transactionId = $headerObject->transactionId;

                return [
                    'orderCode' => $orderCode,
                    'merchantCode' => $this->worldpayHelper->
                                        getMerchantCode($paymentDetails['additional_data']['cc_type']),
                    'orderDescription' => $this->_getOrderDescription($reservedOrderId),
                    'currencyCode' => $quote->getQuoteCurrencyCode(),
                    'amount' => $quote->getGrandTotal(),
                    'paymentType' => $this->_getRedirectPaymentType($paymentDetails),
                    'shopperEmail' => $quote->getCustomerEmail(),
                    'method' => $paymentDetails['method'],
                    'orderStoreId' => $orderStoreId,
                    'protocolVersion' => $version,
                    'signature' => $signature,
                    'data' => $data,
                    'ephemeralPublicKey' => $ephemeralPublicKey,
                    'publicKeyHash' => $publicKeyHash,
                    'transactionId' => $transactionId,
                    'exponent' => $exponent
                ];
            }
        }
    }

    public function getThirdPartyDetails($paymentDetails, $quote)
    {

        //Latin America Payment
        if ($this->belongsToLACountries($paymentDetails, $quote)) {
            if ($paymentDetails['additional_data']['cpf_enabled'] == true) {
                $details = [
                    'cpf' => $paymentDetails['additional_data']['cpf']
                ];
            }
            if (($paymentDetails['additional_data']['instalment_enabled']) == true) {
                $details['instalment'] = $paymentDetails['additional_data']['instalment'];
            }
            if (!empty($paymentDetails['additional_data']['statement'])) {
                $details['statement'] = $paymentDetails['additional_data']['statement'];
            }

            return $details;
        }
    }

    public function getShippingFeeForBrazil($paymentDetails, $quote)
    {
        if ($this->belongsToLACountryBr($paymentDetails, $quote)) {
            if ($paymentDetails['method'] == 'worldpay_moto') {
                $details = [
                    'shippingfee' => $quote->getShippingAddress()->getData('shipping_amount')
                ];
            } else {
                $details = [
                    'shippingfee' => $paymentDetails['additional_data']['shippingfee']
                ];
            }
            return $details;
        }
    }
    
    public function belongsToLACountryBr($paymentDetails, $quote)
    {
        $billingAdress = $this->_getBillingAddress($quote);
        $isCpf = $paymentDetails['additional_data']['cpf_enabled'];
        if ($isCpf && $billingAdress['countryCode'] == 'BR') {
            return true;
        }
        return false;
    }

    public function belongsToLACountries($paymentDetails, $quote)
    {
        $billingAdress = $this->_getBillingAddress($quote);
        $ccountryCd = $billingAdress['countryCode'];
        $instalmentEnabled = $paymentDetails['additional_data']['instalment_enabled'];
        $isCpf = $paymentDetails['additional_data']['cpf_enabled'];
        if ($instalmentEnabled) {
            $instalment = $this->worldpayHelper->getInstalmentValues($ccountryCd);
            $lacountries = ['AR', 'BZ', 'BR', 'CL', 'CO', 'CR', 'SV', 'GT', 'HN', 'MX', 'NI', 'PA', 'PE'];
            if ((in_array($billingAdress['countryCode'], $lacountries)) && $instalment !== null) {
                return true;
            }
        } elseif ($isCpf && $billingAdress['countryCode'] == 'BR') {
            return true;
        }
        return false;
    }
    
    public function getShopperAccountAgeIndicator($fromDate, $toDate, $differenceFormat = '%a')
    {
        $datetime1 = date_create($fromDate);
        $datetime2 = date_create($toDate);
        $interval = date_diff($datetime1, $datetime2);
        $days = $interval->format($differenceFormat);
        if ($days > 0 && $days < 30) {
            return self::LESS_THAN_THIRTY_DAYS;
        } elseif ($days > 30 && $days < 60) {
            return self::THIRTY_TO_SIXTY_DAYS;
        } elseif ($days > 60) {
            return self::MORE_THAN_SIXTY_DAYS;
        } else {
            $indicator = !empty($this->customerSession->getCustomer()->getId())
                    ? self::CREATED_DURING_TRANSACTION : self::NO_ACCOUNT;
            return $indicator;
        }
    }
    
    public function getShopperAccountChangeIndicator($fromDate, $toDate, $differenceFormat = '%a')
    {
        $datetime1 = date_create($fromDate);
        $datetime2 = date_create($toDate);
        $interval = date_diff($datetime1, $datetime2);
        $days = $interval->format($differenceFormat);
        if ($days > 0 && $days < 30) {
            return self::LESS_THAN_THIRTY_DAYS;
        } elseif ($days > 30 && $days < 60) {
            return self::THIRTY_TO_SIXTY_DAYS;
        } elseif ($days > 60) {
            return self::MORE_THAN_SIXTY_DAYS;
        } else {
            return self::CHANGED_DURING_TRANSACTION;
        }
    }
    
    public function getShopperAccountPasswordChangeIndicator($fromDate, $toDate, $differenceFormat = '%a')
    {
        $datetime1 = date_create($fromDate);
        $datetime2 = date_create($toDate);
        $interval = date_diff($datetime1, $datetime2);
        $days = $interval->format($differenceFormat);
        if ($days > 0 && $days < 30) {
            return self::LESS_THAN_THIRTY_DAYS;
        } elseif ($days > 30 && $days < 60) {
            return self::THIRTY_TO_SIXTY_DAYS;
        } elseif ($days > 60) {
            return self::MORE_THAN_SIXTY_DAYS;
        } else {
            $indicator = !empty($this->customerSession->getCustomer()->getId())
                            ? self::CHANGED_DURING_TRANSACTION : self::NO_CHANGE;
            return $indicator;
        }
    }
    
    public function getShopperAccountShippingAddressUsageIndicator(
        $fromDate,
        $toDate,
        $differenceFormat = '%a'
    ) {
        $datetime1 = date_create($fromDate);
        $datetime2 = date_create($toDate);
        $interval = date_diff($datetime1, $datetime2);
        $days = $interval->format($differenceFormat);
        if ($days > 0 && $days < 30) {
            return self::LESS_THAN_THIRTY_DAYS;
        } elseif ($days > 30 && $days < 60) {
            return self::THIRTY_TO_SIXTY_DAYS;
        } elseif ($days > 60) {
            return self::MORE_THAN_SIXTY_DAYS;
        } else {
            return self::THIS_TRANSACTION;
        }
    }
    
    public function getShopperAccountPaymentAccountIndicator($fromDate, $toDate, $differenceFormat = '%a')
    {
        $datetime1 = date_create($fromDate);
        $datetime2 = date_create($toDate);
        $interval = date_diff($datetime1, $datetime2);
        $days = $interval->format($differenceFormat);
        if ($days > 0 && $days < 30) {
            return self::LESS_THAN_THIRTY_DAYS;
        } elseif ($days > 30 && $days < 60) {
            return self::THIRTY_TO_SIXTY_DAYS;
        } elseif ($days > 60) {
            return self::MORE_THAN_SIXTY_DAYS;
        } else {
            $indicator = !empty($this->customerSession->getCustomer()->getId())
                                ? self::DURING_TRANSACTION : self::NO_ACCOUNT;
            return $indicator;
        }
    }
}
