<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model;

use Magento\Framework\Filesystem;

class LocalFilesystem extends Filesystem
{
    /**
     * Retrieve uri for given code
     *
     * @param string $code
     * @return string
     */
    public function getUri($code)
    {
        return $this->directoryList->getUrlPath($code);
    }
}
