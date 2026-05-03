define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-datetimepicker'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'tradeflow/instance/index' + location.search,
                    del_url: 'tradeflow/instance/del',
                    multi_url: 'tradeflow/instance/multi',
                    table: 'tradeflow_instance'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {field: 'initiator_name', title: '发起人', operate: false, formatter: Controller.api.formatter.initiator},
                    {field: 'current_node_name', title: '当前节点', operate: false},
                    {field: 'status', title: '状态', searchList: {0: '进行中', 1: '已完成', 2: '已拒绝', 3: '已撤销'}, formatter: Controller.api.formatter.status},
                    {field: 'createtime', title: '发起时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'buttons', title: '操作', operate: false, events: Controller.api.events.operate, formatter: Controller.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        launch: function () {
            Form.api.bindevent($('form[role=form]'));
            Controller.api.bindArrayFields($('form[role=form]'));
        },
        approve: function () {
            Form.api.bindevent($('form[role=form]'));
            Controller.api.bindArrayFields($('form[role=form]'));
        },
        detail: function () {},
        api: {
            events: {
                operate: {
                    'click .btn-approve': function (e, value, row) {
                        e.stopPropagation();
                        Fast.api.open('tradeflow/instance/approve?ids=' + row.id, '办理节点', {area: ['760px', '80%']});
                    },
                    'click .btn-detail': function (e, value, row) {
                        e.stopPropagation();
                        Fast.api.open('tradeflow/instance/detail?ids=' + row.id, '流程详情', {area: ['900px', '80%']});
                    }
                }
            },
            formatter: {
                status: function (value) {
                    var map = {0: ['进行中', 'primary'], 1: ['已完成', 'success'], 2: ['已拒绝', 'danger'], 3: ['已撤销', 'default']};
                    var item = map[value] || [value, 'default'];
                    return '<span class="label label-' + item[1] + '">' + item[0] + '</span>';
                },
                initiator: function (value, row) {
                    return Controller.api.escapeHtml(value || '') + ' <span class="text-muted">(ID:' + Controller.api.escapeHtml(row.initiator_id || '') + ')</span>';
                },
                operate: function (value, row) {
                    var html = '';
                    if (parseInt(row.status, 10) === 0) {
                        html += '<a href="javascript:;" class="btn btn-xs btn-success btn-approve"><i class="fa fa-check"></i> 办理</a> ';
                    } else if (parseInt(row.status, 10) === 1) {
                        html += '<a href="javascript:;" class="btn btn-xs btn-warning btn-approve"><i class="fa fa-edit"></i> 修改</a> ';
                    }
                    html += '<a href="javascript:;" class="btn btn-xs btn-info btn-detail"><i class="fa fa-search"></i> 详情</a>';
                    return html;
                }
            },
            bindArrayFields: function (form) {
                form.on('click', '.tf-array-add', function () {
                    var $box = $(this).closest('.tf-array-field');
                    var $tbody = $box.find('tbody');
                    var $row = $tbody.find('tr:first').clone(false, false);
                    var index = $tbody.find('tr').length;
                    $row.find('input,select,textarea').each(function () {
                        var $field = $(this);
                        var name = $field.attr('name') || '';
                        $field.attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                        if ($field.is(':checkbox,:radio')) {
                            $field.prop('checked', false);
                        } else if ($field.is('select')) {
                            $field.prop('selectedIndex', 0);
                        } else {
                            $field.val('');
                        }
                        if ($field.attr('id')) {
                            $field.attr('id', $field.attr('id').replace(/-\d+-/, '-' + index + '-'));
                        }
                    });
                    $row.find('.faupload,.fachoose').each(function () {
                        var $btn = $(this);
                        if ($btn.attr('id')) {
                            $btn.attr('id', $btn.attr('id').replace(/-\d+-/, '-' + index + '-'));
                        }
                        var inputId = $btn.data('input-id') || $btn.attr('data-input-id') || '';
                        $btn.attr('data-input-id', inputId.replace(/-\d+-/, '-' + index + '-')).data('input-id', inputId.replace(/-\d+-/, '-' + index + '-'));
                    });
                    $tbody.append($row);
                    Form.events.datetimepicker(form);
                    Form.events.faupload(form);
                    Form.events.faselect(form);
                });
                form.on('click', '.tf-array-remove', function () {
                    var $tbody = $(this).closest('tbody');
                    if ($tbody.find('tr').length > 1) {
                        $(this).closest('tr').remove();
                    } else {
                        $(this).closest('tr').find('input,textarea').val('');
                        $(this).closest('tr').find('select').prop('selectedIndex', 0);
                        $(this).closest('tr').find(':checkbox,:radio').prop('checked', false);
                    }
                });
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
