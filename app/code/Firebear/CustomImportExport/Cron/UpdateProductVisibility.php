<?php

namespace Firebear\CustomImportExport\Cron;
use Magento\Framework\App\ResourceConnection;

class UpdateProductVisibility
{
    protected $resourceConnection;
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function execute()
    {
        $connection = $this->resourceConnection->getConnection();
        // $table is table name
        $table = $connection->getTableName('catalog_product_entity_int');
        $query = "UPDATE `" . $table . "` SET `value`= 4 WHERE value = 2 and attribute_id = 99";
        $connection->query($query);
    }
}