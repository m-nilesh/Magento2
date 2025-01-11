/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define(
    [
        'jquery',
        'underscore',
        'Magento_Ui/js/form/element/multiselect',
        'uiRegistry'
    ],
    function ($, _, Abstract, reg) {
        'use strict';

        return Abstract.extend(
            {
                defaults: {
                    isShown: false,
                    visible: false,
                    imports      : {
                        changeSource: '${$.ns}.${$.ns}.settings.entity:value'
                    },
                },

                toggleVisibility: function (isShown) {
                    this.isShown = isShown == '4';
                    this.visible(this.isShown);
                    if (!this.visible()) {
                        this.value('');
                    }
                },

                changeSource: function (value) {
                    let updateExistingProducts = reg.get(
                        'import_job_form.import_job_form.settings.update_existing_products'
                    );
                    let updateExistingProductsValue = 0;
                    if (updateExistingProducts !== undefined) {
                        updateExistingProductsValue = updateExistingProducts.value();
                    }
                    if (value !== 'catalog_product') {
                        this.isShown = false;
                    } else if (updateExistingProductsValue == '4') {
                        this.isShown = true;
                    }
                    this.visible(this.isShown);
                    if (!this.visible()) {
                        this.value('');
                    }
                },
            }
        )
    }
);
