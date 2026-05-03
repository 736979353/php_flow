define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var fieldTypes = {
        text: '信息填写',
        textarea: '多行文本',
        select: '下拉选择',
        radio: '单选',
        checkbox: '多选',
        file: '附件上传',
        datetime: '时间',
        array: '数组明细'
    };
    var childTypes = {
        text: '信息填写',
        textarea: '多行文本',
        select: '下拉选择',
        radio: '单选',
        checkbox: '多选',
        file: '附件上传',
        datetime: '时间'
    };
    var choiceTypes = ['select', 'radio', 'checkbox'];

    var Controller = {
        index: function () {
            $('.btn-add,.btn-edit').attr('data-area', '["1180px","88%"]');
            Table.api.init({
                extend: {
                    index_url: 'tradeflow/form/index' + location.search,
                    add_url: 'tradeflow/form/add',
                    edit_url: 'tradeflow/form/edit',
                    del_url: 'tradeflow/form/del',
                    multi_url: 'tradeflow/form/multi',
                    table: 'tradeflow_form'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'name', title: '模板名称', operate: 'LIKE'},
                    {field: 'description', title: '说明', operate: 'LIKE', formatter: Table.api.formatter.content},
                    {field: 'status', title: '状态', searchList: {0: '禁用', 1: '启用'}, formatter: Controller.api.formatter.status},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
                        events: Table.api.events.operate,
                        buttons: [
                            $.extend({}, Table.button.edit, {extend: 'data-toggle="tooltip" data-container="body" data-area=\'["1180px","88%"]\''}),
                            Table.button.del
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindFields([]);
            Form.api.bindevent($('form[role=form]'), null, null, function () {
                Controller.api.collectFields();
                return true;
            });
        },
        edit: function () {
            var fields = [];
            try {
                fields = JSON.parse($('#fields-data').val() || '[]') || [];
            } catch (e) {}
            Controller.api.bindFields(fields);
            Form.api.bindevent($('form[role=form]'), null, null, function () {
                Controller.api.collectFields();
                return true;
            });
        },
        api: {
            fields: [],
            activeIndex: -1,
            draggingIndex: null,
            bindFields: function (fields) {
                Controller.api.injectStyle();
                Controller.api.fields = $.map(fields || [], function (field, index) {
                    return Controller.api.normalizeField(field, index);
                });
                Controller.api.activeIndex = Controller.api.fields.length ? 0 : -1;
                Controller.api.renderPalette();
                Controller.api.renderCanvas();
                Controller.api.renderInspector();
                Controller.api.collectFields();
            },
            injectStyle: function () {
                if ($('#tf-form-designer-style').length) {
                    return;
                }
                $('head').append(
                    '<style id="tf-form-designer-style">' +
                    '.tf-template-form{padding-top:10px}' +
                    '.tf-template-form .form-group{margin-bottom:12px}' +
                    '.tf-form-designer{display:grid;grid-template-columns:170px minmax(360px,1fr) 330px;gap:14px;align-items:stretch;max-width:1120px}' +
                    '.tf-form-palette,.tf-form-canvas-wrap,.tf-form-inspector{border:1px solid #e6e8eb;background:#fff;border-radius:6px;min-height:120px;box-shadow:0 1px 2px rgba(0,0,0,.03)}' +
                    '.tf-form-palette,.tf-form-inspector{padding:12px}' +
                    '.tf-form-canvas-wrap{padding:12px;background:#f7f9fb}' +
                    '.tf-panel-title{font-weight:600;color:#222;margin-bottom:10px;line-height:20px}' +
                    '.tf-panel-title:before{content:"";display:inline-block;width:3px;height:14px;background:#18bc9c;border-radius:2px;margin-right:7px;vertical-align:-2px}' +
                    '.tf-palette-item{display:flex;align-items:center;justify-content:space-between;width:100%;height:36px;margin-bottom:8px;text-align:left;border-color:#dfe3e8;background:#fff}' +
                    '.tf-palette-item:hover{border-color:#18bc9c;color:#18bc9c;background:#f8fffd}' +
                    '.tf-palette-item .fa{color:#9aa3ad}' +
                    '.tf-form-canvas{min-height:410px;max-height:58vh;overflow:auto;border:1px dashed #cfd8dc;background:#fff;border-radius:6px;padding:12px}' +
                    '.tf-form-inspector{max-height:calc(58vh + 44px);overflow:auto}' +
                    '.tf-empty-canvas{height:360px;display:flex;align-items:center;justify-content:center;color:#8a929a;text-align:center;background:#fbfcfd;border-radius:4px}' +
                    '.tf-field-card{background:#fff;border:1px solid #dfe3e8;border-radius:6px;margin-bottom:10px;padding:12px;cursor:pointer;transition:border-color .15s,box-shadow .15s,background .15s}' +
                    '.tf-field-card:hover{border-color:#b8c4ce;background:#fcfefe}' +
                    '.tf-field-card.active{border-color:#18bc9c;box-shadow:0 0 0 2px rgba(24,188,156,.12)}' +
                    '.tf-field-card.dragging{opacity:.45}' +
                    '.tf-field-card-head{display:flex;align-items:center;gap:8px}' +
                    '.tf-field-drag{color:#aaa;cursor:move}' +
                    '.tf-field-title{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
                    '.tf-field-meta{margin-top:8px;color:#888;font-size:12px}' +
                    '.tf-field-badge{display:inline-block;background:#f0f3f5;border-radius:3px;padding:2px 7px;margin-right:4px;color:#66717c}' +
                    '.tf-inspector-empty{color:#999;padding:40px 0;text-align:center}' +
                    '.tf-inspector-section{border-top:1px solid #eee;margin-top:10px;padding-top:10px}' +
                    '#tf-field-inspector{overflow:hidden}' +
                    '#tf-field-inspector .form-group{margin:0 0 12px 0}' +
                    '#tf-field-inspector label{display:block;margin-bottom:6px;font-weight:600;color:#333}' +
                    '#tf-field-inspector .form-control{width:100%!important;max-width:100%;box-sizing:border-box}' +
                    '#tf-field-inspector .checkbox{margin:8px 0 0 0}' +
                    '#tf-field-inspector .checkbox label{display:inline-block;font-weight:400;margin-bottom:0}' +
                    '.tf-option-row,.tf-child-option-row{display:flex;align-items:center;gap:6px;margin-bottom:6px}' +
                    '.tf-option-row .form-control,.tf-child-option-row .form-control{flex:1 1 auto;min-width:0}' +
                    '.tf-array-child-card{border:1px solid #e5e8eb;background:#fafafa;border-radius:6px;padding:8px;margin-bottom:8px}' +
                    '.tf-array-child-grid{display:grid;grid-template-columns:1fr 112px 32px;gap:6px;align-items:center}' +
                    '.tf-array-child-content{margin-top:6px}' +
                    '.tf-form-designer .btn-icon{width:30px;height:30px;padding:0;line-height:30px}' +
                    '@media(max-width:1100px){.tf-form-designer{grid-template-columns:160px minmax(320px,1fr)}.tf-form-inspector{grid-column:1 / -1;max-height:none}}' +
                    '@media(max-width:760px){.tf-form-designer{grid-template-columns:1fr}.tf-form-canvas,.tf-form-inspector{max-height:none}}' +
                    '</style>'
                );
            },
            renderPalette: function () {
                var $palette = $('#tf-field-palette').empty();
                $.each(fieldTypes, function (type, title) {
                    var $item = $('<button type="button" class="btn btn-default btn-sm tf-palette-item" draggable="true"></button>');
                    $item.attr('data-type', type).html('<span>' + title + '</span><i class="fa fa-plus"></i>');
                    $palette.append($item);
                });
                $palette.off('.tfdesigner')
                    .on('click.tfdesigner', '.tf-palette-item', function () {
                        Controller.api.addField($(this).data('type'));
                    })
                    .on('dragstart.tfdesigner', '.tf-palette-item', function (e) {
                        e.originalEvent.dataTransfer.setData('field-type', $(this).data('type'));
                    });
            },
            renderCanvas: function () {
                var api = Controller.api;
                var $canvas = $('#tf-template-fields').empty();
                if (!api.fields.length) {
                    $canvas.append('<div class="tf-empty-canvas">从左侧拖入字段，或点击字段组件添加</div>');
                }
                $.each(api.fields, function (index, field) {
                    var $card = $('<div class="tf-field-card" draggable="true"></div>');
                    $card.attr('data-index', index).toggleClass('active', index === api.activeIndex);
                    $card.append(
                        '<div class="tf-field-card-head">' +
                        '<i class="fa fa-bars tf-field-drag"></i>' +
                        '<div class="tf-field-title">' + api.escapeHtml(field.label || fieldTypes[field.type] || '字段') + '</div>' +
                        '<button type="button" class="btn btn-danger btn-xs btn-icon tf-field-remove" title="删除"><i class="fa fa-trash"></i></button>' +
                        '</div>'
                    );
                    $card.append(
                        '<div class="tf-field-meta">' +
                        '<span class="tf-field-badge">' + api.escapeHtml(fieldTypes[field.type] || field.type) + '</span>' +
                        (field.required ? '<span class="tf-field-badge">必填</span>' : '') +
                        (field.type === 'array' ? '<span class="tf-field-badge">' + (field.children || []).length + ' 个子字段</span>' : '') +
                        '</div>'
                    );
                    $canvas.append($card);
                });
                $canvas.off('.tfdesigner')
                    .on('click.tfdesigner', '.tf-field-card', function () {
                        api.activeIndex = parseInt($(this).attr('data-index'), 10);
                        api.renderCanvas();
                        api.renderInspector();
                    })
                    .on('click.tfdesigner', '.tf-field-remove', function (e) {
                        e.stopPropagation();
                        var index = parseInt($(this).closest('.tf-field-card').attr('data-index'), 10);
                        api.fields.splice(index, 1);
                        api.activeIndex = api.fields.length ? Math.min(index, api.fields.length - 1) : -1;
                        api.renderCanvas();
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('dragstart.tfdesigner', '.tf-field-card', function (e) {
                        api.draggingIndex = parseInt($(this).attr('data-index'), 10);
                        $(this).addClass('dragging');
                        e.originalEvent.dataTransfer.setData('card-index', api.draggingIndex);
                    })
                    .on('dragend.tfdesigner', '.tf-field-card', function () {
                        api.draggingIndex = null;
                        $(this).removeClass('dragging');
                    })
                    .on('dragover.tfdesigner', function (e) {
                        e.preventDefault();
                    })
                    .on('drop.tfdesigner', function (e) {
                        e.preventDefault();
                        var fieldType = e.originalEvent.dataTransfer.getData('field-type');
                        var cardIndex = e.originalEvent.dataTransfer.getData('card-index');
                        var targetIndex = api.dropIndex(e, $canvas);
                        if (fieldType) {
                            api.addField(fieldType, targetIndex);
                        } else if (cardIndex !== '') {
                            api.moveField(parseInt(cardIndex, 10), targetIndex);
                        }
                    });
            },
            dropIndex: function (e, $canvas) {
                var y = e.originalEvent.clientY;
                var index = Controller.api.fields.length;
                $canvas.find('.tf-field-card').each(function () {
                    var rect = this.getBoundingClientRect();
                    if (y < rect.top + rect.height / 2) {
                        index = parseInt($(this).attr('data-index'), 10);
                        return false;
                    }
                });
                return index;
            },
            renderInspector: function () {
                var api = Controller.api;
                var $box = $('#tf-field-inspector').empty();
                var field = api.fields[api.activeIndex];
                if (!field) {
                    $box.append('<div class="tf-inspector-empty">请选择画布中的字段</div>');
                    return;
                }
                $box.append('<div class="form-group"><label>标题</label><input class="form-control tf-inspector-label" value="' + api.escapeHtml(field.label || '') + '" placeholder="字段标题"></div>');
                var $type = $('<select class="form-control tf-inspector-type"></select>');
                $.each(fieldTypes, function (key, text) {
                    $type.append('<option value="' + key + '">' + text + '</option>');
                });
                $type.val(field.type);
                $box.append($('<div class="form-group"><label>类型</label></div>').append($type));
                $box.append('<div class="checkbox"><label><input type="checkbox" class="tf-inspector-required" ' + (field.required ? 'checked' : '') + '> 必填</label></div>');
                if (api.isChoiceType(field.type)) {
                    $box.append(api.renderOptions(field.options || []));
                }
                if (field.type === 'array') {
                    $box.append(api.renderArrayChildren(field.children || []));
                }
                $box.off('.tfdesigner')
                    .on('keyup.tfdesigner change.tfdesigner', '.tf-inspector-label', function () {
                        field.label = $.trim($(this).val());
                        api.renderCanvas();
                        api.collectFields();
                    })
                    .on('change.tfdesigner', '.tf-inspector-type', function () {
                        field.type = $(this).val();
                        if (!api.isChoiceType(field.type)) {
                            field.options = [];
                        } else if (!field.options || !field.options.length) {
                            field.options = ['选项1'];
                        }
                        if (field.type === 'array' && (!field.children || !field.children.length)) {
                            field.children = [api.normalizeChild({label: '内容', type: 'text'}, 0)];
                        }
                        api.renderCanvas();
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('change.tfdesigner', '.tf-inspector-required', function () {
                        field.required = $(this).prop('checked') ? 1 : 0;
                        api.renderCanvas();
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-option-add', function () {
                        field.options = field.options || [];
                        field.options.push('');
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('keyup.tfdesigner change.tfdesigner', '.tf-option-input', function () {
                        field.options[$(this).closest('.tf-option-row').data('index')] = $.trim($(this).val());
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-option-remove', function () {
                        field.options.splice($(this).closest('.tf-option-row').data('index'), 1);
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-array-child-add', function () {
                        field.children = field.children || [];
                        field.children.push(api.normalizeChild({label: '子字段', type: 'text'}, field.children.length));
                        api.renderInspector();
                        api.renderCanvas();
                        api.collectFields();
                    })
                    .on('keyup.tfdesigner change.tfdesigner', '.tf-array-child-label', function () {
                        api.childByRow($(this)).label = $.trim($(this).val());
                        api.collectFields();
                    })
                    .on('change.tfdesigner', '.tf-array-child-type', function () {
                        var child = api.childByRow($(this));
                        child.type = $(this).val();
                        if (!api.isChoiceType(child.type)) {
                            child.options = [];
                        } else if (!child.options || !child.options.length) {
                            child.options = ['选项1'];
                        }
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('keyup.tfdesigner change.tfdesigner', '.tf-array-child-content-input', function () {
                        api.childByRow($(this)).content = $.trim($(this).val());
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-array-child-remove', function () {
                        field.children.splice($(this).closest('.tf-array-child-card').data('index'), 1);
                        api.renderInspector();
                        api.renderCanvas();
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-child-option-add', function () {
                        var child = api.childByRow($(this));
                        child.options = child.options || [];
                        child.options.push('');
                        api.renderInspector();
                        api.collectFields();
                    })
                    .on('keyup.tfdesigner change.tfdesigner', '.tf-child-option-input', function () {
                        var $row = $(this).closest('.tf-child-option-row');
                        var child = api.childByRow($(this));
                        child.options[$row.data('option-index')] = $.trim($(this).val());
                        api.collectFields();
                    })
                    .on('click.tfdesigner', '.tf-child-option-remove', function () {
                        var $row = $(this).closest('.tf-child-option-row');
                        var child = api.childByRow($(this));
                        child.options.splice($row.data('option-index'), 1);
                        api.renderInspector();
                        api.collectFields();
                    });
            },
            renderOptions: function (options) {
                var $section = $('<div class="tf-inspector-section"><label>选项</label><div class="tf-option-list"></div></div>');
                $.each(options && options.length ? options : [''], function (index, option) {
                    $section.find('.tf-option-list').append(
                        '<div class="tf-option-row" data-index="' + index + '">' +
                        '<input class="form-control tf-option-input" placeholder="选项名称" value="' + Controller.api.escapeHtml(option || '') + '">' +
                        '<button type="button" class="btn btn-danger btn-xs btn-icon tf-option-remove"><i class="fa fa-trash"></i></button>' +
                        '</div>'
                    );
                });
                $section.append('<button type="button" class="btn btn-default btn-xs tf-option-add"><i class="fa fa-plus"></i> 添加选项</button>');
                return $section;
            },
            renderArrayChildren: function (children) {
                var api = Controller.api;
                var $section = $('<div class="tf-inspector-section"><label>数组子字段</label><div class="tf-array-child-list"></div></div>');
                $.each(children && children.length ? children : [], function (index, child) {
                    var $card = $('<div class="tf-array-child-card" data-index="' + index + '"></div>');
                    var $type = $('<select class="form-control tf-array-child-type"></select>');
                    $.each(childTypes, function (key, text) {
                        $type.append('<option value="' + key + '">' + text + '</option>');
                    });
                    $type.val(child.type || 'text');
                    $card.append(
                        $('<div class="tf-array-child-grid"></div>')
                            .append('<input class="form-control tf-array-child-label" placeholder="标题" value="' + api.escapeHtml(child.label || '') + '">')
                            .append($type)
                            .append('<button type="button" class="btn btn-danger btn-xs btn-icon tf-array-child-remove"><i class="fa fa-trash"></i></button>')
                    );
                    if (api.isChoiceType(child.type)) {
                        $card.append(api.renderChildOptions(child.options || api.contentOptions(child.content || '')));
                    } else {
                        $card.append('<div class="tf-array-child-content"><input class="form-control tf-array-child-content-input" placeholder="默认内容，可为空" value="' + api.escapeHtml(child.content || '') + '"></div>');
                    }
                    $section.find('.tf-array-child-list').append($card);
                });
                $section.append('<button type="button" class="btn btn-default btn-xs tf-array-child-add"><i class="fa fa-plus"></i> 添加子字段</button>');
                return $section;
            },
            renderChildOptions: function (options) {
                var $box = $('<div class="tf-array-child-content"><div class="tf-child-option-list"></div></div>');
                $.each(options && options.length ? options : [''], function (index, option) {
                    $box.find('.tf-child-option-list').append(
                        '<div class="tf-child-option-row" data-option-index="' + index + '">' +
                        '<input class="form-control tf-child-option-input" placeholder="选项名称" value="' + Controller.api.escapeHtml(option || '') + '">' +
                        '<button type="button" class="btn btn-danger btn-xs btn-icon tf-child-option-remove"><i class="fa fa-trash"></i></button>' +
                        '</div>'
                    );
                });
                $box.append('<button type="button" class="btn btn-default btn-xs tf-child-option-add"><i class="fa fa-plus"></i> 添加选项</button>');
                return $box;
            },
            addField: function (type, index) {
                var api = Controller.api;
                var field = api.normalizeField({
                    label: fieldTypes[type] || '字段',
                    type: type,
                    required: 0,
                    options: api.isChoiceType(type) ? ['选项1', '选项2'] : [],
                    children: type === 'array' ? [api.normalizeChild({label: '内容', type: 'text'}, 0)] : []
                }, api.fields.length);
                if (typeof index === 'number' && index >= 0 && index < api.fields.length) {
                    api.fields.splice(index, 0, field);
                    api.activeIndex = index;
                } else {
                    api.fields.push(field);
                    api.activeIndex = api.fields.length - 1;
                }
                api.renderCanvas();
                api.renderInspector();
                api.collectFields();
            },
            moveField: function (from, to) {
                var api = Controller.api;
                if (from === to || from < 0 || from >= api.fields.length) {
                    return;
                }
                if (to > api.fields.length) {
                    to = api.fields.length;
                }
                var field = api.fields.splice(from, 1)[0];
                if (to > from) {
                    to -= 1;
                }
                api.fields.splice(to, 0, field);
                api.activeIndex = to;
                api.renderCanvas();
                api.renderInspector();
                api.collectFields();
            },
            normalizeField: function (field, index) {
                field = field || {};
                var type = field.type || 'text';
                var normalized = {
                    label: field.label || fieldTypes[type] || '字段',
                    name: field.name || '',
                    type: type,
                    options: $.isArray(field.options) ? field.options : [],
                    required: field.required ? 1 : 0,
                    children: []
                };
                if (type === 'array') {
                    var children = field.children || Controller.api.legacyChildren(field.options || []);
                    normalized.children = $.map(children && children.length ? children : [{label: '内容', type: 'text'}], function (child, childIndex) {
                        return Controller.api.normalizeChild(child, childIndex);
                    });
                    normalized.options = [];
                }
                if (!normalized.name) {
                    normalized.name = Controller.api.makeFieldName(normalized.label, index);
                }
                return normalized;
            },
            normalizeChild: function (child, index) {
                child = child || {};
                var type = child.type || 'text';
                var options = $.isArray(child.options) ? child.options : Controller.api.contentOptions(child.content || '');
                return {
                    label: child.label || '内容',
                    name: child.name || Controller.api.makeFieldName(child.label || '内容', index).replace(/^field_/, 'sub_'),
                    type: type,
                    content: child.content || '',
                    options: Controller.api.isChoiceType(type) ? options : []
                };
            },
            childByRow: function ($el) {
                var index = $el.closest('.tf-array-child-card').data('index');
                return Controller.api.fields[Controller.api.activeIndex].children[index];
            },
            collectFields: function () {
                var api = Controller.api;
                var fields = [];
                $.each(api.fields, function (index, item) {
                    var label = $.trim(item.label || '');
                    if (!label) {
                        return;
                    }
                    var field = {
                        label: label,
                        name: item.name || api.makeFieldName(label, index),
                        type: item.type || 'text',
                        options: api.isChoiceType(item.type) ? api.cleanOptions(item.options) : [],
                        required: item.required ? 1 : 0
                    };
                    if (item.type === 'array') {
                        field.children = api.cleanChildren(item.children || []);
                    }
                    fields.push(field);
                });
                $('#fields-data').val(JSON.stringify(fields));
            },
            cleanOptions: function (options) {
                return $.map(options || [], function (option) {
                    option = $.trim(option || '');
                    return option ? option : null;
                });
            },
            cleanChildren: function (children) {
                var api = Controller.api;
                return $.map(children || [], function (child, index) {
                    var label = $.trim(child.label || '');
                    if (!label) {
                        return null;
                    }
                    var options = api.cleanOptions(child.options || []);
                    return {
                        label: label,
                        name: child.name || api.makeFieldName(label, index).replace(/^field_/, 'sub_'),
                        type: child.type || 'text',
                        content: options.length ? options.join(',') : $.trim(child.content || ''),
                        options: options
                    };
                });
            },
            isChoiceType: function (type) {
                return $.inArray(type, choiceTypes) !== -1;
            },
            contentOptions: function (content) {
                return String(content || '').split(',').map(function (item) {
                    return $.trim(item);
                }).filter(Boolean);
            },
            legacyChildren: function (options) {
                var children = [];
                (options || []).forEach(function (option, index) {
                    var parts = String(option || '').split(':');
                    var label = $.trim(parts.shift() || '');
                    if (!label) {
                        return;
                    }
                    children.push({
                        label: label,
                        name: Controller.api.makeFieldName(label, index).replace(/^field_/, 'sub_'),
                        type: $.trim(parts.shift() || 'text'),
                        content: $.trim(parts.join(':'))
                    });
                });
                return children;
            },
            makeFieldName: function (label, index) {
                var hash = 0;
                label = label || ('field_' + index);
                for (var i = 0; i < label.length; i++) {
                    hash = ((hash << 5) - hash) + label.charCodeAt(i);
                    hash |= 0;
                }
                return 'field_' + Math.abs(hash) + '_' + index;
            },
            status: function (value) {
                var map = {0: ['禁用', 'danger'], 1: ['启用', 'success']};
                var item = map[value] || [value, 'default'];
                return '<span class="label label-' + item[1] + '">' + item[0] + '</span>';
            },
            formatter: {
                status: function (value) {
                    return Controller.api.status(value);
                }
            },
            escapeHtml: function (s) {
                return String(s || '').replace(/[&<>"']/g, function (c) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[c];
                });
            }
        }
    };
    return Controller;
});
