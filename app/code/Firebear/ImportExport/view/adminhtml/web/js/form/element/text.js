/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    'Magento_Ui/js/form/element/abstract'
], function (TextElement) {
    'use strict';

    return TextElement.extend({
        defaults: {
            elementTmpl: 'Firebear_ImportExport/form/element/input',
            valuesForOptions: [],
            sourceOptions: null,
            isShown: false,
            inverseVisibility: false,
            imports: {
                toggleVisibility: '${$.ns}.${$.ns}.settings.entity:value'
            },
            visible: false
        },
        toggleVisibility: function (selected) {
            this.isShown = selected in this.valuesForOptions;
            this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
        },
    });
});
