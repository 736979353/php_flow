define(['jquery', 'toastr', 'fast'], function ($, Toastr, Fast) {
    var flow = null;
    var admins = [];
    var selectedId = null;
    var formTemplates = [];
    var z = 1;
    var fieldTypes = {text: '信息填写', textarea: '多行文本', select: '下拉选择', radio: '单选', checkbox: '多选', file: '附件上传', datetime: '时间', array: '数组明细'};
    var childTypes = {text: '信息填写', textarea: '多行文本', select: '下拉选择', radio: '单选', checkbox: '多选', file: '附件上传', datetime: '时间'};
    var choiceTypes = ['select', 'radio', 'checkbox'];

    function init(configFlow, adminOptions, templateOptions) {
        flow = $.extend(true, {}, configFlow.graph || {});
        admins = adminOptions || [];
        formTemplates = templateOptions || [];
        normalize();
        render();
        bind();
    }

    function normalize() {
        flow.nodes = flow.nodes || [];
        flow.edges = flow.edges || [];
        if (!node('start')) {
            flow.nodes.unshift(baseNode('start', 'start', '开始', 160, 120));
        }
        if (!node('end')) {
            flow.nodes.push(baseNode('end', 'end', '结束', 760, 120));
        }
        relink();
    }

    function baseNode(id, type, label, x, y) {
        return {id: id, type: type, label: label, x: x, y: y, reviewer_ids: [], cc_ids: [], form_fields: []};
    }

    function node(id) {
        for (var i = 0; i < flow.nodes.length; i++) {
            if (flow.nodes[i].id === id) return flow.nodes[i];
        }
        return null;
    }

    function relink() {
        flow.edges = [];
        var stages = stagedNodes();
        for (var i = 0; i < stages.length - 1; i++) {
            stages[i].forEach(function (from) {
                stages[i + 1].forEach(function (to) {
                    flow.edges.push({from: from.id, to: to.id});
                });
            });
        }
    }

    function stagedNodes() {
        var start = node('start');
        var end = node('end');
        var middle = flow.nodes.filter(function (n) {
            return n.type !== 'start' && n.type !== 'end';
        }).sort(function (a, b) {
            return a.x === b.x ? a.y - b.y : a.x - b.x;
        });
        var stages = [];
        middle.forEach(function (n) {
            var last = stages.length ? stages[stages.length - 1] : null;
            if (last && Math.abs(last[0].x - n.x) <= 120) {
                last.push(n);
            } else {
                stages.push([n]);
            }
        });
        stages.forEach(function (stage) {
            stage.sort(function (a, b) { return a.y - b.y; });
        });
        if (start) stages.unshift([start]);
        if (end) stages.push([end]);
        return stages;
    }

    function render() {
        var $canvas = $('#tf-canvas').empty();
        flow.nodes.forEach(function (n) {
            $('<div/>', {
                'class': 'tf-node ' + n.type + (n.id === selectedId ? ' active' : ''),
                'data-id': n.id,
                text: n.label
            }).css({left: n.x, top: n.y, zIndex: z++}).appendTo($canvas);
        });
        renderLines();
        selectNode(selectedId || 'start');
    }

    function renderLines() {
        relink();
        var svg = $('#tf-lines').empty()[0];
        flow.edges.forEach(function (e) {
            var a = node(e.from), b = node(e.to);
            if (!a || !b) return;
            var x1 = a.x + 136, y1 = a.y + 28, x2 = b.x, y2 = b.y + 28;
            var line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            line.setAttribute('d', 'M' + x1 + ' ' + y1 + ' C' + (x1 + 80) + ' ' + y1 + ',' + (x2 - 80) + ' ' + y2 + ',' + x2 + ' ' + y2);
            line.setAttribute('stroke', '#78909c');
            line.setAttribute('stroke-width', '2');
            line.setAttribute('fill', 'none');
            svg.appendChild(line);
        });
    }

    function bind() {
        $('.tf-add-node').on('click', function () {
            var type = $(this).data('type');
            var id = type + '_' + Date.now();
            flow.nodes.push(baseNode(id, type, type === 'audit' ? '审核节点' : '表单节点', 360 + Math.random() * 160, 160 + Math.random() * 120));
            selectedId = id;
            render();
        });

        $('#tf-canvas').on('mousedown', '.tf-node', function (e) {
            var $el = $(this), id = $el.data('id'), n = node(id);
            selectedId = id;
            selectNode(id);
            var startX = e.pageX, startY = e.pageY, ox = n.x, oy = n.y;
            $(document).on('mousemove.tf', function (ev) {
                n.x = Math.max(10, ox + ev.pageX - startX);
                n.y = Math.max(10, oy + ev.pageY - startY);
                $el.css({left: n.x, top: n.y});
                renderLines();
            }).on('mouseup.tf', function () {
                $(document).off('.tf');
            });
        });

        $('#node-form-template').on('change', function () {
            applyTemplateToPanel($(this).val());
            var n = node(selectedId);
            if (n) {
                n.form_template_id = $(this).val() || '';
                n.form_fields = collectPanelFields();
            }
            Toastr.success('应用成功');
        });

        $('#tf-apply-node').on('click', function () {
            applyPanel(false);
        });

        $('#tf-save').on('click', function () {
            applyPanel(true);
            relink();
            Fast.api.ajax({
                url: 'tradeflow/flow/savegraph',
                type: 'post',
                data: {id: Config.flow.id, graph: JSON.stringify(flow)}
            });
        });
    }

    function selectNode(id) {
        var n = node(id);
        if (!n) return;
        selectedId = id;
        $('.tf-node').removeClass('active');
        $('.tf-node[data-id="' + id + '"]').addClass('active');
        $('#node-id').val(n.id);
        $('#node-label').val(n.label).prop('disabled', n.type === 'start' || n.type === 'end');
        $('#node-reviewers').val((n.reviewer_ids || []).map(String));
        $('#node-copies').val((n.cc_ids || []).map(String));
        $('#node-form-template').val(n.form_template_id || '');
        $('.tf-audit-only').toggle(n.type === 'audit');
        $('.tf-form-only').toggle(n.type === 'form' || n.type === 'start');
        $('#tf-delete-node').hide();
        $('#tf-fields').empty();
        (n.form_fields || []).forEach(addFieldRow);
    }

    function templateById(id) {
        var tpl = null;
        formTemplates.forEach(function (item) {
            if (String(item.id) === String(id)) {
                tpl = item;
            }
        });
        return tpl;
    }

    function applyTemplateToPanel(id) {
        $('#tf-fields').empty();
        if (!id) {
            return;
        }
        var tpl = templateById(id);
        if (!tpl) {
            return;
        }
        (tpl.fields || []).forEach(function (field) {
            addFieldRow($.extend(true, {}, field));
        });
    }

    function addFieldRow(field) {
        var $row = $('<div class="tf-field-row"/>');
        $row.append('<input class="form-control tf-field-label" placeholder="字段标题" value="' + escapeHtml(field.label || '') + '">');
        $row.append('<input type="hidden" class="tf-field-name" value="' + escapeHtml(field.name || '') + '">');
        var $type = $('<select class="form-control tf-field-type"/>');
        $.each(fieldTypes, function (k, v) { $type.append('<option value="' + k + '">' + v + '</option>'); });
        $type.val(field.type || 'text');
        $row.append($type);
        $row.append(optionDesigner('tf-field-option', field.options || []));
        $row.append(arrayDesigner(field.children || legacyChildren(field.options || [])));
        $row.append('<label><input type="checkbox" class="tf-field-required" ' + (field.required ? 'checked' : '') + '> 必填</label> ');
        $row.append('<button type="button" class="btn btn-danger btn-xs pull-right tf-field-remove">删除</button>');
        $row.on('click', '.tf-field-remove', function () { $row.remove(); });
        $row.on('click', '.tf-array-child-add', function () { addArrayChildRow($row.find('.tf-array-children'), {}); });
        $row.on('click', '.tf-array-child-remove', function () { $(this).closest('.tf-array-child-row').remove(); });
        $row.on('click', '.tf-option-add', function () { addOptionRow($(this).closest('.tf-options-designer').find('.tf-option-list'), '', 'tf-field-option'); });
        $row.on('click', '.tf-option-remove', function () { $(this).closest('.tf-option-row').remove(); });
        $row.on('click', '.tf-array-child-option-add', function () { addOptionRow($(this).closest('.tf-options-designer').find('.tf-option-list'), '', 'tf-array-child-option'); });
        $row.on('click', '.tf-array-child-option-remove', function () { $(this).closest('.tf-option-row').remove(); });
        $row.on('change', '.tf-array-child-type', function () { toggleArrayChildContent($(this).closest('.tf-array-child-row')); });
        $row.on('change', '.tf-field-type', function () {
            toggleArrayDesigner($row);
            toggleOptionDesigner($row);
        });
        $('#tf-fields').append($row);
        toggleArrayDesigner($row);
        toggleOptionDesigner($row);
        $row.find('.tf-array-child-row').each(function () {
            toggleArrayChildContent($(this));
        });
    }

    function optionDesigner(inputClass, options) {
        var $box = $('<div class="tf-options-designer" style="display:none;"></div>');
        $box.append('<div class="tf-options-title">选项设置</div>');
        var $list = $('<div class="tf-option-list"></div>');
        $box.append($list);
        (options && options.length ? options : ['']).forEach(function (option) {
            addOptionRow($list, option, inputClass);
        });
        $box.append('<button type="button" class="btn btn-default btn-xs tf-option-add"><i class="fa fa-plus"></i> 添加选项</button>');
        return $box;
    }

    function addOptionRow($list, value, inputClass) {
        var removeClass = inputClass === 'tf-array-child-option' ? 'tf-array-child-option-remove' : 'tf-option-remove';
        var $row = $('<div class="tf-option-row" style="display:flex;align-items:center;gap:6px;width:100%;margin-bottom:6px;"></div>');
        $row.append('<input class="form-control ' + inputClass + '" style="flex:1 1 auto;width:auto!important;min-width:0;margin-bottom:0;" placeholder="选项名称" value="' + escapeHtml(value || '') + '">');
        $row.append('<button type="button" class="btn btn-danger btn-xs ' + removeClass + '" style="flex:0 0 30px;width:30px;height:30px;padding:0;line-height:30px;"><i class="fa fa-trash"></i></button>');
        $list.append($row);
    }

    function collectOptions($scope, inputClass) {
        var options = [];
        $scope.find('.' + inputClass).each(function () {
            var value = $.trim($(this).val());
            if (value) {
                options.push(value);
            }
        });
        return options;
    }

    function isChoiceType(type) {
        return $.inArray(type, choiceTypes) !== -1;
    }

    function toggleOptionDesigner($row) {
        $row.children('.tf-options-designer').toggle(isChoiceType($row.find('.tf-field-type').val()));
    }

    function arrayDesigner(children) {
        var $box = $('<div class="tf-array-designer" style="display:none;"></div>');
        $box.append('<div class="tf-array-title">数组子字段</div>');
        var $table = $('<table class="table table-condensed tf-array-child-table"><thead><tr><th style="width:28%;">标题</th><th style="width:24%;">类型</th><th>内容</th><th style="width:58px;">操作</th></tr></thead></table>');
        var $children = $('<tbody class="tf-array-children"></tbody>');
        $table.append($children);
        $box.append($table);
        $box.append('<button type="button" class="btn btn-default btn-xs tf-array-child-add"><i class="fa fa-plus"></i> 添加子字段</button>');
        (children && children.length ? children : [{type: 'text', label: '内容', content: ''}]).forEach(function (child) {
            addArrayChildRow($children, child);
        });
        return $box;
    }

    function addArrayChildRow($children, child) {
        child = child || {};
        var $child = $('<tr class="tf-array-child-row"></tr>');
        $child.data('name', child.name || '');
        $child.append('<td><input class="form-control tf-array-child-label" placeholder="标题" value="' + escapeHtml(child.label || '') + '"></td>');
        var $type = $('<select class="form-control tf-array-child-type"></select>');
        $.each(childTypes, function (key, text) {
            $type.append('<option value="' + key + '">' + text + '</option>');
        });
        $type.val(child.type || 'text');
        $child.append($('<td></td>').append($type));
        var $content = $('<td></td>');
        $content.append('<input class="form-control tf-array-child-content" placeholder="默认内容，可为空" value="' + escapeHtml(child.content || '') + '">');
        var $options = optionDesigner('tf-array-child-option', child.options || contentOptions(child.content || ''));
        $options.find('.tf-option-add').removeClass('tf-option-add').addClass('tf-array-child-option-add');
        $content.append($options);
        $child.append($content);
        $child.append('<td class="text-center"><button type="button" class="btn btn-danger btn-xs tf-array-child-remove"><i class="fa fa-trash"></i></button></td>');
        $children.append($child);
    }

    function toggleArrayDesigner($row) {
        var isArray = $row.find('.tf-field-type').val() === 'array';
        $row.find('.tf-array-designer').toggle(isArray);
        $row.children('.tf-options-designer').toggle(!isArray && isChoiceType($row.find('.tf-field-type').val()));
    }

    function toggleArrayChildContent($row) {
        var isChoice = isChoiceType($row.find('.tf-array-child-type').val());
        $row.find('.tf-array-child-content').toggle(!isChoice);
        $row.find('.tf-options-designer').toggle(isChoice);
    }

    function collectArrayChildren($row) {
        var children = [];
        $row.find('.tf-array-child-row').each(function (index) {
            var $child = $(this);
            var label = $.trim($child.find('.tf-array-child-label').val());
            if (!label) return;
            var options = collectOptions($child, 'tf-array-child-option');
            children.push({
                label: label,
                name: $child.data('name') || makeFieldName(label, index).replace(/^field_/, 'sub_'),
                type: $child.find('.tf-array-child-type').val() || 'text',
                content: options.length ? options.join(',') : $.trim($child.find('.tf-array-child-content').val()),
                options: options
            });
        });
        return children;
    }

    function contentOptions(content) {
        return String(content || '').split(',').map(function (item) {
            return $.trim(item);
        }).filter(Boolean);
    }

    function legacyChildren(options) {
        var children = [];
        (options || []).forEach(function (option, index) {
            var parts = String(option || '').split(':');
            var label = $.trim(parts.shift() || '');
            if (!label) return;
            children.push({
                label: label,
                name: makeFieldName(label, index).replace(/^field_/, 'sub_'),
                type: $.trim(parts.shift() || 'text'),
                content: $.trim(parts.join(':'))
            });
        });
        return children;
    }

    function applyPanel(silent) {
        var n = node(selectedId);
        if (!n) return;
        if (n.type !== 'start' && n.type !== 'end') {
            n.label = $('#node-label').val() || n.label;
        }
        n.reviewer_ids = ($('#node-reviewers').val() || []).map(String);
        n.cc_ids = ($('#node-copies').val() || []).map(String);
        n.form_template_id = $('#node-form-template').val() || '';
        n.form_fields = (n.type === 'form' || n.type === 'start') ? collectPanelFields() : [];
        render();
        if (!silent) {
            Toastr.success('应用成功');
        }
    }

    function collectPanelFields() {
        var fields = [];
        $('#tf-fields .tf-field-row').each(function (index) {
            var $r = $(this);
            var options = collectOptions($r.children('.tf-options-designer'), 'tf-field-option');
            var label = $r.find('.tf-field-label').val();
            var type = $r.find('.tf-field-type').val();
            var field = {
                label: label,
                name: $r.find('.tf-field-name').val() || makeFieldName(label, index),
                type: type,
                options: type === 'array' ? [] : options,
                required: $r.find('.tf-field-required').prop('checked') ? 1 : 0
            };
            if (type === 'array') {
                field.children = collectArrayChildren($r);
            }
            fields.push(field);
        });
        return fields;
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[c];
        });
    }

    function makeFieldName(label, index) {
        var hash = 0;
        label = label || ('field_' + index);
        for (var i = 0; i < label.length; i++) {
            hash = ((hash << 5) - hash) + label.charCodeAt(i);
            hash |= 0;
        }
        return 'field_' + Math.abs(hash) + '_' + index;
    }

    return {init: init};
});
