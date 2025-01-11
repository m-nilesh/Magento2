/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define(
    [
        'jquery',
        'Magento_Ui/js/form/element/select',
        'Firebear_ImportExport/js/form/element/general',
        'uiRegistry'
    ],
    function ($, Abstract, general, reg) {
        'use strict';

        return Abstract.extend(general).extend(
            {
                defaults: {
                    valuesForOptions: [],
                    sourceOptions: null,
                    isShown: true,
                    inverseVisibility: false,
                    visible: true,
                    entity: '${$.parentName}.source_filter_entity',
                    filterTypeMapping: {
                        'text': [
                            {
                                'value': 'contains',
                                'label': 'Contains'
                            },
                            {
                                'value': 'not_contains',
                                'label': 'Not contains'
                            }
                        ],
                        'not': [
                            {
                                'value': 'contains',
                                'label': 'Contains'
                            },
                            {
                                'value': 'not_contains',
                                'label': 'Not contains'
                            }
                        ],
                        'select': [
                            {
                                'value': 'equal',
                                'label': 'Equal'
                            },
                            {
                                'value': 'not_equal',
                                'label': 'Not equal'
                            }
                        ],
                        'int': [
                            {
                                'value': 'equal',
                                'label': 'Equal'
                            },
                            {
                                'value': 'not_equal',
                                'label': 'Not equal'
                            },
                            {
                                'value': 'more_or_equal',
                                'label': 'More or equal'
                            },
                            {
                                'value': 'less_or_equal',
                                'label': 'Less or equal'
                            },
                            {
                                'value': 'range_int',
                                'label': 'Range'
                            },
                        ],
                        'date': [
                            {
                                'value': 'more_or_equal_date',
                                'label': 'More or equal'
                            },
                            {
                                'value': 'less_or_equal_date',
                                'label': 'Less or equal'
                            },
                            {
                                'value': 'range_date',
                                'label': 'Range'
                            }
                        ],
                        'range': [
                            {
                                'value': 'range',
                                'label': 'Range'
                            }
                        ]
                    }
                },

                changeSource: function (value) {
                    var data = JSON.parse(localStorage.getItem('list_filtres')),
                    exists = 0,
                    entity = reg.get(this.entity),
                    self = this,
                    attributeType = 'text',
                    oldValue = this.value();

                    if (data !== null && typeof data === 'object') {
                        if (value in data) {
                            exists = 1;
                            if (typeof data[value]['type'] !== 'undefined') {
                                attributeType = data[value]['type'];
                            }
                        }
                    }
                    var type = 'attr';
                    if (exists == 0 && typeof value != 'undefined') {
                        $.ajax({
                            type: "POST",
                            url: this.ajaxUrl,
                            data: {entity: entity.value(), attribute: value, type: type},
                            success: function (array) {
                                var newData = JSON.parse(localStorage.getItem('list_filtres'));
                                if (newData === null) {
                                    newData = {};
                                }
                                newData[value] = array;
                                localStorage.setItem('list_filtres', JSON.stringify(newData));
                                self.setOptions([]);
                                self.setOptions(self.filterTypeMapping[array.type]);
                                self.value(oldValue);
                            }
                        });
                    } else {
                        self.setOptions([]);
                        self.setOptions(self.filterTypeMapping[attributeType]);
                        self.value(oldValue);
                    }
                }
            }
        )
    }
);
