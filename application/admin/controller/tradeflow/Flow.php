<?php

namespace app\admin\controller\tradeflow;

use app\admin\model\Admin;
use app\common\controller\Backend;
use think\Db;
use think\Session;

class Flow extends Backend
{
    protected $model = null;
    protected $noNeedRight = ['admins', 'batchassign'];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\tradeflow\Flow;
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $admin = Session::get('admin');
            $params['createuser_id'] = isset($admin['id']) ? $admin['id'] : 0;
            if (empty($params['graph'])) {
                $params['graph'] = json_encode($this->defaultGraph(), JSON_UNESCAPED_UNICODE);
            }
            $this->model->allowField(true)->save($params);
            $this->success();
        }
        return parent::add();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $ownerIds = $this->visibleAdminIds();
            $ownerIds = $ownerIds ?: [0];
            $total = $this->model
                ->where($where)
                ->where('createuser_id', 'in', $ownerIds)
                ->count();
            $list = $this->model
                ->where($where)
                ->where('createuser_id', 'in', $ownerIds)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            $rows = [];
            $instanceStatusList = (new \app\admin\model\tradeflow\Instance)->getStatusList();
            foreach ($list as $row) {
                $item = $row->toArray();
                $instance = \app\admin\model\tradeflow\Instance::where('flow_id', $row['id'])->order('id desc')->find();
                $item['has_instance'] = $instance ? 1 : 0;
                $item['instance_id'] = $instance ? $instance['id'] : 0;
                $item['instance_title'] = $instance ? $instance['title'] : '';
                $item['instance_status'] = $instance ? $instance['status'] : '';
                $item['instance_status_text'] = $instance ? (isset($instanceStatusList[$instance['status']]) ? $instanceStatusList[$instance['status']] : '') : '';
                $item['instance_current_node_name'] = $instance ? $instance['current_node_name'] : '';
                $item['instance_createtime'] = $instance ? $instance['createtime'] : '';
                $item['instance_initiator_name'] = $instance ? $instance['initiator_name'] : '';
                $item['instance_initiator_id'] = $instance ? $instance['initiator_id'] : 0;
                $rows[] = $item;
            }
            return json(['total' => $total, 'rows' => $rows]);
        }
        $this->assignconfig('subordinateAdmins', $this->subordinateAdminOptions());
        return $this->view->fetch();
    }

    public function batchassign()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ids = $this->request->post('ids', '');
        $adminId = $this->request->post('admin_id/d', 0);
        $flowIds = array_values(array_filter(array_map('intval', explode(',', (string)$ids))));
        if (!$flowIds) {
            $this->error('请选择要分配的流程');
        }
        $ownerIds = $this->visibleAdminIds();
        $allowedCount = $this->model->where('id', 'in', $flowIds)->where('createuser_id', 'in', $ownerIds ?: [0])->count();
        if ($allowedCount !== count($flowIds)) {
            $this->error('只能分配自己或下级名下的流程');
        }
        $childrenAdminIds = $this->auth->getChildrenAdminIds(false);
        if (!$adminId || !in_array($adminId, array_map('intval', $childrenAdminIds))) {
            $this->error('只能分配给自己的下级用户');
        }
        $count = $this->model->where('id', 'in', $flowIds)->update(['createuser_id' => $adminId, 'updatetime' => time()]);
        $this->success('已分配 ' . $count . ' 个流程');
    }

    public function designer($ids = null)
    {
        $ids = $ids ?: input('ids') ?: input('flow_id');
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->checkFlowVisible($row);
        $graph = json_decode($row['graph'], true);
        if (!$graph) {
            $graph = $this->defaultGraph();
        }
        $this->assignconfig([
            'flow' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'graph' => $graph,
            ],
            'admins' => $this->adminOptions(),
            'formTemplates' => $this->formTemplateOptions(),
        ]);
        $this->view->assign([
            'row' => $row,
            'admins' => $this->adminOptions(),
            'formTemplates' => $this->formTemplateOptions(),
        ]);
        return $this->view->fetch();
    }

    public function savegraph()
    {
        if (!$this->request->isAjax()) {
            $this->error('非法请求');
        }
        $id = $this->request->post('id/d');
        $graph = $this->request->post('graph');
        $row = $this->model->get($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->checkFlowVisible($row);
        $data = json_decode($graph, true);
        $error = $this->validateGraph($data);
        if ($error !== true) {
            $this->error($error);
        }
        $row->save([
            'graph' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => 1,
        ]);
        $this->success('流程设计已保存并启用');
    }

    public function admins()
    {
        $this->success('', null, $this->adminOptions());
    }

    protected function defaultGraph()
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'label' => '开始', 'x' => 160, 'y' => 120, 'reviewer_ids' => [], 'cc_ids' => [], 'form_fields' => []],
                ['id' => 'end', 'type' => 'end', 'label' => '结束', 'x' => 760, 'y' => 120, 'reviewer_ids' => [], 'cc_ids' => [], 'form_fields' => []],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'end'],
            ],
        ];
    }

    protected function validateGraph($graph)
    {
        if (!is_array($graph) || empty($graph['nodes']) || empty($graph['edges'])) {
            return '流程图不能为空';
        }
        $types = array_column($graph['nodes'], 'type');
        if (count(array_keys($types, 'start')) !== 1 || count(array_keys($types, 'end')) !== 1) {
            return '流程必须且只能包含一个开始节点和一个结束节点';
        }
        $ids = [];
        foreach ($graph['nodes'] as $node) {
            if (empty($node['id']) || empty($node['type']) || empty($node['label'])) {
                return '节点缺少必要属性';
            }
            if (isset($ids[$node['id']])) {
                return '节点ID重复';
            }
            $ids[$node['id']] = true;
            if ($node['type'] === 'audit' && empty($node['reviewer_ids'])) {
                return '审核节点「' . $node['label'] . '」必须选择审核人';
            }
            if ($node['type'] === 'form' && empty($node['form_fields'])) {
                return '表单节点「' . $node['label'] . '」必须至少包含一个字段';
            }
        }
        foreach ($graph['edges'] as $edge) {
            if (empty($ids[$edge['from']]) || empty($ids[$edge['to']])) {
                return '流程连线包含不存在的节点';
            }
        }
        return true;
    }

    protected function adminOptions()
    {
        $rows = Admin::field('id,nickname,username')->where('status', 'normal')->order('id asc')->select();
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => $row['id'],
                'name' => $row['nickname'] ?: $row['username'],
            ];
        }
        return $list;
    }

    protected function formTemplateOptions()
    {
        $rows = \app\admin\model\tradeflow\Form::field('id,name,fields_data')->where('status', 1)->order('id desc')->select();
        $list = [];
        foreach ($rows as $row) {
            $fields = json_decode($row['fields_data'], true);
            $list[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'fields' => is_array($fields) ? $fields : [],
            ];
        }
        return $list;
    }

    protected function visibleAdminIds()
    {
        if ($this->auth->isSuperAdmin() || $this->hasFullFlowAccess()) {
            return Admin::column('id');
        }
        $ids = $this->auth->getChildrenAdminIds(false);
        $ids[] = $this->auth->id;
        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected function hasFullFlowAccess()
    {
        $config = get_addon_config('tradeflow');
        $groupIds = isset($config['full_access_group_ids']) ? $config['full_access_group_ids'] : '';
        if (is_string($groupIds)) {
            $groupIds = array_filter(explode(',', $groupIds), 'strlen');
        }
        if (!is_array($groupIds) || !$groupIds) {
            return false;
        }
        $groupIds = array_map('intval', $groupIds);
        $currentGroupIds = Db::name('auth_group_access')
            ->where('uid', (int)$this->auth->id)
            ->column('group_id');
        $currentGroupIds = array_map('intval', $currentGroupIds);
        return (bool)array_intersect($groupIds, $currentGroupIds);
    }

    protected function checkFlowVisible($row)
    {
        if (!in_array((int)$row['createuser_id'], $this->visibleAdminIds())) {
            $this->error('无权操作该流程');
        }
    }

    protected function subordinateAdminOptions()
    {
        $ids = $this->auth->getChildrenAdminIds(false);
        if (!$ids) {
            return [];
        }
        $rows = Admin::field('id,nickname,username')->where('id', 'in', $ids)->where('status', 'normal')->order('id asc')->select();
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => $row['id'],
                'name' => $row['nickname'] ?: $row['username'],
            ];
        }
        return $list;
    }
}
