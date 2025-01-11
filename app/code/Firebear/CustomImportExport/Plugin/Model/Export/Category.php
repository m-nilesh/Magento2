<?php
namespace Firebear\CustomImportExport\Plugin\Model\Export;
class Category
{    
    public function after_getHeaderColumns(\Firebear\ImportExport\Model\Export\Category $subject, $result)
    {
        if(is_array($result)) {
            array_unshift($result, "entity_id");
        }
        return $result;
    }
}