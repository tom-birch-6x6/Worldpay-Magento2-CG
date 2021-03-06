<?php
/**
 * @copyright 2017 Sapient
 */
namespace Sapient\Worldpay\Cron;

use \Magento\Framework\App\ObjectManager;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactoryInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Exception;

/**
 * Model for order sync status based on configuration set by admin
 */
class RecurringOrders
{

    /**
     * @var \Sapient\Worldpay\Logger\WorldpayLogger
     */
    protected $_logger;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    private $orderCollectionFactory;
    private $_orderId;
    private $_order;
    private $_paymentUpdate;
    private $_tokenState;
    
    /**
     * @var CollectionFactory
     */
    private $subscriptionCollectionFactory;
    
    /**
     * @var CollectionFactory
     */
    private $transactionCollectionFactory;
    
    /**
     * @var CollectionFactory
     */
    private $addressCollectionFactory;
    
    /**
     * Constructor
     *
     * @param \Sapient\Worldpay\Logger\WorldpayLogger $wplogger
     * @param JsonFactory $resultJsonFactory
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Sapient\Worldpay\Helper\Data $worldpayhelper
     * @param \Sapient\Worldpay\Model\Payment\Service $paymentservice,
     * @param \Sapient\Worldpay\Model\Token\WorldpayToken $worldpaytoken,
     * @param \Sapient\Worldpay\Model\Order\Service $orderservice,
     * @param \Sapient\Worldpay\Model\Recurring\Subscription $subscriptions,
     * @param \Sapient\Worldpay\Model\Recurring\Subscription\Transactions $recurringTransactions,
     * @param \Sapient\Worldpay\Model\Recurring\Subscription\Address $subscriptionAddress
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        \Sapient\Worldpay\Logger\WorldpayLogger $wplogger,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Sapient\Worldpay\Helper\Data $worldpayhelper,
        \Sapient\Worldpay\Model\Payment\Service $paymentservice,
        \Sapient\Worldpay\Model\SavedToken $worldpaytoken,
        \Sapient\Worldpay\Model\Order\Service $orderservice,
        \Sapient\Worldpay\Model\Recurring\Subscription $subscriptions,
        \Sapient\Worldpay\Model\Recurring\Subscription\Transactions $recurringTransactions,
        \Sapient\Worldpay\Model\Recurring\Subscription\Address $subscriptionAddress,
        \Sapient\Worldpay\Helper\Recurring $recurringhelper,
        \Sapient\Worldpay\Model\Recurring\Subscription\TransactionsFactory $transactionsFactory,
        \Sapient\Worldpay\Model\Recurring\PlanFactory $planFactory
    ) {
        $this->_logger = $wplogger;
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->worldpayhelper = $worldpayhelper;
        $this->paymentservice = $paymentservice;
        $this->orderservice = $orderservice;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->worldpaytoken = $worldpaytoken;
        $this->subscriptionCollectionFactory = $subscriptions;
        $this->transactionCollectionFactory = $recurringTransactions;
        $this->addressCollectionFactory = $subscriptionAddress;
        $this->recurringhelper = $recurringhelper;
        $this->transactionFactory = $transactionsFactory;
        $this->planFactory = $planFactory;
    }

    /**
     * Get the list of orders to be sync the status
     */
    public function execute()
    {
        $this->_logger->info('Recurring Orders transactions executed on - '.date('Y-m-d H:i:s'));
        $recurringOrderIds = $this->getRecurringOrderIds();
                
        if (!empty($recurringOrderIds)) {
            foreach ($recurringOrderIds as $recurringOrder) {
                $orderData = $paymentData = [];
                $recurringOrderData = $recurringOrder;
                $totalInfo = $this->getTotalDetails($recurringOrderData);
                if ($totalInfo && isset($totalInfo['tokenData'][0])) {
                    $orderDetails = $totalInfo['orderDetails'][0];
                    $addressDetails['shipping'] = $totalInfo['addressData'][1];
                    $addressDetails['billing'] = $totalInfo['addressData'][0];
                    $subscriptionDetails = $totalInfo['subscriptionData'][0];
                    $tokenDetails = $totalInfo['tokenData'][0];
                    $orderData = [
                    'currency_id'       => $orderDetails['order_currency_code'],
                    'item_price'        => $subscriptionDetails['item_price'],
                    'email'             => $subscriptionDetails['customer_email'],
                    'customer_id'       => $subscriptionDetails['customer_id'],
                    'shipping_method'   => $subscriptionDetails['shipping_method'],
                    'store_id'          => $subscriptionDetails['store_id'],
                    'store_name'        => $subscriptionDetails['store_name'],
                    'product_id'        => $subscriptionDetails['product_id'],
                    'product_sku'        => $subscriptionDetails['product_sku'],
                    'qty'               => 1
                    ];

                    $shipping = $addressDetails['shipping'];
                    $customerId = $subscriptionDetails['customer_id'];
                    $orderData['shipping_address'] = $this->getShippingAddress($shipping, $customerId);
                    $orderData['billing_address'] = $this->getBillingAddress($addressDetails['billing']);
                    $paymentType = "worldpay_cc";

                    $paymentData['paymentMethod']['method'] = $paymentType;
                    $paymentData['paymentMethod']['additional_data'] = $this->getAdditionalData($tokenDetails);
                    $paymentData['billing_address'] = $this->getBillingAddress($addressDetails['billing']);
                    try {
                        $result = $this->recurringhelper->createMageOrder($orderData, $paymentData);
                        $this->updateRecurringTransactions($result, $recurringOrderData['entity_id']);
                    } catch (Exception $e) {
                        $this->_logger->error($e->getMessage());
                    }
                }
            }
        }
        return $this;
    }
    
    /**
     * Get the list of orders to be Sync
     *
     * @return array List of order IDs
     */
    public function getRecurringOrderIds()
    {
        $curdate = date("Y-m-d");
        $fiveDays = strtotime(date("Y-m-d", strtotime($curdate)) . " +5 day");
        $cronDate = date('Y-m-d', $fiveDays);
        
        
        $result = $this->transactionCollectionFactory->getCollection()
                ->addFieldToFilter('status', ['eq' => 'active'])
                ->addFieldToFilter('recurring_date', ['gteq' => $curdate])
                ->addFieldToFilter('recurring_date', ['lteq' => $cronDate])->getData();
        return $result;
    }
    
    public function getTotalDetails($recurringOrderData)
    {
        $data = [];
        if ($recurringOrderData) {
            $tokenId = $recurringOrderData['worldpay_token_id'];
            $data['tokenData'] = $this->getTokenInfo($tokenId, $recurringOrderData['customer_id']);
            $data['subscriptionData'] = $this->getSubscriptionsInfo($recurringOrderData['subscription_id']);
            $data['addressData'] = $this->getAddressInfo($recurringOrderData['subscription_id']);
            $data['orderDetails'] = $this->getOrderInfo($recurringOrderData['recurring_order_id']);
        }
        return $data;
    }
    
    public function getTokenInfo($tokenId, $customerId)
    {
        $curdate = date("Y-m-d");
        if ($tokenId) {
            $result = $this->worldpaytoken->getCollection()
                ->addFieldToFilter('id', ['eq' => trim($tokenId)])
                ->addFieldToFilter('customer_id', ['eq' => trim($customerId)])
                ->addFieldToFilter('token_expiry_date', ['gteq' => $curdate])->getData();
            return $result;
        }
    }
    
    public function getSubscriptionsInfo($subscriptionId)
    {
        if ($subscriptionId) {
            $result = $this->subscriptionCollectionFactory->getCollection()
                ->addFieldToFilter('subscription_id', ['eq' => trim($subscriptionId)])->getData();
            return $result;
        }
    }
    
    public function getAddressInfo($subscriptionId)
    {
        if ($subscriptionId) {
            $result = $this->addressCollectionFactory->getCollection()
                ->addFieldToFilter('subscription_id', ['eq' => trim($subscriptionId)])->getData();
            return $result;
        }
    }
    
    /**
     * Get the list of orders to be Sync
     *
     * @return array List of order IDs
     */
    public function getOrderInfo($orderId)
    {
        $orders = $this->getOrderCollectionFactory()->create();
        $orders->distinct(true);
        $orders->addFieldToFilter('main_table.entity_id', ['eq' => trim($orderId)]);
        $orderIds = $orders->getData();
        return $orderIds;
    }

    /**
     * @return CollectionFactoryInterface
     */
    private function getOrderCollectionFactory()
    {
        if ($this->orderCollectionFactory === null) {

            $this->orderCollectionFactory = ObjectManager::getInstance()->get(CollectionFactoryInterface::class);
        }
        return $this->orderCollectionFactory;
    }
    
    /**
     * Frame Shipping Address
     * @return array
     */
    private function getShippingAddress($addressDetails, $customerId)
    {
        $shippingAddress = [
                            'region'        => $addressDetails['region'],
                            'region_id'     => $addressDetails['region_id'],
                            'country_id'    => $addressDetails['country_id'],
                            'street'        => [$addressDetails['street']],
                            'postcode'      => $addressDetails['postcode'],
                            'city'          => $addressDetails['city'],
                            'firstname'     => $addressDetails['firstname'],
                            'lastname'      => $addressDetails['lastname'],
                            'customer_id'   => $customerId,
                            'email'         => $addressDetails['email'],
                            'telephone'     => $addressDetails['telephone'],
                            'fax'           => $addressDetails['fax']
                        ];
        return $shippingAddress;
    }
    
    /**
     * Frame Billing Address
     * @return array
     */
    private function getBillingAddress($addressDetails)
    {
        $billingAddress = [
                            'region'        => $addressDetails['region'],
                            'region_id'     => $addressDetails['region_id'],
                            'country_id'    => $addressDetails['country_id'],
                            'street'        => [$addressDetails['street']],
                            'postcode'      => $addressDetails['postcode'],
                            'city'          => $addressDetails['city'],
                            'firstname'     => $addressDetails['firstname'],
                            'lastname'      => $addressDetails['lastname'],
                            'email'         => $addressDetails['email'],
                            'telephone'     => $addressDetails['telephone'],
                            'fax'           => $addressDetails['fax']
                        ];
        return $billingAddress;
    }
    
    /**
     * Frame Payment Additional data
     * @return array
     */
    private function getAdditionalData($tokenDetails)
    {
        $additionalData = [
                            'cc_cid' => '',
                            'cc_type' => 'savedcard',
                            'cc_number' => '',
                            'cc_name' => '',
                            'save_my_card' => '',
                            'cse_enabled' => '',
                            'encryptedData' => '',
                            'tokenCode' => $tokenDetails['token_code'],
                            'saved_cc_cid' => '',
                            'isSavedCardPayment' => 1,
                            'tokenization_enabled' => 1,
                            'stored_credentials_enabled' => 1,
                            'subscriptionStatus' => ''
                        ];
        return $additionalData;
    }

    /**
     * Update recurring order Transactionsfor next order
     *
     *
     */
    public function updateRecurringTransactions($orderId, $recurringId)
    {
        $transactionDetails = $this->transactionFactory->create()->loadById($recurringId);
        $this->insertNewTransaction($transactionDetails, $orderId);
        $transactionDetails->setStatus('completed')->save();
    }

    public function insertNewTransaction($transactionDetails, $orderId)
    {
        if ($transactionDetails) {
            $date = $transactionDetails->getRecurringDate();
            $week = strtotime(date("Y-m-d", strtotime($date)) . " +1 week");
            $monthdate = strtotime(date("Y-m-d", strtotime($date)) . " +1 month");
            $tmonthsdate = strtotime(date("Y-m-d", strtotime($date)) . " +3 month");
            $sixmonthsdate = strtotime(date("Y-m-d", strtotime($date)) . " +6 month");
            $yeardate = strtotime(date("Y-m-d", strtotime($date)) . " +12 month");
            
            $plan = $this->planFactory->create()->loadById($transactionDetails->getPlanId());
            $planInterval = $plan->getInterval();
            
            $recurringOrderId = $transactionDetails->getRecurringOrderId();
            
            if ($planInterval == 'WEEKLY') {
                $recurringDate = date('Y-m-d', $week);
            } elseif ($planInterval == 'MONTHLY') {
                $recurringDate = date('Y-m-d', $monthdate);
            } elseif ($planInterval == 'QUARTERLY') {
                $recurringDate = date('Y-m-d', $tmonthsdate);
            } elseif ($planInterval == 'SEMIANNUAL') {
                $recurringDate = date('Y-m-d', $sixmonthsdate);
            } elseif ($planInterval == 'ANNUAL') {
                $recurringDate = date('Y-m-d', $yeardate);
            }
            $transactions = $this->transactionFactory->create();
            $transactions->setOriginalOrderId($orderId);
            $transactions->setCustomerId($transactionDetails->getCustomerId());
            $transactions->setPlanId($transactionDetails->getPlanId());
            $transactions->setSubscriptionId($transactionDetails->getSubscriptionId());
            $transactions->setRecurringDate($recurringDate);
            $transactions->setRecurringEndDate($recurringDate);
            $transactions->setStatus('active');
            $transactions->setRecurringOrderId($recurringOrderId);
            $transactions->setWorldpayTokenId($transactionDetails->getWorldpayTokenId());
            $transactions->setWorldpayOrderId($transactionDetails->getWorldpayOrderId());
            $transactions->save();
        }
    }
}
