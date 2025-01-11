<?php
namespace Firebear\CustomImportExport\Model\Import;

class Product 
{
	protected function uploadMediaFiles($fileName, $renameFileOff = false, $existingUpload = [])
    {
        $fileName = '/'.$fileName;
        try {
            $result = $this->_getUploader()->move($fileName, $renameFileOff, $existingUpload);
            return $result['file'];
        } catch (\Exception $e) {
            $this->addLogWriteln($e->getMessage(), $this->getOutput(), 'error');
            return '';
        }
    }
}