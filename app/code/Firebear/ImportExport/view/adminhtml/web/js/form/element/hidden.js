/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define(
    [
        'underscore',
        'Magento_Ui/js/form/element/abstract',
        'uiRegistry'
    ],
    function (_, Abstract, reg) {
        'use strict';

        return Abstract.extend(
            {
                setInitialValue: function () {
                    this.initialValue = this.getInitialValue();
                    let self = this;
                    let category = reg.get(this.parentName + '.source_category_data_new');
                    let options = category.options()
                    _.each(options, function (item) {
                        if (item.value == category.value() && typeof item.id !== 'undefined') {
                            self.initialValue = item.id;
                        }
                    });

                    if (this.value.peek() !== this.initialValue) {
                        this.value(this.initialValue);
                    }

                    this.on('value', this.onUpdate.bind(this));
                    this.isUseDefault(this.disabled());
                    return this;
                },
            }
        );
    }
);
