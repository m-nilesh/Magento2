/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */
define(
    [
        'jquery',
        'underscore',
        'Firebear_ImportExport/js/form/element/select',
    ],
    function ($, _, FBSelect) {
        'use strict';
        return FBSelect.extend(
            {
                defaults: {
                    isShown: false,
                    visible: false,
                    sourceExt       : null,
                    sourceOptions: null,
                    imports      : {
                        changeSource: '${$.parentName}.source_data_entity:value',
                        toggleVisibility: '${$.ns}.${$.ns}.settings.entity:value'
                    },
                    ajaxUrl: '',
                },
                toggleVisibility: function (selected) {
                    this.isShown = (selected in this.valuesForOptions);
                    this.visible(this.isShown ? true : false)
                },
            }
        )
    }
);