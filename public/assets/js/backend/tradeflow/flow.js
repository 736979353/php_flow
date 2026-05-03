define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'toastr'], function ($, undefined, Backend, Table, Form, Toastr) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'tradeflow/flow/index' + location.search,
                    add_url: 'tradeflow/flow/add',
                    edit_url: 'tradeflow/flow/edit',
                    del_url: 'tradeflow/flow/del',
                    multi_url: 'tradeflow/flow/multi',
                    table: 'tradeflow_flow'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                detailView: true,
                detailFormatter: Controller.api.formatter.detail,
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'name', title: '流程名称', operate: 'LIKE'},
                    {field: 'description', title: '说明', operate: 'LIKE', formatter: Table.api.formatter.content},
                    {field: 'creator_name', title: '创建人', operate: false},
                    {field: 'instance_status_text', title: '流程办理', operate: false, formatter: Controller.api.formatter.instance},
                    {field: 'status', title: '状态', searchList: {0: '草稿', 1: '启用', 2: '停用'}, formatter: Controller.api.formatter.status},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'buttons', title: '操作', operate: false, table: table, events: Controller.api.events.operate, formatter: Controller.api.formatter.operate}
                ]]
            });
            table.on('click', '.btn-flow-approve', function (e) {
                e.stopPropagation();
                Fast.api.open('tradeflow/instance/approve?ids=' + $(this).data('id') + '&dialog=1', '办理节点', {area: ['760px', '80%']});
            });
            table.on('click', '.btn-flow-detail', function (e) {
                e.stopPropagation();
                Fast.api.open('tradeflow/instance/detail?ids=' + $(this).data('id') + '&dialog=1', '流程详情', {area: ['900px', '80%']});
            });
            table.on('click', '.btn-flow-del-instance', function (e) {
                e.stopPropagation();
                var id = $(this).data('id');
                Layer.confirm('确定删除这个流程办理吗？删除后该流程设计可以重新发起。', function (index) {
                    Layer.close(index);
                    Fast.api.ajax({
                        url: 'tradeflow/instance/del',
                        type: 'post',
                        data: {ids: id}
                    }, function () {
                        table.bootstrapTable('refresh');
                    });
                });
            });
            $('.btn-batch-assign').on('click', function () {
                var ids = Table.api.selectedids(table);
                if (!ids.length) {
                    Toastr.warning('请选择要分配的流程');
                    return;
                }
                var admins = Config.subordinateAdmins || [];
                if (!admins.length) {
                    Toastr.warning('暂无可分配的下级用户');
                    return;
                }
                var options = admins.map(function (item) {
                    return '<option value="' + item.id + '">' + Controller.api.escapeHtml(item.name) + '</option>';
                }).join('');
                Layer.open({
                    type: 1,
                    title: '批量分配流程',
                    area: ['420px', '220px'],
                    content: '<div style="padding:20px;"><div class="form-group"><label>分配给</label><select class="form-control" id="tf-assign-admin">' + options + '</select></div><div class="text-muted">已选择 ' + ids.length + ' 个流程</div></div>',
                    btn: ['确定', '取消'],
                    yes: function (index) {
                        var adminId = $('#tf-assign-admin').val();
                        Fast.api.ajax({
                            url: 'tradeflow/flow/batchassign',
                            type: 'post',
                            data: {ids: ids.join(','), admin_id: adminId}
                        }, function () {
                            Layer.close(index);
                            table.bootstrapTable('refresh');
                        });
                    }
                });
            });
            Table.api.bindevent(table);
        },
        add: function () {
            Form.api.bindevent($('form[role=form]'));
        },
        edit: function () {
            Form.api.bindevent($('form[role=form]'));
        },
        designer: function () {
            require.config({
                paths: {
                    'tradeflow-designer': '../addons/tradeflow/js/designer'
                }
            });
            require(['tradeflow-designer'], function (Designer) {
                Designer.init(Config.flow, Config.admins, Config.formTemplates || []);
            });
        },
        api: {
            events: {
                operate: $.extend({}, Table.api.events.operate, {
                    'click .btn-designer': function (e, value, row) {
                        e.stopPropagation();
                        e.preventDefault();
                        $(this).data('area', ['100%', '100%']);
                        Fast.api.open('tradeflow/flow/designer?ids=' + row.id + '&dialog=1', '可视化设计', $(this).data() || {});
                    },
                    'click .btn-launch': function (e, value, row) {
                        e.stopPropagation();
                        e.preventDefault();
                        Fast.api.open('tradeflow/instance/launch?flow_id=' + row.id + '&dialog=1', '发起流程', {area: ['760px', '80%']});
                    },
                    'click .btn-instance-approve': function (e, value, row) {
                        e.stopPropagation();
                        e.preventDefault();
                        Fast.api.open('tradeflow/instance/approve?ids=' + row.instance_id + '&dialog=1', '办理节点', {area: ['760px', '80%']});
                    },
                    'click .btn-instance-detail': function (e, value, row) {
                        e.stopPropagation();
                        e.preventDefault();
                        Fast.api.open('tradeflow/instance/detail?ids=' + row.instance_id + '&dialog=1', '流程详情', {area: ['900px', '80%']});
                    }
                })
            },
            formatter: {
                status: function (value) {
                    var map = {0: ['草稿', 'default'], 1: ['启用', 'success'], 2: ['停用', 'danger']};
                    var item = map[value] || [value, 'default'];
                    return '<span class="label label-' + item[1] + '">' + item[0] + '</span>';
                },
                instance: function (value, row) {
                    if (!parseInt(row.has_instance, 10)) {
                        return '<span class="label label-default">未发起</span>';
                    }
                    var type = parseInt(row.instance_status, 10) === 1 ? 'success' : (parseInt(row.instance_status, 10) === 2 ? 'danger' : 'primary');
                    return '<span class="label label-' + type + '">' + (row.instance_status_text || '进行中') + '</span> ' + (row.instance_current_node_name || '');
                },
                detail: function (index, row) {
                    if (!parseInt(row.has_instance, 10)) {
                        return '<div class="text-muted" style="padding:10px 20px;">该流程设计还没有发起流程办理。</div>';
                    }
                    var html = [];
                    html.push('<div style="padding:10px 20px;background:#fbfcfd;">');
                    html.push('<div><strong>流程标题：</strong>' + Controller.api.escapeHtml(row.instance_title || '') + '</div>');
                    html.push('<div style="margin-top:6px;"><strong>办理状态：</strong>' + Controller.api.escapeHtml(row.instance_status_text || '') + '</div>');
                    html.push('<div style="margin-top:6px;"><strong>当前节点：</strong>' + Controller.api.escapeHtml(row.instance_current_node_name || '') + '</div>');
                    html.push('<div style="margin-top:6px;"><strong>发起人：</strong>' + Controller.api.escapeHtml(row.instance_initiator_name || '') + ' <span class="text-muted">(ID:' + Controller.api.escapeHtml(row.instance_initiator_id || '') + ')</span></div>');
                    html.push('<div style="margin-top:10px;">');
                    html.push('<a href="javascript:;" class="btn btn-xs btn-success btn-flow-approve" data-id="' + row.instance_id + '"><i class="fa fa-check"></i> 办理/修改</a> ');
                    html.push('<a href="javascript:;" class="btn btn-xs btn-info btn-flow-detail" data-id="' + row.instance_id + '"><i class="fa fa-search"></i> 查看详情</a>');
                    html.push(' <a href="javascript:;" class="btn btn-xs btn-danger btn-flow-del-instance" data-id="' + row.instance_id + '"><i class="fa fa-trash"></i> 删除流程办理</a>');
                    html.push('</div></div>');
                    return html.join('');
                },
                operate: function (value, row) {
                    var html = '<a href="javascript:;" class="btn btn-xs btn-info btn-designer"><i class="fa fa-sitemap"></i> 设计</a> ';
                    if (parseInt(row.has_instance, 10)) {
                        html += '<a href="javascript:;" class="btn btn-xs btn-success btn-instance-approve"><i class="fa fa-check"></i> 办理</a> ';
                        html += '<a href="javascript:;" class="btn btn-xs btn-primary btn-instance-detail"><i class="fa fa-search"></i> 详情</a> ';
                    } else if (parseInt(row.status, 10) === 1) {
                        html += '<a href="javascript:;" class="btn btn-xs btn-warning btn-launch"><i class="fa fa-play"></i> 发起流程</a> ';
                    }
                    html += Table.api.formatter.operate.call(this, value, row);
                    return html;
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
