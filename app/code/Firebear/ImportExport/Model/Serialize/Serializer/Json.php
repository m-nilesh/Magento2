<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Model\Serialize\Serializer;

use Magento\Framework\Serialize\Serializer\Json as CoreJson;

/**
 * Model Json
 */
class Json extends CoreJson
{
    /**
     * Serialize
     *
     * @param array|bool|float|int|string|null $data
     * @return bool|string
     */
    public function serialize($data)
    {
        $jsonString = '';
        try {
            $jsonString = parent::serialize($data);
        } catch (\Exception $exception) {
            if (json_last_error() === JSON_ERROR_UTF8) {
                $jsonString = json_encode($data, JSON_INVALID_UTF8_IGNORE);
            }
        }
        return $jsonString;
    }

    /**
     * Unserialize
     *
     * @param string $string
     * @return array|bool|float|int|mixed|string|null
     */
    public function unserialize($string)
    {
        if ($string === '') {
            $jsonDecode = [];
        } else {
            $jsonDecode = parent::unserialize($string);
        }
        return $jsonDecode;
    }
}
