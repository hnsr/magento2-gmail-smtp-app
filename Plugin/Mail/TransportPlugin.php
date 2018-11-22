<?php
/**
 * Copyright © MagePal LLC. All rights reserved.
 * See COPYING.txt for license details.
 * http://www.magepal.com | support@magepal.com
 */

namespace MagePal\GmailSmtpApp\Plugin\Mail;

use Zend\Mail\Message;
use Zend\Mail\Transport\SmtpOptions;

class TransportPlugin
{
    /**
     * @var \MagePal\GmailSmtpApp\Helper\Data
     */
    protected $dataHelper;

    /**
     * @var \MagePal\GmailSmtpApp\Model\Store
     */
    protected $storeModel;

    /**
     * @var \Zend\Mail\Transport\Smtp
     */
    private $smtpTransport;

    /**
     * @param \MagePal\GmailSmtpApp\Helper\Data $dataHelper
     */
    public function __construct(
        \MagePal\GmailSmtpApp\Helper\Data $dataHelper,
        \MagePal\GmailSmtpApp\Model\Store $storeModel,
        \Zend\Mail\Transport\Smtp $smtpTransport
    ) {
        $this->dataHelper = $dataHelper;
        $this->storeModel = $storeModel;
        $this->smtpTransport = $smtpTransport;
    }

    /**
     * @param \Magento\Framework\Mail\TransportInterface $subject
     * @param \Closure $proceed
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Zend_Mail_Exception
     */
    public function aroundSendMessage(
        \Magento\Framework\Mail\TransportInterface $subject,
        \Closure $proceed
    ): void
    {
        if ($this->dataHelper->isActive()) {
            if (method_exists($subject, 'getStoreId')) {
                $this->storeModel->setStoreId($subject->getStoreId());
            }

            $message = $subject->getMessage();
            $this->sendSmtpMessage($message);
        } else {
            $proceed();
        }
    }

    /**
     * @param \Magento\Framework\Mail\MessageInterface $message
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Zend_Mail_Exception
     */
    public function sendSmtpMessage(\Magento\Framework\Mail\MessageInterface $message): void
    {
        $dataHelper = $this->dataHelper;
        $dataHelper->setStoreId($this->storeModel->getStoreId());

        // This is less than ideal, but we must eventually get a Zend message if we want to use its smtp transport.
        // See \Magento\Framework\Mail\Transport::sendMessage() where this is also done.
        /** @var Message $message */
        $message = Message::fromString($message->getRawMessage());

        //Set reply-to path
        $setReturnPath = $dataHelper->getConfigSetReturnPath();
        switch ($setReturnPath) {
            case 1:
                $returnPathEmail = $message->getFrom();
                break;
            case 2:
                $returnPathEmail = $dataHelper->getConfigReturnPathEmail();
                break;
            default:
                $returnPathEmail = null;
                break;
        }

        if ($returnPathEmail !== null && $dataHelper->getConfigSetReturnPath()) {
            $message->getHeaders()->addHeaderLine('Return-Path', $returnPathEmail);
        }

        if ($message->getReplyTo() === null && $dataHelper->getConfigSetReplyTo()) {
            $message->setReplyTo($returnPathEmail);
        }

        if ($returnPathEmail !== null && $dataHelper->getConfigSetFrom()) {
            $message->getHeaders()->removeHeader('From');
            $message->setFrom($returnPathEmail);
        }

        $this->setConnectionOptions();

        try {
            $this->smtpTransport->send($message);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase($e->getMessage()), $e);
        }
    }

    protected function setConnectionOptions()
    {
        $dataHelper = $this->dataHelper;

        $smtpConf = [
            'name' => $dataHelper->getConfigName(),
            'port' => $dataHelper->getConfigSmtpPort(),
        ];

        $auth = strtolower($dataHelper->getConfigAuth());
        if ($auth !== 'none') {
            $smtpConf['connection_class'] = $auth === 'cram-md5' ? 'crammd5' : $auth;
            $smtpConf['connection_config'] = [
                'username' => $dataHelper->getConfigUsername(),
                'password' => $dataHelper->getConfigPassword(),
            ];
        }

        $ssl = $dataHelper->getConfigSsl();
        if ($ssl !== 'none') {
            $smtpConf['connection_config']['ssl'] = $ssl;
        }
        $smtpConf['host'] = $dataHelper->getConfigSmtpHost();

        $this->smtpTransport->setOptions(new SmtpOptions($smtpConf));
    }
}
