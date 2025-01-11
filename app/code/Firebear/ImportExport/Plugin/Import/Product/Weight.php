<?php

namespace Firebear\ImportExport\Plugin\Import\Product;

use Firebear\ImportExport\Model\Import\Product;

class Weight
{
    /**
     * Convert Weight to appropriate format
     *
     * @param Product $subject
     * @param array $rowData
     * @return array
     */
    public function beforeCustomChangeData(
        Product $subject,
        array $rowData
    ): array {
        $jobParameters = $subject->getParameters();
        if (!empty($rowData['weight']) && !empty($jobParameters['weight_factor'])) {
            $factor = (float) $jobParameters['weight_factor'];
            $rowData['weight'] *= $factor;
        }
        return [$rowData];
    }
}
