/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define(
    [
        'Magento_Ui/js/form/element/select',
        'uiRegistry'
    ],
    function (Abstract, reg) {
        'use strict';

        return Abstract.extend(
            {
                defaults: {
                    valuesForOptions: [],
                    sourceOptions: null,
                    isShown: false,
                    inverseVisibility: false,
                    visible: false
                },

                toggleVisibility: function (isShown) {
                    this.isShown = isShown !== '0';
                    this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
                    if (!this.visible()) {
                        this.value('');
                    }
                    if (this.isShown && typeof this.entityValues !== 'undefined') {
                        var entity = reg.get(this.parentName + '.entity');
                        if (entity !== undefined) {
                            this.toggleByEntity(entity.value());
                        }
                    }
                },

                toggleByEntity: function (selected) {
                    this.isShown = selected in this.entityValues;
                    this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
                    var lastExportType = reg.get(this.parentName + '.enable_last_entity_id');
                    if (this.isShown && lastExportType !== undefined && lastExportType.value() == '0') {
                        this.visible(false);
                    }
                }
            }
        )
    }
);
