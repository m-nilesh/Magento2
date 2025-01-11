/**
 * select-system-attr
 *
 * @copyright Copyright © 2020 Firebear Studio. All rights reserved.
 * @author    Firebear Studio <fbeardev@gmail.com>
 */
define([
    'jquery',
    'underscore',
    'Magento_Ui/js/form/element/select',
    'uiRegistry',
    'domReady!'
], function ($, _, Abstract, reg) {
    'use strict';

    /**
     * Select dropdown ajax for systemAttributes
     */
    return Abstract.extend({
        defaults: {
            sourceExt: null,
            sourceOptions: null,
            imports: {
                changeSource: '${$.parentName}.source_data_entity:value'
            },
            ajaxUrl: '',
        },

        /**
         *
         * @returns {initialize}
         */
        initialize: function () {
            var self = this;
            this._super();

            self.value.subscribe(function () {
                self.updateValueOptions();
            });

            return this;
        },

        getInitialValue: function () {
            var normalizedValue = this._super();
            if (normalizedValue === undefined) {
                return this.value();
            }
            return normalizedValue;
        },

        setInitialValue: function () {
            this.initialValue = this.getInitialValue();

            this.value(this.initialValue);

            this.on('value', this.onUpdate.bind(this));
            this.isUseDefault(this.disabled());

            return this;
        },

        /**
         *
         * @returns {updateValueOptions}
         */
        updateValueOptions: function () {
            var self = this;
            var exportAttr = reg.get(this.parentName + '.source_data_export');
            if (self.value()) {
                if (!exportAttr.value() && exportAttr.initialValue) {
                    exportAttr.value(exportAttr.initialValue);
                } else if (!exportAttr.value() && !exportAttr.initialValue) {
                    exportAttr.value(self.value());
                }
            } else {
                exportAttr.value('');
            }
            return this;
        },

        /**
         *
         * @param config
         * @returns {*}
         */
        initConfig: function (config) {
            this._super();
            this.sourceOptions = JSON.parse(this.sourceOptions);
            return this;
        },

        /**
         *
         * @param entityValue
         */
        changeSource: function (entityValue) {
            var self = this;
            var oldValue = self.value();

            var systemAtrributesData = JSON.parse(localStorage.getItem('system_attributes_data'));
            if (systemAtrributesData === null) {
                systemAtrributesData = {};
            }
            systemAtrributesData[self.inputName] = oldValue;

            localStorage.setItem('system_attributes_data',JSON.stringify(systemAtrributesData));

            var parent = reg.get(this.ns + '.' + this.ns + '.source_data_map_container.source_data_map');
            parent.showSpinner(true);
            var param = {
                entity: entityValue,
                form_key: window.FORM_KEY
            };

            var loadedEntity = JSON.parse(localStorage.getItem('loaded_entity'));

            if (loadedEntity == null) {
                loadedEntity = {};
            }

            if (!(entityValue in loadedEntity)) {
                $.ajax({
                    type: "POST",
                    url: this.ajaxUrl,
                    data: param,
                    success: function (response, status) {
                        if (status === "success") {
                            var newData = JSON.parse(localStorage.getItem('list_values'));
                            if (newData === null) {
                                newData = {};
                            }
                            newData[entityValue] = response;
                            localStorage.setItem('list_values', JSON.stringify(newData));
                            self.updateSystemAttributesData(response, self, param.entity);
                            parent.showSpinner(false);
                        }
                    }
                });
                loadedEntity[entityValue] = entityValue;
                localStorage.setItem('loaded_entity',JSON.stringify(loadedEntity));
            }
        },

        /**
         *
         * @param response
         * @param self
         */
        updateSystemAttributesData: function (response, self, entity) {
            let mappingData = reg.get(self.ns + '.' + self.ns + '.source_data_map_container.source_data_map')._elems;

            for (var i = 0; i < mappingData.length; i++) {
                let recordItems = mappingData[i]._elems;
                if (typeof recordItems !== 'undefined') {
                    for (let key in recordItems) {
                        if (!isNaN(key)) {
                            let recordItem = reg.get(recordItems[key]);

                            if (typeof recordItem == 'object'){
                                if (recordItem.index == 'source_data_entity') {
                                    var sourceDataEntity = recordItem.value();
                                }

                                var systemAtrributesData = JSON.parse(localStorage.getItem('system_attributes_data'));
                                let recordValue = systemAtrributesData[recordItem.inputName];

                                if (sourceDataEntity == entity
                                    && recordItem.index == 'source_data_system'){
                                    recordItem.setOptions(response);
                                    recordItem.value(recordValue);
                                    sourceDataEntity = false;
                                }
                            }
                        }
                    }
                }
            }
        }
    });
});
