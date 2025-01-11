/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

define([
    'Magento_Ui/js/form/element/single-checkbox',
    'uiRegistry'
], function (SingleCheckbox, reg) {
    'use strict';

    return SingleCheckbox.extend({
        defaults: {
            valueMap: {
                'true': '1',
                'false': '0'
            },
            prefer: 'toggle',
            isShown: false,
            inverseVisibility: false,
            visible:false,
            toogleByEntityFlag:false
        },
        toggleVisibility: function (selected) {
            this.isShown = selected in this.valuesForOptions;
            this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
            if (this.isShown && typeof this.entityValues !== 'undefined') {
                var entity = reg.get(this.ns + '.' + this.ns + '.settings.entity');
                if (entity !== undefined && !this.toogleByEntityFlag) {
                    this.toggleByEntity(entity.value());
                }
            }
        },

        toggleByEntity: function (selected) {
            this.toogleByEntityFlag = true;
            this.isShown = selected in this.entityValues;
            this.visible(this.inverseVisibility ? !this.isShown : this.isShown);
            let visibilitySource = this.imports.toggleVisibility;
            if ((typeof visibilitySource !== 'undefined')
                && (visibilitySource == 'import_export_job_form.import_export_job_form.behavior.behavior_field_file_format:value')) {
                if (this.isShown && typeof this.valuesForOptions !== 'undefined') {
                    let fileFormat = reg.get(this.ns + '.' + this.ns + '.behavior.behavior_field_file_format');
                    if (fileFormat !== undefined) {
                        this.toggleVisibility(fileFormat.value());
                    }
                }
            }
        },

        toggleBySource: function (selected) {
            if (!selected || selected === undefined) {
                return;
            }
            this.toggleVisibility(selected);
        },

        toggleByPlatform: function (selected) {
            if (!selected || selected === undefined) {
                this.toggleVisibility('file');
                return;
            }

            var entity = reg.get(this.ns + '.' + this.ns + '.settings.entity');
            if (entity === undefined) {
                this.toggleVisibility(selected);
                return;
            }

            var value = entity.value() + '_' + selected;
            value = value in this.platformForm ? value : 'file';
            this.toggleVisibility(value);
        }
    });
});
