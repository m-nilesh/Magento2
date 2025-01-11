<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Model\Email\TransportBuilder;

use Firebear\ImportExport\Model\Email\TransportBuilderInterface;
use Magento\Framework\Mail\Template\TransportBuilder as AbstractTransportBuilder;
use Magento\Framework\HTTP\Mime;

/**
 * Transport Builder
 */
class TransportBuilder extends AbstractTransportBuilder implements TransportBuilderInterface
{
    /**
     * Set mail from address by scopeId
     *
     * @param string|array $from
     * @param string|int $scopeId
     */
    public function setFromByScope($from, $scopeId = null)
    {
        $result = $this->_senderResolver->resolve($from, $scopeId);
        $this->message->setFrom($result['email'], $result['name']);

        return $this;
    }

    /**
     * Add attachment to email
     *
     * @param string $content
     * @param string $fileName
     * @param string $fileType
     * @return $this
     */
    public function addAttachment($content, $fileName, $fileType)
    {
        $this->message->createAttachment(
            $content,
            $fileType,
            Mime::DISPOSITION_ATTACHMENT,
            Mime::ENCODING_BASE64,
            $fileName
        );
        return $this;
    }
}
