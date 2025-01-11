<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Model\ValidatorChain;

use Laminas\Validator\StringLength as LaminasStringLength;

class StringLength extends LaminasStringLength
{
    /**
     * @var string[]
     */
    protected $messageTemplates = [
        self::INVALID   => "Invalid type given. String expected",
        self::TOO_SHORT => "'%value%' is less than %min% characters long",
        self::TOO_LONG  => "'%value%' is more than %max% characters long",
    ];

    /**
     * @var string
     */
    protected $_encoding = 'UTF-8';

    /**
     * @inheritdoc
     */
    public function setEncoding($encoding = null)
    {
        if ($encoding !== null) {
            $orig = ini_get('default_charset');
            ini_set('default_charset', $encoding);
            if (!ini_get('default_charset')) {
                throw new ValidateException('Given encoding not supported on this OS!');
            }
            ini_set('default_charset', $orig);
        }

        $this->_encoding = $encoding;
        return $this;
    }
}
