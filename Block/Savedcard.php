<?php
/**
 * @copyright 2017 Sapient
 */
namespace Sapient\Worldpay\Block;

class Savedcard extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Sapient\Worldpay\Model\SavedTokenFactory
     */
    protected $_savecard;
    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;
    /**
     * constructor
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Sapient\Worldpay\Model\SavedTokenFactory $savecard
     * @param \Magento\Customer\Model\Session $customerSession
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Sapient\Worldpay\Model\SavedTokenFactory $savecard,
        \Magento\Customer\Model\Session $customerSession,
        \Sapient\Worldpay\Helper\Data $worldpayhelper,
        array $data = []
    ) {
        $this->_savecard = $savecard;
        $this->_customerSession = $customerSession;
        $this->worlpayhelper = $worldpayhelper;
           parent::__construct($context, $data);
    }

    /**
     * @return bool|\Sapient\Worldpay\Model\ResourceModel\SavedToken\Collection
     */
    public function getSavedCard()
    {
        if (!($customerId = $this->_customerSession->getCustomerId())) {
            return false;
        }
        $merchantTokenEnabled = $this->worlpayhelper->getMerchantTokenization();
        $tokenType = $merchantTokenEnabled ? 'merchant' : 'shopper';
        return $savedCardCollection = $this->_savecard->create()->getCollection()
                                    ->addFieldToSelect(['card_brand','card_number',
                                        'cardholder_name','card_expiry_month','card_expiry_year',
                                        'transaction_identifier', 'token_code'])
                                    ->addFieldToFilter('customer_id', ['eq' => $customerId])
                                    ->addFieldToFilter('token_type', ['eq' => $tokenType]);
    }

   /**
    * @param \Sapient\Worldpay\Model\SavedToken $saveCard
    * @return string
    */
    public function getDeleteUrl($saveCard)
    {
        return $this->getUrl('worldpay/savedcard/delete', ['id' => $saveCard->getId()]);
    }

    /**
     * @param \Sapient\Worldpay\Model\SavedToken $saveCard
     * @return string
     */
    public function getEditUrl($saveCard)
    {
        return $this->getUrl('worldpay/savedcard/edit', ['id' => $saveCard->getId()]);
    }
}
