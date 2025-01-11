/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define(
    [
        'underscore',
        'jquery',
        'mageUtils',
        'uiRegistry',
        'Magento_Ui/js/form/element/abstract',
        'uiLayout'
    ],
    function (_, $, utils, registry, Abstract, layout) {
        'use strict';

        return Abstract.extend(
            {
                defaults: {
                    valuesForOptions: [],
                },
                onChange: function (value) {
                    var bool = value in this.valuesForOptions;
                    if (bool) {
                        this.validation.max_text_length = 100;
                    } else {
                        this.validation.max_text_length = 2;
                    }
                    if (this.value()) {
                        this.validate();
                    }
                },

                /**
                 * Toggle visibility state.
                 *
                 * @param {Number} selected
                 */
                toggleVisibility: function (selected) {
                    this.isShown = (selected in this.valuesForOptions);
                    this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
                }
            }
        );
    }
);
